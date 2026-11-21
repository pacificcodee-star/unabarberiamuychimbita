@extends('layouts.public')

@section('title', 'Tu opinión · '.($siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita'))
@section('meta_description', 'Cuéntanos cómo te fue en tu visita.')
@section('robots', 'noindex, nofollow')

@section('content')
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
@endsection

@push('scripts')
    <script src="{{ asset('js/survey.js') }}?v={{ @filemtime(public_path('js/survey.js')) }}" defer></script>
@endpush
