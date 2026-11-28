<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\TurnSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Única regla de disponibilidad de la barbería, para citas y turnos (web, PWA, panel y bot).
 *
 * - El horario de cada día (apertura y cierre) sale del panel "Configuración del negocio" y se lee
 *   de la base de datos en cada consulta: si el admin lo cambia, la siguiente consulta ya lo usa,
 *   también en los procesos largos (cola de trabajos del bot, programador).
 * - Un día cerrado no tiene disponibilidad.
 * - Una cita empieza a la hora de apertura o después y termina a más tardar a la hora de cierre.
 * - Dos citas del mismo barbero nunca se tocan: el fin de una es estrictamente menor que el
 *   inicio de la siguiente (si un corte termina a las 8:45, la siguiente empieza a las 8:46 o más tarde).
 * - Un turno por orden de llegada se acepta solo si, con la fila actual, el servicio termina
 *   a más tardar a la hora de cierre.
 */
class BusinessHours
{
    /** Separación mínima (minutos) entre el fin de una cita y el inicio de la siguiente. */
    public const GAP_MINUTES = 1;

    /** Datos del negocio leídos de la base de datos ahora mismo (sin copias guardadas). */
    public function settings(): array
    {
        return SiteSetting::freshData();
    }

    /**
     * Apertura y cierre de ese día. Null si ese día la barbería está cerrada.
     *
     * @return array{opening: Carbon, closing: Carbon}|null
     */
    public function window(CarbonInterface|string $day, ?array $settings = null): ?array
    {
        $settings ??= $this->settings();
        $day = Carbon::parse($day instanceof CarbonInterface ? $day->format('Y-m-d') : $day)->startOfDay();

        if (SiteSetting::isClosedOn($day, $settings)) {
            return null;
        }

        $closing = TurnSchedule::closingTime($day, $settings);
        $opening = TurnSchedule::openingTime($day, $settings);
        if ($closing === null || $opening === null || $closing <= $opening) {
            return null;
        }

        return ['opening' => $this->at($day, $opening), 'closing' => $this->at($day, $closing)];
    }

    /** Primera apertura y último cierre de la semana mostrada (para dibujar la grilla del calendario del panel). */
    public function weekBounds(CarbonInterface|string|null $weekStart = null, ?array $settings = null): ?array
    {
        $settings ??= $this->settings();
        $monday = $weekStart === null
            ? today()->startOfWeek()
            : Carbon::parse($weekStart instanceof CarbonInterface ? $weekStart->format('Y-m-d') : $weekStart)->startOfWeek();
        $opening = $closing = null;

        for ($i = 0; $i < 7; $i++) {
            $window = $this->window($monday->copy()->addDays($i), $settings);
            if ($window) {
                $open = $window['opening']->format('H:i');
                $close = $window['closing']->format('H:i');
                $opening = $opening === null || $open < $opening ? $open : $opening;
                $closing = $closing === null || $close > $closing ? $close : $closing;
            }
        }

        return $opening === null ? null : ['opening' => $opening, 'closing' => $closing];
    }

    /** Duración (minutos) de un servicio; si no la tiene, la duración por defecto. */
    public function serviceMinutes(Service|int|null $service): int
    {
        if (is_int($service)) {
            $service = Service::find($service);
        }
        $minutes = (int) $service?->duration_minutes;

        return $minutes > 0 ? $minutes : $this->defaultMinutes();
    }

    public function defaultMinutes(): int
    {
        return max(1, (int) config('notificaciones.default_service_minutes', 30));
    }

    /** Cada cuántos minutos se ofrecen horas de cita a partir de la apertura. */
    public function slotStepMinutes(): int
    {
        return max(1, (int) config('notificaciones.appointment_slot_minutes', 30));
    }

