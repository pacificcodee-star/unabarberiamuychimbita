<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeaturedCut extends Model
{
    public const MAX = 100;

    public const GALLERY_FIELDS = ['gallery_1_path', 'gallery_2_path', 'gallery_3_path'];

    protected $fillable = [
        'title',
        'subtitle',
        'description',
        'image_path',
        'video_path',
        'gallery_1_path',
        'gallery_2_path',
        'gallery_3_path',
        'service_id',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function scopeVisible($query)
    {
        return $query->where('active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Rutas de las fotos flotantes que el admin subió (sin vacíos). */
    public function galleryPaths(): array
    {
        return array_values(array_filter(array_map(fn ($field) => $this->{$field}, self::GALLERY_FIELDS)));
    }

    public function hasCutoutImage(): bool
    {
        return in_array(strtolower(pathinfo((string) $this->image_path, PATHINFO_EXTENSION)), ['png', 'webp', 'avif', 'gif'], true);
    }
}
