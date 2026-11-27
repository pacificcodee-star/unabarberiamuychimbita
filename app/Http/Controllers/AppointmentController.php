<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Services\AttendanceVerificationService;
use App\Services\WhatsApp\BookingMessenger;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user->isAdmin() && $user->attention_mode !== 'citas') {
            return redirect()
                ->route('turns.index')
                ->with('error', 'Tu modalidad es por orden de llegada. Gestiona tus turnos en la pestaña Turnos.');
        }

        $date = \Carbon\Carbon::parse($request->input('date', now()->toDateString()))->startOfDay();
        $viewMode = 'week'; // Siempre vista semanal

        $monthGridDays = collect();
        $yearMonths = collect();

        $start = $date->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
        $end = $date->copy()->endOfWeek(\Carbon\Carbon::SUNDAY);
        $days = collect();
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $days->push($d->copy());
        }

        $baseQuery = Appointment::query()
            ->with(['barber', 'service'])
            ->whereDate('appointment_date', '>=', $start->toDateString())
            ->whereDate('appointment_date', '<=', $end->toDateString());

        if (! $user->isAdmin()) {
            $baseQuery->where('barber_id', $user->id);
        }

        if ($user->isAdmin() && $request->filled('barber_id')) {
            $baseQuery->where('barber_id', $request->input('barber_id'));
        }

        $barbers = $user->isAdmin()
            ? User::barbers()->where('attention_mode', 'citas')->orderBy('name')->get()
            : collect();

        $counts = [
            'pendiente' => (clone $baseQuery)->where('status', 'pendiente')->count(),
            'confirmada' => (clone $baseQuery)->where('status', 'confirmada')->count(),
            'atendida' => (clone $baseQuery)->where('status', 'atendida')->count(),
            'cancelada' => (clone $baseQuery)->where('status', 'cancelada')->count(),
        ];

        $filteredQuery = clone $baseQuery;
        if ($request->filled('status')) {
            $filteredQuery->where('status', $request->input('status'));
        }

        $appointments = $filteredQuery->orderBy('appointment_date')->orderBy('appointment_time')->get();

        $hourStart = 7;
        $hourEnd = 20;
        // La grilla del calendario va de la primera apertura a el último cierre de la semana (panel del negocio).
        if ($bounds = app(\App\Services\BusinessHours::class)->weekBounds()) {
            $hourStart = max(0, (int) substr($bounds['opening'], 0, 2) - 1);
            $hourEnd = min(23, (int) substr($bounds['closing'], 0, 2) + 1);
        }

        return view('appointments.index', [
            'appointments' => $appointments,
            'barbers' => $barbers,
            'date' => $date->toDateString(),
            'counts' => $counts,
            'viewMode' => $viewMode,
            'days' => $days,
            'rangeStart' => $start,
            'rangeEnd' => $end,
            'hourStart' => $hourStart,
            'hourEnd' => $hourEnd,
            'monthGridDays' => $monthGridDays,
            'yearMonths' => $yearMonths,
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();

        if (! $user->isAdmin() && $user->attention_mode !== 'citas') {
            return redirect()
                ->route('turns.index')
                ->with('error', 'Tu modalidad es por orden de llegada. Gestiona tus turnos en la pestaña Turnos.');
        }

        $barbers = $user->isAdmin()
            ? User::barbers()->where('active', true)->where('attention_mode', 'citas')->orderBy('name')->get()
            : collect([$user]);

        $services = Service::active()->orderBy('name')->get();

        return view('appointments.create', compact('barbers', 'services'));
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);

        if (! $request->user()->isAdmin()) {
            $data['barber_id'] = $request->user()->id;
        }

        $this->ensureBarberHandlesAppointments((int) $data['barber_id']);

        $appointment = Appointment::reserveSlot($data, message: 'Ese horario ya está ocupado para el barbero seleccionado. Elige otra hora.');
        AuditLog::record('cita.creada', $appointment, ['barber_id' => $appointment->barber_id]);
        app(\App\Services\AttendedServiceRecorder::class)->sync($appointment, $request->input('payment_method'));
        $appointment->load(['service', 'barber']);
        app(\App\Services\ClientNotifier::class)->appointmentBooked($appointment);
        app(\App\Services\ClientNotifier::class)->flush();

        // Cita creada desde el panel: se le pide al cliente confirmar por WhatsApp igual que en la página.
        app(BookingMessenger::class)->requestAppointmentConfirmation($appointment);

        return redirect()
            ->route('appointments.index', ['date' => $data['appointment_date']])
            ->with('success', 'Cita agendada correctamente.');
    }

    public function edit(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);

        if ($appointment->status === 'atendida') {
            return redirect()->route('appointments.index', ['date' => $appointment->appointment_date->toDateString()])
                ->with('error', 'Esta cita ya fue atendida y no se puede modificar. Si fue un error, elimínala.');
        }

        if ($appointment->attendance_status !== null) {
            return redirect()->route('appointments.index', ['date' => $appointment->appointment_date->toDateString()])
                ->with('error', 'Esta cita ya tiene una decisión de asistencia y no se puede editar.');
        }
        $user = $request->user();

        if (! $user->isAdmin() && $user->attention_mode !== 'citas') {
            return redirect()
                ->route('turns.index')
                ->with('error', 'Tu modalidad es por orden de llegada. Gestiona tus turnos en la pestaña Turnos.');
        }

        $barbers = $user->isAdmin()
            ? User::barbers()->where('active', true)->where('attention_mode', 'citas')->orderBy('name')->get()
            : collect([$user]);

        $services = Service::active()->orderBy('name')->get();

        return view('appointments.edit', compact('appointment', 'barbers', 'services'));
    }

    public function update(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);

        if ($appointment->status === 'atendida') {
            return redirect()->route('appointments.index', ['date' => $appointment->appointment_date->toDateString()])
                ->with('error', 'Esta cita ya fue atendida y no se puede modificar. Si fue un error, elimínala.');
        }

        if ($appointment->attendance_status !== null) {
            return redirect()->route('appointments.index', ['date' => $appointment->appointment_date->toDateString()])
                ->with('error', 'Esta cita ya tiene una decisión de asistencia y no se puede editar.');
        }
        $data = $this->validatedData($request);

        if (! $request->user()->isAdmin()) {
            $data['barber_id'] = $request->user()->id;
        }

        $this->ensureBarberHandlesAppointments((int) $data['barber_id']);

        $before = ['status' => $appointment->status, 'starts_at' => $appointment->startsAt()];
        $appointment = DB::transaction(function () use ($request, $appointment, $data) {
            $current = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());
            if ($current->attendance_status !== null) {
                throw ValidationException::withMessages(['status' => 'La asistencia de esta cita ya fue registrada.']);
            }

            $this->ensureAttendanceDecisionNotBypassed($request, $current, $data['status']);
            if (! $current->canTransitionTo($data['status'])) {
                throw ValidationException::withMessages(['status' => 'No se puede pasar una cita de "'.$current->statusLabel().'" a "'.Appointment::STATUSES[$data['status']].'".']);
            }

            return Appointment::reserveSlot($data, $current, 'Ese horario ya está ocupado para el barbero seleccionado. Elige otra hora.');
        });
        AuditLog::record('cita.editada', $appointment, ['antes' => ['estado' => $before['status'], 'inicio' => (string) $before['starts_at']], 'estado' => $appointment->status]);
        $note = app(\App\Services\AttendedServiceRecorder::class)->sync($appointment, $request->input('payment_method'));
        // Si cambió la fecha, la hora o el estado: se cancelan los recordatorios viejos y se crean los nuevos.
        $appointment->load(['service', 'barber']);
        app(\App\Services\ClientNotifier::class)->appointmentChanged($appointment, $before);
        app(\App\Services\ClientNotifier::class)->flush();

        return redirect()
            ->route('appointments.index', ['date' => $data['appointment_date']])
            ->with('success', 'Cita actualizada correctamente.'.($note ? ' '.$note : ''));
    }

    public function updateStatus(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(Appointment::STATUSES))],
        ]);

        // Una cita atendida queda cerrada: no se cambia (solo se puede registrar su ingreso si faltaba, o eliminarla).
        if ($appointment->status === 'atendida' && ($data['status'] !== 'atendida' || $appointment->sale_id)) {
            return back()->with('error', 'Esta cita ya fue atendida y no se puede modificar. Si fue un error, elimínala.');
        }

        $before = ['status' => $appointment->status, 'starts_at' => $appointment->startsAt()];
        try {
            $appointment = DB::transaction(function () use ($request, $appointment, $data) {
                $current = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());
                if ($current->status === 'atendida' && ($data['status'] !== 'atendida' || $current->sale_id)) {
                    throw ValidationException::withMessages(['status' => 'Esta cita ya fue atendida y no se puede modificar.']);
                }

                $this->ensureAttendanceDecisionNotBypassed($request, $current, $data['status']);
                if (! $current->canTransitionTo($data['status'])) {
                    throw ValidationException::withMessages(['status' => 'No se puede pasar una cita de "'.$current->statusLabel().'" a "'.Appointment::STATUSES[$data['status']].'".']);
                }

                if ($current->status === 'cancelada' && $data['status'] !== 'cancelada') {
                    Appointment::reserveSlot([
                        'barber_id' => $current->barber_id,
                        'appointment_date' => $current->appointment_date->toDateString(),
                        'appointment_time' => substr((string) $current->appointment_time, 0, 5),
                        'status' => $data['status'],
                    ], $current, 'No se puede reactivar: ese horario ya lo tiene otra cita.');
                    $current->forceFill([
                        'status' => $data['status'],
                        'attendance_status' => null,
                        'attendance_verified_at' => null,
                        'attendance_verified_by' => null,
                        'attendance_prompted_at' => null,
                        'started_at' => null,
                    ])->save();
                } else {
                    $current->update(['status' => $data['status']]);
                }

                return $current;
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->with('error', $e->getMessage());
        }
        AuditLog::record('cita.estado', $appointment, ['de' => $before['status'], 'a' => $data['status']]);
        $note = app(\App\Services\AttendedServiceRecorder::class)->sync($appointment, $request->input('payment_method'));
        $appointment->loadMissing(['service', 'barber']);
        app(\App\Services\ClientNotifier::class)->appointmentChanged($appointment, $before);
        app(\App\Services\ClientNotifier::class)->flush();

        return back()->with('success', 'Estado de la cita actualizado.'.($note ? ' '.$note : ''));
    }

    public function updateAttendance(Request $request, Appointment $appointment, AttendanceVerificationService $verification)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['decision' => ['required', 'in:presente,ausente']]);
        $result = $verification->verify($request->user(), $appointment, $data['decision']);

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function destroy(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);
        // Si la cita ya había generado ingreso, también se elimina ese registro.
        AuditLog::record('cita.eliminada', $appointment, ['tenia_ingreso' => (bool) $appointment->sale_id]);
        $appointment->sale?->delete();
        $appointment->delete();

        return back()->with('success', 'Cita eliminada correctamente.');
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'barber_id' => ['required', 'exists:users,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'client_name' => ['required', 'string', 'max:120'],
            'client_phone' => ['nullable', 'string', 'max:30'],
            'appointment_date' => ['required', 'date'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'status' => ['required', 'in:'.implode(',', array_keys(Appointment::STATUSES))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function ensureBarberHandlesAppointments(int $barberId): void
    {
        $barber = User::find($barberId);

        if (! $barber || $barber->role !== 'barbero' || ! $barber->active || $barber->attention_mode !== 'citas') {
            throw ValidationException::withMessages([
                'barber_id' => 'El barbero seleccionado no atiende por citas. Elige un barbero con modalidad de citas.',
            ]);
        }
    }


    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $appointment->barber_id === $user->id, 403);
    }

    private function ensureAttendanceDecisionNotBypassed(Request $request, Appointment $appointment, string $status): void
    {
        if ($appointment->attendance_status === 'ausente' && $status !== 'cancelada') {
            throw ValidationException::withMessages(['status' => 'La ausencia ya fue registrada. Esta cita no se puede reactivar.']);
        }

        $legacyAttended = $appointment->attendance_status === null && $appointment->status === 'atendida';

        if ($status === 'atendida' && ! $legacyAttended && $appointment->attendance_status !== 'presente') {
            throw ValidationException::withMessages(['status' => 'Primero verifica la llegada del cliente con la acción "Llegó".']);
        }

        if ($status === 'cancelada' && ($appointment->attendance_status === 'presente'
            || ($appointment->attendance_status === null && $appointment->startsAt()->lte(now())))) {
            throw ValidationException::withMessages(['status' => 'Usa las acciones "Llegó" o "No llegó" para registrar la asistencia.']);
        }
    }
}
