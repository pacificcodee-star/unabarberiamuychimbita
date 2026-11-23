<!DOCTYPE html>
<html lang="es">
<head>
    {{-- Red de seguridad: reporta errores de JS y mantiene el contenido visible si fallan las animaciones. --}}
    <script src="{{ asset('js/error-guard.js') }}?v={{ @filemtime(public_path('js/error-guard.js')) }}"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel') · {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400..700&family=JetBrains+Mono:wght@400..600&family=Manrope:wght@400..800&display=swap" rel="stylesheet">
    @vite('resources/js/app.js')
    <style>
        /* Campos compactos en todo el panel */
        .form-control, .form-select { padding: .34rem .65rem; font-size: .86rem; min-height: 34px; border-radius: 8px; }
        .form-control-sm, .form-select-sm { padding: .22rem .55rem; font-size: .8rem; min-height: 30px; }
        textarea.form-control { min-height: 64px; }
        .form-control[type="file"] { padding: .26rem .5rem; }
        .form-label { font-size: .8rem; margin-bottom: .2rem; }
        .form-text { font-size: .74rem; margin-top: .2rem; }
        .form-check-label { font-size: .84rem; }
        .input-group-text { padding: .34rem .6rem; font-size: .86rem; }
        .card-body .row.g-3 { --bs-gutter-y: .7rem; }
        :root {
            --ink: #16130e;
            --ink-soft: #29251d;
            --gold: #a02e25;          /* colores del logo (antes dorado) */
            --gold-ink: #7f2d28;
            --gold-soft: #f6e6e3;
            --cream: #f4efe6;
            --paper: #ffffff;
            --muted: #6b6356;
            --success: #1f8a5b;
            --danger: #c0392b;
            --sidebar-width: 265px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--cream);
            color: var(--ink);
            font-family: 'Manrope', system-ui, sans-serif;
            font-size: .92rem;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4, h5, h6, .brand-title {
            font-family: 'Manrope', system-ui, sans-serif;
            font-weight: 700;
            letter-spacing: -.02em;
        }

        /* Mismo tipo de letra que la página pública en todo el panel */
        body, button, input, select, textarea, .btn, .form-control, .form-select, .table, .badge, .dropdown-menu, .modal, .tooltip, .popover { font-family: 'Manrope', system-ui, sans-serif; }
        [style*="Cormorant"], [style*="Georgia"], [style*="Playfair"] { font-family: 'Manrope', system-ui, sans-serif !important; }

        .app-shell { min-height: 100vh; }

        .main-content {
            flex: 1;
            min-width: 0;
            margin-left: 0;
            min-height: 100vh;
            padding: clamp(22px, 3vw, 40px) clamp(18px, 3vw, 42px) 54px;
            background: var(--cream);
            transition: margin-left .4s ease;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(22,22,22,.09);
        }
        .topbar h1 { font-size: 1.75rem; margin: 0; }
        .topbar .date { color: var(--muted); font-size: .82rem; }

        .mobile-nav-toggle {
            display: none;
            align-items: center; justify-content: center;
            width: 42px; height: 42px; padding: 0;
            border-radius: 12px; border: 1px solid rgba(22,22,22,.12);
            background: #fff; color: var(--ink);
            box-shadow: 0 4px 14px rgba(22,22,22,.06);
        }
        .mobile-nav-toggle:hover { background: #fbf3f2; border-color: rgba(160,46,37,.45); color: var(--gold-ink); }

        .card {
            border: 1px solid rgba(22, 22, 22, .07);
            border-radius: 18px;
            box-shadow: 0 12px 34px rgba(22, 22, 22, .045);
            background: var(--paper);
        }

        .main-content > .row > [class*="col-"] > .card,
        .main-content > .card { overflow: hidden; }

        .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(22, 22, 22, .07);
            padding: 17px 21px;
            font-weight: 600;
            color: #27251f;
        }

        .stat-card {
            padding: 20px;
            height: 100%;
            min-height: 166px;
            position: relative;
            overflow: hidden;
            border-left: 3px solid var(--gold);
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 18px 38px rgba(22,22,22,.09); }

        .stat-card .icon {
            width: 44px;
            height: 44px;
            border-radius: 13px;
            display: grid;
            place-items: center;
            background: rgba(160, 46, 37, .12);
            color: var(--gold-ink);
            font-size: 1.2rem;
            margin-bottom: 14px;
        }

        .stat-card .label {
            color: var(--muted);
            font-size: .76rem;
            text-transform: uppercase;
            letter-spacing: .1em;
            font-weight: 600;
        }

        .stat-card .value {
            font-family: 'Manrope', system-ui, sans-serif;
            font-weight: 700;
            font-size: 2rem;
            line-height: 1.1;
            margin-top: 5px;
        }

        .stat-card .hint { color: var(--muted); font-size: .78rem; margin-top: 6px; }

        .btn { border-radius: 8px; font-weight: 600; min-height: 40px; display: inline-flex; align-items: center; justify-content: center; gap: 4px; }
        .btn-sm { min-height: 32px; }
        .btn:focus-visible, .form-control:focus-visible, .form-select:focus-visible, a:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
        .btn-dark { background: var(--ink); border-color: var(--ink); }
        .btn-gold { background: var(--gold); border-color: var(--gold); color: #fff; }
        .btn-gold:hover { background: #bc3a2e; border-color: #bc3a2e; color: #fff; }
        .btn-outline-dark { border-color: rgba(22,22,22,.2); }

        .form-control, .form-select {
            border-radius: 10px;
            border-color: rgba(22, 22, 22, .15);
            padding: .58rem .75rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 .2rem rgba(160, 46, 37, .15);
        }

        .table > :not(caption) > * > * { padding: .8rem .75rem; vertical-align: middle; }
        .table thead th {
            color: var(--muted);
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .1em;
            font-weight: 700;
            border-bottom-width: 1px;
        }
        .table tbody tr:last-child td { border-bottom: 0; }
        .table tbody tr:hover { background: rgba(160,46,37,.045); }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: .35rem .55rem;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 600;
            background: rgba(22, 22, 22, .06);
            color: #4b463e;
        }

        .badge-soft.gold { background: rgba(160, 46, 37, .18); color: var(--gold-ink); }
        .badge-soft.green { background: rgba(31, 138, 91, .12); color: #16704a; }
        .badge-soft.red { background: rgba(192, 57, 43, .1); color: #a63225; }
        .badge-soft.blue { background: rgba(32, 107, 196, .1); color: #1f5fa8; }

        .alert { border-radius: 14px; border: none; }
        .alert-success { background: rgba(31, 138, 91, .12); color: #146341; }
        .alert-danger { background: rgba(192, 57, 43, .1); color: #8e2b20; }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
        }

        .empty-state i { font-size: 2.3rem; color: rgba(160, 46, 37, .5); display: block; margin-bottom: 12px; }

        .page-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        .receipt {
            max-width: 560px;
            margin: 0 auto;
            background: #fff;
            border-radius: 18px;
            padding: 34px;
            border: 1px solid rgba(22,22,22,.08);
        }

        @media (max-width: 991.98px) {
            .app-shell { display: block; }
            .mobile-nav-toggle { display: inline-flex; }
            .main-content { margin-left: 0; padding: 22px 18px 40px; }
            .topbar h1 { font-size: 1.4rem; }
        }
        @media (min-width: 992px) {
            .app-shell { display: flex; align-items: flex-start; }
            .mobile-nav-toggle { display: none; }
        }
        @media (max-width: 575.98px) {
            .topbar { align-items: flex-start; flex-direction: column; gap: 14px; margin-bottom: 20px; padding-bottom: 16px; }
            .topbar > .d-flex:last-child { width: 100%; justify-content: flex-start !important; }
            .topbar > .d-flex:last-child form { margin-left: auto; }
            .page-actions { flex: 1 1 auto; }
            .page-actions .btn { padding: .48rem .65rem; font-size: .8rem; }
            .main-content { padding: 18px 13px 32px; }
            .stat-card { min-height: 150px; padding: 16px; }
            .stat-card .value { font-size: 1.45rem; }
            .card-header { padding: 15px 16px; }
        }

        @media print {
            .topbar, .no-print, .alert { display: none !important; }
            .main-content { margin: 0; padding: 0; }
            body { background: #fff; }
            .receipt { border: none; max-width: 100%; }
        }
    </style>
    {{-- Menú lateral: Sidebar Menu Template (FathyHesham) --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="{{ asset('vendor/sidebar/css/sidebar.css') }}?v={{ @filemtime(public_path('vendor/sidebar/css/sidebar.css')) }}">
    {{-- Colores del logo y modo oscuro de todo el panel --}}
    <link rel="stylesheet" href="{{ asset('css/admin-theme.css') }}?v={{ @filemtime(public_path('css/admin-theme.css')) }}">
    <script>(function(r){r.classList.add('sidebar-preload');try{var t=localStorage.getItem('admin:theme')||localStorage.getItem('sidebar:theme')||localStorage.getItem('sidebarTheme');if(t==='dark'){r.classList.add('admin-dark');r.setAttribute('data-bs-theme','dark')}}catch(e){}})(document.documentElement)</script>
    <style>
        .staff-bell-menu { width: min(380px, calc(100vw - 24px)); z-index: 1311; }
        /* Velo con desenfoque: el panel queda por encima, nítido, en computadora, tablet y teléfono. */
        .staff-bell-backdrop { position: fixed; inset: 0; z-index: 1305; background: rgba(8, 7, 5, .5); -webkit-backdrop-filter: blur(10px) saturate(.85); backdrop-filter: blur(10px) saturate(.85); touch-action: none; overscroll-behavior: contain; }
        .staff-bell-backdrop[hidden] { display: none; }
        .staff-bell-list { max-height: min(60vh, 460px); overflow-y: auto; overscroll-behavior: contain; }
        .staff-bell-item { display: flex; gap: 12px; padding: 10px 14px; border-bottom: 1px solid var(--bs-border-color); color: inherit; text-decoration: none; }
        .staff-bell-item:last-child { border-bottom: 0; }
        .staff-bell-item:hover { background: rgba(127, 127, 127, .10); color: inherit; }
        .staff-bell-item.is-unread { background: rgba(200, 165, 90, .14); }
        .staff-bell-item i { font-size: 1.2rem; color: #b8862f; }
        .staff-bell-item strong { display: block; font-size: .88rem; }
        .staff-bell-item span { display: block; font-size: .8rem; opacity: .75; }
        .staff-bell-item time { display: block; font-size: .72rem; opacity: .6; margin-top: 2px; }
        .staff-bell-item > div { min-width: 0; }
        .staff-bell-item strong, .staff-bell-item span { overflow-wrap: anywhere; }
        /* En el teléfono la campana se abre como hoja inferior: así nunca se sale de la pantalla. */
        @media (max-width: 575.98px) {
            .staff-bell-menu {
                position: fixed !important;
                inset: auto 12px 12px 12px !important;
                transform: none !important;
                width: auto !important;
                max-width: none;
                max-height: min(75vh, 620px);
                overflow: hidden;
                border-radius: 16px;
                z-index: 1310;
            }
            .staff-bell-list { max-height: calc(min(75vh, 620px) - 52px); }
        }
    </style>
    @stack('styles')
    @include('partials.speculation-rules')
</head>
<body class="{{ request()->boolean('embed') ? 'is-embed' : '' }}">
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
            <div class="d-flex align-items-center gap-3">
                <x-sidebar.open-button class="btn mobile-nav-toggle" />
                <div>
                    <h1>@yield('title', 'Panel')</h1>
                    <div class="date">{{ now()->locale('es')->translatedFormat('l, d \d\e F \d\e Y') }}</div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                @yield('actions')
                {{-- Campana de avisos del equipo --}}
                <div class="dropdown" id="staffBell" data-feed="{{ route('staff-notifications.feed') }}" data-seen="{{ route('staff-notifications.seen') }}">
                    <button class="btn btn-outline-dark position-relative" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notificaciones" title="Notificaciones">
                        <i class="bi bi-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" data-staff-bell-badge hidden>0</span>
                    </button>
                    <div class="staff-bell-backdrop" data-staff-bell-backdrop hidden></div>
                    <div class="dropdown-menu dropdown-menu-end shadow p-0 staff-bell-menu">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                            <strong>Notificaciones</strong>
                            <a class="small" href="{{ route('staff-notifications.index') }}">Ver todas</a>
                        </div>
                        <div data-staff-bell-list class="staff-bell-list">
                            <div class="text-center text-muted small p-4">Cargando…</div>
                        </div>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn btn-outline-dark" type="submit" title="Cerrar sesión">
                        <i class="bi bi-box-arrow-right"></i>
                        <span class="d-none d-md-inline ms-1">Cerrar sesión</span>
                    </button>
                </form>
            </div>
        </div>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-1"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-1"></i> {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger" role="alert">
                <strong><i class="bi bi-exclamation-triangle me-1"></i> Revisa la información:</strong>
                <ul class="mb-0 mt-2">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</div>

<div class="staff-push-prompt" id="staffPushPrompt" role="dialog" aria-labelledby="staffPushTitle" data-store="{{ route('staff.push.store') }}" data-vapid="{{ config('services.webpush.public_key') }}" hidden>
    <div>
        <strong id="staffPushTitle">Activa los avisos del equipo</strong>
        <span data-staff-push-status role="status" aria-live="polite">Recibe avisos de nuevas reservas y cambios de turno.</span>
    </div>
    <button type="button" class="btn btn-primary btn-sm" data-staff-push-enable>Activar avisos</button>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-staff-push-close>Ahora no</button>
</div>
<style>
    .staff-push-prompt { position: fixed; z-index: 1300; right: 20px; bottom: 20px; display: flex; align-items: center; gap: 12px; width: min(640px, calc(100vw - 32px)); padding: 16px; border: 1px solid rgba(201,162,39,.35); border-radius: 16px; background: #1c1813; color: #f3ebdd; box-shadow: 0 18px 50px rgba(0,0,0,.28); }
    .staff-push-prompt[hidden] { display: none; }
    .staff-push-prompt > div { display: grid; gap: 3px; flex: 1; }
    .staff-push-prompt span { color: #c0b4a2; font-size: .82rem; }
    .staff-push-prompt button { white-space: nowrap; }
    @media (max-width: 575px) { .staff-push-prompt { right: 12px; bottom: 12px; flex-wrap: wrap; } .staff-push-prompt > div { flex-basis: 100%; } }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="{{ asset('vendor/sidebar/js/sidebar.js') }}?v={{ @filemtime(public_path('vendor/sidebar/js/sidebar.js')) }}"></script>
<script src="{{ asset('js/chime.js') }}?v={{ @filemtime(public_path('js/chime.js')) }}"></script>
<script>
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

    let revision = null;
    const live = document.querySelector('[data-booking-region]');
    const paint = (data) => {
        const latest = data.items[0]?.id || 0;
        if (live && latest !== revision) window.dispatchEvent(new Event('booking:changed'));
        // Aviso nuevo con el panel abierto: timbre (si el push está activo, ya suena la notificación del sistema).
        if (revision !== null && latest > revision && data.items[0]?.unread) window.ubmcChime?.();
        revision = latest;
        badge.hidden = !data.unread;
        badge.textContent = data.unread > 9 ? '9+' : String(data.unread);
        list.replaceChildren();
        if (!data.items.length) {
            list.append(el('div', 'text-center text-muted small p-4', 'Todavía no hay avisos.'));
            return;
        }
        data.items.forEach((item) => {
            const row = el('a', 'staff-bell-item' + (item.unread ? ' is-unread' : ''));
            row.href = item.url || '#';
            const text = el('div');
            text.append(el('strong', '', item.title));
            if (item.body) text.append(el('span', '', item.body));
            text.append(el('time', '', item.ago));
            row.append(el('i', 'bi ' + item.icon), text);
            list.append(row);
        });
    };

    const load = async () => {
        if (document.hidden) return;
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
        load();
    });
    backdrop.addEventListener('click', closeMenu);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !backdrop.hidden) closeMenu();
    });

    load();
    setInterval(load, live ? 5000 : 30000);
    document.addEventListener('visibilitychange', load);
})();
</script>
<script>
(() => {
    const box = document.getElementById('staffPushPrompt');
    if (!box) return;
    const enable = box.querySelector('[data-staff-push-enable]');
    const close = box.querySelector('[data-staff-push-close]');
    const status = box.querySelector('[data-staff-push-status]');
    const key = 'staff-push-dismissed-until';
    const setStatus = (message) => { status.textContent = message; };
    const bytes = (base64) => {
        const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
    };
    const dismissed = () => { try { return Number(localStorage.getItem(key) || 0) > Date.now(); } catch (e) { return false; } };
    const snooze = () => { try { localStorage.setItem(key, String(Date.now() + 7 * 864e5)); } catch (e) {} box.hidden = true; };

    if (dismissed()) return;
    if (!('Notification' in window) || !('PushManager' in window) || !('serviceWorker' in navigator) || !box.dataset.vapid) {
        setStatus('Este navegador no admite avisos push.');
        enable.hidden = true;
        return;
    }

    const swReady = navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' })
        .then((registration) => { registration.update().catch(() => {}); return navigator.serviceWorker.ready; })
        .catch(() => null);

    const saveSubscription = async (subscription) => {
        const encodings = PushManager.supportedContentEncodings || ['aes128gcm'];
        const payload = Object.assign(subscription.toJSON(), { contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm' });
        const response = await fetch(box.dataset.store, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify(payload),
        });
        if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || 'No se pudo registrar este dispositivo.');
    };

    // Si el navegador ya dio permiso, este dispositivo se registra solo (o se vuelve a
    // registrar si el servidor lo perdió) y el aviso no aparece: nunca se pide dos veces.
    const syncIfGranted = async () => {
        if (Notification.permission !== 'granted') return false;
        try {
            const registration = await swReady;
            if (!registration) throw new Error('El navegador no pudo iniciar el servicio de avisos.');
            let subscription = await registration.pushManager.getSubscription();
            if (!subscription) subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes(box.dataset.vapid) });
            await saveSubscription(subscription);
            box.hidden = true;
            return true;
        } catch (error) {
            setStatus('Este dispositivo tiene permiso, pero no se pudo conectar con el servidor de avisos.');
            return false;
        }
    };

    close.addEventListener('click', snooze);
    enable.addEventListener('click', async () => {
        enable.setAttribute('aria-disabled', 'true');
        setStatus('Conectando los avisos a este dispositivo…');
        try {
            const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
            if (permission !== 'granted') throw new Error(permission === 'denied' ? 'Los avisos están bloqueados en la configuración del navegador.' : 'Permite las notificaciones para continuar.');
            await syncIfGranted();
            setStatus('Avisos activados. Este dispositivo recibirá las notificaciones del equipo.');
            enable.textContent = 'Avisos activos';
            window.setTimeout(snooze, 2400);
        } catch (error) {
            setStatus(error.message || 'No se pudieron activar los avisos.');
        } finally {
            enable.removeAttribute('aria-disabled');
        }
    });

    (async () => {
        if (Notification.permission === 'denied') {
            setStatus('Los avisos están bloqueados en este navegador. Actívalos desde el candado de la barra de direcciones.');
            enable.hidden = true;
            box.hidden = false;
            return;
        }
        // Oculto mientras se comprueba: si ya estaban activos, no llega a mostrarse.
        if (!(await syncIfGranted())) box.hidden = false;
    })();
})();
</script>
@if(request()->routeIs('dashboard', 'turns.index', 'appointments.index'))
    <script src="{{ asset('js/booking-panels.js') }}?v={{ filemtime(public_path('js/booking-panels.js')) }}"></script>
@endif
@stack('scripts')
</body>
</html>
