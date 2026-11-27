<!DOCTYPE html>
<html lang="es">
<head>
    {{-- Red de seguridad: reporta errores de JS y mantiene el contenido visible si fallan las animaciones. --}}
    <script src="{{ asset('js/error-guard.js') }}?v={{ @filemtime(public_path('js/error-guard.js')) }}"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel') · {{ config('app.name') }}</title>
    @include('partials.favicon')
    {{-- App instalable del panel (PWA): manifiesto propio, abre directo en el Panel general --}}
    <link rel="manifest" href="{{ asset('panel.webmanifest') }}">
    <meta name="theme-color" content="#f4f1ec" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#121418" media="(prefers-color-scheme: dark)">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Panel Chimbita">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400..700&family=JetBrains+Mono:wght@400..600&family=Manrope:wght@400..800&display=swap" rel="stylesheet">
    <script src="{{ asset('js/panel.js') }}?v={{ @filemtime(public_path('js/panel.js')) }}"></script>
    {{-- Sistema de diseño del panel: tokens, componentes, menú lateral y modo oscuro --}}
    <link rel="stylesheet" href="{{ asset('css/admin-ui.css') }}?v={{ @filemtime(public_path('css/admin-ui.css')) }}">
    <script>(function(r){r.classList.add('sidebar-preload');try{var t=localStorage.getItem('admin:theme')||localStorage.getItem('sidebar:theme')||localStorage.getItem('sidebarTheme');if(t==='dark'){r.classList.add('admin-dark');r.setAttribute('data-bs-theme','dark')}}catch(e){}})(document.documentElement)</script>
    <style>
        /* ---------- Notificaciones del equipo (campana y página /notificaciones) ---------- */
        .bell-btn { width: 42px; padding: 0; position: relative; }
        .bell-btn .bell-badge {
            position: absolute; top: -6px; right: -6px; min-width: 20px; height: 20px; padding: 0 6px;
            display: grid; place-items: center; border-radius: 999px; border: 2px solid var(--cream);
            background: var(--gold); color: #fff; font-size: .68rem; font-weight: 800; line-height: 1;
            font-variant-numeric: tabular-nums;
        }
        .bell-badge[hidden] { display: none; }

        .staff-bell-menu {
            width: min(400px, calc(100vw - 24px)); z-index: 1311; margin-top: 10px !important;
            border: 1px solid var(--bs-border-color); border-radius: 16px !important; overflow: hidden;
            box-shadow: 0 24px 60px rgba(22, 19, 14, .18), 0 2px 8px rgba(22, 19, 14, .06) !important;
        }
        /* Velo con desenfoque: el panel queda por encima, nítido, en computadora, tablet y teléfono. */
        /* Sin velo de fondo: el panel de avisos se abre sobre la página tal cual. */
        .staff-bell-backdrop { display: none !important; }
        .staff-bell-backdrop[hidden] { display: none; }
        /* Con el panel de avisos abierto, la barra superior queda por encima de todo (incluido el aviso de activar). */
        .topbar:has(.staff-bell-menu.show) { z-index: 1320; }

        .nt-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 14px 12px 18px; border-bottom: 1px solid var(--bs-border-color); }
        .nt-head h2 { margin: 0; font-size: 1rem; font-weight: 800; letter-spacing: -.01em; }
        .nt-head p { margin: 1px 0 0; font-size: .75rem; color: var(--bs-secondary-color); }
        .nt-icon-btn {
            display: inline-grid; place-items: center; width: 36px; height: 36px; padding: 0;
            border: 1px solid transparent; border-radius: 10px; background: transparent; color: var(--bs-secondary-color);
            transition: background-color .15s ease, color .15s ease, border-color .15s ease;
        }
        .nt-icon-btn:hover { background: rgba(192, 57, 43, .09); color: var(--danger); border-color: rgba(192, 57, 43, .18); }
        .nt-icon-btn[hidden] { display: none; }

        .nt-device { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 14px 10px 18px; border-bottom: 1px solid var(--bs-border-color); background: rgba(127, 127, 127, .05); }
        .nt-device-ico { flex: none; display: grid; place-items: center; width: 30px; height: 30px; border-radius: 9px; font-size: .85rem; background: rgba(127, 127, 127, .14); color: var(--bs-secondary-color); }
        .nt-device[data-state="on"] .nt-device-ico, .nt-device[data-state="local"] .nt-device-ico { background: rgba(31, 138, 91, .14); color: #1a7a50; }
        html.admin-dark .nt-device[data-state="local"] .nt-device-ico { color: #62d3a0; }
        .nt-device[data-state="off"] .nt-device-ico { background: rgba(196, 135, 24, .16); color: #96600a; }
        .nt-device[data-state="denied"] .nt-device-ico, .nt-device[data-state="error"] .nt-device-ico { background: rgba(192, 57, 43, .12); color: #b03a2e; }
        html.admin-dark .nt-device[data-state="on"] .nt-device-ico { color: #62d3a0; }
        html.admin-dark .nt-device[data-state="off"] .nt-device-ico { color: #f0b54e; }
        html.admin-dark .nt-device[data-state="denied"] .nt-device-ico, html.admin-dark .nt-device[data-state="error"] .nt-device-ico { color: #f08a7e; }
        .nt-device-text { flex: 1 1 170px; min-width: 0; display: grid; gap: 1px; }
        .nt-device-text strong { font-size: .8rem; }
        .nt-device-text span { font-size: .74rem; line-height: 1.35; color: var(--bs-secondary-color); overflow-wrap: anywhere; }
        .nt-device .btn { min-height: 32px; padding: .2rem .7rem; font-size: .78rem; }
        .nt-device .btn[hidden] { display: none; }
        .nt-confirm { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 12px 18px; background: rgba(192, 57, 43, .07); border-bottom: 1px solid rgba(192, 57, 43, .16); font-size: .82rem; }
        .nt-confirm[hidden] { display: none; }
        .nt-confirm span { flex: 1 1 180px; font-weight: 600; }
        .nt-confirm .btn { min-height: 32px; padding: .25rem .7rem; font-size: .78rem; }

        /* El panel nunca se sale de la pantalla: la lista ocupa el alto disponible y se desplaza por dentro. */
        .staff-bell-menu.show { display: flex; flex-direction: column; max-height: calc(100vh - 100px); max-height: calc(100dvh - 100px); }
        .staff-bell-menu > :not(.staff-bell-list) { flex: none; }
        .staff-bell-list { flex: 1 1 auto; min-height: 0; max-height: none; overflow-y: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; scrollbar-width: thin; }
        .nt-day {
            position: sticky; top: 0; z-index: 1; padding: 9px 18px 6px;
            background: var(--bs-body-bg); color: var(--bs-secondary-color);
            font-size: .68rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
        }
        .nt-item {
            position: relative; display: flex; align-items: flex-start; gap: 12px; padding: 11px 34px 11px 18px;
            color: inherit; text-decoration: none; transition: background-color .15s ease;
        }
        .nt-item + .nt-item { border-top: 1px solid var(--bs-border-color); }
        a.nt-item:hover { background: var(--ui-surface-2); color: inherit; }
        .nt-ico { flex: none; display: grid; place-items: center; width: 38px; height: 38px; border-radius: 12px; font-size: 1.05rem; }
        .nt-text { min-width: 0; flex: 1; display: grid; gap: 2px; }
        .nt-text strong { font-size: .86rem; font-weight: 700; line-height: 1.3; overflow-wrap: anywhere; }
        .nt-text span { font-size: .8rem; line-height: 1.4; color: var(--bs-secondary-color); overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .nt-text time { font-size: .72rem; color: var(--bs-secondary-color); opacity: .85; margin-top: 2px; }
        .nt-item.is-unread strong { color: var(--bs-emphasis-color); }
        .nt-item.is-unread::after { content: ''; position: absolute; top: 50%; right: 16px; width: 8px; height: 8px; margin-top: -4px; border-radius: 50%; background: var(--c-brand); box-shadow: 0 0 0 3px var(--c-brand-soft); }


        .nt-empty { display: grid; justify-items: center; gap: 6px; padding: 38px 24px; text-align: center; color: var(--bs-secondary-color); }
        .nt-empty .nt-ico { width: 52px; height: 52px; border-radius: 16px; font-size: 1.4rem; margin-bottom: 6px; }
        .nt-empty strong { color: var(--bs-body-color); font-size: .92rem; }
        .nt-empty p { margin: 0; max-width: 30ch; font-size: .8rem; line-height: 1.45; }
        .nt-foot { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px; border-top: 1px solid var(--bs-border-color); color: var(--gold); font-size: .82rem; font-weight: 700; text-decoration: none; }
        .nt-foot:hover { background: var(--ui-surface-2); color: var(--gold-ink); }
        html.admin-dark .nt-foot, html.admin-dark .nt-foot:hover { color: #f08a7e; }

        /* En el teléfono la campana se abre como hoja inferior: así nunca se sale de la pantalla. */
        @media (max-width: 575.98px) {
            .staff-bell-menu {
                position: fixed !important;
                inset: auto 10px 10px 10px !important;
                transform: none !important;
                width: auto !important;
                max-width: none;
                max-height: min(80vh, 640px);
                display: none; flex-direction: column;
                border-radius: 20px !important;
                z-index: 1310;
            }
            .staff-bell-menu.show { display: flex; }
            .staff-bell-list { max-height: none; flex: 1 1 auto; min-height: 0; }
        }
        @media (prefers-reduced-motion: reduce) { .nt-item, .nt-icon-btn { transition: none; } }
    </style>
    @stack('styles')
    @include('partials.speculation-rules')
</head>
@php
    // Color e icono de cada sección del panel (mismos tonos que los grupos del menú lateral).
    $pageSections = [
        'dashboard' => ['brand', 'bi-grid-1x2'],
        'appointments.*' => ['blue', 'bi-calendar2-check'],
        'turns.*' => ['blue', 'bi-list-ol'],
        'sales.*' => ['emerald', 'bi-receipt'],
        'pos.*' => ['emerald', 'bi-shop'],
        'invoices.*' => ['emerald', 'bi-file-earmark-text'],
        'cash.*' => ['emerald', 'bi-safe2'],
        'services.*' => ['amber', 'bi-scissors'],
        'supplies.*' => ['amber', 'bi-box-seam'],
        'barbers.*' => ['violet', 'bi-people'],
        'availability.*' => ['violet', 'bi-calendar2-x'],
        'commissions.*' => ['violet', 'bi-wallet2'],
        'surveys.*' => ['rose', 'bi-star-half'],
        'expenses.*' => ['teal', 'bi-cash-stack'],
        'reports.*' => ['teal', 'bi-bar-chart-line'],
        'whatsapp.*' => ['slate', 'bi-whatsapp'],
        'site-settings.*' => ['slate', 'bi-gear'],
        'staff-notifications.*' => ['brand', 'bi-bell'],
    ];
    [$pageTone, $pageIcon] = ['brand', 'bi-grid-1x2'];
    foreach ($pageSections as $pattern => $section) {
        if (request()->routeIs($pattern)) { [$pageTone, $pageIcon] = $section; break; }
    }
@endphp
<body class="{{ request()->boolean('embed') ? 'is-embed' : '' }}" data-tone="{{ $pageTone }}">
@if(request()->boolean('embed'))
<style>
    /* Vista dentro de una ventana emergente del mismo panel: sin menú lateral ni barra superior */
    .is-embed .sidebar, .is-embed .sidebar-overlay, .is-embed .topbar, .is-embed .whatsapp-float { display: none !important; }
    .is-embed .main-content { margin-left: 0 !important; padding: 16px 18px 24px !important; }
    .is-embed { background: #fff; }
</style>
@endif
<div class="app-shell">
    <x-sidebar />

    <main class="main-content">
        <div class="topbar">
            <div class="topbar-title">
                <x-sidebar.open-button class="btn btn-icon mobile-nav-toggle" />
                <span class="page-icon" aria-hidden="true"><i class="bi {{ $pageIcon }}"></i></span>
                <div>
                    <h1>@yield('title', 'Panel')</h1>
                    <div class="date">{{ now()->locale('es')->translatedFormat('l, d \d\e F \d\e Y') }}</div>
                </div>
            </div>
            <div class="topbar-actions">
                @yield('actions')
                {{-- Campana de avisos del equipo --}}
                <div class="dropdown" id="staffBell" data-feed="{{ route('staff-notifications.feed') }}" data-seen="{{ route('staff-notifications.seen') }}" data-clear="{{ route('staff-notifications.clear') }}">
                    <button class="btn btn-outline-dark btn-icon bell-btn" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notificaciones" title="Notificaciones">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                        <span class="bell-badge" data-staff-bell-badge hidden>0</span>
                    </button>
                    <div class="staff-bell-backdrop" data-staff-bell-backdrop hidden></div>
                    <div class="dropdown-menu dropdown-menu-end p-0 staff-bell-menu" aria-labelledby="staffBellTitle">
                        <div class="nt-head">
                            <div>
                                <h2 id="staffBellTitle">Notificaciones</h2>
                                <p data-staff-bell-summary role="status" aria-live="polite">Cargando…</p>
                            </div>
                            <button type="button" class="nt-icon-btn" data-staff-bell-clear title="Eliminar todas" aria-label="Eliminar todas las notificaciones" hidden>
                                <i class="bi bi-trash3" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="nt-device" data-push-device data-state="checking">
                            <span class="nt-device-ico" aria-hidden="true"><i class="bi bi-bell-fill"></i></span>
                            <div class="nt-device-text">
                                <strong>Avisos en este dispositivo</strong>
                                <span data-push-device-detail role="status" aria-live="polite">Comprobando…</span>
                            </div>
                            <button type="button" class="btn btn-gold" data-push-device-enable hidden>Activar</button>
                            <button type="button" class="btn btn-outline-dark" data-push-device-test hidden><i class="bi bi-volume-up" aria-hidden="true"></i> Probar</button>
                        </div>
                        <div class="nt-confirm" data-staff-bell-confirm hidden>
                            <span>¿Eliminar todas tus notificaciones?</span>
                            <button type="button" class="btn btn-outline-dark" data-staff-bell-cancel>Cancelar</button>
                            <button type="button" class="btn btn-danger" data-staff-bell-confirm-yes>Eliminar</button>
                        </div>
                        <div data-staff-bell-list class="staff-bell-list"></div>
                        <a class="nt-foot" href="{{ route('staff-notifications.index') }}">Ver todas las notificaciones <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="logout-form">
                    @csrf
                    <button class="btn btn-outline-dark btn-icon" type="submit" title="Cerrar sesión" aria-label="Cerrar sesión">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>
            </div>
        </div>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show d-flex gap-2" role="alert">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>{{ session('success') }}</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show d-flex gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><span>{{ session('error') }}</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger d-flex gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <div>
                    <strong>Revisa la información:</strong>
                    <ul class="mb-0 mt-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @yield('content')
    </main>
</div>

<div class="staff-push-prompt" id="staffPushPrompt" role="dialog" aria-labelledby="staffPushTitle" data-store="{{ route('staff.push.store') }}" data-test="{{ route('staff.push.test') }}" data-vapid="{{ config('services.webpush.public_key') }}" hidden>
    <div>
        <strong id="staffPushTitle">Activa los avisos del equipo</strong>
        <span data-staff-push-status role="status" aria-live="polite">Recibe avisos de nuevas reservas y cambios de turno.</span>
    </div>
    <button type="button" class="btn btn-gold btn-sm" data-staff-push-enable>Activar avisos</button>
    <button type="button" class="btn btn-outline-dark btn-sm" data-staff-push-close>Ahora no</button>
</div>
<style>
    .staff-push-prompt { position: fixed; z-index: 1300; right: 24px; bottom: 24px; display: flex; align-items: center; gap: 12px; width: min(560px, calc(100vw - 32px)); padding: 14px 16px; border: 1px solid var(--ui-border); border-radius: var(--ui-radius-lg); background: var(--ui-surface); color: var(--ui-text); box-shadow: var(--ui-shadow-lg); }
    .staff-push-prompt[hidden] { display: none; }
    .staff-push-prompt > div { display: grid; gap: 2px; flex: 1; min-width: 0; }
    .staff-push-prompt strong { font-size: .88rem; }
    .staff-push-prompt span { color: var(--ui-text-3); font-size: .8rem; line-height: 1.4; }
    .staff-push-prompt button { white-space: nowrap; }
    @media (max-width: 575px) { .staff-push-prompt { right: 12px; bottom: 12px; flex-wrap: wrap; } .staff-push-prompt > div { flex-basis: calc(100% - 50px); } }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="{{ asset('vendor/sidebar/js/sidebar.js') }}?v={{ @filemtime(public_path('vendor/sidebar/js/sidebar.js')) }}"></script>
<script src="{{ asset('js/chime.js') }}?v={{ @filemtime(public_path('js/chime.js')) }}" data-volume="2"></script>
<script>
    // Color de la sección actual (paleta del panel) para las gráficas: panelTone() o panelTone(.12) con transparencia.
    window.panelTone = function (alpha) {
        var hex = getComputedStyle(document.body).getPropertyValue('--tone').trim() || '#b32533';
        if (alpha === undefined || hex.charAt(0) !== '#') return hex;
        var n = parseInt(hex.slice(1), 16);
        return 'rgba(' + (n >> 16 & 255) + ', ' + (n >> 8 & 255) + ', ' + (n & 255) + ', ' + alpha + ')';
    };
    // Gráficas y modo oscuro: colores de texto y líneas según el tema, también al cambiarlo.
    (function () {
        if (!window.Chart) return;
        function palette() {
            var dark = document.documentElement.classList.contains('admin-dark');
            return { text: dark ? '#CBD5E1' : '#6b6356', grid: dark ? 'rgba(255,255,255,.08)' : 'rgba(22,22,22,.05)' };
        }
        function applyDefaults() {
            var p = palette();
            Chart.defaults.color = p.text;
            Chart.defaults.borderColor = p.grid;
            return p;
        }
        applyDefaults();
        document.addEventListener('admin:themechange', function () {
            var p = applyDefaults();
            Object.values(Chart.instances || {}).forEach(function (chart) {
                var scales = (chart.options && chart.options.scales) || {};
                Object.keys(scales).forEach(function (k) {
                    var sc = scales[k];
                    if (sc.ticks) sc.ticks.color = p.text;
                    if (sc.grid) sc.grid.color = p.grid;
                });
                var legend = chart.options.plugins && chart.options.plugins.legend;
                if (legend && legend.labels) legend.labels.color = p.text;
                chart.update('none');
            });
        });
        // Al cargar en modo oscuro, ajusta las gráficas que la página cree después.
        window.addEventListener('load', function () {
            if (document.documentElement.classList.contains('admin-dark')) document.dispatchEvent(new Event('admin:themechange'));
        });
    })();
    // Al imprimir siempre se usa el tema claro (recibos, nómina, informes).
    (function () {
        var root = document.documentElement, wasDark = false;
        window.addEventListener('beforeprint', function () {
            wasDark = root.classList.contains('admin-dark');
            if (wasDark) { root.classList.remove('admin-dark'); root.removeAttribute('data-bs-theme'); }
        });
        window.addEventListener('afterprint', function () {
            if (wasDark) { root.classList.add('admin-dark'); root.setAttribute('data-bs-theme', 'dark'); }
        });
    })();
</script>
<script>
// Campana del panel: consulta los avisos del equipo cada 30 segundos (solo actualiza lo que se ve en pantalla).
(() => {
    const root = document.getElementById('staffBell');
    if (!root) return;
    const badge = root.querySelector('[data-staff-bell-badge]');
    const list = root.querySelector('[data-staff-bell-list]');
    const backdrop = root.querySelector('[data-staff-bell-backdrop]');
    const toggle = root.querySelector('[data-bs-toggle="dropdown"]');
    const closeMenu = () => {
        const api = window.bootstrap?.Dropdown;
        if (api) api.getOrCreateInstance(toggle).hide(); else toggle.click();
    };
    const el = (tag, className, text) => { const node = document.createElement(tag); if (className) node.className = className; if (text) node.textContent = text; return node; };

    const summary = root.querySelector('[data-staff-bell-summary]');
    const clearBtn = root.querySelector('[data-staff-bell-clear]');
    const confirmBox = root.querySelector('[data-staff-bell-confirm]');
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const icon = (name, tone) => { const box = el('span', 'nt-ico tone-' + (tone || 'neutral')); box.setAttribute('aria-hidden', 'true'); box.append(el('i', 'bi ' + name)); return box; };

    let revision = null;
    const live = document.querySelector('[data-booking-region]');
    const paint = (data) => {
        const latest = data.items[0]?.id || 0;
        if (live && latest !== revision) window.dispatchEvent(new Event('booking:changed'));
        // Aviso nuevo con el panel abierto: timbre (si el push está activo, ya suena la notificación del sistema).
        if (revision !== null && latest > revision && data.items[0]?.unread) {
            // Un timbre por cada aviso nuevo (hasta 5 seguidos), no uno solo por tanda.
            const fresh = data.items.filter((item) => item.id > revision && item.unread).length;
            window.ubmcChime?.(Math.max(1, fresh));
            // Modo local (sin servicio push, p. ej. Brave): la notificación del sistema la muestra el panel.
            if (window.staffPush?.state.name === 'local') {
                data.items.filter((item) => item.id > revision && item.unread).reverse()
                    .forEach((item) => window.staffPush.showLocal(item.title, item.body, item.url, 'staff-' + item.id));
            }
        }
        revision = latest;
        badge.hidden = !data.unread;
        badge.textContent = data.unread > 9 ? '9+' : String(data.unread);
        summary.textContent = !data.total ? 'Estás al día'
            : (data.unread ? `${data.unread} ${data.unread === 1 ? 'nueva' : 'nuevas'} · ${data.total} en total` : `${data.total} ${data.total === 1 ? 'notificación' : 'notificaciones'}`);
        clearBtn.hidden = !data.total;
        if (!data.total) confirmBox.hidden = true;

        list.replaceChildren();
        if (!data.items.length) {
            const empty = el('div', 'nt-empty');
            empty.append(icon('bi-bell-slash', 'neutral'), el('strong', '', 'No tienes notificaciones'), el('p', '', 'Aquí verás los turnos y citas nuevos, las cancelaciones y el avance de la fila.'));
            list.append(empty);
            return;
        }
        let day = null;
        data.items.forEach((item) => {
            if (item.day !== day) { day = item.day; list.append(el('div', 'nt-day', day)); }
            const row = el(item.url ? 'a' : 'div', 'nt-item' + (item.unread ? ' is-unread' : ''));
            if (item.url) row.href = item.url;
            const text = el('span', 'nt-text');
            text.append(el('strong', '', item.title));
            if (item.body) text.append(el('span', '', item.body));
            const time = el('time', '', `${item.time} · ${item.ago}`);
            text.append(time);
            row.append(icon(item.icon, item.tone), text);
            if (item.unread) row.setAttribute('aria-label', 'Nueva: ' + item.title);
            list.append(row);
        });
    };

    // Eliminar todas: confirmación dentro del mismo panel (sin ventanas del navegador).
    clearBtn.addEventListener('click', () => { confirmBox.hidden = false; confirmBox.querySelector('[data-staff-bell-cancel]').focus(); });
    confirmBox.querySelector('[data-staff-bell-cancel]').addEventListener('click', () => { confirmBox.hidden = true; clearBtn.focus(); });
    confirmBox.querySelector('[data-staff-bell-confirm-yes]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        try {
            const response = await fetch(root.dataset.clear, {
                method: 'DELETE', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            });
            if (!response.ok) throw new Error();
            confirmBox.hidden = true;
            await load(true);
        } catch (e) {
            summary.textContent = 'No se pudieron eliminar. Inténtalo de nuevo.';
        } finally {
            button.disabled = false;
        }
    });

    const load = async (force = false) => {
        // En modo local se sigue revisando en segundo plano para poder mostrar la notificación con sonido.
        if (document.hidden && !force && window.staffPush?.state.name !== 'local') return;
        try {
            const response = await fetch(root.dataset.feed, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            if (response.ok) paint(await response.json());
        } catch (e) {}
    };

    root.addEventListener('shown.bs.dropdown', () => {
        backdrop.hidden = false;
        badge.hidden = true;
        fetch(root.dataset.seen, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        }).catch(() => {});
    });
    root.addEventListener('hidden.bs.dropdown', () => {
        backdrop.hidden = true;
        confirmBox.hidden = true;
        load();
    });
    backdrop.addEventListener('click', closeMenu);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !backdrop.hidden) closeMenu();
    });

    load();
    setInterval(() => load(), live ? 5000 : 30000);
    document.addEventListener('visibilitychange', () => load());
    // Llegó un push (sw.js avisa a las pestañas abiertas): la campana se actualiza al instante.
    navigator.serviceWorker?.addEventListener('message', (event) => { if (event.data === 'booking-change') load(true); });

    // Fila "Avisos en este dispositivo": estado real, Activar y Probar (js/staff-push.js).
    const device = root.querySelector('[data-push-device]');
    const deviceDetail = device.querySelector('[data-push-device-detail]');
    const deviceEnable = device.querySelector('[data-push-device-enable]');
    const deviceTest = device.querySelector('[data-push-device-test]');
    document.addEventListener('DOMContentLoaded', () => {
        if (!window.staffPush) { device.hidden = true; return; }
        window.staffPush.onChange(({ name, message }) => {
            device.dataset.state = name;
            deviceDetail.textContent = message || (name === 'checking' ? 'Comprobando…' : '');
            deviceEnable.hidden = !['off', 'error'].includes(name);
            deviceEnable.textContent = name === 'error' ? 'Reintentar' : 'Activar';
            deviceTest.hidden = !['on', 'local'].includes(name);
        });
        deviceEnable.addEventListener('click', async () => {
            deviceEnable.disabled = true;
            await window.staffPush.enable();
            deviceEnable.disabled = false;
        });
        deviceTest.addEventListener('click', async () => {
            deviceTest.disabled = true;
            deviceDetail.textContent = 'Enviando un aviso de prueba…';
            try {
                const result = await window.staffPush.test();
                deviceDetail.textContent = result.message;
            } catch (error) {
                deviceDetail.textContent = error.message;
            } finally {
                deviceTest.disabled = false;
            }
        });
    });
})();
</script>
<script src="{{ asset('js/staff-push.js') }}?v={{ @filemtime(public_path('js/staff-push.js')) }}"></script>
<script src="{{ asset('js/panel-pwa.js') }}?v={{ @filemtime(public_path('js/panel-pwa.js')) }}" defer></script>
@if(request()->routeIs('dashboard', 'turns.index', 'appointments.index'))
    <script src="{{ asset('js/booking-panels.js') }}?v={{ filemtime(public_path('js/booking-panels.js')) }}"></script>
@endif
@stack('scripts')
</body>
</html>
