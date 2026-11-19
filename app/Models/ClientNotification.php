<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ClientNotification extends Model
{
    public const PENDING = 'pendiente';
    public const SENDING = 'enviando';
    public const SENT = 'enviada';
    public const FAILED = 'fallida';
    public const CANCELLED = 'cancelada';
    public const EXPIRED = 'expirada';

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'type',
        'channel',
        'title',
        'body',
        'url',
        'dedupe_key',
        'scheduled_for',
        'expires_at',
        'status',
        'attempts',
        'sent_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
