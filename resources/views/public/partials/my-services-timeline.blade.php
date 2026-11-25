@php
    $angle = pi() * (1 + $timeline['progress'] / 100);
    $dotX = round(60 + 52 * cos($angle), 3);
    $dotY = round(59 + 52 * sin($angle), 3);
    $duration = $timeline['duration'];
@endphp
<div class="msv-timeline" data-service-timeline
    @if($timeline['live'] && ! $history)
        data-start="{{ $timeline['start']->timestamp * 1000 }}" data-duration="{{ $duration }}"
    @endif>
    <div class="msv-timeline-time">
        <span>Inicio</span>
        @if($timeline['start'])
            <time datetime="{{ $timeline['start']->toIso8601String() }}">{{ $timeline['start']->format('g:i a') }}</time>
        @else
            <small>{{ $timeline['finished'] ? 'Sin registro' : 'Por definir' }}</small>
        @endif
    </div>
    <div class="msv-timeline-progress" role="progressbar" aria-label="Progreso del servicio"
        aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($timeline['progress']) }}">
        <svg aria-hidden="true" viewBox="0 0 120 66" fill="none">
            <defs>
                <linearGradient id="{{ $gradientId }}" x1="0%" x2="100%">
                    <stop offset="0%" stop-color="#248975"/>
                    <stop offset="46%" stop-color="#377fb9"/>
                    <stop offset="75%" stop-color="#6b50ae"/>
                    <stop offset="100%" stop-color="#a14e79"/>
                </linearGradient>
            </defs>
            <path class="msv-timeline-track" d="M 8 59 A 52 52 0 0 1 112 59" stroke-width="5" stroke-linecap="round"/>
            <path class="msv-timeline-fill" data-timeline-fill d="M 8 59 A 52 52 0 0 1 112 59"
                pathLength="100" stroke="url(#{{ $gradientId }})" stroke-width="5" stroke-linecap="round"
                stroke-dasharray="100 100" stroke-dashoffset="{{ 100 - $timeline['progress'] }}"
                opacity="{{ $timeline['progress'] > 0 ? 1 : 0 }}"/>
            <circle data-timeline-dot class="msv-timeline-dot" cx="{{ $dotX }}" cy="{{ $dotY }}" r="3.6"
                fill="#6b50ae" opacity="{{ $timeline['progress'] > 0 && ! $timeline['finished'] ? 1 : 0 }}"/>
        </svg>
        <div class="msv-timeline-caption">
            <strong data-timeline-label>@if($timeline['finished']) Finalizado @elseif($timeline['started']) {{ $timeline['elapsed'] }} de {{ $duration }} min @else {{ $duration }} min @endif</strong>
            <small data-timeline-detail @if(! $timeline['started']) hidden @endif>{{ $timeline['remaining'] ? $timeline['remaining'].' min restantes' : 'Pendiente de cierre' }}</small>
        </div>
    </div>
    <div class="msv-timeline-time">
        <span>Finalización</span>
        @if($timeline['end'])
            <time datetime="{{ $timeline['end']->toIso8601String() }}">{{ $timeline['end']->format('g:i a') }}</time>
            @unless($timeline['finished'])
                <small>Estimada</small>
            @endunless
        @else
            <small>{{ $timeline['finished'] ? 'Sin registro' : 'Por definir' }}</small>
        @endif
    </div>
</div>
