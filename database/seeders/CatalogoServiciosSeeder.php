<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Catálogo oficial de servicios de Una Barbería Muy Chimbita.
 *
 * Reglas para no romper el historial:
 *  - Reutiliza el ID del servicio anterior cuando el paquete corresponde al mismo tipo de trabajo.
 *  - Desactiva (nunca borra) los servicios que salen del catálogo: sus ventas, citas y turnos quedan intactos.
 *  - Copia las imágenes de "imagenes de servicios" a storage/app/public/services y las asigna a cada servicio.
 *
 *   php artisan db:seed --class=CatalogoServiciosSeeder
 */
class CatalogoServiciosSeeder extends Seeder
{
    /** Servicios que se conservan por ID porque su historial corresponde al mismo tipo de trabajo. */
    private const REUSE_IDS = [
        'Servicio básico' => 1,          // antes: Corte clásico
        'Combo completo' => 2,           // antes: Corte + barba
        'Mechas platinadas' => 5,        // antes: Tinte
        'Servicio básico de niño' => 7,  // antes: Corte niño
    ];

    public function run(): void
    {
        $catalog = [
            [
                'name' => 'Servicio básico',
                'price' => 25000,
                'category' => 'corte',
                'image' => ['basico'],
                'exclude' => ['nino'],
                'image_path' => 'services/servicio-basico.png',
                'description' => implode("\n", [
                    '- Corte de cabello personalizado y estilizado final.',
                    '- Mascarilla facial negra para ayudar a retirar impurezas y cuidar la piel.',
                    '- Masaje relajante para liberar tensión.',
                    '- Cortesía de la casa: bebida gaseosa + snack.',
                ]),
            ],
            [
                'name' => 'Servicio antifatiga',
                'price' => 60000,
                'category' => 'cuidado',
                'image' => ['antifatiga'],
                'image_path' => 'services/servicio-antifatiga.png',
                'description' => implode("\n", [
                    '- Corte de cabello personalizado.',
                    '- Mascarilla facial negra enfocada en puntos negros e impurezas.',
                    '- Masaje ocular: masaje suave en el contorno de ojos, rostro y frente para brindar descanso y relajación.',
                    '- Masaje corporal relajante en espalda, cuello y hombros.',
                    '- Cortesía de la casa: bebida gaseosa + snack.',
                ]),
            ],
            [
                'name' => 'Servicio VIP',
                'price' => 110000,
                'category' => 'cuidado',
                'image' => ['vip'],
                'image_path' => 'services/servicio-vip.png',
                'description' => implode("\n", [
                    '- Vapor de ozono como preparación para la limpieza facial.',
                    '- Exfoliación facial premium para retirar células muertas y mejorar la apariencia de la textura de la piel.',
                    '- Limpieza facial profunda para retirar impurezas, exceso de grasa y residuos.',
                    '- Mascarilla facial específica según las necesidades de la piel: hidratante, purificante, calmante o revitalizante.',
                    '- Masaje y tratamiento ocular con parches o contorno de ojos para refrescar la mirada y reducir la apariencia de cansancio.',
                    '- Masaje facial relajante.',
                    '- Cortesía de la casa: bebida gaseosa + snack.',
                    'Importante: Este servicio no incluye corte de cabello, barba ni cejas.',
                ]),
            ],
            [
                'name' => 'Combo completo',
                'price' => 30000,
                'category' => 'combo',
                'image' => ['combo'],
                'image_path' => 'services/combo-completo.png',
                'description' => implode("\n", [
                    '- Corte de cabello personalizado.',
                    '- Arreglo y perfilado de barba con definición precisa de líneas.',
                    '- Perfilado de cejas: limpieza y diseño.',
                    '- Mascarilla facial negra para ayudar a retirar impurezas y cuidar la piel.',
                    '- Masaje corporal relajante en cuello, hombros y espalda.',
                    '- Cortesía de la casa: bebida gaseosa + snack.',
                ]),
            ],
            [
                'name' => 'Servicio básico de niño',
                'price' => 18000,
                'category' => 'corte',
                'image' => ['basico', 'nino'],
                'image_path' => 'services/servicio-basico-nino.png',
                'description' => implode("\n", [
                    '- Corte de cabello personalizado.',
                    '- Atención paciente y amable.',
                    '- Ambiente cómodo y adaptado para que el niño se sienta seguro y en confianza.',
                    '- Cortesía especial: bebida gaseosa + snack.',
                    'Importante: No incluye mascarilla, barba ni masajes.',
                ]),
            ],
            [
                'name' => 'Mechas platinadas',
                'price' => 150000,
                'category' => 'color',
                'image' => ['mechas'],
                'image_path' => 'services/mechas-platinadas.png',
                'description' => implode("\n", [
                    '- Asesoría y diseño de mechas.',
                    '- Aplicación profesional de mechas platinadas.',
                    '- Tonalidad personalizada.',
                    '- Cuidado y tratamiento del cabello.',
                    '- Corte y estilizado.',
                    '- Cortesía de la casa: bebida gaseosa + snack.',
                ]),
            ],
            [
                'name' => 'Instalación de prótesis capilar',
                'price' => 1500000,
                'category' => 'cuidado',
                'image' => ['protesis'],
                'image_path' => 'services/protesis-capilar.png',
                'description' => implode("\n", [
                    '- Asesoría personalizada.',
                    '- Preparación e higiene del cuero cabelludo.',
                    '- Instalación profesional de la prótesis capilar.',
                    '- Corte y diseño para lograr un acabado natural.',
                    '- Recomendaciones de mantenimiento.',
                    '- Cortesía de la casa: bebida gaseosa + snack.',
                ]),
            ],
        ];

        $this->copyImages($catalog);

        $canonicalNames = [];

        foreach ($catalog as $data) {
            $service = null;

            if (isset(self::REUSE_IDS[$data['name']])) {
                $service = Service::find(self::REUSE_IDS[$data['name']]);
            }

            if (! $service) {
                $service = Service::firstOrNew(['name' => $data['name']]);
            }

            $isNew = ! $service->exists;

            $service->fill([
                'name' => $data['name'],
                'price' => $data['price'],
                'category' => $data['category'],
                'description' => $data['description'],
                'active' => true,
            ]);

            // La duración no forma parte del catálogo oficial: se conserva la que ya tenía el
            // servicio reutilizado y, para los nuevos, queda el valor operativo por defecto.
            if ($isNew) {
                $service->duration_minutes = 30;
            }

            if (Storage::disk('public')->exists($data['image_path'])) {
                $service->image_path = $data['image_path'];
            }

            $service->save();
            $canonicalNames[] = $data['name'];
        }

        // Los servicios que no están en la lista oficial se desactivan, nunca se borran.
        Service::query()
            ->whereNotIn('name', $canonicalNames)
            ->where('active', true)
            ->update(['active' => false]);

        $this->command?->info('Catálogo actualizado: '.(count($canonicalNames)).' servicios activos.');
    }

