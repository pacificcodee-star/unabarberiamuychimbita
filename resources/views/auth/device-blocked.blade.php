<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#0c0a08">
    <title>Solo en computador o tablet · {{ $brandName }}</title>
    @include('partials.favicon')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300..700&family=Manrope:wght@300..800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0c0a08;
            --panel: #14110d;
            --line: rgba(243, 235, 221, .12);
            --text: #f3ebdd;
            --muted: #c0b4a2;
            --subtle: #9a8e7c;
            --red: #b32533;
            --red-soft: rgba(179, 37, 51, .16);
            --gold: #c9a227;
        }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            margin: 0;
            background:
                radial-gradient(680px 420px at 50% -120px, rgba(179, 37, 51, .22), transparent 70%),
                radial-gradient(520px 340px at 85% 110%, rgba(201, 162, 39, .10), transparent 70%),
                var(--bg);
            color: var(--text);
            font-family: 'Manrope', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .wrap {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 26px;
            padding: calc(28px + env(safe-area-inset-top)) 20px calc(26px + env(safe-area-inset-bottom));
        }
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: var(--text);
            text-decoration: none;
            font-weight: 600;
            letter-spacing: .02em;
        }
        .brand img { width: 42px; height: 42px; border-radius: 10px; }
        .card {
            width: 100%;
            max-width: 520px;
            margin: auto 0;
            padding: clamp(28px, 6vw, 44px) clamp(22px, 5vw, 40px);
            background: linear-gradient(180deg, rgba(255, 255, 255, .035), rgba(255, 255, 255, 0)) , var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 30px 70px rgba(0, 0, 0, .45);
            text-align: center;
        }
        .device {
            position: relative;
            width: 84px;
            height: 84px;
            margin: 0 auto 20px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            border: 1px solid var(--line);
            background: radial-gradient(circle at 50% 30%, rgba(179, 37, 51, .30), var(--red-soft) 60%, transparent 75%);
            color: var(--text);
            font-size: 36px;
        }
        .device::after {
            content: "";
            position: absolute;
            left: 12px;
            right: 12px;
            top: 50%;
            height: 3px;
            border-radius: 3px;
            background: var(--red);
            box-shadow: 0 0 12px rgba(179, 37, 51, .65);
            transform: rotate(-42deg);
        }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 14px;
            padding: 7px 14px;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--muted);
            font-size: .74rem;
            font-weight: 600;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .chip i { color: var(--gold); }
        h1 {
            margin: 0 0 14px;
            font-family: 'Fraunces', Georgia, serif;
            font-weight: 400;
            font-size: clamp(1.85rem, 7vw, 2.5rem);
            line-height: 1.08;
            letter-spacing: -.03em;
        }
        .lead {
            margin: 0 auto 12px;
            max-width: 40ch;
            color: var(--muted);
            font-size: 1rem;
            line-height: 1.7;
        }
        .lead strong { color: var(--text); font-weight: 700; }
        .note {
            margin: 0 auto 26px;
            max-width: 42ch;
            color: var(--subtle);
            font-size: .9rem;
            line-height: 1.65;
        }
        .actions { display: grid; gap: 10px; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 48px;
            padding: 0 20px;
            border-radius: 10px;
            border: 1px solid transparent;
            font-family: inherit;
            font-size: .95rem;
            font-weight: 700;
            text-decoration: none;
            transition: transform .15s ease, background-color .2s ease, border-color .2s ease, color .2s ease;
        }
        .btn:active { transform: translateY(1px); }
        .btn-primary {
            background: linear-gradient(180deg, #c8384a, var(--red));
            border-color: var(--red);
            color: #fff;
            box-shadow: 0 12px 28px rgba(179, 37, 51, .28);
        }
        .btn-primary:hover { background: linear-gradient(180deg, #d4475a, #a11f2c); }
        .btn-ghost {
            background: transparent;
            border-color: var(--line);
            color: var(--text);
        }
        .btn-ghost:hover { border-color: rgba(243, 235, 221, .3); }
        .foot {
            margin: 0;
            color: var(--subtle);
            font-size: .74rem;
            letter-spacing: .14em;
            text-transform: uppercase;
        }
        @media (min-width: 560px) {
            .actions { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        }
    </style>
</head>
<body>
<main class="wrap">
    <a class="brand" href="{{ route('landing') }}">
        <img src="{{ asset('images/logo-una-barberia.png') }}" alt="" width="42" height="42">
        <span>{{ $brandName }}</span>
    </a>

    <section class="card" aria-labelledby="device-title">
        <div class="device" aria-hidden="true"><i class="bi bi-phone"></i></div>
        <p class="chip"><i class="bi bi-shield-lock" aria-hidden="true"></i> Acceso al panel</p>
        <h1 id="device-title">Este panel se abre en pantallas grandes</h1>
        <p class="lead">Por seguridad y para que puedas trabajar cómodamente, el ingreso al panel administrativo solo está disponible en <strong>computadores y tablets</strong>.</p>
        <p class="note">Abre esta dirección desde el computador o la tablet del negocio. Desde un celular no es posible iniciar sesión.</p>
        <div class="actions">
            <a class="btn btn-primary" href="{{ route('landing') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al sitio web</a>
            @if(!empty($whatsapp))
                <a class="btn btn-ghost" href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener"><i class="bi bi-whatsapp" aria-hidden="true"></i> Escribir por WhatsApp</a>
            @else
                <a class="btn btn-ghost" href="{{ route('public.contact') }}"><i class="bi bi-chat-dots" aria-hidden="true"></i> Contactar al negocio</a>
            @endif
        </div>
    </section>

    <p class="foot">{{ $brandName }} · Panel administrativo</p>
</main>
</body>
</html>
