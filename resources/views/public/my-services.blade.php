@extends('layouts.public')
@section('title', 'Mis servicios · Una Barbería Muy Chimbita')
@section('robots', 'noindex, nofollow')

@section('content')
    @include('public.partials.page-hero', ['crumb' => 'Mis servicios', 'title' => 'Mis servicios',
        'description' => 'Consulta tus reservas, confirma tu asistencia o elige un nuevo horario.'])
    <div class="container service-manager" data-services data-slots="{{ route('public.booking.slots') }}">
        <p id="service-feedback" role="status" tabindex="-1"></p>
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
@endsection

@push('styles')
<style>
.service-manager { padding-block: 2rem 5rem; }
#service-list { display: grid; gap: 2.5rem 2rem; grid-template-columns: repeat(auto-fit,minmax(min(100%,28rem),1fr)); }
.service-group h2 { margin-bottom: 1rem; font-size: 1.55rem; }
.service-entry { border-top: 1px solid rgba(255,255,255,.2); padding-block: 1rem; overflow-wrap: anywhere; }
.service-heading,.service-actions { display: flex; align-items: baseline; flex-wrap: wrap; gap: .5rem .75rem; }
.service-heading { justify-content: space-between; }
.service-heading h3 { font-size: 1.15rem; margin: 0; }
.service-heading span { color: var(--gold,#e1ba70); font-size: .9rem; }
.service-entry p,.service-empty { margin: .6rem 0; color: #c9c4bb; }
.service-actions .btn { min-height: 44px; }
#service-dialog { width: min(30rem,calc(100% - 2rem)); max-height: 85dvh; overflow: auto; overscroll-behavior: contain; padding: clamp(1rem,4vw,2rem); border: 1px solid #736348; border-radius: 1rem; background: #191714; color: #faf8f3; }
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
@endpush
