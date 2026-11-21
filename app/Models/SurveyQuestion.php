<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pregunta del catálogo de la encuesta (editable en el panel). */
class SurveyQuestion extends Model
{
    public const TYPES = [
        'stars' => 'Estrellas (1 a 5)',
        'choice' => 'Opciones (una sola)',
        'text' => 'Texto libre',
        'consent' => 'Autorización (sí / no)',
    ];

    protected $fillable = ['key', 'text', 'help', 'type', 'options', 'is_optional', 'show_if', 'sort_order', 'active'];

    protected function casts(): array
    {
        return ['options' => 'array', 'show_if' => 'array', 'is_optional' => 'boolean', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->orderBy('sort_order')->orderBy('id');
    }

    /** Copia inmutable que guarda cada sesión al empezar. */
    public function snapshot(): array
    {
        return [
            'key' => $this->key,
            'text' => $this->text,
            'help' => $this->help,
            'type' => $this->type,
            'options' => array_values($this->options ?? []),
            'optional' => $this->is_optional,
            'show_if' => $this->show_if,
        ];
    }
}
