<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ImageUpload implements ValidationRule
{
    public const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Solo imágenes de mapa de bits: un SVG puede llevar JavaScript y ejecutarse en el dominio del sitio.
        if (! $value instanceof UploadedFile || ! in_array((string) $value->getMimeType(), self::ALLOWED, true)) {
            $fail('El archivo debe ser una imagen JPG, PNG, WebP, AVIF o GIF.');
        }
    }
}
