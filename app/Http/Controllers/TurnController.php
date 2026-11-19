<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\Turn;
use App\Models\User;
use App\Services\ClientNotifier;
use App\Services\TurnQueueService;
use App\Services\TurnService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TurnController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();


        $date = $request->input('date', now()->toDateString());

        $query = Turn::query()
            ->with(['barber', 'service'])
            ->whereDate('turn_date', $date)
            ->orderBy('turn_number');

        if (! $user->isAdmin()) {
            $query->where('barber_id', $user->id);
        }

        if ($user->isAdmin() && $request->filled('barber_id')) {
            $query->where('barber_id', $request->input('barber_id'));
        }

        $counts = [
            'esperando' => (clone $query)->where('status', 'esperando')->count(),
            'llamado' => (clone $query)->where('status', 'llamado')->count(),
            'atendiendo' => (clone $query)->where('status', 'atendiendo')->count(),
            'atendido' => (clone $query)->where('status', 'atendido')->count(),
            'cancelado' => (clone $query)->where('status', 'cancelado')->count(),
        ];

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $turns = $query->get();
        $barbers = $user->isAdmin()
            ? User::barbers()->where('active', true)->orderBy('name')->get()
            : collect([$user]);
        $services = Service::active()->orderBy('name')->get();

        // Citas del mismo día, para ver todo (llegada y citas) en un solo panel.
        $appointments = Appointment::query()
            ->with(['barber', 'service'])
            ->whereDate('appointment_date', $date)
            ->when(! $user->isAdmin(), fn ($q) => $q->where('barber_id', $user->id))
            ->when($user->isAdmin() && $request->filled('barber_id'), fn ($q) => $q->where('barber_id', $request->input('barber_id')))
            ->orderBy('appointment_time')
            ->get();

        return view('turns.index', compact('turns', 'barbers', 'services', 'date', 'counts', 'appointments'));
    }

    public function store(Request $request, TurnService $turnService)
    {
        $user = $request->user();

        $isAppointment = $request->input('type') === 'cita';

        $data = $request->validate([
            'type' => ['nullable', 'in:llegada,cita'],
            'barber_id' => [$user->isAdmin() ? 'required' : 'nullable', 'exists:users,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'client_name' => ['required', 'string', 'max:120'],
            'client_phone' => ['nullable', 'string', 'max:30'],
            'turn_date' => ['nullable', 'date'],
            'appointment_date' => [$isAppointment ? 'required' : 'nullable', 'date', 'after_or_equal:today'],
            'appointment_time' => [$isAppointment ? 'required' : 'nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'appointment_date.required' => 'Elige la fecha de la cita.',
            'appointment_date.after_or_equal' => 'La cita no puede ser en una fecha pasada.',
            'appointment_time.required' => 'Elige la hora de la cita.',
        ]);

        $barberId = $user->isAdmin() ? (int) $data['barber_id'] : $user->id;
        // La modalidad del barbero (citas u orden de llegada) se toma de la base de datos.
        $barber = User::barbers()
            ->where('active', true)
            ->where('attention_mode', $isAppointment ? 'citas' : 'llegada')
            ->find($barberId);

        if (! $barber) {
            throw ValidationException::withMessages([
                'barber_id' => $isAppointment ? 'Ese barbero no atiende con cita. Elige uno que trabaje por citas.' : 'Ese barbero no atiende por orden de llegada. Elige uno que trabaje por llegada.',
            ]);
        }

        // Cita: se agenda con fecha y hora (se ve aquí y en el módulo Citas).
        if ($isAppointment) {
            $taken = Appointment::query()
                ->where('barber_id', $barber->id)
                ->whereDate('appointment_date', $data['appointment_date'])
                ->where('appointment_time', 'like', $data['appointment_time'].'%')
                ->where('status', '!=', 'cancelada')
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['appointment_time' => "{$barber->name} ya tiene una cita a esa hora. Elige otra."]);
            }

            $appointment = Appointment::create([
                'barber_id' => $barber->id,
                'service_id' => $data['service_id'] ?? null,
                'client_name' => $data['client_name'],
                'client_phone' => $data['client_phone'] ?? null,
                'appointment_date' => $data['appointment_date'],
                'appointment_time' => $data['appointment_time'],
                'status' => 'confirmada',
                'notes' => $data['notes'] ?? null,
            ]);
            $appointment->load(['service', 'barber']);
            app(ClientNotifier::class)->appointmentBooked($appointment);
            app(ClientNotifier::class)->flush();

            return redirect()
                ->route('turns.index', ['date' => $data['appointment_date']])
                ->with('success', 'Cita agendada para '.$data['client_name'].' con '.$barber->name.' el '.\Carbon\Carbon::parse($data['appointment_date'])->format('d/m/Y').' a las '.\Carbon\Carbon::parse($data['appointment_time'])->format('h:i A').'.');
        }

        unset($data['type'], $data['appointment_date'], $data['appointment_time']);

        $turn = $turnService->createFor($barber, $data);

        return redirect()
            ->route('turns.index', ['date' => $turn->turn_date->toDateString()])
            ->with('success', "Turno #{$turn->turn_number} registrado para {$barber->name}.");
    }

    public function updateStatus(Request $request, Turn $turn)
    {
        $this->authorizeTurn($request, $turn);

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(Turn::STATUSES))],
        ]);

        // Un turno atendido queda cerrado: no se cambia (solo se puede registrar su ingreso si faltaba, o eliminarlo).
        if ($turn->status === 'atendido' && ($data['status'] !== 'atendido' || $turn->sale_id)) {
            return back()->with('error', 'Este turno ya fue atendido y no se puede modificar. Si fue un error, elimínalo.');
        }

        $previousStatus = $turn->status;
        $attributes = ['status' => $data['status']];

        if ($data['status'] === 'llamado') {
            $attributes['called_at'] = now();
        }

        if ($data['status'] === 'atendiendo') {
            // Empieza a contar la duración del servicio; al cumplirse, el turno se cierra solo.
            $attributes['called_at'] = $turn->called_at ?: now();
            $attributes['started_at'] = now();
        }

        if (in_array($data['status'], ['atendido', 'cancelado'], true)) {
            $attributes['finished_at'] = now();
        }

        $turn->update($attributes);
        $note = app(\App\Services\AttendedServiceRecorder::class)->sync($turn, $request->input('payment_method'));

        // Avisos al cliente y avance automático de la fila del barbero.
        $notifier = app(ClientNotifier::class);
        $turn->loadMissing(['barber', 'service']);
        if ($previousStatus !== $turn->status) {
            if ($turn->status === 'cancelado') {
                $notifier->cancelPending($turn);
                $notifier->turnCancelledByStaff($turn);
            } elseif ($previousStatus === 'esperando' && in_array($turn->status, ['llamado', 'atendiendo'], true)) {
                $notifier->turnCalled($turn, false);
            }
        }
        if ($turn->turn_date->isToday()) {
            app(TurnQueueService::class)->advance($turn->barber_id);
        } else {
            $notifier->flush();
        }

        return back()->with('success', 'Estado del turno actualizado.'.($note ? ' '.$note : ''));
    }

    public function callNext(Request $request)
    {
        $user = $request->user();
        $barberId = $user->isAdmin() ? (int) $request->input('barber_id') : $user->id;

        $barber = User::barbers()
            ->where('active', true)
            ->where('attention_mode', 'llegada')
            ->find($barberId);

        if (! $barber) {
            return back()->with('error', 'Selecciona un barbero que atienda por orden de llegada.');
        }

        if (! $user->isAdmin() && $barber->id !== $user->id) {
            abort(403);
        }

        // La fila avanza sola, pero el barbero puede adelantarse: se cierra el turno en atención
        // (se registra su ingreso), se llama al siguiente y se le avisa al cliente.
        $result = app(TurnQueueService::class)->callNextManually($barber);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function destroy(Request $request, Turn $turn)
    {
        $this->authorizeTurn($request, $turn);
        // Si el turno ya había generado ingreso, también se elimina ese registro.
        $turn->sale?->delete();
        $barberId = $turn->barber_id;
        $wasToday = $turn->turn_date->isToday();
        $turn->delete(); // también borra sus avisos pendientes y sus dispositivos suscritos
        if ($wasToday) {
            app(TurnQueueService::class)->advance($barberId);
        }

        return back()->with('success', 'Turno eliminado correctamente.');
    }

    private function authorizeTurn(Request $request, Turn $turn): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $turn->barber_id === $user->id, 403);
    }
}
