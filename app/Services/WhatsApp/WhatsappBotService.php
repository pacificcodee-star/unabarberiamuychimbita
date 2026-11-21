<?php

namespace App\Services\WhatsApp;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\ClientNotifier;
use App\Services\TurnQueueService;
use App\Services\TurnService;
use App\Support\AttendanceConfirmation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bot automático de WhatsApp de la barbería.
 *
 * Todo se navega con números: 98 = menú principal, 99 = paso anterior (0 solo sirve para
 * "cualquier barbero").
 * Reserva citas y turnos, consulta, modifica y cancela con confirmación,
 * y todo se guarda con los mismos servicios que usa el panel.
 */
class WhatsappBotService
{
    private const CONFIRM_WORDS = ['si', 'sipi', 'claro', 'ok', 'okay', 'dale', 'listo', 'confirmo', 'confirmar', 'confirmada', 'confirmado', 'asisto', 'yes', 'perfecto'];

    public function __construct(
        private readonly WhatsAppInbox $inbox,
        private readonly ClientNotifier $notifier,
        private readonly TurnQueueService $queue,
        private readonly TurnService $turns,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('services.evolution.bot_enabled');
    }

    /** Procesa un mensaje entrante y envía la respuesta si corresponde. */
    public function handle(WhatsAppConversation $conversation, WhatsAppMessage $message): ?string
    {
        if (! $this->enabled() || ! $conversation->bot_active) {
            return null;
        }

        if ($message->direction !== WhatsAppMessage::DIRECTION_IN) {
            return null;
        }

        $text = trim((string) ($message->body ?? ''));

        try {
            $reply = $text === ''
                ? $this->mainMenu($conversation)
                : $this->respond($conversation, $text);
        } catch (Throwable $e) {
            Log::error('WhatsApp bot: no se pudo procesar el mensaje', [
                'phone' => \App\Support\Pii::phone($conversation->phone),
                'error' => $e->getMessage(),
            ]);
            $this->saveFlow($conversation->phone, ['step' => 'error_options', 'data' => [], 'history' => []]);
            $reply = $this->errorText();
        }

        if ($reply === null) {
            return null;
        }

        $this->inbox->send($conversation, $reply);

        return $reply;
    }

    // ------------------------------------------------------------------------------------------
    // Entrada y navegación
    // ------------------------------------------------------------------------------------------

    private function respond(WhatsAppConversation $conversation, string $text): ?string
    {
        // Revocación (Ley 1581): "BAJA" o "STOP" en cualquier momento corta los avisos por WhatsApp.
        if (in_array(trim($this->normalize($text), " .,!¡?¿"), self::OPT_OUT_WORDS, true)) {
            return $this->optOut($conversation);
        }

        // Encuesta de satisfacción en curso: solo toma el mensaje si es una respuesta válida.
        if (($surveyReply = app(\App\Services\Surveys\SurveyConversation::class)->handle($conversation->phone, $text)) !== null) {
            return $surveyReply;
        }

        $numeric = preg_match('/^\s*(\d{1,3})\s*$/u', $text, $match) ? (int) $match[1] : null;
        $flow = $this->flow($conversation->phone);

        if ($numeric === 98) {
            return $this->mainMenu($conversation);
        }

        if ($flow === null) {
            return $this->entry($conversation, $text, $numeric);
        }

        if ($numeric === 99) {
            return $this->back($conversation, $flow);
        }

        if (($flow['step'] ?? '') === 'book_name') {
            // Mientras pide el nombre solo se atienden pedidos claros (cancelar, asesor);
            // así "Hola, soy Ana Gómez" sigue contando como nombre.
            $t = $this->normalize($text);
            if ($this->has($t, ['cancelar', 'cancela', 'anular', 'anulo', 'no podre', 'no puedo asistir', 'no asistire', 'no llegare', 'no puedo ir', 'asesor', 'humano', 'persona real'])) {
                return $this->freeTextIntent($conversation, $t);
            }

            return $this->handleBookName($conversation, $flow, $text);
        }

        // Cancelación: pasos que esperan texto (nombre y apellido, o los 4 últimos dígitos del teléfono).
        if (($flow['step'] ?? '') === 'cancel_name') {
            $t = $this->normalize($text);
            if ($this->has($t, ['asesor', 'humano', 'persona real', 'menu'])) {
                return $this->freeTextIntent($conversation, $t);
            }

            return $this->handleCancelName($conversation, $flow, $text);
        }

        if (($flow['step'] ?? '') === 'cancel_verify') {
            return $this->handleCancelVerify($conversation, $flow, $text);
        }

        if ($numeric === null) {
            // Texto libre en medio de un menú: si pide algo conocido (precios, cancelar, reservar…),
            // se atiende directamente en vez de responder "opción no válida".
            return $this->freeTextIntent($conversation, $this->normalize($text))
                ?? $this->invalidOption($conversation, $flow);
        }

        return $this->step($conversation, $flow, $numeric);
    }