    /**
     * Horas libres (HH:MM) para una cita de $minutes minutos con ese barbero ese día.
     *
     * Se ofrecen las horas de la grilla (apertura, apertura + paso, …) y, además, el primer minuto
     * libre después de cada cita ya agendada, siempre que el servicio quepa entero antes del cierre
     * y no se cruce con otra cita ni con un bloqueo del barbero.
     */
    public function appointmentSlots(User $barber, string $date, ?int $minutes = null, ?int $ignoreAppointmentId = null): array
    {
        if ($date === '' || ! $barber->active || $barber->attention_mode !== 'citas') {
            return [];
        }

        $day = Carbon::parse($date)->startOfDay();
        $date = $day->toDateString();
        $window = $this->window($day);
        if ($day->lt(today()) || $window === null || $barber->isUnavailableOnDate($date)) {
            return [];
        }

        $minutes = max(1, $minutes ?? $this->defaultMinutes());
        $busy = $this->busyRanges($barber->id, $date, $ignoreAppointmentId);
        $latestStart = $window['closing']->copy()->subMinutes($minutes);
        $now = now();

        $candidates = [];
        for ($slot = $window['opening']->copy(); $slot->lte($latestStart); $slot->addMinutes($this->slotStepMinutes())) {
            $candidates[$slot->format('H:i')] = $slot->copy();
        }
        foreach ($busy as [, $end]) {
            $after = $end->copy()->addMinutes(self::GAP_MINUTES);
            if ($after->gte($window['opening']) && $after->lte($latestStart)) {
                $candidates[$after->format('H:i')] = $after;
            }
        }
        ksort($candidates);

        $slots = [];
        foreach ($candidates as $label => $start) {
            $end = $start->copy()->addMinutes($minutes);
            if ($start->lte($now) || $this->overlapsAny($start, $end, $busy)) {
                continue;
            }
            if ($barber->isUnavailableBetween($date, $start->format('H:i'), $end->format('H:i'))) {
                continue;
            }
            $slots[] = $label;
        }

        return $slots;
    }

    /**
     * Motivo por el que no se puede agendar esa cita, o null si se puede.
     * Lo usa la reserva dentro de la transacción (con la fila del barbero bloqueada).
     */
    public function appointmentProblem(User $barber, string $date, string $time, int $minutes, ?int $ignoreAppointmentId = null, string $takenMessage = Appointment::SLOT_TAKEN_MESSAGE): ?string
    {
        $day = Carbon::parse($date)->startOfDay();
        $window = $this->window($day);
        if ($window === null) {
            return 'Ese día la barbería está cerrada. Elige otra fecha.';
        }

        $start = $this->at($day, substr($time, 0, 5));
        $end = $start->copy()->addMinutes(max(1, $minutes));
        $hours = TurnSchedule::timeLabel($window['opening']).' a '.TurnSchedule::timeLabel($window['closing']);

        if ($start->lt($window['opening']) || $start->gte($window['closing'])) {
            return "Esa hora está fuera del horario de atención de ese día ({$hours}). Elige otra hora.";
        }
        if ($end->gt($window['closing'])) {
            return 'El servicio terminaría a las '.TurnSchedule::timeLabel($end).', después del cierre ('
                .TurnSchedule::timeLabel($window['closing']).'). Elige una hora más temprana.';
        }
        if ($this->overlapsAny($start, $end, $this->busyRanges($barber->id, $day->toDateString(), $ignoreAppointmentId))) {
            return $takenMessage;
        }
        if ($barber->isUnavailableBetween($day->toDateString(), $start->format('H:i'), $end->format('H:i'))) {
            return 'El barbero no está disponible durante ese horario. Elige otra hora.';
        }

        return null;
    }

    /**
     * Estado de la recepción de turnos ahora (abierta, antes de abrir, terminada o día cerrado),
     * con el horario leído de la base de datos. El último momento para pedir turno es el que deja
     * terminar el servicio más corto antes del cierre (margen de llegada + duración).
     */
    public function turnState(?CarbonInterface $now = null): array
    {
        return TurnSchedule::forNow($now, $this->settings(), $this->turnLeadMinutes());
    }

