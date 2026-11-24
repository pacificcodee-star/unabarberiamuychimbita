@extends('layouts.public')

@php $seo = \App\Support\Seo::page('about', $siteSettings); @endphp
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
    @include('public.partials.page-hero', [
        'crumb' => 'Nosotros',
        'title' => $siteSettings['about_hero_title'],
        'description' => $siteSettings['about_hero_description'],
    ])

    <section class="section" aria-labelledby="about-title">
        <div class="container">
            <div class="row align-items-center g-4 g-lg-5">
                <div class="col-lg-6">
                    <div class="about-visual reveal">
                        <div class="about-photo" role="img" aria-label="El equipo y el espacio de la barbería" style="background-image: url('{{ !empty($siteSettings['about_image_path']) ? asset('storage/'.$siteSettings['about_image_path']) : asset('storage/home-slides/01-equipo.jpg') }}');"></div>
                        <div class="about-badge">
                            <strong>Tu estilo.</strong>
                            <span>Tu espacio. Tu momento.</span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="reveal">
                        <div class="section-kicker">Quiénes somos</div>
                        <h2 class="section-title" id="about-title">{{ $siteSettings['about_title'] }}</h2>
                        <p class="section-lead mb-3">{{ $siteSettings['about_paragraph_1'] }}</p>
                        <p class="section-lead">{{ $siteSettings['about_paragraph_2'] }}</p>
                        <div class="about-promises">
                            <span><i class="bi bi-check2" aria-hidden="true"></i> Atención cercana</span>
                            <span><i class="bi bi-check2" aria-hidden="true"></i> Estilo a tu manera</span>
                            <span><i class="bi bi-check2" aria-hidden="true"></i> Cada detalle cuenta</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section section--surface" aria-labelledby="purpose-title">
        <div class="container">
            <div class="section-head reveal">
                <div>
                    <div class="section-kicker">Lo que nos mueve</div>
                    <h2 class="section-title" id="purpose-title">Una forma de <em>hacer las cosas.</em></h2>
                    <p class="section-lead">{{ $siteSettings['about_motivation_description'] }}</p>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="mission-vision reveal">
                        <div class="mv-icon"><i class="bi bi-compass" aria-hidden="true"></i></div>
                        <h3>Misión</h3>
                        <p>{{ $siteSettings['mission'] }}</p>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="mission-vision reveal">
                        <div class="mv-icon"><i class="bi bi-eye" aria-hidden="true"></i></div>
                        <h3>Visión</h3>
                        <p>{{ $siteSettings['vision'] }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="values-title">
        <div class="container">
            <div class="section-head reveal">
                <div>
                    <div class="section-kicker">Nuestros valores</div>
                    <h2 class="section-title" id="values-title">Lo que se siente <em>en cada visita.</em></h2>
                </div>
            </div>
            <div class="row g-4">
                @foreach(range(1, 4) as $valueIndex)
                    <div class="col-sm-6 col-lg-3">
                        <div class="value-card reveal">
                            <i class="bi {{ ['bi-brush', 'bi-rulers', 'bi-chat-heart', 'bi-stars'][$valueIndex - 1] }}" aria-hidden="true"></i>
                            <h3>{{ $siteSettings['value_'.$valueIndex.'_title'] }}</h3>
                            <p>{{ $siteSettings['value_'.$valueIndex.'_description'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="section pt-0">
        <div class="container">
            <div class="cta-band reveal">
                <div class="row align-items-center g-4">
                    <div class="col-lg-8">
                        <div class="section-kicker">Ven a conocernos</div>
                        <h2>Tu próximo look empieza con una buena conversación.</h2>
                        <p>Reserva con el barbero que prefieras o toma tu turno por orden de llegada.</p>
                    </div>
                    <div class="col-lg-4">
                        <div class="cta-band-actions">
                            <a href="{{ route('public.booking') }}" class="btn btn-gold btn-lg btn-booking-blue"><i class="bi bi-calendar2-check" aria-hidden="true"></i> Reservar ahora</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
