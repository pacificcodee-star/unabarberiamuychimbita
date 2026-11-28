<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Turn;
use App\Models\User;
use App\Services\AttendanceVerificationService;
use App\Services\ClientNotifier;
use App\Services\TurnQueueService;
use App\Services\TurnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TurnController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Al abrir la cola se cierra la jornada anterior aunque el programador no haya corrido a medianoche.
        app(TurnQueueService::class)->closePreviousDays();

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
        // Este módulo es solo de turnos (orden de llegada): las citas con hora viven en el módulo Citas.
        $barbers = $user->isAdmin()
            ? User::barbers()->where('active', true)->where('attention_mode', 'llegada')->orderBy('name')->get()
            : collect([$user]);
        $services = Service::active()->orderBy('name')->get();

        return view('turns.index', compact('turns', 'barbers', 'services', 'date', 'counts'));
    }

    public function store(Request $request, TurnService $turnService)
    {
        $user = $request->user();

        $data = $request->validate([
            'barber_id' => [$user->isAdmin() ? 'required' : 'nullable', 'exists:users,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'client_name' => ['required', 'string', 'max:120'],
            'client_phone' => ['nullable', 'string', 'max:30'],
            'turn_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $barberId = $user->isAdmin() ? (int) $data['barber_id'] : $user->id;
        // Solo barberos que atienden por orden de llegada (la modalidad viene de la base de datos).
        $barber = User::barbers()
            ->where('active', true)
            ->where('attention_mode', 'llegada')
            ->find($barberId);

        if (! $barber) {
            throw ValidationException::withMessages([
                'barber_id' => 'Ese barbero atiende con cita, no por orden de llegada. Agenda su cita en el módulo Citas.',
            ]);
        }

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

        try {
            [$turn, $previousStatus, $note] = DB::transaction(function () use ($request, $turn, $data) {
                $barberId = (int) $turn->barber_id;
                User::whereKey($barberId)->lockForUpdate()->firstOrFail();
                $current = Turn::whereKey($turn->id)->lockForUpdate()->firstOrFail();

                if ((int) $current->barber_id !== $barberId) {
                    throw ValidationException::withMessages(['status' => 'El turno cambió de barbero mientras se actualizaba. Recárgalo e inténtalo de nuevo.']);
                }

                // Un turno atendido queda cerrado: solo se puede registrar su ingreso si faltaba.
                if ($current->status === 'atendido' && ($data['status'] !== 'atendido' || $current->sale_id)) {
                    throw ValidationException::withMessages(['status' => 'Este turno ya fue atendido y no se puede modificar. Si fue un error, elimínalo.']);
                }

                $this->ensureAttendanceDecisionNotBypassed($request, $current, $data['status']);
                if (! $current->canTransitionTo($data['status'])) {
                    throw ValidationException::withMessages(['status' => 'No se puede pasar un turno de "'.$current->statusLabel().'" a "'.Turn::STATUSES[$data['status']].'".']);
                }

                $previousStatus = $current->status;
                $attributes = ['status' => $data['status']];

                if ($data['status'] === 'llamado') {
                    $attributes['called_at'] = $current->called_at && $previousStatus === 'llamado' ? $current->called_at : now();
                    $attributes['attendance_prompted_at'] = null;
                    $attributes['attendance_status'] = null;
                    $attributes['attendance_verified_at'] = null;
                    $attributes['attendance_verified_by'] = null;
                    $attributes['started_at'] = null;
                    $attributes['finished_at'] = null;
                }

                if ($data['status'] === 'esperando' && $previousStatus === 'cancelado') {
                    $attributes['attendance_status'] = null;
                    $attributes['attendance_verified_at'] = null;
                    $attributes['attendance_verified_by'] = null;
                    $attributes['attendance_prompted_at'] = null;
                    $attributes['started_at'] = null;
                    $attributes['finished_at'] = null;
                }

                if ($data['status'] === 'atendiendo' && ($previousStatus !== 'atendiendo' || ! $current->started_at)) {
                    $attributes['called_at'] = $current->called_at ?: now();
                    $attributes['started_at'] = now();
                }

                if (in_array($data['status'], ['atendido', 'cancelado'], true) && ($previousStatus !== $data['status'] || ! $current->finished_at)) {
                    // Finalizar guarda la hora real: la cola deja de contar este turno y el
                    // tiempo no utilizado queda libre inmediatamente para web y bot.
                    $attributes['finished_at'] = now();
                }

                $current->update($attributes);
                $note = app(\App\Services\AttendedServiceRecorder::class)->sync($current, $request->input('payment_method'));

                return [$current, $previousStatus, $note];
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->with('error', $e->getMessage());
        }

        // Avisos al cliente y avance automático de la fila fuera de la transacción.
        $notifier = app(ClientNotifier::class);
        $turn->loadMissing(['barber', 'service']);
        if ($previousStatus !== $turn->status) {
            if ($turn->status === 'cancelado') {
                $notifier->turnCancelled($turn);
            } elseif ($turn->status === 'atendido') {
                $notifier->serviceThanks($turn);
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

        // La fila avanza sola, pero el barbero puede adelantarse cuando el servicio ya terminó.
        $result = app(TurnQueueService::class)->callNextManually($barber);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function updateAttendance(Request $request, Turn $turn, AttendanceVerificationService $verification)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['decision' => ['required', 'in:presente,ausente']]);
        $result = $verification->verify($request->user(), $turn, $data['decision']);

        if ($request->expectsJson()) {
            return response()->json($result);
        }

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

    private function ensureAttendanceDecisionNotBypassed(Request $request, Turn $turn, string $status): void
    {
        if ($turn->attendance_status === 'ausente' && $status !== 'cancelado') {
            throw ValidationException::withMessages(['status' => 'La ausencia ya fue registrada. Este turno no se puede reactivar.']);
        }

        $legacyAttended = $turn->attendance_status === null && $turn->status === 'atendido' && $status === 'atendido';
        $legacyInProgress = $turn->attendance_status === null && $turn->status === 'atendiendo' && $status === 'atendido';

        if (in_array($status, ['atendiendo', 'atendido'], true) && ! $legacyAttended && ! $legacyInProgress && $turn->attendance_status !== 'presente') {
            throw ValidationException::withMessages(['status' => 'Solo el administrador puede iniciar el servicio después de verificar "Llegó".']);
        }

        if ($status === 'cancelado' && ($turn->attendance_status === 'presente'
            || ($turn->attendance_status === null && $turn->status === 'llamado' && app(TurnQueueService::class)->expectedStart($turn)->lte(now())))) {
            throw ValidationException::withMessages(['status' => 'Usa las acciones "Llegó" o "No llegó" para registrar la asistencia.']);
        }
    }
}
