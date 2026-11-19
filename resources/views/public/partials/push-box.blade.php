{{-- Caja de avisos push de un turno o cita. Recibe: $pushType ('turno'|'cita'), $pushToken, $pushOpen (bool), $pushLabel.
     Los avisos se activan solos: si el navegador ya tiene permiso no hay que tocar nada; si no, el navegador muestra
     su propia pregunta (esa pregunta la pone el navegador y ninguna página puede saltársela). --}}
@php
    $pushRoute = ['type' => $pushType, 'token' => $pushToken];
    $pushIsTurn = $pushType === 'turno';
@endphp
<div class="track-push" id="pushBox"
     data-subject="{{ $pushType }}-{{ substr($pushToken, 0, 12) }}"
     data-label="{{ $pushLabel }}"
     data-open="{{ $pushOpen ? '1' : '0' }}"
     data-auto="1"
     data-vapid="{{ config('services.webpush.public_key') }}"
     data-csrf="{{ csrf_token() }}"
     data-track-url="{{ route('track.show', $pushRoute) }}"
     data-push-url="{{ route('track.push.store', $pushRoute) }}"
     data-status-url="{{ route('track.status', $pushRoute) }}"
     data-manifest-url="{{ route('track.manifest', $pushRoute) }}"
     @if(! $pushOpen) hidden @endif>

    <div data-push-state="working" hidden>
        <div class="is-on"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Activando tus avisos…</div>
        <p class="mb-0">Si tu navegador te pregunta, elige <strong>Permitir</strong>.</p>
    </div>

    <div data-push-state="ask" hidden>
        <h2><i class="bi bi-bell" aria-hidden="true"></i> Activa tus avisos</h2>
        @if($pushIsTurn)
            <p>Te llega un aviso cuando seas el siguiente y otro cuando puedas pasar, aunque cierres el navegador.</p>
        @else
            <p>Te recordamos tu cita 1 hora antes, 15 minutos antes y a la hora exacta, aunque cierres el navegador.</p>
        @endif
        <button type="button" class="btn btn-gold" data-push-enable><i class="bi bi-bell-fill" aria-hidden="true"></i> Activar avisos</button>
    </div>

    <div data-push-state="on" hidden>
        <div class="is-on"><i class="bi bi-bell-fill" aria-hidden="true"></i> Avisos activados en este dispositivo</div>
        <p>Puedes cerrar la página. Mantén el dispositivo con internet para que te lleguen.</p>
        <button type="button" class="btn btn-ghost-light btn-sm" data-push-disable>Desactivar avisos</button>
    </div>

    <div data-push-state="ios" hidden>
        <h2><i class="bi bi-phone" aria-hidden="true"></i> En iPhone, primero agrega la página a tu inicio</h2>
        <p>Apple solo permite avisos en páginas instaladas (iOS 16.4 o más reciente):</p>
        <ul>
            <li>Abre esta página en <strong>Safari</strong>.</li>
            <li>Toca <i class="bi bi-box-arrow-up" aria-label="Compartir"></i> <strong>Compartir</strong> y luego <strong>“Agregar a inicio”</strong>.</li>
            <li>Abre la barbería desde el ícono nuevo: los avisos se activan ahí.</li>
        </ul>
    </div>

    <div data-push-state="denied" hidden>
        <h2><i class="bi bi-bell-slash" aria-hidden="true"></i> Los avisos están bloqueados</h2>
        <p>Para recibirlos, permite las notificaciones de este sitio en los ajustes de tu navegador (ícono del candado junto a la dirección) y vuelve a cargar la página.</p>
    </div>

    <div data-push-state="unsupported" hidden>
        <h2><i class="bi bi-bell-slash" aria-hidden="true"></i> Este navegador no admite avisos</h2>
        <p>Abre este enlace en Chrome, Edge, Firefox o Safari actualizados. Mientras tanto, tus avisos aparecen en la campana <i class="bi bi-bell" aria-hidden="true"></i> de arriba.</p>
    </div>

    <div data-push-state="unavailable" hidden>
        <p class="mb-0">Los avisos al celular aún no están disponibles. Tus avisos aparecen en la campana <i class="bi bi-bell" aria-hidden="true"></i> de arriba mientras tengas la página abierta.</p>
    </div>

    <div class="invalid-feedback d-block" data-push-error hidden></div>
</div>
