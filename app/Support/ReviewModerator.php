<?php

namespace App\Support;

/**
 * Moderación objetiva de opiniones: detecta spam, datos privados y amenazas o insultos graves.
 * NO mira la calificación: una opinión negativa pasa igual que una positiva.
 * Si algo se detecta, la opinión no se publica sola y queda "requiere revisión" en el panel.
 */
class ReviewModerator
{
    private const OFFENSIVE = [
        'hijueputa', 'hijo de puta', 'gonorrea', 'malparido', 'malparida', 'triplehijueputa', 'carechimba',
        'te voy a matar', 'los voy a matar', 'lo voy a matar', 'quemar el local', 'les voy a dar', 'amenaza',
    ];

    /** Devuelve el motivo (para el panel) o null si se puede publicar. */
    public static function check(string $text): ?string
    {
        $plain = mb_strtolower(\Illuminate\Support\Str::ascii($text));

        if (preg_match('#(https?://|www\.|\b[a-z0-9-]+\.(com|co|net|org|info|xyz|ly)\b)#i', $text)) {
            return 'Contiene un enlace (posible spam).';
        }
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text)) {
            return 'Contiene un correo electrónico (dato privado).';
        }
        if (preg_match('/(\d[\s.-]?){7,}/', $text)) {
            return 'Contiene un número de teléfono o documento (dato privado).';
        }
        foreach (self::OFFENSIVE as $word) {
            if (str_contains($plain, $word)) {
                return 'Contiene insultos graves o amenazas.';
            }
        }
        if (preg_match('/(.)\1{9,}/u', $text) || (mb_strlen($text) > 20 && $text === mb_strtoupper($text) && preg_match('/\p{L}{10,}/u', $text))) {
            return 'Texto repetido o todo en mayúsculas (posible spam).';
        }

        return null;
    }
}
