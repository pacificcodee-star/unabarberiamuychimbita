@extends('layouts.public')

@php $seo = \App\Support\Seo::page('booking', $siteSettings); @endphp
@section('title', $seo['title'])
@section('meta_description', $seo['description'])

@section('content')
    @php
        $historyLookup = config('services.evolution.bot_enabled') && filled($siteSettings['whatsapp'] ?? null);
        $currentTab = old('_form', $activeTab);
        $requestedService = request('servicio');
        $citaBarber = old('_form') === 'cita' ? old('barber_id', $selectedBarberId) : $selectedBarberId;
        $turnoBarber = old('_form') === 'turno' ? old('barber_id', $selectedBarberId) : $selectedBarberId;
        $citaService = old('_form') === 'cita' ? old('service_id') : $requestedService;
        $turnoService = old('_form') === 'turno' ? old('service_id') : $requestedService;
        $fieldError = fn (string $form, string $field) => old('_form') === $form ? ($errors->first($field) ?: null) : null;
        $closedOn = fn ($day) => \App\Models\SiteSetting::isClosedOn($day, $siteSettings);
        $selectedDate = old('_form') === 'cita' ? old('appointment_date', now()->toDateString()) : now()->toDateString();
        // Calendario: hoy cuenta como día 1 y el último día reservable es hoy + 29 (30 días calendario).
        $calMax = now()->addDays(29)->toDateString();
        $calClosed = collect(range(0, 29))
            ->map(fn ($offset) => now()->addDays($offset))
            ->filter($closedOn)
            ->map(fn ($day) => $day->toDateString())
            ->values();
    @endphp

    @if($historyLookup)
        <form id="history-lookup" method="post" action="{{ route('my-services.whatsapp.finish') }}" hidden>
            @csrf
            <input type="hidden" name="intent" value="lookup">
            <input type="hidden" name="client_phone">
        </form>
    @endif

    {{-- Contenedor de la página de reservas: fondo crema #EFE8DC (ver cinema.css, sección "Página de reservas"). --}}
    <div class="booking-page">
    @include('public.partials.page-hero', [
        'crumb' => 'Reservar',
        'ghost' => false,
        'title' => $siteSettings['booking_hero_title'],
        'description' => $siteSettings['booking_hero_description'],
        'image' => asset('images/reserva-herramientas.webp'),
        'image_fallback' => asset('images/reserva-herramientas.jpg'),
        'image_alt' => 'Herramientas de barbería: tijeras, peine, brocha de afeitar, máquina y navaja sobre una repisa de mármol.',
    ])

    <section class="section">
        <div class="container">
            @if($errors->any())
                <div class="alert alert-danger mb-4" role="alert">
                    <strong><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i> Revisa la información marcada:</strong>
                    <ul class="mb-0 mt-2">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="booking-layout">
                <div class="booking-card">
                    <ul class="nav booking-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link {{ $currentTab === 'cita' ? 'active' : '' }}" id="cita-tab" data-bs-toggle="tab" data-bs-target="#cita-pane" type="button" role="tab" aria-controls="cita-pane" aria-selected="{{ $currentTab === 'cita' ? 'true' : 'false' }}">
                                <strong><i class="bi bi-calendar2-week" aria-hidden="true"></i> Reservar cita</strong>
                                <small>Eliges día y hora</small>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link {{ $currentTab === 'turno' ? 'active' : '' }}" id="turno-tab" data-bs-toggle="tab" data-bs-target="#turno-pane" type="button" role="tab" aria-controls="turno-pane" aria-selected="{{ $currentTab === 'turno' ? 'true' : 'false' }}">
                                <strong><i class="bi bi-person-walking" aria-hidden="true"></i> Tomar turno</strong>
                                <small>Por orden de llegada</small>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        {{-- Cita --}}
                        <div class="tab-pane fade {{ $currentTab === 'cita' ? 'show active' : '' }}" id="cita-pane" role="tabpanel" aria-labelledby="cita-tab" tabindex="0">
                            <p class="booking-intro">Para barberos que trabajan con horarios reservados. Elige día y hora; te contactaremos para confirmar.</p>

                            @if($appointmentBarbers->isEmpty())
                                <div class="empty-state">
                                    <i class="bi bi-calendar-x" aria-hidden="true"></i>
                                    <h3>Sin barberos por citas por ahora</h3>
                                    <p>Puedes tomar un turno con un barbero que atiende por orden de llegada.</p>
                                    <button type="button" class="btn btn-ghost-light mt-2" data-switch-tab="#turno-tab">Tomar turno</button>
                                </div>
                            @else
                                <form method="POST" action="{{ route('public.booking.appointment') }}" data-booking-slots="{{ route('public.booking.slots') }}" data-booking-form="cita" novalidate>
                                    @csrf
                                    <input type="hidden" name="_form" value="cita">
                                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}" data-idempotency-key>
                                    <div class="hp-field" aria-hidden="true"><label>No llenar este campo <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                                    <fieldset class="booking-step">
                                        <legend class="booking-step-title"><span>1</span> Barbero y servicio</legend>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label" for="appointmentBarber">Barbero <span class="req" aria-hidden="true">*</span></label>
                                                <select name="barber_id" id="appointmentBarber" class="form-select {{ $fieldError('cita', 'barber_id') ? 'is-invalid' : '' }}" required @if($fieldError('cita', 'barber_id')) aria-invalid="true" aria-describedby="appointmentBarberError" @endif>
                                                    <option value="">Selecciona un barbero</option>
                                                    @foreach($appointmentBarbers as $barber)
                                                        <option value="{{ $barber->id }}" data-services="{{ implode(',', $barber->offeredServiceIds()) }}" @selected($citaBarber == $barber->id)>{{ $barber->name }}</option>
                                                    @endforeach
                                                </select>
                                                @if($fieldError('cita', 'barber_id'))<div class="invalid-feedback" id="appointmentBarberError"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('cita', 'barber_id') }}</div>@endif
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="appointmentService">Servicio <span class="req" aria-hidden="true">*</span></label>
                                                <select name="service_id" id="appointmentService" class="form-select {{ $fieldError('cita', 'service_id') ? 'is-invalid' : '' }}" required @if($fieldError('cita', 'service_id')) aria-invalid="true" aria-describedby="appointmentServiceError" @endif>
                                                    <option value="">Selecciona un servicio</option>
                                                    @foreach($services as $service)
                                                        <option value="{{ $service->id }}" @selected($citaService == $service->id)>{{ $service->name }} · {{ format_money($service->price) }}</option>
                                                    @endforeach
                                                </select>
                                                @if($fieldError('cita', 'service_id'))<div class="invalid-feedback" id="appointmentServiceError"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('cita', 'service_id') }}</div>@endif
                                            </div>
                                        </div>
                                    </fieldset>

                                    <fieldset class="booking-step">
                                        <legend class="booking-step-title"><span>2</span> Fecha</legend>
                                        <div class="cal-picker" data-calendar data-closed="{{ $calClosed->toJson() }}">
                                            <div class="cal-card">
                                                <div class="cal-head">
                                                    <button type="button" class="cal-nav" data-cal-prev aria-label="Mes anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                                                    <p class="cal-title" data-cal-title>{{ \Illuminate\Support\Str::ucfirst(\Illuminate\Support\Carbon::parse($selectedDate)->locale('es')->translatedFormat('F \d\e Y')) }}</p>
                                                    <button type="button" class="cal-nav" data-cal-next aria-label="Mes siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                                                </div>
                                                <div class="cal-week" aria-hidden="true">
                                                    <span>L</span><span>M</span><span>M</span><span>J</span><span>V</span><span>S</span><span>D</span>
                                                </div>
                                                <div class="cal-grid" data-cal-grid></div>
                                                <button type="button" class="cal-apply" data-cal-apply disabled>Aplicar</button>
                                            </div>
                                            <label class="visually-hidden" for="appointmentDate">Fecha</label>
                                            <input type="hidden" name="appointment_date" id="appointmentDate" value="{{ $selectedDate }}" min="{{ now()->toDateString() }}" max="{{ $calMax }}" class="{{ $fieldError('cita', 'appointment_date') ? 'is-invalid' : '' }}" required>
                                            @if($fieldError('cita', 'appointment_date'))<div class="invalid-feedback d-block"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('cita', 'appointment_date') }}</div>@endif
                                        </div>
                                        <div class="mt-4">
                                            <span class="form-label d-block" id="appointmentTimeLabel">Hora disponible <span class="req" aria-hidden="true">*</span></span>
                                            <div id="appointmentTimes" class="slot-grid slot-card" role="radiogroup" aria-labelledby="appointmentTimeLabel" aria-describedby="appointmentTimeHelp" data-selected="{{ old('_form') === 'cita' ? old('appointment_time') : '' }}" aria-live="polite"></div>
                                            <div class="form-text" id="appointmentTimeHelp">Selecciona primero el barbero y la fecha.</div>
                                            @if($fieldError('cita', 'appointment_time'))<div class="invalid-feedback"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('cita', 'appointment_time') }}</div>@endif
                                        </div>
                                    </fieldset>

                                    <fieldset class="booking-step">
                                        <legend class="booking-step-title"><span>3</span> Tus datos</legend>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label" for="appointmentName">Nombre completo <span class="req" aria-hidden="true">*</span></label>
                                                <input type="text" name="client_name" id="appointmentName" value="{{ old('_form') === 'cita' ? old('client_name') : '' }}" class="form-control {{ $fieldError('cita', 'client_name') ? 'is-invalid' : '' }}" maxlength="120" required autocomplete="name" placeholder="Ej. Andrés Gómez">
                                                @if($fieldError('cita', 'client_name'))<div class="invalid-feedback"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('cita', 'client_name') }}</div>@endif
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="appointmentPhone">Teléfono o celular <span class="req" aria-hidden="true">*</span></label>
                                                <input type="tel" name="client_phone" id="appointmentPhone" value="{{ old('_form') === 'cita' ? old('client_phone') : '' }}" class="form-control {{ $fieldError('cita', 'client_phone') ? 'is-invalid' : '' }}" maxlength="30" required autocomplete="tel" inputmode="tel" placeholder="Ej. 300 123 4567" aria-describedby="appointmentPhoneHelp">
                                                <div class="form-text" id="appointmentPhoneHelp">Usamos este número para confirmar tu cita.</div>
                                                @if($historyLookup)
                                                    <button type="button" hidden class="btn btn-link p-0 form-text" data-history-phone="appointmentPhone">Consultar mis servicios</button>
                                                @endif
                                                @if($fieldError('cita', 'client_phone'))<div class="invalid-feedback"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('cita', 'client_phone') }}</div>@endif
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="appointmentPayment">Método de pago <span class="req" aria-hidden="true">*</span></label>
                                                <select name="payment_method" id="appointmentPayment" class="form-select {{ $fieldError('cita', 'payment_method') ? 'is-invalid' : '' }}" required>
                                                    <option value="">Selecciona un método de pago</option>
                                                    @foreach(($paymentMethods ?? []) as $key => $label)
                                                        <option value="{{ $key }}" @selected(old('_form') === 'cita' && old('payment_method') === $key)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                @if($fieldError('cita', 'payment_method'))<div class="invalid-feedback"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('cita', 'payment_method') }}</div>@endif
                                                <div class="form-text">Si eliges un medio virtual, te enviaremos por WhatsApp el número/cuenta para consignar.</div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="appointmentEmail">Correo electrónico <span class="optional">(opcional)</span></label>
                                                <input type="email" name="client_email" id="appointmentEmail" value="{{ old('_form') === 'cita' ? old('client_email') : '' }}" class="form-control {{ $fieldError('cita', 'client_email') ? 'is-invalid' : '' }}" maxlength="150" autocomplete="email" inputmode="email" placeholder="Ej. andres@correo.com">
                                                @if($fieldError('cita', 'client_email'))<div class="invalid-feedback"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('cita', 'client_email') }}</div>@endif
                                            </div>
                                            <div class="col-12">
                                                <div class="form-check">
                                                    <input type="hidden" name="whatsapp_consent" value="0">
                                                    <input type="checkbox" name="whatsapp_consent" id="appointmentWhatsappConsent" value="1" class="form-check-input" @checked(old('_form') === 'cita' && old('whatsapp_consent'))>
                                                    <label class="form-check-label" for="appointmentWhatsappConsent">Acepto recibir avisos de mi cita por WhatsApp en este número <span class="optional">(opcional)</span></label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label" for="appointmentNotes">Notas <span class="optional">(opcional)</span></label>
                                                <textarea name="notes" id="appointmentNotes" rows="2" class="form-control" maxlength="500" placeholder="¿Algo que debamos saber?">{{ old('_form') === 'cita' ? old('notes') : '' }}</textarea>
                                            </div>
                                        </div>
                                    </fieldset>

                                    <div class="booking-note mt-4">
                                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                                        <span>Tu cita quedará pendiente de confirmación.<br>Al enviar tu solicitud, podrás consultar los detalles de tu reserva en la sección «Mis servicios», donde deberás confirmar tu cita para completar el proceso.</span>
                                    </div>

                                    <button type="submit" class="btn btn-gold btn-lg w-100 mt-4 btn-submit-blue" data-submit-label="Enviar solicitud">
                                        <i class="bi bi-calendar2-check" aria-hidden="true"></i> Enviar solicitud
                                    </button>
                                    <p class="form-legal-note text-center">Al confirmar aceptas el tratamiento de tus datos según la <a href="{{ route('legal.privacy') }}">política de privacidad</a>.</p>
                                    <p class="form-text text-center mt-2 mb-0" data-push-hint hidden><i class="bi bi-bell" aria-hidden="true"></i> Al enviar, tu navegador te preguntará si permites los avisos. Elige <strong>Permitir</strong> para que te avisemos al celular.</p>
                                </form>
                            @endif
                        </div>

                        {{-- Turno --}}
                        <div class="tab-pane fade {{ $currentTab === 'turno' ? 'show active' : '' }}" id="turno-pane" role="tabpanel" aria-labelledby="turno-tab" tabindex="0">
                            <p class="booking-intro">Para barberos que atienden por orden de llegada. Toma tu turno virtual y espera a que te llamen.</p>

                            @if($businessOpenNow)
                                <p class="booking-intro small" id="turnDeadlineNote" data-turn-note><i class="bi bi-clock" aria-hidden="true"></i> {{ $turnState['message'] }}</p>
                            @endif

                            <div class="alert alert-warning mb-4" id="turnClosedAlert" role="alert" hidden>
                                <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
                                <strong data-turn-closed-text>{{ $turnState['cutoff_message'] }}</strong>
                            </div>

                            {{-- El aviso de cierre se muestra una sola vez: en el recuadro de abajo, o aquí si no hay barberos por turno. --}}
                            @if(empty($businessOpenNow) && $turnBarbers->isEmpty())
                                <div class="alert alert-warning mb-4" role="alert">
                                    <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
                                    <strong>{{ $turnState['message'] }}</strong>
                                </div>
                            @endif

                            @if($turnBarbers->isEmpty())
                                <div class="empty-state">
                                    <i class="bi bi-person-x" aria-hidden="true"></i>
                                    <h3>Sin barberos por llegada por ahora</h3>
                                    <p>Puedes reservar una cita con un barbero que trabaja con horarios.</p>
                                    <button type="button" class="btn btn-ghost-light mt-2" data-switch-tab="#cita-tab">Reservar cita</button>
                                </div>
                            @elseif(empty($businessOpenNow))
                                <div class="empty-state">
                                    <i class="bi bi-door-closed" aria-hidden="true"></i>
                                    <h3>Turnos no disponibles ahora</h3>
                                    <p>{{ $turnState['message'] }}</p>
                                    <button type="button" class="btn btn-ghost-light mt-2" data-switch-tab="#cita-tab">Reservar cita</button>
                                </div>
                            @else
                                <form method="POST" action="{{ route('public.booking.turn') }}" data-booking-form="turno" novalidate
                                      data-turn-deadline="{{ $turnState['deadline']?->toIso8601String() }}"
                                      data-turn-closed-message="{{ $turnState['cutoff_message'] }}">
                                    @csrf
                                    <input type="hidden" name="_form" value="turno">
                                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}" data-idempotency-key>
                                    <div class="hp-field" aria-hidden="true"><label>No llenar este campo <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                                    <fieldset class="booking-step">
                                        <legend class="booking-step-title"><span>1</span> Barbero y servicio</legend>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label" for="turnBarber">Barbero <span class="req" aria-hidden="true">*</span></label>
                                                <select name="barber_id" id="turnBarber" class="form-select {{ $fieldError('turno', 'barber_id') ? 'is-invalid' : '' }}" required>
                                                    <option value="">Selecciona un barbero</option>
                                                    @foreach($turnBarbers as $barber)
                                                        <option value="{{ $barber->id }}" data-services="{{ implode(',', $barber->offeredServiceIds()) }}" @selected($turnoBarber == $barber->id)>{{ $barber->name }}</option>
                                                    @endforeach
                                                </select>
                                                @if($fieldError('turno', 'barber_id'))<div class="invalid-feedback"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('turno', 'barber_id') }}</div>@endif
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="turnService">Servicio <span class="req" aria-hidden="true">*</span></label>
                                                <select name="service_id" id="turnService" class="form-select {{ $fieldError('turno', 'service_id') ? 'is-invalid' : '' }}" required @if($fieldError('turno', 'service_id')) aria-invalid="true" aria-describedby="turnServiceError" @endif>
                                                    <option value="">Selecciona un servicio</option>
                                                    @foreach($services as $service)
                                                        <option value="{{ $service->id }}" @selected($turnoService == $service->id)>{{ $service->name }} · {{ format_money($service->price) }}</option>
                                                    @endforeach
                                                </select>
                                                @if($fieldError('turno', 'service_id'))<div class="invalid-feedback" id="turnServiceError"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('turno', 'service_id') }}</div>@endif
                                            </div>
                                        </div>
                                    </fieldset>

                                    <fieldset class="booking-step">
                                        <legend class="booking-step-title"><span>2</span> Tus datos</legend>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label" for="turnName">Nombre completo <span class="req" aria-hidden="true">*</span></label>
                                                <input type="text" name="client_name" id="turnName" value="{{ old('_form') === 'turno' ? old('client_name') : '' }}" class="form-control {{ $fieldError('turno', 'client_name') ? 'is-invalid' : '' }}" maxlength="120" required autocomplete="name" placeholder="Ej. Andrés Gómez">
                                                @if($fieldError('turno', 'client_name'))<div class="invalid-feedback"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('turno', 'client_name') }}</div>@endif
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="turnPhone">Teléfono o celular <span class="req" aria-hidden="true">*</span></label>
                                                <input type="tel" name="client_phone" id="turnPhone" value="{{ old('_form') === 'turno' ? old('client_phone') : '' }}" class="form-control {{ $fieldError('turno', 'client_phone') ? 'is-invalid' : '' }}" maxlength="30" required autocomplete="tel" inputmode="tel" placeholder="Ej. 300 123 4567">
                                                @if($historyLookup)
                                                    <button type="button" hidden class="btn btn-link p-0 form-text" data-history-phone="turnPhone">Consultar mis servicios</button>
                                                @endif
                                                @if($fieldError('turno', 'client_phone'))<div class="invalid-feedback"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('turno', 'client_phone') }}</div>@endif
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="turnPayment">Método de pago <span class="req" aria-hidden="true">*</span></label>
                                                <select name="payment_method" id="turnPayment" class="form-select {{ $fieldError('turno', 'payment_method') ? 'is-invalid' : '' }}" required>
                                                    <option value="">Selecciona un método de pago</option>
                                                    @foreach(($paymentMethods ?? []) as $key => $label)
                                                        <option value="{{ $key }}" @selected(old('_form') === 'turno' && old('payment_method') === $key)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                @if($fieldError('turno', 'payment_method'))<div class="invalid-feedback"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('turno', 'payment_method') }}</div>@endif
                                                <div class="form-text">Si eliges un medio virtual, te enviaremos por WhatsApp el número/cuenta para consignar.</div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="turnEmail">Correo electrónico <span class="optional">(opcional)</span></label>
                                                <input type="email" name="client_email" id="turnEmail" value="{{ old('_form') === 'turno' ? old('client_email') : '' }}" class="form-control {{ $fieldError('turno', 'client_email') ? 'is-invalid' : '' }}" maxlength="150" autocomplete="email" inputmode="email" placeholder="Ej. andres@correo.com">
                                                @if($fieldError('turno', 'client_email'))<div class="invalid-feedback"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ $fieldError('turno', 'client_email') }}</div>@endif
                                            </div>
                                            <div class="col-12">
                                                <div class="form-check">
                                                    <input type="hidden" name="whatsapp_consent" value="0">
                                                    <input type="checkbox" name="whatsapp_consent" id="turnWhatsappConsent" value="1" class="form-check-input" @checked(old('_form') === 'turno' && old('whatsapp_consent'))>
                                                    <label class="form-check-label" for="turnWhatsappConsent">Acepto recibir avisos de mi turno por WhatsApp en este número <span class="optional">(opcional)</span></label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label" for="turnNotes">Notas <span class="optional">(opcional)</span></label>
                                                <textarea name="notes" id="turnNotes" rows="2" class="form-control" maxlength="500" placeholder="¿Algo que debamos saber?">{{ old('_form') === 'turno' ? old('notes') : '' }}</textarea>
                                            </div>
                                        </div>
                                    </fieldset>

                                    <div class="booking-note mt-4">
                                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                                        <span>Al registrar el turno te mostraremos tu número. Acércate a la barbería y espera tu llamado.</span>
                                    </div>

                                    <button type="submit" class="btn btn-gold btn-lg w-100 mt-4" data-submit-label="Tomar turno">
                                        <i class="bi bi-person-walking" aria-hidden="true"></i> Tomar turno
                                    </button>
                                    <p class="form-legal-note text-center">Al confirmar aceptas el tratamiento de tus datos según la <a href="{{ route('legal.privacy') }}">política de privacidad</a>.</p>
                                    <p class="form-text text-center mt-2 mb-0" data-push-hint hidden><i class="bi bi-bell" aria-hidden="true"></i> Al enviar, tu navegador te preguntará si permites los avisos. Elige <strong>Permitir</strong> para que te avisemos al celular.</p>
                                </form>
                                @if(!empty($siteSettings['whatsapp']))
                                    <a href="https://wa.me/{{ $siteSettings['whatsapp'] }}?text={{ rawurlencode(\App\Services\WhatsApp\WhatsappBotService::TURN_REQUEST) }}" target="_blank" rel="noopener" class="btn-whatsapp-green btn btn-ghost-light w-100 mt-3">
                                        <i class="bi bi-whatsapp" aria-hidden="true"></i> Tomar turno por WhatsApp
                                    </a>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>

                <aside class="booking-aside" aria-label="Información de la reserva">
                    <div class="booking-card d-none d-lg-block">
                        <h2>Tu reserva</h2>
                        <ul class="booking-summary" data-booking-summary>
                            <li><span>Tipo</span><strong data-summary="type">{{ $currentTab === 'turno' ? 'Turno por llegada' : 'Cita' }}</strong></li>
                            <li><span>Barbero</span><strong data-summary="barber" class="is-empty">Sin elegir</strong></li>
                            <li><span>Servicio</span><strong data-summary="service" class="is-empty">Sin elegir</strong></li>
                            <li data-summary-row="date"><span>Fecha</span><strong data-summary="date" class="is-empty">Sin elegir</strong></li>
                            <li data-summary-row="time"><span>Hora</span><strong data-summary="time" class="is-empty">Sin elegir</strong></li>
                        </ul>
                    </div>
                    <div class="booking-card">
                        <h2>Cómo funciona</h2>
                        <ul class="how-list">
                            <li><i class="bi bi-calendar2-week" aria-hidden="true"></i><div><strong>Barberos por citas</strong><p>Eliges barbero, fecha y hora. El equipo confirma tu cita.</p></div></li>
                            <li><i class="bi bi-person-walking" aria-hidden="true"></i><div><strong>Barberos por llegada</strong><p>Tomas un turno virtual y recibes un número. Te llamamos en orden.</p></div></li>
                            <li><i class="bi bi-clock" aria-hidden="true"></i><div><strong>Horario</strong><p>@foreach(\App\Models\SiteSetting::groupedHours($siteSettings) as $group){{ $group['label'] }} {{ $group['value'] }}@unless($loop->last) · @endunless @endforeach</p></div></li>
                        </ul>
                        @if(!empty($siteSettings['whatsapp']))
                            <a href="https://wa.me/{{ $siteSettings['whatsapp'] }}?text={{ rawurlencode(\App\Services\WhatsApp\WhatsappBotService::ADVISOR_REQUEST) }}" target="_blank" rel="noopener" class="btn-whatsapp-green btn btn-ghost-light w-100 mt-4">
                                <i class="bi bi-whatsapp" aria-hidden="true"></i> Hablar con un asesor
                            </a>
                        @endif
                    </div>
                </aside>
            </div>
        </div>
    </section>
    </div>
