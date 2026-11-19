<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Service extends Model
{
    /** Categorías disponibles para la carta pública (clave => etiqueta). */
    public const CATEGORIES = [
        'corte' => 'Cortes',
        'barba' => 'Barba',
        'color' => 'Color',
        'combo' => 'Combos',
        'cuidado' => 'Cuidado',
    ];

    protected $fillable = [
        'name',
        'category',
        'description',
        'image_path',
        'price',
        'duration_minutes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** Categoría guardada o, si el admin no eligió ninguna, una deducida del nombre. */
    public function categoryKey(): string
    {
        return array_key_exists((string) $this->category, self::CATEGORIES)
            ? $this->category
            : self::guessCategory($this->name);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->categoryKey()];
    }

    /** True cuando la imagen admite transparencia (PNG/WebP/AVIF/GIF): se muestra recortada, sin fondo. */
    public function hasCutoutImage(): bool
    {
        return $this->image_path
            && in_array(strtolower(pathinfo($this->image_path, PATHINFO_EXTENSION)), ['png', 'webp', 'avif', 'gif'], true);
    }

    /**
     * Lo que incluye el servicio: cada renglón de la descripción que empieza con "- ".
     *
     * @return array<int, string>
     */
    public function includedItems(): array
    {
        return collect(preg_split('/\R/', (string) $this->description))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => str_starts_with($line, '- '))
            ->map(fn ($line) => trim(substr($line, 2)))
            ->values()
            ->all();
    }

    /** Renglones de la descripción que no son viñetas (por ejemplo, "Importante: no incluye…"). */
    public function descriptionNotes(): array
    {
        return collect(preg_split('/\R/', (string) $this->description))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => $line !== '' && ! str_starts_with($line, '- '))
            ->values()
            ->all();
    }

    public static function guessCategory(?string $name): string
    {
        $name = Str::lower(Str::ascii((string) $name));
        $keywords = [
            'corte' => ['corte', 'fade', 'degradado', 'rapado'],
            'barba' => ['barba', 'afeit', 'navaja', 'bigote'],
            'color' => ['tinte', 'color', 'mecha', 'decolor', 'platinado'],
            'cuidado' => ['ceja', 'mascarilla', 'facial', 'lavado', 'tratamiento', 'masaje', 'exfolia'],
        ];

        $matches = array_keys(array_filter(
            $keywords,
            fn (array $words) => (bool) array_filter($words, fn ($word) => str_contains($name, $word))
        ));

        if (str_contains($name, 'combo') || str_contains($name, '+') || count($matches) > 1) {
            return 'combo';
        }

        return $matches[0] ?? 'corte';
    }
}
