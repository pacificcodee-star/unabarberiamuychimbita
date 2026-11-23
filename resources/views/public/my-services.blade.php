@extends('layouts.public')
@section('title', 'Mis servicios · Una Barbería Muy Chimbita')
@section('robots', 'noindex, nofollow')

@section('content')
    <div class="my-services-page">
        @include('public.partials.page-hero', ['crumb' => 'Mis servicios', 'title' => 'Mis servicios',
            'description' => 'Consulta tus reservas, confirma tu asistencia o elige un nuevo horario.'])
    <div class="container service-manager" data-services data-slots="{{ route('public.booking.slots') }}">
        @if(session('status'))
            <p class="service-alert" role="status">{{ session('status') }}</p>
        @endif
        <p id="service-feedback" role="status" tabindex="-1"></p>
        <div class="service-tabs" role="tablist" aria-label="Servicios activos e historial">
            <button type="button" role="tab" id="tab-activos" data-service-tab="activos" aria-selected="true" tabindex="0">Activos</button>
            <button type="button" role="tab" id="tab-historial" data-service-tab="historial" aria-selected="false" tabindex="-1">Historial</button>
        </div>
        <div id="service-list">@include('public.partials.my-services-list')</div>
        <dialog id="service-dialog" aria-labelledby="service-dialog-title">
            <form id="service-form">
                <h2 id="service-dialog-title"></h2>
                <p id="service-context"></p>
                <fieldset id="service-schedule" hidden disabled>
                    <label for="service-date">Nueva fecha</label>
                    <input class="form-control" id="service-date" name="appointment_date" type="date" min="{{ today()->toDateString() }}" required>
                    <label for="service-time">Hora disponible</label>
                    <select class="form-select" id="service-time" name="appointment_time" required></select>
                    <small>Al guardar, confirmas tu asistencia en el nuevo horario.</small>
                </fieldset>
                <p id="service-dialog-message" role="status"></p>
                <div class="service-actions">
                    <button id="service-back" class="btn btn-ghost-light" type="button" autofocus>Volver</button>
                    <button class="btn btn-gold" id="service-submit" type="submit"></button>
                </div>
            </form>
        </dialog>
    </div>
    </div>
@endsection

