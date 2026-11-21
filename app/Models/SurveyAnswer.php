<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Respuesta a una pregunta. value = null significa que el cliente la omitió. */
class SurveyAnswer extends Model
{
    protected $fillable = ['survey_session_id', 'question_key', 'question_text', 'type', 'value', 'value_label', 'channel', 'answered_at'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SurveySession::class, 'survey_session_id');
    }
}
