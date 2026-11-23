<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\SiteSetting;
use App\Models\Turn;
use App\Support\AppointmentMessages;
use App\Support\AttendanceConfirmation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Avisos a clientes (push + WhatsApp) asociados a un turno o a una cita, nunca a una cuenta.
 *
 * - queue*(): guarda el aviso en la tabla client_notifications (una fila por canal). No envía nada todavía,
 *   para no hacer llamadas de red dentro de transacciones de base de datos.
 * - flush(): envía de inmediato lo que se guardó en esta petición.
 * - processDue(): lo ejecuta el programador cada minuto; envía recordatorios cuya hora ya llegó y reintenta fallos.
 *
 * La columna única dedupe_key garantiza que un mismo aviso no se envíe dos veces.
 */
class ClientNotifier
{
    public const REMINDER_TYPES = ['appt_reminder_60', 'appt_reminder_15', 'appt_reminder_0', 'appt_reminder_30'];

    /** @var int[] ids guardados en esta petición, pendientes de enviar con flush() */
    private array $queued = [];

    public function __construct(private PushSender $push, private WhatsAppService $whatsApp)
    {
    }

    // ---------------------------------------------------------------------------------------------
    // Turnos
    // ---------------------------------------------------------------------------------------------

    public function turnConfirmed(Turn $turn): void
    {
        $this->queue(
            $turn,
            'turn_confirmed',
            'Turno '.$this->number($turn).' confirmado',
            '¡Tu turno ha sido confirmado! Tu número de turno es el '.$this->number($turn).$this->withBarberAndService($turn).'. Te avisaremos cuando se aproxime tu atención.',
            ttlMinutes: (int) config('notificaciones.ttl_minutes.confirmation', 120),
        );
    }

    public function turnNext(Turn $turn, ?Carbon $estimatedCall = null): void
    {
        $when = $estimatedCall ? ' Hora aproximada: '.$this->time($estimatedCall) : '';

        $this->queue(
            $turn,
            'turn_next',
            '¡Prepárate! Eres el siguiente',
            '¡Prepárate! Tu turno '.$this->number($turn).$this->withBarberAndService($turn).' está próximo. Eres el siguiente en ser atendido.'.$when,
            ttlMinutes: (int) config('notificaciones.ttl_minutes.turn_next', 30),
        );
    }

    public function turnCalled(Turn $turn, bool $afterPreviousClient = true): void
    {
        $barber = $turn->barber?->name ?: 'El barbero';
        $grace = (int) config('notificaciones.turn_grace_minutes', 10);
        $reason = $afterPreviousClient ? "{$barber} ha terminado con el cliente anterior." : "{$barber} ya está disponible.";

        $this->queue(
            $turn,
            'turn_called',
            '¡Llegó tu turno '.$this->number($turn).'!',
            "¡Llegó tu turno! {$reason} Puedes pasar. Tienes {$grace} minutos para llegar.",
            ttlMinutes: (int) config('notificaciones.ttl_minutes.turn_called', 15),
        );
    }

    /** Aviso 15 minutos antes del inicio estimado del turno (se recalcula si la fila se mueve). */
    public function turnStartingSoon(Turn $turn, Carbon $startAt, int $minutesBefore): void
    {
        $stamp = $startAt->format('YmdHi');
        $prefix = $turn->notificationKey().':turn_reminder:';

        // Si la fila se movió, el aviso anterior queda anulado para que no llegue con una hora vieja.
        ClientNotification::query()
            ->where('notifiable_type', $turn->getMorphClass())
            ->where('notifiable_id', $turn->getKey())
            ->where('type', 'turn_reminder')
            ->where('status', ClientNotification::PENDING)
            ->where('dedupe_key', 'not like', $prefix.$stamp.'%')
            ->update(['status' => ClientNotification::CANCELLED, 'updated_at' => now()]);

        $body = '¡Hola'.($this->firstName($turn->client_name) !== '' ? ', '.$this->firstName($turn->client_name) : '')."!\n\n"
            .'¡Tenemos una actualización importante sobre tu turno!'."\n\n"
            .'Tu atención en '.$this->brand().' está próxima a comenzar.'."\n\n"
            .'💈 Número de turno: '.$this->number($turn)."\n"
            .($turn->service ? '✂️ Servicio: '.$turn->service->name."\n" : '')
            .($turn->barber ? '💈 Barbero: '.$turn->barber->name."\n" : '')
            ."\n".'⏳ Tiempo estimado para iniciar tu atención: '.max(1, $minutesBefore).' minutos.'."\n\n"
            .'¿Deseas continuar con tu turno?'."\n\n"
            .'1. Sí, deseo recibir el servicio.'."\n"
            .'2. Cancelar mi turno.'."\n"
            .'3. Necesito más información.'."\n\n"
            .'Selecciona una opción para actualizar el estado de tu turno.';

        $this->queue($turn, 'turn_reminder', 'Tu turno está por comenzar', $body, ttlMinutes: 15, stamp: $stamp);
    }

