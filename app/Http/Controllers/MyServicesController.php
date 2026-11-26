<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Turn;
use App\Services\ClientBookingActions;
use App\Services\TurnQueueService;
use App\Support\AttendanceConfirmation;
use App\Support\ClientServiceAccess;
use App\Support\ServiceTimeline;
use App\Support\WhatsAppHistoryLink;
use Illuminate\Http\Request;

class MyServicesController extends Controller
{
    public function access(Request $request, string $type, string $token)
    {
        $subject = ($type === 'turno' ? Turn::query() : Appointment::query())->where('public_token', $token)->firstOrFail();
        $date = $type === 'turno' ? $subject->turn_date : $subject->appointment_date;
        abort_if($date->copy()->addDays((int) config('notificaciones.track_link_days', 30))->endOfDay()->isPast(), 404);
        ClientServiceAccess::remember($request, $subject);

        return redirect()->route('my-services.index')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, TurnQueueService $queue, ServiceTimeline $timeline)
    {
        $now = now();
        $appointments = ClientServiceAccess::owned($request, 'cita')->with(['barber', 'service', 'completionEvent'])
            ->orderByDesc('appointment_date')->orderByDesc('appointment_time')->get();
        $turns = ClientServiceAccess::owned($request, 'turno')->with(['barber', 'service'])
            ->orderByDesc('turn_date')->orderByDesc('turn_number')->get();

        [$activeAppointments, $historyAppointments] = $appointments->partition(function (Appointment $appointment) use ($now) {
            if (! in_array($appointment->status, ['pendiente', 'confirmada'], true)) {
                return false;
            }

            return $appointment->startsAt()->addMinutes($appointment->durationMinutes())->gt($now)
                || ($appointment->status === 'confirmada' && $appointment->appointment_date->isToday()
                    && AttendanceConfirmation::confirmed($appointment));
        });
        [$activeTurns, $historyTurns] = $turns->partition(fn ($t) =>
            $t->turn_date->isToday() && in_array($t->status, TurnQueueService::OPEN, true));
        $estimates = [];
        foreach ($activeTurns->where('status', 'esperando')->pluck('barber_id')->unique() as $barberId) {
            $estimates += $queue->estimates($barberId);
        }

        $timelines = ['cita' => [], 'turno' => []];
        foreach ($appointments as $appointment) {
            $timelines['cita'][$appointment->id] = $timeline->for($appointment, null, $now);
        }
        foreach ($turns as $turn) {
            $timelines['turno'][$turn->id] = $timeline->for($turn, $estimates[$turn->id] ?? null, $now);
        }

        $activeCitas = $activeAppointments->reverse();
        $hasActive = $activeCitas->isNotEmpty() || $activeTurns->isNotEmpty();
        $hasHistory = $historyAppointments->isNotEmpty() || $historyTurns->isNotEmpty();
        $defaultTab = ! $hasActive && $hasHistory ? 'historial' : 'activos';
        $template = $request->header('X-Service-List') ? 'public.partials.my-services-list' : 'public.my-services';

        return response()->view($template, compact(
            'activeCitas', 'activeTurns', 'historyAppointments', 'historyTurns', 'estimates', 'hasActive', 'hasHistory', 'defaultTab',
            'timelines', 'now'
        ))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function openWhatsAppLink(Request $request, string $token, WhatsAppHistoryLink $historyLink)
    {
        $historyLink->stage($request, $token);

        return redirect()->route('my-services.whatsapp.confirm')
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function confirmWhatsAppLink(Request $request, WhatsAppHistoryLink $historyLink)
    {
        return response()->view('my-services.connect-whatsapp', [
            'maskedPhone' => $historyLink->maskedPhone($request),
            'chatUrl' => $historyLink->chatUrl($request),
        ])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function finishWhatsAppLink(Request $request, WhatsAppHistoryLink $historyLink)
    {
        if ($request->input('intent') === 'lookup') {
            $data = $request->validate(['client_phone' => ['required', 'string', 'max:30']]);
            $linked = $historyLink->begin($request, $data['client_phone']);

            return redirect()->route($linked ? 'my-services.index' : 'my-services.whatsapp.confirm')
                ->header('Cache-Control', 'private, no-store');
        }
        $historyLink->finish($request);

        return redirect()->route('my-services.index')
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function update(Request $request, string $type, int $id, string $action, ClientBookingActions $actions)
    {
        $subject = ClientServiceAccess::owned($request, $type)->findOrFail($id);
        $data = $action === 'reprogramar' ? $request->validate([
            'appointment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'appointment_time' => ['required', 'date_format:H:i'],
        ]) : [];

        return response()->json(['message' => $actions->apply($subject, $action, $data)]);
    }
}
