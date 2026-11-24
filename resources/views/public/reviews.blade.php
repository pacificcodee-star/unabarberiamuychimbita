@extends('layouts.public')

@php $seo = \App\Support\Seo::page('reviews', $siteSettings); @endphp
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
    @include('public.partials.page-hero', [
        'crumb' => 'Reseñas',
        'title' => 'Experiencias de nuestros clientes.',
    ])

    @if($reviews->isNotEmpty())
        <section class="rv-section" aria-label="Opiniones publicadas">
            <div class="container rv-head">
                <div class="section-kicker">Opiniones</div>
                <div class="rv-score" role="img" aria-label="Calificación promedio {{ number_format($averageRating, 1, ',', '') }} de 5, con {{ $reviewCount }} {{ $reviewCount === 1 ? 'opinión' : 'opiniones' }}">
                    <strong>{{ number_format($averageRating, 1, ',', '') }}</strong>
                    <span class="rv-score-meta">
                        <span class="rv-stars" aria-hidden="true">@for($i = 1; $i <= 5; $i++)<svg viewBox="0 0 24 24" width="18" height="18" class="{{ $i <= round($averageRating) ? 'is-on' : '' }}"><path d="M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.4l-5.8 3.1 1.1-6.5L2.6 9.4l6.5-.9z"/></svg>@endfor</span>
                        <small>{{ $reviewCount }} {{ $reviewCount === 1 ? 'opinión' : 'opiniones' }}</small>
                    </span>
                </div>
            </div>
            @include('public.partials.reviews-row', ['reviews' => $reviews])
        </section>
    @endif

    {{-- Las opiniones llegan solo por la encuesta de WhatsApp, después de atenderse: aquí no hay formulario. --}}
    <section class="rv-form-section" aria-labelledby="rv-how-title">
        <div class="container">
            <div class="rv-form-intro">
                <div class="section-kicker">Tu experiencia</div>
                <h2 class="rv-title" id="rv-how-title">¿Te atendimos <em>hace poco?</em></h2>
                <p>Cuando tu cita o turno queda atendido, te escribimos por WhatsApp para que nos cuentes cómo te fue. Tu opinión solo se publica si tú lo autorizas.</p>
                @if(config("web.google.review_url"))
                    <a href="{{ config("web.google.review_url") }}" class="xlink" target="_blank" rel="noopener">También puedes calificarnos en Google</a>
                @endif
            </div>
        </div>
    </section>
@endsection
