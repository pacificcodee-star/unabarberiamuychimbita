<?php

namespace App\Models;

use App\Models\Concerns\HasClientNotifications;
use App\Services\BusinessHours;
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
            $barber = User::whereKey($attributes['barber_id'])->lockForUpdate()->first();
            $date = Carbon::parse($attributes['appointment_date'])->toDateString();
            $time = Carbon::parse($attributes['appointment_time'])->format('H:i:s');
            $sameSlot = $existing && $existing->status !== 'cancelada'
                && $existing->barber_id === (int) $attributes['barber_id']
                && $existing->appointment_date->toDateString() === $date
                && Carbon::parse($existing->appointment_time)->format('H:i:s') === $time
                && (! array_key_exists('service_id', $attributes) || (int) $attributes['service_id'] === (int) $existing->service_id);

            if (! $sameSlot && ($attributes['status'] ?? $existing?->status) !== 'cancelada'
                && $barber?->isUnavailableAt($date, $time)) {
                throw ValidationException::withMessages([
                    'appointment_time' => 'El barbero no está disponible en ese horario. Elige otra hora.',
                ]);
            }

            // Horario del día, cierre y choques con otras citas (contando la duración de cada servicio).
            // Una cita cancelada no ocupa el horario, así que guardarla así nunca choca.
            $serviceId = $attributes['service_id'] ?? $existing?->service_id;
            $problem = ! $sameSlot && $barber && ($attributes['status'] ?? $existing?->status) !== 'cancelada'
                ? app(BusinessHours::class)->appointmentProblem($barber, $date, $time, app(BusinessHours::class)->serviceMinutes($serviceId ? (int) $serviceId : null), $existing?->getKey(), $message)
                : null;

            if ($problem !== null) {
                Log::notice('reservas.horario_ocupado', [
                    'barber_id' => $attributes['barber_id'],
                    'fecha' => (string) $attributes['appointment_date'],
                    'hora' => (string) $attributes['appointment_time'],
                    'motivo' => $problem,
                ]);
                throw ValidationException::withMessages(['appointment_time' => $problem]);
            }

            if ($existing) {
                $existing->update($attributes);

                return $existing;
            }

            return static::create($attributes);
        });
    }

    /**
     * Horas libres (HH:MM) para una cita con ese barbero ese día. La regla (horario del día leído de la
     * base de datos, duración del servicio, sin choques con otras citas) vive en App\Services\BusinessHours.
     */
    public static function availableTimes(User $barber, string $date, ?int $ignoreAppointmentId = null, Service|int|string|null $service = null): array
    {
        $hours = app(BusinessHours::class);
        $minutes = $service === null || $service === '' ? $hours->defaultMinutes() : $hours->serviceMinutes($service instanceof Service ? $service : (int) $service);

        return $hours->appointmentSlots($barber, $date, $minutes, $ignoreAppointmentId);
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