    /** El cliente confirmó que desea recibir su turno y ya puede acercarse a la barbería. */
    public function turnArrivalConfirmed(Turn $turn): void
    {
        $this->queue(
            $turn,
            'turn_confirmed',
            'Turno '.$this->number($turn).' confirmado',
            '¡Excelente! Hemos registrado tu confirmación. Tu turno '.$this->number($turn).' continúa activo y ya puedes acercarte a nuestra barbería para recibir tu servicio.',
            ttlMinutes: (int) config('notificaciones.ttl_minutes.confirmation', 120),
            stamp: 'arrival',
        );
    }

    /** Mensaje de agradecimiento cuando el servicio (cita o turno) quedó como atendido. Se envía una sola vez. */
    public function serviceThanks(Appointment|Turn $subject): void
    {
        $name = $this->firstName($subject->client_name);
        $url = rtrim((string) config('app.url'), '/').'/';

        $body = '¡Gracias por tu visita'.($name !== '' ? ', '.$name : '').'! 💈'."\n\n"
            .'Fue un gusto atenderte en '.$this->brand().'. Esperamos que disfrutes tu nuevo estilo.'."\n\n"
            .'Cuando quieras volver, reserva aquí: '.$url."\n\n"
            .'¡Te esperamos pronto!';

        $this->queue(
            $subject,
            'service_thanks',
            '¡Gracias por tu visita!',
            $body,
            ttlMinutes: (int) config('notificaciones.ttl_minutes.change', 120),
        );
    }

    /** La cita o el turno se canceló solo porque el cliente no confirmó a tiempo tras el recordatorio. */
    public function cancelledWithoutConfirmation(Appointment|Turn $subject, int $minutes): void
    {
        $this->cancelPending($subject);
        $isTurn = $subject instanceof Turn;
        $name = $this->firstName($subject->client_name);
        $url = rtrim((string) config('app.url'), '/').'/';

        $detail = $isTurn
            ? 'tu turno '.$this->number($subject)
            : 'tu cita '.$this->appointmentWhen($subject, lower: true);

        $body = 'Hola'.($name !== '' ? ', '.$name : '').'.'."\n\n"
            .'Como no recibimos tu confirmación en los '.$minutes.' minutos siguientes al recordatorio, '
            .$detail.' fue *cancelad'.($isTurn ? 'o' : 'a').'* para darle el espacio a otro cliente.'."\n\n"
            .'Si aún quieres atenderte, puedes '.($isTurn ? 'tomar un turno nuevo' : 'agendar otra cita').' aquí: '.$url."\n"
            .'o escribiéndonos por este chat.'."\n\n"
            .$this->brand();

        $this->queue(
            $subject,
            $isTurn ? 'turn_cancelled' : 'appt_cancelled',
            ($isTurn ? 'Turno' : 'Cita').' cancelad'.($isTurn ? 'o' : 'a').' por falta de confirmación',
            $body,
            ttlMinutes: (int) config('notificaciones.ttl_minutes.change', 120),
            stamp: 'sin-confirmar',
        );
    }

