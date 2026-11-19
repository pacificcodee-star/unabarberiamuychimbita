<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\Turn;
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
    public const REMINDER_TYPES = ['appt_reminder_60', 'appt_reminder_15', 'appt_reminder_0'];

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
            '¡Tu turno ha sido confirmado! Tu número es el '.$this->number($turn).$this->withBarberAndService($turn).'. Te avisaremos cuando se aproxime tu atención.',
            ttlMinutes: (int) config('notificaciones.ttl_minutes.confirmation', 120),
        );
    }

    public function turnNext(Turn $turn, ?Carbon $estimatedCall = null): void
    {
        $when = $estimatedCall ? ' Hora aproximada: '.$estimatedCall->format('g:i A').'.' : '';

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

    /** Solo cuando cancela la barbería. Si cancela el propio cliente no se le envía nada más. */
    public function turnCancelledByStaff(Turn $turn): void
    {
        $this->queue(
            $turn,
            'turn_cancelled',
            'Turno '.$this->number($turn).' cancelado',
            'Tu turno '.$this->number($turn).' fue cancelado por la barbería. Si quieres, puedes tomar uno nuevo desde la página.',
            ttlMinutes: (int) config('notificaciones.ttl_minutes.change', 120),
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Citas
    // ---------------------------------------------------------------------------------------------

    /** Confirmación inmediata + recordatorios (1 hora antes, 15 minutos antes y a la hora). */
    public function appointmentBooked(Appointment $appointment): void
    {
        $this->queue(
            $appointment,
            'appt_booked',
            '¡Tu cita quedó registrada!',
            '¡Tu cita quedó registrada! '.$this->appointmentWhen($appointment).'. Te la recordaremos 1 hora y 15 minutos antes.',
            ttlMinutes: (int) config('notificaciones.ttl_minutes.confirmation', 120),
        );
        $this->scheduleAppointmentReminders($appointment);
    }

    /**
     * Llamar después de modificar una cita. $before = ['status' => ..., 'starts_at' => Carbon] con los valores anteriores.
     * Cancela los recordatorios viejos y crea los nuevos cuando corresponde.
     */
    public function appointmentChanged(Appointment $appointment, array $before, bool $byClient = false): void
    {
        $active = in_array($appointment->status, ['pendiente', 'confirmada'], true);
        $wasActive = in_array($before['status'] ?? null, ['pendiente', 'confirmada'], true);
        $start = $appointment->startsAt();
        $moved = isset($before['starts_at']) && ! $start->equalTo($before['starts_at']);

        if (! $active) {
            $this->cancelPending($appointment);
            if ($appointment->status === 'cancelada' && $wasActive && ! $byClient) {
                $this->queue(
                    $appointment,
                    'appt_cancelled',
                    'Cita cancelada',
                    'Tu cita del '.$this->dateTime($start).' fue cancelada. Si quieres, puedes agendar otra desde la página.',
                    ttlMinutes: (int) config('notificaciones.ttl_minutes.change', 120),
                    stamp: now()->format('YmdHi'),
                );
            }

            return;
        }

        if (! $wasActive) { // se reactivó una cita cancelada
            $this->scheduleAppointmentReminders($appointment);

            return;
        }

        if ($moved) {
            $this->cancelPending($appointment, self::REMINDER_TYPES);
            $this->scheduleAppointmentReminders($appointment);
            $this->queue(
                $appointment,
                'appt_rescheduled',
                'Tu cita fue reprogramada',
                'Tu cita fue reprogramada. Ahora es '.$this->appointmentWhen($appointment, lower: true).'.',
                ttlMinutes: (int) config('notificaciones.ttl_minutes.change', 120),
                stamp: $start->format('YmdHi'),
            );

            return;
        }

        if (($before['status'] ?? null) === 'pendiente' && $appointment->status === 'confirmada') {
            $this->queue(
                $appointment,
                'appt_confirmed',
                'Cita confirmada',
                'Tu cita fue confirmada: '.$this->appointmentWhen($appointment, lower: true).'. ¡Te esperamos!',
                ttlMinutes: (int) config('notificaciones.ttl_minutes.change', 120),
            );
        }
    }

    public function scheduleAppointmentReminders(Appointment $appointment): void
    {
        if (! in_array($appointment->status, ['pendiente', 'confirmada'], true)) {
            return;
        }

        $start = $appointment->startsAt();
        $barber = $appointment->barber?->name ?: 'tu barbero';
        $hour = $start->format('g:i A');
        $texts = [
            60 => ['Tu cita es en 1 hora', "Tu cita con {$barber} es en 1 hora ({$hour}). ¡Te esperamos!"],
            15 => ['Tu cita es en 15 minutos', "Tu cita con {$barber} es en 15 minutos ({$hour}). Ve saliendo para llegar a tiempo."],
            0 => ['¡Es la hora de tu cita!', "¡Es la hora de tu cita con {$barber}! Te estamos esperando."],
        ];

        foreach ((array) config('notificaciones.appointment_reminders', [60 => 30, 15 => 10, 0 => 10]) as $minutesBefore => $ttl) {
            $at = $start->copy()->subMinutes((int) $minutesBefore);
            if ($at->lte(now())) {
                continue; // esa hora ya pasó (por ejemplo, cita agendada para dentro de 40 minutos)
            }
            [$title, $body] = $texts[$minutesBefore] ?? ['Recordatorio de tu cita', "Tu cita con {$barber} es a las {$hour}."];
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
    public function queue(Model $subject, string $type, string $title, string $body, ?Carbon $at = null, int $ttlMinutes = 60, string $stamp = ''): void
    {
        $at = $at ?: now();
        $channels = ['push'];
        if ($subject->whatsapp_consent && filled($subject->client_phone)) {
            $channels[] = 'whatsapp';
        }

        foreach ($channels as $channel) {
            $key = implode(':', array_filter([$subject->notificationKey(), $type, $stamp, $channel], fn ($part) => $part !== ''));
            $attributes = [
                'notifiable_type' => $subject->getMorphClass(),
                'notifiable_id' => $subject->getKey(),
                'type' => $type,
                'channel' => $channel,
                'title' => Str::limit($title, 140, ''),
                'body' => $body,
                'url' => $this->trackUrl($subject),
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
            'body' => $notification->body,
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
            Log::info('WhatsApp (simulado, sin API configurada)', ['to' => $subject->client_phone, 'message' => $notification->body]);
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
        $text = Str::ucfirst($this->dateTime($appointment->startsAt()));
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
        return $moment->copy()->locale('es')->translatedFormat('l j \d\e F').' a las '.$moment->format('g:i A');
    }
}
