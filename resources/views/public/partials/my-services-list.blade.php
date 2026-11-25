@php
    // La reserva más próxima se resalta: el turno de hoy primero; si no hay, la cita más cercana.
    $nextKey = $activeTurns->isNotEmpty()
        ? 'turno:'.$activeTurns->first()->id
        : ($activeCitas->isNotEmpty() ? 'cita:'.$activeCitas->sortBy(fn ($c) => $c->startsAt())->first()->id : null);
@endphp
<section class="service-panel" id="services-activos" role="tabpanel" aria-labelledby="tab-activos" data-service-panel="activos" @if($hasActive) data-has-active @endif @if($defaultTab !== 'activos') hidden @endif>
    @if($hasActive)
        @include('public.partials.my-services-group', ['groupKey' => 'active-citas', 'title' => 'Citas', 'subjects' => $activeCitas, 'history' => false])
        @include('public.partials.my-services-group', ['groupKey' => 'active-turnos', 'title' => 'Turnos', 'subjects' => $activeTurns, 'history' => false])
    @else
        <div class="service-empty-state">
            <span class="msv-empty-ico" aria-hidden="true"><i class="bi bi-calendar2-plus"></i></span>
            <h2>No tienes servicios activos.</h2>
            <p>Puedes solicitar una nueva cita o turno cuando quieras.</p>
            <div class="service-empty-actions">
                <a class="btn btn-gold btn-booking-blue" href="{{ route('public.booking') }}">Solicitar nuevo servicio</a>
            </div>
        </div>
    @endif
</section>
<section class="service-panel" id="services-historial" role="tabpanel" aria-labelledby="tab-historial" data-service-panel="historial" @if($defaultTab !== 'historial') hidden @endif>
    @if($hasHistory)
        @include('public.partials.my-services-group', ['groupKey' => 'history-citas', 'title' => 'Citas anteriores', 'subjects' => $historyAppointments, 'history' => true])
        @include('public.partials.my-services-group', ['groupKey' => 'history-turnos', 'title' => 'Turnos anteriores', 'subjects' => $historyTurns, 'history' => true])
    @else
        <div class="service-empty-state">
            <span class="msv-empty-ico" aria-hidden="true"><i class="bi bi-clock-history"></i></span>
            <h2>Todavía no tienes servicios anteriores.</h2>
            <p>Cuando termines o canceles una reserva, aparecerá aquí para que puedas volver a reservarla.</p>
        </div>
    @endif
</section>
