<?php

namespace App\Models;

use App\Models\Concerns\HasClientNotifications;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasClientNotifications;

    public const STATUSES = [
        'pendiente' => 'Pendiente',
        'confirmada' => 'Confirmada',
        'atendida' => 'Atendida',
        'cancelada' => 'Cancelada',
    ];

    protected $fillable = [
        'public_token',
        'client_email',
        'whatsapp_consent',
        'barber_id',
        'service_id',
        'client_name',
        'client_phone',
        'payment_method',
        'appointment_date',
        'appointment_time',
        'status',
        'sale_id',
        'notes',
        'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
            'whatsapp_consent' => 'boolean',
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

    /** Fecha y hora exactas en que empieza la cita. */
    public function startsAt(): Carbon
    {
        return Carbon::parse($this->appointment_date->toDateString().' '.substr((string) $this->appointment_time, 0, 5));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
