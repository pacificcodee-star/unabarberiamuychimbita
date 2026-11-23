@unless($subjects->isEmpty())
    @php
        // Color de la etiqueta de estado.
        $tones = [
            'pendiente' => 'amber', 'confirmada' => 'green', 'atendida' => 'muted', 'cancelada' => 'red',
            'esperando' => 'gold', 'llamado' => 'green', 'atendiendo' => 'blue', 'atendido' => 'muted', 'cancelado' => 'red',
        ];
        $icons = ['confirmar' => 'bi-check2-circle', 'reprogramar' => 'bi-calendar2-week', 'cancelar' => 'bi-x-circle'];
    @endphp
    <section class="service-group" aria-labelledby="service-group-{{ $groupKey }}">
        <h2 id="service-group-{{ $groupKey }}">{{ $title }} <span class="msv-count">{{ $subjects->count() }}</span></h2>
        <div class="msv-grid">
            @foreach($subjects as $subject)
                @php
                    $turn = $subject instanceof \App\Models\Turn;
                    $type = $turn ? 'turno' : 'cita';
                    $estimate = $turn && ! $history ? ($estimates[$subject->id] ?? null) : null;
                    $when = $turn ? $subject->turn_date->format('d/m/Y') : $subject->startsAt()->format('d/m/Y · g:i a');
                    $labels = ['confirmar' => 'Confirmar cita', 'reprogramar' => 'Reprogramar cita', 'cancelar' => 'Cancelar '.$type];
                    $day = $turn ? $subject->turn_date->copy()->locale('es') : $subject->startsAt()->locale('es');
                    $isNext = ! $history && isset($nextKey) && $nextKey === $type.':'.$subject->id;
                    $tone = $tones[$subject->status] ?? 'muted';
                    $relative = $day->isToday() ? 'Hoy' : ($day->isTomorrow() ? 'Mañana' : ucfirst($day->isoFormat('dddd D [de] MMMM')));
                @endphp
                <article class="msv-card {{ $isNext ? 'is-next' : '' }} {{ $history ? 'is-history' : '' }}">
                    @if($isNext)
                        <p class="msv-flag"><i class="bi bi-stars" aria-hidden="true"></i> Tu próximo servicio</p>
                    @endif
                    <div class="msv-main">
                        <div class="msv-when" aria-hidden="true">
                            @if($turn)
                                <span>Turno</span>
                                <strong>#{{ str_pad((string) $subject->turn_number, 3, '0', STR_PAD_LEFT) }}</strong>
                                <span>{{ rtrim($day->isoFormat('D MMM'), '.') }}</span>
                            @else
                                <span>{{ rtrim($day->isoFormat('ddd'), '.') }}</span>
                                <strong>{{ $day->format('j') }}</strong>
                                <span>{{ rtrim($day->isoFormat('MMM'), '.') }}</span>
                            @endif
                        </div>
                        <div class="msv-body">
                            <div class="service-heading">
                                <h3>{{ $subject->service?->name ?? ($turn ? 'Turno' : 'Cita') }}</h3>
                                <span class="msv-status tone-{{ $tone }}">{{ $subject->statusLabel() }}</span>
                            </div>
                            <ul class="msv-meta">
                                <li><i class="bi bi-calendar3" aria-hidden="true"></i> {{ $relative }}@unless($turn) · {{ $subject->startsAt()->format('g:i a') }}@else · por orden de llegada @endunless</li>
                                @if($subject->barber)
                                    <li><i class="bi bi-person" aria-hidden="true"></i> {{ $subject->barber->name }}</li>
                                @endif
                                @if($history && $subject->service_price !== null)
                                    <li><i class="bi bi-receipt" aria-hidden="true"></i> Precio registrado: {{ format_money($subject->service_price) }}</li>
                                @endif
                            </ul>
                        </div>
                    </div>

                    @if($estimate)
                        <div class="msv-queue {{ $estimate['ahead'] === 0 ? 'is-next-up' : '' }}">
                            <i class="bi {{ $estimate['ahead'] === 0 ? 'bi-bell' : 'bi-people' }}" aria-hidden="true"></i>
                            <div>
                                <strong>{{ $estimate['ahead'] === 0 ? 'Eres el siguiente' : ($estimate['ahead'] === 1 ? '1 persona antes de ti' : $estimate['ahead'].' personas antes de ti') }}</strong>
                                <span>Te llamamos cerca de las {{ $estimate['call_at']->format('g:i a') }}</span>
                            </div>
                        </div>
                    @endif

                    @php $actions = \App\Services\ClientBookingActions::actions($subject); @endphp
                    @if(count($actions) || ($history && $subject->service?->active))
                        <div class="service-actions">
                            @foreach($actions as $action)
                                <button type="button" class="btn btn-sm {{ $action === 'confirmar' ? 'msv-btn-confirm' : ($action === 'cancelar' ? 'msv-btn-cancel' : 'msv-btn-reschedule') }}"
                                    data-action="{{ $action }}" data-url="{{ route('my-services.update', ['type' => $type, 'id' => $subject->id, 'action' => $action]) }}"
                                    data-context="{{ $when }} · {{ $subject->barber?->name }}" data-barber="{{ $subject->barber_id }}"
                                    data-date="{{ $turn ? '' : $subject->appointment_date->toDateString() }}"><i class="bi {{ $icons[$action] ?? 'bi-arrow-right' }}" aria-hidden="true"></i> {{ $labels[$action] }}</button>
                            @endforeach
                            @if($history && $subject->service?->active)
                                <a class="btn btn-sm btn-ghost-light" href="{{ route('public.booking', ['tipo' => $type, 'servicio' => $subject->service_id]) }}"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Volver a reservar</a>
                            @endif
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endunless
