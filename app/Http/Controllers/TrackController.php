<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\PushSubscription;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Services\ClientNotifier;
use App\Services\TurnQueueService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Página de seguimiento del cliente (sin cuenta). El acceso es por el enlace secreto del turno o cita:
 * quien no tenga ese enlace no puede ver, modificar ni cancelar nada, aunque conozca el teléfono o el nombre.
 */
class TrackController extends Controller
{
    private const MAX_DEVICES = 5;

    /** Servicios push oficiales de los navegadores. No se aceptan otras direcciones. */
    private const PUSH_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
        '.push.apple.com',
        '.notify.windows.com',
        '.push.services.mozilla.com',
    ];

    public function show(string $type, string $token, TurnQueueService $queue)
    {
        $subject = $this->find($type, $token);

        return response()
            ->view('public.track', [
                'type' => $type,
                'subject' => $subject,
                'state' => $this->state($subject, $queue),
                'vapidPublicKey' => (string) config('services.webpush.public_key'),
                'hasDevices' => $subject->pushSubscriptions()->exists(),
            ])
            ->header('Referrer-Policy', 'no-referrer') // el enlace secreto no se filtra a otros sitios
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }

    public function status(string $type, string $token, TurnQueueService $queue)
    {
        return response()
            ->json($this->state($this->find($type, $token), $queue))
            ->header('Cache-Control', 'no-store, private');
    }

    /** Manifest propio para iPhone: al "Agregar a inicio" desde esta página, la app abre directamente este turno o cita. */
    public function manifest(string $type, string $token)
    {
        $subject = $this->find($type, $token);
        $manifest = json_decode((string) @file_get_contents(public_path('manifest.webmanifest')), true) ?: [
            'name' => config('app.name'),
            'display' => 'standalone',
            'scope' => '/',
        ];
        $manifest['start_url'] = app(ClientNotifier::class)->trackUrl($subject);
        unset($manifest['id']);

        return response()
            ->json($manifest)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'no-store, private');
    }

    public function cancel(string $type, string $token, TurnQueueService $queue, ClientNotifier $notifier)
    {
        $subject = $this->find($type, $token);
        $back = redirect()->route('track.show', ['type' => $type, 'token' => $token]);

        if ($subject instanceof Turn) {
            // Actualización condicionada: solo cancela si todavía no ha comenzado el servicio.
            $cancelled = Turn::whereKey($subject->id)
                ->whereIn('status', ['esperando', 'llamado'])
                ->update(['status' => 'cancelado', 'finished_at' => now(), 'updated_at' => now()]);

            if (! $cancelled) {
                $message = $subject->fresh()->status === 'atendiendo'
                    ? 'Tu servicio ya comenzó, así que no se puede cancelar desde aquí. Habla directamente con tu barbero.'
                    : ($subject->fresh()->status === 'cancelado' ? 'Tu turno ya estaba cancelado.' : 'Este turno ya no se puede cancelar.');

                return $back->with('track_error', $message);
            }

            $notifier->turnCancelled($subject);
            StaffNotification::record(
                'client_cancelled',
                'Turno #'.$subject->turn_number.' cancelado por el cliente',
                $subject->client_name.' canceló su turno. La fila se reorganizó sola.',
                route('turns.index', [], false),
                $subject->barber_id,
            );
            $queue->advance($subject->barber_id); // los demás clientes se adelantan y se recalculan sus horas

            return $back->with('track_success', 'Tu turno fue cancelado. No recibirás más avisos.');
        }

        $before = ['status' => $subject->status, 'starts_at' => $subject->startsAt()];
        $cancelled = $subject->startsAt()->isFuture()
            ? Appointment::whereKey($subject->id)
                ->whereIn('status', ['pendiente', 'confirmada'])
                ->update(['status' => 'cancelada', 'updated_at' => now()])
            : 0;

        if (! $cancelled) {
            return $back->with('track_error', $subject->fresh()->status === 'cancelada'
                ? 'Tu cita ya estaba cancelada.'
                : 'Esta cita ya no se puede cancelar desde aquí. Si necesitas ayuda, escríbenos.');
        }

        $notifier->appointmentChanged($subject->fresh(), $before);
        $notifier->flush();
        StaffNotification::record(
            'client_cancelled',
            'Cita cancelada por el cliente',
            $subject->client_name.' canceló su cita del '.$before['starts_at']->format('d/m').' a las '.$this->time($before['starts_at']).' El horario quedó libre.',
            route('appointments.index', ['date' => $before['starts_at']->toDateString()], false),
            $subject->barber_id,
        );

        return $back->with('track_success', 'Tu cita fue cancelada. No recibirás más recordatorios.');
    }

    /** Guarda la suscripción push de un dispositivo y la asocia a este turno o cita. */
    public function subscribe(Request $request, string $type, string $token, ClientNotifier $notifier)
    {
        $subject = $this->find($type, $token);
        if (! $this->isOpen($subject)) {
            return response()->json(['message' => 'Este '.$type.' ya terminó, así que no hay avisos por enviar.'], 409);
        }

        $data = $this->validatedSubscription($request);
        $hash = PushSubscription::hashEndpoint($data['endpoint']);

        $exists = $subject->pushSubscriptions()->where('endpoint_hash', $hash)->exists();
        if (! $exists && $subject->pushSubscriptions()->count() >= self::MAX_DEVICES) {
            return response()->json(['message' => 'Ya hay demasiados dispositivos recibiendo avisos de este '.$type.'.'], 422);
        }

        $subject->pushSubscriptions()->updateOrCreate(
            ['endpoint_hash' => $hash],
            [
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ]
        );

        // Si la confirmación ya salió antes (o caducó), se manda un aviso corto para que el cliente vea que funciona.
        $hasPending = $subject->clientNotifications()
            ->where('channel', 'push')
            ->where('status', ClientNotification::PENDING)
            ->where('scheduled_for', '<=', now())
            ->exists();
        if (! $hasPending && ! $exists) {
            $notifier->queue($subject, 'push_enabled', 'Avisos activados', 'Listo. Te avisaremos aquí aunque cierres la página.', null, 10, $hash);
        }
        $notifier->deliverPendingFor($subject);

        return response()->json(['ok' => true]);
    }

    /** El cliente desactiva los avisos en este dispositivo. */
    public function unsubscribe(Request $request, string $type, string $token)
    {
        $subject = $this->find($type, $token);
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        $subject->pushSubscriptions()->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * El navegador renovó la suscripción por su cuenta (evento pushsubscriptionchange del Service Worker).
     * Solo quien conoce la dirección anterior (que es secreta) puede reemplazarla.
     */
    public function renew(Request $request)
    {
        $old = $request->validate(['old_endpoint' => ['required', 'string', 'max:2000']])['old_endpoint'];
        $data = $this->validatedSubscription($request, 'subscription.');
        $new = $data['subscription'];

        $updated = 0;
        foreach (PushSubscription::where('endpoint_hash', PushSubscription::hashEndpoint($old))->get() as $subscription) {
            try {
                $subscription->update([
                    'endpoint' => $new['endpoint'],
                    'endpoint_hash' => PushSubscription::hashEndpoint($new['endpoint']),
                    'p256dh' => $new['keys']['p256dh'],
                    'auth' => $new['keys']['auth'],
                ]);
                $updated++;
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $subscription->delete(); // ese turno o cita ya tenía registrada la suscripción nueva
            }
        }

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    // ---------------------------------------------------------------------------------------------

    private function find(string $type, string $token): Model
    {
        $model = $type === 'turno' ? Turn::class : Appointment::class;

        $subject = $model::with(['barber', 'service'])->where('public_token', $token)->firstOrFail();

        // Vencimiento: el enlace deja de funcionar 30 días después de la fecha del turno o la cita,
        // así un enlace viejo reenviado o filtrado no muestra datos para siempre.
        $date = $subject instanceof Turn ? $subject->turn_date : $subject->appointment_date;
        abort_if($date && $date->copy()->addDays((int) config('notificaciones.track_link_days', 30))->endOfDay()->isPast(), 404);

        return $subject;
    }

    private function isOpen(Model $subject): bool
    {
        if ($subject instanceof Turn) {
            return in_array($subject->status, TurnQueueService::OPEN, true) && $subject->turn_date->isToday();
        }

        return in_array($subject->status, ['pendiente', 'confirmada'], true)
            && $subject->startsAt()->copy()->addMinutes(15)->isFuture();
    }

    /** Estado que ve el cliente. Nunca incluye datos de otras personas de la fila. */
    private function state(Model $subject, TurnQueueService $queue): array
    {
        if ($subject instanceof Turn) {
            $estimate = $subject->status === 'esperando' ? ($queue->estimates($subject->barber_id)[$subject->id] ?? null) : null;
            $grace = $queue->graceMinutes();

            $detail = match ($subject->status) {
                'esperando' => $estimate
                    ? ($estimate['ahead'] === 0 ? 'Eres el siguiente.' : ($estimate['ahead'] === 1 ? 'Hay 1 persona antes de ti.' : "Hay {$estimate['ahead']} personas antes de ti."))
                    : 'Estás en la fila.',
                'llamado' => "¡Es tu turno! Puedes pasar. Tienes {$grace} minutos para llegar".($subject->called_at ? ' (hasta las '.$this->time($subject->called_at->copy()->addMinutes($grace)).').' : '.'),
                'atendiendo' => 'Tu servicio está en curso.',
                'atendido' => 'Tu servicio ya terminó. ¡Gracias por tu visita!',
                default => 'Este turno fue cancelado.',
            };

            return [
                'status' => $subject->status,
                'label' => $subject->statusLabel(),
                'detail' => $detail,
                'ahead' => $estimate['ahead'] ?? null,
                'call_at' => isset($estimate['call_at']) ? $this->time($estimate['call_at']) : null,
                'can_cancel' => in_array($subject->status, ['esperando', 'llamado'], true),
                'open' => $this->isOpen($subject),
                'notifications' => $this->inbox($subject),
            ];
        }

        $active = in_array($subject->status, ['pendiente', 'confirmada'], true);

        return [
            'status' => $subject->status,
            'label' => $subject->statusLabel(),
            'detail' => match ($subject->status) {
                'pendiente' => 'Recibimos tu cita. El equipo la confirmará pronto.',
                'confirmada' => 'Tu cita está confirmada. ¡Te esperamos!',
                'atendida' => 'Tu cita ya fue atendida. ¡Gracias por tu visita!',
                default => 'Esta cita fue cancelada.',
            },
            'ahead' => null,
            'call_at' => null,
            'can_cancel' => $active && $subject->startsAt()->isFuture(),
            'open' => $this->isOpen($subject),
            'notifications' => $this->inbox($subject),
        ];
    }

    /** Avisos de este turno o cita cuya hora ya llegó. Se ven en la campana aunque el push no se haya podido entregar. */
    private function inbox(Model $subject): array
    {
        return $subject->clientNotifications()
            ->where('channel', 'push')
            ->where('type', '!=', 'push_enabled')
            ->where('status', '!=', ClientNotification::CANCELLED)
            ->where('scheduled_for', '<=', now())
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (ClientNotification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'at' => $this->time($n->scheduled_for, withDate: ! $n->scheduled_for->isToday()),
            ])
            ->all();
    }

    private function time(Carbon $moment, bool $withDate = false): string
    {
        $time = $moment->format('g:i').' '.((int) $moment->format('G') >= 12 ? 'p. m.' : 'a. m.');

        return $withDate ? $moment->format('d/m').' '.$time : $time;
    }

    private function validatedSubscription(Request $request, string $prefix = ''): array
    {
        $data = $request->validate([
            $prefix.'endpoint' => ['required', 'string', 'max:2000', 'starts_with:https://'],
            $prefix.'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-+\/=]+$/'],
            $prefix.'keys.auth' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-+\/=]+$/'],
            $prefix.'contentEncoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ]);

        $endpoint = $prefix === '' ? $data['endpoint'] : $data['subscription']['endpoint'];
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $allowed = false;
        foreach (self::PUSH_HOSTS as $pattern) {
            if ($host === ltrim($pattern, '.') || (str_starts_with($pattern, '.') && str_ends_with($host, $pattern))) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            throw ValidationException::withMessages(['endpoint' => 'Este navegador usa un servicio de notificaciones que no reconocemos.']);
        }

        return $data;
    }
}
