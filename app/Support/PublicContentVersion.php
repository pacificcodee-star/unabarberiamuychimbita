<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Huella del contenido público (servicios, barberos, ajustes, cortes, reseñas y portada)
 * y del código que lo dibuja. La página la compara cada pocos segundos para refrescarse sola
 * tras un cambio en el panel o después de publicar una versión nueva del sitio.
 */
final class PublicContentVersion
{
    private const TABLES = ['services', 'users', 'site_settings', 'featured_cuts', 'reviews', 'home_slides'];

    private const CACHE_KEY = 'public-content:version';

    /**
     * Antes se calculaba en cada página y en cada consulta de /contenido/version (6 consultas COUNT/MAX
     * por petición). Ahora se guarda 5 minutos y se borra al guardar cualquiera de esos modelos
     * (AppServiceProvider → changed()); los 5 minutos solo cubren cambios hechos por fuera de Laravel.
     */
    public static function current(): string
    {
        if (app()->bound('public-content.version')) {
            return app('public-content.version');
        }

        $version = Cache::remember(self::CACHE_KEY, now()->addMinutes(5), fn () => self::compute());
        app()->instance('public-content.version', $version);

        return $version;
    }

    /** Algo público cambió: nueva huella y fuera las páginas guardadas. */
    public static function changed(): void
    {
        Cache::forget(self::CACHE_KEY);
        app()->forgetInstance('public-content.version');
        PageCache::flush();
    }

    /** Al publicar una versión nueva (docker-entrypoint). */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('public-content:deploy-hash');
        app()->forgetInstance('public-content.version');
    }

    private static function compute(): string
    {
        $parts = [];
        foreach (self::TABLES as $table) {
            $row = DB::table($table)->selectRaw('COUNT(*) as total, MAX(updated_at) as latest')->first();
            $parts[] = $table.':'.(int) ($row->total ?? 0).'|'.(string) ($row->latest ?? '');
        }

        $parts[] = 'deploy:'.self::deployHash();

        return substr(sha1(implode(';', $parts)), 0, 12);
    }

    /** Huella de las plantillas, estilos y controladores: cambia al publicar una versión nueva. */
    private static function deployHash(): string
    {
        return Cache::remember('public-content:deploy-hash', now()->addMinute(), function () {
            $dirs = [
                resource_path('views'),
                base_path('public/css'),
                base_path('public/js'),
                app_path('Http/Controllers'),
                app_path('Support'),
            ];

            $files = [];
            foreach ($dirs as $dir) {
                if (! is_dir($dir)) {
                    continue;
                }
                $walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($walker as $file) {
                    if ($file->isFile()) {
                        $files[$file->getPathname()] = $file->getMTime();
                    }
                }
            }
            ksort($files);

            return sha1(json_encode($files));
        });
    }
}
