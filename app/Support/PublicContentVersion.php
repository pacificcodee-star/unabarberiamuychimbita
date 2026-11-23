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

    public static function current(): string
    {
        if (app()->bound('public-content.version')) {
            return app('public-content.version');
        }

        $parts = [];
        foreach (self::TABLES as $table) {
            $row = DB::table($table)->selectRaw('COUNT(*) as total, MAX(updated_at) as latest')->first();
            $parts[] = $table.':'.(int) ($row->total ?? 0).'|'.(string) ($row->latest ?? '');
        }

        $parts[] = 'deploy:'.self::deployHash();

        $version = substr(sha1(implode(';', $parts)), 0, 12);
        app()->instance('public-content.version', $version);

        return $version;
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
