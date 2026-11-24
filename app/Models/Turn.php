<?php

namespace App\Models;

use App\Models\Concerns\HasClientNotifications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Turn extends Model
{
    use HasClientNotifications;

    // 'llamado': ya se le avisó al cliente que puede pasar y corre el margen de llegada.
    public const STATUSES = [
        'esperando' => 'En espera',
        'llamado' => 'Llamado',
        'atendiendo' => 'En atención',
        'atendido' => 'Finalizado',
        'cancelado' => 'Anulado',
    ];

    /**
     * Cambios de estado permitidos. Un turno anulado solo puede volver a la fila (esperando),
     * nunca pasar a finalizado; uno finalizado queda cerrado.
     */
    public const TRANSITIONS = [
        'esperando' => ['llamado', 'atendiendo', 'cancelado'],
        'llamado' => ['esperando', 'atendiendo', 'atendido', 'cancelado'],
        'atendiendo' => ['atendido', 'cancelado'],
        'cancelado' => ['esperando'],
        'atendido' => [],
    ];

    protected static function booted(): void
    {
        static::saving(function (self $turn): void {
            $turn->client_phone_normalized = GuestVisitor::normalizePhone($turn->client_phone);
        });
        // Sin turno no hay encuesta: se borra su invitación (y con ella las respuestas) para que no cuente en Resultados.
        static::deleted(fn (self $turn) => SurveyInvitation::forSubject($turn)->delete());
    }

    public function canTransitionTo(string $status): bool
    {
        return $status === $this->status || in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    protected $fillable = [
        'guest_visitor_id',
        'public_token',
        'client_email',
        'whatsapp_consent',
        'started_at',
        'barber_id',
        'service_id',
        'client_name',
        'client_phone',
        'client_phone_normalized',
        'history_whatsapp_phone',
        'service_price',
        'payment_method',
        'turn_date',
        'turn_number',
        'status',
        'sale_id',
        'notes',
        'called_at',
        'finished_at',
        'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'barber_id' => 'integer',
            'service_id' => 'integer',
            'guest_visitor_id' => 'integer',
            'service_price' => 'decimal:2',
            'turn_number' => 'integer',
            'turn_date' => 'date',
            'called_at' => 'datetime',
            'started_at' => 'datetime',
            'whatsapp_consent' => 'boolean',
            'attendance_confirmed_at' => 'datetime',
            'finished_at' => 'datetime',
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

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('turn_date', now()->toDateString());
    }
}
