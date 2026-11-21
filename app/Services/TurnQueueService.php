<?php

namespace App\Services;

use App\Models\StaffNotification;
use App\Models\Turn;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fila automática de turnos. Cada barbero tiene su propia fila independiente.
 *
 * Ciclo de un turno:  esperando -> llamado -> atendiendo -> atendido
 *   - llamado:    se avisó al cliente "puedes pasar". Tiene un margen de llegada (10 min por defecto).
 *   - atendiendo: al cumplirse el margen (o si el barbero lo marca antes) empieza a contar la duración del servicio.
 *   - atendido:   al cumplirse la duración se cierra solo, se registra el ingreso y se llama al siguiente.
 *
 * No hay temporizadores en memoria: todo se calcula con las horas guardadas en la base de datos (called_at,
 * started_at), así que si el servidor se reinicia la fila continúa donde iba en la siguiente pasada del programador.
 */
class TurnQueueService
{
    public const OPEN = ['esperando', 'llamado', 'atendiendo'];

    public function __construct(private ClientNotifier $notifier, private AttendedServiceRecorder $recorder)
    {
    }

    public function graceMinutes(): int
    {
        return max(0, (int) config('notificaciones.turn_grace_minutes', 10));
    }

    public function durationMinutes(Turn $turn): int
    {
        $minutes = (int) ($turn->service?->duration_minutes ?: 0);

        return $minutes > 0 ? $minutes : max(1, (int) config('notificaciones.default_service_minutes', 30));
    }

    /** Avanza la fila de todos los barberos que tienen turnos abiertos hoy. Lo ejecuta el programador cada minuto. */
    public function advanceAll(): void
    {
        Turn::query()
            ->whereDate('turn_date', now()->toDateString())
            ->whereIn('status', self::OPEN)
            ->distinct()
            ->pluck('barber_id')
            ->each(fn ($barberId) => $this->advance((int) $barberId));
    }