    /**
     * Frases que el cliente escribe con sus palabras, en cualquier momento de la conversación.
     * Devuelve null si no reconoce nada (el menú sigue igual).
     */
    private function freeTextIntent(WhatsAppConversation $conversation, string $t): ?string
    {
        if ($t === '') {
            return null;
        }

        if ($this->has($t, ['cancelar', 'cancela', 'anular', 'anulo', 'no podre', 'no puedo asistir', 'no asistire', 'no llegare', 'no puedo ir'])) {
            return $this->startCancel($conversation);
        }

        if ($this->has($t, ['asesor', 'humano', 'persona real', 'hablar con alguien'])) {
            return $this->advisorPrompt($conversation);
        }

        if ($this->has($t, ['como voy', 'cuanto falta', 'mi turno', 'estado de mi turno', 'en que puesto', 'cuantos faltan'])) {
            if ($turn = $this->activeTurn($conversation->phone)) {
                return $this->turnActionsText($conversation, $turn);
            }

            return $this->showConsult($conversation);
        }

        if ($this->has($t, ['horario', 'horarios', 'a que hora', 'abren', 'cierran', 'atienden', 'hora de atencion'])) {
            return $this->hoursText();
        }

        if ($this->has($t, ['ubicacion', 'direccion', 'donde quedan', 'donde estan', 'donde es', 'como llego', 'mapa'])) {
            return $this->locationText();
        }

        if ($this->has($t, ['precio', 'precios', 'cuanto cuesta', 'cuanto vale', 'cuanto sale', 'valor', 'tarifa', 'tarifas', 'servicios'])) {
            return $this->showServices($conversation);
        }

        if ($this->has($t, ['tomar turno', 'un turno', 'sacar turno', 'pedir turno', 'turno'])) {
            return $this->startBooking($conversation, 'turno');
        }

        if ($this->has($t, ['reservar', 'reserva', 'agendar', 'agenda', 'una cita', 'sacar cita', 'pedir cita', 'separar', 'cita'])) {
            return $this->startBooking($conversation, 'cita')
                ."\n\n".'🌐 También puedes reservar en línea: '.route('public.booking');
        }

        if ($this->has($t, ['barbero', 'barberos'])) {
            return $this->showBarbers($conversation);
        }

        if ($this->has($t, ['hola', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches', 'menu', 'inicio', 'ayuda', 'opciones'])) {
            return $this->mainMenu($conversation);
        }

        return null;
    }

    /** Horario de hoy y de la semana, desde Configuración del negocio. */
    private function hoursText(): string
    {
        $settings = SiteSetting::currentData();
        $lines = collect(SiteSetting::groupedHours($settings))
            ->map(fn (array $row) => '• *'.$row['label'].'*: '.$row['value'])
            ->implode("\n");

        return '🕒 *Horarios de atención*'."\n\n"
            .'Hoy: *'.SiteSetting::todayHours($settings).'*'."\n\n"
            .($lines !== '' ? $lines."\n\n" : '')
            .'98. 🏠 Volver al menú principal.';
    }

    /** Dirección del negocio con enlace de Google Maps. */
    private function locationText(): string
    {
        $settings = SiteSetting::currentData();
        $address = trim(($settings['address'] ?? '').', '.($settings['city'] ?? ''), ' ,');
        $maps = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address);

        return '📍 *¿Dónde estamos?*'."\n\n"
            .($address !== '' ? $address."\n" : '')
            .$maps."\n\n"
            .'98. 🏠 Volver al menú principal.';
    }

    /** Primer mensaje sin flujo activo: avisos pendientes o el menú de bienvenida. */
    private function entry(WhatsAppConversation $conversation, string $text, ?int $numeric): string
    {
        if ($numeric !== null) {
            return $this->reminderOrMenu($conversation, $numeric);
        }

        $t = $this->normalize($text);

        if ($this->has($t, ['cancelar', 'cancela', 'anular', 'anulo', 'no podre', 'no puedo asistir', 'no asistire', 'no llegare'])) {
            return $this->startCancel($conversation);
        }

        if ($this->isAffirmative($t) || $this->has($t, ['confirmar', 'confirmo', 'asisto'])) {
            if ($appointment = $this->pendingAppointment($conversation->phone) ?? $this->activeAppointment($conversation->phone)) {
                return $this->confirmAppointment($conversation, $appointment);
            }

            if ($turn = $this->activeTurn($conversation->phone)) {
                return $this->confirmTurn($conversation, $turn);
            }
        }

        return $this->freeTextIntent($conversation, $t) ?? $this->mainMenu($conversation);
    }

    /** Responde al recordatorio de cita (30 min) o de turno (15 min) cuando el cliente contesta 1, 2 o 3. */
    private function reminderOrMenu(WhatsAppConversation $conversation, int $numeric): string
    {
        $appointment = $this->activeAppointment($conversation->phone);

        if ($appointment && $appointment->startsAt()->between(now()->subMinutes(15), now()->addMinutes(120))) {
            if ($numeric === 1) {
                return $this->confirmAppointment($conversation, $appointment);
            }
            if ($numeric === 2) {
                return $this->cancelConfirmFor($conversation, 'appointment', $appointment);
            }
            if ($numeric === 3) {
                return $this->advisorPrompt($conversation);
            }
        }

        $turn = $this->activeTurn($conversation->phone);

        if ($turn && $this->turnIsNear($turn)) {
            if ($numeric === 1) {
                return $this->confirmTurn($conversation, $turn);
            }
            if ($numeric === 2) {
                return $this->cancelConfirmFor($conversation, 'turn', $turn);
            }
            if ($numeric === 3) {
                return $this->turnActionsText($conversation, $turn);
            }
        }

        return $this->menuOption($conversation, $numeric);
    }

    private function step(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        return match ($flow['step'] ?? 'menu') {
            'menu' => $this->menuOption($conversation, $n),
            'book_service' => $this->handleBookService($conversation, $flow, $n),
            'book_barber' => $this->handleBookBarber($conversation, $flow, $n),
            'book_date' => $this->handleBookDate($conversation, $flow, $n),
            'book_time' => $this->handleBookTime($conversation, $flow, $n),
            'book_confirm' => $this->handleBookConfirm($conversation, $flow, $n),
            'book_done' => $this->handleDone($conversation, $n),
            'done' => $this->handleDone($conversation, $n),
            'services' => $this->handleServices($conversation, $n),
            'barbers' => $this->handleBarbers($conversation, $n),
            'consult' => $this->handleConsult($conversation, $n),
            'modify' => $this->handleModify($conversation, $flow, $n),
            'mod_service' => $this->handleModService($conversation, $flow, $n),
            'mod_barber' => $this->handleModBarber($conversation, $flow, $n),
            'mod_date' => $this->handleModDate($conversation, $flow, $n),
            'mod_time' => $this->handleModTime($conversation, $flow, $n),
            'cancel' => $this->handleCancelMenu($conversation, $n),
            'cancel_pick' => $this->handleCancelPick($conversation, $flow, $n),
            'cancel_confirm' => $this->handleCancelConfirm($conversation, $flow, $n),
            'cancel_done' => $this->handleCancelDone($conversation, $n),
            'advisor' => $this->handleAdvisor($conversation, $n),
            'no_bookings' => $this->handleNoBookings($conversation, $n),
            'turn_actions' => $this->handleTurnActions($conversation, $flow, $n),
            'invalid' => $this->handleInvalid($conversation, $flow, $n),
            'slot_taken' => $this->handleSlotTaken($conversation, $flow, $n),
            'error_options' => $this->handleErrorOptions($conversation, $n),
            default => $this->mainMenu($conversation),
        };
    }

    private function back(WhatsAppConversation $conversation, array $flow): string
    {
        $history = (array) ($flow['history'] ?? []);

        if ($history === []) {
            return $this->mainMenu($conversation);
        }

        $previous = array_pop($history);
        $flow = [
            'step' => $previous['step'] ?? 'menu',
            'data' => $previous['data'] ?? [],
            'history' => $history,
        ];
        $this->saveFlow($conversation->phone, $flow);

        return $this->render($conversation, $flow);
    }

    private function render(WhatsAppConversation $conversation, array $flow): string
    {
        $data = (array) ($flow['data'] ?? []);

        return match ($flow['step'] ?? 'menu') {
            'menu' => $this->menuText(),
            'book_name' => $this->bookingNameText($this->bookingMode($data)),
            'book_service' => $this->bookingServiceText(null, $this->bookingMode($data)),
            'book_barber' => $this->bookingBarberText($data),
            'book_date' => $this->bookingDateText($data),
            'book_time' => $this->bookingTimeText($data),
            'book_confirm' => $this->bookingConfirmText($data),
            'book_done', 'done' => $this->doneText($data),
            'services' => $this->servicesText(),
            'barbers' => $this->barbersText(),
            'consult' => $this->consultText(),
            'modify' => $this->modifyText($data),
            'mod_service' => $this->modServiceText(),
            'mod_barber' => $this->modBarberText($data),
            'mod_date' => $this->modDateText($data),
            'mod_time' => $this->modTimeText($data),
            'cancel' => $this->cancelText(),
            'cancel_name' => $this->cancelNameText((string) ($data['kind'] ?? 'any')),
            'cancel_pick' => $this->cancelPickText($data),
            'cancel_verify' => $this->cancelVerifyText(),
            'cancel_confirm' => $this->cancelConfirmText($data),
            'cancel_done' => $this->cancelDoneText(),
            'advisor' => $this->advisorText(),
            'no_bookings' => $this->noBookingsText(),
            'turn_actions' => $this->turnActionsText($conversation, $this->subjectTurn($data)),
            'invalid' => $this->invalidText(),
            'slot_taken' => $this->slotTakenText(),
            'error_options' => $this->errorText(),
            default => $this->menuText(),
        };
    }

    private function mainMenu(WhatsAppConversation $conversation): string
    {
        $this->saveFlow($conversation->phone, ['step' => 'menu', 'data' => [], 'history' => []]);

        return $this->menuText();
    }

    private function menuOption(WhatsAppConversation $conversation, int $n): string
    {
        $this->saveFlow($conversation->phone, ['step' => 'menu', 'data' => [], 'history' => []]);

        $offHours = ! $this->businessOpenNow();

        if ($offHours) {
            return match ($n) {
                1 => $this->startBooking($conversation),
                2 => $this->showServices($conversation),
                3 => $this->showBarbers($conversation),
                4 => $this->farewell($conversation),
                default => $this->invalidOption($conversation, ['step' => 'menu', 'data' => [], 'history' => []]),
            };
        }

        return match ($n) {
            1 => $this->startBooking($conversation, 'cita'),
            2 => $this->startBooking($conversation, 'turno'),
            3 => $this->showServices($conversation),
            4 => $this->showBarbers($conversation),
            5 => $this->showConsult($conversation),
            6 => $this->startModify($conversation),
            7 => $this->startCancelMenu($conversation),
            8 => $this->advisorPrompt($conversation),
            default => $this->invalidOption($conversation, ['step' => 'menu', 'data' => [], 'history' => []]),
        };
    }

    // ------------------------------------------------------------------------------------------
    // Reservar: nombre -> servicio -> barbero -> (cita: fecha -> hora -> confirmación | turno: listo)
    // "cita" solo muestra barberos que atienden con cita; "turno" solo los que atienden por llegada.
    // ------------------------------------------------------------------------------------------

    private function startBooking(WhatsAppConversation $conversation, string $mode = 'cita'): string
    {
        $mode = $mode === 'turno' ? 'turno' : 'cita';

        if ($mode === 'turno' && ! $this->businessOpenNow()) {
            return '🌙 En este momento estamos fuera de horario, así que no es posible tomar un turno para hoy.'."\n\n"
                .'Puedes *agendar una cita con hora* para otro día.'."\n\n"
                .$this->menuText();
        }

        $services = Service::query()->active()->orderBy('name')->get();

        if ($services->isEmpty()) {
            return '⚠️ Estamos actualizando nuestro catálogo de servicios. Escríbenos en un rato y con gusto te agendamos.'."\n\n"
                .$this->menuText();
        }

        $current = $this->flow($conversation->phone);
        $name = trim((string) ($current['data']['client_name'] ?? ''));

        if ($name === '') {
            $flow = ['step' => 'book_name', 'data' => ['mode' => $mode], 'history' => [['step' => 'menu', 'data' => []]]];
            $this->saveFlow($conversation->phone, $flow);

            return $this->bookingNameText($mode);
        }

        // Si el cliente solo está corrigiendo su reserva, se conserva el nombre ya escrito.
        $flow = ['step' => 'book_service', 'data' => ['client_name' => $name, 'mode' => $mode], 'history' => [['step' => 'menu', 'data' => []]]];
        $this->saveFlow($conversation->phone, $flow);

        return $this->bookingServiceText(null, $mode);
    }

    private function bookingNameText(string $mode = 'cita'): string
    {
        return ($mode === 'turno' ? '¡Listo, vamos a tomar tu turno! 🎟️' : '¡Excelente decisión! Vamos a agendar tu cita. 📅')."\n\n"
            .'Antes de continuar, escribe tu *nombre y apellido*.'."\n\n"
            .'Con ellos registraremos tu reserva a tu nombre. 📝'."\n\n"
            .'Ejemplo: *María Gómez*.'."\n\n"
            .'98. 🏠 Volver al menú principal.';
    }

    private function handleBookName(WhatsAppConversation $conversation, array $flow, string $text): string
    {
        $name = $this->cleanPersonName($text);

        if ($name === null) {
            return '⚠️ Necesito tu *nombre y apellido* para continuar.'."\n\n"
                .'Escríbelos juntos, por ejemplo: *María Gómez*.'."\n\n"
                .'98. 🏠 Volver al menú principal.';
        }

        $conversation->update(['client_name' => $name]);

        $mode = $this->bookingMode($flow['data'] ?? []);
        $flow['data'] = ['client_name' => $name, 'mode' => $mode];
        $flow = $this->enter($conversation->phone, $flow, 'book_service', $flow['data']);

        return $this->bookingServiceText('¡Gracias, *'.$name.'*! 😊', $mode);
    }

    /** Tipo de reserva en curso: "cita" (con hora) o "turno" (por orden de llegada). */
    private function bookingMode(array $data): string
    {
        return ($data['mode'] ?? 'cita') === 'turno' ? 'turno' : 'cita';
    }

    private function bookingServiceText(?string $lead = null, string $mode = 'cita'): string
    {
        $services = Service::query()->active()->orderBy('name')->get()->values();

        if ($services->isEmpty()) {
            return '⚠️ Estamos actualizando nuestro catálogo de servicios. Escríbenos en un rato y con gusto te agendamos.';
        }

        $lines = $services->map(fn (Service $service, int $index) => ($index + 1).'. *'.$service->name.'* — '.$this->money($service->price))->implode("\n");

        return ($lead ?? '¡Excelente decisión! ✨')."\n\n"
            .($mode === 'turno'
                ? '🎟️ Vamos a tomar tu *turno para hoy* en *'.$this->brand()."* (por orden de llegada).\n\n"
                : '📅 Vamos a agendar tu *cita con hora* en *'.$this->brand()."*.\n\n")
            .'✂️ Selecciona el servicio que deseas realizarte:'."\n\n"
            .$lines."\n\n"
            .'98. 🏠 Volver al menú principal.'."\n\n"
            .'👉 Responde con el *número* del servicio.';
    }

    private function handleBookService(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $services = Service::query()->active()->orderBy('name')->get()->values();

        if ($services->isEmpty()) {
            return $this->mainMenu($conversation);
        }

        $service = $services->get($n - 1);

        if (! $service) {
            return $this->invalidOption($conversation, $flow);
        }

        $mode = $this->bookingMode($flow['data'] ?? []);
        $barbers = $this->barbersForMode($service->id, $mode);

        if ($barbers->isEmpty()) {
            $other = $mode === 'turno' ? 'agendar una *cita con hora* (opción 1)' : 'tomar un *turno* por orden de llegada (opción 2)';

            return '⚠️ Para *'.$service->name.'* no tenemos barberos que atiendan '
                .($mode === 'turno' ? '*por turno*' : '*con cita*').' en este momento.'."\n\n"
                .'Puedes '.$other.'.'."\n\n"
                .$this->menuText();
        }

        $clientName = $flow['data']['client_name'] ?? null;
        $flow['data'] = ['service_id' => $service->id, 'client_name' => $clientName, 'mode' => $mode];
        $flow = $this->enter($conversation->phone, $flow, 'book_barber', [
            'service_id' => $service->id,
            'client_name' => $clientName,
            'mode' => $mode,
            'barbers' => $barbers->pluck('id')->all(),
        ]);

        return $this->bookingBarberText($flow['data']);
    }

    private function bookingBarberText(array $data): string
    {
        $barbers = $this->barbersByIds((array) ($data['barbers'] ?? []));

        if ($barbers->isEmpty()) {
            return $this->menuText();
        }

        $service = Service::find($data['service_id'] ?? 0);

        $lines = $barbers->values()
            ->map(fn (User $barber, int $index) => ($index + 1).'. *'.$barber->name.'*'.($barber->specialty ? ' — '.$barber->specialty : ''))
            ->implode("\n");

        $turno = $this->bookingMode($data) === 'turno';

        return '¡Perfecto! ✅'."\n\n"
            .($service ? $this->serviceIncludes($service)."\n\n" : '')
            .($turno
                ? '💈 Estos barberos atienden *por turno* (orden de llegada). ¿Con quién quieres tu turno?'
                : '💈 Estos barberos atienden *con cita*. Selecciona al profesional que deseas que te atienda.')."\n\n"
            .$lines."\n\n"
            .($turno ? '0. 🙋 El que tenga menos espera.'."\n" : '')
            .'99. ⬅️ Volver al paso anterior.'."\n\n"
            .'👉 Responde con el *número* del barbero.';
    }

    /** Detalle del servicio elegido (precio, duración y lo que incluye) antes de escoger al barbero. */
    private function serviceIncludes(Service $service): string
    {
        $duration = (int) $service->duration_minutes;
        $lines = ['✂️ *'.$service->name.'* — *'.$this->money($service->price).'*'.($duration > 0 ? ' · '.$duration.' min' : '')];

        $included = $service->includedItems();
        $notes = $service->descriptionNotes();

        if ($included !== []) {
            $lines[] = '';
            $lines[] = '✅ *Incluye:*';
            foreach ($included as $item) {
                $lines[] = '• '.$item;
            }
            if ($notes !== []) {
                $lines[] = '';
                foreach ($notes as $note) {
                    $lines[] = str_starts_with($note, 'Importante:')
                        ? '*Importante:*'.substr($note, strlen('Importante:'))
                        : $note;
                }
            }
        } elseif ($notes !== []) {
            $lines[] = '';
            foreach ($notes as $note) {
                $lines[] = str_starts_with($note, 'Importante:')
                    ? '*Importante:*'.substr($note, strlen('Importante:'))
                    : $note;
            }
        }

        return implode("\n", $lines);
    }

    private function handleBookBarber(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $barbers = $this->barbersByIds((array) ($flow['data']['barbers'] ?? []));

        if ($barbers->isEmpty()) {
            return $this->mainMenu($conversation);
        }

        $mode = $this->bookingMode($flow['data'] ?? []);

        if ($n === 0 && $mode === 'turno') {
            $barber = $this->quickestTurnBarber($barbers);

            if (! $barber) {
                return '⚠️ En este momento no hay barberos *por turno* disponibles para este servicio.'."\n\n"
                    .'Elige uno de los barberos de la lista:'."\n\n"
                    .$this->bookingBarberText($flow['data']);
            }
        } else {
            $barber = $n >= 1 ? $barbers->get($n - 1) : null;
        }

        if (! $barber) {
            return $this->invalidOption($conversation, $flow);
        }

        $data = [
            'service_id' => $flow['data']['service_id'] ?? null,
            'barber_id' => $barber->id,
            'client_name' => $flow['data']['client_name'] ?? null,
            'mode' => $mode,
        ];

        // El tipo de reserva lo decide lo que eligió el cliente (cita o turno), no el barbero:
        // la lista ya solo muestra barberos de ese tipo.
        if ($mode === 'turno') {
            return $this->bookTurn($conversation, $barber, $data['service_id'], $data['client_name']);
        }

        $dates = $this->bookingDates($barber);

        if ($dates === []) {
            return '⚠️ No encontré fechas con disponibilidad para *'.$barber->name.'* en los próximos días.'."\n\n"
                .$this->menuText();
        }

        $data['dates'] = $dates;
        $flow = $this->enter($conversation->phone, $flow, 'book_date', $data);

        return $this->bookingDateText($flow['data']);
    }

    /**
     * "Cualquier barbero": solo quienes atienden por turno, ordenados por la fila más corta
     * y, en empate, por la hora estimada en que quedarían libres.
     */
    private function quickestTurnBarber(\Illuminate\Support\Collection $barbers): ?User
    {
        $first = $barbers
            ->filter(fn (User $barber) => $barber->attention_mode === 'llegada')
            ->map(function (User $barber) {
                $waiting = Turn::query()
                    ->where('barber_id', $barber->id)
                    ->whereDate('turn_date', now()->toDateString())
                    ->whereIn('status', TurnQueueService::OPEN)
                    ->count();

                $estimates = $this->queue->estimates((int) $barber->id);
                $last = $estimates === [] ? null : end($estimates);

                return [
                    'barber' => $barber,
                    'waiting' => $waiting,
                    'ready_at' => $last ? $last['end_at']->getTimestamp() : now()->getTimestamp(),
                ];
            })
            ->sortBy([['waiting', 'asc'], ['ready_at', 'asc']])
            ->values()
            ->first();

        return $first['barber'] ?? null;
    }

    /** El barbero atiende por orden de llegada: se crea el turno de una vez. */
    private function bookTurn(WhatsAppConversation $conversation, User $barber, ?int $serviceId, ?string $clientName = null): string
    {
        if (! $this->businessOpenNow()) {
            return '🌙 En este momento estamos fuera de horario, así que no es posible asignarte un turno para hoy.'."\n\n"
                .'Puedo agendarte una *cita con hora* para otro día.'."\n\n"
                .$this->menuText();
        }

        $name = trim((string) $clientName) ?: (trim((string) $conversation->client_name) ?: 'Cliente WhatsApp');

        $turn = $this->turns->createFor($barber, [
            'service_id' => $serviceId,
            'client_name' => $name,
            'client_phone' => $conversation->phone,
            'whatsapp_consent' => true,
        ], notify: false);

        $turn->load(['service', 'barber']);
        \App\Models\ConsentRecord::grant($turn, 'tratamiento_reserva', 'whatsapp_bot');
        \App\Models\ConsentRecord::grant($turn, 'avisos_whatsapp', 'whatsapp_bot');

        StaffNotification::record(
            'turn_new',
            'Nuevo turno #'.$turn->turn_number.' (WhatsApp): '.$turn->client_name,
            'Con '.$barber->name.($turn->service ? ' · '.$turn->service->name : '').'. Pedido por el bot.',
            route('turns.index', [], false),
            $barber->id,
        );

        $this->saveFlow($conversation->phone, [
            'step' => 'done',
            'data' => ['kind' => 'turn', 'turn_id' => $turn->id],
            'history' => [],
        ]);

        return $this->doneText(['kind' => 'turn', 'turn_id' => $turn->id]);
    }

    private function bookingDateText(array $data): string
    {
        $dates = (array) ($data['dates'] ?? []);

        if ($dates === []) {
            return $this->menuText();
        }

        $lines = collect($dates)->values()
            ->map(fn (string $date, int $index) => ($index + 1).'. '.$this->dateOptionLabel($date))
            ->implode("\n");

        return '¡Continuemos con tu reserva! 🗓️'."\n\n"
            .'📅 Selecciona la fecha en la que deseas recibir tu servicio:'."\n\n"
            .$lines."\n\n"
            .'99. ⬅️ Volver al paso anterior.'."\n"
            .'98. 🏠 Volver al menú principal.'."\n\n"
            .'Las fechas mostradas corresponden a la *disponibilidad actual* de nuestra barbería.';
    }

    private function handleBookDate(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $dates = (array) ($flow['data']['dates'] ?? []);
        $date = $dates[$n - 1] ?? null;

        if (! $date) {
            return $this->invalidOption($conversation, $flow);
        }

        $barber = User::find($flow['data']['barber_id'] ?? 0);
        $slots = $barber ? $this->slotsFor($barber, $date) : [];

        if ($slots === []) {
            return '⚠️ Ese día ya no tiene horarios disponibles.'."\n\n"
                .'🗓️ Elige otra fecha:'."\n\n"
                .collect($dates)->values()->map(fn (string $d, int $i) => ($i + 1).'. '.$this->dateOptionLabel($d))->implode("\n")
                ."\n\n99. ⬅️ Volver al paso anterior.\n98. 🏠 Volver al menú principal.";
        }

        $flow['data']['date'] = $date;
        $flow['data']['slots'] = $slots;
        $flow = $this->enter($conversation->phone, $flow, 'book_time', $flow['data']);

        return $this->bookingTimeText($flow['data']);
    }

    private function bookingTimeText(array $data): string
    {
        $slots = (array) ($data['slots'] ?? []);
        $date = (string) ($data['date'] ?? '');

        if ($slots === [] || $date === '') {
            return $this->menuText();
        }

        $lines = collect($slots)->values()
            ->map(fn (string $slot, int $index) => ($index + 1).'. '.$this->slotLabel($slot))
            ->implode("\n");

        return '¡Ya casi tenemos tu cita! 🕒'."\n\n"
            .'Estos son los horarios disponibles para el *'.$this->dateLabel(Carbon::parse($date)).'*:'."\n\n"
            .$lines."\n\n"
            .'99. ⬅️ Seleccionar otra fecha.'."\n"
            .'98. 🏠 Volver al menú principal.'."\n\n"
            .'👉 Responde con el *número* del horario que prefieres.';
    }

    private function handleBookTime(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $slots = (array) ($flow['data']['slots'] ?? []);
        $time = $slots[$n - 1] ?? null;

        if (! $time) {
            return $this->invalidOption($conversation, $flow);
        }

        $flow['data']['time'] = $time;
        $flow = $this->enter($conversation->phone, $flow, 'book_confirm', $flow['data']);

        return $this->bookingConfirmText($flow['data']);
    }

    private function bookingConfirmText(array $data): string
    {
        $service = Service::find($data['service_id'] ?? 0);
        $barber = User::find($data['barber_id'] ?? 0);
        $date = (string) ($data['date'] ?? '');
        $time = (string) ($data['time'] ?? '');

        if (! $barber || $date === '' || $time === '') {
            return $this->menuText();
        }

        $lines = [
            '✂️ Servicio: *'.($service?->name ?? 'Por definir con el barbero').'*',
            '💈 Barbero: *'.$barber->name.'*',
            '📅 Fecha: *'.$this->dateLabel(Carbon::parse($date)).'*',
            '🕒 Hora: *'.$this->slotLabel($time).'*',
        ];

        if ($service) {
            $lines[] = '💰 Valor: *'.$this->money($service->price).'*';
        }

        if (! empty($data['client_name'])) {
            array_unshift($lines, '👤 Cliente: *'.$data['client_name'].'*');
        }

        return '📋 *Revisa los detalles de tu reserva:*'."\n\n"
            .implode("\n", $lines)."\n\n"
            .'¿Deseas confirmar esta reserva? 🤝'."\n\n"
            .'1. ✅ Confirmar mi cita.'."\n"
            .'2. ✏️ Modificar la reserva.'."\n"
            .'3. ❌ Cancelar el proceso.'."\n\n"
            .'Responde con el *número* de tu elección.';
    }

    private function handleBookConfirm(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        if ($n === 2) {
            return $this->startBooking($conversation);
        }

        if ($n === 3) {
            $this->clearFlow($conversation->phone);

            return 'Entendido, no se agendó ninguna reserva. 👍'."\n\n".$this->menuText();
        }

        if ($n !== 1) {
            return $this->invalidOption($conversation, $flow);
        }

        $data = $flow['data'];
        $barber = User::find($data['barber_id'] ?? 0);
        $date = (string) ($data['date'] ?? '');
        $time = (string) ($data['time'] ?? '');

        if (! $barber || $date === '' || $time === '') {
            return $this->mainMenu($conversation);
        }

        $name = trim((string) ($data['client_name'] ?? ''))
            ?: (trim((string) $conversation->client_name) ?: 'Cliente WhatsApp');

        // Comprobación y creación atómicas: si alguien tomó el horario mientras el cliente escribía, se le avisa.
        try {
            $appointment = Appointment::reserveSlot([
                'barber_id' => $barber->id,
                'service_id' => $data['service_id'] ?? null,
                'client_name' => Str::limit($name, 120, ''),
                'client_phone' => $conversation->phone,
                'whatsapp_consent' => true,
                'appointment_date' => $date,
                'appointment_time' => $time,
                'status' => 'confirmada',
                'notes' => 'Reservada por WhatsApp (bot)',
            ]);
        } catch (\Illuminate\Validation\ValidationException) {
            $flow = $this->enter($conversation->phone, $flow, 'slot_taken', $flow['data']);

            return $this->slotTakenText();
        }

        $appointment->load(['service', 'barber']);
        \App\Models\ConsentRecord::grant($appointment, 'tratamiento_reserva', 'whatsapp_bot');
        \App\Models\ConsentRecord::grant($appointment, 'avisos_whatsapp', 'whatsapp_bot');
        // Faltando 15 minutos o menos queda confirmada sola (sin pedir confirmación con el recordatorio).
        $this->notifier->autoConfirmIfSoon($appointment);
        $this->notifier->scheduleAppointmentReminders($appointment);
        $this->notifier->flush();

        StaffNotification::record(
            'appt_new',
            'Nueva cita confirmada (WhatsApp): '.$appointment->client_name,
            $this->appointmentWhen($appointment)
                .($appointment->service ? ' · '.$appointment->service->name : '')
                .'. Confirmada por el cliente en el chat.',
            route('appointments.index', ['date' => $date], false),
            $barber->id,
        );

        $this->saveFlow($conversation->phone, [
            'step' => 'book_done',
            'data' => ['kind' => 'appointment', 'appointment_id' => $appointment->id],
            'history' => [],
        ]);

        return $this->doneText(['kind' => 'appointment', 'appointment_id' => $appointment->id]);
    }

    private function doneText(array $data): string
    {
        if (($data['kind'] ?? '') === 'turn') {
            $turn = Turn::with(['service', 'barber'])->find($data['turn_id'] ?? 0);

            if (! $turn) {
                return $this->menuText();
            }

            $number = $this->turnNumber($turn);

            return '✅ ¡Perfecto! Tu turno *'.$number.'* con *'.($turn->barber?->name ?? 'tu barbero').'* quedó registrado correctamente.'."\n\n"
                .($turn->service ? '✂️ Servicio: *'.$turn->service->name."*\n\n" : '')
                .(trim((string) $turn->client_name) !== '' ? '👤 Cliente: *'.$turn->client_name."*\n\n" : '')
                // Si el barbero estaba libre, el turno ya fue llamado ("¡Llegó tu turno!"): no se promete otro aviso.
                .($turn->status === 'esperando' ? '⏰ Te avisaremos por aquí *15 minutos antes* de tu atención.'."\n\n" : '')
                .'1. 🏠 Volver al menú principal.'."\n"
                .'2. 👋 Finalizar conversación.';
        }

        $appointment = Appointment::with(['service', 'barber'])->find($data['appointment_id'] ?? 0);

        if (! $appointment) {
            return $this->menuText();
        }

        $details = '📅 Fecha: *'.$this->dateLabel(Carbon::parse($appointment->appointment_date?->toDateString()))."*\n"
            .'🕒 Hora: *'.$this->slotLabel(substr((string) $appointment->appointment_time, 0, 5))."*\n"
            .'✂️ Servicio: *'.($appointment->service?->name ?? 'Por definir con el barbero')."*\n"
            .'💈 Barbero: *'.($appointment->barber?->name ?? 'Por asignar')."*\n"
            .'👤 Cliente: *'.$appointment->client_name."*\n";
        $footer = '1. 🏠 Volver al menú principal.'."\n"
            .'2. 👋 Finalizar conversación.';
        $window = (int) config('notificaciones.confirm_window_minutes', 15);
        $minutesLeft = (int) ceil(now()->diffInMinutes($appointment->startsAt(), false));

        // Cita en 15 minutos o menos (14, 7, 3…): quedó confirmada sola, no se le pide nada más.
        if ($minutesLeft <= $window && AttendanceConfirmation::confirmed($appointment)) {
            $address = trim((string) (SiteSetting::currentData()['address'] ?? ''));

            return '⏰ *¡Tu cita es '.($minutesLeft <= 1 ? 'en este momento' : 'en '.$minutesLeft.' minutos').' y ya quedó confirmada!*'."\n\n"
                .'Como falta poco para tu cita, la confirmamos *automáticamente*: no necesitas responder nada más.'."\n\n"
                .'📋 *Detalles de tu cita:*'."\n\n"
                .$details
                .($address !== '' ? '📍 Dirección: *'.$address."*\n" : '')."\n"
                .'🚶 Te recomendamos salir ya para llegar a tiempo. Si al final no puedes venir, escríbenos *cancelar*.'."\n\n"
                .'💈 ¡Te esperamos en *'.$this->brand().'*!'."\n\n"
                .$footer;
        }

        // Entre 16 y 30 minutos el recordatorio para confirmar ya salió (antes que este mensaje):
        // igual que con el turno ya llamado, no se promete otro aviso. Con más tiempo, sí.
        $reminder = $minutesLeft <= 30
            ? ''
            : '⏰ Te enviaremos un recordatorio automático *30 minutos antes* de tu cita para que confirmes tu asistencia (tendrás *'.$window.' minutos* para responder) o canceles si lo necesitas.'."\n\n";

        return '✅ ¡Tu cita ha sido confirmada exitosamente!'."\n\n"
            .'Gracias por elegir *'.$this->brand()."*.\n\n"
            .'📋 *Estos son los detalles de tu reserva:*'."\n\n"
            .$details."\n"
            .$reminder
            .'💈 ¡Será un placer atenderte!'."\n\n"
            .$footer;
    }

    private function handleDone(WhatsAppConversation $conversation, int $n): string
    {
        if ($n === 1 || $n === 0) {
            return $this->mainMenu($conversation);
        }

        if ($n === 2) {
            return $this->farewell($conversation);
        }

        return $this->invalidOption($conversation, $this->flow($conversation->phone) ?? ['step' => 'done', 'data' => [], 'history' => []]);
    }

    // ------------------------------------------------------------------------------------------
    // Servicios y barberos
    // ------------------------------------------------------------------------------------------

    private function showServices(WhatsAppConversation $conversation): string
    {
        $this->saveFlow($conversation->phone, ['step' => 'services', 'data' => [], 'history' => [['step' => 'menu', 'data' => []]]]);

        return $this->servicesText();
    }

    private function servicesText(): string
    {
        $services = Service::query()->active()->orderBy('name')->get();

        if ($services->isEmpty()) {
            return '⚠️ Estamos actualizando nuestro catálogo de servicios. Escríbenos en un rato y con gusto te ayudamos.';
        }

        $lines = $services->map(fn (Service $service) => '• *'.$service->name.'* — '.$this->money($service->price))->implode("\n");

        return 'En *'.$this->brand().'* contamos con servicios diseñados para cuidar tu imagen y resaltar tu estilo. 💈'."\n\n"
            .'📖 Consulta nuestro catálogo:'."\n\n"
            .$lines."\n\n"
            .'¿Qué deseas hacer?'."\n\n"
            .'1. 📅 Reservar un servicio.'."\n"
            .'2. 🔁 Consultar otro servicio.'."\n"
            .'3. 🏠 Volver al menú principal.';
    }

    private function handleServices(WhatsAppConversation $conversation, int $n): string
    {
        return match ($n) {
            1 => $this->startBooking($conversation),
            2 => $this->servicesText(),
            3 => $this->mainMenu($conversation),
            default => $this->invalidOption($conversation, ['step' => 'services', 'data' => [], 'history' => []]),
        };
    }

    private function showBarbers(WhatsAppConversation $conversation): string
    {
        $this->saveFlow($conversation->phone, ['step' => 'barbers', 'data' => [], 'history' => [['step' => 'menu', 'data' => []]]]);

        return $this->barbersText();
    }

    private function barbersText(): string
    {
        $barbers = User::barbers()->where('active', true)->orderBy('name')->get();

        if ($barbers->isEmpty()) {
            return '⚠️ Estamos actualizando nuestro equipo de trabajo. Escríbenos en un rato y con gusto te ayudamos.';
        }

        $lines = $barbers->map(function (User $barber) {
            $details = [];
            if ($barber->specialty) {
                $details[] = $barber->specialty;
            }
            $details[] = mb_strtolower($barber->attentionModeLabel());

            return '• *'.$barber->name.'* — '.implode(' · ', $details);
        })->implode("\n");

        return '💈 *Nuestro equipo* está conformado por profesionales comprometidos con la calidad y la atención personalizada.'."\n\n"
            .'Conoce a nuestros barberos:'."\n\n"
            .$lines."\n\n"
            .'¿Qué deseas hacer?'."\n\n"
            .'1. 📅 Reservar con un barbero.'."\n"
            .'2. 🔎 Consultar disponibilidad.'."\n"
            .'3. 🏠 Volver al menú principal.';
    }

    private function handleBarbers(WhatsAppConversation $conversation, int $n): string
    {
        return match ($n) {
            1 => $this->startBooking($conversation),
            2 => $this->availabilityText()."\n\n".'¿Qué deseas hacer?'."\n\n"
                .'1. 📅 Reservar con un barbero.'."\n"
                .'2. 🔎 Consultar disponibilidad.'."\n"
                .'3. 🏠 Volver al menú principal.',
            3 => $this->mainMenu($conversation),
            default => $this->invalidOption($conversation, ['step' => 'barbers', 'data' => [], 'history' => []]),
        };
    }

    private function availabilityText(): string
    {
        $barbers = User::barbers()->where('active', true)->orderBy('name')->get();

        if ($barbers->isEmpty()) {
            return '⚠️ Estamos actualizando nuestro equipo de trabajo. Escríbenos en un rato y con gusto te ayudamos.';
        }

        $lines = $barbers->map(function (User $barber) {
            if ($barber->attention_mode === 'llegada') {
                $waiting = Turn::query()
                    ->where('barber_id', $barber->id)
                    ->whereDate('turn_date', now()->toDateString())
                    ->whereIn('status', TurnQueueService::OPEN)
                    ->count();

                return '• *'.$barber->name.'* — '.($waiting > 0 ? $waiting.' en fila hoy' : 'fila libre hoy');
            }

            $next = null;
            for ($offset = 0; $offset < 8 && $next === null; $offset++) {
                $day = now()->addDays($offset);
                $slots = $this->slotsFor($barber, $day->toDateString());

                if ($slots !== []) {
                    $next = $this->dateOptionLabel($day->toDateString()).' a las '.$this->slotLabel($slots[0]);
                }
            }

            return '• *'.$barber->name.'* — '.($next ? 'próximo cupo: '.$next : 'sin cupos esta semana');
        })->implode("\n");

        return '📊 *Disponibilidad actual:*'."\n\n".$lines;
    }

    // ------------------------------------------------------------------------------------------
    // Consultar cita o turno
    // ------------------------------------------------------------------------------------------

    private function showConsult(WhatsAppConversation $conversation): string
    {
        $this->saveFlow($conversation->phone, ['step' => 'consult', 'data' => [], 'history' => [['step' => 'menu', 'data' => []]]]);

        return $this->consultText();
    }

    private function consultText(): string
    {
        return '¡Con gusto! 🙌'."\n\n"
            .'Selecciona qué información deseas consultar:'."\n\n"
            .'1. 📅 Consultar mi próxima cita.'."\n"
            .'2. 🎟️ Consultar mi turno actual.'."\n"
            .'3. 🧾 Consultar mis reservas anteriores.'."\n"
            .'4. 🏠 Volver al menú principal.';
    }

    private function handleConsult(WhatsAppConversation $conversation, int $n): string
    {
        if ($n === 4) {
            return $this->mainMenu($conversation);
        }

        if ($n === 1) {
            $appointment = $this->activeAppointment($conversation->phone);

            if ($appointment) {
                return $this->appointmentDetails($appointment);
            }

            if ($this->activeTurn($conversation->phone)) {
                return 'ℹ️ No tienes citas programadas en este momento.'."\n\n"
                    .'Tu turno actual sigue activo; selecciona *2* para verlo.'."\n\n"
                    .$this->consultText();
            }

            return $this->noBookings($conversation);
        }

        if ($n === 2) {
            $turn = $this->activeTurn($conversation->phone);

            if ($turn) {
                return $this->turnDetails($conversation, $turn);
            }

            if ($this->activeAppointment($conversation->phone)) {
                return 'ℹ️ No tienes un turno activo en este momento.'."\n\n"
                    .'Tu próxima cita sigue agendada; selecciona *1* para verla.'."\n\n"
                    .$this->consultText();
            }

            return $this->noBookings($conversation);
        }

        if ($n === 3) {
            return $this->historyText($conversation->phone);
        }

        return $this->invalidOption($conversation, ['step' => 'consult', 'data' => [], 'history' => []]);
    }

    private function appointmentDetails(Appointment $appointment): string
    {
        return '📅 *Tu próxima cita*'."\n\n"
            .'✂️ Servicio: *'.($appointment->service?->name ?? 'Por definir con el barbero')."*\n"
            .'💈 Barbero: *'.($appointment->barber?->name ?? 'Por asignar')."*\n"
            .'📅 Fecha: *'.$this->dateLabel(Carbon::parse($appointment->appointment_date?->toDateString()))."*\n"
            .'🕒 Hora: *'.$this->slotLabel(substr((string) $appointment->appointment_time, 0, 5))."*\n"
            .'📌 Estado: *'.$appointment->statusLabel()."*\n\n"
            .'98. 🏠 Volver al menú principal.';
    }

    private function turnDetails(WhatsAppConversation $conversation, Turn $turn): string
    {
        $estimate = $this->queue->estimates((int) $turn->barber_id)[$turn->id] ?? null;

        $lines = [
            '🎟️ Número de turno: *'.$this->turnNumber($turn).'*',
            '✂️ Servicio: *'.($turn->service?->name ?? 'Por definir con el barbero').'*',
            '💈 Barbero: *'.($turn->barber?->name ?? 'Por asignar').'*',
        ];

        if ($turn->status === 'esperando' && $estimate) {
            $lines[] = '⏳ Posición en la fila: *'.($estimate['ahead'] + 1).'*';
            $lines[] = '🕒 Hora estimada: *'.$this->slotLabel($estimate['start_at']->format('H:i')).'*';
        }

        $lines[] = '📌 Estado: *'.$turn->statusLabel().'*';

        $text = '🎟️ *Tu turno*'."\n\n".implode("\n", $lines);

        if (in_array($turn->status, ['esperando', 'llamado'], true)) {
            $this->saveFlow($conversation->phone, [
                'step' => 'turn_actions',
                'data' => ['kind' => 'turn', 'turn_id' => $turn->id],
                'history' => [['step' => 'consult', 'data' => []]],
            ]);

            return $text."\n\n".'¿Qué deseas hacer?'."\n\n"
                .'1. ✅ Confirmar mi asistencia.'."\n"
                .'2. ❌ Cancelar mi turno.'."\n"
                .'3. 💬 Hablar con un asesor.'."\n"
                .'98. 🏠 Volver al menú principal.';
        }

        return $text."\n\n".'98. 🏠 Volver al menú principal.';
    }

    private function historyText(string $phone): string
    {
        $local = substr($phone, -10);

        if ($local === '') {
            return '⚠️ No fue posible identificar tu número para consultar tu historial.'."\n\n".'98. 🏠 Volver al menú principal.';
        }

        $appointments = Appointment::query()
            ->with(['service', 'barber', 'sale'])
            ->where('client_phone', 'like', '%'.$local)
            ->where('status', 'atendida')
            ->orderByDesc('appointment_date')
            ->limit(5)
            ->get()
            ->map(fn (Appointment $appointment) => [
                'at' => $appointment->appointment_date?->toDateString().' '.substr((string) $appointment->appointment_time, 0, 5),
                'service' => $appointment->service?->name,
                'barber' => $appointment->barber?->name,
                'total' => $appointment->sale?->total ?? $appointment->service?->price,
            ]);

        $turns = Turn::query()
            ->with(['service', 'barber', 'sale'])
            ->where('client_phone', 'like', '%'.$local)
            ->where('status', 'atendido')
            ->orderByDesc('turn_date')
            ->limit(5)
            ->get()
            ->map(fn (Turn $turn) => [
                'at' => $turn->turn_date?->toDateString().' 00:00',
                'service' => $turn->service?->name,
                'barber' => $turn->barber?->name,
                'total' => $turn->sale?->total ?? $turn->service?->price,
            ]);

        $visits = $appointments->concat($turns)->sortByDesc('at')->take(5)->values();

        if ($visits->isEmpty()) {
            return '📭 Aún no registramos visitas asociadas a tu número de WhatsApp.'."\n\n".'98. 🏠 Volver al menú principal.';
        }

        $lines = $visits->map(function (array $visit) {
            $line = '• '.Carbon::parse($visit['at'])->locale('es')->translatedFormat('j \d\e F');
            if ($visit['service']) {
                $line .= ' · '.$visit['service'];
            }
            if ($visit['barber']) {
                $line .= ' con '.$visit['barber'];
            }
            if ($visit['total']) {
                $line .= ' — '.$this->money($visit['total']);
            }

            return $line;
        })->implode("\n");

        return '🧾 *Tus últimas visitas*'."\n\n".$lines."\n\n".'98. 🏠 Volver al menú principal.';
    }

    // ------------------------------------------------------------------------------------------
    // Modificar una reserva
    // ------------------------------------------------------------------------------------------

    private function startModify(WhatsAppConversation $conversation): string
    {
        $appointment = $this->activeAppointment($conversation->phone);

        if (! $appointment) {
            $this->saveFlow($conversation->phone, ['step' => 'no_bookings', 'data' => [], 'history' => [['step' => 'menu', 'data' => []]]]);

            return $this->noBookingsText();
        }

        $this->saveFlow($conversation->phone, [
            'step' => 'modify',
            'data' => ['appointment_id' => $appointment->id],
            'history' => [['step' => 'menu', 'data' => []]],
        ]);

        return $this->modifyText(['appointment_id' => $appointment->id]);
    }

    private function modifyText(array $data): string
    {
        $appointment = Appointment::with(['barber', 'service'])->find($data['appointment_id'] ?? 0);

        if (! $appointment) {
            return $this->menuText();
        }

        return '🗓️ Tu cita actual es el *'.$this->dateLabel(Carbon::parse($appointment->appointment_date?->toDateString())).'*'
            .' a las *'.$this->slotLabel(substr((string) $appointment->appointment_time, 0, 5)).'*'
            .($appointment->barber?->name ? ' con *'.$appointment->barber->name.'*' : '')."\n\n"
            .'Estamos aquí para ayudarte a realizar los cambios que necesites.'."\n\n"
            .'Selecciona qué información deseas modificar:'."\n\n"
            .'1. ✂️ Cambiar el servicio.'."\n"
            .'2. 💈 Cambiar el barbero.'."\n"
            .'3. 🗓️ Cambiar la fecha.'."\n"
            .'4. 🕒 Cambiar el horario.'."\n"
            .'5. 🏠 Volver al menú principal.';
    }

    private function handleModify(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $appointment = Appointment::with(['barber', 'service'])->find($flow['data']['appointment_id'] ?? 0);

        if (! $appointment || ! in_array($appointment->status, ['pendiente', 'confirmada'], true)) {
            $this->clearFlow($conversation->phone);

            return '⚠️ No encontré una cita activa para modificar.'."\n\n".$this->menuText();
        }

        if ($n === 1) {
            $this->saveFlow($conversation->phone, $this->enter($conversation->phone, $flow, 'mod_service', $flow['data']));

            return $this->modServiceText();
        }

        if ($n === 2) {
            $barbers = $this->barbersForService((int) $appointment->service_id, onlyAppointments: true);
            $flow['data']['barbers'] = $barbers->pluck('id')->all();
            $this->saveFlow($conversation->phone, $this->enter($conversation->phone, $flow, 'mod_barber', $flow['data']));

            return $this->modBarberText($flow['data']);
        }

        if ($n === 3) {
            $dates = $this->bookingDates($appointment->barber, $appointment->id);
            $flow['data']['dates'] = $dates;

            if ($dates === []) {
                return '⚠️ No hay fechas libres con *'.($appointment->barber?->name ?? 'tu barbero').'* por ahora.'."\n\n".$this->modifyText($flow['data']);
            }

            $this->saveFlow($conversation->phone, $this->enter($conversation->phone, $flow, 'mod_date', $flow['data']));

            return $this->modDateText($flow['data']);
        }

        if ($n === 4) {
            $date = $appointment->appointment_date?->toDateString();
            $slots = $appointment->barber ? $this->slotsFor($appointment->barber, (string) $date, $appointment->id) : [];
            $flow['data']['date'] = $date;
            $flow['data']['slots'] = $slots;

            if ($slots === []) {
                return '⚠️ No quedan horarios libres ese día con *'.($appointment->barber?->name ?? 'tu barbero').'*.'."\n\n".$this->modifyText($flow['data']);
            }

            $this->saveFlow($conversation->phone, $this->enter($conversation->phone, $flow, 'mod_time', $flow['data']));

            return $this->modTimeText($flow['data']);
        }

        if ($n === 5) {
            return $this->mainMenu($conversation);
        }

        return $this->invalidOption($conversation, $flow);
    }

    private function modServiceText(): string
    {
        $services = Service::query()->active()->orderBy('name')->get()->values();

        if ($services->isEmpty()) {
            return '⚠️ Estamos actualizando nuestro catálogo de servicios. Escríbenos en un rato y con gusto te ayudamos.';
        }

        $lines = $services->map(fn (Service $service, int $index) => ($index + 1).'. *'.$service->name.'* — '.$this->money($service->price))->implode("\n");

        return '✂️ Selecciona el nuevo servicio:'."\n\n".$lines."\n\n"
            .'99. ⬅️ Volver al paso anterior.'."\n"
            .'98. 🏠 Volver al menú principal.';
    }

    private function handleModService(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $services = Service::query()->active()->orderBy('name')->get()->values();
        $service = $services->get($n - 1);
        $appointment = Appointment::with(['barber', 'service'])->find($flow['data']['appointment_id'] ?? 0);

        if (! $service || ! $appointment) {
            return $this->invalidOption($conversation, $flow);
        }

        if ($appointment->barber && ! $appointment->barber->offersService($service->id)) {
        return '⚠️ El barbero *'.$appointment->barber->name.'* no ofrece *'.$service->name.'*.'."\n\n".$this->modServiceText();
        }

        $before = ['status' => $appointment->status, 'starts_at' => $appointment->startsAt()];
        $appointment->update(['service_id' => $service->id]);
        $appointment->load(['barber', 'service']);

        $this->notifier->appointmentChanged($appointment, $before);
        $this->notifier->flush();

        StaffNotification::record(
            'appt_rescheduled',
            'Cliente cambió el servicio (WhatsApp)',
            ($appointment->client_name ?: $appointment->client_phone).' · ahora '.$service->name.' · '.$this->appointmentWhen($appointment),
            route('appointments.index', ['date' => $appointment->appointment_date?->toDateString()], false),
            $appointment->barber_id,
        );

        $this->saveFlow($conversation->phone, [
            'step' => 'modify',
            'data' => ['appointment_id' => $appointment->id],
            'history' => [['step' => 'menu', 'data' => []]],
        ]);

        return '✅ El servicio de tu cita ahora es *'.$service->name.'*.'."\n\n".$this->modifyText(['appointment_id' => $appointment->id]);
    }

    private function modBarberText(array $data): string
    {
        $barbers = $this->barbersByIds((array) ($data['barbers'] ?? []));

        if ($barbers->isEmpty()) {
            return '⚠️ No hay barberos disponibles para este servicio.'."\n\n".'98. 🏠 Volver al menú principal.';
        }

        $lines = $barbers->values()->map(fn (User $barber, int $index) => ($index + 1).'. *'.$barber->name.'*'.($barber->specialty ? ' — '.$barber->specialty : ''))->implode("\n");

        return '💈 Selecciona el nuevo barbero:'."\n\n".$lines."\n\n"
            .'99. ⬅️ Volver al paso anterior.'."\n"
            .'98. 🏠 Volver al menú principal.';
    }

    private function handleModBarber(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $barbers = $this->barbersByIds((array) ($flow['data']['barbers'] ?? []));
        $barber = $barbers->get($n - 1);
        $appointment = Appointment::with(['barber', 'service'])->find($flow['data']['appointment_id'] ?? 0);

        if (! $barber || ! $appointment) {
            return $this->invalidOption($conversation, $flow);
        }

        $before = ['status' => $appointment->status, 'starts_at' => $appointment->startsAt()];
        $appointment->update(['barber_id' => $barber->id]);
        $appointment->load(['barber', 'service']);

        $this->notifier->appointmentChanged($appointment, $before);
        $this->notifier->flush();

        StaffNotification::record(
            'appt_rescheduled',
            'Cliente cambió el barbero (WhatsApp)',
            ($appointment->client_name ?: $appointment->client_phone).' · ahora con '.$barber->name.' · '.$this->appointmentWhen($appointment),
            route('appointments.index', ['date' => $appointment->appointment_date?->toDateString()], false),
            $barber->id,
        );

        $this->saveFlow($conversation->phone, [
            'step' => 'modify',
            'data' => ['appointment_id' => $appointment->id],
            'history' => [['step' => 'menu', 'data' => []]],
        ]);

        return '✅ El barbero de tu cita ahora es *'.$barber->name.'*.'."\n\n".$this->modifyText(['appointment_id' => $appointment->id]);
    }

    private function modDateText(array $data): string
    {
        $dates = (array) ($data['dates'] ?? []);

        if ($dates === []) {
            return '⚠️ No hay fechas disponibles por ahora.'."\n\n".'98. 🏠 Volver al menú principal.';
        }

        $lines = collect($dates)->values()->map(fn (string $date, int $index) => ($index + 1).'. '.$this->dateOptionLabel($date))->implode("\n");

        return '🗓️ Selecciona la nueva fecha:'."\n\n".$lines."\n\n"
            .'99. ⬅️ Volver al paso anterior.'."\n"
            .'98. 🏠 Volver al menú principal.';
    }

    private function handleModDate(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $dates = (array) ($flow['data']['dates'] ?? []);
        $date = $dates[$n - 1] ?? null;
        $appointment = Appointment::with(['barber', 'service'])->find($flow['data']['appointment_id'] ?? 0);

        if (! $date || ! $appointment) {
            return $this->invalidOption($conversation, $flow);
        }

        $slots = $appointment->barber ? $this->slotsFor($appointment->barber, $date, $appointment->id) : [];

        if ($slots === []) {
            return '⚠️ Ese día ya no tiene horarios disponibles. Elige otra fecha:'."\n\n".$this->modDateText($flow['data']);
        }

        $flow['data']['date'] = $date;
        $flow['data']['slots'] = $slots;
        $this->saveFlow($conversation->phone, $this->enter($conversation->phone, $flow, 'mod_time', $flow['data']));

        return $this->modTimeText($flow['data']);
    }

    private function modTimeText(array $data): string
    {
        $slots = (array) ($data['slots'] ?? []);
        $date = (string) ($data['date'] ?? '');

        if ($slots === []) {
            return '⚠️ No quedan horarios disponibles ese día.'."\n\n".'98. 🏠 Volver al menú principal.';
        }

        $lines = collect($slots)->values()->map(fn (string $slot, int $index) => ($index + 1).'. '.$this->slotLabel($slot))->implode("\n");

        return '🕒 Horarios disponibles para el *'.$this->dateLabel(Carbon::parse($date)).'*:'."\n\n".$lines."\n\n"
            .'99. ⬅️ Volver al paso anterior.'."\n"
            .'98. 🏠 Volver al menú principal.';
    }

    private function handleModTime(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $slots = (array) ($flow['data']['slots'] ?? []);
        $time = $slots[$n - 1] ?? null;
        $appointment = Appointment::with(['barber', 'service'])->find($flow['data']['appointment_id'] ?? 0);

        if (! $time || ! $appointment) {
            return $this->invalidOption($conversation, $flow);
        }

        $date = (string) ($flow['data']['date'] ?? $appointment->appointment_date?->toDateString());

        $taken = Appointment::query()
            ->where('id', '!=', $appointment->id)
            ->where('barber_id', $appointment->barber_id)
            ->whereDate('appointment_date', $date)
            ->whereTime('appointment_time', $time)
            ->where('status', '!=', 'cancelada')
            ->exists();

        if ($taken) {
            return '⚠️ Ese horario acaba de ocuparse. Elige otro:'."\n\n".$this->modTimeText($flow['data']);
        }

        $before = ['status' => $appointment->status, 'starts_at' => $appointment->startsAt()];
        $appointment->update(['appointment_date' => $date, 'appointment_time' => $time]);
        $appointment->load(['barber', 'service']);

        $this->notifier->cancelPending($appointment, ClientNotifier::REMINDER_TYPES);
        $this->notifier->scheduleAppointmentReminders($appointment);
        $this->notifier->flush();

        StaffNotification::record(
            'appt_rescheduled',
            'Cliente reprogramó su cita (WhatsApp)',
            ($appointment->client_name ?: $appointment->client_phone).' · Nueva fecha: '.$this->appointmentWhen($appointment),
            route('appointments.index', ['date' => $date], false),
            $appointment->barber_id,
        );

        $this->saveFlow($conversation->phone, [
            'step' => 'modify',
            'data' => ['appointment_id' => $appointment->id],
            'history' => [['step' => 'menu', 'data' => []]],
        ]);

        return '✅ Tu cita quedó reprogramada para *'.$this->appointmentWhen($appointment).'*.'."\n\n".$this->modifyText(['appointment_id' => $appointment->id]);
    }

    // ------------------------------------------------------------------------------------------
    // Cancelar una reserva
    // ------------------------------------------------------------------------------------------

    private function startCancelMenu(WhatsAppConversation $conversation): string
    {
        $this->saveFlow($conversation->phone, ['step' => 'cancel', 'data' => [], 'history' => [['step' => 'menu', 'data' => []]]]);

        return $this->cancelText();
    }

    private function cancelText(): string
    {
        return 'Entendemos que tus planes pueden cambiar. 🤝'."\n\n"
            .'Selecciona qué deseas cancelar:'."\n\n"
            .'1. 📅 Mi cita programada.'."\n"
            .'2. 🎟️ Mi turno actual.'."\n"
            .'3. 🏠 Regresar al menú principal.';
    }

    private function handleCancelMenu(WhatsAppConversation $conversation, int $n): string
    {
        if ($n === 3) {
            return $this->mainMenu($conversation);
        }

        if ($n === 1 || $n === 2) {
            return $this->askCancelName($conversation, $n === 1 ? 'appointment' : 'turn');
        }

        return $this->invalidOption($conversation, ['step' => 'cancel', 'data' => [], 'history' => []]);
    }

    /** Si el cliente escribe "cancelar", se le pide su nombre para encontrar la reserva (cita o turno). */
    private function startCancel(WhatsAppConversation $conversation): string
    {
        return $this->askCancelName($conversation, 'any');
    }

    // ------------------------------------------------------------------------------------------
    // Cancelar por nombre: la reserva puede haberse hecho en la página con otro teléfono, así que
    // se busca por nombre y apellido. Si el teléfono de la reserva no es el de este WhatsApp,
    // se piden sus 4 últimos dígitos para que nadie cancele la reserva de otra persona.
    // ------------------------------------------------------------------------------------------

    /** $kind: 'appointment' (cita), 'turn' (turno) o 'any' (cualquiera de las dos). */
    private function askCancelName(WhatsAppConversation $conversation, string $kind): string
    {
        $this->saveFlow($conversation->phone, [
            'step' => 'cancel_name',
            'data' => ['kind' => $kind, 'tries' => 0],
            'history' => [['step' => 'cancel', 'data' => []]],
        ]);

        return $this->cancelNameText($kind);
    }

    private function cancelNameText(string $kind): string
    {
        $what = match ($kind) {
            'appointment' => 'tu *cita*',
            'turn' => 'tu *turno*',
            default => 'tu *cita o turno*',
        };

        return '✍️ Para encontrar '.$what.', escribe el *nombre y apellido* con el que hiciste la reserva.'."\n\n"
            .'Ejemplo: *María Gómez*.'."\n\n"
            .'98. 🏠 Volver al menú principal.';
    }

    private function handleCancelName(WhatsAppConversation $conversation, array $flow, string $text): string
    {
        $name = $this->cleanPersonName($text);

        if ($name === null) {
            return '⚠️ Escribe tu *nombre y apellido* (sin números), por ejemplo: *María Gómez*.'."\n\n"
                .'98. 🏠 Volver al menú principal.';
        }

        $kind = (string) ($flow['data']['kind'] ?? 'any');
        $matches = $this->bookingsByName($name, $kind);

        if ($matches === []) {
            $tries = (int) ($flow['data']['tries'] ?? 0) + 1;
            $flow['data']['tries'] = $tries;
            $this->saveFlow($conversation->phone, $flow);

            return '🔎 No encontré '.($kind === 'turn' ? 'un *turno de hoy*' : ($kind === 'appointment' ? 'una *cita activa*' : 'una *cita o turno activo*'))
                .' para el cliente *'.$name.'*.'."\n\n"
                .'Revisa que esté escrito igual que en la reserva y vuelve a escribirlo.'."\n\n"
                .($tries >= 2 ? '💬 Si prefieres, escribe *asesor* y te ayuda una persona del equipo.'."\n\n" : '')
                .'98. 🏠 Volver al menú principal.';
        }

        if (count($matches) === 1) {
            return $this->cancelSelected($conversation, $matches[0]['kind'], $matches[0]['id']);
        }

        $this->saveFlow($conversation->phone, [
            'step' => 'cancel_pick',
            'data' => ['options' => $matches],
            'history' => [['step' => 'cancel_name', 'data' => ['kind' => $kind, 'tries' => 0]]],
        ]);

        return $this->cancelPickText(['options' => $matches]);
    }

    private function cancelPickText(array $data): string
    {
        $lines = collect((array) ($data['options'] ?? []))->values()
            ->map(function (array $option, int $index) {
                $subject = $this->subjectFrom(['kind' => $option['kind'], 'id' => $option['id']]);

                return $subject ? ($index + 1).'. '.$this->bookingLine($subject) : null;
            })
            ->filter()
            ->implode("\n");

        return '📋 Encontré varias reservas a ese nombre. ¿Cuál deseas cancelar?'."\n\n"
            .$lines."\n\n"
            .'99. ⬅️ Volver al paso anterior.'."\n"
            .'98. 🏠 Volver al menú principal.';
    }

    private function handleCancelPick(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $option = array_values((array) ($flow['data']['options'] ?? []))[$n - 1] ?? null;

        if (! $option) {
            return $this->invalidOption($conversation, $flow);
        }

        return $this->cancelSelected($conversation, $option['kind'], (int) $option['id']);
    }

    /** Reserva elegida: si el teléfono no coincide con este WhatsApp, se verifica antes de confirmar. */
    private function cancelSelected(WhatsAppConversation $conversation, string $kind, int $id): string
    {
        $subject = $this->subjectFrom(['kind' => $kind, 'id' => $id]);

        if (! $subject || ! $this->isCancellable($subject)) {
            $this->clearFlow($conversation->phone);

            return '⚠️ No encontré esa reserva activa. Solo se pueden cancelar citas y turnos de hoy en adelante que no estén finalizados.'."\n\n".$this->menuText();
        }

        $bookingPhone = preg_replace('/\D+/', '', (string) $subject->client_phone);

        if ($bookingPhone !== '' && substr($bookingPhone, -10) !== substr((string) $conversation->phone, -10)) {
            $this->saveFlow($conversation->phone, [
                'step' => 'cancel_verify',
                'data' => ['kind' => $kind, 'id' => $id, 'tries' => 0],
                'history' => [['step' => 'menu', 'data' => []]],
            ]);

            return $this->cancelVerifyText();
        }

        return $this->cancelConfirmFor($conversation, $kind, $subject);
    }

    private function cancelVerifyText(): string
    {
        return '🔐 Por seguridad, escribe los *4 últimos dígitos* del teléfono que registraste en la reserva.'."\n\n"
            .'98. 🏠 Volver al menú principal.';
    }

    private function handleCancelVerify(WhatsAppConversation $conversation, array $flow, string $text): string
    {
        $subject = $this->subjectFrom($flow['data'] ?? []);

        if (! $subject) {
            $this->clearFlow($conversation->phone);

            return '⚠️ No encontré esa reserva. Puede que ya esté cancelada o finalizada.'."\n\n".$this->menuText();
        }

        $digits = preg_replace('/\D+/', '', $text);
        $expected = substr(preg_replace('/\D+/', '', (string) $subject->client_phone), -4);

        if ($digits !== '' && strlen($digits) >= 4 && substr($digits, -4) === $expected) {
            return $this->cancelConfirmFor($conversation, ($flow['data']['kind'] ?? 'appointment'), $subject);
        }

        $tries = (int) ($flow['data']['tries'] ?? 0) + 1;

        if ($tries >= 3) {
            $this->clearFlow($conversation->phone);

            return '⚠️ Los dígitos no coinciden con la reserva, así que por seguridad no puedo cancelarla.'."\n\n"
                .'Escribe *asesor* y una persona del equipo te ayuda.'."\n\n"
                .$this->menuText();
        }

        $flow['data']['tries'] = $tries;
        $this->saveFlow($conversation->phone, $flow);

        return '⚠️ Esos dígitos no coinciden con el teléfono de la reserva. Inténtalo de nuevo.'."\n\n"
            .'98. 🏠 Volver al menú principal.';
    }

    /**
     * Citas activas (de hoy en adelante) y turnos de hoy a ese nombre.
     * Compara sin tildes ni mayúsculas, y vale si el nombre de la reserva contiene todas las palabras escritas.
     *
     * @return array<int, array{kind: string, id: int}>
     */
    private function bookingsByName(string $name, string $kind): array
    {
        $words = array_values(array_filter(explode(' ', $this->normalize($name)), fn ($w) => mb_strlen($w) >= 2));

        if ($words === []) {
            return [];
        }

        $matchesName = function (?string $candidate) use ($words): bool {
            $haystack = ' '.$this->normalize((string) $candidate).' ';

            foreach ($words as $word) {
                if (! str_contains($haystack, ' '.$word.' ')) {
                    return false;
                }
            }

            return true;
        };

        $found = [];

        // Primero los turnos de hoy (lo más inmediato) y después las citas por fecha.
        if ($kind !== 'appointment') {
            Turn::query()
                ->whereDate('turn_date', now()->toDateString())
                ->whereIn('status', ['esperando', 'llamado'])
                ->orderBy('turn_number')
                ->get()
                ->filter(fn (Turn $turn) => $matchesName($turn->client_name))
                ->each(function (Turn $turn) use (&$found) {
                    $found[] = ['kind' => 'turn', 'id' => $turn->id];
                });
        }

        if ($kind !== 'turn') {
            Appointment::query()
                ->whereIn('status', ['pendiente', 'confirmada'])
                ->whereDate('appointment_date', '>=', now()->toDateString())
                ->orderBy('appointment_date')
                ->orderBy('appointment_time')
                ->get()
                ->filter(fn (Appointment $appointment) => $matchesName($appointment->client_name)
                    && $appointment->startsAt()->greaterThan(now()->subMinutes(15)))
                ->each(function (Appointment $appointment) use (&$found) {
                    $found[] = ['kind' => 'appointment', 'id' => $appointment->id];
                });
        }

        return array_slice($found, 0, 9);
    }

    /** Se puede cancelar: cita activa de hoy en adelante (que no haya pasado) o turno de hoy aún no atendido. */
    private function isCancellable(Appointment|Turn $subject): bool
    {
        if ($subject instanceof Turn) {
            return $subject->turn_date?->isSameDay(now())
                && in_array($subject->status, ['esperando', 'llamado'], true);
        }

        return in_array($subject->status, ['pendiente', 'confirmada'], true)
            && $subject->appointment_date?->toDateString() >= now()->toDateString()
            && $subject->startsAt()->greaterThan(now()->subMinutes(15));
    }

    /** Línea corta para elegir entre varias reservas. */
    private function bookingLine(Appointment|Turn $subject): string
    {
        if ($subject instanceof Turn) {
            return '🎟️ Turno *'.$this->turnNumber($subject).'* de hoy'
                .($subject->barber ? ' con '.$subject->barber->name : '');
        }

        return '📅 Cita · '.$this->appointmentWhen($subject)
            .($subject->barber ? ' con '.$subject->barber->name : '');
    }

    private function cancelConfirmFor(WhatsAppConversation $conversation, string $kind, Appointment|Turn $subject): string
    {
        $this->saveFlow($conversation->phone, [
            'step' => 'cancel_confirm',
            'data' => ['kind' => $kind, 'id' => $subject->id],
            'history' => [['step' => 'menu', 'data' => []]],
        ]);

        return $this->cancelConfirmText(['kind' => $kind, 'id' => $subject->id]);
    }

    private function cancelConfirmText(array $data): string
    {
        $subject = $this->subjectFrom($data);

        if (! $subject) {
            return $this->menuText();
        }

        $type = $subject instanceof Turn
            ? 'turno '.$this->turnNumber($subject)
            : 'cita del '.$this->dateLabel(Carbon::parse($subject->appointment_date?->toDateString()));

        return '⚠️ ¿Confirmas que deseas cancelar tu *'.$type.'*?'."\n\n"
            .'Al confirmar, se liberará el espacio correspondiente para que otro cliente pueda recibir atención.'."\n\n"
            .'1. ✅ Sí, confirmar cancelación.'."\n"
            .'2. ↩️ No, conservar mi reserva.'."\n"
            .'3. 🏠 Volver al menú principal.';
    }

    private function handleCancelConfirm(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        if ($n === 3) {
            return $this->mainMenu($conversation);
        }

        if ($n === 2) {
            return '👍 Tu reserva sigue activa.'."\n\n".$this->menuText();
        }

        if ($n !== 1) {
            return $this->invalidOption($conversation, $flow);
        }

        $subject = $this->subjectFrom($flow['data']);

        if (! $subject || ! $this->isCancellable($subject)) {
            $this->clearFlow($conversation->phone);

            return '⚠️ Esa reserva ya no se puede cancelar: puede que ya esté cancelada, finalizada o que su fecha ya haya pasado.'."\n\n".$this->menuText();
        }

        if ($subject instanceof Appointment) {
            $done = '✅ Tu cita ha sido cancelada correctamente.';
            $before = ['status' => $subject->status, 'starts_at' => $subject->startsAt()];
            $subject->update(['status' => 'cancelada']);
            $this->notifier->appointmentChanged($subject, $before);
            $this->notifier->flush();

            StaffNotification::record(
                'client_cancelled',
                'Cliente canceló su cita (WhatsApp)',
                ($subject->client_name ?: $subject->client_phone).' · '.$this->appointmentWhen($subject),
                route('appointments.index', ['date' => $subject->appointment_date?->toDateString()], false),
                $subject->barber_id,
            );
        } else {
            $done = '✅ Tu turno *'.$this->turnNumber($subject).'* ha sido cancelado correctamente.';
            $cancelled = Turn::query()
                ->whereKey($subject->id)
                ->whereIn('status', ['esperando', 'llamado'])
                ->update(['status' => 'cancelado', 'finished_at' => now()]);

            if (! $cancelled) {
                $this->clearFlow($conversation->phone);

                return '⚠️ Tu turno ya estaba finalizado o en atención, así que no fue posible anularlo.'."\n\n"
                    .'Escríbenos al equipo si necesitas ayuda. 💬'."\n\n".$this->menuText();
            }

            $subject = $subject->fresh();
            $this->notifier->turnCancelled($subject);

            StaffNotification::record(
                'client_cancelled',
                'Cliente anuló su turno (WhatsApp)',
                ($subject->client_name ?: $subject->client_phone).' · '.$this->turnNumber($subject),
                route('turns.index', [], false),
                $subject->barber_id,
            );

            $this->queue->advance((int) $subject->barber_id);
        }

        $this->saveFlow($conversation->phone, ['step' => 'cancel_done', 'data' => [], 'history' => []]);

        return $done."\n\n"
            .'Agradecemos que nos hayas informado con anticipación. Esto nos permite organizar mejor nuestra agenda y ofrecer ese espacio a otro cliente.'."\n\n"
            .'Esperamos tener el gusto de atenderte próximamente. 💈'."\n\n"
            .'¡Gracias por elegir *'.$this->brand().'*!'."\n\n"
            .'1. 📅 Realizar una nueva reserva.'."\n"
            .'2. 🏠 Volver al menú principal.'."\n"
            .'3. 👋 Finalizar conversación.';
    }

    private function cancelDoneText(): string
    {
        return '1. 📅 Realizar una nueva reserva.'."\n"
            .'2. 🏠 Volver al menú principal.'."\n"
            .'3. 👋 Finalizar conversación.';
    }

    private function handleCancelDone(WhatsAppConversation $conversation, int $n): string
    {
        return match ($n) {
            1 => $this->startBooking($conversation),
            2 => $this->mainMenu($conversation),
            3 => $this->farewell($conversation),
            default => $this->invalidOption($conversation, ['step' => 'cancel_done', 'data' => [], 'history' => []]),
        };
    }

    // ------------------------------------------------------------------------------------------
    // Confirmaciones del cliente
    // ------------------------------------------------------------------------------------------

    private function confirmAppointment(WhatsAppConversation $conversation, Appointment $appointment): string
    {
        $wasPending = $appointment->status !== 'confirmada';

        // Asistencia confirmada: ya no se cancela por falta de confirmación y al terminar queda como atendida.
        AttendanceConfirmation::mark($appointment);

        if ($wasPending) {
            $appointment->update(['status' => 'confirmada']);

            StaffNotification::record(
                'client_confirmed',
                'Cliente confirmó su cita (WhatsApp)',
                ($appointment->client_name ?: $appointment->client_phone).' · '.$this->appointmentWhen($appointment),
                route('appointments.index', ['date' => $appointment->appointment_date?->toDateString()], false),
                $appointment->barber_id,
            );
        }

        $this->saveFlow($conversation->phone, [
            'step' => 'done',
            'data' => ['kind' => 'appointment', 'appointment_id' => $appointment->id],
            'history' => [],
        ]);

        return $this->greeting('¡Muchas gracias por confirmar', $appointment->client_name).' 🙌'."\n\n"
            .'✅ Hemos registrado tu asistencia.'."\n\n"
            .'Tu cita continúa activa y nuestro equipo estará preparado para recibirte en el horario programado.'."\n\n"
            .'💈 ¡Te esperamos en *'.$this->brand().'*!'."\n\n"
            .'1. 🏠 Volver al menú principal.'."\n"
            .'2. 👋 Finalizar conversación.';
    }

    private function confirmTurn(WhatsAppConversation $conversation, Turn $turn): string
    {
        if (! in_array($turn->status, ['esperando', 'llamado'], true)) {
            return '⚠️ Tu turno *'.$this->turnNumber($turn).'* ya no está activo.'."\n\n".$this->menuText();
        }

        $turn->load(['barber', 'service']);
        AttendanceConfirmation::mark($turn); // ya no se cancela por falta de confirmación

        StaffNotification::record(
            'client_confirmed',
            'Cliente confirmó su turno (WhatsApp): '.$this->turnNumber($turn),
            ($turn->client_name ?: $turn->client_phone).($turn->barber ? ' · con '.$turn->barber->name : '').'. Ya puede acercarse.',
            route('turns.index', [], false),
            $turn->barber_id,
        );

        $this->saveFlow($conversation->phone, [
            'step' => 'done',
            'data' => ['kind' => 'turn', 'turn_id' => $turn->id],
            'history' => [],
        ]);

        return $this->greeting('¡Excelente', $turn->client_name).' 🙌'."\n\n"
            .'✅ Hemos registrado tu confirmación.'."\n\n"
            .'Tu turno *'.$this->turnNumber($turn).'* continúa activo y *ya puedes acercarte a nuestra barbería para recibir tu servicio*.'."\n\n"
            .'Te recomendamos estar pendiente de los llamados de nuestro equipo para no perder tu oportunidad de atención.'."\n\n"
            .'💈 ¡Gracias por elegir *'.$this->brand().'*!'."\n\n"
            .'1. 🏠 Volver al menú principal.'."\n"
            .'2. 👋 Finalizar conversación.';
    }

    // ------------------------------------------------------------------------------------------
    // Asesor, sin reservas y errores
    // ------------------------------------------------------------------------------------------

    private function advisorPrompt(WhatsAppConversation $conversation): string
    {
        $this->saveFlow($conversation->phone, ['step' => 'advisor', 'data' => [], 'history' => [['step' => 'menu', 'data' => []]]]);

        return $this->advisorText();
    }

    private function advisorText(): string
    {
        return '¡Por supuesto! 👋'."\n\n"
            .'Vamos a poner tu conversación en manos de un asesor de *'.$this->brand().'* para brindarte una atención personalizada.'."\n\n"
            .'Selecciona una opción:'."\n\n"
            .'1. 💬 Solicitar atención de un asesor.'."\n"
            .'2. 🏠 Regresar al menú principal.';
    }

    private function handleAdvisor(WhatsAppConversation $conversation, int $n): string
    {
        if ($n === 2) {
            return $this->mainMenu($conversation);
        }

        if ($n !== 1) {
            return $this->invalidOption($conversation, ['step' => 'advisor', 'data' => [], 'history' => []]);
        }

        $conversation->update(['bot_active' => false]);
        $this->clearFlow($conversation->phone);

        StaffNotification::record(
            'client_advisor',
            'Cliente pide un asesor (WhatsApp)',
            ($conversation->client_name ?: 'Cliente').' · '.$conversation->phone.'. El bot quedó en pausa en ese chat.',
            route('whatsapp.chat.show', $conversation, false),
        );

        return 'Un asesor de *'.$this->brand().'* continuará la conversación contigo en un momento. 💬'."\n\n"
            .'¡Gracias por escribirnos! 💈';
    }

    private function noBookings(WhatsAppConversation $conversation): string
    {
        $this->saveFlow($conversation->phone, ['step' => 'no_bookings', 'data' => [], 'history' => [['step' => 'menu', 'data' => []]]]);

        return $this->noBookingsText();
    }

    private function noBookingsText(): string
    {
        return '📭 Actualmente no encontramos citas ni turnos activos asociados a tu número de WhatsApp.'."\n\n"
            .'¿Deseas realizar alguna de las siguientes acciones?'."\n\n"
            .'1. 📅 Agendar una cita.'."\n"
            .'2. ✂️ Consultar servicios y precios.'."\n"
            .'3. 💈 Conocer nuestros barberos.'."\n"
            .'4. 💬 Hablar con un asesor.'."\n"
            .'5. 🏠 Volver al menú principal.';
    }

    private function handleNoBookings(WhatsAppConversation $conversation, int $n): string
    {
        return match ($n) {
            1 => $this->startBooking($conversation),
            2 => $this->showServices($conversation),
            3 => $this->showBarbers($conversation),
            4 => $this->advisorPrompt($conversation),
            5 => $this->mainMenu($conversation),
            default => $this->invalidOption($conversation, ['step' => 'no_bookings', 'data' => [], 'history' => []]),
        };
    }

    private function turnActionsText(WhatsAppConversation $conversation, ?Turn $turn): string
    {
        if (! $turn) {
            return $this->menuText();
        }

        $turn->load(['barber', 'service']);

        $lines = [
            '🎟️ Número de turno: *'.$this->turnNumber($turn).'*',
            '✂️ Servicio: *'.($turn->service?->name ?? 'Por definir con el barbero').'*',
            '💈 Barbero: *'.($turn->barber?->name ?? 'Por asignar').'*',
        ];

        $this->saveFlow($conversation->phone, [
            'step' => 'turn_actions',
            'data' => ['kind' => 'turn', 'turn_id' => $turn->id],
            'history' => [['step' => 'menu', 'data' => []]],
        ]);

        return implode("\n", $lines)."\n\n"
            .'¿Deseas continuar con tu turno? 🎟️'."\n\n"
            .'1. ✅ Sí, deseo recibir el servicio.'."\n"
            .'2. ❌ Cancelar mi turno.'."\n"
            .'3. 💬 Necesito más información.'."\n"
            .'98. 🏠 Volver al menú principal.';
    }

    private function handleTurnActions(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $turn = $this->subjectTurn($flow['data']);

        if (! $turn) {
            return $this->mainMenu($conversation);
        }

        return match ($n) {
            1 => $this->confirmTurn($conversation, $turn),
            2 => $this->cancelConfirmFor($conversation, 'turn', $turn),
            3 => $this->advisorPrompt($conversation),
            default => $this->invalidOption($conversation, $flow),
        };
    }

    private function invalidOption(WhatsAppConversation $conversation, array $flow): string
    {
        $this->saveFlow($conversation->phone, [
            'step' => 'invalid',
            'data' => ['retry' => ['step' => $flow['step'] ?? 'menu', 'data' => $flow['data'] ?? []]],
            'history' => $flow['history'] ?? [],
        ]);

        return $this->invalidText();
    }

    private function invalidText(): string
    {
        return '⚠️ No hemos podido identificar la opción seleccionada.'."\n\n"
            .'Por favor, responde *únicamente* con uno de los números disponibles en el menú.'."\n\n"
            .'1. 🔁 Intentar nuevamente.'."\n"
            .'2. 🏠 Regresar al menú principal.';
    }

    private function handleInvalid(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        if ($n === 2) {
            return $this->mainMenu($conversation);
        }

        if ($n !== 1) {
            return $this->invalidText();
        }

        $retry = $flow['data']['retry'] ?? null;

        if (! is_array($retry)) {
            return $this->mainMenu($conversation);
        }

        $retryFlow = ['step' => $retry['step'] ?? 'menu', 'data' => $retry['data'] ?? [], 'history' => $flow['history'] ?? []];
        $this->saveFlow($conversation->phone, $retryFlow);

        return $this->render($conversation, $retryFlow);
    }

    private function slotTakenText(): string
    {
        return '⏳ El horario seleccionado ya no se encuentra disponible.'."\n\n"
            .'No te preocupes, podemos ayudarte a encontrar otra alternativa. 🤝'."\n\n"
            .'1. 🕒 Consultar otros horarios.'."\n"
            .'2. 🗓️ Seleccionar otra fecha.'."\n"
            .'3. 💈 Elegir otro barbero.'."\n"
            .'4. 🏠 Volver al menú principal.';
    }

    private function handleSlotTaken(WhatsAppConversation $conversation, array $flow, int $n): string
    {
        $data = $flow['data'];
        $barber = User::find($data['barber_id'] ?? 0);

        if (! $barber) {
            return $this->mainMenu($conversation);
        }

        if ($n === 1) {
            $slots = $this->slotsFor($barber, (string) ($data['date'] ?? ''));

            if ($slots === []) {
                $dates = $this->bookingDates($barber);
                $data['dates'] = $dates;
                $this->saveFlow($conversation->phone, $this->enter($conversation->phone, $flow, 'book_date', $data));

                return $this->bookingDateText($data);
            }

            $data['slots'] = $slots;
            unset($data['time']);
            $this->saveFlow($conversation->phone, $this->enter($conversation->phone, $flow, 'book_time', $data));

            return $this->bookingTimeText($data);
        }

        if ($n === 2) {
            $data['dates'] = $this->bookingDates($barber);
            $this->saveFlow($conversation->phone, $this->enter($conversation->phone, $flow, 'book_date', $data));

            return $this->bookingDateText($data);
        }

        if ($n === 3) {
            $barbers = $this->barbersForMode((int) ($data['service_id'] ?? 0), 'cita');
            $data['mode'] = 'cita';
            $data['barbers'] = $barbers->pluck('id')->all();
            unset($data['date'], $data['slots'], $data['time']);
            $this->saveFlow($conversation->phone, $this->enter($conversation->phone, $flow, 'book_barber', $data));

            return $this->bookingBarberText($data);
        }

        if ($n === 4) {
            return $this->mainMenu($conversation);
        }

        return $this->invalidOption($conversation, $flow);
    }

    private function errorText(): string
    {
        return '⚠️ En este momento no ha sido posible procesar tu solicitud.'."\n\n"
            .'No te preocupes, puedes intentarlo nuevamente o comunicarte con uno de nuestros asesores.'."\n\n"
            .'1. 🔁 Intentar nuevamente.'."\n"
            .'2. 💬 Hablar con un asesor.'."\n"
            .'3. 🏠 Volver al menú principal.';
    }

    private function handleErrorOptions(WhatsAppConversation $conversation, int $n): string
    {
        return match ($n) {
            1 => $this->mainMenu($conversation),
            2 => $this->advisorPrompt($conversation),
            3 => $this->mainMenu($conversation),
            default => $this->errorText(),
        };
    }

    private function farewell(WhatsAppConversation $conversation): string
    {
        $this->clearFlow($conversation->phone);

        return '👋 Gracias por comunicarte con *'.$this->brand().'*.'."\n\n"
            .'Ha sido un placer atenderte.'."\n\n"
            .'Estamos comprometidos con brindarte siempre un servicio de calidad y una experiencia excepcional.'."\n\n"
            .'¡Esperamos verte pronto! 💈'."\n\n"
            .'*'.$this->brand().'*'."\n"
            .'Tu estilo, nuestra pasión.';
    }

    // ------------------------------------------------------------------------------------------
    // Textos del menú
    // ------------------------------------------------------------------------------------------

    private function menuText(): string
    {
        if (! $this->businessOpenNow()) {
            return '¡Gracias por comunicarte con *'.$this->brand().'*! 👋'."\n\n"
                .'🌙 En este momento nos encontramos *fuera de nuestro horario de atención*.'."\n\n"
                .'Puedes continuar utilizando nuestro asistente virtual para consultar servicios, precios y gestionar tus reservas.'."\n\n"
                .'1. 📅 Agendar una cita.'."\n"
                .'2. ✂️ Consultar servicios y precios.'."\n"
                .'3. 💈 Conocer nuestros barberos.'."\n"
                .'4. 👋 Finalizar conversación.';
        }

        return '¡Hola! 👋 Bienvenido a *'.$this->brand().'*.'."\n\n"
            .'Nos alegra tenerte aquí. Estamos listos para brindarte una experiencia de cuidado personal y ayudarte a lucir tu mejor estilo.'."\n\n"
            .'¿Qué deseas hacer?'."\n\n"
            .'1. 📅 Agendar una cita (con fecha y hora).'."\n"
            .'2. 🎟️ Tomar un turno (hoy, por orden de llegada).'."\n"
            .'3. ✂️ Consultar servicios y precios.'."\n"
            .'4. 💈 Conocer nuestros barberos.'."\n"
            .'5. 🔎 Consultar mi cita o turno.'."\n"
            .'6. ✏️ Modificar una reserva.'."\n"
            .'7. ❌ Cancelar una reserva.'."\n"
            .'8. 💬 Hablar con un asesor.'."\n\n"
            .'Responde únicamente con el *número* de la opción que deseas.';
    }

    // ------------------------------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------------------------------

    private function enter(string $phone, array $flow, string $step, array $data): array
    {
        $flow['history'][] = ['step' => $flow['step'] ?? 'menu', 'data' => $flow['data'] ?? []];

        if (count($flow['history']) > 20) {
            array_shift($flow['history']);
        }

        $flow['step'] = $step;
        $flow['data'] = $data;
        $this->saveFlow($phone, $flow);

        return $flow;
    }

    private function barbersForService(?int $serviceId, bool $onlyAppointments = false): \Illuminate\Support\Collection
    {
        return User::barbers()
            ->where('active', true)
            ->when($onlyAppointments, fn ($query) => $query->where('attention_mode', 'citas'))
            ->with('offeredServices')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $barber) => $serviceId === null || $barber->offersService($serviceId))
            ->values();
    }

    /** Barberos que hacen el servicio y atienden del modo pedido: "cita" (citas) o "turno" (llegada). */
    private function barbersForMode(?int $serviceId, string $mode): \Illuminate\Support\Collection
    {
        $attention = $mode === 'turno' ? 'llegada' : 'citas';

        return $this->barbersForService($serviceId)
            ->filter(fn (User $barber) => $barber->attention_mode === $attention)
            ->values();
    }

    private function barbersByIds(array $ids): \Illuminate\Support\Collection
    {
        if ($ids === []) {
            return collect();
        }

        return User::barbers()
            ->whereIn('id', $ids)
            ->with('offeredServices')
            ->orderBy('name')
            ->get()
            ->sortBy(fn (User $barber) => array_search($barber->id, $ids, true))
            ->values();
    }

    /** Próximos 14 días con al menos un horario libre para el barbero. */
    private function bookingDates(?User $barber, ?int $ignoreAppointmentId = null): array
    {
        if (! $barber) {
            return [];
        }

        $dates = [];

        for ($offset = 0; $offset < 14; $offset++) {
            $date = now()->addDays($offset)->toDateString();

            if ($this->slotsFor($barber, $date, $ignoreAppointmentId) !== []) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /** Mensaje completo (sin nada más) con el que el cliente pide no recibir más avisos. */
    public const OPT_OUT_WORDS = ['baja', 'stop', 'darme de baja', 'no mas mensajes', 'no quiero mas mensajes', 'cancelar suscripcion'];

    /**
     * El cliente revoca los avisos por WhatsApp: se apaga el consentimiento en sus reservas vigentes,
     * se anulan los avisos pendientes por ese canal y queda constancia de la revocación.
     */
    private function optOut(WhatsAppConversation $conversation): string
    {
        $local = substr(preg_replace('/\D/', '', $conversation->phone), -10);
        $ids = ['appointment' => [], 'turn' => []];
        if ($local !== '') {
            $ids['appointment'] = Appointment::where('client_phone', 'like', '%'.$local)->where('whatsapp_consent', true)->pluck('id')->all();
            $ids['turn'] = Turn::where('client_phone', 'like', '%'.$local)->where('whatsapp_consent', true)->pluck('id')->all();
            Appointment::whereIn('id', $ids['appointment'])->update(['whatsapp_consent' => false]);
            Turn::whereIn('id', $ids['turn'])->update(['whatsapp_consent' => false]);
            foreach ($ids as $type => $list) {
                \App\Models\ConsentRecord::revoke('avisos_whatsapp', $type, $list, 'whatsapp_baja');
                if ($list !== []) {
                    \App\Models\ClientNotification::where('notifiable_type', $type)->whereIn('notifiable_id', $list)
                        ->where('channel', 'whatsapp')->where('status', \App\Models\ClientNotification::PENDING)
                        ->update(['status' => \App\Models\ClientNotification::CANCELLED, 'updated_at' => now()]);
                }
            }
        }
        $this->saveFlow($conversation->phone, ['step' => 'menu', 'data' => [], 'history' => []]);
        Log::notice('whatsapp.baja', ['phone' => \App\Support\Pii::phone($conversation->phone), 'reservas' => count($ids['appointment']) + count($ids['turn'])]);

        return "Listo, no te enviaremos más avisos automáticos por WhatsApp.\n\n"
            ."Tus reservas siguen igual. Si escribes de nuevo te respondemos, y si quieres que borremos tus datos, dínoslo por aquí.";
    }

    private function pendingAppointment(string $phone): ?Appointment
    {
        $local = substr($phone, -10);

        if ($local === '') {
            return null;
        }

        return Appointment::query()
            ->with(['barber', 'service'])
            ->where('client_phone', 'like', '%'.$local)
            ->where('status', 'pendiente')
            ->whereDate('appointment_date', '>=', now()->toDateString())
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->first();
    }

    private function activeAppointment(string $phone): ?Appointment
    {
        $local = substr($phone, -10);

        if ($local === '') {
            return null;
        }

        return Appointment::query()
            ->with(['barber', 'service'])
            ->where('client_phone', 'like', '%'.$local)
            ->whereIn('status', ['pendiente', 'confirmada'])
            ->whereDate('appointment_date', '>=', now()->toDateString())
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->first();
    }

    private function activeTurn(string $phone): ?Turn
    {
        $local = substr($phone, -10);

        if ($local === '') {
            return null;
        }

        return Turn::query()
            ->with(['barber', 'service'])
            ->where('client_phone', 'like', '%'.$local)
            ->whereDate('turn_date', now()->toDateString()) // los turnos de días anteriores ya no cuentan
            ->whereIn('status', ['esperando', 'llamado', 'atendiendo'])
            ->orderBy('turn_number')
            ->first();
    }

    private function subjectTurn(array $data): ?Turn
    {
        $turn = Turn::with(['barber', 'service'])->find($data['turn_id'] ?? 0);

        return $turn && in_array($turn->status, ['esperando', 'llamado'], true) ? $turn : null;
    }

    private function subjectFrom(array $data): Appointment|Turn|null
    {
        return ($data['kind'] ?? '') === 'turn'
            ? Turn::with(['barber', 'service'])->find($data['id'] ?? 0)
            : Appointment::with(['barber', 'service'])->find($data['id'] ?? 0);
    }

    private function turnIsNear(Turn $turn): bool
    {
        if ($turn->status === 'llamado') {
            return true;
        }

        if ($turn->status !== 'esperando') {
            return false;
        }

        $estimate = $this->queue->estimates((int) $turn->barber_id)[$turn->id] ?? null;

        return $estimate !== null && $estimate['start_at']->lte(now()->addMinutes(20));
    }

    /** Horarios libres (cada 30 min) de un barbero en un día, igual que la página de reservas. */
    private function slotsFor(User $barber, string $date, ?int $ignoreAppointmentId = null): array
    {
        if ($date === '') {
            return [];
        }

        $settings = SiteSetting::currentData();
        $day = Carbon::parse($date);

        if (SiteSetting::isClosedOn($day, $settings) || $barber->isUnavailableOnDate($date)) {
            return [];
        }

        $start = Carbon::createFromFormat('H:i', $settings['booking_start'] ?? '08:00');
        $end = Carbon::createFromFormat('H:i', $settings['booking_end'] ?? '20:00');

        $reserved = Appointment::query()
            ->where('barber_id', $barber->id)
            ->whereDate('appointment_date', $date)
            ->where('status', '!=', 'cancelada')
            ->when($ignoreAppointmentId, fn ($query) => $query->where('id', '!=', $ignoreAppointmentId))
            ->pluck('appointment_time')
            ->map(fn ($time) => substr((string) $time, 0, 5))
            ->all();

        $slots = [];
        for ($slot = $start->copy(); $slot->lte($end); $slot->addMinutes(30)) {
            $formatted = $slot->format('H:i');

            if ($day->isToday() && $slot->lte(now())) {
                continue;
            }
            if (in_array($formatted, $reserved, true)) {
                continue;
            }
            if ($barber->isUnavailableAt($date, $formatted)) {
                continue;
            }

            $slots[] = $formatted;
        }

        return $slots;
    }

    private function businessOpenNow(): bool
    {
        $settings = SiteSetting::currentData();
        $now = now();

        if (SiteSetting::isClosedOn($now, $settings)) {
            return false;
        }

        $start = $settings['booking_start'] ?? '00:00';
        $end = $settings['booking_end'] ?? '23:59';
        $current = $now->format('H:i');

        return $current >= $start && $current <= $end;
    }

    private function appointmentWhen(Appointment $appointment): string
    {
        return 'el '.$this->dateLabel(Carbon::parse($appointment->appointment_date?->toDateString()))
            .' a las '.$this->slotLabel(substr((string) $appointment->appointment_time, 0, 5));
    }

    private function dateLabel(Carbon $date): string
    {
        return $date->copy()->locale('es')->translatedFormat('l j \d\e F');
    }

    private function dateOptionLabel(string $date): string
    {
        $day = Carbon::parse($date);
        $label = $this->dateLabel($day);

        if ($day->isToday()) {
            return 'Hoy, '.$label;
        }

        if ($day->isTomorrow()) {
            return 'Mañana, '.$label;
        }

        return ucfirst($label);
    }

    private function slotLabel(string $slot): string
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $slot)) {
            return $slot;
        }

        $time = Carbon::createFromFormat('H:i', $slot);

        return $time->format('g:i').' '.($time->hour >= 12 ? 'p. m.' : 'a. m.');
    }

    private function turnNumber(Turn $turn): string
    {
        return '#'.str_pad((string) $turn->turn_number, 3, '0', STR_PAD_LEFT);
    }

    private function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : explode(' ', $name)[0];
    }

    /** Nombre y apellido escritos por el cliente: letras, espacios, apóstrofos y guiones. */
    private function cleanPersonName(string $text): ?string
    {
        if (preg_match('/\d/u', $text)) {
            return null;
        }

        $name = preg_replace("/[^\\p{L}\\p{M}\\s'’.-]+/u", '', $text) ?? '';
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '' || mb_strlen($name, 'UTF-8') > 60) {
            return null;
        }

        if (count(preg_split('/\s+/u', $name)) < 2) {
            return null;
        }

        if ($name === mb_strtolower($name, 'UTF-8')) {
            $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        }

        return $name;
    }

    private function greeting(string $lead, ?string $name): string
    {
        $first = $this->firstName($name);

        return $first === '' ? $lead.'!' : $lead.', '.$first.'!';
    }

    private function brand(): string
    {
        $settings = SiteSetting::currentData();

        return (string) ($settings['brand_name'] ?? config('app.name', 'la barbería'));
    }

    private function money(float|string|int|null $amount): string
    {
        return '$'.number_format((float) $amount, 0, ',', '.');
    }

    // ------------------------------------------------------------------------------------------
    // Estado del flujo en caché (30 minutos)
    // ------------------------------------------------------------------------------------------

    private function flowKey(string $phone): string
    {
        return 'wa.bot.flow.'.$phone;
    }

    private function flow(string $phone): ?array
    {
        $flow = Cache::get($this->flowKey($phone));

        return is_array($flow) ? $flow : null;
    }

    private function saveFlow(string $phone, array $flow): void
    {
        Cache::put($this->flowKey($phone), $flow, now()->addMinutes(30));
    }

    private function clearFlow(string $phone): void
    {
        Cache::forget($this->flowKey($phone));
    }

    /** Olvida en qué paso del menú iba este cliente (al eliminar su chat de la bandeja). */
    public function forgetConversation(string $phone): void
    {
        $this->clearFlow($phone);
    }

    /** minúsculas, sin acentos y con espacios colapsados. */
    private function normalize(string $text): string
    {
        return trim((string) Str::of(Str::ascii(mb_strtolower($text, 'UTF-8'), 'UTF-8'))->squish());
    }

    private function isAffirmative(string $text): bool
    {
        $bare = trim($text, " \t\n\r\0\x0B.,!¡¿?;:-_");

        return in_array($bare, self::CONFIRM_WORDS, true);
    }

    /** ¿Aparece alguna frase como palabra completa? */
    private function has(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/ui';

            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
