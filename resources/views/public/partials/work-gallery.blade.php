{{--
    Galería de trabajos tipo "carrete de cine" (inicio y perfil de cada barbero).

    Parámetros:
      $galleryId   id único de la sección
      $kicker      rótulo pequeño
      $title       titular
      $lead        texto de apoyo (opcional)
      $items       colección de fotos: ['url', 'width', 'height', 'barber' (opcional)]
      $hidden      true si arranca oculta (barberos que no están en pantalla)
      $emptyText   mensaje cuando no hay fotos

    Todas las tarjetas miden lo mismo. La foto se muestra COMPLETA dentro de la tarjeta (sin estirarla
    ni recortarla); el espacio que sobre se rellena con la misma foto desenfocada.
    Se recorre con las flechas, arrastrando, deslizando con el dedo o con el teclado (public/js/immersive.js).
--}}
@php $items = collect($items ?? []); @endphp
<section class="wg {{ $items->isEmpty() ? 'is-empty' : '' }}" id="{{ $galleryId }}" data-cx data-wg @if(!empty($hidden)) hidden @endif aria-labelledby="{{ $galleryId }}-title">
    <div class="wg-stage">
        {{-- Titular gigante detrás de las tarjetas: las fotos tapan parte de las letras (capas con profundidad).
             Se puede apagar con 'ghost' => false (galerías de cada barbero). --}}
        @if($ghost ?? true)
            <div class="wg-ghost" data-wg-ghost aria-hidden="true">{{ $title }}</div>
        @endif

        <header class="wg-head">
            <div class="section-kicker">{{ $kicker }}</div>
            <h2 class="wg-title" id="{{ $galleryId }}-title" data-cx-chars>{{ $title }}</h2>
            @if(!empty($lead))<p class="wg-lead">{{ $lead }}</p>@endif
        </header>

        @if($items->isNotEmpty())
            <div class="wg-reel" data-wg-reel tabindex="0" role="region" aria-roledescription="carrusel"
                 aria-label="{{ $title }}: {{ $items->count() }} {{ $items->count() === 1 ? 'foto' : 'fotos' }}. Usa las flechas para recorrerlas.">
                <ul class="wg-cards" data-wg-cards>
                    @foreach($items as $item)
                        @php $alt = !empty($item['barber']) ? 'Corte realizado por '.$item['barber'] : 'Trabajo realizado en la barbería'; @endphp
                        <li class="wg-card" data-wg-item>
                            <figure class="wg-frame">
                                <span class="wg-blur" aria-hidden="true" style="background-image: url('{{ $item['url'] }}');"></span>
                                <img src="{{ $item['url'] }}" alt="{{ $alt }}" loading="{{ $loop->index < 4 ? 'eager' : 'lazy' }}" decoding="async" draggable="false">
                                <span class="wg-shine" aria-hidden="true"></span>
                                @if(!empty($item['barber']))
                                    <figcaption class="wg-caption"><span>Por</span> {{ $item['barber'] }}</figcaption>
                                @endif
                            </figure>
                        </li>
                    @endforeach
                </ul>
            </div>

            @if($items->count() > 1)
                <div class="wg-controls">
                    <button type="button" class="wg-arrow" data-wg-prev aria-label="Foto anterior"><i class="bi bi-arrow-left" aria-hidden="true"></i></button>
                    <div class="wg-progress" aria-hidden="true"><span data-wg-bar></span></div>
                    <button type="button" class="wg-arrow" data-wg-next aria-label="Foto siguiente"><i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                </div>
            @endif
        @else
            <p class="wg-empty">{{ $emptyText ?? 'Muy pronto verás aquí nuestros trabajos.' }}</p>
        @endif
    </div>
</section>
