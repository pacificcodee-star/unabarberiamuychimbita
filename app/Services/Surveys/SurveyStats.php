<?php

namespace App\Services\Surveys;

use App\Models\Review;
use App\Models\SurveyAnswer;
use App\Models\SurveyInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Indicadores de satisfacción calculados SOLO con respuestas reales.
 * Sin respuestas no hay promedio (null), y un barbero o servicio con menos de `min_sample`
 * encuestas no entra al comparativo (se marca "muestra insuficiente").
 */
class SurveyStats
{
    public function __construct(private array $filters = [])
    {
    }

    public function invitations(): Builder
    {
        $f = $this->filters;

        return SurveyInvitation::query()
            ->when($f['barber_id'] ?? null, fn ($q, $v) => $q->where('barber_id', $v))
            ->when($f['service_id'] ?? null, fn ($q, $v) => $q->where('service_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('service_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('service_date', '<=', $v));
    }

    /** Respuestas de encuestas terminadas para una pregunta, con su invitación. */
    private function answers(string $key): Collection
    {
        $ids = $this->invitations()->where('status', 'completada')->pluck('id');

        return SurveyAnswer::query()
            ->join('survey_sessions', 'survey_sessions.id', '=', 'survey_answers.survey_session_id')
            ->join('survey_invitations', 'survey_invitations.id', '=', 'survey_sessions.survey_invitation_id')
            ->whereIn('survey_invitations.id', $ids)
            ->where('survey_sessions.status', 'completada')
            ->where('survey_answers.question_key', $key)
            ->whereNotNull('survey_answers.value')
            ->get(['survey_answers.value', 'survey_invitations.barber_id', 'survey_invitations.service_id', 'survey_invitations.service_date']);
    }

    public function summary(): array
    {
        $base = $this->invitations();
        $sent = (clone $base)->whereIn('status', ['enviada', 'completada', 'vencida'])->count();
        $completed = (clone $base)->where('status', 'completada')->count();
        $pending = (clone $base)->whereIn('status', ['programada', 'enviada'])->count();

        $experience = $this->answers('experiencia')->pluck('value')->map(fn ($v) => (int) $v);
        $barberCare = $this->answers('atencion_barbero')->pluck('value')->map(fn ($v) => (int) $v);
        $recommend = $this->answers('recomendaria')->pluck('value');

        return [
            'sent' => $sent,
            'completed' => $completed,
            'pending' => $pending,
            'response_rate' => $sent > 0 ? round($completed / $sent * 100) : null,
            'avg_experience' => $experience->isNotEmpty() ? round($experience->avg(), 1) : null,
            'avg_barber' => $barberCare->isNotEmpty() ? round($barberCare->avg(), 1) : null,
            'recommend_rate' => $recommend->isNotEmpty() ? round($recommend->filter(fn ($v) => $v === 'si')->count() / $recommend->count() * 100) : null,
            'rated' => $experience->count(),
            'public_reviews' => Review::approved()->whereNotNull('survey_session_id')->count(),
            'needs_attention' => $this->needsAttentionCount(),
            'in_review' => Review::where('moderation_status', 'revision')->count(),
        ];
    }

    /** Promedio de experiencia por barbero o servicio. */
    public function breakdown(string $by): Collection
    {
        $column = $by === 'service' ? 'service_id' : 'barber_id';
        $min = (int) config('encuestas.min_sample', 5);

        return $this->answers('experiencia')
            ->filter(fn ($row) => $row->{$column} !== null)
            ->groupBy($column)
            ->map(fn (Collection $rows, $id) => [
                'id' => (int) $id,
                'count' => $rows->count(),
                'avg' => round($rows->avg(fn ($r) => (int) $r->value), 1),
                'enough' => $rows->count() >= $min,
            ])
            ->sortByDesc(fn ($row) => [$row['enough'], $row['avg'], $row['count']])
            ->values();
    }

    /** Evolución mensual de los últimos meses (solo meses con respuestas). */
    public function monthly(int $months = 6): Collection
    {
        $since = now()->startOfMonth()->subMonths($months - 1);

        return $this->answers('experiencia')
            ->filter(fn ($row) => Carbon::parse($row->service_date)->gte($since))
            ->groupBy(fn ($row) => Carbon::parse($row->service_date)->format('Y-m'))
            ->map(fn (Collection $rows, $month) => [
                'month' => $month,
                'label' => Carbon::createFromFormat('Y-m', $month)->locale('es')->translatedFormat('M Y'),
                'count' => $rows->count(),
                'avg' => round($rows->avg(fn ($r) => (int) $r->value), 1),
            ])
            ->sortKeys()
            ->values();
    }

    /** Encuestas terminadas con señales de insatisfacción (para reaccionar, no para ocultar). */
    public function needsAttentionQuery(): Builder
    {
        return $this->invitations()->where('status', 'completada')->whereHas('session.answers', function ($q) {
            $q->where(function ($q) {
                $q->whereIn('question_key', ['experiencia', 'atencion_barbero'])->whereIn('value', ['1', '2']);
            })->orWhere(function ($q) {
                $q->whereIn('question_key', ['expectativas', 'recomendaria'])->where('value', 'no');
            });
        });
    }

    private function needsAttentionCount(): int
    {
        return $this->needsAttentionQuery()->count();
    }
}
