<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Huella del contenido público (servicios, barberos, ajustes, cortes, reseñas y portada).
 * La página la compara cada pocos segundos para enterarse de los cambios hechos en el panel.
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

        $version = substr(sha1(implode(';', $parts)), 0, 12);
        app()->instance('public-content.version', $version);

        return $version;
    }
}
