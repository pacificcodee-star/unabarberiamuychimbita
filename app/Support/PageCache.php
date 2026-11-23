<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Caché de páginas públicas en archivos HTML (public/page-cache).
 *
 * Las páginas sin formularios ni datos personales (inicio, servicios, barberos, nosotros, contacto y
 * legales) se guardan ya armadas. Un visitante anónimo las recibe sin que Laravel consulte la base de
 * datos ni escriba su sesión; en el VPS, Caddy las entrega directamente sin pasar por PHP
 * (deploy/caddy/Caddyfile). Se borran solas al cambiar algo en el panel (AppServiceProvider), al
 * publicar una versión nueva (docker-entrypoint) y al cumplir su tiempo de vida (purga cada 30 s).
 *
 * Quien tiene reservas en este dispositivo (cookie mis_servicios) o es del equipo (cookie ubmc_staff)
 * ve la página de siempre, armada para él.
 */
final class PageCache
{
    /** Ruta con nombre → archivo. Solo estas páginas se guardan (nunca una ruta inventada). */
    public const PAGES = [
        'landing' => 'index',
        'public.services' => 'servicios',
        'public.barbers' => 'barberos',
        'public.about' => 'nosotros',
        'public.contact' => 'contacto',
        'legal.notice' => 'aviso-legal',
        'legal.privacy' => 'politica-de-privacidad',
        'legal.cookies' => 'cookies',
    ];

    /** Con cualquiera de estas cookies la página es personal: no se sirve ni se guarda la copia. */
    public const BYPASS_COOKIES = ['mis_servicios', 'ubmc_staff'];

    /** Parámetros de campañas que no cambian la página (cualquier otro parámetro evita la caché). */
    private const IGNORED_QUERY = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'];

    public static function enabled(): bool
    {
        return (bool) config('web.page_cache.enabled', true);
    }

    public static function ttl(): int
    {
        return max(5, (int) config('web.page_cache.ttl', 60));
    }

    public static function directory(): string
    {
        return public_path('page-cache');
    }

    /** Archivo de la página de esta petición, o null si la petición no se puede atender desde la caché. */
    public static function fileFor(Request $request): ?string
    {
        if (! self::enabled() || ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $name = $request->route()?->getName();
        if (! isset(self::PAGES[$name])) {
            return null;
        }

        foreach (self::BYPASS_COOKIES as $cookie) {
            if ($request->cookies->has($cookie)) {
                return null;
            }
        }

        if (array_diff(array_keys($request->query()), self::IGNORED_QUERY) !== []) {
            return null;
        }

        return self::directory().DIRECTORY_SEPARATOR.self::PAGES[$name].'.html';
    }

    /**
     * Deja la página lista para cualquier visitante: sin el token CSRF de quien la pidió
     * (las páginas lo piden con window.ubmcCsrf() cuando lo necesitan).
     */
    public static function sanitize(string $html): string
    {
        return (string) preg_replace('/(<meta name="csrf-token" content=")[^"]*(")/', '$1$2', $html, 1);
    }

    public static function store(string $file, string $html): void
    {
        $dir = dirname($file);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }

        // Escritura atómica: Caddy o PHP nunca leen un archivo a medio escribir.
        $tmp = $file.'.'.getmypid().'.tmp';
        if (@file_put_contents($tmp, $html) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    /** Copia vigente (dentro del tiempo de vida) o null. */
    public static function fresh(string $file): ?string
    {
        clearstatcache(true, $file);
        $modified = @filemtime($file);
        if ($modified === false || time() - $modified >= self::ttl()) {
            return null;
        }

        $html = @file_get_contents($file);

        return $html === false || $html === '' ? null : $html;
    }

    /** Borra todas las páginas guardadas (algo cambió en el panel o hay versión nueva). */
    public static function flush(): void
    {
        foreach (glob(self::directory().DIRECTORY_SEPARATOR.'*.html') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** Borra las páginas que cumplieron su tiempo de vida (la próxima visita las vuelve a armar). */
    public static function purgeExpired(): int
    {
        $deleted = 0;
        foreach (glob(self::directory().DIRECTORY_SEPARATOR.'*.html') ?: [] as $file) {
            $modified = @filemtime($file);
            if ($modified !== false && time() - $modified >= self::ttl() && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
