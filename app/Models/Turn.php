<?php

namespace App\Models;

use App\Models\Concerns\HasClientNotifications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $fillable = [
        'public_token',
        'client_email',
        'whatsapp_consent',
        'started_at',
        'barber_id',
        'service_id',
        'client_name',
        'client_phone',
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
            'turn_date' => 'date',
            'called_at' => 'datetime',
            'started_at' => 'datetime',
            'whatsapp_consent' => 'boolean',
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

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('turn_date', now()->toDateString());
    }
}
