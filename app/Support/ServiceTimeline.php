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
        if (in_array($subject->status, ['cancelada', 'cancelado'], true)) {
            return null;
        }

        if ($subject instanceof Turn) {
            $duration = $this->queue->durationMinutes($subject);
            $finished = $subject->status === 'atendido';
            $verified = $subject->attendance_status === 'presente'
                || ($subject->attendance_status === null && $subject->status === 'atendiendo');
            $start = match (true) {
                $verified => $subject->started_at ?? $subject->called_at ?? $this->queue->expectedStart($subject),
                $subject->status === 'esperando' => $estimate['start_at'] ?? null,
                $subject->status === 'llamado' => $this->queue->expectedStart($subject),
                $finished => $subject->started_at ?? $subject->called_at ?? $subject->finished_at?->copy()->subMinutes($duration),
                default => null,
            };
            $end = $finished ? $subject->finished_at : $start?->copy()->addMinutes($duration);
            $live = $verified && in_array($subject->status, ['llamado', 'atendiendo'], true);
        } else {
            $duration = $subject->durationMinutes();
            $finished = $subject->status === 'atendida';
            $verified = $subject->attendance_status === 'presente';
            $start = $verified ? ($subject->started_at ?? $subject->startsAt()) : $subject->startsAt();
            $end = $finished
                ? ($subject->completionEvent?->created_at ?? $start->copy()->addMinutes($duration))
                : $start->copy()->addMinutes($duration);
            $live = $verified && $subject->status === 'confirmada';
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
