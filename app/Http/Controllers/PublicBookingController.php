<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Sale;
use App\Models\SiteSetting;
use App\Models\Service;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Models\User;
use App\Services\BusinessHours;
use App\Services\ClientNotifier;
use App\Services\TurnService;
use App\Services\WhatsApp\BookingMessenger;
use App\Services\WhatsAppService;
use App\Support\ClientServiceAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicBookingController extends Controller
{
    public function availableTimes(Request $request)
    {
        $data = $request->validate([
            'barber_id' => ['required', 'integer', 'exists:users,id'],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('active', true)],
            'date' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(29)->toDateString()],
            'appointment_id' => ['nullable', 'integer'],
        ]);
        $barber = User::barbers()->where('active', true)->where('attention_mode', 'citas')->find($data['barber_id']);
        abort_if(! $barber, 404);

        // La duración del servicio decide qué horas caben antes del cierre y sin cruzarse con otras citas.
        $service = isset($data['service_id']) ? Service::active()->find($data['service_id']) : null;
        // Al reprogramar desde "Mis servicios", la propia cita del cliente no le quita horas.
        $own = isset($data['appointment_id'])
            ? ClientServiceAccess::owned($request, 'cita')->where('barber_id', $barber->id)->find($data['appointment_id'])
            : null;
        $serviceId = $own?->service_id ?? $service?->id;

        if ($serviceId && ! Service::active()->find($serviceId)) {
            throw ValidationException::withMessages(['service_id' => 'El servicio seleccionado ya no está disponible. Elige otro.']);
        }
        if ($serviceId && ! $barber->offersService((int) $serviceId)) {
            throw ValidationException::withMessages(['service_id' => 'Ese barbero no ofrece el servicio seleccionado.']);
        }

        return response()->json([
            'times' => Appointment::availableTimes($barber, Carbon::parse($data['date'])->toDateString(), $own?->id, $serviceId),
        ])->header('Cache-Control', 'no-store');
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
        $turnState = app(BusinessHours::class)->turnState();
        $businessOpenNow = $turnState['open'];

        $activeTab = $request->input('tipo') === 'turno' ? 'turno' : 'cita';
        $selectedBarberId = (int) $request->input('barbero', 0);

        return view('public.booking', compact(
            'services',
            'appointmentBarbers',
            'turnBarbers',
            'activeTab',
            'selectedBarberId',
            'paymentMethods',
            'businessOpenNow',
            'turnState'
        ));
    }

    public function storeAppointment(Request $request, WhatsAppService $whatsApp)
    {
        return $this->idempotent($request, fn () => $this->createAppointment($request, $whatsApp));
    }

    public function storeTurn(Request $request, TurnService $turnService, WhatsAppService $whatsApp)
    {
        return $this->idempotent($request, fn () => $this->createTurn($request, $turnService, $whatsApp));
    }

    private function createAppointment(Request $request, WhatsAppService $whatsApp)
    {
        $settings = SiteSetting::currentData();
        // Anti-spam: el campo oculto "website" solo lo llenan los robots. Se simula éxito sin guardar nada.
        if (filled($request->input('website'))) {
            return redirect()->route('public.booking');
        }

        $enabled = array_keys($this->enabledPaymentMethods($settings));
        // Hoy cuenta como día 1: el último día reservable es hoy + 29 (30 días calendario).
        $maxBookingDate = now()->addDays(29)->toDateString();

        $data = $request->validate([
            'barber_id' => ['required', 'exists:users,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'client_name' => ['required', 'string', 'max:120'],
            'client_phone' => $this->phoneRules(),
            'client_email' => ['nullable', 'email', 'max:150'],
            'whatsapp_consent' => ['nullable', 'boolean'],
            'payment_method' => ['required', Rule::in($enabled)],
            'appointment_date' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.$maxBookingDate],
            'appointment_time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'service_id.required' => 'Selecciona un servicio.',
            'service_id.exists' => 'El servicio seleccionado ya no está disponible. Elige otro.',
            'payment_method.required' => 'Selecciona un método de pago.',
            'payment_method.in' => 'El método de pago seleccionado no está disponible.',
            'appointment_date.before_or_equal' => 'Solo puedes agendar hasta 30 días calendario desde hoy.',
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

        if (! $barber->offersService((int) $data['service_id'])) {
            throw ValidationException::withMessages([
                'service_id' => 'Ese barbero no ofrece el servicio seleccionado. Elige otro servicio u otro barbero.',
            ]);
        }

        $appointmentDate = Carbon::parse($data['appointment_date'])->toDateString();
        if (! in_array($data['appointment_time'], Appointment::availableTimes($barber, $appointmentDate, null, (int) $data['service_id']), true)) {
            throw ValidationException::withMessages(['appointment_time' => 'Ese horario no está disponible. Elige otra hora.']);
        }

        // Se vuelve a comprobar el horario en el servidor, con bloqueo, al momento de guardar.
        // El precio se congela con el servicio para que el historial no cambie si luego se edita.
        $appointment = Appointment::reserveSlot([
            'barber_id' => $barber->id,
            'service_id' => $data['service_id'],
            'service_price' => Service::whereKey($data['service_id'])->value('price'),
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
        ClientServiceAccess::remember($request, $appointment);
        $this->recordConsents($appointment, (bool) ($data['whatsapp_consent'] ?? false));
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
            Carbon::parse($appointment->appointment_date)->locale('es')->translatedFormat('l j \d\e F').' a las '.$this->timeLabel($appointment->appointment_time).' con '.$barber->name.' · '.$appointment->service->name.'. Pendiente de confirmar.',
            route('appointments.index', ['date' => $appointmentDate], false),
            $barber->id,
        );

        $this->rememberSuccess($request, [
            'type' => 'cita',
            'title' => '¡Tu cita quedó registrada!',
            'client' => $appointment->client_name,
            'barber' => $barber->name,
            'service' => $appointment->service->name,
            'date' => Carbon::parse($appointment->appointment_date)->locale('es')->translatedFormat('l, d \d\e F'),
            'time' => $this->timeLabel($appointment->appointment_time),
            'status' => 'Pendiente de confirmación',
            'payment' => $this->enabledPaymentMethods($settings)[$data['payment_method']] ?? $data['payment_method'],
            'code' => 'CITA-'.$appointment->id,
            'track_url' => route('track.show', ['type' => 'cita', 'token' => $appointment->public_token]),
            'track_type' => 'cita',
            'track_token' => $appointment->public_token,
            'access_url' => ClientServiceAccess::recoveryUrl($appointment),
        ]);

        // Sin panel ni aviso de "cita registrada": va directo a Mis servicios, donde ve su cita y puede confirmarla.
        return redirect()->route('my-services.index');
    }

    private function createTurn(Request $request, TurnService $turnService, WhatsAppService $whatsApp)
    {
        $settings = SiteSetting::currentData();
        // Anti-spam: el campo oculto "website" solo lo llenan los robots. Se simula éxito sin guardar nada.
        if (filled($request->input('website'))) {
            return redirect()->route('public.booking');
        }

        $enabled = array_keys($this->enabledPaymentMethods($settings));

        $data = $request->validate([
            'barber_id' => ['required', 'exists:users,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'client_name' => ['required', 'string', 'max:120'],
            'client_phone' => $this->phoneRules(),
            'client_email' => ['nullable', 'email', 'max:150'],
            'whatsapp_consent' => ['nullable', 'boolean'],
            'payment_method' => ['required', Rule::in($enabled)],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'service_id.required' => 'Selecciona un servicio.',
            'service_id.exists' => 'El servicio seleccionado ya no está disponible. Elige otro.',
            'payment_method.required' => 'Selecciona un método de pago.',
            'payment_method.in' => 'El método de pago seleccionado no está disponible.',
            'client_phone.regex' => 'Escribe un número de teléfono válido (solo números, por ejemplo 300 123 4567).',
            'client_email.email' => 'Escribe un correo electrónico válido o deja el campo vacío.',
        ]);

        // Se vuelve a validar en el servidor: nadie puede saltarse el límite llamando directo a la ruta.
        $turnState = app(BusinessHours::class)->turnState();
        if (! $turnState['open']) {
            throw ValidationException::withMessages(['barber_id' => $turnState['message']]);
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

        if (! $barber->offersService((int) $data['service_id'])) {
            throw ValidationException::withMessages([
                'service_id' => 'Ese barbero no ofrece el servicio seleccionado. Elige otro servicio u otro barbero.',
            ]);
        }

        $turn = $turnService->createFor($barber, $data);
        ClientServiceAccess::remember($request, $turn);
        $this->recordConsents($turn, (bool) ($data['whatsapp_consent'] ?? false));
        $turn->load('service');
        $this->sendPaymentInfoIfVirtual($whatsApp, $settings, $data['client_phone'], $data['client_name'], $data['payment_method'], 'turno');

        StaffNotification::record(
            'turn_new',
            'Nuevo turno #'.$turn->turn_number.': '.$turn->client_name,
            'Con '.$barber->name.' · '.$turn->service->name.'. Pedido desde la página.',
            route('turns.index', [], false),
            $barber->id,
        );

        $this->rememberSuccess($request, [
            'type' => 'turno',
            'title' => '¡Tu turno quedó registrado!',
            'client' => $turn->client_name,
            'barber' => $barber->name,
            'service' => $turn->service->name,
            'turn_number' => $turn->turn_number,
            'status' => $turn->status === 'llamado' ? '¡Es tu turno! Puedes pasar' : 'Esperando ser llamado',
            'payment' => $this->enabledPaymentMethods($settings)[$data['payment_method']] ?? $data['payment_method'],
            'code' => 'TURNO-'.$turn->id,
            'track_url' => route('track.show', ['type' => 'turno', 'token' => $turn->public_token]),
            'track_type' => 'turno',
            'track_token' => $turn->public_token,
            'access_url' => ClientServiceAccess::recoveryUrl($turn),
        ]);

        // Sin pantalla de confirmación: va directo a Mis servicios, donde ve su turno (igual que la cita).
        return redirect()->route('my-services.index');
    }

    /**
     * Datos de la reserva recién hecha. Ya no hay pantalla de confirmación (cita y turno van a Mis
     * servicios); solo se usan en esta petición para la caché de idempotencia y para volver a vincular
     * el dispositivo si la respuesta se perdió.
     */
    private function rememberSuccess(Request $request, array $data): void
    {
        // En la petición actual, para decidir qué se guarda en la caché de idempotencia.
        $request->attributes->set('booking_success', $data);
    }

    /**
     * Si la respuesta original de la reserva nunca llegó al navegador, tampoco llegó la cookie de
     * «Mis servicios». Al reintentar o consultar el estado se vuelve a vincular este dispositivo
     * con la reserva ya hecha, sin duplicarla y sin perder el seguimiento.
     */
    private function relinkDevice(Request $request, array $success): void
    {
        $type = $success['track_type'] ?? null;
        $token = $success['track_token'] ?? null;
        if (! in_array($type, ['cita', 'turno'], true) || ! is_string($token) || $token === '') {
            return;
        }

        $subject = ($type === 'turno' ? Turn::query() : Appointment::query())
            ->where('public_token', $token)->first();
        if ($subject) {
            ClientServiceAccess::remember($request, $subject);
        }
    }

    /** Teléfono: entre 7 y 15 dígitos; se permiten espacios, guiones, paréntesis y el + inicial. */
    /**
     * ¿Ya se procesó la reserva con esta clave? Lo consulta el navegador cuando se perdió la respuesta
     * (sin internet, tiempo agotado). Si quedó hecha, vuelve a vincular el dispositivo y responde
     * a dónde ir. La clave es un UUID aleatorio que solo conoce quien llenó el formulario.
     */
    public function bookingStatus(Request $request, string $key)
    {
        abort_unless(Str::isUuid($key), 404);

        $stored = Cache::get(self::IDEMPOTENCY_PREFIX.$key);
        if ($stored) {
            $this->rememberSuccess($request, $stored['success']);
            $this->relinkDevice($request, $stored['success']);

            return response()->json(['status' => 'done', 'redirect' => $stored['redirect']]);
        }

        // Todavía se está procesando (la otra petición tiene el candado).
        $lock = Cache::lock(self::lockName($key), 30);
        if (! $lock->get()) {
            return response()->json(['status' => 'processing']);
        }
        $lock->release();

        return response()->json(['status' => 'none']);
    }

    private const IDEMPOTENCY_PREFIX = 'reserva:';

    private static function lockName(string $key): string
    {
        return 'reserva-candado:'.$key;
    }

    /**
     * Reserva idempotente: si llega dos veces la misma clave (doble clic, reintento tras perder la
     * conexión, reenvío del formulario), la segunda no crea nada y devuelve la reserva ya hecha.
     * Con fetch (Accept: JSON) responde {redirect}; sin JavaScript, la redirección de siempre.
     */
    private function idempotent(Request $request, \Closure $create)
    {
        $key = (string) $request->input('idempotency_key', '');
        if (! Str::isUuid($key)) {
            return $this->asBookingResponse($request, $create());
        }

        $cacheKey = self::IDEMPOTENCY_PREFIX.$key;
        $lock = Cache::lock(self::lockName($key), 30);

        try {
            $lock->block(15); // si hay otra petición con la misma clave en curso, se espera su resultado
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            Log::warning('reservas.idempotencia_ocupada');

            return response()->json(['message' => 'Tu reserva se está procesando. Espera un momento.'], 409);
        }

        try {
            if ($stored = Cache::get($cacheKey)) {
                Log::info('reservas.reintento_sin_duplicar');
                $this->rememberSuccess($request, $stored['success']);
                $this->relinkDevice($request, $stored['success']);

                return $this->asBookingResponse($request, redirect()->to($stored['redirect']));
            }

            $response = $create();

            // Solo se recuerda una reserva hecha de verdad (no errores ni el campo trampa).
            $success = $request->attributes->get('booking_success');
            if ($success && $response instanceof \Illuminate\Http\RedirectResponse) {
                Cache::put($cacheKey, ['success' => $success, 'redirect' => $response->getTargetUrl()], now()->addDay());
            }

            return $this->asBookingResponse($request, $response);
        } finally {
            $lock->release();
        }
    }

    private function asBookingResponse(Request $request, $response)
    {
        if ($request->expectsJson() && $response instanceof \Illuminate\Http\RedirectResponse) {
            return response()->json(['status' => 'done', 'redirect' => $response->getTargetUrl()]);
        }

        return $response;
    }

    /**
     * Prueba de la autorización (Ley 1581): enviar el formulario tras leer el aviso es una conducta
     * inequívoca para gestionar la reserva; los avisos por WhatsApp se autorizan aparte, con su casilla.
     */
    private function recordConsents(\Illuminate\Database\Eloquent\Model $subject, bool $whatsapp): void
    {
        \App\Models\ConsentRecord::grant($subject, 'tratamiento_reserva', 'formulario_web');
        if ($whatsapp) {
            \App\Models\ConsentRecord::grant($subject, 'avisos_whatsapp', 'casilla_web');
        }
    }

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
