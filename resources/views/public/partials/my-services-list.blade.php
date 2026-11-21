@foreach($sections as $title => $subjects)
    <section class="service-group" aria-labelledby="service-group-{{ $loop->index }}">
        <h2 id="service-group-{{ $loop->index }}">{{ $title }}</h2>
        @forelse($subjects as $subject)
            @php
                $turn = $subject instanceof \App\Models\Turn;
                $type = $turn ? 'turno' : 'cita';
                $estimate = $turn ? ($estimates[$subject->id] ?? null) : null;
                $when = $turn ? $subject->turn_date->format('d/m/Y') : $subject->startsAt()->format('d/m/Y · g:i a');
                $labels = ['confirmar' => 'Confirmar cita', 'reprogramar' => 'Reprogramar cita', 'cancelar' => 'Cancelar '.$type];
            @endphp
            <article class="service-entry">
                <div class="service-heading">
                    <h3>{{ $subject->service?->name ?? ($turn ? 'Turno' : 'Cita') }} @if($turn) · #{{ $subject->turn_number }}@endif</h3>
                    <span>{{ $subject->statusLabel() }}</span>
                </div>
                <p>{{ $when }} · {{ $subject->barber?->name }}</p>
                @if($estimate)
                    <p>{{ $estimate['ahead'] === 0 ? 'Eres el siguiente' : 'Personas antes de ti: '.$estimate['ahead'] }} · Llamado estimado {{ $estimate['call_at']->format('g:i a') }}</p>
                @endif
                <div class="service-actions">
                    @foreach(\App\Services\ClientBookingActions::actions($subject) as $action)
                        <button type="button" class="btn btn-sm {{ $action === 'confirmar' ? 'btn-gold' : 'btn-ghost-light' }}"
                            data-action="{{ $action }}" data-url="{{ route('my-services.update', ['type' => $type, 'id' => $subject->id, 'action' => $action]) }}"
                            data-context="{{ $when }} · {{ $subject->barber?->name }}" data-barber="{{ $subject->barber_id }}"
                            data-date="{{ $turn ? '' : $subject->appointment_date->toDateString() }}">{{ $labels[$action] }}</button>
                    @endforeach
                </div>
            </article>
        @empty
            <p class="service-empty">{{ match ($title) {
                'Citas' => 'No tienes citas activas.', 'Turnos' => 'No tienes turnos activos.',
                'Historial de citas' => 'Todavía no hay citas anteriores.', default => 'Todavía no hay turnos anteriores.'
            } }}</p>
        @endforelse
    </section>
@endforeach
