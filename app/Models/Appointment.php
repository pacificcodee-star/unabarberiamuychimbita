<?php

namespace App\Models;

use App\Models\Concerns\HasClientNotifications;
use App\Support\TurnSchedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class Appointment extends Model
{
    use HasClientNotifications;

    public const ATTENDANCE_STATUSES = ['presente' => 'Llegada verificada', 'ausente' => 'No llegó'];

    public const STATUSES = [
        'pendiente' => 'Pendiente',
        'confirmada' => 'Confirmada',
        'atendida' => 'Atendida',
        'cancelada' => 'Cancelada',
    ];

    protected $fillable = [
        'guest_visitor_id',
        'public_token',
        'client_email',
        'whatsapp_consent',
        'attendance_status',
        'attendance_verified_at',
        'attendance_verified_by',
        'attendance_prompted_at',
        'started_at',
        'barber_id',
        'service_id',
        'client_name',
        'client_phone',
        'client_phone_normalized',
        'history_whatsapp_phone',
        'service_price',
        'payment_method',
        'appointment_date',
        'appointment_time',
        'status',
        'sale_id',
        'notes',
        'reminder_sent_at',
    ];

    /**
     * Cambios de estado permitidos (máquina de estados). Una cita cancelada solo se puede
     * reactivar (y entonces se vuelve a comprobar el horario); nunca pasa directo a atendida.
     * Una atendida queda cerrada.
     */
    public const TRANSITIONS = [
        'pendiente' => ['confirmada', 'cancelada', 'atendida'],
        'confirmada' => ['pendiente', 'cancelada', 'atendida'],
        'cancelada' => ['pendiente', 'confirmada'],
        'atendida' => [],
    ];

    protected static function booted(): void
    {
        static::saving(function (self $appointment): void {
            $appointment->client_phone_normalized = GuestVisitor::normalizePhone($appointment->client_phone);
        });
        // Sin cita no hay encuesta: se borra su invitación (y con ella las respuestas) para que no cuente en Resultados.
        static::deleted(fn (self $appointment) => SurveyInvitation::forSubject($appointment)->delete());
    }

    public function canTransitionTo(string $status): bool
    {
        return $status === $this->status || in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Mensaje cuando el horario ya lo tomó otra persona. */
    public const SLOT_TAKEN_MESSAGE = 'Ese horario ya está ocupado. Elige otra hora o intenta con otro barbero.';

    /** La agenda pública se ofrece en franjas de 30 minutos. */
    public const SLOT_INTERVAL_MINUTES = 30;

    /**
     * Crea (o mueve) una cita solo si el horario del barbero sigue libre, sin carreras:
     * dentro de una transacción se bloquea la fila del barbero (SELECT … FOR UPDATE), así dos
     * reservas simultáneas al mismo barbero se atienden una detrás de otra y la segunda ve ocupado.
     *
     * @throws ValidationException con el error en 'appointment_time' si el horario está ocupado.
     */
    public static function reserveSlot(array $attributes, ?self $existing = null, string $message = self::SLOT_TAKEN_MESSAGE): self
    {
        return DB::transaction(function () use ($attributes, $existing, $message) {
            $barber = User::barbers()->where('active', true)->where('attention_mode', 'citas')
                ->whereKey($attributes['barber_id'])->lockForUpdate()->first();
            $date = Carbon::parse($attributes['appointment_date'])->toDateString();
            $time = Carbon::parse($attributes['appointment_time'])->format('H:i:s');
            $status = $attributes['status'] ?? $existing?->status;

            // Una cita cancelada no ocupa tiempo ni necesita estar dentro del horario.
            if ($status === 'cancelada') {
                return $existing ? tap($existing)->update($attributes) : static::create($attributes);
            }

            if (! $barber) {
                throw ValidationException::withMessages([
                    'barber_id' => 'El barbero seleccionado no está activo o no atiende por citas.',
                ]);
            }

            $serviceId = array_key_exists('service_id', $attributes)
                ? $attributes['service_id']
                : $existing?->service_id;
            $service = $serviceId ? Service::active()->find($serviceId) : null;
            if ($serviceId && ! $service) {
                throw ValidationException::withMessages([
                    'service_id' => 'El servicio seleccionado ya no está disponible. Elige otro.',
                ]);
            }
            if ($service && ! $barber->offersService($service->id)) {
                throw ValidationException::withMessages([
                    'service_id' => 'El barbero ya no ofrece este servicio. Elige otro.',
                ]);
            }

            $duration = $service && (int) $service->duration_minutes > 0
                ? (int) $service->duration_minutes
                : max(1, (int) config('notificaciones.default_service_minutes', 30));
            $start = Carbon::parse($date.' '.$time);
            $end = $start->copy()->addMinutes($duration);

            static::assertWorkingWindow($date, $start, $end);
            static::assertBarberAvailable($barber, $date, $start, $end);

            $conflicts = static::query()
                ->with('service:id,duration_minutes')
                ->where('barber_id', $barber->id)
                ->whereDate('appointment_date', $date)
                ->where('status', '!=', 'cancelada')
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->getKey()))
                ->get(['id', 'service_id', 'appointment_time']);

            // Los extremos también cuentan como conflicto: una cita no puede empezar justo
            // cuando termina otra (ni terminar justo cuando empieza la siguiente).
            $conflict = $conflicts->first(function (self $appointment) use ($start, $end, $date): bool {
                $otherStart = Carbon::parse($date.' '.substr((string) $appointment->appointment_time, 0, 5));

                return $start->lte($otherStart->copy()->addMinutes($appointment->durationMinutes()))
                    && $end->gte($otherStart);
            });

            if ($conflict) {
                Log::notice('reservas.horario_ocupado', [
                    'barber_id' => $barber->id,
                    'fecha' => $date,
                    'hora' => $time,
                    'duracion_minutos' => $duration,
                    'cita_conflictiva' => $conflict->id,
                ]);
                throw ValidationException::withMessages(['appointment_time' => $message]);
            }

            if ($existing) {
                $existing->update($attributes);
                $existing->unsetRelation('service');

                return $existing;
            }

            return static::create($attributes);
        });
    }

    /** @return list<string> Horas de inicio que caben completas en la jornada del día. */
    public static function availableTimes(
        User $barber,
        string $date,
        ?int $ignoreAppointmentId = null,
        ?int $serviceId = null
    ): array {
        $day = $date === '' ? today()->copy() : Carbon::parse($date)->startOfDay();
        if ($day->lt(today()) || ! $barber->active || $barber->attention_mode !== 'citas') {
            return [];
        }

        $settings = SiteSetting::currentData();
        $opening = TurnSchedule::openingTime($day, $settings);
        $closing = TurnSchedule::closingTime($day, $settings);
        if ($opening === null || $closing === null || $barber->isUnavailableOnDate($day->toDateString())) {
            return [];
        }

        $service = $serviceId ? Service::active()->find($serviceId) : null;
        if ($serviceId && ! $service) {
            return [];
        }
        $duration = $service && (int) $service->duration_minutes > 0
            ? (int) $service->duration_minutes
            : max(1, (int) config('notificaciones.default_service_minutes', 30));
        $start = Carbon::parse($day->toDateString().' '.$opening);
        $end = Carbon::parse($day->toDateString().' '.$closing);

        $reserved = static::query()
            ->with('service:id,duration_minutes')
            ->where('barber_id', $barber->id)
            ->whereDate('appointment_date', $day->toDateString())
            ->where('status', '!=', 'cancelada')
            ->when($ignoreAppointmentId, fn ($query) => $query->where('id', '!=', $ignoreAppointmentId))
            ->get(['id', 'service_id', 'appointment_time']);

        $slots = [];
        for ($slot = $start->copy(); $slot->copy()->addMinutes($duration)->lte($end); $slot->addMinutes(self::SLOT_INTERVAL_MINUTES)) {
            $formatted = $slot->format('H:i');
            $slotEnd = $slot->copy()->addMinutes($duration);

            if ($day->isToday() && $slot->lte(now())) {
                continue;
            }
            if (static::barberBlocked($barber, $day->toDateString(), $slot, $slotEnd)) {
                continue;
            }
            $conflict = $reserved->contains(function (self $appointment) use ($slot, $slotEnd, $day): bool {
                $otherStart = Carbon::parse($day->toDateString().' '.substr((string) $appointment->appointment_time, 0, 5));

                return $slot->lte($otherStart->copy()->addMinutes($appointment->durationMinutes()))
                    && $slotEnd->gte($otherStart);
            });
            if ($conflict) {
                continue;
            }

            $slots[] = $formatted;
        }

        return $slots;
    }

    /** La apertura y el cierre son los de ese día, no el rango global de respaldo. */
    private static function assertWorkingWindow(string $date, Carbon $start, Carbon $end): void
    {
        $day = $start->copy()->startOfDay();
        $settings = SiteSetting::currentData();
        $opening = TurnSchedule::openingTime($day, $settings);
        $closing = TurnSchedule::closingTime($day, $settings);

        if ($opening === null || $closing === null) {
            throw ValidationException::withMessages([
                'appointment_time' => 'La barbería no presta servicio ese día. Elige otra fecha.',
            ]);
        }

        $opensAt = Carbon::parse($date.' '.$opening);
        $closesAt = Carbon::parse($date.' '.$closing);
        if ($start->lt($opensAt) || $end->gt($closesAt)) {
            throw ValidationException::withMessages([
                'appointment_time' => 'El servicio debe terminar dentro del horario de ese día. Elige otra hora.',
            ]);
        }
    }

    /** Un bloqueo no puede atravesar ninguna parte del servicio; tocar sus extremos sí está permitido. */
    private static function assertBarberAvailable(User $barber, string $date, Carbon $start, Carbon $end): void
    {
        if (! static::barberBlocked($barber, $date, $start, $end)) {
            return;
        }

        throw ValidationException::withMessages([
            'appointment_time' => 'El barbero no está disponible durante ese horario. Elige otra hora.',
        ]);
    }

    private static function barberBlocked(User $barber, string $date, Carbon $start, Carbon $end): bool
    {
        $day = Carbon::parse($date);
        $blocks = $barber->unavailabilities()
            ->where(fn ($query) => $query->whereDate('date', $date)->orWhere('weekday', $day->dayOfWeekIso))
            ->get();

        return $blocks->contains(function (BarberUnavailability $block) use ($date, $start, $end): bool {
            if ($block->isAllDay()) {
                return true;
            }

            $blockStart = Carbon::parse($date.' '.$block->start_time);
            $blockEnd = Carbon::parse($date.' '.$block->end_time);

            // Comprueba el intervalo completo: el bloqueo también puede estar dentro del servicio.
            return $start->lt($blockEnd) && $end->gt($blockStart);
        });
    }

    protected function casts(): array
    {
        return [
            'barber_id' => 'integer',
            'service_id' => 'integer',
            'guest_visitor_id' => 'integer',
            'service_price' => 'decimal:2',
            'appointment_date' => 'date',
            'whatsapp_consent' => 'boolean',
            'started_at' => 'datetime',
            'attendance_verified_at' => 'datetime',
            'attendance_verified_by' => 'integer',
            'attendance_prompted_at' => 'datetime',
            'attendance_confirmed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    public function barber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barber_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function attendanceVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_verified_by');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function guestVisitor(): BelongsTo
    {
        return $this->belongsTo(GuestVisitor::class);
    }

    public function statusEvents(): MorphMany
    {
        return $this->morphMany(ReservationStatusEvent::class, 'subject');
    }

    public function completionEvent(): MorphOne
    {
        return $this->statusEvents()->one()->ofMany(['id' => 'max'], fn ($query) => $query->where('to_status', 'atendida'));
    }

    public function durationMinutes(): int
    {
        $minutes = (int) $this->service?->duration_minutes;

        return $minutes > 0 ? $minutes : max(1, (int) config('notificaciones.default_service_minutes', 30));
    }

    /** Fecha y hora exactas en que empieza la cita. */
    public function startsAt(): Carbon
    {
        return Carbon::parse($this->appointment_date->toDateString().' '.substr((string) $this->appointment_time, 0, 5));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function attendanceLabel(): ?string
    {
        return self::ATTENDANCE_STATUSES[$this->attendance_status] ?? null;
    }
}