    /**
     * Revisa la fila de un barbero y hace lo que corresponda según la hora: iniciar el servicio del cliente llamado,
     * finalizar el servicio que ya cumplió su tiempo, llamar al siguiente y avisar al que sigue.
     * Es seguro llamarlo muchas veces: bloquea la fila del barbero y los avisos no se duplican.
     */
    public function advance(int $barberId, ?Turn $cancel = null): void
    {
        $auto = (bool) config('notificaciones.auto_queue', true);

        $events = DB::transaction(function () use ($barberId, $auto, $cancel) {
            $barber = User::whereKey($barberId)->lockForUpdate()->first();
            if (! $barber) {
                return [];
            }

            if ($cancel) {
                $turn = Turn::whereKey($cancel->id)->where('barber_id', $barberId)->lockForUpdate()->firstOrFail();
                if (! in_array('cancelar', ClientBookingActions::actions($turn), true)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'Este turno ya no se puede cancelar.']);
                }
                $turn->update(['status' => 'cancelado', 'finished_at' => now()]);
                $this->notifier->turnCancelled($turn);
            }

            $now = now();
            $turns = $this->openTurns($barberId);
            $active = $turns->first(fn (Turn $t) => in_array($t->status, ['llamado', 'atendiendo'], true));
            $finishedPrevious = false;

            if ($auto && $active && $active->status === 'llamado') {
                $calledAt = $active->called_at ?: $active->updated_at;
                if ($now->gte($calledAt->copy()->addMinutes($this->graceMinutes()))) {
                    $active->update(['status' => 'atendiendo', 'started_at' => $now]);
                }
            }

            if ($auto && $active && $active->status === 'atendiendo') {
                $startedAt = $active->started_at ?: $active->called_at ?: $active->updated_at;
                if ($now->gte($startedAt->copy()->addMinutes($this->durationMinutes($active)))) {
                    $active->update(['status' => 'atendido', 'finished_at' => $now]);
                    $this->recorder->sync($active, $active->payment_method);
                    $finished = $active;
                    $active = null;
                    $finishedPrevious = true;
                }
            }

            $waiting = $turns->filter(fn (Turn $t) => $t->status === 'esperando')->values();
            $events = isset($finished) ? [['finished', $finished, false]] : [];

            if ($auto && ! $active && $waiting->isNotEmpty() && ! $barber->isUnavailableAt($now->toDateString(), $now->format('H:i'))) {
                $next = $waiting->shift();
                $next->update(['status' => 'llamado', 'called_at' => $now]);
                $events[] = ['called', $next, $finishedPrevious];
                $active = $next;
            }

            if ($active && $waiting->isNotEmpty()) {
                $events[] = ['next', $waiting->first(), false];
            }

            return $events;
        });

        $this->dispatch($barberId, $events, true);
    }

    /** Botón "Llamar siguiente" del panel: sigue disponible, pero ya no es obligatorio. */
    public function callNextManually(User $barber): array
    {
        $result = DB::transaction(function () use ($barber) {
            User::whereKey($barber->id)->lockForUpdate()->first();
            $turns = $this->openTurns($barber->id);

            if ($called = $turns->firstWhere('status', 'llamado')) {
                return ['ok' => false, 'message' => "El turno #{$called->turn_number} ya fue llamado. Márcalo \"En atención\" cuando llegue o \"Anulado\" si no llegó.", 'events' => []];
            }

            $next = $turns->firstWhere('status', 'esperando');
            if (! $next) {
                return ['ok' => false, 'message' => "No hay turnos esperando para {$barber->name}.", 'events' => []];
            }

            // El turno que se estaba atendiendo pasa a "atendido" y se registra como servicio realizado (ingreso).
            $finished = false;
            foreach ($turns->where('status', 'atendiendo') as $previous) {
                $previous->update(['status' => 'atendido', 'finished_at' => now()]);
                $this->recorder->sync($previous, $previous->payment_method);
                $finished = true;
            }

            $next->update(['status' => 'llamado', 'called_at' => now()]);
            $events = [['called', $next, $finished]];
            if ($after = $turns->first(fn (Turn $t) => $t->status === 'esperando' && $t->id !== $next->id)) {
                $events[] = ['next', $after, false];
            }

            return ['ok' => true, 'message' => "Llamando al turno #{$next->turn_number} de {$next->client_name}. Ya se le avisó.", 'events' => $events];
        });

        $this->dispatch($barber->id, $result['events']);

        return ['ok' => $result['ok'], 'message' => $result['message']];
    }

    /**
     * Posición y horas estimadas de cada turno en espera de un barbero.
     *
     * @return array<int, array{ahead:int, call_at:Carbon, start_at:Carbon, end_at:Carbon}>  indexado por id de turno
     */
    public function estimates(int $barberId): array
    {
        $now = now();
        $grace = $this->graceMinutes();
        $cursor = $now->copy();
        $ahead = 0;
        $estimates = [];

        foreach ($this->openTurns($barberId) as $turn) {
            $duration = $this->durationMinutes($turn);

            if ($turn->status === 'atendiendo') {
                $end = ($turn->started_at ?: $turn->called_at ?: $now)->copy()->addMinutes($duration);
                $cursor = $end->gt($now) ? $end : $now->copy();
                $ahead++;
            } elseif ($turn->status === 'llamado') {
                $start = ($turn->called_at ?: $now)->copy()->addMinutes($grace);
                $end = ($start->gt($now) ? $start : $now->copy())->addMinutes($duration);
                $cursor = $end;
                $ahead++;
            } else {
                $callAt = $cursor->copy();
                $startAt = $callAt->copy()->addMinutes($grace);
                $endAt = $startAt->copy()->addMinutes($duration);
                $estimates[$turn->id] = ['ahead' => $ahead, 'call_at' => $callAt, 'start_at' => $startAt, 'end_at' => $endAt];
                $cursor = $endAt->copy();
                $ahead++;
            }
        }

        return $estimates;
    }

    /**
     * Avisa a los clientes cuyo turno empieza dentro de la ventana (15 minutos por defecto).
     * Se recalcula en cada pasada, así que si la fila se mueve el aviso se actualiza.
     */
    public function remindUpcoming(?int $windowMinutes = null): int
    {
        $windowMinutes = $windowMinutes ?? (int) config('notificaciones.turn_reminder_minutes', 15);
        $now = now();
        $sent = 0;

        $barberIds = Turn::query()
            ->where('status', 'esperando')
            ->whereDate('turn_date', $now->toDateString())
            ->distinct()
            ->pluck('barber_id');

        foreach ($barberIds as $barberId) {
            foreach ($this->estimates((int) $barberId) as $turnId => $estimate) {
                $minutes = (int) floor($now->diffInMinutes($estimate['start_at'], false));

                if ($minutes < 1 || $minutes > $windowMinutes) {
                    continue;
                }

                $turn = Turn::with(['barber', 'service'])->find($turnId);

                if (! $turn || $turn->status !== 'esperando') {
                    continue;
                }

                $this->notifier->turnStartingSoon($turn, $estimate['start_at'], $minutes);
                $sent++;
            }
        }

        return $sent;
    }

    private function openTurns(int $barberId)
    {
        return Turn::query()
            ->with(['service', 'barber'])
            ->where('barber_id', $barberId)
            ->whereDate('turn_date', now()->toDateString())
            ->whereIn('status', self::OPEN)
            ->orderBy('turn_number')
            ->get();
    }

    /** Guarda y envía los avisos fuera de la transacción (no se hacen llamadas de red con la fila bloqueada). */
    private function dispatch(int $barberId, array $events, bool $automatic = false): void
    {
        if ($events !== []) {
            $estimates = $this->estimates($barberId);
            $url = route('turns.index', [], false);
            foreach ($events as [$kind, $turn, $afterPrevious]) {
                if ($kind === 'finished') {
                    // Al cliente: gracias por la visita. Al equipo: el turno se cerró solo al cumplirse su tiempo.
                    $this->notifier->serviceThanks($turn);
                    StaffNotification::record('turn_finished', "Turno #{$turn->turn_number} finalizado automáticamente", "{$turn->client_name}: se cumplió el tiempo del servicio".($turn->sale_id ? ' y se registró el ingreso.' : '.').' Si el cliente no llegó, anula el turno.', $url, $barberId);
                } elseif ($kind === 'called') {
                    $this->notifier->turnCalled($turn, $afterPrevious);
                    if ($automatic) {
                        StaffNotification::record('turn_called', "Turno #{$turn->turn_number} llamado: {$turn->client_name}", 'Se le avisó que puede pasar. Tiene '.$this->graceMinutes().' minutos para llegar.', $url, $barberId);
                    }
                } else {
                    $this->notifier->turnNext($turn, $estimates[$turn->id]['call_at'] ?? null);
                }
            }
        }

        $this->notifier->flush();
    }
}
