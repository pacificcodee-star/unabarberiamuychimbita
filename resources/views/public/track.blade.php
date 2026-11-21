@extends('layouts.public')

@php
    $isTurn = $type === 'turno';
    $number = $isTurn ? '#'.str_pad((string) $subject->turn_number, 3, '0', STR_PAD_LEFT) : null;
    $heading = $isTurn ? 'Tu turno '.$number : 'Tu cita';
    $pushScript = public_path('js/push-client.js');
@endphp

@section('title', $heading.' · '.($siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita'))
@section('robots', 'noindex, nofollow')
@section('meta_description', 'Seguimiento de tu '.$type.' en '.($siteSettings['brand_name'] ?? 'la barbería').'.')

@push('styles')
    <style>
        .track-wrap { max-width: 720px; margin-inline: auto; }
        .track-status { display: flex; flex-direction: column; gap: .35rem; padding: 1.1rem 1.25rem; border: 1px solid rgba(255, 255, 255, .14); border-radius: 14px; margin-bottom: 1.25rem; }
        .track-status strong { font-size: 1.35rem; line-height: 1.2; }
        .track-status[data-status="llamado"] { border-color: #d9a441; box-shadow: 0 0 0 3px rgba(217, 164, 65, .18); }
        .track-status small { opacity: .8; }
        .track-link-note { font-size: .85rem; opacity: .75; margin-top: 1rem; }
    </style>
@endpush

@section('content')
    @include('public.partials.page-hero', [
        'crumb' => $isTurn ? 'Mi turno' : 'Mi cita',
        'title' => $heading,
        'description' => $isTurn
            ? 'Aquí ves cómo va la fila. Te avisamos cuando sea tu turno.'
            : 'Aquí ves el estado de tu cita. Te la recordamos antes de la hora.',
    ])

    <section class="section">
        <div class="container">
            <div class="track-wrap">
                @if(session('track_success'))
                    <div class="alert alert-success mb-4" role="status"><i class="bi bi-check-circle me-1" aria-hidden="true"></i> {{ session('track_success') }}</div>
                @endif
                @if(session('track_error'))
                    <div class="alert alert-danger mb-4" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i> {{ session('track_error') }}</div>
                @endif

                <div class="track-status" id="trackLive" data-status="{{ $state['status'] }}" aria-live="polite">
                    <small>Estado</small>
                    <strong data-live="label">{{ $state['label'] }}</strong>
                    <span data-live="detail">{{ $state['detail'] }}</span>
                    @if($isTurn)
                        <span data-live-eta @if(empty($state['call_at'])) hidden @endif>Hora aproximada de tu llamado: <strong data-live="call_at" style="font-size: inherit;">{{ $state['call_at'] }}</strong></span>
                    @endif
                </div>

                @include('public.partials.push-box', [
                    'pushType' => $type,
                    'pushToken' => $subject->public_token,
                    'pushOpen' => $state['open'],
                    'pushLabel' => $heading,
                ])

                <div class="booking-success">
                    <dl>
                        @if($isTurn)
                            <div>
                                <dt>Tu número de turno</dt>
                                <dd class="turn-number">{{ $number }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt>Cliente</dt>
                            <dd>{{ $subject->client_name }}</dd>
                        </div>
                        <div>
                            <dt>Barbero</dt>
                            <dd>{{ $subject->barber?->name ?? 'Por definir' }}</dd>
                        </div>
                        <div>
                            <dt>Servicio</dt>
                            <dd>{{ $subject->service?->name ?? 'Por definir' }}</dd>
                        </div>
                        @unless($isTurn)
                            <div>
                                <dt>Fecha</dt>
                                <dd>{{ $subject->startsAt()->locale('es')->translatedFormat('l j \d\e F') }}</dd>
                            </div>
                            <div>
                                <dt>Hora</dt>
                                <dd>{{ $subject->startsAt()->format('g:i') }} {{ (int) $subject->startsAt()->format('G') >= 12 ? 'p. m.' : 'a. m.' }}</dd>
                            </div>
                        @endunless
                    </dl>

                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('track.cancel', ['type' => $type, 'token' => $subject->public_token]) }}" id="trackCancel"
                              onsubmit="return confirm('¿Seguro que quieres cancelar tu {{ $type }}?');" @if(! $state['can_cancel']) hidden @endif>
                            @csrf
                            <button type="submit" class="btn btn-ghost-light"><i class="bi bi-x-circle" aria-hidden="true"></i> Cancelar mi {{ $type }}</button>
                        </form>
                        <a href="{{ route('public.booking') }}" class="btn btn-ghost-light">Hacer otra reserva</a>
                        @if(!empty($siteSettings['whatsapp']))
                            <a href="https://wa.me/{{ $siteSettings['whatsapp'] }}" class="btn btn-outline-gold" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp" aria-hidden="true"></i> Escribirnos</a>
                        @endif
                    </div>
                </div>

                <p class="track-link-note"><i class="bi bi-lock" aria-hidden="true"></i> Este enlace es personal: con él se puede ver y cancelar tu {{ $type }}. No lo compartas.</p>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/push-client.js') }}?v={{ file_exists($pushScript) ? filemtime($pushScript) : '1' }}" defer></script>
@endpush
