@extends('layouts.app')

@section('title', 'Chat · '.($conversation->client_name ?: $conversation->phone))

@section('actions')
    <a href="{{ route('whatsapp.chat.index') }}" class="btn btn-outline-dark"><i class="bi bi-arrow-left"></i> Bandeja</a>
@endsection

@php
    $contactName = $conversation->client_name ?: 'Cliente sin nombre';
    $initial = mb_strtoupper(mb_substr($conversation->client_name ?: $conversation->phone, 0, 1));
@endphp

@section('content')
    <div class="chat-layout">
        {{-- El chat se ve como WhatsApp dentro de un iPhone --}}
        <div class="phone-stage">
        {{-- Maqueta del iPhone 18 Pro Max: 78,0 × 163,4 mm, pantalla 6,9" (1320 × 2868), isla dinámica reducida --}}
        <div class="iphone" data-finish="burgundy" role="region" aria-label="Conversación con {{ $contactName }}">
            <span class="iphone-btn is-action" aria-hidden="true"></span>
            <span class="iphone-btn is-vol-up" aria-hidden="true"></span>
            <span class="iphone-btn is-vol-down" aria-hidden="true"></span>
            <span class="iphone-btn is-side" aria-hidden="true"></span>
            <span class="iphone-btn is-camera" aria-hidden="true"></span>
            <span class="iphone-band is-top" aria-hidden="true"></span>
            <span class="iphone-band is-bottom" aria-hidden="true"></span>
            <div class="iphone-screen">
                <div class="ios-status" aria-hidden="true">
                    <span class="ios-clock" data-ios-clock>{{ now()->format('H:i') }}</span>
                    <span class="ios-island"></span>
                    <span class="ios-indicators">
                        <i class="bi bi-reception-4"></i>
                        <i class="bi bi-wifi"></i>
                        <span class="ios-battery"><span></span></span>
                    </span>
                </div>

                <header class="ios-nav">
                    <a href="{{ route('whatsapp.chat.index') }}" class="ios-back" aria-label="Volver a la bandeja"><i class="bi bi-chevron-left"></i></a>
                    <span class="ios-avatar" aria-hidden="true">{{ $initial }}</span>
                    <span class="ios-contact">
                        <strong>{{ $contactName }}</strong>
                        <small>{{ $conversation->bot_active ? 'Responde el bot automático' : 'Bot en pausa · responde el equipo' }}</small>
                    </span>
                    <span class="ios-nav-icons" aria-hidden="true"><i class="bi bi-camera-video"></i><i class="bi bi-telephone"></i></span>
                </header>

                <div class="ios-thread" id="wa-thread" tabindex="0" aria-label="Mensajes">
                    <div class="ios-messages" id="wa-messages" data-last="{{ $messages->last()?->id ?? 0 }}" aria-live="polite">
                        <div class="ios-notice"><i class="bi bi-lock-fill" aria-hidden="true"></i> Los mensajes de este chat se guardan en el panel de la barbería.</div>
                        @php($previousDay = null)
                        @forelse($messages as $message)
                            @include('whatsapp.chat.partials.message', ['message' => $message, 'previousDay' => $previousDay])
                            @php($previousDay = $message->created_at?->format('d/m/Y'))
                        @empty
                            <div class="ios-empty" data-ios-empty>Todavía no hay mensajes en esta conversación.</div>
                        @endforelse
                    </div>
                </div>

                <form method="POST" action="{{ route('whatsapp.chat.send', $conversation) }}" class="ios-composer" data-chat-send>
                    @csrf
                    <span class="ios-composer-plus" aria-hidden="true"><i class="bi bi-plus-lg"></i></span>
                    <label for="wa-body" class="visually-hidden">Mensaje</label>
                    <input type="text" id="wa-body" name="body" maxlength="2000" autocomplete="off" placeholder="Mensaje" required>
                    <button type="submit" class="ios-send" aria-label="Enviar mensaje"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M1.101 21.757 23.8 12.028 1.101 2.3l.011 7.912 13.623 1.816-13.623 1.817-.011 7.912z"/></svg></button>
                </form>
                <span class="ios-home" aria-hidden="true"></span>
            </div>
        </div>
        <div class="finish-picker" role="radiogroup" aria-label="Color del iPhone">
            @foreach(['black' => 'Negro', 'silver' => 'Plata', 'glacier' => 'Glaciar', 'burgundy' => 'Burdeos'] as $finish => $finishLabel)
                <button type="button" role="radio" class="finish-swatch" data-finish-option="{{ $finish }}" aria-checked="{{ $finish === 'burgundy' ? 'true' : 'false' }}" title="{{ $finishLabel }}"><span class="is-{{ $finish }}"></span><small>{{ $finishLabel }}</small></button>
            @endforeach
        </div>
        </div>

        {{-- Datos del cliente y controles del bot --}}
        <aside class="chat-side">
            <div class="card">
                <div class="card-body chat-contact">
                    <span class="chat-contact-avatar" aria-hidden="true">{{ $initial }}</span>
                    <strong>{{ $contactName }}</strong>
                    <span class="text-muted">{{ $conversation->phone }}</span>
                    <a class="btn btn-outline-dark btn-sm mt-2" href="https://wa.me/{{ $conversation->phone }}" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> Abrir en WhatsApp</a>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-robot"></i> Bot automático</div>
                <div class="card-body">
                    <div class="data-row">
                        <span>Estado</span>
                        <span class="badge-soft {{ $conversation->bot_active ? 'green' : 'red' }}">{{ $conversation->bot_active ? 'Activo' : 'En pausa' }}</span>
                    </div>
                    <p class="form-text mb-3">Si el equipo responde, conviene pausar el bot en este chat para evitar respuestas dobles.</p>
                    <form method="POST" action="{{ route('whatsapp.chat.bot', $conversation) }}">
                        @csrf
                        <button type="submit" class="btn {{ $conversation->bot_active ? 'btn-outline-dark' : 'btn-gold' }} w-100">
                            <i class="bi {{ $conversation->bot_active ? 'bi-pause-circle' : 'bi-play-circle' }}"></i>
                            {{ $conversation->bot_active ? 'Pausar bot' : 'Reanudar bot' }}
                        </button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('whatsapp.chat.destroy', $conversation) }}"
                          onsubmit="return confirm('¿Eliminar este chat de la bandeja? Se borran todos sus mensajes del panel (no del teléfono). No se puede deshacer.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-trash3"></i> Eliminar chat</button>
                    </form>
                    <p class="form-text mb-0">Se borran los mensajes del panel, no los del teléfono.</p>
                </div>
            </div>
        </aside>
    </div>