@push('styles')
<style>
/* Fondo crema y textos oscuros: mismo diseño que Servicios, Reservar y Contacto. */
.my-services-page {
    --bg: #efe8dc;
    --text: #15120e;
    --text-rgb: 21, 18, 14;
    --text-muted: rgba(21, 18, 14, .74);
    --text-subtle: rgba(21, 18, 14, .58);
    --line: rgba(21, 18, 14, .14);
    --line-strong: rgba(21, 18, 14, .26);
    --gold: #8a6a12;
    --gold-line: rgba(138, 106, 18, .34);
    --brand-red-text: #a23b34;
    background: #efe8dc;
    color: var(--text);
}
.my-services-page .page-hero { background: transparent !important; }
.my-services-page .page-hero-ghost { color: rgba(21, 18, 14, .07) !important; }
.service-manager { padding-block: 2rem 5rem; }
.service-alert { margin-block: 0 1.25rem; padding: .8rem 1rem; border: 1px solid rgba(138, 106, 18, .35); border-radius: .6rem; background: rgba(225, 186, 112, .22); color: #5c4a0f; }
.service-tabs { display: inline-flex; gap: .25rem; margin-block: 0 2rem; padding: .3rem; border: 1px solid var(--line); border-radius: 999px; background: rgba(21, 18, 14, .05); }
.service-tabs [role="tab"] { min-height: 44px; padding: .55rem 1.25rem; border: 1px solid transparent; border-radius: 999px; background: transparent; color: var(--text-muted); font: inherit; font-weight: 600; cursor: pointer; }
.service-tabs [role="tab"]:hover { color: var(--text); background: rgba(21, 18, 14, .07); }
.service-tabs [role="tab"][aria-selected="true"] { border-color: rgba(138, 106, 18, .45); background: rgba(201, 162, 39, .18); color: var(--gold); }
.service-tabs [role="tab"]:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
#service-list { display: block; }
.service-panel { display: grid; gap: 2.75rem; }
.service-panel[hidden] { display: none; }
.service-empty-state { max-width: 34rem; margin-inline: auto; padding: 2.5rem 1.5rem; text-align: center; border: 1px dashed var(--line-strong); border-radius: 1.25rem; background: rgba(255, 253, 248, .55); }
.msv-empty-ico { display: inline-grid; place-items: center; width: 3.5rem; height: 3.5rem; margin-bottom: 1rem; border-radius: 1rem; background: rgba(201, 162, 39, .16); color: var(--gold); font-size: 1.5rem; }
.service-empty-state h2 { margin: 0 0 .5rem; font-size: 1.45rem; }
.service-empty-state p { margin: 0 0 1.25rem; color: var(--text-muted); }
.service-empty-state .btn { min-height: 44px; }
.service-recover-link { display: block; margin-top: 1rem; color: var(--gold); text-decoration: underline; text-underline-offset: 3px; }
.service-group h2 { display: flex; align-items: center; gap: .6rem; margin: 0 0 1rem; font-size: 1.35rem; }
.msv-count { display: inline-grid; place-items: center; min-width: 1.7rem; height: 1.7rem; padding: 0 .5rem; border-radius: 999px; background: rgba(21, 18, 14, .08); color: var(--text-muted); font-family: var(--font-body, system-ui); font-size: .78rem; font-weight: 700; }
.msv-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 23rem), 1fr)); }
.msv-card { position: relative; display: grid; gap: 1rem; padding: 1.15rem; border: 1px solid var(--line); border-radius: 1.1rem; background: linear-gradient(180deg, #fbf7ef, #f4ecdf); box-shadow: 0 14px 32px rgba(64, 46, 20, .08); overflow-wrap: anywhere; transition: border-color .2s ease, transform .2s ease; }
.msv-card:hover { border-color: var(--line-strong); }
.msv-card.is-next { border-color: var(--gold-line); background: linear-gradient(180deg, #fdf4dd, #f7ecd2); box-shadow: 0 18px 40px rgba(138, 106, 18, .16); }
.msv-card.is-history { opacity: .88; }
.msv-flag { margin: 0; display: inline-flex; align-items: center; gap: .4rem; color: var(--gold); font-size: .72rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
.msv-main { display: flex; gap: 1rem; align-items: flex-start; }
.msv-when { flex: none; display: grid; justify-items: center; align-content: center; width: 4.4rem; min-height: 4.6rem; padding: .45rem .3rem; border-radius: .9rem; background: #fffdf8; border: 1px solid var(--line); box-shadow: inset 0 1px 0 rgba(255, 255, 255, .8); text-align: center; line-height: 1.05; }
.msv-when span { color: var(--text-muted); font-size: .66rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
.msv-when strong { margin: .15rem 0; font-family: var(--font-display, Georgia, serif); font-size: 1.65rem; font-weight: 600; color: var(--text); }
.msv-card.is-next .msv-when { background: rgba(201, 162, 39, .16); border-color: var(--gold-line); }
.msv-body { min-width: 0; flex: 1; display: grid; gap: .55rem; }
.service-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem .75rem; flex-wrap: wrap; }
.service-heading h3 { margin: 0; font-size: 1.12rem; line-height: 1.25; }
.msv-status { display: inline-flex; align-items: center; gap: .4rem; padding: .2rem .6rem; border-radius: 999px; font-size: .72rem; font-weight: 700; white-space: nowrap; }
.msv-status::before { content: ''; width: .45rem; height: .45rem; border-radius: 50%; background: currentColor; }
.msv-status.tone-green { background: rgba(47, 138, 91, .14); color: #26714b; }
.msv-status.tone-amber { background: rgba(240, 181, 78, .22); color: #8a5a08; }
.msv-status.tone-gold  { background: rgba(201, 162, 39, .2); color: var(--gold); }
.msv-status.tone-blue  { background: rgba(47, 111, 194, .14); color: #2f5f9e; }
.msv-status.tone-red   { background: rgba(228, 112, 106, .16); color: var(--brand-red-text); }
.msv-status.tone-muted { background: rgba(21, 18, 14, .08); color: var(--text-muted); }
.msv-meta { margin: 0; padding: 0; list-style: none; display: grid; gap: .3rem; color: var(--text-muted); font-size: .92rem; }
.msv-meta i { width: 1.1rem; margin-right: .35rem; color: var(--gold); }
.msv-queue { display: flex; gap: .75rem; align-items: center; padding: .75rem .9rem; border-radius: .8rem; background: rgba(21, 18, 14, .045); border: 1px solid var(--line); }
.msv-queue > i { font-size: 1.25rem; color: var(--gold); }
.msv-queue div { display: grid; gap: .1rem; }
.msv-queue strong { font-size: .95rem; }
.msv-queue span { color: var(--text-muted); font-size: .85rem; }
.msv-queue.is-next-up { background: rgba(47, 138, 91, .10); border-color: rgba(47, 138, 91, .35); }
.msv-queue.is-next-up > i { color: #2f8a5b; }
.service-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
.service-actions .btn { min-height: 44px; display: inline-flex; align-items: center; gap: .4rem; }
.msv-btn-cancel { border: 1px solid rgba(162, 59, 52, .35); background: transparent; color: var(--brand-red-text); }
.msv-btn-cancel:hover, .msv-btn-cancel:focus-visible { background: rgba(162, 59, 52, .1); border-color: rgba(162, 59, 52, .6); color: #7d2b25; }
@media (max-width: 575.98px) {
    .msv-card { padding: 1rem; }
    .msv-when { width: 3.9rem; min-height: 4.2rem; }
    .service-actions .btn { flex: 1 1 auto; justify-content: center; }
}
@media (prefers-reduced-motion: reduce) { .msv-card { transition: none; } }
#service-dialog { --text: #faf8f3; --text-rgb: 250, 248, 243; --line-strong: rgba(255, 255, 255, .24); --gold: #e1ba70; width: min(30rem,calc(100% - 2rem)); max-height: 85dvh; overflow: auto; overscroll-behavior: contain; padding: clamp(1rem,4vw,2rem); border: 1px solid #736348; border-radius: 1rem; background: #191714; color: #faf8f3; }
#service-dialog::backdrop { background: rgb(0 0 0 / .72); }
#service-dialog h2 { font-size: 1.45rem; }
#service-schedule label { display: block; margin: .8rem 0 .4rem; }
#service-schedule small { display: block; margin-top: .8rem; }
#service-dialog-message { margin-block: .8rem; }
@media (prefers-reduced-motion: no-preference) {
    #service-dialog[open] { animation: service-enter .16s ease-out; }
    @keyframes service-enter { from { opacity: 0; transform: translateY(5px); } }
}
</style>
@endpush
@push('scripts')
<script src="{{ asset('js/my-services.js') }}?v={{ filemtime(public_path('js/my-services.js')) }}" defer></script>
@php
    // Reservas activas de este dispositivo: se guardan para la campana y para registrar los avisos
    // (js/notify-permission.js), así no hace falta abrir la página de seguimiento.
    $deviceBookings = collect($activeCitas ?? [])->concat($activeTurns ?? [])->filter(fn ($s) => filled($s->public_token))->map(function ($s) {
        $type = $s instanceof \App\Models\Turn ? 'turno' : 'cita';
        $route = ['type' => $type, 'token' => $s->public_token];

        return [
            'key' => $type.'-'.substr($s->public_token, 0, 12), // misma clave que push-box (seguimiento)
            'label' => $type === 'turno' ? 'Tu turno #'.str_pad((string) $s->turn_number, 3, '0', STR_PAD_LEFT) : 'Tu cita',
            'url' => route('track.show', $route),
            'statusUrl' => route('track.status', $route),
            'pushUrl' => route('track.push.store', $route),
        ];
    })->values();
@endphp
<script>
(() => {
    const incoming = @json($deviceBookings);
    if (!incoming.length) return;
    try {
        const list = JSON.parse(localStorage.getItem('ubmc-bookings') || '[]').filter((item) => item && !incoming.some((b) => b.key === item.key));
        incoming.forEach((b) => list.unshift(Object.assign({ savedAt: Date.now() }, b)));
        localStorage.setItem('ubmc-bookings', JSON.stringify(list.slice(0, 5)));
        window.dispatchEvent(new Event('ubmc:bookings'));
    } catch (e) {}
})();
</script>
@endpush
