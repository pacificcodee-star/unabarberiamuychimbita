<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ImageUpload implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! str_starts_with((string) $value->getMimeType(), 'image/')) {
            $fail('El archivo debe ser una imagen válida.');
        }
    }
}
