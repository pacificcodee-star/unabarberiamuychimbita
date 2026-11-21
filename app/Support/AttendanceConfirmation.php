<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Marca "el cliente confirmó su asistencia" para una cita o un turno.
 *
 * Se guarda en la caché (tabla cache, persistente) para no tocar el esquema de la base de datos.
 * Dura 3 días: de sobra para una cita de hoy o de los próximos días tras su recordatorio.
 */
class AttendanceConfirmation
{
    public static function mark(Model $subject): void
    {
        Cache::put(self::key($subject), now()->toIso8601String(), now()->addDays(3));
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
