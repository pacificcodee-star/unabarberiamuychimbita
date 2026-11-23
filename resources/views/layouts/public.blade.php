<!DOCTYPE html>
<html lang="es">
<head>
    {{-- Red de seguridad: reporta errores de JS y mantiene el contenido visible si fallan las animaciones. --}}
    <script src="{{ asset('js/error-guard.js') }}?v={{ @filemtime(public_path('js/error-guard.js')) }}"></script>
    <script>
        // Pantalla de carga solo la primera vez que se entra al sitio en esta pestaña: no al cambiar de
        // página dentro del sitio ni al recargar después (así recargar o volver no hace esperar otra vez).
        (function () {
            window.addEventListener('beforeinstallprompt', function (event) {
                event.preventDefault();
                window.deferredPwaInstall = event;
            });
            var internal = false, seen = false;
            try { internal = !!document.referrer && new URL(document.referrer).origin === location.origin; } catch (e) {}
            try { seen = sessionStorage.getItem('ubmc-intro') === '1'; sessionStorage.setItem('ubmc-intro', '1'); } catch (e) {}
            if (!internal && !seen) document.documentElement.classList.add('is-loading');
        })();
        // Transiciones entre páginas con dirección: según el orden del menú se avanza (izquierda) o se regresa (derecha).
        // Debe ir en el <head>, antes de que la página se pinte. Los navegadores sin esta función cambian de página normalmente.
        (function () {
            var order = ['/', '/servicios', '/barberos', '/contacto', '/reservar'];
            var place = function (url) { try { return order.indexOf((new URL(url).pathname.replace(/\/+$/, '')) || '/'); } catch (e) { return -1; } };
            var mark = function (transition, from, to) {
                if (!transition || !transition.types || !from || !to) return;
                var a = place(from), b = place(to);
                transition.types.add(a < 0 || b < 0 || a === b ? 'nav-fade' : (b > a ? 'nav-forward' : 'nav-back'));
            };
            window.addEventListener('pageswap', function (e) { if (e.activation && e.activation.from) mark(e.viewTransition, e.activation.from.url, e.activation.entry.url); });
            window.addEventListener('pagereveal', function (e) { var act = window.navigation && navigation.activation; if (act && act.from) mark(e.viewTransition, act.from.url, act.entry.url); });
        })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $brandName = $siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita';
        $pageTitle = trim($__env->yieldContent('title', $brandName.' · Más que un corte, una experiencia'));
        $metaDescription = trim($__env->yieldContent('meta_description', $siteSettings['footer_description'] ?? 'Cortes clásicos y modernos, barba, color y una experiencia pensada para ti.'));
        $logoUrl = !empty($siteSettings['logo_path']) ? asset('storage/'.$siteSettings['logo_path']) : asset('images/logo-una-barberia.png');
        // Versión liviana (128 px, 11 KB) para el menú, el pie y la pantalla de carga.
        $logoSmall = !empty($siteSettings['logo_path']) ? $logoUrl : asset('images/logo-una-barberia-128.webp');
        $shareImage = trim($__env->yieldContent('og_image')) ?: asset('images/og-image.jpg');
        $robots = trim($__env->yieldContent('robots', 'index, follow'));
        $ga4Id = config('web.ga4_id');
        $cssVersion = file_exists(public_path('css/site.css')) ? filemtime(public_path('css/site.css')) : '1';
        $navItems = [
            ['route' => 'landing', 'label' => 'Inicio', 'icon' => 'bi-house-door'],
            ['route' => 'public.services', 'label' => 'Servicios', 'icon' => 'bi-scissors'],
            ['route' => 'public.barbers', 'label' => 'Barberos', 'icon' => 'bi-person-badge'],
            ['route' => 'public.contact', 'label' => 'Contacto', 'icon' => 'bi-geo-alt'],
        ];
        if (\App\Support\ClientServiceAccess::hasServices(request())) {
            $navItems[] = ['route' => 'my-services.index', 'label' => 'Mis servicios', 'icon' => 'bi-calendar2-check'];
        }
    @endphp
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="robots" content="{{ $robots }}">
    @if(config('web.google.site_verification'))<meta name="google-site-verification" content="{{ config('web.google.site_verification') }}">@endif
    <meta name="color-scheme" content="dark">
    <link rel="canonical" href="{{ url()->current() }}">
    @include('partials.favicon')
    {{-- App instalable (PWA) --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#0c0a08">
    {{-- Chrome/Edge: no muestran la página nueva hasta tener la barra inferior (sin parpadeo al cambiar de pestaña). --}}
    <link rel="expect" href="#mobileBottomNav" blocking="render">
    <meta name="vapid-public-key" content="{{ config('services.webpush.public_key') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Barbería Chimbita">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="es_CO">
    <meta property="og:site_name" content="{{ $brandName }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $shareImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $brandName }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">
    <meta name="twitter:image" content="{{ $shareImage }}">
    <link rel="sitemap" type="application/xml" href="{{ route('sitemap') }}">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..800;1,9..144,300..800&family=JetBrains+Mono:wght@400..700&family=Manrope:wght@300..800&display=swap" rel="stylesheet">
    <link href="{{ asset('css/site.css') }}?v={{ $cssVersion }}" rel="stylesheet">
    {{-- Identidad visual cinematográfica: tokens, tipografía y componentes (se carga después de site.css) --}}
    <link href="{{ asset('css/cinema.css') }}?v={{ file_exists(public_path('css/cinema.css')) ? filemtime(public_path('css/cinema.css')) : '1' }}" rel="stylesheet">
    <script>document.documentElement.classList.add('js');</script>
    <style>
        /* Campana de avisos del cliente */
        .nav-bell { position: relative; display: grid; width: 45px; height: 45px; place-items: center; border: 1px solid var(--line-strong); border-radius: 999px; background: transparent; color: var(--text); font-size: 1.15rem; cursor: pointer; transition: border-color .2s ease, color .2s ease; }
        .nav-bell:hover, .nav-bell[aria-expanded="true"] { border-color: var(--gold); color: var(--gold); }
        .nav-bell-badge { position: absolute; top: -4px; right: -4px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 999px; background: var(--brand-red); color: #fff; font-size: .7rem; font-weight: 700; line-height: 20px; text-align: center; }
        .bell-panel { position: fixed; z-index: 1085; top: 76px; right: max(12px, calc((100vw - 1296px) / 2)); width: min(380px, calc(100vw - 24px)); max-height: min(72vh, 560px); display: flex; flex-direction: column; background: var(--surface-2); border: 1px solid var(--line-strong); border-radius: 16px; box-shadow: 0 24px 60px rgba(0, 0, 0, .55); color: var(--text); overflow: hidden; }
        .bell-panel[hidden] { display: none; }
        /* Velo con desenfoque: el fondo queda fuera de foco y el panel de avisos se mantiene nítido delante. */
        .bell-backdrop { position: fixed; inset: 0; z-index: 1080; background: rgba(8, 7, 5, .5); -webkit-backdrop-filter: blur(10px) saturate(.85); backdrop-filter: blur(10px) saturate(.85); touch-action: none; overscroll-behavior: contain; }
        .bell-backdrop[hidden] { display: none; }
        .bell-item-top strong { min-width: 0; }
        .bell-item-top strong, .bell-group-head strong, .bell-item p, .bell-detail { overflow-wrap: anywhere; }
        @media (max-width: 575.98px) {
            /* En el celular la campana abre una tarjeta compacta pegada al lateral derecho. */
            .bell-panel { top: calc(env(safe-area-inset-top, 0px) + 72px); left: auto; right: 12px; width: min(320px, calc(100vw - 72px)); max-height: min(58svh, 400px); }
        }
        .bell-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--line); }
        .bell-panel-head h2 { margin: 0; font-size: 1rem; }
        .bell-panel-head button { border: 0; background: transparent; color: inherit; font-size: 1rem; padding: 4px 6px; cursor: pointer; }
        .bell-panel-body { overflow-y: auto; overscroll-behavior: contain; padding: 6px 16px 14px; }
        .bell-empty { padding: 26px 8px; text-align: center; opacity: .9; }
        .bell-empty i { font-size: 1.8rem; color: var(--gold); }
        .bell-empty p { margin: 8px 0 14px; }
        .bell-group { padding: 12px 0; border-bottom: 1px solid var(--line); }
        .bell-group:last-child { border-bottom: 0; }
        .bell-group-head { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; color: var(--text); text-decoration: none; }
        .bell-group-head:hover strong { color: var(--gold); }
        .bell-group-head span { font-size: .78rem; padding: 2px 9px; border: 1px solid var(--line-strong); border-radius: 999px; white-space: nowrap; }
        .bell-detail, .bell-none { margin: 4px 0 8px; font-size: .85rem; opacity: .8; }
        .bell-item { padding: 9px 11px; margin-top: 6px; background: var(--surface-3); border-radius: 10px; }
        .bell-item-top { display: flex; justify-content: space-between; gap: 10px; font-size: .88rem; }
        .bell-item-top time { font-size: .76rem; opacity: .7; white-space: nowrap; }
        .bell-item p { margin: 3px 0 0; font-size: .84rem; opacity: .88; }
        /* Caja de activación de avisos (página de seguimiento y confirmación de reserva) */
        .track-push { padding: 1.1rem 1.25rem; border: 1px solid var(--line-strong); border-radius: 14px; margin-bottom: 1.25rem; }
        .track-push h2 { font-size: 1.05rem; margin: 0 0 .4rem; }
        .track-push p { margin: 0 0 .85rem; opacity: .88; }
        .track-push ul { margin: 0 0 .85rem; padding-left: 1.1rem; opacity: .88; }
        .track-push .is-on { display: flex; align-items: center; gap: .5rem; font-weight: 600; margin-bottom: .6rem; }
    </style>
    @stack('styles')
    @include('partials.speculation-rules')
</head>
<body data-content-version="{{ $contentVersion ?? '' }}">
{{-- Pantalla de carga: contador gigante con franjas de barbero que sube hasta 100 y se abre como puertas --}}
<div class="pl" id="pageLoader" role="status" aria-live="polite" aria-label="Cargando la página">
    <div class="pl-door pl-door--top" aria-hidden="true"></div>
    <div class="pl-door pl-door--bottom" aria-hidden="true"></div>
    <div class="pl-stage {{ !empty($siteSettings['loader_video_path']) ? 'has-video' : '' }}" aria-hidden="true">
        @if(!empty($siteSettings['loader_video_path']))
            <video class="pl-video" src="{{ asset('storage/'.$siteSettings['loader_video_path']) }}" muted autoplay loop playsinline preload="auto"></video>
        @endif
        <div class="pl-count {{ !empty($siteSettings['loader_image_path']) ? 'has-img' : '' }}" @if(!empty($siteSettings['loader_image_path'])) style="--pl-img: url('{{ asset('storage/'.$siteSettings['loader_image_path']) }}')" @endif><span data-pl-num>00</span></div>
    </div>
    <div class="pl-foot" aria-hidden="true">
        <span class="pl-brand"><img src="{{ $logoSmall }}" alt="" width="28" height="28">{{ $siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita' }}</span>
        <span class="pl-bar"><span data-pl-bar></span></span>
        <span class="pl-tag">{{ $siteSettings['tagline'] ?? '' }}</span>
    </div>
</div>
<script>
(() => {
    const root = document.documentElement;
    const loader = document.getElementById('pageLoader');
    const num = loader.querySelector('[data-pl-num]');
    const bar = loader.querySelector('[data-pl-bar]');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // Solo al abrir la página por primera vez; al cambiar de pestaña no se muestra.
    if (!root.classList.contains('is-loading')) { loader.remove(); return; }
    const minTime = reduced ? 400 : 3600;
    const start = performance.now();
    let loaded = document.readyState === 'complete', shown = 0, done = false;
    window.addEventListener('load', () => { loaded = true; });
    const finish = () => {
        if (done) return; done = true;
        num.textContent = '100'; bar.style.transform = 'scaleX(1)';
        loader.classList.add('is-done');
        root.classList.remove('is-loading');
        setTimeout(() => loader.remove(), reduced ? 400 : 2000);
    };
    const tick = (now) => {
        if (done) return;
        const t = Math.min(1, (now - start) / minTime);
        const eased = t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; // conteo parejo, sin saltos
        const target = loaded ? 100 * eased : Math.min(92, 100 * eased);
        shown += (target - shown) * 0.12;
        const value = Math.min(100, Math.round(shown));
        num.textContent = String(value).padStart(2, '0');
        bar.style.transform = `scaleX(${(value / 100).toFixed(3)})`;
        if (loaded && t >= 1 && value >= 99) { setTimeout(finish, 450); return; }
        requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
    setTimeout(finish, 10000); // nunca dejar la página bloqueada
    // Al volver con el botón "atrás" (bfcache) no se muestra de nuevo.
    window.addEventListener('pageshow', (e) => { if (e.persisted) finish(); });
})();
</script>
<a class="skip-link" href="#main-content">Saltar al contenido</a>
{{-- Navegación inferior flotante (móvil). Va al inicio del <body> a propósito: así existe desde el primer
     cuadro de cada página y no desaparece un instante al cambiar de pestaña. --}}
@include('public.partials.mobile-bottom-nav')
@php
    // Datos estructurados (schema.org) para Google: negocio local con horario, ubicación y ficha.
    $toTime = function (string $text): ?string {
        // "9:00 a. m." → 09:00 · "8:00 p. m." → 20:00 · "18:30" → 18:30
        if (! preg_match('/(\d{1,2})(?::(\d{2}))?\s*([ap])?/iu', $text, $m)) return null;
        $hour = (int) $m[1];
        $meridiem = strtolower($m[3] ?? '');
        if ($meridiem === 'p' && $hour < 12) $hour += 12;
        if ($meridiem === 'a' && $hour === 12) $hour = 0;
        return $hour <= 23 ? sprintf('%02d:%02d', $hour, (int) ($m[2] ?? 0)) : null;
    };
    $schemaDays = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
    $openingHours = [];
    foreach ($schemaDays as $key => $dayName) {
        $text = (string) ($siteSettings['hours_'.$key] ?? '');
        if (\App\Models\SiteSetting::isClosedText($text)) continue;
        $parts = preg_split('/\s*[–—-]\s*(?=\d)/u', $text);
        $opens = isset($parts[0]) ? $toTime($parts[0]) : null;
        $closes = isset($parts[1]) ? $toTime($parts[1]) : null;
        if ($opens && $closes) {
            $openingHours[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/'.$dayName, 'opens' => $opens, 'closes' => $closes];
        }
    }
    [$lat, $lng] = array_pad(array_map('trim', explode(',', (string) ($siteSettings['map_coords'] ?? ''))), 2, null);
    // Se omiten los enlaces genéricos de redes (p. ej. https://www.instagram.com/ sin usuario).
    $profileLinks = array_filter([
        $siteSettings['instagram_url'] ?? null,
        $siteSettings['facebook_url'] ?? null,
        $siteSettings['tiktok_url'] ?? null,
        config('web.google.business_url'),
    ], fn ($link) => filled($link) && trim((string) parse_url($link, PHP_URL_PATH), '/') !== '');
    $businessSchema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'HairSalon',
        '@id' => url('/').'#negocio',
        'name' => $brandName,
        'url' => url('/'),
        'logo' => $logoUrl,
        'image' => [asset('images/og-image.jpg'), $logoUrl],
        'description' => $siteSettings['footer_description'] ?? '',
        'telephone' => $siteSettings['phone'] ?? '',
        'email' => $siteSettings['email'] ?? '',
        'priceRange' => '$$',
        'currenciesAccepted' => 'COP',
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => $siteSettings['address'] ?? '',
            'addressLocality' => trim(explode(',', (string) ($siteSettings['city'] ?? ''))[0]),
            'addressCountry' => 'CO',
        ],
        'geo' => is_numeric($lat) && is_numeric($lng) ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng] : null,
        'hasMap' => config('web.google.business_url') ?: 'https://www.google.com/maps/search/?api=1&query='.urlencode($brandName.' '.($siteSettings['address'] ?? '').' '.($siteSettings['city'] ?? '')),
        'openingHoursSpecification' => $openingHours ?: null,
        'potentialAction' => ['@type' => 'ReserveAction', 'target' => route('public.booking'), 'name' => 'Reservar cita o turno'],
        'sameAs' => array_values($profileLinks) ?: null,
    ]);
@endphp
<script type="application/ld+json">@json($businessSchema)</script>
@stack('schema')

<header class="site-nav" id="siteNav">
    <div class="container">
        <div class="nav-inner">
            <a href="{{ route('landing') }}" class="landing-brand" aria-label="{{ $brandName }} — Inicio">
                <span class="mark"><img src="{{ $logoSmall }}" alt="" width="42" height="42"></span>
                <span class="min-w-0">
                    <span class="name">{{ $brandName }}</span>
                </span>
            </a>

            <nav class="nav-links" id="navLinks" aria-label="Navegación principal">
                <span class="nav-indicator" aria-hidden="true"></span>
                @foreach($navItems as $item)
                    @php $isActive = request()->routeIs($item['route']); @endphp
                    <a href="{{ route($item['route']) }}" class="{{ $isActive ? 'active' : '' }}" @if($isActive) aria-current="page" @endif>
                        <span class="nav-label">{{ $item['label'] }}</span>
                    </a>
                @endforeach
                <div class="nav-mobile-extra">
                    <a href="{{ route('public.booking') }}" class="btn btn-gold btn-lg btn-booking-blue"><i class="bi bi-calendar2-check" aria-hidden="true"></i> Reservar cita o turno</a>
                    @if(!empty($siteSettings['whatsapp']))
                        <a href="https://wa.me/{{ $siteSettings['whatsapp'] }}" class="btn btn-ghost-light" target="_blank" rel="noopener"><i class="bi bi-whatsapp" aria-hidden="true"></i> Escríbenos</a>
                    @endif
                    <p>{{ $siteSettings['address'] }}, {{ $siteSettings['city'] }} · Hoy: {{ \App\Models\SiteSetting::todayHours($siteSettings) }}</p>
                </div>
            </nav>

            <div class="nav-cta">
                @php $isBookingPage = request()->routeIs('public.booking'); @endphp
                <a href="{{ route('public.booking') }}" class="btn btn-gold btn-sm nav-booking {{ $isBookingPage ? 'active' : '' }}" aria-label="Reservar cita o turno" @if($isBookingPage) aria-current="page" @endif>
                    <i class="bi bi-calendar2-check" aria-hidden="true"></i><span>Reservar</span>
                </a>
                <button class="nav-bell" id="clientBell" type="button" aria-label="Avisos" aria-haspopup="dialog" aria-controls="clientBellPanel" aria-expanded="false">
                    <i class="bi bi-bell" aria-hidden="true"></i>
                    <span class="nav-bell-badge" data-bell-badge hidden>0</span>
                </button>
                <button class="menu-toggle" id="menuToggle" type="button" aria-label="Abrir menú" aria-controls="navLinks" aria-expanded="false">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
</header>

<div class="bell-backdrop" id="clientBellBackdrop" hidden></div>
<div class="bell-panel" id="clientBellPanel" role="dialog" aria-label="Tus avisos" tabindex="-1" data-booking-url="{{ route('public.booking') }}" hidden>
    <div class="bell-panel-head">
        <h2><i class="bi bi-bell" aria-hidden="true"></i> Tus avisos</h2>
        <button type="button" data-bell-close aria-label="Cerrar avisos"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="bell-panel-body" data-bell-body></div>
</div>

<main id="main-content" tabindex="-1">
    @yield('content')
</main>

<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div>
                <a href="{{ route('landing') }}" class="landing-brand">
                    <span class="mark"><img src="{{ $logoSmall }}" alt="" width="42" height="42" loading="lazy"></span>
                    <span class="min-w-0">
                        <span class="name">{{ $brandName }}</span>
                        @if(!empty($siteSettings['tagline']))<span class="tag">{{ $siteSettings['tagline'] }}</span>@endif
                    </span>
                </a>
                <p>{{ $siteSettings['footer_description'] }}</p>
                @include('public.partials.social-links', ['class' => 'social-links'])
            </div>
            <div>
                <div class="footer-title">Visítanos</div>
                <ul class="footer-list">
                    <li><i class="bi bi-geo-alt" aria-hidden="true"></i><span>{{ $siteSettings['address'] }}, {{ $siteSettings['city'] }}</span></li>
                    @if(!empty($siteSettings['phone']))<li><i class="bi bi-telephone" aria-hidden="true"></i><a href="tel:{{ $siteSettings['phone_link'] ?? $siteSettings['phone'] }}">{{ $siteSettings['phone'] }}</a></li>@endif
                    @if(!empty($siteSettings['email']))<li><i class="bi bi-envelope" aria-hidden="true"></i><a href="mailto:{{ $siteSettings['email'] }}">{{ $siteSettings['email'] }}</a></li>@endif
                    <li><i class="bi bi-clock" aria-hidden="true"></i><span>@foreach(\App\Models\SiteSetting::groupedHours($siteSettings) as $group){{ $group['label'] }}: {{ $group['value'] }}@unless($loop->last)<br>@endunless @endforeach</span></li>
                </ul>
            </div>
            <div>
                <div class="footer-title">Medios de pago</div>
                <div class="payment-methods" role="list" aria-label="Medios de pago aceptados">
                    @if($siteSettings['payment_cash'])<span class="payment-method" role="listitem">
                        <img class="payment-icon-cash" src="{{ asset('images/pagos/efectivo.png') }}" alt="" width="28" height="28" loading="lazy" decoding="async">
                        Efectivo
                    </span>@endif
                    @if($siteSettings['payment_nequi'])<span class="payment-method payment-method--logo" role="listitem" aria-label="Nequi" title="Nequi">
                        <span class="payment-logo payment-logo--big"><img src="{{ asset('images/pagos/nequi.svg') }}" alt="Nequi" width="72" height="32" loading="lazy" decoding="async"></span>
                    </span>@endif
                    @if($siteSettings['payment_daviplata'])<span class="payment-method payment-method--logo" role="listitem" aria-label="Daviplata" title="Daviplata">
                        <span class="payment-logo payment-logo--big"><img src="{{ asset('images/pagos/daviplata.svg') }}" alt="Daviplata" width="72" height="32" loading="lazy" decoding="async"></span>
                    </span>@endif
                    @if($siteSettings['payment_breb'] ?? true)<span class="payment-method payment-method--logo" role="listitem" aria-label="Bre-b" title="Bre-b">
                        <span class="payment-logo payment-logo--big"><img src="{{ asset('images/pagos/breb.svg') }}" alt="Bre-b" width="72" height="32" loading="lazy" decoding="async"></span>
                    </span>@endif
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <span class="footer-copy">&copy; {{ now()->year }} {{ $brandName }}. Todos los derechos reservados.</span>
            <nav class="footer-legal" aria-label="Información legal">
                <a href="{{ route('my-services.recover') }}">Recuperar mis servicios</a>
                <a href="{{ route('legal.notice') }}">Aviso legal</a>
                <a href="{{ route('legal.privacy') }}">Privacidad</a>
                <button type="button" class="footer-legal-btn" data-cookie-settings>Preferencias de cookies</button>
            </nav>
        </div>
    </div>
</footer>

@include('public.partials.legal-modals')

@if(!empty($siteSettings['whatsapp']))
    <a class="whatsapp-float" href="https://wa.me/{{ $siteSettings['whatsapp'] }}" target="_blank" rel="noopener noreferrer" aria-label="Escríbenos por WhatsApp" title="Escríbenos por WhatsApp">
        <span class="whatsapp-float-icon" aria-hidden="true"><i class="bi bi-whatsapp"></i></span>
        <span class="whatsapp-float-label">WhatsApp</span>
    </a>
@endif

{{-- Aviso de cookies: las necesarias siempre; la analítica solo si el visitante acepta. --}}
<div class="cookie-banner" id="cookieBanner" role="region" aria-label="Aviso de cookies" hidden>
    <p>Usamos cookies necesarias para que el sitio funcione{{ $ga4Id ? ' y, si aceptas, cookies de analítica para saber qué páginas se usan más' : '' }}. <a href="{{ route('legal.cookies') }}">Más información</a></p>
    <div class="cookie-banner-actions">
        @if($ga4Id)
            <button type="button" class="cookie-btn cookie-btn--ghost" data-cookie-choice="rejected">Solo necesarias</button>
            <button type="button" class="cookie-btn" data-cookie-choice="accepted">Aceptar</button>
        @else
            <button type="button" class="cookie-btn" data-cookie-choice="rejected">Entendido</button>
        @endif
    </div>
</div>
<script>
(() => {
    const KEY = 'cookie-consent';
    const GA4 = @json($ga4Id);
    const banner = document.getElementById('cookieBanner');
    const read = () => { try { return localStorage.getItem(KEY); } catch (e) { return null; } };
    const save = (v) => { try { localStorage.setItem(KEY, v); } catch (e) {} };

    // Google Analytics 4: se carga solo con consentimiento. Sin ID en .env no se carga nunca.
    let gaLoaded = false;
    const loadAnalytics = () => {
        if (!GA4 || gaLoaded) return;
        gaLoaded = true;
        window.dataLayer = window.dataLayer || [];
        window.gtag = function () { dataLayer.push(arguments); };
        gtag('js', new Date());
        gtag('config', GA4, { anonymize_ip: true });
        const s = document.createElement('script');
        s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA4);
        document.head.appendChild(s);
    };
    // Las páginas pueden registrar eventos (p. ej. reserva hecha) sin saber si hay consentimiento.
    window.trackEvent = (name, params) => { if (gaLoaded && window.gtag) gtag('event', name, params || {}); };

    const show = () => {
        // Espera a que termine la pantalla de carga para no taparla.
        const wait = () => document.documentElement.classList.contains('is-loading') ? setTimeout(wait, 300) : (banner.hidden = false);
        wait();
    };
    const choice = read();
    if (choice === 'accepted') loadAnalytics();
    else if (!choice) show();

    banner.querySelectorAll('[data-cookie-choice]').forEach((btn) => btn.addEventListener('click', () => {
        const value = btn.dataset.cookieChoice;
        save(value);
        banner.hidden = true;
        if (value === 'accepted') loadAnalytics();
        // Si retira el permiso, se borran las cookies de Google y se recarga sin analítica.
        if (value === 'rejected' && gaLoaded) {
            document.cookie.split(';').map((c) => c.split('=')[0].trim()).filter((n) => n.startsWith('_ga'))
                .forEach((n) => { document.cookie = n + '=; Max-Age=0; path=/; domain=.' + location.hostname.replace(/^www\./, ''); document.cookie = n + '=; Max-Age=0; path=/'; });
            location.reload();
        }
    }));
    // Contactos directos como conversiones (solo llegan a Analytics si hubo consentimiento).
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a[href]');
        if (!link) return;
        const href = link.getAttribute('href');
        if (href.startsWith('https://wa.me/')) window.trackEvent('whatsapp_click', { link_url: location.pathname });
        else if (href.startsWith('tel:')) window.trackEvent('phone_click', { link_url: location.pathname });
    });
    document.addEventListener('click', (e) => {
        if (!e.target.closest('[data-cookie-settings]')) return;
        banner.hidden = false;
        banner.querySelector('[data-cookie-choice]')?.focus();
    });
})();
</script>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<script>
    (() => {
        const nav = document.getElementById('siteNav');
        const navLinks = document.getElementById('navLinks');
        const menuToggle = document.getElementById('menuToggle');
        const desktopMenu = window.matchMedia('(min-width: 1100px)');

        const updateNav = () => nav?.classList.toggle('scrolled', window.scrollY > 24);
        updateNav();
        window.addEventListener('scroll', updateNav, { passive: true });

        const setMenu = (open, returnFocus = false) => {
            if (!navLinks || !menuToggle) return;
            navLinks.classList.toggle('show', open);
            nav.classList.toggle('menu-open', open);
            document.body.classList.toggle('nav-locked', open);
            menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            menuToggle.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
            const icon = menuToggle.querySelector('i');
            icon?.classList.toggle('bi-list', !open);
            icon?.classList.toggle('bi-x-lg', open);
            if (open) navLinks.querySelector('a')?.focus();
            if (!open && returnFocus) menuToggle.focus();
        };

        menuToggle?.addEventListener('click', () => setMenu(!navLinks.classList.contains('show')));

        // Indicador dorado que se desliza entre las pestañas (solo escritorio).
        const indicator = navLinks?.querySelector('.nav-indicator');
        const links = navLinks ? [...navLinks.querySelectorAll(':scope > a')] : [];
        const activeLink = links.find((link) => link.classList.contains('active'));
        const moveIndicator = (link) => {
            if (!indicator || !desktopMenu.matches) return;
            if (!link) { indicator.style.opacity = '0'; return; }
            indicator.style.opacity = '1';
            indicator.style.width = `${link.offsetWidth}px`;
            indicator.style.transform = `translateX(${link.offsetLeft}px)`;
        };
        links.forEach((link) => {
            link.addEventListener('mouseenter', () => moveIndicator(link));
            link.addEventListener('focus', () => moveIndicator(link));
        });
        navLinks?.addEventListener('mouseleave', () => moveIndicator(activeLink));
        navLinks?.addEventListener('focusout', (event) => { if (!navLinks.contains(event.relatedTarget)) moveIndicator(activeLink); });
        // La campana mide de ancho lo mismo que de alto, así queda igual de alta que las otras cápsulas y redonda.
        const navBell = document.getElementById('clientBell');
        const sizeBell = () => {
            if (!navBell) return;
            navBell.style.width = '';
            if (desktopMenu.matches) navBell.style.width = `${navBell.offsetHeight}px`;
        };
        const placeIndicator = () => { indicator?.classList.add('no-anim'); moveIndicator(activeLink); sizeBell(); requestAnimationFrame(() => indicator?.classList.remove('no-anim')); };
        window.addEventListener('resize', placeIndicator);
        document.fonts?.ready.then(placeIndicator);
        if ('ResizeObserver' in window && navLinks) {
            const navSizer = new ResizeObserver(placeIndicator);
            navSizer.observe(navLinks);
            links.forEach((link) => navSizer.observe(link));
        }
        placeIndicator();
        navLinks?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setMenu(false)));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && navLinks?.classList.contains('show')) setMenu(false, true);
        });
        desktopMenu.addEventListener?.('change', (event) => { if (event.matches) setMenu(false); });

        const revealItems = document.querySelectorAll('.reveal');
        if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
            revealItems.forEach((element, index) => {
                element.style.setProperty('--reveal-delay', `${(index % 3) * 80}ms`);
                observer.observe(element);
            });
        } else {
            revealItems.forEach((element) => element.classList.add('visible'));
        }

        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const bindCarouselAutoplay = (carousel, advance) => {
            const control = carousel.querySelector('[data-carousel-toggle]');
            let timer;
            let pausedByVisitor = false;
            let hovered = false;
            let focused = false;

            const stop = () => window.clearInterval(timer);
            const updateControl = () => {
                if (!control) return;
                const label = pausedByVisitor ? 'Reanudar' : 'Pausar';
                control.setAttribute('aria-pressed', pausedByVisitor ? 'true' : 'false');
                control.setAttribute('aria-label', `${label} ${control.dataset.carouselLabel || 'presentación'}`);
                const text = control.querySelector('span');
                if (text) text.textContent = label;
                const icon = control.querySelector('i');
                icon?.classList.toggle('bi-play-fill', pausedByVisitor);
                icon?.classList.toggle('bi-pause-fill', !pausedByVisitor);
            };
            const schedule = () => {
                stop();
                if (pausedByVisitor || hovered || focused || document.hidden || reducedMotion.matches) return;
                timer = window.setInterval(advance, 5500);
            };

            control?.addEventListener('click', () => {
                pausedByVisitor = !pausedByVisitor;
                if (!pausedByVisitor) focused = false;
                updateControl();
                schedule();
            });
            carousel.addEventListener('mouseenter', () => { hovered = true; schedule(); });
            carousel.addEventListener('mouseleave', () => { hovered = false; schedule(); });
            carousel.addEventListener('focusin', () => { focused = true; schedule(); });
            carousel.addEventListener('focusout', (event) => {
                if (!carousel.contains(event.relatedTarget)) { focused = false; schedule(); }
            });
            document.addEventListener('visibilitychange', schedule);
            reducedMotion.addEventListener?.('change', schedule);
            schedule();
        };

        document.querySelectorAll('[data-home-carousel]').forEach((carousel) => {
            const slides = [...carousel.querySelectorAll('.hero-slide')];
            const dots = [...carousel.querySelectorAll('[data-slide-to]')];
            if (slides.length < 2) return;

            let current = 0;
            const showSlide = (index) => {
                slides[current].classList.remove('active');
                slides[current].setAttribute('aria-hidden', 'true');
                dots[current]?.classList.remove('active');
                dots[current]?.setAttribute('aria-pressed', 'false');
                current = index;
                slides[current].classList.add('active');
                slides[current].setAttribute('aria-hidden', 'false');
                dots[current]?.classList.add('active');
                dots[current]?.setAttribute('aria-pressed', 'true');
            };

            dots.forEach((dot) => dot.addEventListener('click', () => showSlide(Number(dot.dataset.slideTo))));
            bindCarouselAutoplay(carousel, () => showSlide((current + 1) % slides.length));
        });
    })();
