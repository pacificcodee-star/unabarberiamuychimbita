<?php

namespace App\Http\Controllers;

use App\Models\BarberUnavailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AvailabilityController extends Controller
{
    public function index(Request $request)
    {
        $barbers = User::barbers()->orderBy('name')->get();
        $selectedBarberId = $request->integer('barber_id') ?: ($barbers->first()?->id);
        $date = $request->input('date', now()->toDateString());

        $unavailabilities = collect();
        $selectedBarber = null;
        if ($selectedBarberId) {
            $selectedBarber = $barbers->firstWhere('id', $selectedBarberId) ?: User::barbers()->find($selectedBarberId);
            if ($selectedBarber) {
                $unavailabilities = BarberUnavailability::query()
                    ->where('barber_id', $selectedBarber->id)
                    ->whereDate('date', '>=', now()->subDays(1)->toDateString())
                    ->orderBy('date')
                    ->orderBy('start_time')
                    ->get();
            }
        }

        return view('availability.index', compact('barbers', 'selectedBarber', 'selectedBarberId', 'date', 'unavailabilities'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'barber_id' => ['required', 'exists:users,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'type' => ['required', 'in:day,range'],
            'start_time' => ['nullable', 'required_if:type,range', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_if:type,range', 'date_format:H:i', 'after:start_time'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'end_time.after' => 'La hora de fin debe ser mayor que la hora de inicio.',
        ]);

        $barber = User::barbers()->findOrFail($data['barber_id']);

        $start = $data['type'] === 'range' ? $data['start_time'] : null;
        $end = $data['type'] === 'range' ? $data['end_time'] : null;

        // Evitar duplicados exactos o solapamientos simples.
        $exists = BarberUnavailability::query()
            ->where('barber_id', $barber->id)
            ->whereDate('date', $data['date'])
            ->where(function ($q) use ($start, $end) {
                if ($start === null) {
                    $q->whereNull('start_time');
                } else {
                    $q->whereNull('start_time')
                      ->orWhere(function ($q2) use ($start, $end) {
                          $q2->where('start_time', '<', $end)
                             ->where('end_time', '>', $start);
                      });
                }
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'date' => 'Ya existe una indisponibilidad que se cruza con ese día u horario para este barbero.',
            ]);
        }

        BarberUnavailability::create([
            'barber_id' => $barber->id,
            'date' => Carbon::parse($data['date'])->toDateString(),
            'start_time' => $start,
            'end_time' => $end,
            'note' => $data['note'] ?? null,
        ]);

        return redirect()
            ->route('availability.index', ['barber_id' => $barber->id, 'date' => $data['date']])
            ->with('success', 'Disponibilidad actualizada: se registró la indisponibilidad del barbero.');
    }

    public function destroy(BarberUnavailability $availability)
    {
        $barberId = $availability->barber_id;
        $date = $availability->date?->toDateString();
        $availability->delete();

        return redirect()
            ->route('availability.index', ['barber_id' => $barberId, 'date' => $date])
            ->with('success', 'Indisponibilidad eliminada. El barbero vuelve a estar disponible en ese periodo.');
    }
}