@endsection

@push('scripts')
{{-- Envío resiliente: borrador, idempotencia, verificación si se pierde la conexión y permiso de avisos. --}}
<script src="{{ asset('js/booking-submit.js') }}?v={{ @filemtime(public_path('js/booking-submit.js')) }}" defer></script>
<script>
    (() => {
        const historyLookup = document.getElementById('history-lookup');
        document.querySelectorAll('[data-history-phone]').forEach(button => {
            const phone = document.getElementById(button.dataset.historyPhone);
            const toggle = () => {
                button.hidden = !/^[+0-9().\s-]+$/.test(phone.value) || !/^\d{7,15}$/.test(phone.value.replace(/\D/g, ''));
            };
            phone.addEventListener('input', toggle);
            phone.addEventListener('change', toggle);
            phone.addEventListener('focus', toggle);
            toggle();
            button.addEventListener('click', () => {
                historyLookup.elements.client_phone.value = phone.value;
                historyLookup.requestSubmit();
            });
        });

        // Los turnos dejan de aceptarse al llegar al límite (30 min antes del cierre), sin esperar recarga.
        const turnForm = document.querySelector('[data-booking-form="turno"]');
        const closeTurnRequests = () => {
            if (!turnForm) return;
            turnForm.hidden = true;
            const note = document.querySelector('[data-turn-note]');
            if (note) note.hidden = true;
            const text = document.querySelector('[data-turn-closed-text]');
            if (text && turnForm.dataset.turnClosedMessage) text.textContent = turnForm.dataset.turnClosedMessage;
            const alertBox = document.getElementById('turnClosedAlert');
            if (alertBox) alertBox.hidden = false;
        };
        if (turnForm?.dataset.turnDeadline) {
            const remaining = Date.parse(turnForm.dataset.turnDeadline) - Date.now();
            if (remaining <= 0) {
                closeTurnRequests();
            } else if (remaining < 86400000) {
                setTimeout(closeTurnRequests, remaining);
            }
        }

        // Cambiar de pestaña desde los estados vacíos.
        document.querySelectorAll('[data-switch-tab]').forEach((button) => {
            button.addEventListener('click', () => document.querySelector(button.dataset.switchTab)?.click());
        });

        // Validación accesible en el navegador + estado de envío (evita reservas duplicadas).
        document.querySelectorAll('[data-booking-form]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (form.dataset.turnDeadline && Date.now() > Date.parse(form.dataset.turnDeadline)) {
                    event.preventDefault();
                    closeTurnRequests();
                    return;
                }
                if (!form.checkValidity()) {
                    event.preventDefault();
                    const firstInvalid = form.querySelector(':invalid');
                    form.querySelectorAll('.form-control, .form-select').forEach((field) => field.classList.toggle('is-invalid', !field.checkValidity()));
                    if (firstInvalid?.type === 'radio') {
                        document.getElementById('appointmentTimeHelp').textContent = 'Elige una de las horas disponibles para continuar.';
                        document.getElementById('appointmentTimeHelp').classList.add('text-danger');
                    }
                    firstInvalid?.focus();
                    return;
                }
                // Con booking-submit.js el envío va por fetch (sin perder datos ni duplicar); si no cargó, envío normal.
                if (window.BookingSubmit) {
                    event.preventDefault();
                    window.BookingSubmit.submit(form);
                    return;
                }
                if (form.dataset.sending === '1') { event.preventDefault(); return; }
                form.dataset.sending = '1';
                const button = form.querySelector('[type="submit"]');
                if (button) {
                    button.disabled = true;
                    button.innerHTML = '<span class="spinner" aria-hidden="true"></span> Enviando…';
                }
            });
            form.querySelectorAll('.form-control, .form-select').forEach((field) => {
                field.addEventListener('input', () => { if (field.checkValidity()) field.classList.remove('is-invalid'); });
                field.addEventListener('change', () => { if (field.checkValidity()) field.classList.remove('is-invalid'); });
            });
        });

        // Resumen de la reserva.
        const summary = document.querySelector('[data-booking-summary]');
        const optionText = (select) => select && select.value ? select.options[select.selectedIndex].text.split(' · ')[0] : '';
        const setSummary = (key, value, fallback) => {
            const target = summary?.querySelector(`[data-summary="${key}"]`);
            if (!target) return;
            target.textContent = value || fallback;
            target.classList.toggle('is-empty', !value);
        };
        const updateSummary = () => {
            if (!summary) return;
            const isTurn = document.getElementById('turno-tab')?.classList.contains('active');
            const form = document.querySelector(`[data-booking-form="${isTurn ? 'turno' : 'cita'}"]`);
            setSummary('type', isTurn ? 'Turno por llegada' : 'Cita', '');
            setSummary('barber', optionText(form?.querySelector('[name="barber_id"]')), 'Sin elegir');
            setSummary('service', optionText(form?.querySelector('[name="service_id"]')), 'Sin elegir');
            summary.querySelector('[data-summary-row="date"]').hidden = isTurn;
            summary.querySelector('[data-summary-row="time"]').hidden = isTurn;
            if (!isTurn && form) {
                const dateValue = form.querySelector('[name="appointment_date"]')?.value;
                const label = dateValue ? new Date(`${dateValue}T12:00:00`).toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' }) : '';
                setSummary('date', label ? label.charAt(0).toUpperCase() + label.slice(1) : '', 'Sin elegir');
                setSummary('time', form.querySelector('[name="appointment_time"]:checked')?.dataset.label || '', 'Sin elegir');
            }
        };
        document.querySelectorAll('[data-booking-form]').forEach((form) => form.addEventListener('change', updateSummary));
        document.querySelectorAll('#cita-tab, #turno-tab').forEach((tab) => tab.addEventListener('shown.bs.tab', updateSummary));

        // Cada barbero muestra solo los servicios que ofrece (sin datos configurados = todo el catálogo).
        const syncServices = (barberSelect, serviceSelect) => {
            if (!barberSelect || !serviceSelect) return;
            const ids = (barberSelect.selectedOptions[0]?.dataset.services || '').split(',').filter(Boolean);
            [...serviceSelect.options].forEach((option) => {
                if (!option.value) return;
                const allowed = ids.length === 0 || ids.includes(option.value);
                option.hidden = !allowed;
                option.disabled = !allowed;
            });
            if (serviceSelect.selectedOptions[0]?.disabled) serviceSelect.value = '';
        };
        [['appointmentBarber', 'appointmentService'], ['turnBarber', 'turnService']].forEach(([barberId, serviceId]) => {
            const barberSelect = document.getElementById(barberId);
            const serviceSelect = document.getElementById(serviceId);
            if (!barberSelect || !serviceSelect) return;
            syncServices(barberSelect, serviceSelect);
            barberSelect.addEventListener('change', () => syncServices(barberSelect, serviceSelect));
        });

        // Horarios disponibles.
        const form = document.querySelector('[data-booking-slots]');
        const barber = document.getElementById('appointmentBarber');
        const service = document.getElementById('appointmentService');
        const date = document.getElementById('appointmentDate');
        const slots = document.getElementById('appointmentTimes');
        const help = document.getElementById('appointmentTimeHelp');
        if (!form || !barber || !date || !slots) { updateSummary(); return; }

        const to12h = (value) => {
            const [h, m] = value.split(':').map(Number);
            const suffix = h >= 12 ? 'p. m.' : 'a. m.';
            return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${suffix}`;
        };
        const status = (icon, text, isError = false, retry = false) => {
            const box = document.createElement('div');
            box.className = `slot-status${isError ? ' is-error' : ''}`;
            box.style.gridColumn = '1 / -1';
            box.innerHTML = `<i class="bi ${icon}" aria-hidden="true"></i><span></span>`;
            box.querySelector('span').textContent = text;
            if (retry) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-ghost-light btn-sm';
                button.textContent = 'Reintentar';
                button.addEventListener('click', loadTimes);
                box.appendChild(button);
            }
            slots.replaceChildren(box);
        };

        let requestId = 0;
        async function loadTimes(quiet = false) {
            quiet = quiet === true;
            help.classList.remove('text-danger');
            const current = ++requestId;
            if (!barber.value || !service.value || !date.value) {
                status('bi-info-circle', 'Selecciona un barbero, un servicio y una fecha para ver las horas disponibles.');
                help.textContent = 'Aquí se muestran únicamente los horarios que aún están libres. Los horarios que ya han sido reservados por otros clientes se ocultan automáticamente para evitar reservas duplicadas.';
                updateSummary();
                return;
            }
            if (date.max && date.value > date.max) {
                status('bi-calendar-x', 'Solo puedes agendar hasta 30 días calendario desde hoy. Elige una fecha más cercana.');
                help.textContent = '';
                updateSummary();
                return;
            }
            // Antes de pintar el esqueleto se recuerda la hora marcada, para volver a marcarla después.
            const keep = slots.querySelector('input:checked')?.value;
            if (!quiet && keep && !slots.dataset.selected) slots.dataset.selected = keep;
            if (!quiet) {
            slots.setAttribute('aria-busy', 'true');
            slots.replaceChildren(...Array.from({ length: 8 }, () => Object.assign(document.createElement('div'), { className: 'slot-skeleton' })));
            help.textContent = 'Consultando la agenda del barbero…';
            }
            try {
                const url = new URL(form.dataset.bookingSlots, window.location.origin);
                url.searchParams.set('barber_id', barber.value);
                url.searchParams.set('service_id', service.value);
                url.searchParams.set('date', date.value);
                // Con tiempo límite: la carga nunca se queda girando para siempre.
                const response = await window.safeFetch(url, { headers: { Accept: 'application/json' } }, 12000);
                const result = await response.json();
                if (!result || !Array.isArray(result.times)) throw Object.assign(new Error('agenda'), { kind: 'server' });
                if (current !== requestId) return;
                const displayed = Array.from(slots.querySelectorAll('input')).map(input => input.value);
                if (quiet && JSON.stringify(displayed) === JSON.stringify(result.times) && displayed.length) return;
                // Conserva la hora elegida (o la del borrador restaurado) al volver a cargar la agenda.
                const selected = slots.dataset.selected || slots.querySelector('input:checked')?.value || '';
                if (!result.times.length) {
                    status('bi-calendar-x', 'No hay horas disponibles para este día. Prueba otra fecha u otro barbero.');
                    help.textContent = '';
                } else {
                    const items = result.times.map((slot, index) => {
                        const wrap = document.createElement('div');
                        wrap.className = 'slot';
                        const id = `slot-${slot.replace(':', '')}`;
                        wrap.innerHTML = `<input type="radio" name="appointment_time" id="${id}" value="${slot}" ${index === 0 ? 'required' : ''}><label for="${id}"></label>`;
                        wrap.querySelector('label').textContent = to12h(slot);
                        const input = wrap.querySelector('input');
                        input.dataset.label = to12h(slot);
                        input.required = true;
                        if (slot === selected) input.checked = true;
                        return wrap;
                    });
                    slots.replaceChildren(...items);
                    help.textContent = 'Aquí se muestran únicamente los horarios que aún están libres. Los horarios que ya han sido reservados por otros clientes se ocultan automáticamente para evitar reservas duplicadas.';
                }
                slots.dataset.selected = '';
            } catch (error) {
                if (current !== requestId) return;
                if (quiet) return;
                const messages = {
                    timeout: 'La agenda está tardando más de lo normal. Inténtalo de nuevo.',
                    network: 'La conexión se interrumpió. Revisa tu internet e inténtalo de nuevo.',
                    ratelimit: 'Hiciste muchas consultas seguidas. Espera un momento e inténtalo de nuevo.',
                };
                status('bi-wifi-off', messages[error.kind] || 'No pudimos cargar la agenda. Inténtalo de nuevo.', true, true);
                help.textContent = '';
            } finally {
                if (current === requestId) slots.removeAttribute('aria-busy');
                updateSummary();
            }
        }

        barber.addEventListener('change', loadTimes);
        service.addEventListener('change', () => {
            slots.dataset.selected = '';
            loadTimes();
        });
        date.addEventListener('change', loadTimes);
        // Para volver a consultar la agenda cuando el servidor dice que la hora ya la tomó otra persona.
        const syncSlots = () => {
            if (!document.hidden && barber.value && service.value && date.value && !slots.hasAttribute('aria-busy')) loadTimes(true);
        };
        setInterval(syncSlots, 5000);
        document.addEventListener('visibilitychange', syncSlots);
        window.addEventListener('storage', event => { if (event.key === 'ubmc-service-change') syncSlots(); });
        window.reloadBookingSlots = loadTimes;
        document.addEventListener('booking:draft-restored', loadTimes);
        loadTimes();
    })();

    // Calendario de fecha propio: solo deja elegir 30 días calendario desde hoy.
    (() => {
        const box = document.querySelector('[data-calendar]');
        if (!box) return;
        const input = box.querySelector('#appointmentDate');
        const grid = box.querySelector('[data-cal-grid]');
        const title = box.querySelector('[data-cal-title]');
        const apply = box.querySelector('[data-cal-apply]');
        const prev = box.querySelector('[data-cal-prev]');
        const next = box.querySelector('[data-cal-next]');
        if (!input || !grid || !title || !apply || !prev || !next) return;

        const pad = (n) => String(n).padStart(2, '0');
        const toKey = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        const fromKey = (value) => new Date(`${value}T00:00:00`);
        const capitalize = (text) => text.charAt(0).toUpperCase() + text.slice(1);
        const closed = new Set(JSON.parse(box.dataset.closed || '[]'));
        const minDate = fromKey(input.min);
        const maxDate = fromKey(input.max);
        const todayKey = toKey(new Date());
        const monthLabel = (date) => capitalize(date.toLocaleDateString('es-CO', { month: 'long', year: 'numeric' }));
        const dayLabel = (date) => capitalize(date.toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' }));
        const canPick = (date) => date >= minDate && date <= maxDate && !closed.has(toKey(date));

        // Si hoy está cerrado (p. ej. domingo), se propone el primer día disponible.
        let initial = input.value && input.value >= input.min && input.value <= input.max ? input.value : todayKey;
        if (!canPick(fromKey(initial))) {
            for (const day = new Date(minDate); day <= maxDate; day.setDate(day.getDate() + 1)) {
                if (canPick(day)) { initial = toKey(day); break; }
            }
        }
        const initialChanged = initial !== input.value;
        if (initialChanged) input.value = initial;

        let pending = initial;
        let view = new Date(fromKey(initial).getFullYear(), fromKey(initial).getMonth(), 1);
        // «Aplicar» no puede quedar bloqueado por el día que ya viene marcado (hoy): se habilita
        // siempre que haya un día elegible, de modo que hoy también se pueda confirmar con el botón.
        const applicable = () => Boolean(pending) && canPick(fromKey(pending));
        const syncApply = () => { apply.disabled = !applicable(); };

        const render = () => {
            title.textContent = monthLabel(view);
            const firstMonth = new Date(minDate.getFullYear(), minDate.getMonth(), 1);
            const lastMonth = new Date(maxDate.getFullYear(), maxDate.getMonth(), 1);
            prev.disabled = view <= firstMonth;
            next.disabled = view >= lastMonth;

            const offset = (new Date(view.getFullYear(), view.getMonth(), 1).getDay() + 6) % 7;
            const daysInMonth = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
            const total = Math.ceil((offset + daysInMonth) / 7) * 7;
            const cells = [];

            for (let i = 0; i < total; i++) {
                const dayNumber = i - offset + 1;
                const date = new Date(view.getFullYear(), view.getMonth(), dayNumber);
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'cal-day';
                button.innerHTML = `<span>${date.getDate()}</span>`;
                const outside = dayNumber < 1 || dayNumber > daysInMonth;

                if (outside) {
                    button.classList.add('is-outside');
                    button.disabled = true;
                    button.tabIndex = -1;
                    button.setAttribute('aria-hidden', 'true');
                } else {
                    const key = toKey(date);
                    const available = canPick(date);
                    if (!available) {
                        button.classList.add('is-off');
                        button.disabled = true;
                    }
                    if (key === todayKey) button.classList.add('is-today');
                    if (key === pending) button.classList.add('is-selected');
                    if (available) {
                        button.setAttribute('aria-label', dayLabel(date) + (key === todayKey ? ' (hoy)' : ''));
                        button.setAttribute('aria-pressed', key === pending ? 'true' : 'false');
                        const dot = document.createElement('i');
                        dot.className = 'cal-dot';
                        dot.setAttribute('aria-hidden', 'true');
                        button.appendChild(dot);
                        button.addEventListener('click', () => {
                            pending = key;
                            syncApply();
                            render();
                        });
                    } else {
                        button.setAttribute('aria-label', dayLabel(date) + ' (no disponible)');
                    }
                }
                cells.push(button);
            }
            grid.replaceChildren(...cells);
        };

        apply.addEventListener('click', () => {
            if (!applicable()) return;
            if (input.value !== pending) {
                input.value = pending;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            syncApply();
            render();
        });
        prev.addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); render(); });
        next.addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); render(); });
        document.addEventListener('booking:draft-restored', () => {
            const value = input.value;
            if (!value || value === pending) return;
            const date = fromKey(value);
            if (Number.isNaN(date.getTime())) return;
            pending = value;
            view = new Date(date.getFullYear(), date.getMonth(), 1);
            syncApply();
            render();
        });

        syncApply();
        render();
        if (initialChanged) input.dispatchEvent(new Event('change', { bubbles: true }));
    })();
</script>
@endpush
