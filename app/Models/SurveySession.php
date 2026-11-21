<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Respuesta en curso o terminada de una invitación. Guarda su propia copia de las preguntas. */
class SurveySession extends Model
{
    protected $fillable = ['survey_invitation_id', 'channel', 'status', 'questions', 'started_at', 'last_activity_at', 'completed_at'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'started_at' => 'datetime', 'last_activity_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(SurveyInvitation::class, 'survey_invitation_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyAnswer::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function answerFor(string $key): ?SurveyAnswer
    {
        return $this->answers->firstWhere('question_key', $key);
    }
}
