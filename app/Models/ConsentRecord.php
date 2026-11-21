<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Throwable;

/**
 * Autorización de tratamiento de datos de un titular para una finalidad concreta.
 * No guarda IP ni copias del nombre o teléfono: el titular se identifica por su reserva, turno u opinión.
 */
class ConsentRecord extends Model
{
    /** Textos exactos que acepta el titular en cada finalidad (su versión es la fecha de la política). */
    public const PURPOSES = [
        'tratamiento_reserva' => 'Tratamiento de mis datos (nombre, teléfono, correo y notas) para agendar, confirmar, recordar y gestionar mi cita o turno, según la política de privacidad.',
        'avisos_whatsapp' => 'Recibir por WhatsApp avisos de mi cita o turno (confirmación, recordatorios y cambios) en el número que registré.',
        'publicacion_resena' => 'Publicar en el sitio mi opinión con el nombre que escribí, después de revisada.',
    ];

    protected $fillable = ['uuid', 'purpose', 'text_version', 'channel', 'subject_type', 'subject_id', 'evidence_hash', 'accepted_at', 'revoked_at', 'revoked_via'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public static function version(): string
    {
        return 'politica-'.config('web.legal.updated_at', '2026-11-21');
    }

    /**
     * Guarda la autorización. Nunca interrumpe la reserva: si falla, queda en el registro de errores.
     */
    public static function grant(Model $subject, string $purpose, string $channel): ?self
    {
        try {
            $now = now();
            $version = self::version();
            $type = $subject->getMorphClass();
            $evidence = hash('sha256', implode('|', [$purpose, $version, self::PURPOSES[$purpose] ?? '', $type, $subject->getKey(), $now->toIso8601String()]));

            return self::create([
                'uuid' => (string) Str::uuid(),
                'purpose' => $purpose,
                'text_version' => $version,
                'channel' => $channel,
                'subject_type' => $type,
                'subject_id' => $subject->getKey(),
                'evidence_hash' => $evidence,
                'accepted_at' => $now,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Revoca las autorizaciones vigentes de esa finalidad para esos titulares. */
    public static function revoke(string $purpose, string $subjectType, array $subjectIds, string $via): int
    {
        if ($subjectIds === []) {
            return 0;
        }

        return self::where('purpose', $purpose)->where('subject_type', $subjectType)->whereIn('subject_id', $subjectIds)
            ->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_via' => $via]);
    }
}
