<?php

namespace App\Models\Concerns;

use App\Models\ClientNotification;
use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * Para turnos y citas: cada registro tiene un enlace secreto (public_token) con el que el cliente,
 * sin cuenta ni contraseña, consulta su estado, activa los avisos push o cancela.
 */
trait HasClientNotifications
{
    public static function bootHasClientNotifications(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_token)) {
                $model->public_token = Str::random(40);
            }
        });

        // Al borrar el turno o la cita se borran sus dispositivos y sus avisos.
        static::deleting(function ($model) {
            $model->pushSubscriptions()->delete();
            $model->clientNotifications()->delete();
        });
    }

    public function pushSubscriptions(): MorphMany
    {
        return $this->morphMany(PushSubscription::class, 'notifiable');
    }

    public function clientNotifications(): MorphMany
    {
        return $this->morphMany(ClientNotification::class, 'notifiable');
    }

    /** Clave corta usada en las claves anti-duplicados: "turn:12" o "appointment:5". */
    public function notificationKey(): string
    {
        return $this->getMorphClass().':'.$this->getKey();
    }
}
