<?php

namespace App\Support;

use App\Models\SiteSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Lectura del horario de la jornada y mensajes de la recepción de turnos.
 *
 * La apertura y el cierre se leen del horario del día configurado en el panel ("9:00 a. m. - 8:00 p. m.").
 * Si ese texto no se puede interpretar, se usan las horas de reserva antiguas (booking_start / booking_end).
 * Nunca están escritas en el código: si el admin cambia el horario, el límite cambia con él.
 *
 * Los turnos se pueden pedir dentro del horario del día mientras el servicio alcance a terminar
 * antes del cierre: el último momento es el cierre menos $leadMinutes (margen de llegada + duración).
 * La regla completa, con la fila real de cada barbero, vive en App\Services\BusinessHours.
 */
final class TurnSchedule
{

    /** Clave del día de la semana en SiteSetting::DAYS (mon, tue, ...). */
    public static function dayKey(CarbonInterface $day): string
    {
        $keys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        return $keys[(int) $day->format('N') - 1];
    }

    /** Hora de apertura del día (HH:MM) según el horario configurado. */
    public static function openingTime(CarbonInterface $day, array $settings): ?string
    {
        $times = self::parseTimes((string) ($settings['hours_'.self::dayKey($day)] ?? ''));

        return $times[0] ?? ($settings['booking_start'] ?? null);
    }

    /** Hora de cierre del día (HH:MM). Null si ese día la barbería está cerrada. */
    public static function closingTime(CarbonInterface $day, array $settings): ?string
    {
        $text = (string) ($settings['hours_'.self::dayKey($day)] ?? '');
        if (SiteSetting::isClosedText($text)) {
            return null;
        }

        $times = self::parseTimes($text);
        $opening = $times[0] ?? ($settings['booking_start'] ?? null);
        $closing = count($times) > 1 ? $times[count($times) - 1] : ($settings['booking_end'] ?? null);

        // Si el texto quedó en un formato ambiguo (p. ej. "9:00 a 8:00"), se usa el horario de reservas.
        if ($closing === null || $opening === null || $closing <= $opening) {
            $closing = $settings['booking_end'] ?? null;
        }

        return $closing;
    }

    public static function closingAt(CarbonInterface $day, array $settings): ?Carbon
    {
        $time = self::closingTime($day, $settings);

        return $time === null ? null : self::at($day, $time);
    }

    public static function deadlineAt(CarbonInterface $day, array $settings, int $leadMinutes = 0): ?Carbon
    {
        return self::closingAt($day, $settings)?->subMinutes(max(0, $leadMinutes));
    }

    /**
     * Estado de las solicitudes de turno en el momento indicado (hoy por defecto).
     *
     * @return array{open:bool, reason:string, opening:?Carbon, closing:?Carbon, deadline:?Carbon, message:string, cutoff_message:string}
     */
    public static function forNow(?CarbonInterface $now = null, ?array $settings = null, int $leadMinutes = 0): array
    {
        $settings ??= SiteSetting::freshData();
        $leadMinutes = max(0, $leadMinutes);
        $now = $now === null ? now() : Carbon::instance($now);
        $closing = self::closingAt($now, $settings);

        $next = self::nextOpeningText($now, $settings);

        $nextSentence = $next ? self::sentence("Podrá solicitar su turno {$next}") : null;

        if ($closing === null) {
            $message = self::join([
                'Le informamos que hoy la barbería no presta servicio, por lo que no es posible solicitar turnos.',
                $nextSentence,
                'Si lo prefiere, puede agendar una cita para otro día.',
            ]);

            return self::state(false, 'closed', null, null, null, $message);
        }

        $deadline = $closing->copy()->subMinutes($leadMinutes);
        $openingTime = self::openingTime($now, $settings);
        $opening = $openingTime === null ? $closing->copy()->startOfDay() : self::at($now, $openingTime);

        $closingLabel = self::timeLabel($closing);
        $deadlineLabel = self::timeLabel($deadline);
        $cutoffMessage = self::join([
            self::sentence("Le informamos que la recepción de turnos para hoy ha finalizado. Los turnos se reciben hasta las {$deadlineLabel}, para que el servicio alcance a terminar antes del cierre a las {$closingLabel}"),
            $nextSentence,
            'Si lo prefiere, puede agendar una cita para otro día.',
        ]);

        if ($now->lt($opening)) {
            $openingLabel = self::timeLabel($opening);

            return self::state(
                false,
                'before_open',
                $opening,
                $closing,
                $deadline,
                "Aún no hemos abierto. Podrá solicitar su turno hoy a partir de las {$openingLabel} y hasta las {$deadlineLabel} ".
                    "(horario de atención: {$openingLabel} a {$closingLabel}).",
                $cutoffMessage,
            );
        }

        if ($now->gt($deadline)) {
            return self::state(false, 'cutoff', $opening, $closing, $deadline, $cutoffMessage, $cutoffMessage);
        }

        return self::state(
            true,
            'open',
            $opening,
            $closing,
            $deadline,
            "Hoy puede solicitar su turno hasta las {$deadlineLabel}: el servicio debe terminar a más tardar al cierre, a las {$closingLabel}.",
            $cutoffMessage,
        );
    }

