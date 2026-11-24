<?php

namespace App\Http\Controllers;

use App\Models\BarberUnavailability;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AvailabilityController extends Controller
{
    public function index(Request $request)
    {
        $barbers = User::barbers()->orderBy('name')->get();
        $selectedBarberId = $request->integer('barber_id') ?: ($barbers->first()?->id);
        $selectedBarber = $barbers->firstWhere('id', $selectedBarberId);
        $weekdays = BarberUnavailability::WEEKDAYS;

        $unavailabilities = $selectedBarber?->unavailabilities()
            ->where(fn ($query) => $query->whereNotNull('weekday')->orWhereDate('date', '>=', today()->subDay()->toDateString()))
            ->orderByRaw('CASE WHEN weekday IS NULL THEN 1 ELSE 0 END')
            ->orderBy('weekday')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get() ?? collect();

        return view('availability.index', compact('barbers', 'selectedBarber', 'selectedBarberId', 'weekdays', 'unavailabilities'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'barber_id' => ['required', 'exists:users,id'],
            'weekdays' => ['required', 'array', 'list', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'type' => ['required', 'in:day,range'],
            'start_time' => ['exclude_unless:type,range', 'required', 'date_format:H:i'],
            'end_time' => ['exclude_unless:type,range', 'required', 'date_format:H:i', 'after:start_time'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'weekdays.required' => 'Marca al menos un día de la semana.',
            'start_time.required' => 'Selecciona la hora de inicio.',
            'end_time.required' => 'Selecciona la hora de fin.',
            'end_time.after' => 'La hora de fin debe ser mayor que la hora de inicio.',
        ]);

        DB::transaction(function () use ($data) {
            $barber = User::barbers()->lockForUpdate()->findOrFail($data['barber_id']);
            $start = $data['start_time'] ?? null;
            $end = $data['end_time'] ?? null;

            $conflicts = $barber->unavailabilities()
                ->whereIn('weekday', $data['weekdays'])
                ->when($start !== null, fn ($query) => $query->where(function ($query) use ($start, $end) {
                    $query->whereNull('start_time')
                        ->orWhereNull('end_time')
                        ->orWhere(fn ($range) => $range->whereTime('start_time', '<', $end.':00')->whereTime('end_time', '>', $start.':00'));
                }))
                ->distinct()
                ->orderBy('weekday')
                ->pluck('weekday');

            if ($conflicts->isNotEmpty()) {
                $days = $conflicts->map(fn ($day) => BarberUnavailability::WEEKDAYS[$day])->join(', ');

                throw ValidationException::withMessages([
                    'weekdays' => 'El horario se cruza con un bloqueo semanal en '.mb_strtolower($days).'.',
                ]);
            }

            foreach ($data['weekdays'] as $day) {
                $barber->unavailabilities()->create([
                    'weekday' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                    'note' => $data['note'] ?? null,
                ]);
            }
        });

        return redirect()
            ->route('availability.index', ['barber_id' => $data['barber_id']])
            ->with('success', 'Bloqueo semanal guardado para los días seleccionados.');
    }

    public function destroy(BarberUnavailability $availability)
    {
        $barberId = $availability->barber_id;
        $availability->delete();

        return redirect()
            ->route('availability.index', ['barber_id' => $barberId])
            ->with('success', 'Indisponibilidad eliminada.');
    }
}
