@extends('layouts.public')

@php $seo = \App\Support\Seo::page('landing', $siteSettings); @endphp
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
    @php
        $heroTitle = trim($siteSettings['home_title'] ?? '');
        $heroParts = preg_split('/(?<=[,.;:])\s+/u', $heroTitle, 2);
        // Fondo de portada: el que sube el admin (Animaciones) → la primera imagen del inicio → la foto del local por defecto.
        $heroBg = !empty($siteSettings['hero_image_path']) ? asset('storage/'.$siteSettings['hero_image_path'])
            : ($slides->first() ? asset('storage/'.$slides->first()->image_path) : asset('images/portada-barberia.webp'));
        // Texto detrás del objeto: solo si hay una figura sin fondo fija en el código (public/images/portada-figura.png).
        // Los barberos ya no aparecen en el inicio.
        $heroCut = file_exists(public_path('images/portada-figura.png')) ? ['image_url' => asset('images/portada-figura.png')] : null;
    @endphp
    {{-- Portada cinematográfica: titular editorial a la izquierda y un "fotograma" a la derecha
         que se abre a pantalla completa al bajar (GSAP ScrollTrigger, ver public/js/cinema.js). --}}
    <section class="cx-hero {{ $heroCut ? 'has-cut' : '' }}" id="inicio" data-xh data-cx data-cx-hero aria-labelledby="xh-title">
        <div class="cx-hero-frame" data-cx-hero-frame aria-hidden="true">
            @if($heroBg)<img class="cx-hero-img" src="{{ $heroBg }}" alt="" width="1600" height="1000" fetchpriority="high" decoding="async">@endif
            @if($heroCut)<img class="cx-hero-cut" src="{{ $heroCut['image_url'] }}" alt="" width="800" height="1000" decoding="async">@endif
            <span class="cx-mark cx-mark--tl"></span><span class="cx-mark cx-mark--tr"></span><span class="cx-mark cx-mark--bl"></span><span class="cx-mark cx-mark--br"></span>
        </div>

        <p class="cx-eyebrow" data-cx-in>{{ $siteSettings['home_eyebrow'] ?: $siteSettings['tagline'] }}</p>


        <h1 class="cx-hero-title" id="xh-title" data-cx-hero-title>
            @foreach($heroParts as $i => $part)
                @php $accentWords = $i === 0 ? preg_split('/\s+/u', $part, 3) : []; @endphp
                <span class="cx-line"><span data-cx-line>@if($i === 0 && count($accentWords) >= 3)<em>{{ $accentWords[0] }} {{ $accentWords[1] }}</em> {{ $accentWords[2] }}@else{{ $part }}@endif</span></span>
            @endforeach
        </h1>

        <div class="cx-hero-body" data-cx-in>
            <p class="cx-hero-desc"><strong>{{ $siteSettings['brand_name'] }}</strong> — {{ $siteSettings['home_description'] }}</p>
            <div class="cx-hero-actions">
                <a href="{{ route('public.booking') }}" class="xbtn btn-booking-blue"><span>Reservar cita o turno</span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                <a href="{{ route('public.services') }}" class="xlink">Ver servicios</a>
            </div>
        </div>

        <a href="#experiencia" class="cx-hero-scroll" data-cx-in aria-label="Bajar al contenido"><span class="cx-hero-scroll-line" aria-hidden="true"></span>{{ $siteSettings['home_scroll_label'] ?: 'Desliza para explorar' }}</a>
    </section>

    <div id="nuestros-cortes"></div>

    {{-- Nuestros trabajos: TODAS las fotos de cortes de todos los barberos (en la página Barberos cada uno muestra solo las suyas).
         Fotos de cortes que suben los barberos desde el panel (Barberos → editar → "Cortes realizados")
         + fotos generales de la barbería en storage/app/public/trabajos.
         Solo si no hay ninguna, se muestran las fotos del local que trae el proyecto para que la galería no quede vacía. --}}
    @php
        $workItems = \App\Support\BarberCuts::all();
        if ($workItems->isEmpty()) {
            $workDisk = \Illuminate\Support\Facades\Storage::disk('public');
            $workItems = collect($workDisk->exists('home-slides') ? $workDisk->files('home-slides') : [])
                ->filter(fn ($path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true))
                ->sort()->values()
                ->map(function ($path) use ($workDisk) {
                    $size = @getimagesize($workDisk->path($path)) ?: [null, null];

                    return ['url' => asset('storage/'.$path), 'width' => $size[0], 'height' => $size[1]];
                });
        }
    @endphp
    @include('public.partials.work-gallery', [
        'galleryId' => 'nuestros-trabajos',
        'kicker' => 'Galería',
        'title' => 'Nuestros trabajos',
        'lead' => 'Cortes reales, hechos en nuestras sillas por el equipo.',
        'items' => $workItems,
    ])

    {{-- Escena con foto de fondo (equipo en la barbería): acompaña desde "Nuestra esencia" hasta "Visión".
         La foto queda fija mientras los textos pasan por encima; un velo oscuro mantiene la lectura.
         Imagen optimizada en public/images/esencia-fondo(.webp / -movil.webp / .jpg). --}}
    <div class="cx-scene" data-cx-scene>
        <div class="cx-scene-bg" aria-hidden="true">
            <picture>
                <source type="image/webp" media="(max-width: 767px)" srcset="{{ asset('images/esencia-fondo-movil.webp') }}">
                <source type="image/webp" srcset="{{ asset('images/esencia-fondo.webp') }}">
                <img src="{{ asset('images/esencia-fondo.jpg') }}" alt="" width="1672" height="941" fetchpriority="low" loading="lazy" decoding="async">
            </picture>
        </div>

    {{-- Frase: se ilumina palabra por palabra al hacer scroll --}}
    @php $quoteWords = preg_split('/\s+/', trim($siteSettings['home_quote'] ?? '')); @endphp
    <section class="home-quote" data-scroll-quote aria-label="Nuestra esencia">
        <div class="home-quote-sticky">
            <div class="container">
                <div class="section-kicker">Nuestra esencia</div>
                <blockquote class="home-quote-text">
                    <p>@foreach($quoteWords as $word)<span class="qw" style="--i: {{ $loop->index }}">{{ $word }}</span> @endforeach</p>
                </blockquote>
                <div class="home-quote-line" aria-hidden="true"><span></span></div>
            </div>
        </div>
    </section>

    {{-- Misión y visión: composición editorial, sin tarjetas --}}
    <section class="purpose" id="proposito" data-purpose aria-labelledby="purpose-title">
        <div class="container">
            <header class="purpose-head">
                <div class="section-kicker">Lo que nos mueve</div>
                <h2 class="purpose-title" id="purpose-title">Nuestro <em>propósito.</em></h2>
            </header>

            @foreach([['num' => '01', 'label' => 'Misión', 'text' => $siteSettings['mission']], ['num' => '02', 'label' => 'Visión', 'text' => $siteSettings['vision']]] as $item)
                <article class="purpose-row" data-purpose-row>
                    <div class="purpose-label">
                        <span class="purpose-num" aria-hidden="true">{{ $item['num'] }}</span>
                        <h3>{{ $item['label'] }}</h3>
                    </div>
                    <div class="purpose-body">
                        <span class="purpose-rule" aria-hidden="true"></span>
                        <p>{{ $item['text'] }}</p>
                    </div>
                    <span class="purpose-ghost" aria-hidden="true">{{ $item['label'] }}</span>
                </article>
            @endforeach
        </div>
    </section>
    </div>

    {{-- Opiniones publicadas, en una fila que se desplaza sola.
         Si no hay ninguna aprobada, la sección completa no aparece. --}}
    @php
        // Tarjetas: solo 4 y 5 estrellas. Promedio y total: todas las opiniones publicadas.
        $proofReviews = \App\Models\Review::featured()->with('service')->latest()->take(12)->get();
        $proofCount = \App\Models\Review::approved()->count();
        $proofAverage = $proofCount ? round((float) \App\Models\Review::approved()->avg('rating'), 1) : null;
    @endphp
    @if($proofReviews->isNotEmpty())
        <section class="cx-proof" aria-labelledby="cx-proof-title">
            <div class="container">
                <div class="cx-proof-head">
                    <div>
                        <div class="section-kicker">Reseñas</div>
                        <h2 class="cx-proof-title" id="cx-proof-title">Experiencias de nuestros <em>clientes.</em></h2>
                    </div>
                    <div class="cx-proof-score" role="img" aria-label="Calificación promedio {{ number_format($proofAverage, 1, ',', '') }} de 5, con {{ $proofCount }} reseñas">
                        <strong>{{ number_format($proofAverage, 1, ',', '') }}</strong>
                        <span class="cx-proof-stars" aria-hidden="true">@for($i = 1; $i <= 5; $i++)<i class="bi {{ $i <= round($proofAverage) ? 'bi-star-fill' : 'bi-star' }}"></i>@endfor</span>
                        <small>{{ $proofCount }} {{ $proofCount === 1 ? 'reseña' : 'reseñas' }}</small>
                    </div>
                </div>
            </div>
            @include('public.partials.reviews-row', ['reviews' => $proofReviews])
            <div class="container">
                <a href="{{ route('public.reviews') }}" class="xlink cx-proof-more">Ver todas las opiniones o dejar la tuya</a>
            </div>
        </section>
    @endif

    {{-- Cierre: invitación a reservar (enlaza al sistema de reservas existente) --}}
    <section class="cx-outro" data-cx data-cx-outro aria-labelledby="cx-outro-title">
        <div class="cx-outro-marquee" aria-hidden="true">
            <div data-cx-marquee>
                @foreach(range(1, 4) as $n)<span>{{ $siteSettings['tagline'] }}</span><span class="is-sep">✂</span>@endforeach
            </div>
        </div>
        <div class="container cx-outro-inner">
            <div class="section-kicker">Reservas</div>
            <h2 class="cx-outro-title" id="cx-outro-title">{{ $siteSettings['booking_hero_title'] }}</h2>
            <p class="cx-outro-lead">{{ $siteSettings['booking_hero_description'] }}</p>
            <div class="cx-outro-actions">
                <a href="{{ route('public.booking') }}" class="xbtn btn-booking-blue"><span>Reservar cita o turno</span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                <a href="{{ route('public.barbers') }}" class="xlink">Conocer al equipo</a>
            </div>
        </div>
    </section>
@endsection
