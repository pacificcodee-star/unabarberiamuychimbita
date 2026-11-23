@extends('layouts.public')
@section('title', 'Mis servicios · Una Barbería Muy Chimbita')
@section('robots', 'noindex, nofollow')

@section('content')
    @php
        // Reprogramar usa el mismo calendario que Reservar: 30 días desde hoy, sin los días cerrados.
        $calMax = now()->addDays(29)->toDateString();
        $calClosed = collect(range(0, 29))->map(fn ($offset) => now()->addDays($offset))
            ->filter(fn ($day) => \App\Models\SiteSetting::isClosedOn($day, $siteSettings ?? []))
            ->map(fn ($day) => $day->toDateString())->values();
    @endphp
    <div class="my-services-page">
        @include('public.partials.page-hero', ['crumb' => 'Mis servicios', 'title' => 'Mis servicios', 'ghost' => false,
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
                    <legend class="rs-label">Nueva fecha</legend>
                    {{-- Mismo calendario de Reservar (cinema.css, secciones 50 y 51): tarjeta blanca y selección azul. --}}
                    <div class="cal-picker rs-cal" data-reschedule-calendar data-closed="{{ $calClosed->toJson() }}">
                        <div class="cal-card">
                            <div class="cal-head">
                                <button type="button" class="cal-nav" data-cal-prev aria-label="Mes anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                                <p class="cal-title" data-cal-title aria-live="polite"></p>
                                <button type="button" class="cal-nav" data-cal-next aria-label="Mes siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                            </div>
                            <div class="cal-week" aria-hidden="true"><span>L</span><span>M</span><span>M</span><span>J</span><span>V</span><span>S</span><span>D</span></div>
                            <div class="cal-grid" data-cal-grid></div>
                        </div>
                        <input type="hidden" id="service-date" name="appointment_date" min="{{ today()->toDateString() }}" max="{{ $calMax }}" required>
                    </div>
                    <span class="rs-label" id="service-time-label">Hora disponible</span>
                    <div id="service-times" class="slot-grid slot-card rs-slots" role="radiogroup" aria-labelledby="service-time-label" aria-live="polite"></div>
                    <small>Al guardar, confirmas tu asistencia en el nuevo horario.</small>
                </fieldset>
                <p id="service-dialog-message" role="status"></p>
                <div class="service-dialog-actions">
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
    position: relative;
    isolation: isolate;
    background: #efe8dc;
    color: var(--text);
}
/* Foto de la barbería de fondo, fija detrás del contenido y sin velo: se ve a todo color. */
.my-services-page::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100vh;
    height: 100lvh;
    z-index: -1;
    pointer-events: none;
    background:
        url('{{ asset('images/mis-servicios-fondo.webp') }}') center / cover no-repeat,
        #efe8dc;
    /* Desenfoque suave: la foto se ve, pero no compite con el texto. El leve zoom evita los bordes difusos. */
    transform: scale(1.04);
    filter: blur(3px);
}
.my-services-page .page-hero { background: transparent !important; }
/* Sobre la foto: la ruta y las pestañas llevan su propia cápsula crema para leerse. */
.my-services-page .breadcrumb-public {
    flex-wrap: nowrap;
    width: max-content;
    padding: .4rem .8rem;
    border: 1px solid rgba(21, 18, 14, .12);
    border-radius: 999px;
    background: rgba(250, 248, 243, .82);
    -webkit-backdrop-filter: blur(6px);
    backdrop-filter: blur(6px);
}
.my-services-page .breadcrumb-public a { color: var(--text-muted); }
.my-services-page .breadcrumb-public [aria-current] { color: var(--gold); }
.my-services-page .service-tabs { background: rgba(250, 248, 243, .92); border-color: rgba(21, 18, 14, .18); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); }
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
.service-empty-actions { display: flex; flex-wrap: wrap; gap: .75rem; justify-content: center; }
.service-empty-note { margin: 1rem 0 0; font-size: .88rem; color: var(--text-subtle); }
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
.service-actions { display: flex; flex-wrap: wrap; gap: .6rem; }
.service-actions .btn { min-height: 44px; display: inline-flex; align-items: center; gap: .4rem; }
/* Confirmar (verde), reprogramar (gris) y cancelar (rojo): píldoras compactas en una fila al pie de la
   tarjeta, alineadas a la derecha y separadas del contenido por una línea. */
