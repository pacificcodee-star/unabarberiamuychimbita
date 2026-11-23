<?php

namespace App\Support;

use App\Models\SiteSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Horario de la jornada para los turnos por orden de llegada.
 *
 * La hora de cierre se lee del horario del día configurado en el panel ("9:00 a. m. - 8:00 p. m.").
 * Si ese texto no se puede interpretar, se usa la hora final de reservas (booking_end). Nunca
 * está escrita en el código: si el admin cambia el horario, el límite cambia con él.
 *
 * Los turnos solo se pueden pedir dentro del horario del día y hasta 30 minutos antes del cierre.
 */
final class TurnSchedule
{
    public const REQUEST_CUTOFF_MINUTES = 30;

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

    public static function deadlineAt(CarbonInterface $day, array $settings): ?Carbon
    {
        return self::closingAt($day, $settings)?->subMinutes(self::REQUEST_CUTOFF_MINUTES);
    }

    /**
     * Estado de las solicitudes de turno en el momento indicado (hoy por defecto).
     *
     * @return array{open:bool, reason:string, opening:?Carbon, closing:?Carbon, deadline:?Carbon, message:string, cutoff_message:string}
     */
    public static function forNow(?CarbonInterface $now = null, ?array $settings = null): array
    {
        $settings ??= SiteSetting::currentData();
        $now = $now === null ? now() : Carbon::instance($now);
        $closing = self::closingAt($now, $settings);

        if ($closing === null) {
            $message = 'Hoy la barbería está cerrada, así que no se pueden tomar turnos. Puedes agendar una cita para otro día.';

            return self::state(false, 'closed', null, null, null, $message);
        }

        $deadline = $closing->copy()->subMinutes(self::REQUEST_CUTOFF_MINUTES);
        $openingTime = self::openingTime($now, $settings);
        $opening = $openingTime === null ? $closing->copy()->startOfDay() : self::at($now, $openingTime);

        $closingLabel = self::timeLabel($closing);
        $deadlineLabel = self::timeLabel($deadline);
        $cutoffMessage = "Ya no se pueden tomar turnos hoy: las solicitudes cierran a las {$deadlineLabel}, ".
            "30 minutos antes del cierre de las {$closingLabel}. Puedes agendar una cita para otro día.";

        if ($now->lt($opening)) {
            return self::state(
                false,
                'before_open',
                $opening,
                $closing,
                $deadline,
                'Aún no abrimos. Hoy atendemos de '.self::timeLabel($opening)." a {$closingLabel} y puedes pedir turno hasta las {$deadlineLabel}.",
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
            "Hoy puedes tomar turno hasta las {$deadlineLabel} (30 minutos antes del cierre).",
            $cutoffMessage,
        );
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
    private static function parseTimes(string $text): array
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
