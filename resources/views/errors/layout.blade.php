{{--
    Plantilla de las páginas de error. Es autónoma a propósito: no consulta la base de datos ni
    depende de la sesión, así funciona aunque MySQL esté caído. Nunca muestra detalles técnicos;
    solo el id de la petición para que el equipo lo busque en el registro.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="dark">
    <title>@yield('title') · {{ config('sitio.brand_name', 'Una Barberia Muy Chimbita') }}</title>
    <link rel="icon" href="/favicon.ico">
    <style>
        :root { --bg: #0c0a08; --surface: #14110d; --text: #f3ebdd; --muted: #c0b4a2; --gold: #c9a227; --gold-hover: #e0be4e; --ink: #14110d; --red: #b32533; --line: rgba(243,235,221,.22); }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; background: var(--bg); color: var(--text); font-family: Manrope, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        body { display: grid; place-items: center; min-height: 100vh; padding: 24px 16px; }
        main { width: min(560px, 100%); }
        .brand { display: flex; align-items: center; gap: 12px; color: var(--muted); font-size: .85rem; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 40px; }
        .brand img { width: 40px; height: 40px; }
        .code { font-family: Fraunces, Georgia, serif; font-size: clamp(4rem, 18vw, 7rem); line-height: .9; color: var(--gold); margin: 0 0 12px; font-weight: 400; }
        h1 { font-family: Fraunces, Georgia, serif; font-weight: 420; font-size: clamp(1.7rem, 5vw, 2.4rem); line-height: 1.15; margin: 0 0 14px; }
        p { color: var(--muted); line-height: 1.65; margin: 0 0 24px; font-size: 1.02rem; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 22px; border-radius: 6px; font-weight: 700; font-size: .8rem; letter-spacing: .12em; text-transform: uppercase; text-decoration: none; cursor: pointer; border: 1px solid var(--gold); background: var(--gold); color: var(--ink); font-family: inherit; }
        .btn:hover { background: var(--gold-hover); }
        .btn--ghost { background: transparent; color: var(--text); border-color: var(--line); }
        .btn--ghost:hover { background: rgba(243,235,221,.08); }
        .btn:focus-visible { outline: 2px solid var(--gold); outline-offset: 3px; }
        .ref { margin-top: 36px; font-size: .78rem; color: #9a8e7c; }
        .ref code { color: var(--muted); }
    </style>
</head>
<body>
<main>
    <div class="brand"><img src="/images/logo-una-barberia-128.webp" alt="" width="40" height="40">{{ config('sitio.brand_name', 'Una Barberia Muy Chimbita') }}</div>
    <p class="code" aria-hidden="true">@yield('code')</p>
    <h1>@yield('heading')</h1>
    <p>@yield('message')</p>
    <div class="actions">
        @yield('actions')
    </div>
    @if(\App\Support\RequestId::current())
        <p class="ref">Si el problema sigue, comparte este código con la barbería: <code>{{ \Illuminate\Support\Str::limit(\App\Support\RequestId::current(), 8, '') }}</code></p>
    @endif
</main>
</body>
</html>
