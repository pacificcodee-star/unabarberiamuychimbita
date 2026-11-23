<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\ReservationStatusEvent;
use App\Models\Turn;
use Illuminate\Support\Facades\Auth;

class ReservationStatusHistory
{
    public static function record(Appointment|Turn $subject, ?string $from): void
    {
        $to = (string) $subject->status;
        if ($from === $to) {
            return;
        }

        $request = app()->bound('request') ? request() : null;
        $visitor = $request?->attributes->get('guest_visitor');

        [$actorType, $actorId] = match (true) {
            Auth::check() => ['staff', Auth::id()],
            $visitor !== null => ['guest', $visitor->id],
            default => ['system', null],
        };

        $reason = match (true) {
            $to === 'cancelada' && $actorType === 'guest' => 'Cancelada por el cliente',
            $to === 'cancelada' => 'Cancelación automática',
            $to === 'cancelado' && $actorType === 'guest' => 'Cancelado por el cliente',
            default => null,
        };

        ReservationStatusEvent::create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);
    }
}
