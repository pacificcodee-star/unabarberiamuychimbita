<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Invitación a responder la encuesta de un servicio terminado (una por cita o turno). */
class SurveyInvitation extends Model
{
    public const STATUSES = [
        'programada' => 'Programada',
        'enviada' => 'Enviada',
        'completada' => 'Respondida',
        'cancelada' => 'Cancelada',
        'vencida' => 'Vencida',
    ];

    protected $fillable = ['subject_type', 'subject_id', 'barber_id', 'service_id', 'service_date', 'status', 'send_after', 'sent_at', 'expires_at', 'completed_at'];

    protected function casts(): array
    {
        return ['service_date' => 'date', 'send_after' => 'datetime', 'sent_at' => 'datetime', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function session(): HasOne
    {
        return $this->hasOne(SurveySession::class);
    }

    public function barber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barber_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * ¿Se puede responder por el enlace web? Sí mientras el servicio siga atendido (no cancelada),
     * aunque el cliente llegue antes del aviso o después del plazo: nunca se le muestra un error
     * por falta de tiempo. El plazo (expires_at) solo marca la invitación como «vencida» para
     * dejar de recordarla y para las estadísticas.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, ['programada', 'enviada', 'completada', 'vencida'], true);
    }
}