.service-actions.has-actions { justify-content: flex-end; gap: .45rem; padding-top: .85rem; border-top: 1px solid var(--line); }
.msv-btn-confirm, .msv-btn-reschedule, .msv-btn-cancel { border-radius: 999px; border: 1px solid transparent; color: #fff; }
.service-actions .msv-btn-confirm, .service-actions .msv-btn-reschedule, .service-actions .msv-btn-cancel {
    flex: 0 0 auto; min-height: 36px; justify-content: center; gap: .35rem; padding: .35rem .85rem;
    font-size: .8rem; font-weight: 600; line-height: 1.2; white-space: nowrap;
}
.service-actions .msv-btn-confirm > i, .service-actions .msv-btn-reschedule > i, .service-actions .msv-btn-cancel > i { font-size: .95rem; line-height: 1; }
.msv-btn-confirm { background: #2f8a5b; border-color: #2f8a5b; }
.msv-btn-confirm:hover, .msv-btn-confirm:focus-visible { background: #26714b; border-color: #26714b; color: #fff; }
.msv-btn-reschedule { background: #6f6a62; border-color: #6f6a62; }
.msv-btn-reschedule:hover, .msv-btn-reschedule:focus-visible { background: #5b574f; border-color: #5b574f; color: #fff; }
.msv-btn-cancel { background: #a23b34; border-color: #a23b34; }
.msv-btn-cancel:hover, .msv-btn-cancel:focus-visible { background: #872f29; border-color: #872f29; color: #fff; }
@media (max-width: 575.98px) {
    .msv-card { padding: 1rem; }
    .msv-when { width: 3.9rem; min-height: 4.2rem; }
    .service-actions .btn { flex: 1 1 auto; justify-content: center; }
    /* En el celular los tres botones ocupan la fila por partes iguales, sin crecer de alto. */
    .service-actions.has-actions { flex-wrap: nowrap; }
    .service-actions .msv-btn-confirm, .service-actions .msv-btn-reschedule, .service-actions .msv-btn-cancel { flex: 1 1 auto; min-width: 0; padding-inline: .55rem; font-size: .76rem; gap: .25rem; }
}
@media (prefers-reduced-motion: reduce) { .msv-card { transition: none; } }
#service-dialog { --dlg-pad: clamp(1rem, 4vw, 1.6rem); --text: #faf8f3; --text-rgb: 250, 248, 243; --line-strong: rgba(255, 255, 255, .24); --gold: #e1ba70; width: min(27rem, calc(100% - 1.5rem)); max-height: 90dvh; overflow: auto; overscroll-behavior: contain; padding: var(--dlg-pad); border: 1px solid #3a3d45; border-radius: 1.25rem; background: #141518; color: #faf8f3; }
#service-dialog::backdrop { background: rgb(0 0 0 / .72); }
#service-dialog h2 { margin: 0 0 .25rem; font-size: 1.4rem; }
#service-context { margin: 0 0 .5rem; color: rgba(250, 248, 243, .7); font-size: .9rem; }
#service-schedule { margin: 0; padding: 0; border: 0; min-width: 0; }
#service-schedule .rs-label { display: block; float: none; width: auto; margin: 1rem 0 .55rem; padding: 0; font-size: .78rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: rgba(250, 248, 243, .78); }
#service-schedule small { display: block; margin-top: .8rem; color: rgba(250, 248, 243, .7); }
#service-dialog-message { margin-block: .8rem; min-height: 1.2em; }
#service-dialog-message:empty { margin-block: .4rem; }
/* Calendario y horas con el azul de la página de Reservar. */
#service-dialog .rs-cal { width: 100%; }
#service-dialog .cal-card { box-shadow: none; }
#service-dialog .cal-week { color: var(--booking-blue, #1d4ed8); }
#service-dialog .cal-dot { background: var(--booking-blue, #1d4ed8); }
#service-dialog .cal-day.is-selected .cal-dot { background: #fff; }
#service-dialog .cal-day:hover:not(:disabled):not(.is-selected) { background: #e8eefc; }
#service-dialog .cal-day.is-selected { background: var(--booking-blue, #1d4ed8); color: #fff; }
#service-dialog .cal-day.is-today:not(.is-selected) { color: var(--booking-blue, #1d4ed8); }
#service-dialog .cal-day:focus-visible, #service-dialog .cal-nav:focus-visible { outline-color: var(--booking-blue, #1d4ed8); }
#service-dialog .slot-card { padding: 12px; border-radius: 20px; background: #fff; border: 1px solid rgba(17, 22, 30, .06); gap: 8px; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); }
#service-dialog .slot-card .slot label { min-height: 48px; padding: 0 6px; border: 1px solid #e4ecea; border-radius: 12px; background: #f4f8f7; color: #26303b; font-size: .9rem; white-space: nowrap; }
#service-dialog .slot-card .slot input:hover + label { background: #eef3fc; border-color: #c7d5f8; }
#service-dialog .slot-card .slot input:focus-visible + label { outline: 2px solid var(--booking-blue, #1d4ed8); outline-offset: 2px; }
#service-dialog .slot-card .slot input:checked + label { background: var(--booking-blue, #1d4ed8); border-color: var(--booking-blue, #1d4ed8); color: #fff; }
#service-dialog .slot-card .slot-skeleton { min-height: 48px; border-radius: 12px; background: linear-gradient(90deg, #edf3f1 0%, #f8fbfa 50%, #edf3f1 100%); background-size: 200% 100%; }
#service-dialog .slot-card .slot-status { grid-column: 1 / -1; border: 1px dashed #d9e4e1; border-radius: 12px; color: #59636f; font-size: .86rem; }
#service-dialog .slot-card .slot-status i { color: var(--booking-blue, #1d4ed8); }
/* Volver y Guardar quedan siempre a la vista al pie del diálogo, aunque la lista de horas sea larga. */
.service-dialog-actions { position: sticky; bottom: calc(-1 * var(--dlg-pad)); z-index: 1; display: flex; justify-content: flex-end; gap: .6rem; margin: .25rem calc(-1 * var(--dlg-pad)) calc(-1 * var(--dlg-pad)); padding: .75rem var(--dlg-pad) var(--dlg-pad); border-top: 1px solid rgba(255, 255, 255, .08); background: #141518; }
.service-dialog-actions .btn { min-height: 44px; border-radius: 999px; padding-inline: 1.15rem; font-size: .82rem; white-space: nowrap; }
@media (max-width: 575.98px) {
    #service-dialog { --dlg-pad: .9rem; }
    #service-dialog .cal-card { padding: 12px; }
    #service-dialog .cal-day { height: 40px; }
    .service-dialog-actions .btn { flex: 1 1 0; }
}
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
            'key' => $type.'-'.substr($s->public_token, 0, 12), // clave de la reserva en este dispositivo (campana y avisos)
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