    /**
     * ¿Cabe hoy un turno nuevo de ese servicio con ese barbero? Se calcula con la fila real:
     * se le llamaría cuando el barbero quede libre, tiene el margen de llegada y luego dura el servicio.
     * El turno se acepta solo si termina a más tardar a la hora de cierre.
     *
     * @return array{ok: bool, message: string, ends_at: ?Carbon, closing: ?Carbon}
     */
    public function turnFit(User $barber, Service|int|null $service, ?CarbonInterface $now = null): array
    {
        $now = $now === null ? now() : Carbon::instance($now);
        $state = $this->turnState($now);
        if (! $state['open']) {
            return ['ok' => false, 'message' => $state['message'], 'ends_at' => null, 'closing' => $state['closing']];
        }

        $queue = app(TurnQueueService::class);
        $callAt = $queue->freeAt($barber->id);
        $callAt = $callAt->gt($now) ? $callAt : $now->copy();
        $endsAt = $callAt->copy()->addMinutes($queue->graceMinutes() + $this->serviceMinutes($service));
        $closing = $state['closing'];

        if ($endsAt->gt($closing)) {
            return [
                'ok' => false,
                'message' => 'Con los turnos que ya están en fila, su servicio terminaría hacia las '.TurnSchedule::timeLabel($endsAt)
                    .', después del cierre a las '.TurnSchedule::timeLabel($closing).'. Hoy ya no alcanzamos a atender este servicio con '
                    .$barber->name.'. Puede elegir un servicio más corto, otro barbero o agendar una cita para otro día.',
                'ends_at' => $endsAt,
                'closing' => $closing,
            ];
        }

        return ['ok' => true, 'message' => $state['message'], 'ends_at' => $endsAt, 'closing' => $closing];
    }

    /** Margen de llegada + duración del servicio activo más corto: lo mínimo que necesita un turno. */
    public function turnLeadMinutes(): int
    {
        $shortest = (int) Service::query()->active()->where('duration_minutes', '>', 0)->min('duration_minutes');

        return app(TurnQueueService::class)->graceMinutes() + ($shortest > 0 ? $shortest : $this->defaultMinutes());
    }

    /** ¿Se cruzan los intervalos cerrados [s1, e1] y [s2, e2]? Tocarse en un minuto ya es cruzarse. */
    public static function overlaps(CarbonInterface $s1, CarbonInterface $e1, CarbonInterface $s2, CarbonInterface $e2): bool
    {
        return $s1->lte($e2) && $s2->lte($e1);
    }

    /** @param list<array{0: Carbon, 1: Carbon}> $busy */
    private function overlapsAny(Carbon $start, Carbon $end, array $busy): bool
    {
        foreach ($busy as [$busyStart, $busyEnd]) {
            if (self::overlaps($start, $end, $busyStart, $busyEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inicio y fin de las citas vigentes del barbero ese día. Si ya fue atendida, el fin es el
     * momento real persistido; si sigue abierta, se usa la duración prevista del servicio.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function busyRanges(int $barberId, string $date, ?int $ignoreAppointmentId): array
    {
        return Appointment::query()
            ->with(['service', 'completionEvent'])
            ->where('barber_id', $barberId)
            ->whereDate('appointment_date', $date)
            ->where('status', '!=', 'cancelada')
            ->when($ignoreAppointmentId, fn ($query) => $query->whereKeyNot($ignoreAppointmentId))
            ->orderBy('appointment_time')
            ->get()
            ->map(function (Appointment $appointment) use ($date) {
                $start = $this->at(Carbon::parse($date), substr((string) $appointment->appointment_time, 0, 5));

                return [$start, $appointment->occupiedUntil()];
            })
            ->all();
    }

    private function at(CarbonInterface $day, string $time): Carbon
    {
        return Carbon::parse($day->format('Y-m-d').' '.$time);
    }
}
