<?php

namespace Database\Seeders;

use App\Models\FeaturedCut;
use App\Models\Service;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Descarga fotos gratuitas de Unsplash (licencia Unsplash: uso libre, también comercial)
 * y las deja cargadas como si las hubiera subido el admin:
 *  - 4 cortes en "Cortes destacados" (aparecen en la espiral del inicio).
 *  - 1 imagen para la pantalla de carga (Contenido del sitio → Imágenes principales).
 * Todo se puede cambiar o borrar luego desde el panel.
 *
 *   php artisan db:seed --class=ImagenesInternetSeeder
 */
class ImagenesInternetSeeder extends Seeder
{
    private const URL = 'https://unsplash.com/photos/%s/download?force=true&w=1400';

    public function run(): void
    {
        $cuts = [
            ['id' => 'ydd_DCROCTA', 'title' => 'Corte clásico', 'service' => 'Corte clásico', 'credit' => 'Hair Spies'],
            ['id' => 'PDDvMlScofU', 'title' => 'Corte + barba', 'service' => 'Corte + barba', 'credit' => 'Redd Francisco'],
            ['id' => 'Yb6tFZfh28Y', 'title' => 'Fade con estilo', 'service' => 'Corte clásico', 'credit' => 'Thom Holmes'],
            ['id' => 'I2qtMgu8IJA', 'title' => 'Arreglo de barba', 'service' => 'Arreglo de barba', 'credit' => 'Unsplash'],
        ];

        if (Schema::hasTable('featured_cuts')) {
            $order = (int) FeaturedCut::max('sort_order');
            foreach ($cuts as $cut) {
                $path = 'featured-cuts/internet-'.strtolower(str_replace('_', '-', $cut['id'])).'.jpg';
                if (! $this->download($cut['id'], $path)) {
                    continue;
                }
                $service = Service::where('name', $cut['service'])->first();
                FeaturedCut::updateOrCreate(['image_path' => $path], [
                    'title' => $cut['title'],
                    'subtitle' => $service ? format_money($service->price).' · '.$service->duration_minutes.' min' : null,
                    'description' => $service?->description,
                    'service_id' => $service?->id,
                    'sort_order' => ++$order,
                    'active' => true,
                ]);
                $this->command?->info("Corte «{$cut['title']}» listo (foto de {$cut['credit']} en Unsplash).");
            }
        }

        $loaderPath = 'site/carga-barberia.jpg';
        if ($this->download('k6RsU8om2UE', $loaderPath)) {
            $record = SiteSetting::query()->firstOrCreate([], ['data' => SiteSetting::defaults()]);
            $data = array_merge(SiteSetting::defaults(), $record->data ?? []);
            $data['loader_image_path'] = $loaderPath;
            $record->data = $data;
            $record->save();
            $this->command?->info('Imagen de la pantalla de carga lista (foto de František Čaník en Unsplash).');
        }
    }

    private function download(string $id, string $path): bool
    {
        if (Storage::disk('public')->exists($path)) {
            return true;
        }

        $url = sprintf(self::URL, $id);
        try {
            $response = Http::timeout(90)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($url);
        } catch (\Throwable $e) {
            // XAMPP en Windows a veces no trae certificados raíz: se reintenta sin verificar (solo para bajar fotos públicas).
            try {
                $response = Http::timeout(90)->withoutVerifying()->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($url);
            } catch (\Throwable $e2) {
                $this->command?->warn("No se pudo descargar {$id}: ".$e2->getMessage());
                return false;
            }
        }

        if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            $this->command?->warn("No se pudo descargar {$id} (HTTP {$response->status()}).");
            return false;
        }

        Storage::disk('public')->put($path, $response->body());
        return true;
    }
}
