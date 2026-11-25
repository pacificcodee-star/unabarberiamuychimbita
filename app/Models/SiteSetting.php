<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = ['data'];

    /** Datos del negocio que el admin puede cambiar en "Configuración del negocio". El resto de textos vive en config/sitio.php. */
    public const BUSINESS_KEYS = [
        'address', 'city', 'phone', 'phone_link', 'email', 'whatsapp',
        'hours_mon', 'hours_tue', 'hours_wed', 'hours_thu', 'hours_fri', 'hours_sat', 'hours_sun', 'booking_start', 'booking_end',
        'map_url', 'map_coords', 'instagram_url', 'facebook_url', 'tiktok_url',
        'payment_cash', 'payment_nequi', 'payment_daviplata', 'payment_transfer', 'payment_breb',
        'account_nequi', 'account_daviplata', 'account_transfer', 'account_breb',
    ];

    /** Días de la semana: clave => [nombre, abreviatura, número ISO (1 = lunes)]. */
    public const DAYS = [
        'mon' => ['Lunes', 'Lun', 1], 'tue' => ['Martes', 'Mar', 2], 'wed' => ['Miércoles', 'Mié', 3],
        'thu' => ['Jueves', 'Jue', 4], 'fri' => ['Viernes', 'Vie', 5], 'sat' => ['Sábado', 'Sáb', 6], 'sun' => ['Domingo', 'Dom', 7],
    ];

    public const PAYMENT_METHODS = [
        'cash' => 'Efectivo', 'transfer' => 'Transferencia', 'nequi' => 'Nequi', 'daviplata' => 'Daviplata', 'breb' => 'Bre-b',
    ];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /**
     * Textos e información de la página: viven en el código (config/sitio.php).
     * Los datos del negocio (BUSINESS_KEYS) y el logo se pueden cambiar en el panel: "Configuración del negocio".
     */
    public static function defaults(): array
    {
        return array_merge(config('sitio', []), [
            'logo_path' => '',
            'about_image_path' => '',
            'loader_image_path' => '',
            'hero_image_path' => '',
            'spiral_image_path' => '',
            'contact_image_path' => '',
            'loader_video_path' => '',
        ]);
    }

    /** Se lee una sola vez por petición; al guardar cambios se descarta la copia. */
    protected static function booted(): void
    {
        static::saved(fn () => app()->forgetInstance('site-settings.data'));
        static::deleted(fn () => app()->forgetInstance('site-settings.data'));
    }

    public static function currentData(): array
    {
        if (app()->bound('site-settings.data')) {
            return app('site-settings.data');
        }
        $data = static::computeCurrentData();
        app()->instance('site-settings.data', $data);

        return $data;
    }

    private static function computeCurrentData(): array
    {
        $saved = static::query()->first()?->data ?? [];
        // Del panel solo se toman los datos del negocio y las imágenes; los textos salen siempre del código.
        $fromPanel = array_filter($saved, fn ($value, $key) => in_array($key, self::BUSINESS_KEYS, true) || str_ends_with((string) $key, '_path'), ARRAY_FILTER_USE_BOTH);

        // Compatibilidad: si antes se guardó el horario por grupos (lun-vie / sáb / dom), se reparte por día.
        foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $day) {
            if (! isset($fromPanel['hours_'.$day]) && ! empty($saved['weekday_hours'])) {
                $fromPanel['hours_'.$day] = $saved['weekday_hours'];
            }
        }
        if (! isset($fromPanel['hours_sat']) && ! empty($saved['saturday_hours'])) {
            $fromPanel['hours_sat'] = $saved['saturday_hours'];
        }
        if (! isset($fromPanel['hours_sun']) && ! empty($saved['sunday_hours'])) {
            $fromPanel['hours_sun'] = $saved['sunday_hours'];
        }

        return array_merge(static::defaults(), $fromPanel);
    }

    /** ¿El texto del horario indica que ese día no se abre? (vacío o contiene "cerrad"). */
    public static function isClosedText(?string $value): bool
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' || str_contains($value, 'cerrad');
    }

    /**
     * Horario de un día para el formulario del panel: ['closed' => bool, 'open' => 'HH:MM', 'close' => 'HH:MM'].
     * Toma la primera y la última hora escritas en el texto ("9:00 a. m. - 8:00 p. m.").
     */
    public static function dayRange(?string $text): array
    {
        if (self::isClosedText($text)) {
            return ['closed' => true, 'open' => '', 'close' => ''];
        }
        $times = \App\Support\TurnSchedule::parseTimes((string) $text);

        return [
            'closed' => false,
            'open' => $times[0] ?? '',
            'close' => count($times) > 1 ? $times[count($times) - 1] : '',
        ];
    }

    /** Texto del horario de un día a partir de las horas del panel: "9:00 a. m. - 8:00 p. m.". */
    public static function rangeText(string $open, string $close): string
    {
        $label = function (string $time): string {
            [$h, $m] = array_map('intval', explode(':', $time));

            return (($h % 12) ?: 12).':'.sprintf('%02d', $m).' '.($h >= 12 ? 'p. m.' : 'a. m.');
        };

        return $label($open).' - '.$label($close);
    }

    /** Horario de los 7 días: [['key','label','short','iso','value','closed','today'], ...]. */
    public static function weekHours(array $settings): array
    {
        $today = now()->dayOfWeekIso;

        return collect(self::DAYS)->map(fn ($day, $key) => [
            'key' => $key, 'label' => $day[0], 'short' => $day[1], 'iso' => $day[2],
            'value' => trim((string) ($settings['hours_'.$key] ?? '')) ?: 'Cerrado',
            'closed' => self::isClosedText($settings['hours_'.$key] ?? ''),
            'today' => $day[2] === $today,
        ])->values()->all();
    }

    /** Agrupa días seguidos con el mismo horario: "Lun – Vie: 9 a 8", "Sáb: …", "Dom: Cerrado". */
    public static function groupedHours(array $settings): array
    {
        $groups = [];
        foreach (self::weekHours($settings) as $day) {
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['value'] === $day['value']) {
                $groups[$last]['to'] = $day['short'];
            } else {
                $groups[] = ['from' => $day['short'], 'to' => null, 'value' => $day['value']];
            }
        }

        return array_map(fn ($g) => ['label' => $g['to'] ? $g['from'].' – '.$g['to'] : $g['from'], 'value' => $g['value']], $groups);
    }

    /** Horario de hoy (texto). */
    public static function todayHours(array $settings): string
    {
        $key = array_search(now()->dayOfWeekIso, array_map(fn ($d) => $d[2], self::DAYS), true);

        return trim((string) ($settings['hours_'.$key] ?? '')) ?: 'Cerrado';
    }

    /** ¿Ese día (fecha) la barbería está cerrada? */
    public static function isClosedOn(\DateTimeInterface $date, array $settings): bool
    {
        $key = array_search((int) $date->format('N'), array_map(fn ($d) => $d[2], self::DAYS), true);

        return self::isClosedText($settings['hours_'.$key] ?? '');
    }
}
