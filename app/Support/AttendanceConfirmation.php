<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Marca "el cliente confirmó su asistencia" para una cita o un turno.
 *
 * Se guarda en la caché (tabla cache, persistente) para no tocar el esquema de la base de datos.
 * Para citas futuras se conserva hasta tres días después del servicio.
 */
class AttendanceConfirmation
{
    public static function mark(Model $subject): void
    {
        $until = $subject instanceof \App\Models\Appointment ? $subject->startsAt()->addDays(3) : now()->addDays(3);
        Cache::put(self::key($subject), now()->toIso8601String(), $until);
    }

    public static function confirmed(Model $subject): bool
    {
        return Cache::has(self::key($subject));
    }

    public static function forget(Model $subject): void
    {
        Cache::forget(self::key($subject));
    }

    private static function key(Model $subject): string
    {
        return 'asistencia_confirmada:'.$subject->getMorphClass().':'.$subject->getKey();
    }
}
