<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Turn;
use App\Services\ClientBookingActions;
use App\Services\TurnQueueService;
use App\Support\ClientServiceAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /**
     * Vuelve a vincular este navegador con una reserva cuyo enlace secreto ya estaba guardado aquí
     * (ver public/js/device-recovery.js). Sirve cuando la cookie de «Mis servicios» se perdió pero
     * el navegador conserva el enlace: no entrega más de lo que ese mismo enlace ya permite ver.
     */
    public function link(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['cita', 'turno'])],
            'token' => ['required', 'string', 'max:64'],
        ]);
        $subject = ($data['type'] === 'turno' ? Turn::query() : Appointment::query())
            ->where('public_token', $data['token'])->first();
        abort_if(! $subject, 404);

        $date = $subject instanceof Turn ? $subject->turn_date : $subject->appointment_date;
        abort_if($date && $date->copy()->addDays((int) config('notificaciones.track_link_days', 30))->endOfDay()->isPast(), 404);

        ClientServiceAccess::remember($request, $subject);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request, TurnQueueService $queue)
    {
        $appointments = ClientServiceAccess::owned($request, 'cita')->with(['barber', 'service'])
            ->orderByDesc('appointment_date')->orderByDesc('appointment_time')->get();
        $turns = ClientServiceAccess::owned($request, 'turno')->with(['barber', 'service'])
            ->orderByDesc('turn_date')->orderByDesc('turn_number')->get();

        [$activeAppointments, $historyAppointments] = $appointments->partition(fn ($a) =>
            in_array($a->status, ['pendiente', 'confirmada'], true) && $a->startsAt()->copy()
                ->addMinutes($a->service?->duration_minutes ?: config('notificaciones.default_service_minutes', 30))->isFuture());
        [$activeTurns, $historyTurns] = $turns->partition(fn ($t) =>
            $t->turn_date->isToday() && in_array($t->status, TurnQueueService::OPEN, true));
        $estimates = [];
        foreach ($activeTurns->where('status', 'esperando')->pluck('barber_id')->unique() as $barberId) {
            $estimates += $queue->estimates($barberId);
        }

        $activeCitas = $activeAppointments->reverse();
        $template = $request->header('X-Service-List') ? 'public.partials.my-services-list' : 'public.my-services';

        return response()->view($template, compact(
            'activeCitas', 'activeTurns', 'historyAppointments', 'historyTurns', 'estimates'
        ))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
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
