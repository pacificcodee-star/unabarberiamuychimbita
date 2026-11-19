{{--
    Fila de opiniones que se desplaza sola de derecha a izquierda (se detiene al pasar el mouse o con el teclado).
    Con 3 o más opiniones la fila se repite para que el movimiento sea continuo; con menos, quedan quietas.
    Parámetros: $reviews (colección de opiniones aprobadas).
--}}
@php
    $rowReviews = collect($reviews ?? []);
    $moving = $rowReviews->count() >= 3;
@endphp
@if($rowReviews->isNotEmpty())
    <div class="rv-row {{ $moving ? 'is-moving' : 'is-static' }}" style="--rv-count: {{ $rowReviews->count() }}" data-rv-row>
        <div class="rv-track" role="list" aria-label="Opiniones de clientes">
            @foreach($rowReviews as $review)
                <div role="listitem">@include('public.partials.review-card', ['review' => $review])</div>
            @endforeach
            @if($moving)
                {{-- Copia solo visual para que la fila no tenga cortes (los lectores de pantalla la ignoran). --}}
                @foreach($rowReviews as $review)
                    <div aria-hidden="true" inert>@include('public.partials.review-card', ['review' => $review])</div>
                @endforeach
            @endif
        </div>
        @if($moving)
            <button type="button" class="rv-pause" data-rv-pause aria-pressed="false"><i class="bi bi-pause-fill" aria-hidden="true"></i><span>Pausar opiniones</span></button>
        @endif
    </div>
@endif