    /** Confirmación de cancelación de turno, la pida el cliente o la barbería. */
    public function turnCancelled(Turn $turn): void
    {
        $this->cancelPending($turn);
        $this->queue(
            $turn,
            'turn_cancelled',
            'Turno '.$this->number($turn).' cancelado',
            $this->cancellationBody($turn),
            ttlMinutes: (int) config('notificaciones.ttl_minutes.change', 120),
            stamp: now()->format('YmdHi'),
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Citas
    // ---------------------------------------------------------------------------------------------

    /** Confirmación inmediata + recordatorios (1 hora antes, 15 minutos antes y a la hora). */
    public function appointmentBooked(Appointment $appointment): void
    {
        $autoConfirmed = $this->autoConfirmIfSoon($appointment);

        if ($autoConfirmed) {
            // Agendada en el último cuarto de hora: queda confirmada sola, sin pregunta de confirmación.
            $title = '✅ Tu cita quedó confirmada';
            $body = AppointmentMessages::confirmed($appointment, automatic: true);
            $push = AppointmentMessages::short($appointment).'. Ya quedó confirmada.';
        } elseif ($appointment->status === 'confirmada') {
            // Agendada ya confirmada (p. ej. desde el panel): se envía directamente la confirmación completa.
            $title = '✅ Tu cita quedó confirmada';
            $body = AppointmentMessages::confirmed($appointment);
            $push = AppointmentMessages::short($appointment);
        } else {
            // Primero se pide confirmar; al confirmar llega el mensaje "¡Hola…! Tu cita quedó confirmada".
            $title = '📅 Confirma tu cita';
            $body = AppointmentMessages::confirmationRequest($appointment);
            $push = AppointmentMessages::short($appointment).'. Toca para confirmarla.';
        }

        $this->queue(
            $appointment,
            'appt_booked',
            $title,
            $body,
            ttlMinutes: (int) config('notificaciones.ttl_minutes.confirmation', 120),
            pushBody: $push,
        );
        $this->scheduleAppointmentReminders($appointment);
    }

    /**
     * Cita agendada faltando 15 minutos o menos (página, panel o bot): no hay tiempo para pedir
     * confirmación, así que queda confirmada sola. Devuelve true si se confirmó automáticamente.
     */
    public function autoConfirmIfSoon(Appointment $appointment): bool
    {
        $window = (int) config('notificaciones.confirm_window_minutes', 15);
        $minutesLeft = now()->diffInMinutes($appointment->startsAt(), false);

        if ($minutesLeft > $window || ! in_array($appointment->status, ['pendiente', 'confirmada'], true)) {
            return false;
        }

        if ($appointment->status !== 'confirmada') {
            $appointment->update(['status' => 'confirmada']);
        }
        AttendanceConfirmation::mark($appointment);

        return true;
    }

    /**
     * Llamar después de modificar una cita. $before = ['status' => ..., 'starts_at' => Carbon] con los valores anteriores.
     * Todo cambio de estado se le avisa al cliente por WhatsApp (si lo autorizó) y por push al navegador:
     * confirmada, pendiente otra vez, reprogramada, cancelada, reactivada o atendida.
     *
     * $by: 'client' (lo hizo el cliente en la página), 'staff' (panel) o 'bot' (el cliente por WhatsApp:
     * el bot ya le contestó en el chat, así que solo se manda el push para no repetir el mensaje).
     */
    public function appointmentChanged(Appointment $appointment, array $before, string $by = 'staff'): void
    {
        $appointment->loadMissing(['service', 'barber']);
        $from = $before['status'] ?? null;
        $to = $appointment->status;
        $active = in_array($to, ['pendiente', 'confirmada'], true);
        $wasActive = in_array($from, ['pendiente', 'confirmada'], true);
        $start = $appointment->startsAt();
        $moved = isset($before['starts_at']) && ! $start->equalTo($before['starts_at']);
        $ttl = (int) config('notificaciones.ttl_minutes.change', 120);
        $only = $by === 'bot' ? ['push'] : null;
        $stamp = now()->format('YmdHis');
        $short = AppointmentMessages::short($appointment); // resumen de una línea para el aviso del navegador

        if (! $active) {
            $this->cancelPending($appointment);

            if ($to === 'cancelada' && $from !== 'cancelada') {
                $this->queue($appointment, 'appt_cancelled', 'Cita cancelada', $this->cancellationBody($appointment),
                    ttlMinutes: $ttl, stamp: $stamp, only: $only, pushBody: $short.'. Si quieres, agenda otra cita.');
            } elseif ($to === 'atendida' && $from !== 'atendida') {
                $this->serviceThanks($appointment);
            }

            return;
        }

        if (! $wasActive) { // se reactivó una cita cancelada o atendida
            $this->scheduleAppointmentReminders($appointment);
            $this->queue($appointment, 'appt_reactivated', 'Tu cita fue reactivada', AppointmentMessages::reactivated($appointment),
                ttlMinutes: $ttl, stamp: $stamp, only: $only, pushBody: $short.($appointment->status === 'pendiente' ? '. Toca para confirmarla.' : ''));

            return;
        }

        if ($moved) {
            $this->cancelPending($appointment, self::REMINDER_TYPES);
            $this->scheduleAppointmentReminders($appointment);
            $this->queue($appointment, 'appt_rescheduled', '🔄 Tu cita fue reprogramada', AppointmentMessages::rescheduled($appointment),
                ttlMinutes: $ttl, stamp: $start->format('YmdHi'), only: $only, pushBody: 'Nuevo horario: '.$short);

            return;
        }

        if ($from === 'pendiente' && $to === 'confirmada') {
            $this->queue($appointment, 'appt_confirmed', '✅ Tu cita quedó confirmada',
                AppointmentMessages::confirmed($appointment, thanks: $by !== 'staff'),
                ttlMinutes: $ttl, stamp: $stamp, only: $only, pushBody: $short);
        } elseif ($from === 'confirmada' && $to === 'pendiente') {
            $this->queue($appointment, 'appt_pending', '📅 Confirma tu cita',
                AppointmentMessages::confirmationRequest($appointment, 'Tu cita en *'.$this->brand().'* quedó *pendiente de confirmación*.'),
                ttlMinutes: $ttl, stamp: $stamp, only: $only, pushBody: $short.'. Toca para confirmarla.');
        }
    }

    public function scheduleAppointmentReminders(Appointment $appointment): void
    {
        if (! in_array($appointment->status, ['pendiente', 'confirmada'], true)) {
            return;
        }

        $start = $appointment->startsAt();
        $barber = $appointment->barber?->name ?: 'tu barbero';
        $hour = $this->time($start);
        $date = $start->copy()->locale('es')->translatedFormat('l j \d\e F');
        $texts = [
            30 => [
                'Recordatorio: tu cita es en 30 minutos',
                '¡Hola'.($this->firstName($appointment->client_name) !== '' ? ', '.$this->firstName($appointment->client_name) : '')."!\n\n"
                .'Te recordamos que dentro de 30 minutos tienes una cita programada en '.$this->brand().".\n\n"
                .'📅 Fecha: '.$date."\n"
                .'🕒 Hora: '.$hour."\n"
                .($appointment->service?->name ? '✂️ Servicio: '.$appointment->service->name."\n" : '')
                .'💈 Barbero: '.$barber."\n\n"
                .'¿Deseas mantener tu reserva?'."\n\n"
                .'1. Confirmar mi asistencia.'."\n"
                .'2. Cancelar mi cita.'."\n"
                .'3. Hablar con un asesor.'."\n\n"
                .'Tu confirmación nos ayuda a organizar mejor nuestra agenda.'."\n\n"
                .'¡Te esperamos!',
            ],
        ];

        foreach ((array) config('notificaciones.appointment_reminders', [30 => 20]) as $minutesBefore => $ttl) {
            $at = $start->copy()->subMinutes((int) $minutesBefore);
            $early = false;
            if ($at->lte(now())) {
                // La hora del recordatorio ya pasó (cita agendada sobre la hora). Si aún faltan 15 minutos o más,
                // se manda ya para que el cliente alcance a confirmar; con menos de 15 se confirmó sola al agendar.
                if (now()->diffInMinutes($start, false) <= (int) config('notificaciones.confirm_window_minutes', 15) || AttendanceConfirmation::confirmed($appointment)) {
                    continue;
                }
                $at = now();
                $early = true;
            }
            [$title, $body] = $texts[$minutesBefore] ?? ['Recordatorio de tu cita', "Tu cita con {$barber} es a las {$hour}"];
            $left = (int) ceil(now()->diffInMinutes($start, false));
            if ($early && $left < (int) $minutesBefore) {
                // Enviado antes de tiempo: el texto dice los minutos que faltan de verdad.
                $title = str_replace($minutesBefore.' minutos', $left.' minutos', $title);
                $body = str_replace($minutesBefore.' minutos', $left.' minutos', $body);
            }
            $this->queue($appointment, 'appt_reminder_'.$minutesBefore, $title, $body, $at, (int) $ttl, $start->format('YmdHi'));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Cola, envío y cancelación
    // ---------------------------------------------------------------------------------------------

    /**
     * Guarda el aviso (push siempre; WhatsApp solo si el cliente lo autorizó y dejó teléfono).
     * $stamp distingue avisos del mismo tipo que sí deben repetirse (p. ej. recordatorios tras reprogramar).
     */
    public function queue(Model $subject, string $type, string $title, string $body, ?Carbon $at = null, int $ttlMinutes = 60, string $stamp = '', ?string $url = null, ?array $only = null, ?string $pushBody = null): void
    {
        $at = $at ?: now();
        $channels = ['push'];
        if ($subject->whatsapp_consent && filled($subject->client_phone)) {
            $channels[] = 'whatsapp';
        }
        if ($only !== null) {
            $channels = array_values(array_intersect($channels, $only)); // p. ej. solo push cuando el bot ya respondió
        }

        foreach ($channels as $channel) {
            $key = implode(':', array_filter([$subject->notificationKey(), $type, $stamp, $channel], fn ($part) => $part !== ''));
            $attributes = [
                'notifiable_type' => $subject->getMorphClass(),
                'notifiable_id' => $subject->getKey(),
                'type' => $type,
                'channel' => $channel,
                'title' => Str::limit($title, 140, ''),
                // WhatsApp lleva el mensaje completo; el aviso del navegador, un resumen corto y sin asteriscos.
                'body' => $channel === 'push' ? $this->pushSummary($pushBody ?? $body) : $body,
                'url' => $url ?? $this->trackUrl($subject),
                'scheduled_for' => $at,
                'expires_at' => $at->copy()->addMinutes(max(1, $ttlMinutes)),
                'status' => ClientNotification::PENDING,
                'attempts' => 0,
                'last_error' => null,
            ];

            try {
                $existing = ClientNotification::where('dedupe_key', $key)->first();
                if ($existing) {
                    // Ya existe: solo se reactiva si estaba cancelada (p. ej. la cita volvió a su hora original).
                    if ($existing->status !== ClientNotification::CANCELLED) {
                        continue;
                    }
                    $existing->update($attributes);
                    $row = $existing;
                } else {
                    $row = ClientNotification::create($attributes + ['dedupe_key' => $key]);
                }
                if ($at->lte(now())) {
                    $this->queued[] = $row->id;
                }
            } catch (UniqueConstraintViolationException) {
                // Otro proceso guardó el mismo aviso al mismo tiempo: no se duplica.
            }
        }
    }

    /** Envía ahora los avisos inmediatos guardados en esta petición. Lo que falle lo reintenta el programador. */
    public function flush(): void
    {
        $ids = array_unique($this->queued);
        $this->queued = [];

        foreach ($ids as $id) {
            $this->deliverSafely(ClientNotification::find($id));
        }
    }

    /** Envía lo pendiente de un turno o cita (se usa cuando el cliente acaba de activar los avisos en un dispositivo). */
    public function deliverPendingFor(Model $subject): void
    {
        $subject->clientNotifications()
            ->where('status', ClientNotification::PENDING)
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->get()
            ->each(fn (ClientNotification $n) => $this->deliverSafely($n));
    }

    /** Tarea de cada minuto: caducidad, recuperación de envíos colgados y envío de todo lo que ya debe salir. */
    public function processDue(int $limit = 300): int
    {
        $now = now();

        ClientNotification::where('status', ClientNotification::PENDING)
            ->where('expires_at', '<', $now)
            ->update(['status' => ClientNotification::EXPIRED, 'updated_at' => $now]);

        // Si el servidor se apagó a mitad de un envío, el aviso vuelve a la cola.
        ClientNotification::where('status', ClientNotification::SENDING)
            ->where('updated_at', '<', $now->copy()->subMinutes(3))
            ->update(['status' => ClientNotification::PENDING, 'updated_at' => $now]);

        $due = ClientNotification::where('status', ClientNotification::PENDING)
            ->where('scheduled_for', '<=', $now)
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->get();

        $sent = 0;
        foreach ($due as $notification) {
            $sent += $this->deliverSafely($notification) ? 1 : 0;
        }

        return $sent;
    }

    /** Cancela los avisos que aún no han salido (todos, o solo los tipos indicados). */
    public function cancelPending(Model $subject, ?array $types = null): void
    {
        $subject->clientNotifications()
            ->whereIn('status', [ClientNotification::PENDING, ClientNotification::SENDING])
            ->when($types, fn ($query) => $query->whereIn('type', $types))
            ->update(['status' => ClientNotification::CANCELLED, 'updated_at' => now()]);
    }

    private function deliverSafely(?ClientNotification $notification): bool
    {
        if (! $notification) {
            return false;
        }

        try {
            return $this->deliver($notification);
        } catch (Throwable $e) {
            Log::error('Aviso a cliente: error inesperado', ['id' => $notification->id, 'error' => $e->getMessage()]);
            ClientNotification::whereKey($notification->id)
                ->where('status', ClientNotification::SENDING)
                ->update(['status' => ClientNotification::PENDING, 'last_error' => Str::limit($e->getMessage(), 500)]);

            return false;
        }
    }

    private function deliver(ClientNotification $notification): bool
    {
        // "Reclamo" atómico: si dos procesos intentan enviar el mismo aviso, solo uno lo logra.
        $claimed = ClientNotification::whereKey($notification->id)
            ->where('status', ClientNotification::PENDING)
            ->update(['status' => ClientNotification::SENDING, 'updated_at' => now()]);
        if (! $claimed) {
            return false;
        }
        $notification->refresh();

        $subject = $notification->notifiable;
        $subjectCancelled = $subject && in_array($subject->status, ['cancelado', 'cancelada'], true);
        $isCancellationNotice = in_array($notification->type, ['turn_cancelled', 'appt_cancelled'], true);

        if (! $subject || ($subjectCancelled && ! $isCancellationNotice)) {
            $notification->update(['status' => ClientNotification::CANCELLED]);

            return false;
        }

        if ($notification->expires_at && $notification->expires_at->isPast()) {
            $notification->update(['status' => ClientNotification::EXPIRED]);

            return false;
        }

        return $notification->channel === 'whatsapp'
            ? $this->deliverWhatsApp($notification, $subject)
            : $this->deliverPush($notification, $subject);
    }

    private function deliverPush(ClientNotification $notification, Model $subject): bool
    {
        $subscriptions = $subject->pushSubscriptions()->get();
        if ($subscriptions->isEmpty()) {
            // Aún no hay dispositivos: queda pendiente hasta que el cliente active los avisos o el aviso caduque.
            $notification->update(['status' => ClientNotification::PENDING]);

            return false;
        }

        $ttl = $notification->expires_at ? max(60, (int) now()->diffInSeconds($notification->expires_at, false)) : 600;
        $payload = [
            'title' => $notification->title,
            'body' => $this->plainText($notification->body), // el push no interpreta la negrita de WhatsApp
            'url' => $notification->url ?: '/',
            'tag' => preg_replace('/:push$/', '', $notification->dedupe_key),
        ];

        $delivered = 0;
        $remaining = 0;
        $error = null;
        foreach ($subscriptions as $subscription) {
            $result = $this->push->send($subscription, $payload, $ttl);
            if ($result['ok']) {
                $delivered++;
                $remaining++;
                $subscription->forceFill(['last_success_at' => now()])->save();
            } elseif ($result['gone']) {
                $subscription->delete(); // el cliente quitó el permiso o desinstaló: se limpia la suscripción inválida
            } else {
                $remaining++;
                $error = $result['error'];
            }
        }

        if ($delivered > 0) {
            $notification->update(['status' => ClientNotification::SENT, 'sent_at' => now(), 'last_error' => $error]);

            return true;
        }

        if ($remaining === 0) {
            $notification->update(['status' => ClientNotification::PENDING, 'last_error' => 'Las suscripciones del cliente ya no eran válidas.']);

            return false;
        }

        $this->registerFailure($notification, $error);

        return false;
    }

    private function deliverWhatsApp(ClientNotification $notification, Model $subject): bool
    {
        if (! $subject->whatsapp_consent || blank($subject->client_phone)) {
            $notification->update(['status' => ClientNotification::CANCELLED, 'last_error' => 'Sin autorización o sin teléfono.']);

            return false;
        }

        if (! $this->whatsApp->isConfigured()) {
            Log::info('WhatsApp (simulado, sin API configurada)', ['to' => \App\Support\Pii::phone($subject->client_phone), 'caracteres' => mb_strlen((string) $notification->body)]);
            $notification->update(['status' => ClientNotification::FAILED, 'last_error' => 'WhatsApp Business no está configurado.']);

            return false;
        }

        // Solo la Cloud API de Meta usa plantillas; con Evolution se manda el texto libre.
        $template = $this->whatsApp->usesTemplates() ? config('services.whatsapp.templates.'.$notification->type) : null;
        $ok = $template
            ? $this->whatsApp->sendTemplate($subject->client_phone, $template, [$subject->client_name, $notification->body])
            : $this->whatsApp->sendText($subject->client_phone, $notification->body);

        if ($ok) {
            $notification->update(['status' => ClientNotification::SENT, 'sent_at' => now()]);

            return true;
        }

        $this->registerFailure($notification, 'WhatsApp rechazó el mensaje (revisa storage/logs/laravel.log).');

        return false;
    }

    private function registerFailure(ClientNotification $notification, ?string $error): void
    {
        $attempts = $notification->attempts + 1;
        $notification->update([
            'attempts' => $attempts,
            'status' => $attempts >= (int) config('notificaciones.max_attempts', 3) ? ClientNotification::FAILED : ClientNotification::PENDING,
            'last_error' => Str::limit((string) $error, 500),
        ]);
        Log::warning('Aviso a cliente no entregado', ['id' => $notification->id, 'canal' => $notification->channel, 'intento' => $attempts, 'error' => $error]);
    }

    // ---------------------------------------------------------------------------------------------
    // Textos
    // ---------------------------------------------------------------------------------------------

    public function trackUrl(Model $subject): string
    {
        return route('track.show', [
            'type' => $subject instanceof Turn ? 'turno' : 'cita',
            'token' => $subject->public_token,
        ], false);
    }

    private function number(Turn $turn): string
    {
        return '#'.str_pad((string) $turn->turn_number, 3, '0', STR_PAD_LEFT);
    }

    private function withBarberAndService(Turn $turn): string
    {
        $text = $turn->barber?->name ? ' con '.$turn->barber->name : '';
        if ($turn->service?->name) {
            $text .= ' ('.$turn->service->name.')';
        }

        return $text;
    }

    private function appointmentWhen(Appointment $appointment, bool $lower = false): string
    {
        $text = Str::ucfirst('el '.$this->dateTime($appointment->startsAt()));
        if ($appointment->barber?->name) {
            $text .= ' con '.$appointment->barber->name;
        }
        if ($appointment->service?->name) {
            $text .= ' ('.$appointment->service->name.')';
        }

        return $lower ? Str::lcfirst($text) : $text;
    }

    private function dateTime(Carbon $moment): string
    {
        return $moment->copy()->locale('es')->translatedFormat('l j \d\e F').' a las '.$this->time($moment);
    }

    private function time(Carbon $moment): string
    {
        return $moment->format('g:i').' '.((int) $moment->format('G') >= 12 ? 'p. m.' : 'a. m.');
    }

    /** Cierra la frase sin duplicar el punto cuando ya termina en «p. m.» o «a. m.». */
    private function withDot(string $text): string
    {
        return str_ends_with($text, '.') ? $text : $text.'.';
    }

    /** Quita la negrita de WhatsApp (*texto*) para los canales que la muestran tal cual. */
    /**
     * Texto para la notificación del navegador o de la app: sin asteriscos, sin opciones de menú
     * ("1. Volver…") ni enlaces sueltos, y en máximo 160 caracteres.
     */
    private function pushSummary(string $text): string
    {
        $lines = collect(preg_split('/\R/u', str_replace('*', '', $text)))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => $line !== '' && ! preg_match('/^\d+\.\s/u', $line) && ! preg_match('~^https?://~u', $line));

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $lines->take(2)->implode(' '))), 160, '…');
    }

