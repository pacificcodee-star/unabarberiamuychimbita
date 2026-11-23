@extends('layouts.public')

@section('title', 'Contacto · '.($siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita'))
@section('meta_description', $siteSettings['contact_hero_description'] ?? $siteSettings['footer_description'])

@php
    $fullAddress = trim($siteSettings['address'].', '.($siteSettings['city'] ?? ''), ', ');
    // Indicaciones desde la ubicación actual del visitante hasta la dirección del negocio.
    // Google Maps abre la app (Android/iOS) cuando está instalada y usa "Mi ubicación" como origen.
    $directionsUrl = 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($fullAddress).'&travelmode=driving&dir_action=navigate';
    // Mapa generado desde la dirección configurada para que nunca quede desfasado.
    $mapEmbedUrl = $fullAddress !== ''
        ? 'https://www.google.com/maps?q='.rawurlencode($fullAddress).'&z=17&hl=es&output=embed'
        : ($siteSettings['map_url'] ?? '');
    $contactBg = !empty($siteSettings['contact_image_path']) ? asset('storage/'.$siteSettings['contact_image_path']) : null;
@endphp

@section('content')
    <div class="contact-page">
    {{-- Encabezado editorial --}}
    <section class="ct-hero" aria-labelledby="ct-title">
        @if($contactBg)<div class="ct-hero-bg" aria-hidden="true"><img src="{{ $contactBg }}" alt="" width="1920" height="1080" fetchpriority="high" decoding="async"></div>@endif
        <div class="container">
            <h1 class="ct-title" id="ct-title">{{ $siteSettings['contact_hero_title'] }}</h1>
            <p class="ct-lead">{{ $siteSettings['contact_hero_description'] }}</p>
        </div>
    </section>

    @php
        $mapsKey = config('services.google_maps.key');
        $hourGroups = \App\Models\SiteSetting::groupedHours($siteSettings);
    @endphp
    <section class="ct-body" aria-label="Datos de contacto y ubicación">
        <div class="container">
            <div class="ct-grid">
                {{-- Horario semanal --}}
                @php
                    // Días con nombre completo ("Lun – Sáb" → "Lunes a sábado") y horas con raya tipográfica.
                    $dayNames = ['Lun' => 'lunes', 'Mar' => 'martes', 'Mié' => 'miércoles', 'Jue' => 'jueves', 'Vie' => 'viernes', 'Sáb' => 'sábado', 'Dom' => 'domingo'];
                    $dayLabel = function (string $label) use ($dayNames) {
                        $parts = array_map(fn ($p) => $dayNames[trim($p)] ?? trim($p), preg_split('/\s*[–-]\s*/u', $label));
                        $text = count($parts) > 1 ? $parts[0].' a '.end($parts) : $parts[0];

                        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
                    };
                    $niceHours = fn (string $value) => preg_replace('/\s+[-–]\s+/u', ' – ', trim($value));
                @endphp
                <aside class="ct-hours-card reveal" aria-labelledby="ct-hours-title">
                    <header class="ct-hours-head">
                        <h2 class="ct-hours-title" id="ct-hours-title">Horario</h2>
                    </header>

                    <dl class="ct-hours-list" aria-label="Horario de la semana">
                        @foreach($hourGroups as $group)
                            <div>
                                <dt>{{ $dayLabel($group['label']) }}</dt>
                                <dd class="{{ \App\Models\SiteSetting::isClosedText($group['value']) ? 'is-closed' : '' }}">{{ $niceHours($group['value']) }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <p class="ct-note"><i class="bi bi-info-circle" aria-hidden="true"></i><span><strong>Con cita</strong> te atendemos a tu hora. <strong>Sin cita</strong>, por orden de llegada.</span></p>
                    <a href="{{ route('public.booking') }}" class="xbtn"><span>Reservar</span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </aside>

                <div class="ct-info">
                    {{-- Canales de contacto: correo, visita, llamada y WhatsApp, con íconos SVG oficiales (public/images/contacto). --}}
                    <h2 class="ct-subtitle reveal">Escríbenos</h2>
                    <ul class="ct-channels">
                        <li class="reveal">
                            <a class="ct-channel" href="mailto:{{ $siteSettings['email'] }}">
                                <span class="ct-channel-icon">{!! file_get_contents(public_path('images/contacto/correo.svg')) !!}</span>
                                <span class="ct-channel-text"><small>Correo</small><strong>{{ $siteSettings['email'] }}</strong></span>
                                <i class="bi bi-arrow-up-right ct-channel-arrow" aria-hidden="true"></i>
                            </a>
                        </li>
                        <li class="reveal">
                            <a class="ct-channel" href="{{ $directionsUrl }}" target="_blank" rel="noopener">
                                <span class="ct-channel-icon">{!! file_get_contents(public_path('images/contacto/ubicacion.svg')) !!}</span>
                                <span class="ct-channel-text"><small>Visítanos</small><strong>{{ $siteSettings['address'] }}</strong><em>{{ $siteSettings['city'] }}</em></span>
                                <i class="bi bi-arrow-up-right ct-channel-arrow" aria-hidden="true"></i>
                            </a>
                        </li>
                        <li class="reveal">
                            <a class="ct-channel" href="tel:{{ $siteSettings['phone_link'] }}">
                                <span class="ct-channel-icon">{!! file_get_contents(public_path('images/contacto/telefono.svg')) !!}</span>
                                <span class="ct-channel-text"><small>Llámanos</small><strong>{{ $siteSettings['phone'] }}</strong></span>
                                <i class="bi bi-arrow-up-right ct-channel-arrow" aria-hidden="true"></i>
                            </a>
                        </li>
                        <li class="reveal">
                            <a class="ct-channel ct-channel--wa" href="https://wa.me/{{ $siteSettings['whatsapp'] }}?text={{ rawurlencode('Hola, quiero información para un corte') }}" target="_blank" rel="noopener">
                                <span class="ct-channel-icon"><img src="{{ asset('images/contacto/whatsapp.svg') }}" alt="" width="26" height="26"></span>
                                <span class="ct-channel-text"><small>WhatsApp</small><strong>Escríbenos ahora</strong></span>
                                <i class="bi bi-arrow-up-right ct-channel-arrow" aria-hidden="true"></i>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            {{-- Mapa de Google a todo el ancho, con la dirección en una ficha flotante.
                 Con GOOGLE_MAPS_API_KEY en el archivo .env se usa el mapa oficial (JavaScript API); sin clave, el mapa incrustado de Google. --}}
            <div class="ct-map-wrap reveal">
                <div class="ct-map">
                    @if($mapsKey)
                        <div id="ctGoogleMap" class="ct-gmap" role="region" aria-label="Mapa de Google con la ubicación de {{ $siteSettings['brand_name'] }}"
                             data-address="{{ $fullAddress }}" data-coords="{{ $siteSettings['map_coords'] ?? '' }}" data-title="{{ $siteSettings['brand_name'] }}"
                             data-directions="{{ $directionsUrl }}" data-map-id="{{ config('services.google_maps.map_id') }}"></div>
                        <noscript><iframe src="{{ $mapEmbedUrl }}" title="Ubicación de {{ $siteSettings['brand_name'] }} en Google Maps" loading="lazy"></iframe></noscript>
                    @else
                        <iframe src="{{ $mapEmbedUrl }}" title="Mapa de {{ $siteSettings['brand_name'] }} en {{ $fullAddress }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
                    @endif
                </div>
                <div class="ct-map-card">
                    <span class="ct-label">¿Dónde estamos?</span>
                    <strong>{{ $siteSettings['address'] }}</strong>
                    <span>{{ $siteSettings['city'] }}</span>
                    <a class="ct-map-pill" href="{{ $directionsUrl }}" target="_blank" rel="noopener" aria-label="Abrir indicaciones en Google Maps desde tu ubicación hasta {{ $fullAddress }}"><i class="bi bi-sign-turn-right-fill" aria-hidden="true"></i> Cómo llegar</a>
                </div>
            </div>
        </div>
    </section>
    </div>
@endsection

@push('styles')
<style>
/* Foto de la barbería de fondo, fija detrás del contenido y sin velo: se ve a todo color. */
.contact-page { position: relative; isolation: isolate; }
.contact-page::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100vh;
    height: 100lvh;
    z-index: -1;
    pointer-events: none;
    background: url('{{ asset('images/mis-servicios-fondo.webp') }}') center / cover no-repeat, #efe8dc;
    /* Espejada: el marco y el poste quedan a la derecha, y el título descansa sobre la pared lisa.
       Un desenfoque suave la separa del texto sin ocultarla. */
    transform: scaleX(-1) scale(1.04);
    filter: blur(3px);
}
.contact-page .ct-hero { background: transparent !important; }
/* Sin velo: la foto se ve completa; el halo claro solo separa el texto donde cruza zonas oscuras. */
.contact-page .ct-hero .ct-title,
.contact-page .ct-hero .ct-lead { text-shadow: 0 1px 2px rgba(250, 248, 243, .95), 0 0 4px rgba(250, 248, 243, .9), 0 0 22px rgba(250, 248, 243, .8); }
</style>
@endpush

@if(config('services.google_maps.key'))
@push('scripts')
<script>
    // Mapa oficial de Google: estilo oscuro de la marca y pin dorado en el local.
    window.initBarberMap = async () => {
        const el = document.getElementById('ctGoogleMap');
        if (!el) return;
        const { Map, InfoWindow } = await google.maps.importLibrary('maps');
        const dark = [
            { elementType: 'geometry', stylers: [{ color: '#1a1611' }] },
            { elementType: 'labels.text.fill', stylers: [{ color: '#b9ad97' }] },
            { elementType: 'labels.text.stroke', stylers: [{ color: '#120f0b' }] },
            { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#2b251d' }] },
            { featureType: 'road.arterial', elementType: 'geometry', stylers: [{ color: '#3a3226' }] },
            { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#5a4a30' }] },
            { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0e1418' }] },
            { featureType: 'poi', elementType: 'labels.icon', stylers: [{ visibility: 'off' }] },
            { featureType: 'poi.business', stylers: [{ visibility: 'off' }] },
            { featureType: 'transit', stylers: [{ visibility: 'off' }] },
        ];
        const mapId = el.dataset.mapId || null;
        const map = new Map(el, {
            zoom: 17, center: { lat: 4.65, lng: -74.06 }, disableDefaultUI: true, zoomControl: true, fullscreenControl: true,
            gestureHandling: 'cooperative', ...(mapId ? { mapId } : { styles: dark }),
        });
        const place = async () => {
            const [lat, lng] = (el.dataset.coords || '').split(',').map((v) => parseFloat(v));
            if (!Number.isNaN(lat) && !Number.isNaN(lng)) return { lat, lng };
            const { Geocoder } = await google.maps.importLibrary('geocoding');
            const { results } = await new Geocoder().geocode({ address: el.dataset.address });
            return results[0]?.geometry.location.toJSON();
        };
        try {
            const position = await place();
            if (!position) return;
            map.setCenter(position);
            const pin = { path: 'M12 0C7 0 3 4 3 9c0 6.6 9 15 9 15s9-8.4 9-15c0-5-4-9-9-9zm0 12.5A3.5 3.5 0 1 1 12 5.5a3.5 3.5 0 0 1 0 7z', fillColor: '#e0b45a', fillOpacity: 1, strokeColor: '#120f0b', strokeWeight: 1, scale: 2, anchor: new google.maps.Point(12, 24) };
            const marker = new google.maps.Marker({ map, position, title: el.dataset.title, icon: pin, animation: google.maps.Animation.DROP });
            const info = new InfoWindow({ content: `<div style="font:600 13px Montserrat,sans-serif;color:#111;padding:2px 4px">${el.dataset.title}<br><a href="${el.dataset.directions}" target="_blank" rel="noopener" style="color:#9a6b12;font-weight:600">Cómo llegar →</a></div>` });
            marker.addListener('click', () => info.open({ map, anchor: marker }));
        } catch (error) {
            console.warn('No se pudo ubicar la dirección en Google Maps:', error);
        }
    };
</script>
<script async src="https://maps.googleapis.com/maps/api/js?key={{ urlencode(config('services.google_maps.key')) }}&callback=initBarberMap&loading=async&language=es&region=CO"></script>
@endpush
@endif
