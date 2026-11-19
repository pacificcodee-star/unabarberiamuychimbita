<!DOCTYPE html>
<html lang="es">
<head>
    <script>
        // Pantalla de carga solo al entrar al sitio o al recargar; no al cambiar de pestaña dentro del sitio.
        (function () {
            window.addEventListener('beforeinstallprompt', function (event) {
                event.preventDefault();
                window.deferredPwaInstall = event;
            });
            var internal = false, reload = false;
            try { internal = !!document.referrer && new URL(document.referrer).origin === location.origin; } catch (e) {}
            try { var nav = performance.getEntriesByType('navigation')[0]; reload = !!nav && nav.type === 'reload'; } catch (e) {}
            if (!internal || reload) document.documentElement.classList.add('is-loading');
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
    @php
        $brandName = $siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita';
        $pageTitle = trim($__env->yieldContent('title', $brandName.' · Más que un corte, una experiencia'));
        $metaDescription = trim($__env->yieldContent('meta_description', $siteSettings['footer_description'] ?? 'Cortes clásicos y modernos, barba, color y una experiencia pensada para ti.'));
        $logoUrl = !empty($siteSettings['logo_path']) ? asset('storage/'.$siteSettings['logo_path']) : asset('images/logo-una-barberia.png');
        $cssVersion = file_exists(public_path('css/site.css')) ? filemtime(public_path('css/site.css')) : '1';
        $navItems = [
            ['route' => 'landing', 'label' => 'Inicio', 'icon' => 'bi-house-door'],
            ['route' => 'public.services', 'label' => 'Servicios', 'icon' => 'bi-scissors'],
            ['route' => 'public.barbers', 'label' => 'Barberos', 'icon' => 'bi-person-badge'],
            ['route' => 'public.contact', 'label' => 'Contacto', 'icon' => 'bi-geo-alt'],
        ];
    @endphp
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="color-scheme" content="dark">
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/logo-una-barberia.png') }}">
    {{-- App instalable (PWA) --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#0c0a08">
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
    <meta property="og:image" content="{{ $logoUrl }}">
    <meta name="twitter:card" content="summary">
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
        .nav-bell { position: relative; display: grid; width: 46px; height: 46px; place-items: center; border: 1px solid var(--line-strong); border-radius: 50%; background: transparent; color: var(--text); font-size: 1.15rem; cursor: pointer; transition: border-color .2s ease, color .2s ease; }
        .nav-bell:hover, .nav-bell[aria-expanded="true"] { border-color: var(--gold); color: var(--gold); }
        .nav-bell-badge { position: absolute; top: -4px; right: -4px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 999px; background: var(--brand-red); color: #fff; font-size: .7rem; font-weight: 700; line-height: 20px; text-align: center; }
        .bell-panel { position: fixed; z-index: 1085; top: 76px; right: max(12px, calc((100vw - 1296px) / 2)); width: min(380px, calc(100vw - 24px)); max-height: min(72vh, 560px); display: flex; flex-direction: column; background: var(--surface-2); border: 1px solid var(--line-strong); border-radius: 16px; box-shadow: 0 24px 60px rgba(0, 0, 0, .55); color: var(--text); overflow: hidden; }
        .bell-panel[hidden] { display: none; }
        .bell-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--line); }
        .bell-panel-head h2 { margin: 0; font-size: 1rem; }
        .bell-panel-head button { border: 0; background: transparent; color: inherit; font-size: 1rem; padding: 4px 6px; cursor: pointer; }
        .bell-panel-body { overflow-y: auto; padding: 6px 16px 14px; }
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
</head>
<body>
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
        <span class="pl-brand"><img src="{{ $logoUrl }}" alt="" width="28" height="28">{{ $siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita' }}</span>
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
@php
    $businessSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'HairSalon',
        'name' => $brandName,
        'url' => url('/'),
        'image' => $logoUrl,
        'description' => $siteSettings['footer_description'] ?? '',
        'telephone' => $siteSettings['phone'] ?? '',
        'email' => $siteSettings['email'] ?? '',
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => $siteSettings['address'] ?? '',
            'addressLocality' => $siteSettings['city'] ?? '',
            'addressCountry' => 'CO',
        ],
        'sameAs' => array_values(array_filter([
            $siteSettings['instagram_url'] ?? null,
            $siteSettings['facebook_url'] ?? null,
            $siteSettings['tiktok_url'] ?? null,
        ])),
    ];
@endphp
<script type="application/ld+json">@json($businessSchema)</script>

<header class="site-nav" id="siteNav">
    <div class="container">
        <div class="nav-inner">
            <a href="{{ route('landing') }}" class="landing-brand" aria-label="{{ $brandName }} — Inicio">
                <span class="mark"><img src="{{ $logoUrl }}" alt="" width="42" height="42"></span>
                <span class="min-w-0">
                    <span class="name">{{ $brandName }}</span>
                    @if(!empty($siteSettings['tagline']))<span class="tag">{{ $siteSettings['tagline'] }}</span>@endif
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
                    <a href="{{ route('public.booking') }}" class="btn btn-gold btn-lg"><i class="bi bi-calendar2-check" aria-hidden="true"></i> Reservar cita o turno</a>
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

<div class="bell-panel" id="clientBellPanel" role="dialog" aria-label="Tus avisos" data-booking-url="{{ route('public.booking') }}" hidden>
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
                    <span class="mark"><img src="{{ $logoUrl }}" alt="" width="42" height="42" loading="lazy"></span>
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
                        <svg viewBox="0 0 40 28" aria-hidden="true"><rect x="2" y="3" width="36" height="22" rx="4" fill="#1d2a20" stroke="#c9a227"/><path d="M5 9h30M8 14h5m14 0h5M10 20h20" stroke="#c9a227" stroke-width="1.5" stroke-linecap="round"/><text x="20" y="18" text-anchor="middle" fill="#fff" font-size="8" font-family="Arial" font-weight="700">$</text></svg>
                        Efectivo
                    </span>@endif
                    @if($siteSettings['payment_transfer'])<span class="payment-method" role="listitem">
                        <svg viewBox="0 0 40 28" aria-hidden="true"><path d="M4 11 20 3l16 8M7 12h26M9 13v9m7-9v9m8-9v9m7-9v9M5 23h30" fill="none" stroke="#c9a227" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Transferencia
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
            <span>&copy; {{ now()->year }} {{ $brandName }}. Todos los derechos reservados.</span>
            <span><a href="{{ route('login') }}" class="footer-staff-link">Acceso del equipo</a></span>
        </div>
    </div>
</footer>

@if(!empty($siteSettings['whatsapp']))
    <a class="whatsapp-float" href="https://wa.me/{{ $siteSettings['whatsapp'] }}" target="_blank" rel="noopener noreferrer" aria-label="Escríbenos por WhatsApp" title="Escríbenos por WhatsApp">
        <span class="whatsapp-float-icon" aria-hidden="true"><i class="bi bi-whatsapp"></i></span>
        <span class="whatsapp-float-label">WhatsApp</span>
    </a>
@endif

{{-- Navegación inferior flotante (móvil): usa las mismas rutas públicas que el encabezado. --}}
@include('public.partials.mobile-bottom-nav')

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
        const placeIndicator = () => { indicator?.classList.add('no-anim'); moveIndicator(activeLink); requestAnimationFrame(() => indicator?.classList.remove('no-anim')); };
        window.addEventListener('resize', placeIndicator);
        document.fonts?.ready.then(placeIndicator);
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

{{-- Aviso para instalar la app (PWA) --}}
<div class="pwa-prompt" id="pwaPrompt" role="dialog" aria-modal="false" aria-labelledby="pwaTitle" hidden>
    <img src="{{ asset('icons/icon-192.png') }}" alt="" width="52" height="52">
    <div class="pwa-prompt-text">
        <strong id="pwaTitle">Instala la app de {{ $siteSettings['brand_name'] ?? 'la barbería' }}</strong>
        <span data-pwa-msg>Instala la barbería y activa los avisos para recibir novedades de tus reservas.</span>
        <span data-pwa-status role="status" aria-live="polite" hidden></span>
    </div>
    <div class="pwa-prompt-actions">
        <button type="button" class="pwa-btn" data-pwa-install hidden>Instalar</button>
        <button type="button" class="pwa-btn pwa-btn-notify" data-pwa-notify data-staff-push-url="{{ auth()->check() ? route('staff.push.store') : '' }}">Activar avisos</button>
        <button type="button" class="pwa-close" data-pwa-close aria-label="Ahora no"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
</div>
<script>
(() => {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('{{ asset('sw.js') }}', { scope: '/' }).catch(() => {}));
    }
    const box = document.getElementById('pwaPrompt');
    if (!box) return;
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const KEY = 'pwa-dismissed-until';
    let snoozed = false;
    try { snoozed = Number(localStorage.getItem(KEY) || 0) > Date.now(); } catch (e) {}
    if (standalone || snoozed) return;

    const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const installBtn = box.querySelector('[data-pwa-install]');
    const msg = box.querySelector('[data-pwa-msg]');
    let deferred = window.deferredPwaInstall || null;
    if (deferred) installBtn.hidden = false;

    const show = () => {
        const wait = () => document.documentElement.classList.contains('is-loading') ? setTimeout(wait, 250) : setTimeout(() => { box.hidden = false; requestAnimationFrame(() => box.classList.add('is-visible')); }, 250);
        wait();
    };
    const hide = (days) => {
        box.classList.remove('is-visible');
        setTimeout(() => { box.hidden = true; }, 350);
        try { localStorage.setItem(KEY, String(Date.now() + days * 864e5)); } catch (e) {}
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferred = event;
        installBtn.hidden = false;
        show();
    });
    installBtn.addEventListener('click', async () => {
        if (deferred) {
            deferred.prompt();
            const { outcome } = await deferred.userChoice;
            deferred = null;
            hide(outcome === 'accepted' ? 365 : 7);
        } else if (isIOS) {
            hide(7);
        } else {
            setStatus('La instalación no está disponible en este navegador.');
        }
    });
    const notifyBtn = box.querySelector('[data-pwa-notify]');
    const status = box.querySelector('[data-pwa-status]');
    const setStatus = (text) => { if (status) { status.textContent = text; status.hidden = !text; } };
    const bytes = (base64) => {
        const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
    };
    notifyBtn.addEventListener('click', async () => {
        if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
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
            const staffUrl = notifyBtn.dataset.staffPushUrl;
            if (staffUrl) {
                const vapid = @json(config('services.webpush.public_key'));
                if (!vapid) throw new Error('Falta configurar la clave pública de avisos.');
                const registration = await navigator.serviceWorker.ready;
                let subscription = await registration.pushManager.getSubscription();
                if (!subscription) subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes(vapid) });
                const encodings = PushManager.supportedContentEncodings || ['aes128gcm'];
                const payload = Object.assign(subscription.toJSON(), { contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm' });
                const response = await fetch(staffUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, body: JSON.stringify(payload) });
                if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || 'No se pudo registrar este dispositivo.');
                setStatus('Avisos activados para las notificaciones del equipo.');
            } else {
                setStatus('Listo. Al reservar, vincularemos los avisos a tu cita o turno.');
            }
            notifyBtn.textContent = 'Avisos activos';
        } catch (error) {
            setStatus(error.message || 'No se pudieron activar los avisos.');
        } finally {
            notifyBtn.removeAttribute('aria-disabled');
        }
    });
    box.querySelector('[data-pwa-close]').addEventListener('click', () => hide(7));
    window.addEventListener('appinstalled', () => hide(365));

    // iPhone/iPad no avisan solos: se muestran las instrucciones de Safari.
    if (isIOS) {
        msg.innerHTML = 'En Safari, toca <i class="bi bi-box-arrow-up" aria-label="Compartir"></i> <strong>Compartir</strong> y elige <strong>“Agregar a inicio”</strong>.';
        installBtn.textContent = 'Entendido';
        installBtn.hidden = false;
        show();
    }
    if (!standalone && !snoozed) show();
})();
</script>
<script src="{{ asset('js/client-bell.js') }}?v={{ @filemtime(public_path('js/client-bell.js')) }}" defer></script>
@stack('scripts')
{{-- Scroll cinematográfico: GSAP + ScrollTrigger + Lenis (copias locales en public/vendor, instaladas con npm) --}}
<script src="{{ asset('vendor/gsap.min.js') }}?v=3.15.1" defer onerror="document.documentElement.classList.add('cx-static')"></script>
<script src="{{ asset('vendor/ScrollTrigger.min.js') }}?v=3.15.1" defer></script>
<script src="{{ asset('vendor/lenis.min.js') }}?v=1.3.27" defer></script>
<script src="{{ asset('js/cinema.js') }}?v={{ file_exists(public_path('js/cinema.js')) ? filemtime(public_path('js/cinema.js')) : '1' }}" defer onerror="document.documentElement.classList.add('cx-static')"></script>
<script src="{{ asset('js/immersive.js') }}?v={{ file_exists(public_path('js/immersive.js')) ? filemtime(public_path('js/immersive.js')) : '1' }}" defer></script>
<script src="{{ asset('js/motion.js') }}?v={{ file_exists(public_path('js/motion.js')) ? filemtime(public_path('js/motion.js')) : '1' }}" defer></script>
</body>
</html>
