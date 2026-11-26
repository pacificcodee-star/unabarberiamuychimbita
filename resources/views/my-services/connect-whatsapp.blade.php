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
        @if($chatUrl)
            <p>Abre WhatsApp y envía el mensaje preparado desde ese número. Luego vuelve aquí para ver tus citas y turnos.</p>
            <p><a href="{{ $chatUrl }}" target="_blank" rel="noopener noreferrer">Confirmar por WhatsApp</a></p>
            @error('whatsapp') <p role="alert">{{ $message }}</p> @enderror
        @endif
        <form method="POST" action="{{ route('my-services.whatsapp.finish') }}">
            @csrf
            <button type="submit">{{ $chatUrl ? 'Ver mis servicios' : 'Vincular este dispositivo' }}</button>
        </form>
    </main>
    @if($chatUrl)
        <script>
            const chat = document.querySelector('main a');
            const confirm = document.querySelector('main form');
            let openedChat = false;
            chat.addEventListener('click', () => { openedChat = true; });
            function checkOnReturn() {
                if (openedChat && document.visibilityState === 'visible' && document.hasFocus()) {
                    openedChat = false;
                    confirm.requestSubmit();
                }
            }
            window.addEventListener('focus', checkOnReturn);
            document.addEventListener('visibilitychange', checkOnReturn);
        </script>
    @endif
</body>
</html>
