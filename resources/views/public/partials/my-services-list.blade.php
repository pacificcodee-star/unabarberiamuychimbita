@php
    $hasActive = $activeCitas->isNotEmpty() || $activeTurns->isNotEmpty();
    $hasHistory = $historyAppointments->isNotEmpty() || $historyTurns->isNotEmpty();
@endphp
<section class="service-panel" id="services-activos" role="tabpanel" aria-labelledby="tab-activos" data-service-panel="activos">
    @if($hasActive)
        @include('public.partials.my-services-group', ['groupKey' => 'active-citas', 'title' => 'Citas', 'subjects' => $activeCitas, 'history' => false])
        @include('public.partials.my-services-group', ['groupKey' => 'active-turnos', 'title' => 'Turnos', 'subjects' => $activeTurns, 'history' => false])
    @else
        <div class="service-empty-state">
            <h2>No tienes servicios activos.</h2>
            <p>Puedes solicitar una nueva cita o turno cuando quieras.</p>
            <a class="btn btn-gold" href="{{ route('public.booking') }}">Solicitar nuevo servicio</a>
            <a class="service-recover-link" href="{{ route('my-services.recover') }}">¿Perdiste el acceso? Recupera tus servicios</a>
        </div>
    @endif
</section>
<section class="service-panel" id="services-historial" role="tabpanel" aria-labelledby="tab-historial" data-service-panel="historial" hidden>
    @if($hasHistory)
        @include('public.partials.my-services-group', ['groupKey' => 'history-citas', 'title' => 'Citas anteriores', 'subjects' => $historyAppointments, 'history' => true])
        @include('public.partials.my-services-group', ['groupKey' => 'history-turnos', 'title' => 'Turnos anteriores', 'subjects' => $historyTurns, 'history' => true])
    @else
        <div class="service-empty-state">
            <h2>Todavía no tienes servicios anteriores.</h2>
            <p>Cuando termines o canceles una reserva, aparecerá aquí para que puedas volver a reservarla.</p>
            <a class="service-recover-link" href="{{ route('my-services.recover') }}">¿Perdiste el acceso? Recupera tus servicios</a>
        </div>
    @endif
</section>
