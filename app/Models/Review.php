<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opinión pública. Viene del formulario de la web (sin verificar) o de una encuesta tras un
 * servicio real (verificada). Solo se publica con autorización y si pasó la moderación objetiva.
 */
class Review extends Model
{
    public const MODERATION = [
        'pendiente' => 'Pendiente de revisión',
        'aprobada' => 'Publicada',
        'revision' => 'Requiere revisión',
        'oculta' => 'Oculta por el equipo',
        'retirada' => 'Retirada a pedido del cliente',
    ];

    protected $fillable = [
        'client_name',
        'rating',
        'comment',
        'approved',
        'source',
        'survey_session_id',
        'barber_id',
        'service_id',
        'anonymous',
        'moderation_status',
        'moderation_reason',
        'published_at',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'approved' => 'boolean',
            'anonymous' => 'boolean',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** Publicadas: aprobadas y no retiradas. Es lo único que ve el público. */
    public function scopeApproved($query)
    {
        return $query->where('approved', true)->whereNull('withdrawn_at');
    }

    /** Calificación mínima para que la tarjeta de una opinión se muestre en la página pública. */
    public const MIN_FEATURED_RATING = 4;

    /**
     * Opiniones aprobadas que se muestran como tarjetas (4 y 5 estrellas).
     * El promedio y el total públicos se calculan con todas las aprobadas (approved), no solo con estas,
     * para que la calificación que ve el cliente no quede inflada.
     */
    public function scopeFeatured($query)
    {
        return $query->approved()->where('rating', '>=', self::MIN_FEATURED_RATING);
    }

    public function barber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barber_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isVerified(): bool
    {
        return $this->survey_session_id !== null;
    }

    /** Nombre que se muestra: "Andrés G." o "Cliente verificado" si pidió no mostrar su nombre. */
    public function publicName(): string
    {
        if ($this->anonymous) {
            return $this->isVerified() ? 'Cliente verificado' : 'Cliente';
        }
        $parts = preg_split('/\s+/u', trim((string) $this->client_name)) ?: [];
        $first = $parts[0] ?? 'Cliente';

        return isset($parts[1]) ? $first.' '.mb_strtoupper(mb_substr($parts[1], 0, 1)).'.' : $first;
    }
}
