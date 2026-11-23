@extends('layouts.public')
@section('title', 'Recuperar mis servicios · Una Barbería Muy Chimbita')
@section('robots', 'noindex, nofollow')

@section('content')
    @include('public.partials.page-hero', ['crumb' => 'Recuperar mis servicios', 'title' => 'Recuperar mis servicios',
        'description' => 'Verifica el celular con el que reservaste y vuelve a ver tus citas y turnos en este dispositivo.'])

    <div class="container recover-wrap">
        <div class="recover-card">
            @if($errors->any())
                <p class="recover-alert" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> {{ $errors->first() }}</p>
            @endif

            @if($step === 'phone')
                <h2>Paso 1: tu celular</h2>
                <p>Te enviaremos un código por WhatsApp al número con el que hiciste tu reserva.</p>
                @unless($available)
                    <p class="recover-alert" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i> El envío automático no está disponible ahora. Si no recibes el código, escríbenos por WhatsApp para ayudarte.</p>
                @endunless
                <form method="POST" action="{{ route('my-services.recover.send') }}" novalidate>
                    @csrf
                    <label class="form-label" for="recover-phone">Número de celular</label>
                    <input class="form-control {{ $errors->has('phone') ? 'is-invalid' : '' }}" id="recover-phone" name="phone" type="tel"
                        value="{{ old('phone') }}" autocomplete="tel" inputmode="tel" maxlength="30" placeholder="Ej. 300 123 4567"
                        aria-describedby="recover-phone-help" required>
                    <small id="recover-phone-help">El mismo número con el que reservaste la cita o pediste el turno.</small>
                    <div class="form-check recover-consent">
                        <input class="form-check-input" type="checkbox" name="consent" id="recover-consent" value="1" @checked(old('consent')) required>
                        <label class="form-check-label" for="recover-consent">Confirmo que este número es mío y acepto la <a href="{{ route('legal.privacy') }}">política de privacidad</a>.</label>
                    </div>
                    <button class="btn btn-gold btn-lg w-100" type="submit">Enviar código</button>
                </form>
            @else
                <h2>Paso 2: el código</h2>
                <p>Si ese número tiene reservas, te enviamos un código de 6 dígitos por WhatsApp. Vence en 10 minutos y solo sirve una vez.</p>
                <form method="POST" action="{{ route('my-services.recover.check') }}" novalidate>
                    @csrf
                    <label class="form-label" for="recover-code">Código de 6 dígitos</label>
                    <input class="form-control recover-code {{ $errors->has('code') ? 'is-invalid' : '' }}" id="recover-code" name="code" type="text"
                        inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required>
                    <button class="btn btn-gold btn-lg w-100" type="submit">Ver mis servicios</button>
                </form>
                <div class="recover-secondary">
                    <form method="POST" action="{{ route('my-services.recover.send') }}">
                        @csrf
                        <input type="hidden" name="phone" value="{{ $phone }}">
                        <input type="hidden" name="consent" value="1">
                        <button class="btn btn-ghost-light" type="submit">Reenviar código</button>
                    </form>
                    <a href="{{ route('my-services.recover') }}">Usar otro número</a>
                </div>
            @endif
        </div>
        <p class="recover-back"><a href="{{ route('my-services.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a Mis servicios</a></p>
    </div>
@endsection

@push('styles')
<style>
.recover-wrap { max-width: 40rem; padding-block: 2rem 5rem; }
.recover-card { padding: clamp(1.25rem,4vw,2.25rem); border: 1px solid #736348; border-radius: 1rem; background: #191714; color: #faf8f3; }
.recover-card h2 { margin: 0 0 .6rem; font-size: 1.5rem; }
.recover-card > p { margin: 0 0 1.25rem; color: #c9c4bb; }
.recover-card small { display: block; margin: .5rem 0 1rem; color: #a8a29a; }
.recover-card .form-label { margin-bottom: .4rem; }
.recover-card .form-control { min-height: 48px; }
.recover-consent { margin-block: .5rem 1.25rem; }
.recover-consent .form-check-label { color: #c9c4bb; }
.recover-code { font-family: var(--font-mono, ui-monospace, monospace); font-size: 1.6rem; letter-spacing: .5em; text-align: center; }
.recover-alert { padding: .8rem 1rem; border: 1px solid rgba(225,186,112,.45); border-radius: .6rem; background: rgba(225,186,112,.12); color: #f4e4bf; }
.recover-secondary { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; margin-top: 1.25rem; }
.recover-secondary a, .recover-back a { color: var(--gold,#e1ba70); text-decoration: underline; text-underline-offset: 3px; }
.recover-back { margin-top: 1.5rem; }
</style>
@endpush
