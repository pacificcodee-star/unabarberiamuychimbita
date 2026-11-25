<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Vincular este dispositivo</title>
    <style>
        body { margin: 0; padding: 1.5rem; background: #f3eadb; color: #262019; font-family: system-ui, sans-serif; }
        main { max-width: 31rem; margin: 12vh auto 0; padding: 1.5rem; background: #fffaf1; border: 1px solid #d6c5a6; border-radius: 1rem; }
        h1 { margin: 0 0 .65rem; font: 700 1.6rem Georgia, serif; }
        p { margin: 0 0 1.1rem; }
        button { min-height: 44px; padding: .65rem 1.15rem; border: 0; border-radius: 2rem; background: #2a241b; color: #fff; font: inherit; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        <h1>Vincular este dispositivo</h1>
        <p>Mostrará las reservas del WhatsApp terminado en <strong>{{ $maskedPhone }}</strong> en este navegador.</p>
        <form method="POST" action="{{ route('my-services.whatsapp.finish') }}">
            @csrf
            <button type="submit">Vincular este dispositivo</button>
        </form>
    </main>
</body>
</html>
