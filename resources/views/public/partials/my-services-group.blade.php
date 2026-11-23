@unless($subjects->isEmpty())
    <section class="service-group" aria-labelledby="service-group-{{ $groupKey }}">
        <h2 id="service-group-{{ $groupKey }}">{{ $title }}</h2>
        @foreach($subjects as $subject)
            @php
                $turn = $subject instanceof \App\Models\Turn;
                $type = $turn ? 'turno' : 'cita';
                $estimate = $turn && ! $history ? ($estimates[$subject->id] ?? null) : null;
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
                @if($history && $subject->service_price !== null)
                    <p>Precio registrado: {{ format_money($subject->service_price) }}</p>
                @endif
                <div class="service-actions">
                    @foreach(\App\Services\ClientBookingActions::actions($subject) as $action)
                        <button type="button" class="btn btn-sm {{ $action === 'confirmar' ? 'btn-gold' : 'btn-ghost-light' }}"
                            data-action="{{ $action }}" data-url="{{ route('my-services.update', ['type' => $type, 'id' => $subject->id, 'action' => $action]) }}"
                            data-context="{{ $when }} · {{ $subject->barber?->name }}" data-barber="{{ $subject->barber_id }}"
                            data-date="{{ $turn ? '' : $subject->appointment_date->toDateString() }}">{{ $labels[$action] }}</button>
                    @endforeach
                    @if($history && $subject->service?->active)
                        <a class="btn btn-sm btn-ghost-light" href="{{ route('public.booking', ['tipo' => $type, 'servicio' => $subject->service_id]) }}">Volver a reservar</a>
                    @endif
                </div>
            </article>
        @endforeach
    </section>
@endunless
