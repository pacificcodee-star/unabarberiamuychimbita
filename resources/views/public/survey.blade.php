@extends('layouts.public')

@section('title', 'Tu opinión · '.($siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita'))
@section('meta_description', 'Cuéntanos cómo te fue en tu visita.')
@section('robots', 'noindex, nofollow')

@section('content')
    <div class="survey-page">
    @if(!empty($unavailable))
    <section class="sv" aria-labelledby="sv-title">
        <div class="container sv-wrap">
            <header class="sv-head"><div class="section-kicker">Tu opinión</div></header>
            @if(config('encuestas.web'))
                <h1 class="sv-title" id="sv-title">Esta encuesta no está disponible</h1>
                <p class="sv-help">Puede que el servicio no se haya completado o que el enlace esté incompleto. Si quieres contarnos cómo te fue, escríbenos por WhatsApp; con gusto te leemos.</p>
            @else
                <h1 class="sv-title" id="sv-title">Cuéntanos tu experiencia por WhatsApp</h1>
                <p class="sv-help">La encuesta se responde en el chat de WhatsApp: cuando tu cita o turno queda atendido, te escribimos para que nos cuentes cómo te fue.</p>
            @endif
            <div class="sv-actions">
                <a class="xbtn btn-booking-blue" href="{{ route('public.booking') }}"><span>Reservar</span><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                <a class="xlink" href="{{ route('landing') }}">Ir al inicio</a>
            </div>
        </div>
    </section>
    @else
    <section class="sv" data-survey
             data-state-url="{{ route('survey.state', ['type' => $type, 'token' => $token]) }}"
             data-answer-url="{{ route('survey.answer', ['type' => $type, 'token' => $token]) }}"
             data-storage-key="encuesta:{{ substr(hash('sha256', $token), 0, 16) }}"
             aria-labelledby="sv-title">
        <div class="container sv-wrap">
            <header class="sv-head">
                <div class="section-kicker">Tu opinión</div>
                <div class="sv-progress" role="progressbar" aria-label="Avance de la encuesta" aria-valuemin="0" aria-valuemax="{{ $state['total'] }}" aria-valuenow="{{ $state['answered'] }}" data-sv-progress>
                    <span style="--p: {{ $state['total'] ? round($state['answered'] / $state['total'] * 100) : 0 }}%"></span>
                </div>
            </header>

            {{-- La primera pantalla viene del servidor (se ve aunque el JavaScript tarde); luego el guion toma el control. --}}
            <div class="sv-stage" data-sv-stage aria-live="polite">
                <div class="sv-card is-in" data-sv-card>
                    @if($state['status'] === 'completada')
                        <h1 class="sv-title" id="sv-title">¡Gracias{{ $firstName ? ', '.$firstName : '' }}!</h1>
                        <p class="sv-help">Ya respondiste esta encuesta.</p>
                    @else
                        <h1 class="sv-title" id="sv-title">¿Cómo te fue{{ $firstName ? ', '.$firstName : '' }}?</h1>
                        <p class="sv-lead">
                            @if($serviceName){{ $serviceName }}@else Tu visita @endif
                            @if($barberName) con {{ $barberName }}@endif
                            · {{ \Illuminate\Support\Str::ucfirst($serviceDate->locale('es')->translatedFormat('l j \d\e F')) }}
                        </p>
                        <p class="sv-help">Son {{ $state['total'] }} preguntas cortas. Cada respuesta se guarda al instante: si se corta la conexión, sigues donde ibas. Tu comentario solo se publica si tú lo autorizas.</p>
                        <div class="sv-actions">
                            <button type="button" class="xbtn" data-sv-start><span>{{ $state['answered'] ? 'Continuar' : 'Empezar' }}</span><i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                        </div>
                        <noscript><p class="sv-help">Para responder necesitas activar JavaScript, o escríbenos por WhatsApp.</p></noscript>
                    @endif
                </div>
            </div>

            <p class="sv-status" data-sv-status role="status" aria-live="polite"></p>
        </div>
        <script type="application/json" data-sv-initial>@json($state)</script>
    </section>
    @endif
    </div>
@endsection

@push('styles')
<style>
/* Encuesta con el mismo diseño de Mis servicios y Contacto: paleta crema, foto de la barbería fija
   de fondo y el contenido dentro de una tarjeta crema centrada, para leerse bien sobre la foto. */
.survey-page {
    --bg: #efe8dc;
    --bg-rgb: 239, 232, 220;
    --surface: #fdfaf4;
    --surface-2: #f7f0e4;
    --text: #15120e;
    --text-rgb: 21, 18, 14;
    --text-muted: rgba(21, 18, 14, .72);
    --text-subtle: rgba(21, 18, 14, .56);
    --line: rgba(21, 18, 14, .12);
    --line-strong: rgba(21, 18, 14, .22);
    --gold: #8a6a12;
    --gold-line: rgba(138, 106, 18, .45);
    --gold-soft: rgba(201, 162, 39, .16);
    position: relative;
    isolation: isolate;
    background: var(--bg);
    color: var(--text);
}
.survey-page::before {
    content: '';
    position: fixed;
    inset: 0 0 auto;
    height: 100lvh;
    z-index: -1;
    pointer-events: none;
    background: url('{{ asset('images/mis-servicios-fondo.webp') }}') center / cover no-repeat, var(--bg);
    transform: scale(1.04);
    filter: blur(3px);
}
.survey-page .sv { min-height: 100svh; padding: calc(var(--header-space) + clamp(20px, 4vw, 48px)) 0 clamp(40px, 7vw, 88px); }
.survey-page .sv-wrap {
    width: min(100% - 2 * var(--gutter), 46rem);
    max-width: none;
    padding: clamp(1.4rem, 4vw, 2.75rem);
    border: 1px solid rgba(255, 255, 255, .6);
    border-radius: 1.5rem;
    background: rgba(251, 247, 239, .94);
    box-shadow: 0 30px 70px rgba(21, 18, 14, .22), inset 0 1px 0 rgba(255, 255, 255, .8);
    -webkit-backdrop-filter: blur(10px);
    backdrop-filter: blur(10px);
}
.survey-page .sv-head { margin-bottom: clamp(20px, 4vw, 36px); }
.survey-page .sv-head .section-kicker { color: var(--gold); }
.survey-page .sv-progress { background: rgba(21, 18, 14, .1); height: 4px; }
.survey-page .sv-progress span { background: linear-gradient(90deg, #c9a227, var(--gold)); }
.survey-page .sv-title { color: var(--text); font-size: clamp(1.7rem, 4.2vw, 2.6rem); }
.survey-page .sv-lead { color: var(--gold); font-weight: 600; font-size: 1.05rem; }
.survey-page .sv-help { color: var(--text-muted); font-size: 1rem; }
.survey-page .sv-step { color: var(--text-subtle); }
/* Opciones, estrellas y comentario: tarjetas blancas suaves con selección dorada. */
.survey-page .sv-options { max-width: none; }
.survey-page .sv-option,
.survey-page .sv-star,
.survey-page .sv-textarea { background: #fff; border-color: var(--line-strong); color: var(--text); box-shadow: 0 2px 8px rgba(21, 18, 14, .04); }
.survey-page .sv-option:hover { border-color: var(--gold-line); background: #fffdf8; }
.survey-page .sv-option.is-selected { border-color: var(--gold); background: #fbf2dc; }
.survey-page .sv-option-key { background: var(--surface-2); border-color: var(--line); color: var(--text-muted); }
.survey-page .sv-star { color: rgba(21, 18, 14, .22); }
.survey-page .sv-star.is-on { color: #c9a227; border-color: var(--gold-line); background: #fbf2dc; }
.survey-page .sv-star-word { color: var(--gold); }
.survey-page .sv-textarea { max-width: none; }
.survey-page .sv-textarea::placeholder { color: var(--text-subtle); }
.survey-page .sv-nav { max-width: none; }
.survey-page .sv-link { color: var(--text-muted); }
.survey-page .sv-link:hover { color: var(--gold); }
.survey-page .sv-status { color: var(--text-muted); }
.survey-page .sv-end-icon { background: #2f8a5b; color: #fff; }
.survey-page .sv-star:focus-visible, .survey-page .sv-option:focus-visible, .survey-page .sv-textarea:focus-visible, .survey-page .sv-link:focus-visible { outline-color: var(--booking-blue); }
/* Botón principal en el azul de Reservar, como en Contacto. */
.survey-page .xbtn,
.survey-page .xbtn:is(:hover, :focus, :focus-visible, :active) { background: var(--booking-blue); border-color: var(--booking-blue); color: #fff; }
.survey-page .xbtn:is(:hover, :focus-visible) { background: var(--booking-blue-hover); }
.survey-page .xbtn > i { background: rgba(255, 255, 255, .16); color: #fff; }
.survey-page .xlink { color: var(--text); }
@media (max-width: 575.98px) {
    /* La tarjeta arriba y la página siempre a pantalla completa (el pie no sube entre pregunta y pregunta);
       abajo queda espacio para el menú flotante. */
    .survey-page .sv { min-height: 100svh; align-items: start; padding-top: calc(var(--header-space) + 12px); padding-bottom: calc(112px + env(safe-area-inset-bottom, 0px)); }
    .survey-page .sv-wrap { width: calc(100% - 1.5rem); border-radius: 1.25rem; }
    .survey-page .sv-head { gap: 10px; }
    .survey-page .sv-progress { flex-basis: 100%; max-width: none; }
    .survey-page .sv-option { min-height: 52px; padding: 8px 12px; font-size: .98rem; }
    /* Las cinco estrellas siempre caben en una fila, del ancho que haya. */
    .survey-page .sv-stars { gap: 6px; }
    .survey-page .sv-star { flex: 1 1 0; width: auto; height: auto; aspect-ratio: 1; max-width: 60px; min-width: 0; border-radius: 10px; }
    .survey-page .sv-actions { gap: 12px 20px; }
    .survey-page .sv-actions .xbtn { flex: 1 1 100%; justify-content: space-between; }
    .survey-page .sv-nav { margin-top: 12px; }
}
</style>
@endpush

@if(empty($unavailable))
@push('scripts')
    <script src="{{ asset('js/survey.js') }}?v={{ @filemtime(public_path('js/survey.js')) }}" defer></script>
@endpush
@endif
