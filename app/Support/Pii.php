<?php

namespace App\Support;

/** Enmascara datos personales antes de escribirlos en los registros. */
class Pii
{
    /** "573001234567" → "***4567": basta para soporte y no identifica a la persona. */
    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return $digits === '' ? null : '***'.substr($digits, -4);
    }
}
