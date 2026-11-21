@extends('layouts.public')

@section('title', 'Reseñas · '.($siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita'))
@section('meta_description', $siteSettings['reviews_hero_description'] ?? $siteSettings['footer_description'])

@section('content')
    @include('public.partials.page-hero', [
        'crumb' => 'Reseñas',
        'title' => $siteSettings['reviews_hero_title'],
        'description' => $siteSettings['reviews_hero_description'],
    ])

    @if($reviews->isNotEmpty())
        <section class="rv-section" aria-labelledby="rv-list-title">
            <div class="container rv-head">
                <div>
                    <div class="section-kicker">Opiniones</div>
                    <h2 class="rv-title" id="rv-list-title">Lo que dicen en <em>la silla.</em></h2>
                </div>
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

    <section class="rv-form-section" id="opinar" aria-labelledby="rv-form-title">
        <div class="container">
            <div class="rv-form-grid">
                <div class="rv-form-intro">
                    <div class="section-kicker">Tu opinión</div>
                    <h2 class="rv-title" id="rv-form-title">¿Cómo te fue <em>en tu visita?</em></h2>
                    <p>Cuéntanos tu experiencia.</p>
                    @if(config('web.google.review_url'))
                        <a href="{{ config('web.google.review_url') }}" class="xlink" target="_blank" rel="noopener">También puedes calificarnos en Google</a>
                    @endif
                </div>

                @if(session('review_sent'))
                    <div class="rv-thanks" role="status">
                        <span class="rv-thanks-icon" aria-hidden="true"><i class="bi bi-check2"></i></span>
                        <h3>¡Gracias por tu opinión!</h3>
                        <p>Recibimos tu opinión.</p>
                        <a href="{{ route('public.booking') }}" class="xbtn"><span>Reservar de nuevo</span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </div>
                @else
                    <form class="rv-form" method="POST" action="{{ route('public.reviews.store') }}" novalidate>
                        @csrf
                        {{-- Campo trampa para robots (invisible para las personas) --}}
                        <div class="rv-hp" aria-hidden="true"><label>No llenar <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                        <fieldset class="rv-rating @error('rating') is-invalid @enderror">
                            <legend>Tu calificación</legend>
                            <div class="rv-rating-stars">
                                @for($i = 5; $i >= 1; $i--)
                                    <input type="radio" id="rv-star-{{ $i }}" name="rating" value="{{ $i }}" @checked((int) old('rating', 5) === $i)>
                                    <label for="rv-star-{{ $i }}" title="{{ $i }} {{ $i === 1 ? 'estrella' : 'estrellas' }}">
                                        <svg viewBox="0 0 24 24" width="34" height="34" aria-hidden="true"><path d="M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.4l-5.8 3.1 1.1-6.5L2.6 9.4l6.5-.9z"/></svg>
                                        <span class="visually-hidden">{{ $i }} {{ $i === 1 ? 'estrella' : 'estrellas' }}</span>
                                    </label>
                                @endfor
                            </div>
                            @error('rating')<p class="rv-error">{{ $message }}</p>@enderror
                        </fieldset>

                        <div class="rv-field">
                            <label for="rv-name">Tu nombre</label>
                            <input id="rv-name" name="client_name" type="text" value="{{ old('client_name') }}" maxlength="60" autocomplete="given-name" required @error('client_name') aria-invalid="true" aria-describedby="rv-name-error" @enderror>
                            @error('client_name')<p class="rv-error" id="rv-name-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="rv-field">
                            <label for="rv-comment">Tu opinión</label>
                            <textarea id="rv-comment" name="comment" rows="4" minlength="10" maxlength="600" required placeholder="¿Qué te gustó del corte, la atención, el ambiente…?" @error('comment') aria-invalid="true" aria-describedby="rv-comment-error" @enderror>{{ old('comment') }}</textarea>
                            @error('comment')<p class="rv-error" id="rv-comment-error">{{ $message }}</p>@enderror
                        </div>

                        <button type="submit" class="btn btn-gold btn-lg rv-submit"><i class="bi bi-send" aria-hidden="true"></i> Enviar mi opinión</button>
                        <p class="form-legal-note">Tu opinión se revisa antes de publicarse. Al enviarla aceptas la <a href="{{ route('legal.privacy') }}">política de privacidad</a>.</p>
                    </form>
                @endif
            </div>
        </div>
    </section>
@endsection
