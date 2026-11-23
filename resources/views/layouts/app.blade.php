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
        .staff-bell-backdrop { position: fixed; inset: 0; z-index: 1305; background: rgba(8, 7, 5, .42); -webkit-backdrop-filter: blur(8px) saturate(.85); backdrop-filter: blur(8px) saturate(.85); touch-action: none; overscroll-behavior: contain; }
        .staff-bell-backdrop[hidden] { display: none; }

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
        .nt-device[data-state="on"] .nt-device-ico { background: rgba(31, 138, 91, .14); color: #1a7a50; }
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

        .staff-bell-list { max-height: min(62vh, 480px); overflow-y: auto; overscroll-behavior: contain; }
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
        a.nt-item:hover { background: rgba(160, 46, 37, .05); color: inherit; }
        .nt-ico { flex: none; display: grid; place-items: center; width: 38px; height: 38px; border-radius: 12px; font-size: 1.05rem; }
        .nt-text { min-width: 0; flex: 1; display: grid; gap: 2px; }
        .nt-text strong { font-size: .86rem; font-weight: 700; line-height: 1.3; overflow-wrap: anywhere; }
        .nt-text span { font-size: .8rem; line-height: 1.4; color: var(--bs-secondary-color); overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .nt-text time { font-size: .72rem; color: var(--bs-secondary-color); opacity: .85; margin-top: 2px; }
        .nt-item.is-unread strong { color: var(--bs-emphasis-color); }
        .nt-item.is-unread::after { content: ''; position: absolute; top: 50%; right: 16px; width: 8px; height: 8px; margin-top: -4px; border-radius: 50%; background: var(--gold); box-shadow: 0 0 0 3px rgba(160, 46, 37, .15); }

        .tone-green   { background: rgba(31, 138, 91, .12);  color: #1a7a50; }
        .tone-blue    { background: rgba(32, 107, 196, .12); color: #1f5fa8; }
        .tone-amber   { background: rgba(196, 135, 24, .15); color: #96600a; }
        .tone-red     { background: rgba(192, 57, 43, .12);  color: #b03a2e; }
        .tone-brand   { background: rgba(160, 46, 37, .12);  color: var(--gold); }
        .tone-neutral { background: rgba(127, 127, 127, .14); color: var(--bs-secondary-color); }
        html.admin-dark .tone-green { color: #62d3a0; }
        html.admin-dark .tone-blue { color: #7fb2ef; }
        html.admin-dark .tone-amber { color: #f0b54e; }
        html.admin-dark .tone-red, html.admin-dark .tone-brand { color: #f08a7e; }

        .nt-empty { display: grid; justify-items: center; gap: 6px; padding: 38px 24px; text-align: center; color: var(--bs-secondary-color); }
        .nt-empty .nt-ico { width: 52px; height: 52px; border-radius: 16px; font-size: 1.4rem; margin-bottom: 6px; }
        .nt-empty strong { color: var(--bs-body-color); font-size: .92rem; }
        .nt-empty p { margin: 0; max-width: 30ch; font-size: .8rem; line-height: 1.45; }
        .nt-foot { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px; border-top: 1px solid var(--bs-border-color); color: var(--gold); font-size: .82rem; font-weight: 700; text-decoration: none; }
        .nt-foot:hover { background: rgba(160, 46, 37, .05); color: var(--gold-ink); }
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
                <div class="dropdown" id="staffBell" data-feed="{{ route('staff-notifications.feed') }}" data-seen="{{ route('staff-notifications.seen') }}" data-clear="{{ route('staff-notifications.clear') }}">
                    <button class="btn btn-outline-dark bell-btn" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notificaciones" title="Notificaciones">
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

<div class="staff-push-prompt" id="staffPushPrompt" role="dialog" aria-labelledby="staffPushTitle" data-store="{{ route('staff.push.store') }}" data-test="{{ route('staff.push.test') }}" data-vapid="{{ config('services.webpush.public_key') }}" hidden>
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
        if (revision !== null && latest > revision && data.items[0]?.unread) window.ubmcChime?.();
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
        if (document.hidden && !force) return;
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
            deviceTest.hidden = name !== 'on';
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
@if(request()->routeIs('dashboard', 'turns.index', 'appointments.index'))
    <script src="{{ asset('js/booking-panels.js') }}?v={{ filemtime(public_path('js/booking-panels.js')) }}"></script>
@endif
@stack('scripts')
</body>
</html>
