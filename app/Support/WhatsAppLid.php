<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * WhatsApp está cambiando el remitente de los mensajes de "57300…@s.whatsapp.net" a un identificador
 * opaco "1234…@lid". Evolution API suele mandar también el número (remoteJidAlt o senderPn), pero no
 * siempre. Aquí se recuerda qué número corresponde a cada @lid cada vez que aparecen juntos (al enviar
 * un mensaje o al recibir uno que trae ambos), para reconocer al cliente cuando llega solo el @lid.
 */
final class WhatsAppLid
{
    private const DAYS = 180;

    public static function isLid(mixed $jid): bool
    {
        return is_string($jid) && preg_match('/\A[0-9]+@lid\z/', $jid) === 1;
    }

    public static function remember(?string $lidJid, ?string $phone): void
    {
        if (! self::isLid($lidJid) || preg_match('/\A[0-9]{8,20}\z/', (string) $phone) !== 1) {
            return;
        }

        Cache::put(self::key((string) $lidJid), $phone, now()->addDays(self::DAYS));
    }

    public static function phoneFor(?string $lidJid): ?string
    {
        if (! self::isLid($lidJid)) {
            return null;
        }

        $phone = Cache::get(self::key((string) $lidJid));

        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    private static function key(string $lidJid): string
    {
        return 'wa.lid.'.strtolower(explode('@', $lidJid)[0]);
    }
}
