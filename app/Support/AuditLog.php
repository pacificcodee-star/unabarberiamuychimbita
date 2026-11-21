<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registro de auditoría de acciones importantes del panel (storage/logs/auditoria-*.log, 90 días).
 * Guarda quién, qué, sobre cuál registro y desde dónde; nunca nombres, teléfonos ni contraseñas.
 * Si escribir el registro falla, la acción del usuario sigue normal.
 */
class AuditLog
{
    public static function record(string $action, ?Model $subject = null, array $context = []): void
    {
        try {
            $user = auth()->user();
            Log::channel('audit')->info($action, array_filter([
                'usuario_id' => $user?->getKey(),
                'rol' => $user?->role,
                'registro' => $subject ? class_basename($subject).'#'.$subject->getKey() : null,
                'ip' => request()?->ip(),
                'request_id' => RequestId::current(),
            ]) + $context);
        } catch (Throwable) {
            // La auditoría nunca debe interrumpir la operación.
        }
    }
}