@endsection

@push('styles')
<style>
    .chat-layout { display: grid; grid-template-columns: auto minmax(260px, 340px); gap: 24px; justify-content: center; align-items: start; }
    .chat-side { display: grid; gap: var(--ui-gap); }
    .chat-contact { display: grid; justify-items: center; gap: 2px; text-align: center; }
    .chat-contact strong { font-size: 1rem; }
    .chat-contact-avatar { display: grid; place-items: center; width: 64px; height: 64px; margin-bottom: 8px; border-radius: 50%; background: linear-gradient(140deg, #25d366, #128c7e); color: #fff; font-size: 1.5rem; font-weight: 800; }
    @media (max-width: 991.98px) { .chat-layout { grid-template-columns: 1fr; justify-items: center; } .chat-side { width: min(100%, 420px); } .iphone { height: min(820px, 150vw); } }

    /* ---------- iPhone 18 Pro Max ----------
       Proporción real 78,0 × 163,4 mm. 1 mm ≈ 5,2 px a este tamaño:
       marco de titanio/aluminio de ~1,2 mm, esquinas continuas, isla dinámica
       reducida (~13,5 mm), botón de acción, volumen, botón lateral y Camera Control. */
    .phone-stage { display: grid; justify-items: center; gap: 16px; }
    .iphone {
        --frame-1: #6d1f2b; --frame-2: #3d0f17; --frame-hi: #b2556a; --btn: #521722;
        position: relative;
        height: min(840px, calc(100vh - 190px)); min-height: 600px;
        aspect-ratio: 78 / 163.4;
        padding: 7px;
        border-radius: 60px / 59px;
        background:
            linear-gradient(90deg, var(--frame-hi) 0, transparent 1.4%, transparent 98.6%, var(--frame-hi) 100%),
            linear-gradient(160deg, var(--frame-1), var(--frame-2) 55%, var(--frame-1));
        box-shadow:
            inset 0 0 0 1px rgba(255, 255, 255, .18),
            inset 0 0 0 3px rgba(0, 0, 0, .35),
            0 0 0 1px rgba(0, 0, 0, .25),
            0 40px 80px -20px rgba(20, 10, 10, .45),
            0 18px 30px -18px rgba(20, 10, 10, .35);
    }
    .iphone[data-finish="black"]    { --frame-1: #3b3b3f; --frame-2: #17171a; --frame-hi: #6c6c72; --btn: #2a2a2e; }
    .iphone[data-finish="silver"]   { --frame-1: #e6e6e8; --frame-2: #b9babd; --frame-hi: #ffffff; --btn: #c9cacd; }
    .iphone[data-finish="glacier"]  { --frame-1: #cfdfea; --frame-2: #9fb7c8; --frame-hi: #eef6fb; --btn: #b3c8d6; }
    .iphone[data-finish="burgundy"] { --frame-1: #6d1f2b; --frame-2: #3d0f17; --frame-hi: #b2556a; --btn: #521722; }

    /* Botones laterales (sobresalen del marco) */
    .iphone-btn { position: absolute; width: 4px; border-radius: 2px; background: linear-gradient(90deg, var(--frame-hi), var(--btn)); box-shadow: 0 1px 2px rgba(0, 0, 0, .3); }
    .iphone-btn.is-action   { left: -3px; top: 17%;  height: 4.4%; }
    .iphone-btn.is-vol-up   { left: -3px; top: 24%;  height: 7.6%; }
    .iphone-btn.is-vol-down { left: -3px; top: 33%;  height: 7.6%; }
    .iphone-btn.is-side     { right: -3px; top: 26%; height: 11%; background: linear-gradient(270deg, var(--frame-hi), var(--btn)); }
    .iphone-btn.is-camera   { right: -2px; top: 56%; width: 3px; height: 6.4%; border-radius: 2px; background: linear-gradient(270deg, rgba(255, 255, 255, .55), rgba(0, 0, 0, .35)); }
    /* Bandas de antena */
    .iphone-band { position: absolute; width: 6px; height: 2px; background: rgba(0, 0, 0, .22); }
    .iphone-band.is-top { right: 0; top: 9%; }
    .iphone-band.is-bottom { left: 0; bottom: 9%; }

    .iphone-screen {
        position: relative;
        display: flex; flex-direction: column;
        height: 100%;
        overflow: hidden;
        border-radius: 53px / 52px;
        background: #efe7de;
        color: #111b21;
        box-shadow: 0 0 0 2px #000;
        font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Helvetica Neue', var(--font-ui);
    }

    /* Barra de estado e isla dinámica (más pequeña: Face ID bajo la pantalla) */
    .ios-status { position: relative; display: flex; align-items: center; justify-content: space-between; height: 54px; padding: 16px 30px 0 38px; background: #f6f6f6; font-size: 16px; font-weight: 600; letter-spacing: -.01em; }
    .ios-island { position: absolute; left: 50%; top: 11px; width: 18.5%; aspect-ratio: 2.55; transform: translateX(-50%); border-radius: 999px; background: #000; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .04); }
    .ios-island::after { content: ''; position: absolute; right: 18%; top: 50%; width: 22%; aspect-ratio: 1; transform: translateY(-50%); border-radius: 50%; background: radial-gradient(circle at 35% 35%, #3b4a6b, #0b0f1a 60%); }
    .ios-indicators { display: inline-flex; align-items: center; gap: 6px; font-size: 15px; }
    .ios-battery { position: relative; display: inline-block; width: 25px; height: 12px; padding: 1.5px; border: 1.2px solid rgba(0, 0, 0, .45); border-radius: 4px; }
    .ios-battery::after { content: ''; position: absolute; right: -4px; top: 3px; width: 2px; height: 4px; border-radius: 0 1px 1px 0; background: rgba(0, 0, 0, .45); }
    .ios-battery span { display: block; width: 78%; height: 100%; border-radius: 2px; background: #111; }

    /* Selector de acabado */
    .finish-picker { display: inline-flex; gap: 6px; padding: 5px; border: 1px solid var(--ui-border); border-radius: 999px; background: var(--ui-surface); box-shadow: var(--ui-shadow-sm); }
    .finish-swatch { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px 4px 5px; border: 0; border-radius: 999px; background: transparent; color: var(--ui-text-2); font-size: .74rem; font-weight: 700; }
    .finish-swatch span { width: 18px; height: 18px; border-radius: 50%; box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .15); }
    .finish-swatch .is-black { background: linear-gradient(140deg, #4a4a4f, #17171a); }
    .finish-swatch .is-silver { background: linear-gradient(140deg, #f4f4f5, #b9babd); }
    .finish-swatch .is-glacier { background: linear-gradient(140deg, #e3eef5, #9fb7c8); }
    .finish-swatch .is-burgundy { background: linear-gradient(140deg, #8a2a3a, #3d0f17); }
    .finish-swatch[aria-checked="true"] { background: var(--ui-surface-2); color: var(--ui-text); box-shadow: inset 0 0 0 1px var(--ui-border-strong); }
    .finish-swatch:focus-visible { outline: 2px solid var(--tone); outline-offset: 2px; }

    /* Encabezado del contacto */
    .ios-nav { display: flex; align-items: center; gap: 8px; padding: 6px 12px 8px 8px; background: #f6f6f6; border-bottom: 1px solid rgba(0, 0, 0, .08); }
    .ios-back { display: grid; place-items: center; width: 30px; height: 36px; color: #007aff; font-size: 22px; text-decoration: none; }
    .ios-back:hover { color: #0062cc; }
    .ios-avatar { flex: none; display: grid; place-items: center; width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(140deg, #25d366, #128c7e); color: #fff; font-size: 15px; font-weight: 700; }
    .ios-contact { flex: 1; min-width: 0; display: grid; line-height: 1.2; }
    .ios-contact strong { font-size: 16px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ios-contact small { font-size: 12px; color: #667781; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ios-nav-icons { display: inline-flex; gap: 18px; color: #007aff; font-size: 20px; }

    /* Hilo con el fondo clásico de WhatsApp */
    .ios-thread {
        flex: 1; min-height: 0;
        overflow-y: auto; overscroll-behavior: contain;
        padding: 10px 12px 14px;
        background-color: #efe7de;
        background-image:
            radial-gradient(circle at 20% 20%, rgba(0, 0, 0, .035) 1.5px, transparent 2px),
            radial-gradient(circle at 70% 60%, rgba(0, 0, 0, .03) 1.5px, transparent 2px);
        background-size: 34px 34px, 46px 46px;
        scrollbar-width: none;
    }
    .ios-thread::-webkit-scrollbar { display: none; }
    .ios-thread:focus-visible { outline: 2px solid #007aff; outline-offset: -2px; }
    .ios-messages { display: flex; flex-direction: column; gap: 3px; }
    .ios-notice { align-self: center; max-width: 86%; margin: 4px 0 10px; padding: 6px 10px; border-radius: 8px; background: #fff4c4; color: #54656f; font-size: 12px; text-align: center; line-height: 1.35; }
    .ios-notice .bi { font-size: 10px; }
    .ios-day { align-self: center; margin: 10px 0 6px; }
    .ios-day span { display: inline-block; padding: 4px 10px; border-radius: 8px; background: rgba(255, 255, 255, .92); color: #54656f; font-size: 12px; font-weight: 500; box-shadow: 0 1px 1px rgba(0, 0, 0, .06); }
    .ios-empty { align-self: center; margin-top: 40px; padding: 8px 12px; border-radius: 8px; background: rgba(255, 255, 255, .9); color: #54656f; font-size: 13px; }

    /* Globos con su colita */
    .ios-row { display: flex; }
    .ios-row.is-in { justify-content: flex-start; }
    .ios-row.is-out { justify-content: flex-end; }
    .ios-row.is-in + .ios-row.is-out, .ios-row.is-out + .ios-row.is-in { margin-top: 8px; }
    .ios-bubble {
        position: relative;
        max-width: 82%;
        padding: 6px 8px 6px 10px;
        border-radius: 12px;
        font-size: 14.5px;
        line-height: 1.35;
        box-shadow: 0 1px .5px rgba(11, 20, 26, .13);
        overflow-wrap: anywhere;
    }
    .is-in .ios-bubble { background: #fff; border-top-left-radius: 4px; }
    .is-out .ios-bubble { background: #d9fdd3; border-top-right-radius: 4px; }
    .is-in .ios-bubble::before, .is-out .ios-bubble::before {
        content: ''; position: absolute; top: 0; width: 10px; height: 13px;
    }
    .is-in .ios-bubble::before { left: -7px; background: #fff; clip-path: polygon(100% 0, 0 0, 100% 100%); }
    .is-out .ios-bubble::before { right: -7px; background: #d9fdd3; clip-path: polygon(0 0, 100% 0, 0 100%); }
    .ios-text { white-space: pre-wrap; }
    .ios-media { color: #667781; }
    .ios-meta { float: right; display: inline-flex; align-items: center; gap: 3px; margin: 8px 0 -4px 10px; color: #667781; font-size: 11px; line-height: 1; white-space: nowrap; }
    .ios-ticks { font-size: 15px; line-height: 1; }
    .ios-ticks.is-read { color: #53bdeb; }
    .ios-ticks.is-failed { color: #e0245e; font-size: 12px; }

    /* Barra para escribir */
    .ios-composer { display: flex; align-items: center; gap: 8px; padding: 8px 14px 30px; background: #f6f6f6; border-top: 1px solid rgba(0, 0, 0, .06); }
    .ios-composer-plus { display: grid; place-items: center; width: 30px; color: #007aff; font-size: 22px; }
    .ios-composer input {
        flex: 1; min-width: 0; height: 36px;
        padding: 0 14px;
        border: 1px solid rgba(0, 0, 0, .1);
        border-radius: 18px;
        background: #fff;
        color: #111b21;
        font-size: 15px;
        outline: none;
    }
    .ios-composer input:focus { border-color: #25d366; box-shadow: 0 0 0 3px rgba(37, 211, 102, .18); }
    .ios-send { display: grid; place-items: center; width: 36px; height: 36px; padding: 0; border: 0; border-radius: 50%; background: #25d366; color: #fff; font-size: 15px; cursor: pointer; transition: transform .15s ease, background-color .15s ease; }
    .ios-send:hover { background: #1fb858; }
    .ios-send:active { transform: scale(.92); }
    .ios-send:focus-visible { outline: 2px solid #007aff; outline-offset: 2px; }
    .ios-send svg { transform: translateX(1px); }
    .ios-home { position: absolute; left: 50%; bottom: 8px; width: 36%; height: 5px; transform: translateX(-50%); border-radius: 3px; background: #111; }

    /* Modo oscuro: WhatsApp oscuro de iOS */
    html.admin-dark .iphone-screen { background: #0b141a; color: #e9edef; }
    html.admin-dark .ios-status, html.admin-dark .ios-nav, html.admin-dark .ios-composer { background: #1f2c34; border-color: rgba(255, 255, 255, .06); color: #e9edef; }
    html.admin-dark .ios-battery { border-color: rgba(255, 255, 255, .55); }
    html.admin-dark .ios-battery::after { background: rgba(255, 255, 255, .55); }
    html.admin-dark .ios-battery span { background: #e9edef; }
    html.admin-dark .ios-thread { background-color: #0b141a; background-image: radial-gradient(circle at 20% 20%, rgba(255, 255, 255, .03) 1.5px, transparent 2px), radial-gradient(circle at 70% 60%, rgba(255, 255, 255, .025) 1.5px, transparent 2px); }
    html.admin-dark .is-in .ios-bubble, html.admin-dark .is-in .ios-bubble::before { background: #202c33; }
    html.admin-dark .is-out .ios-bubble, html.admin-dark .is-out .ios-bubble::before { background: #005c4b; }
    html.admin-dark .ios-meta, html.admin-dark .ios-contact small, html.admin-dark .ios-media { color: #8696a0; }
    html.admin-dark .ios-day span, html.admin-dark .ios-empty { background: #182229; color: #8696a0; }
    html.admin-dark .ios-notice { background: #182229; color: #ffd279; }
    html.admin-dark .ios-composer input { background: #2a3942; border-color: transparent; color: #e9edef; }
    html.admin-dark .ios-home { background: #e9edef; }

    @media (prefers-reduced-motion: reduce) { .ios-send { transition: none; } }
</style>
@endpush

@push('scripts')
    <script>
        (function () {
            const thread = document.getElementById('wa-thread');
            const box = document.getElementById('wa-messages');
            const url = @json(route('whatsapp.chat.messages', $conversation));
            let last = parseInt(box.dataset.last || '0', 10);
            let busy = false;

            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const ticks = { read: ['is-read', 'bi-check2-all'], delivered: ['is-delivered', 'bi-check2-all'], sent: ['is-sent', 'bi-check2'], failed: ['is-failed', 'bi-exclamation-circle-fill'] };
            const lastDay = () => { const days = box.querySelectorAll('[data-day]'); return days.length ? days[days.length - 1].dataset.day : null; };
            const today = () => { const d = new Date(); return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear(); };

            // Acabado del iPhone: se recuerda en este navegador
            const phone = document.querySelector('.iphone');
            const swatches = document.querySelectorAll('[data-finish-option]');
            const setFinish = (finish) => {
                phone.dataset.finish = finish;
                swatches.forEach((b) => b.setAttribute('aria-checked', b.dataset.finishOption === finish ? 'true' : 'false'));
                try { localStorage.setItem('panel:iphone-finish', finish); } catch (e) {}
            };
            try { const saved = localStorage.getItem('panel:iphone-finish'); if (saved) setFinish(saved); } catch (e) {}
            swatches.forEach((b) => b.addEventListener('click', () => setFinish(b.dataset.finishOption)));

            // Reloj de la barra de estado del iPhone
            const clock = document.querySelector('[data-ios-clock]');
            const tick = () => { const d = new Date(); clock.textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
            if (clock) { tick(); setInterval(tick, 30000); }

            function toBottom() { thread.scrollTop = thread.scrollHeight; }
            toBottom();

            async function refresh() {
                if (busy) return;
                busy = true;
                try {
                    const res = await fetch(url + '?after=' + last, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (!data.messages.length) return;
                    box.querySelector('[data-ios-empty]')?.remove();
                    data.messages.forEach((m) => {
                        last = Math.max(last, m.id);
                        if (m.day && m.day !== lastDay()) {
                            const sep = document.createElement('div');
                            sep.className = 'ios-day';
                            sep.dataset.day = m.day;
                            sep.innerHTML = '<span>' + (m.day === today() ? 'Hoy' : esc(m.day)) + '</span>';
                            box.appendChild(sep);
                        }
                        const out = m.direction === 'out';
                        const text = m.type === 'text' && m.body ? '<span class="ios-text">' + esc(m.body) + '</span>' : '<em class="ios-text ios-media">[' + esc(m.type === 'text' ? 'sin texto' : m.type) + ']</em>';
                        const [tickClass, tickIcon] = ticks[m.status_key] || ticks.sent;
                        const meta = '<span class="ios-meta">' + esc(m.time || m.created_at) +
                            (out ? ' <span class="ios-ticks ' + tickClass + '" title="' + esc(m.status) + '" aria-label="' + esc(m.status) + '"><i class="bi ' + tickIcon + '"></i></span>' : '') + '</span>';
                        const row = document.createElement('div');
                        row.className = 'ios-row ' + (out ? 'is-out' : 'is-in');
                        row.innerHTML = '<div class="ios-bubble">' + text + meta + '</div>';
                        box.appendChild(row);
                    });
                    box.dataset.last = last;
                    toBottom();
                } finally { busy = false; }
            }

            setInterval(refresh, 5000);
            document.querySelector('[data-chat-send]')?.addEventListener('submit', () => setTimeout(refresh, 400));
        })();
    </script>
@endpush
