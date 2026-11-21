<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Sale;
use App\Models\SiteSetting;
use App\Models\Service;
use App\Models\StaffNotification;
use App\Models\User;
use App\Services\ClientNotifier;
use App\Services\TurnService;
use App\Services\WhatsApp\BookingMessenger;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicBookingController extends Controller
{
    public function availableTimes(Request $request)
    {
        $data = $request->validate([
            'barber_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $barber = User::barbers()->where('active', true)->where('attention_mode', 'citas')->find($data['barber_id']);
        abort_if(! $barber, 404);

        $date = Carbon::parse($data['date']);
        $settings = SiteSetting::currentData();
        if (SiteSetting::isClosedOn($date, $settings) || $barber->isUnavailableOnDate($date->toDateString())) {
            return response()->json(['times' => []]);
        }

        $start = Carbon::createFromFormat('H:i', $settings['booking_start']);
        $end = Carbon::createFromFormat('H:i', $settings['booking_end']);
        $reserved = Appointment::query()
            ->where('barber_id', $barber->id)
            ->whereDate('appointment_date', $date->toDateString())
            ->where('status', '!=', 'cancelada')
            ->pluck('appointment_time')
            ->map(fn ($time) => substr((string) $time, 0, 5))
            ->all();

        $times = [];
        for ($slot = $start->copy(); $slot->lte($end); $slot->addMinutes(30)) {
            if ($date->isToday() && $slot->lte(Carbon::now())) {
                continue;
            }
            $formatted = $slot->format('H:i');
            if (in_array($formatted, $reserved, true)) {
                continue;
            }
            if ($barber->isUnavailableAt($date->toDateString(), $formatted)) {
                continue;
            }
            $times[] = $formatted;
        }

        return response()->json(['times' => $times]);
    }

    public function index(Request $request)
    {
        $services = Service::active()->orderBy('name')->get();
        $appointmentBarbers = User::barbers()
            ->where('active', true)
            ->where('attention_mode', 'citas')
            ->with('offeredServices')
            ->orderBy('name')
            ->get();
        $turnBarbers = User::barbers()
            ->where('active', true)
            ->where('attention_mode', 'llegada')
            ->with('offeredServices')
            ->orderBy('name')
            ->get();

        $settings = SiteSetting::currentData();
        $paymentMethods = $this->enabledPaymentMethods($settings);
        $businessOpenNow = $this->isBusinessOpenNow($settings);

        $activeTab = $request->input('tipo') === 'turno' ? 'turno' : 'cita';
        $selectedBarberId = (int) $request->input('barbero', 0);

        return view('public.booking', compact(
            'services',
            'appointmentBarbers',
            'turnBarbers',
            'activeTab',
            'selectedBarberId',
            'paymentMethods',
            'businessOpenNow'
        ));
    }

    public function storeAppointment(Request $request, WhatsAppService $whatsApp)
    {
        $settings = SiteSetting::currentData();
        // Anti-spam: el campo oculto "website" solo lo llenan los robots. Se simula éxito sin guardar nada.
        if (filled($request->input('website'))) {
            return redirect()->route('public.booking');
        }

        $enabled = array_keys($this->enabledPaymentMethods($settings));

        $data = $request->validate([
            'barber_id' => ['required', 'exists:users,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'client_name' => ['required', 'string', 'max:120'],
            'client_phone' => $this->phoneRules(),
            'client_email' => ['nullable', 'email', 'max:150'],
            'whatsapp_consent' => ['nullable', 'boolean'],
            'payment_method' => ['required', Rule::in($enabled)],
            'appointment_date' => ['required', 'date', 'after_or_equal:today'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'payment_method.required' => 'Selecciona un método de pago.',
            'payment_method.in' => 'El método de pago seleccionado no está disponible.',
            'client_phone.regex' => 'Escribe un número de teléfono válido (solo números, por ejemplo 300 123 4567).',
            'client_email.email' => 'Escribe un correo electrónico válido o deja el campo vacío.',
        ]);

        $barber = User::barbers()
            ->where('active', true)
            ->where('attention_mode', 'citas')
            ->find($data['barber_id']);

        if (! $barber) {
            throw ValidationException::withMessages([
                'barber_id' => 'El barbero seleccionado no atiende por citas. Elige otro barbero.',
            ]);
        }

        if (! empty($data['service_id']) && ! $barber->offersService((int) $data['service_id'])) {
            throw ValidationException::withMessages([
                'service_id' => 'Ese barbero no ofrece el servicio seleccionado. Elige otro servicio u otro barbero.',
            ]);
        }

        if ($data['appointment_time'] < $settings['booking_start'] || $data['appointment_time'] > $settings['booking_end']) {
            throw ValidationException::withMessages([
                'appointment_time' => 'La hora seleccionada está fuera del horario de atención.',
            ]);
        }

        $appointmentDate = Carbon::parse($data['appointment_date'])->toDateString();

        if (SiteSetting::isClosedOn(Carbon::parse($data['appointment_date']), $settings) || $barber->isUnavailableOnDate($appointmentDate)) {
            throw ValidationException::withMessages([
                'appointment_date' => 'Ese día el barbero no está disponible. Elige otro día.',
            ]);
        }

        $slotStart = Carbon::createFromFormat('H:i', $settings['booking_start']);
        $slotEnd = Carbon::createFromFormat('H:i', $settings['booking_end']);
        $slotIsValid = false;
        while ($slotStart->lte($slotEnd)) {
            if ($slotStart->format('H:i') === $data['appointment_time']) {
                $slotIsValid = true;
                break;
            }
            $slotStart->addMinutes(30);
        }
        if (! $slotIsValid) {
            throw ValidationException::withMessages([
                'appointment_time' => 'Elige uno de los horarios disponibles.',
            ]);
        }

        if ($barber->isUnavailableAt($appointmentDate, $data['appointment_time'])) {
            throw ValidationException::withMessages([
                'appointment_time' => 'El barbero no está disponible en ese horario. Elige otra hora.',
            ]);
        }

        $slotTaken = Appointment::query()
            ->where('barber_id', $barber->id)
            ->whereDate('appointment_date', $data['appointment_date'])
            ->whereTime('appointment_time', $data['appointment_time'])
            ->where('status', '!=', 'cancelada')
            ->exists();

        if ($slotTaken) {
            throw ValidationException::withMessages([
                'appointment_time' => 'Ese horario ya está ocupado. Elige otra hora o intenta con otro barbero.',
            ]);
        }

        $appointment = Appointment::create([
            'barber_id' => $barber->id,
            'service_id' => $data['service_id'] ?? null,
            'client_name' => $data['client_name'],
            'client_phone' => $data['client_phone'],
            'client_email' => $data['client_email'] ?? null,
            'whatsapp_consent' => (bool) ($data['whatsapp_consent'] ?? false),
            'payment_method' => $data['payment_method'],
            'appointment_date' => $data['appointment_date'],
            'appointment_time' => $data['appointment_time'],
            'status' => 'pendiente',
            'notes' => $data['notes'] ?? null,
        ]);

        $appointment->load(['service', 'barber']);
        $this->sendPaymentInfoIfVirtual($whatsApp, $settings, $data['client_phone'], $data['client_name'], $data['payment_method'], 'cita');

        // Confirmación inmediata + recordatorios (1 hora antes, 15 minutos antes y a la hora de la cita).
        $notifier = app(ClientNotifier::class);
        $notifier->appointmentBooked($appointment);
        $notifier->flush();

        // Pide la confirmación por WhatsApp: el cliente responde "sí" y la cita pasa a confirmada.
        app(BookingMessenger::class)->requestAppointmentConfirmation($appointment);

        StaffNotification::record(
            'appt_new',
            'Nueva cita: '.$appointment->client_name,
            Carbon::parse($appointment->appointment_date)->locale('es')->translatedFormat('l j \d\e F').' a las '.$this->timeLabel($appointment->appointment_time).' con '.$barber->name.($appointment->service ? ' · '.$appointment->service->name : '').'. Pendiente de confirmar.',
            route('appointments.index', ['date' => $appointmentDate], false),
            $barber->id,
        );

        return redirect()
            ->route('public.booking', ['tipo' => 'cita'])
            ->with('booking_success', [
                'type' => 'cita',
                'title' => '¡Tu cita quedó registrada!',
                'client' => $appointment->client_name,
                'barber' => $barber->name,
                'service' => $appointment->service?->name,
                'date' => Carbon::parse($appointment->appointment_date)->locale('es')->translatedFormat('l, d \d\e F'),
                'time' => $this->timeLabel($appointment->appointment_time),
                'status' => 'Pendiente de confirmación',
                'payment' => $this->enabledPaymentMethods($settings)[$data['payment_method']] ?? $data['payment_method'],
                'code' => 'CITA-'.$appointment->id,
                'track_url' => route('track.show', ['type' => 'cita', 'token' => $appointment->public_token]),
                'track_type' => 'cita',
                'track_token' => $appointment->public_token,
            ]);
    }

    public function storeTurn(Request $request, TurnService $turnService, WhatsAppService $whatsApp)
    {
        $settings = SiteSetting::currentData();
        // Anti-spam: el campo oculto "website" solo lo llenan los robots. Se simula éxito sin guardar nada.
        if (filled($request->input('website'))) {
            return redirect()->route('public.booking');
        }

        $enabled = array_keys($this->enabledPaymentMethods($settings));

        $data = $request->validate([
            'barber_id' => ['required', 'exists:users,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'client_name' => ['required', 'string', 'max:120'],
            'client_phone' => $this->phoneRules(),
            'client_email' => ['nullable', 'email', 'max:150'],
            'whatsapp_consent' => ['nullable', 'boolean'],
            'payment_method' => ['required', Rule::in($enabled)],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'payment_method.required' => 'Selecciona un método de pago.',
            'payment_method.in' => 'El método de pago seleccionado no está disponible.',
            'client_phone.regex' => 'Escribe un número de teléfono válido (solo números, por ejemplo 300 123 4567).',
            'client_email.email' => 'Escribe un correo electrónico válido o deja el campo vacío.',
        ]);

        if (! $this->isBusinessOpenNow($settings)) {
            throw ValidationException::withMessages([
                'barber_id' => 'En este momento el negocio no está abierto para tomar turnos. Puedes agendar una cita para otro horario.',
            ]);
        }

        $barber = User::barbers()
            ->where('active', true)
            ->where('attention_mode', 'llegada')
            ->find($data['barber_id']);

        if (! $barber) {
            throw ValidationException::withMessages([
                'barber_id' => 'El barbero seleccionado atiende por citas. Elige un barbero de llegada.',
            ]);
        }

        if (! empty($data['service_id']) && ! $barber->offersService((int) $data['service_id'])) {
            throw ValidationException::withMessages([
                'service_id' => 'Ese barbero no ofrece el servicio seleccionado. Elige otro servicio u otro barbero.',
            ]);
        }

        $turn = $turnService->createFor($barber, $data);
        $turn->load('service');
        $this->sendPaymentInfoIfVirtual($whatsApp, $settings, $data['client_phone'], $data['client_name'], $data['payment_method'], 'turno');

        StaffNotification::record(
            'turn_new',
            'Nuevo turno #'.$turn->turn_number.': '.$turn->client_name,
            'Con '.$barber->name.($turn->service ? ' · '.$turn->service->name : '').'. Pedido desde la página.',
            route('turns.index', [], false),
            $barber->id,
        );

        return redirect()
            ->route('public.booking', ['tipo' => 'turno'])
            ->with('booking_success', [
                'type' => 'turno',
                'title' => '¡Tu turno quedó registrado!',
                'client' => $turn->client_name,
                'barber' => $barber->name,
                'service' => $turn->service?->name,
                'turn_number' => $turn->turn_number,
                'status' => $turn->status === 'llamado' ? '¡Es tu turno! Puedes pasar' : 'Esperando ser llamado',
                'payment' => $this->enabledPaymentMethods($settings)[$data['payment_method']] ?? $data['payment_method'],
                'code' => 'TURNO-'.$turn->id,
                'track_url' => route('track.show', ['type' => 'turno', 'token' => $turn->public_token]),
                'track_type' => 'turno',
                'track_token' => $turn->public_token,
            ]);
    }

    /** Teléfono: entre 7 y 15 dígitos; se permiten espacios, guiones, paréntesis y el + inicial. */
    private function phoneRules(): array
    {
        return ['required', 'string', 'max:30', 'regex:/^\+?(?:[\s\-().]*\d){7,15}[\s\-().]*$/'];
    }

    private function enabledPaymentMethods(array $settings): array
    {
        $map = [
            'cash' => 'efectivo',
            'transfer' => 'transferencia',
            'nequi' => 'nequi',
            'daviplata' => 'daviplata',
            'breb' => 'breb',
        ];
        $methods = [];
        foreach ($map as $siteKey => $saleKey) {
            if (! empty($settings['payment_'.$siteKey]) && isset(Sale::PAYMENT_METHODS[$saleKey])) {
                $methods[$saleKey] = Sale::PAYMENT_METHODS[$saleKey];
            }
        }
        if (empty($methods)) {
            $methods['efectivo'] = Sale::PAYMENT_METHODS['efectivo'];
        }
        return $methods;
    }

    private function timeLabel(string $time): string
    {
        $moment = Carbon::parse($time);

        return $moment->format('g:i').' '.($moment->hour >= 12 ? 'p. m.' : 'a. m.');
    }

    private function isBusinessOpenNow(array $settings): bool
    {
        $now = now();
        if (SiteSetting::isClosedOn($now, $settings)) {
            return false;
        }
        $start = $settings['booking_start'] ?? '00:00';
        $end = $settings['booking_end'] ?? '23:59';
        $current = $now->format('H:i');
        return $current >= $start && $current <= $end;
    }

    private function sendPaymentInfoIfVirtual(WhatsAppService $whatsApp, array $settings, string $phone, string $clientName, string $method, string $tipo): void
    {
        $virtualAccounts = [
            'nequi' => $settings['account_nequi'] ?? '',
            'daviplata' => $settings['account_daviplata'] ?? '',
            'transferencia' => $settings['account_transfer'] ?? '',
            'breb' => $settings['account_breb'] ?? '',
        ];
        if (! array_key_exists($method, $virtualAccounts)) {
            return;
        }
        $account = trim((string) $virtualAccounts[$method]);
        $label = Sale::PAYMENT_METHODS[$method] ?? ucfirst($method);
        $business = $settings['brand_name'] ?? config('app.name', 'la barbería');
        if ($account === '') {
            $msg = "Hola {$clientName}, registraste tu {$tipo} en {$business} con pago por {$label}. Para consignar, por favor consulta el número/cuenta directamente con el negocio.";
        } else {
            $msg = "Hola {$clientName}, registraste tu {$tipo} en {$business} con pago por {$label}. Número/cuenta para consignar: {$account}.";
        }
        $whatsApp->sendText($phone, $msg);
    }
}
