<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Turn;
use App\Services\TurnQueueService;
use Carbon\Carbon;

class ServiceTimeline
{
    public function __construct(private TurnQueueService $queue) {}

    public function for(Appointment|Turn $subject, ?array $estimate, Carbon $now): ?array
    {
        if (in_array($subject->status, ['cancelada', 'cancelado', 'no_asistio'], true)) {
            return null;
        }

        if ($subject instanceof Turn) {
            $duration = $this->queue->durationMinutes($subject);
            $finished = $subject->status === 'atendido';
            $start = match ($subject->status) {
                'esperando' => $estimate['start_at'] ?? null,
                'llamado' => $this->queue->expectedStart($subject),
                default => $subject->started_at ?? ($subject->status === 'atendiendo' ? $subject->called_at : null),
            };
            $end = $finished ? $subject->finished_at : $start?->copy()->addMinutes($duration);
            $live = $subject->status === 'atendiendo';
        } else {
            $duration = $subject->durationMinutes();
            $finished = $subject->status === 'atendida';
            $start = $subject->started_at ?? $subject->startsAt();
            $end = $finished ? $subject->completionEvent?->created_at : $start->copy()->addMinutes($duration);
            $live = $subject->status === 'en_atencion' && $subject->started_at !== null;
        }

        $elapsed = $start !== null && $live ? max(0, $now->timestamp - $start->timestamp) : 0;
        $progress = $finished ? 100 : min(100, 100 * $elapsed / ($duration * 60));

        return [
            'start' => $start,
            'end' => $end,
            'duration' => $duration,
            'live' => $live && $start !== null,
            'finished' => $finished,
            'progress' => round($progress, 2),
            'elapsed' => min($duration, intdiv($elapsed, 60)),
            'remaining' => max(0, $duration - intdiv($elapsed, 60)),
            'started' => $live && $start !== null && $now->gte($start),
        ];
    }
}