    private function plainText(string $text): string
    {
        return (string) preg_replace('/\*{1,2}([^*\n]+)\*{1,2}/u', '$1', $text);
    }

    private function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : explode(' ', $name)[0];
    }

    private function brand(): string
    {
        $settings = SiteSetting::currentData();

        return (string) ($settings['brand_name'] ?? config('app.name', 'la barbería'));
    }

    /**
     * Confirmación de cancelación (cita o turno) con los datos de la página:
     * fecha y hora de la reserva, el enlace oficial para volver a reservar, la marca y el eslogan.
     */
    private function cancellationBody(Appointment|Turn $subject): string
    {
        $settings = SiteSetting::currentData();
        $brand = (string) ($settings['brand_name'] ?? config('app.name', 'la barbería'));
        $tagline = trim((string) ($settings['tagline'] ?? ''));
        $url = rtrim((string) config('app.url'), '/').'/';
        $isTurn = $subject instanceof Turn;

        $lines = [
            '*Confirmación de cancelación de '.($isTurn ? 'turno' : 'cita').'*',
            '',
            $isTurn
                ? 'Te informamos que tu turno ha sido cancelado correctamente.'
                : 'Te informamos que tu cita ha sido cancelada correctamente.',
            '',
        ];

        if ($isTurn) {
            $lines[] = '*Turno:* '.$this->number($subject);
            $lines[] = '*Fecha:* '.$subject->turn_date->copy()->locale('es')->translatedFormat('l j \d\e F');
        } else {
            $start = $subject->startsAt();
            $lines[] = '*Fecha:* '.$start->copy()->locale('es')->translatedFormat('l j \d\e F');
            $lines[] = '*Hora:* '.$this->time($start);
        }

        $lines[] = '';
        $lines[] = $isTurn
            ? 'Si deseas reservar un nuevo turno, puedes hacerlo fácilmente a través de nuestro sitio web oficial:'
            : 'Si deseas reservar una nueva cita, puedes hacerlo fácilmente a través de nuestro sitio web oficial:';
        $lines[] = '';
        $lines[] = '🌐 '.$url;
        $lines[] = '';
        $lines[] = 'Será un gusto atenderte nuevamente.';
        $lines[] = '';
        $lines[] = '*'.$brand.'*';
        if ($tagline !== '') {
            $lines[] = str_ends_with($tagline, '.') ? $tagline : $tagline.'.';
        }

        return implode("\n", $lines);
    }
}