    /**
     * Copia las imágenes de la carpeta del proyecto a storage/app/public/services.
     * Si el archivo ya existe en storage, no se vuelve a copiar.
     */
    private function copyImages(array $catalog): void
    {
        $disk = Storage::disk('public');
        $sourceDir = base_path('imagenes de servicios');

        if (! $disk->exists('services')) {
            $disk->makeDirectory('services');
        }

        if (! File::isDirectory($sourceDir)) {
            return;
        }

        $sources = collect(File::files($sourceDir))->map(fn ($file) => [
            'path' => $file->getPathname(),
            'name' => $this->normalize($file->getFilename()),
        ]);

        foreach ($catalog as $data) {
            if ($disk->exists($data['image_path'])) {
                continue;
            }

            $match = $sources->first(function (array $source) use ($data) {
                foreach ($data['image'] as $keyword) {
                    if (! str_contains($source['name'], $keyword)) {
                        return false;
                    }
                }

                foreach ($data['exclude'] ?? [] as $keyword) {
                    if (str_contains($source['name'], $keyword)) {
                        return false;
                    }
                }

                return true;
            });

            if ($match) {
                $disk->put($data['image_path'], File::get($match['path']));
            }
        }
    }

    /** "Instalación profesional de prótesis capilar.png" → "instalacion profesional de protesis capilar png". */
    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));
    }
}
