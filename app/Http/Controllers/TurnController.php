<?php

namespace App\Http\Controllers;

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
            'no_asistio' => (clone $query)->where('status', 'no_asistio')->count(),
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

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $turn) {
            User::whereKey($turn->barber_id)->lockForUpdate()->firstOrFail();
            $current = Turn::lockForUpdate()->findOrFail($turn->id);
            $this->authorizeTurn($request, $current);

            return $this->changeStatus($request, $current);
        }, 3);
    }

    private function changeStatus(Request $request, Turn $turn)
    {

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(Turn::STATUSES))],
        ]);

        if (in_array($data['status'], ['atendiendo', 'no_asistio'], true)) {
            return back()->with('error', 'La llegada se registra desde administración.');
        }

        // Un turno atendido queda cerrado: no se cambia (solo se puede registrar su ingreso si faltaba, o eliminarlo).
        if ($turn->status === 'atendido' && ($data['status'] !== 'atendido' || $turn->sale_id)) {
            return back()->with('error', 'Este turno ya fue atendido y no se puede modificar. Si fue un error, elimínalo.');
        }

        if (! $turn->canTransitionTo($data['status'])) {
            return back()->with('error', 'No se puede pasar un turno de "'.$turn->statusLabel().'" a "'.Turn::STATUSES[$data['status']].'".');
        }

        if ($data['status'] === 'llamado' && $turn->status === 'esperando') {
            $open = Turn::where('barber_id', $turn->barber_id)->whereDate('turn_date', today());
            if (! $turn->turn_date->isToday() || (clone $open)->whereIn('status', ['llamado', 'atendiendo'])->exists()
                || (clone $open)->where('status', 'esperando')->where('turn_number', '<', $turn->turn_number)->exists()) {
                return back()->with('error', 'Espera a que este turno sea el siguiente en la fila del barbero.');
            }
        }

        $previousStatus = $turn->status;
        $attributes = ['status' => $data['status']];

        if ($data['status'] === 'llamado' && ($previousStatus !== 'llamado' || ! $turn->called_at)) {
            $attributes['called_at'] = now();
        }

        if (in_array($data['status'], ['atendido', 'cancelado'], true) && ($previousStatus !== $data['status'] || ! $turn->finished_at)) {
            $attributes['finished_at'] = now();
        }

        $turn->update($attributes);
        $note = app(\App\Services\AttendedServiceRecorder::class)->sync($turn, $request->input('payment_method'));

        // Avisos al cliente y avance automático de la fila del barbero.
        $notifier = app(ClientNotifier::class);
        $turn->loadMissing(['barber', 'service']);
        if ($previousStatus !== $turn->status) {
            if ($turn->status === 'cancelado') {
                $notifier->turnCancelled($turn);
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
