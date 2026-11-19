<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * "Cortes realizados" de cada barbero: fotos de sus trabajos.
 *
 * Se guardan como archivos en storage/app/public/barbers/cortes/{id del barbero}
 * (no usan tablas nuevas). Se muestran en el perfil del barbero y en la galería
 * "Nuestros trabajos" del inicio.
 */
class BarberCuts
{
    public const MAX_PER_BARBER = 40;

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'];

    public static function directory(User $barber): string
    {
        return 'barbers/cortes/'.$barber->id;
    }

    /** Fotos del barbero, de la más reciente a la más antigua. */
    public static function for(User $barber): Collection
    {
        $disk = Storage::disk('public');
        $directory = self::directory($barber);
        if (! $disk->exists($directory)) {
            return collect();
        }

        return collect($disk->files($directory))
            ->filter(fn (string $path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true))
            ->map(function (string $path) use ($disk, $barber) {
                // Medidas reales de la foto: la galería reserva su espacio sin deformarla.
                $size = @getimagesize($disk->path($path)) ?: [null, null];

                return [
                    'path' => $path,
                    'name' => basename($path),
                    'url' => asset('storage/'.$path),
                    'width' => $size[0],
                    'height' => $size[1],
                    'modified' => $disk->lastModified($path),
                    'barber' => $barber->name,
                    'barber_id' => $barber->id,
                ];
            })
            ->sortByDesc('modified')
            ->values();
    }

    /** Fotos de todos los barberos activos, mezcladas por fecha (para el inicio). */
    public static function recent(int $limit = 14): Collection
    {
        return User::barbers()->where('active', true)->get()
            ->flatMap(fn (User $barber) => self::for($barber))
            ->sortByDesc('modified')
            ->take($limit)
            ->values();
    }

    /**
     * Fotos generales de la barbería (sin barbero asignado) en storage/app/public/trabajos.
     * Se muestran en "Nuestros trabajos" del inicio junto con las de los barberos.
     */
    public static function general(): Collection
    {
        $disk = Storage::disk('public');
        if (! $disk->exists('trabajos')) {
            return collect();
        }

        return collect($disk->files('trabajos'))
            ->filter(fn (string $path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true))
            ->sort(fn ($a, $b) => strnatcasecmp($a, $b))
            ->map(function (string $path) use ($disk) {
                $size = @getimagesize($disk->path($path)) ?: [null, null];

                return [
                    'path' => $path,
                    'name' => basename($path),
                    'url' => asset('storage/'.$path),
                    'width' => $size[0],
                    'height' => $size[1],
                    'modified' => $disk->lastModified($path),
                ];
            })
            ->values();
    }

    /** Galería del inicio: TODAS las fotos de todos los barberos (las más recientes primero) y luego las generales. */
    public static function all(): Collection
    {
        return self::recent(PHP_INT_MAX)->concat(self::general())->values();
    }

    /** @param  array<int, UploadedFile>  $files */
    public static function store(User $barber, array $files): int
    {
        $free = max(0, self::MAX_PER_BARBER - self::for($barber)->count());
        $saved = 0;
        foreach (array_slice($files, 0, $free) as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $file->store(self::directory($barber), 'public');
                $saved++;
            }
        }

        return $saved;
    }

    /** Borra solo archivos que pertenecen a la carpeta del barbero (se ignora cualquier otro nombre). */
    public static function remove(User $barber, array $names): void
    {
        $existing = self::for($barber)->pluck('path', 'name');
        foreach ($names as $name) {
            $path = $existing->get(basename((string) $name));
            if ($path) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    public static function removeAll(User $barber): void
    {
        Storage::disk('public')->deleteDirectory(self::directory($barber));
    }
}
