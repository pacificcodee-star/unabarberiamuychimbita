<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PushSubscription extends Model
{
    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
        'content_encoding',
        'user_agent',
        'last_success_at',
    ];

    // Las claves del dispositivo nunca salen en respuestas JSON.
    protected $hidden = ['endpoint', 'p256dh', 'auth'];

    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