</script>
<script src="{{ asset('js/alpha-video.js') }}?v={{ file_exists(public_path('js/alpha-video.js')) ? filemtime(public_path('js/alpha-video.js')) : '1' }}"></script>

{{-- Aviso discreto para instalar la app (PWA): solo la invitación a instalar. --}}
<div class="pwa-prompt" id="pwaPrompt" role="dialog" aria-modal="false" aria-label="Instala nuestra app" hidden>
    <div class="pwa-prompt-text">
        <span data-pwa-msg>Instala nuestra app</span>
        <span data-pwa-status role="status" aria-live="polite" hidden></span>
    </div>
    <div class="pwa-prompt-actions">
        <button type="button" class="pwa-btn" data-pwa-install hidden>Instalar</button>
        <button type="button" class="pwa-btn pwa-btn-notify" data-pwa-notify data-staff-push-url="{{ auth()->check() ? route('staff.push.store') : '' }}" hidden>Activar avisos</button>
        <button type="button" class="pwa-close" data-pwa-close aria-label="Ahora no"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
</div>
<script>
(() => {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', async () => {
            try {
                // updateViaCache: 'none' hace que el navegador revise sw.js en cada carga:
                // así las mejoras de avisos y PWA llegan sin reinstalar la app.
                const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' });
                registration.update().catch(() => {});
            } catch (e) {}
        });
    }

    const box = document.getElementById('pwaPrompt');
    if (!box) return;

    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const supported = 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
    const KEY = 'pwa-dismissed-until';
    const KEY_PUSH = 'pwa-push-active';
    const snoozedUntil = () => { try { return Number(localStorage.getItem(KEY) || 0) > Date.now(); } catch (e) { return false; } };

    const installBtn = box.querySelector('[data-pwa-install]');
    const notifyBtn = box.querySelector('[data-pwa-notify]');
    const status = box.querySelector('[data-pwa-status]');
    const msg = box.querySelector('[data-pwa-msg]');
    const setStatus = (text) => { if (status) { status.textContent = text; status.hidden = !text; } };
    let deferred = window.deferredPwaInstall || null;
    let shown = false;

    const bytes = (base64) => {
        const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
    };

    const show = (force) => {
        if (shown && !force) return;
        if (standalone || snoozedUntil()) return;
        // Solo se muestra cuando de verdad hay algo que instalar.
        if (!deferred && !isIOS) return;
        const wait = () => document.documentElement.classList.contains('is-loading') ? setTimeout(wait, 250) : setTimeout(() => {
            if (standalone || snoozedUntil()) return;
            shown = true;
            box.hidden = false;
            requestAnimationFrame(() => box.classList.add('is-visible'));
        }, force ? 0 : 250);
        wait();
    };
    const hide = (days) => {
        box.classList.remove('is-visible');
        setTimeout(() => { box.hidden = true; }, 350);
        try { localStorage.setItem(KEY, String(Date.now() + days * 864e5)); } catch (e) {}
    };

    // ¿Este dispositivo ya tiene los avisos activos? Entonces no se vuelven a pedir.
    const currentSubscription = async () => {
        if (!supported || Notification.permission !== 'granted') return null;
        try {
            const registration = await navigator.serviceWorker.ready;
            let subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                const vapid = @json(config('services.webpush.public_key'));
                if (!vapid) return null;
                subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes(vapid) });
            }
            return subscription;
        } catch (e) {
            return null;
        }
    };

    const markActive = () => {
        notifyBtn.textContent = 'Avisos activos';
        notifyBtn.disabled = true;
        notifyBtn.hidden = true;
        try { localStorage.setItem(KEY_PUSH, '1'); } catch (e) {}
        try { localStorage.setItem(KEY, String(Date.now() + 365 * 864e5)); } catch (e) {}
    };

    const registerStaff = async (subscription) => {
        const staffUrl = notifyBtn.dataset.staffPushUrl;
        if (!staffUrl) return false;
        const encodings = PushManager.supportedContentEncodings || ['aes128gcm'];
        const payload = Object.assign(subscription.toJSON(), { contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm' });
        const response = await fetch(staffUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify(payload),
        });
        if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || 'No se pudo registrar este dispositivo.');
        return true;
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferred = event;
        installBtn.hidden = false;
        if (shown) show(true);
        else setTimeout(() => show(true), 12000);
    });
    installBtn.addEventListener('click', async () => {
        if (deferred) {
            deferred.prompt();
            const { outcome } = await deferred.userChoice;
            deferred = null;
            hide(outcome === 'accepted' ? 365 : 30);
        } else if (isIOS) {
            hide(30);
        } else {
            setStatus('La instalación no está disponible en este navegador.');
        }
    });
    notifyBtn.addEventListener('click', async () => {
        if (!supported) {
            setStatus('Este navegador no admite avisos push.');
            return;
        }
        if (isIOS && !standalone) {
            setStatus('En iPhone, agrega primero la página al inicio desde Safari. Después abre la app instalada y activa los avisos.');
            return;
        }
        notifyBtn.setAttribute('aria-disabled', 'true');
        setStatus('Esperando permiso del navegador…');
        try {
            const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
            if (permission !== 'granted') {
                setStatus(permission === 'denied' ? 'Los avisos quedaron bloqueados en el navegador.' : 'Puedes activar los avisos cuando quieras.');
                return;
            }
            const subscription = await currentSubscription();
            if (!subscription) throw new Error('El navegador no pudo crear la suscripción de avisos.');
            const saved = await registerStaff(subscription);
            setStatus(saved ? 'Avisos activados para las notificaciones del equipo.' : 'Avisos activos en este dispositivo. Al reservar los vinculamos a tu cita.');
            markActive();
            setTimeout(() => hide(365), 2400);
        } catch (error) {
            setStatus(error.message || 'No se pudieron activar los avisos.');
        } finally {
            notifyBtn.removeAttribute('aria-disabled');
        }
    });
    box.querySelector('[data-pwa-close]').addEventListener('click', () => hide(30));
    window.addEventListener('appinstalled', () => hide(365));

    // Ya activos antes de entrar: se deja solo la opción de instalar (si el navegador la ofrece).
    (async () => {
        const existing = await currentSubscription();
        if (existing) markActive();
        if (standalone) return;

        // El aviso se muestra con calma, cuando la página ya se disfrutó, y nunca si ya hay avisos activos
        // sin nada que instalar.
        if (!isIOS && !deferred && existing) return;

        if (isIOS && !standalone) {
            msg.innerHTML = 'En Safari, toca <i class="bi bi-box-arrow-up" aria-label="Compartir"></i> <strong>Compartir</strong> y elige <strong>“Agregar a inicio”</strong>.';
            installBtn.textContent = 'Entendido';
            installBtn.hidden = false;
        }
        if (snoozedUntil()) return;
        setTimeout(() => show(true), isIOS ? 8000 : 12000);
    })();
})();
</script>
<script src="{{ asset('js/chime.js') }}?v={{ @filemtime(public_path('js/chime.js')) }}" defer></script>
<script src="{{ asset('js/notify-permission.js') }}?v={{ @filemtime(public_path('js/notify-permission.js')) }}" defer></script>
<style>
    /* Aviso "Activa las notificaciones" (js/notify-permission.js): solo si aún no están activadas. */
    .notify-ask { position: fixed; z-index: 1300; left: 50%; bottom: 20px; display: flex; align-items: center; gap: 12px; width: min(560px, calc(100vw - 24px)); padding: 12px 12px 12px 14px; border: 1px solid rgba(var(--accent-rgb), .35); border-radius: 18px; background: rgba(20, 17, 13, .96); color: var(--text); box-shadow: 0 22px 50px rgba(0, 0, 0, .45); transform: translate(-50%, 16px); opacity: 0; transition: transform .32s var(--ease-out), opacity .32s ease; }
    .notify-ask.is-visible { transform: translate(-50%, 0); opacity: 1; }
    .notify-ask-ico { flex: none; display: grid; place-items: center; width: 40px; height: 40px; border-radius: 12px; background: rgba(var(--accent-rgb), .16); color: var(--gold); }
    .notify-ask-text { flex: 1; min-width: 0; display: grid; gap: 2px; }
    .notify-ask-text strong { font-size: .92rem; }
    .notify-ask-text span { font-size: .8rem; color: var(--text-muted); line-height: 1.35; }
    .notify-ask-yes { flex: none; min-height: 40px; padding: 0 16px; border: 0; border-radius: 999px; background: var(--button-gold); color: #14110d; font-weight: 800; font-size: .82rem; }
    .notify-ask-yes:hover { background: var(--button-gold-hover); }
    .notify-ask-no { flex: none; display: grid; place-items: center; width: 34px; height: 34px; border: 0; border-radius: 50%; background: transparent; color: var(--text-muted); }
    .notify-ask-no:hover { background: rgba(var(--text-rgb), .08); color: var(--text); }
    @media (max-width: 767.98px) { .notify-ask { bottom: calc(var(--mnav-clear, 86px) + 12px); } }
    @media (max-width: 420px) { .notify-ask { flex-wrap: wrap; } .notify-ask-text { flex-basis: calc(100% - 100px); } .notify-ask-yes { flex: 1; } }
    @media (prefers-reduced-motion: reduce) { .notify-ask { transition: none; } }
</style>
<script src="{{ asset('js/client-bell.js') }}?v={{ @filemtime(public_path('js/client-bell.js')) }}" defer></script>
@stack('scripts')
{{-- Scroll cinematográfico: GSAP + ScrollTrigger sobre el scroll nativo (copias locales en public/vendor) --}}
<script src="{{ asset('vendor/gsap.min.js') }}?v=3.15.1" defer onerror="document.documentElement.classList.add('cx-static')"></script>
<script src="{{ asset('vendor/ScrollTrigger.min.js') }}?v=3.15.1" defer></script>
<script src="{{ asset('js/cinema.js') }}?v={{ file_exists(public_path('js/cinema.js')) ? filemtime(public_path('js/cinema.js')) : '1' }}" defer onerror="document.documentElement.classList.add('cx-static')"></script>
<script src="{{ asset('js/immersive.js') }}?v={{ file_exists(public_path('js/immersive.js')) ? filemtime(public_path('js/immersive.js')) : '1' }}" defer></script>
<script src="{{ asset('js/motion.js') }}?v={{ file_exists(public_path('js/motion.js')) ? filemtime(public_path('js/motion.js')) : '1' }}" defer></script>
{{-- Refresco automático: los cambios hechos en el panel se ven sin recargar a mano. --}}
<script src="{{ asset('js/live-content.js') }}?v={{ file_exists(public_path('js/live-content.js')) ? filemtime(public_path('js/live-content.js')) : '1' }}" defer></script>
</body>
</html>