    /**
     * Cuándo se vuelve a poder pedir turno después de hoy: "mañana a partir de las 9:00 a. m." o
     * "el lunes a partir de las 9:00 a. m.". Null si en la próxima semana no hay ningún día abierto.
     */
    public static function nextOpeningText(CarbonInterface $now, array $settings): ?string
    {
        $names = ['mon' => 'lunes', 'tue' => 'martes', 'wed' => 'miércoles', 'thu' => 'jueves', 'fri' => 'viernes', 'sat' => 'sábado', 'sun' => 'domingo'];

        for ($offset = 1; $offset <= 7; $offset++) {
            $day = Carbon::instance($now)->startOfDay()->addDays($offset);
            if (self::closingTime($day, $settings) === null) {
                continue;
            }

            $opening = self::openingTime($day, $settings);
            $when = $offset === 1 ? 'mañana' : 'el '.$names[self::dayKey($day)];

            return $opening === null ? $when : $when.' a partir de las '.self::timeLabel(self::at($day, $opening));
        }

        return null;
    }

    /** Cierra la frase con punto, sin duplicarlo cuando ya termina en "a. m." o "p. m.". */
    private static function sentence(string $text): string
    {
        return str_ends_with($text, '.') ? $text : $text.'.';
    }

    /** @param list<?string> $sentences */
    private static function join(array $sentences): string
    {
        return implode(' ', array_filter($sentences));
    }

    public static function timeLabel(CarbonInterface $moment): string
    {
        return $moment->format('g:i').' '.((int) $moment->format('G') >= 12 ? 'p. m.' : 'a. m.');
    }

    /** @return array{open:bool, reason:string, opening:?Carbon, closing:?Carbon, deadline:?Carbon, message:string, cutoff_message:string} */
    private static function state(
        bool $open,
        string $reason,
        ?Carbon $opening,
        ?Carbon $closing,
        ?Carbon $deadline,
        string $message,
        ?string $cutoffMessage = null,
    ): array {
        return [
            'open' => $open,
            'reason' => $reason,
            'opening' => $opening,
            'closing' => $closing,
            'deadline' => $deadline,
            'message' => $message,
            'cutoff_message' => $cutoffMessage ?? $message,
        ];
    }

    private static function at(CarbonInterface $day, string $time): Carbon
    {
        return Carbon::parse($day->format('Y-m-d').' '.$time);
    }

    /**
     * Horas (HH:MM) en el orden en que aparecen en el texto del horario.
     *
     * @return list<string>
     */
    public static function parseTimes(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $pattern = '/(\d{1,2})(?::(\d{2}))?\s*(a\.?\s*m\.?|p\.?\s*m\.?)|(\d{1,2}):(\d{2})(?!\d)/iu';
        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $times = [];
        foreach ($matches as $match) {
            if (! empty($match[3])) {
                $meridiem = mb_strtolower(str_replace([' ', '.'], '', $match[3]));
                $hour = (int) $match[1] % 12;
                if (str_starts_with($meridiem, 'p')) {
                    $hour += 12;
                }
                $times[] = sprintf('%02d:%02d', $hour, (int) ($match[2] ?: 0));
            } elseif (isset($match[4]) && $match[4] !== '') {
                $times[] = sprintf('%02d:%02d', (int) $match[4], (int) $match[5]);
            }
        }

        return array_values(array_filter($times, fn (string $time) => $time >= '00:00' && $time <= '23:59'));
    }
}
