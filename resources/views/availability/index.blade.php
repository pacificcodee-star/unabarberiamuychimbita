@extends('layouts.app')

@section('title', 'Disponibilidad por barbero')

@section('content')
    <p class="page-intro">Configura los días y horarios en los que cada barbero no podrá atender.</p>

    <div class="card">
        <div class="card-body">
            <form method="GET" action="{{ route('availability.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-8 col-md-5">
                    <label class="form-label mb-1">Barbero</label>
                    <select name="barber_id" class="form-select" onchange="this.form.submit()">
                        @foreach($barbers as $barber)
                            <option value="{{ $barber->id }}" @selected($selectedBarberId == $barber->id)>{{ $barber->name }}@if(! $barber->active) (inactivo)@endif</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <noscript><button class="btn btn-dark">Ver</button></noscript>
                </div>
                @if($selectedBarber)
                    <div class="col-md text-md-end">
                        <span class="badge-soft gold"><i class="bi bi-person-badge" aria-hidden="true"></i> {{ $selectedBarber->attentionModeLabel() }}</span>
                    </div>
                @endif
            </form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-calendar-x me-1"></i> Bloqueo semanal</div>
                <div class="card-body">
                    @if($selectedBarber)
                    <p class="text-muted small mb-3">El bloqueo se repite cada semana para <strong>{{ $selectedBarber->name }}</strong> en todos los días que marques.</p>
                    <form method="POST" action="{{ route('availability.store') }}" class="row g-3">
                        @csrf
                        <input type="hidden" name="barber_id" value="{{ $selectedBarberId }}">
                        <fieldset class="col-12">
                            <legend class="form-label fs-6">Días de la semana</legend>
                            <div class="d-flex flex-wrap gap-2 column-gap-3">
                                @foreach($weekdays as $day => $label)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="weekdays[]" id="weekday{{ $day }}" value="{{ $day }}"
                                            @checked(in_array($day, (array) old('weekdays', [])))>
                                        <label class="form-check-label" for="weekday{{ $day }}">{{ $label }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                        <fieldset class="col-12">
                            <legend class="form-label fs-6">Horario del bloqueo</legend>
                            <div class="d-flex flex-wrap gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="type" id="typeDay" value="day" {{ old('type', 'day') === 'day' ? 'checked' : '' }}>
                                    <label class="form-check-label" for="typeDay">No disponible todo el día</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="type" id="typeRange" value="range" {{ old('type') === 'range' ? 'checked' : '' }}>
                                    <label class="form-check-label" for="typeRange">No disponible en un horario</label>
                                </div>
                            </div>
                        </fieldset>
                        <div class="col-sm-6 time-range-field">
                            <label class="form-label" for="availabilityStart">Desde</label>
                            <input type="time" name="start_time" id="availabilityStart" class="form-control" value="{{ old('start_time') }}">
                        </div>
                        <div class="col-sm-6 time-range-field">
                            <label class="form-label" for="availabilityEnd">Hasta</label>
                            <input type="time" name="end_time" id="availabilityEnd" class="form-control" value="{{ old('end_time') }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Nota (opcional)</label>
                            <input type="text" name="note" class="form-control" maxlength="255" value="{{ old('note') }}" placeholder="Ej: permiso, cita médica, descanso">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-dark"><i class="bi bi-save me-1"></i> Guardar bloqueo semanal</button>
                        </div>
                    </form>
                    @else
                        <p class="text-muted mb-0">Selecciona un barbero para configurar su horario.</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">
                    <span><i class="bi bi-list-ul"></i> Indisponibilidades de {{ $selectedBarber?->name ?? 'barbero' }}</span>
                </div>
                <div class="card-body p-0">
                    @if(! $selectedBarber)
                        <div class="empty-state"><i class="bi bi-person"></i>Selecciona un barbero para ver su disponibilidad.</div>
                    @elseif($unavailabilities->isEmpty())
                        <div class="empty-state"><i class="bi bi-calendar-check"></i><p>No hay bloqueos vigentes para <strong>{{ $selectedBarber->name }}</strong>.</p></div>
                    @else
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Día</th>
                                        <th>Horario</th>
                                        <th>Nota</th>
                                        <th class="text-end">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($unavailabilities as $item)
                                        <tr>
                                            <td>
                                                @if($item->weekday !== null)
                                                    {{ $weekdays[$item->weekday] }}
                                                    <div class="small text-muted">Cada semana</div>
                                                @else
                                                    {{ $item->date?->locale('es')->translatedFormat('d \d\e F, Y') }}
                                                    <div class="small text-muted">Una sola vez</div>
                                                @endif
                                            </td>
                                            <td>
                                                @if($item->isAllDay())
                                                    <span class="badge text-bg-secondary">Todo el día</span>
                                                @else
                                                    {{ \Carbon\Carbon::parse($item->start_time)->format('h:i A') }} - {{ \Carbon\Carbon::parse($item->end_time)->format('h:i A') }}
                                                @endif
                                            </td>
                                            <td>{{ $item->note ?: '—' }}</td>
                                            <td class="text-end">
                                                <form method="POST" action="{{ route('availability.destroy', $item) }}" onsubmit="return confirm('¿Eliminar esta indisponibilidad?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        const day = document.getElementById('typeDay');
        const range = document.getElementById('typeRange');
        const fields = document.querySelectorAll('.time-range-field');
        const inputs = document.querySelectorAll('.time-range-field input');
        function sync() {
            const isRange = range && range.checked;
            fields.forEach(f => f.style.display = isRange ? '' : 'none');
            inputs.forEach(i => { i.required = isRange; i.disabled = !isRange; });
        }
        day?.addEventListener('change', sync);
        range?.addEventListener('change', sync);
        sync();
    })();
</script>
@endpush
