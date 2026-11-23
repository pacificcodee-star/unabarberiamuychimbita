@extends('layouts.public')

@section('title', 'Página no encontrada · '.($siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita'))
@section('meta_description', 'La página que buscas no existe o cambió de dirección.')
@section('robots', 'noindex, follow')

@section('content')
    <section class="err-page" aria-labelledby="err-title">
        <div class="container">
            <p class="err-code" aria-hidden="true">404</p>
            <h1 id="err-title">Este corte no está en <em>la carta.</em></h1>
            <p>La página que buscas no existe o cambió de dirección. Revisa el enlace o sigue desde aquí.</p>
            <div class="err-actions">
                <a href="{{ route('public.booking') }}" class="xbtn btn-booking-blue"><span>Reservar cita o turno</span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                <a href="{{ route('landing') }}" class="xlink">Volver al inicio</a>
            </div>
            <ul class="err-links" aria-label="Páginas del sitio">
                <li><a href="{{ route('public.services') }}">Servicios y precios</a></li>
                <li><a href="{{ route('public.barbers') }}">Barberos</a></li>
                <li><a href="{{ route('public.reviews') }}">Reseñas</a></li>
                <li><a href="{{ route('public.contact') }}">Contacto y horario</a></li>
            </ul>
        </div>
    </section>
@endsection
