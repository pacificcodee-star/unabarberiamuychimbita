<?php

namespace App\Support;

/** Identificador de correlación de la petición actual (lo fija el middleware AssignRequestId). */
class RequestId
{
    private static ?string $current = null;

    public static function set(?string $id): void
    {
        self::$current = $id;
    }

    public static function current(): ?string
    {
        return self::$current;
    }
}
