<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#1b3a57">
    <title>@yield('title', 'Ingresar') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo-una-barberia.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..800;1,9..144,300..800&family=JetBrains+Mono:wght@400..700&family=Manrope:wght@300..800&display=swap" rel="stylesheet">
    <link href="{{ asset('css/site.css') }}?v={{ file_exists(public_path('css/site.css')) ? filemtime(public_path('css/site.css')) : '1' }}" rel="stylesheet">
    <link href="{{ asset('css/cinema.css') }}?v={{ file_exists(public_path('css/cinema.css')) ? filemtime(public_path('css/cinema.css')) : '1' }}" rel="stylesheet">
    <style>
        .login-shell { min-height: 100vh; min-height: 100dvh; display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, .95fr); }
        .login-visual { position: relative; display: flex; flex-direction: column; justify-content: space-between; padding: clamp(40px, 5vw, 72px); border-right: 1px solid var(--line); background: linear-gradient(180deg, rgba(var(--bg-rgb), .72), rgba(var(--bg-rgb), .9)), url('{{ asset('images/portada-barberia.jpg') }}') 62% 50% / cover no-repeat, var(--surface); overflow: hidden; }
        .login-visual .brand-mark { width: 60px; height: 60px; }
        .login-visual h1 { font-size: clamp(2.8rem, 5.4vw, 5rem); font-weight: 360; letter-spacing: -.045em; line-height: .94; margin: 32px 0 22px; }
        .login-visual h1 em { color: var(--brand-red-text); font-weight: 300; }
        .login-visual p { color: var(--text-muted); max-width: 44ch; margin: 0; line-height: 1.75; }
        .feature-list { list-style: none; margin: 32px 0 0; padding: 0; display: grid; gap: 14px; }
        .feature-list li { display: flex; align-items: center; gap: 12px; color: var(--text); font-size: .92rem; }
        .feature-list i { color: var(--gold); }
        .login-visual small { color: var(--text-muted); font-family: var(--font-mono); font-size: .7rem; letter-spacing: .18em; text-transform: uppercase; }
        .login-form-wrap { display: flex; align-items: center; justify-content: center; padding: 40px 24px; }
        .login-card { width: 100%; max-width: 420px; }
        .login-card h2 { font-size: clamp(2.4rem, 4vw, 3.2rem); font-weight: 380; letter-spacing: -.04em; margin: 0 0 10px; }
        .login-card .lead-copy { color: var(--text-muted); margin: 0 0 32px; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; margin-bottom: 20px; color: var(--text-muted); font-family: var(--font-mono); font-size: .7rem; font-weight: 500; letter-spacing: .16em; text-transform: uppercase; }
        .back-link:hover { color: var(--text); }
        .forgot-link { display: inline-flex; align-items: center; min-height: 44px; color: var(--text-muted); font-size: .85rem; text-decoration: underline; text-underline-offset: 3px; }
        .forgot-link:hover { color: var(--gold); }
        .password-field { position: relative; }
        .password-field .form-control { padding-right: 56px; }
        .password-toggle { position: absolute; top: 3px; right: 3px; width: 44px; height: 44px; display: grid; place-items: center; border: 0; border-radius: var(--radius-sm); background: transparent; color: var(--text-muted); cursor: pointer; }
        .password-toggle:hover { color: var(--gold); }
        .form-check-input { width: 1.15em; height: 1.15em; background-color: var(--bg); border-color: var(--line-strong); }
        .form-check-input:checked { background-color: var(--brand-red); border-color: var(--brand-red); }
        .form-check-input:focus { box-shadow: 0 0 0 3px rgba(var(--accent-rgb), .2); border-color: var(--gold); }
        .form-check-label { color: var(--text-muted); font-size: .9rem; }
        .demo-box { margin-top: 32px; padding: 16px 18px; border: 1px dashed var(--gold-line); border-radius: var(--radius-sm); color: var(--text-muted); font-size: .84rem; line-height: 1.7; }
        .demo-box strong { color: var(--text); }
        .demo-box code { color: var(--gold); }
        @media (max-width: 900px) {
            .login-shell { grid-template-columns: 1fr; }
            .login-visual { display: none; }
        }
    </style>
</head>
<body>
<main class="login-shell">
    <section class="login-visual" aria-hidden="true">
        <div>
            <img class="brand-mark" src="{{ asset('images/logo-una-barberia.png') }}" alt="" width="60" height="60">
            <h1>Una Barberia<br><em>Muy Chimbita</em></h1>
            <p>Lleva el control de ventas, gastos, comisiones y ganancias de tu barbería de una forma fácil y organizada. Cada barbero, a su manera.</p>
            <ul class="feature-list">
                <li><i class="bi bi-check2-circle"></i> Registro rápido de servicios y métodos de pago</li>
                <li><i class="bi bi-check2-circle"></i> Comisiones calculadas automáticamente</li>
                <li><i class="bi bi-check2-circle"></i> Caja diaria, gastos y ganancias reales</li>
                <li><i class="bi bi-check2-circle"></i> Citas o atención por orden de llegada</li>
            </ul>
        </div>
        <small>Plataforma de gestión · {{ now()->year }}</small>
    </section>

    <section class="login-form-wrap">
        <div class="login-card">
            @yield('card')
        </div>
    </section>
</main>
@stack('scripts')
</body>
</html>
