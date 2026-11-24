<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Marca "el cliente confirmó su asistencia" para una cita o un turno.
 *
 * Las confirmaciones se guardan con la reserva. Se siguen leyendo las entradas anteriores de caché.
 */
class AttendanceConfirmation
{
    public static function mark(Model $subject): void
    {
        if ($subject->attendance_confirmed_at === null) {
            $subject->forceFill(['attendance_confirmed_at' => now()])->saveQuietly();
        }
    }

    public static function confirmed(Model $subject): bool
    {
        return $subject->attendance_confirmed_at !== null || Cache::has(self::key($subject));
    }

    public static function forget(Model $subject): void
    {
        Cache::forget(self::key($subject));
        if ($subject->attendance_confirmed_at !== null) {
            $subject->forceFill(['attendance_confirmed_at' => null])->saveQuietly();
        }
    }

    private static function key(Model $subject): string
    {
        return 'asistencia_confirmada:'.$subject->getMorphClass().':'.$subject->getKey();
    }
}
