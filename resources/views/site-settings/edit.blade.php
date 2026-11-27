@extends('layouts.app')

@section('title', 'Configuración del negocio')

@section('content')
    @php
        $groups = [
            'Ubicación y contacto' => [
                'address' => ['Dirección', 'text', 'Ej. Calle 45 # 23-18'],
                'city' => ['Ciudad y país', 'text', 'Ej. Tumaco, Colombia'],
                'phone' => ['Teléfono', 'text', 'Ej. +57 300 123 4567'],
                'email' => ['Correo', 'email', 'Ej. hola@tubarberia.com'],
                'map_url' => ['Enlace de Google Maps (respaldo, para incrustar)', 'url', 'https://www.google.com/maps?q=...&output=embed'],
                'map_coords' => ['Coordenadas del local (opcional)', 'text', 'Ej. 1.8067,-78.7647 — en Google Maps: clic derecho sobre el local'],
            ],
            // Los 7 días se dibujan aparte (abre / cierra / cerrado). Ese horario es el único que usan
            // las citas y los turnos: no hay horas de reserva aparte.
            'Horario de atención' => [],
            'Redes sociales' => [
                'whatsapp' => ['WhatsApp (solo números, con indicativo)', 'text', 'Ej. 573001234567'],
                'instagram_url' => ['Instagram', 'url', 'https://www.instagram.com/tu_cuenta'],
                'facebook_url' => ['Facebook', 'url', 'https://www.facebook.com/tu_pagina'],
                'tiktok_url' => ['TikTok', 'url', 'https://www.tiktok.com/@tu_cuenta'],
            ],
        ];
        $optional = ['map_coords', 'instagram_url', 'facebook_url', 'tiktok_url'];
        $groupIcons = ['Ubicación y contacto' => 'bi-geo-alt', 'Horario de atención' => 'bi-clock', 'Redes sociales' => 'bi-share'];
        $icons = ['whatsapp' => 'bi-whatsapp', 'instagram_url' => 'bi-instagram', 'facebook_url' => 'bi-facebook', 'tiktok_url' => 'bi-tiktok'];
    @endphp

    <p class="page-intro">Datos de contacto, horarios, redes, medios de pago y logo. Los textos de la página se cambian en el código.</p>

    <form method="POST" action="{{ route('site-settings.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        @foreach($groups as $group => $fields)
            <section class="card content-settings-card">
                <div class="card-header"><h2 class="h6 mb-0"><i class="bi {{ $groupIcons[$group] ?? 'bi-sliders' }}" aria-hidden="true"></i> {{ $group }}</h2></div>
                <div class="card-body">
                    @if($group === 'Horario de atención')
                        <div class="hours-week mb-4" role="group" aria-label="Horario de cada día">
                            @foreach(\App\Models\SiteSetting::DAYS as $dayKey => [$dayName])
                                @php
                                    $range = \App\Models\SiteSetting::dayRange($settings['hours_'.$dayKey] ?? '');
                                    $closed = (bool) old('hours.'.$dayKey.'.closed', $range['closed']);
                                @endphp
                                <div class="hours-day @if($closed) is-closed @endif @error('hours.'.$dayKey) is-invalid @enderror" data-hours-day>
                                    <span class="hours-day-name">{{ $dayName }}</span>
                                    <div class="form-check form-switch hours-day-switch">
                                        <input type="hidden" name="hours[{{ $dayKey }}][closed]" value="0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="hours-{{ $dayKey }}-closed" name="hours[{{ $dayKey }}][closed]" value="1" @checked($closed) data-hours-closed>
                                        <label class="form-check-label" for="hours-{{ $dayKey }}-closed">Cerrado</label>
                                    </div>
                                    <div class="hours-day-range">
                                        <label class="visually-hidden" for="hours-{{ $dayKey }}-open">{{ $dayName }}: abre</label>
                                        <input id="hours-{{ $dayKey }}-open" type="time" name="hours[{{ $dayKey }}][open]" value="{{ old('hours.'.$dayKey.'.open', $range['open'] ?: '09:00') }}" class="form-control" step="900" @disabled($closed)>
                                        <span class="hours-day-sep" aria-hidden="true">a</span>
                                        <label class="visually-hidden" for="hours-{{ $dayKey }}-close">{{ $dayName }}: cierra</label>
                                        <input id="hours-{{ $dayKey }}-close" type="time" name="hours[{{ $dayKey }}][close]" value="{{ old('hours.'.$dayKey.'.close', $range['close'] ?: '20:00') }}" class="form-control" step="900" @disabled($closed)>
                                    </div>
                                    @error('hours.'.$dayKey)<div class="hours-day-error">{{ $message }}</div>@enderror
                                </div>
                            @endforeach
                        </div>
                    @endif
                @if($fields)
                <div class="row g-3">
                    @foreach($fields as $key => [$label, $type, $placeholder])
                        <div class="col-md-{{ $key === 'map_url' ? '12' : '6' }}">
                            <label for="setting-{{ $key }}" class="form-label fw-semibold">@isset($icons[$key])<i class="bi {{ $icons[$key] }} me-1" aria-hidden="true"></i>@endisset{{ $label }}</label>
                            <input id="setting-{{ $key }}" type="{{ $type }}" name="data[{{ $key }}]" value="{{ old('data.'.$key, $settings[$key] ?? '') }}" class="form-control" placeholder="{{ $placeholder }}" @unless(in_array($key, $optional, true)) required @endunless>
                        </div>
                    @endforeach
                </div>
                @endif
                </div>
                @if($group === 'Horario de atención')<div class="card-footer small text-muted">Elige a qué hora abre y cierra cada día. Las citas y los turnos se ofrecen solo dentro de ese horario y el servicio debe terminar a más tardar a la hora de cierre; los cambios cuentan de inmediato en la web, la app y WhatsApp. Marca <strong>Cerrado</strong> en los días que no abren: ese día no se podrán reservar citas ni pedir turnos.</div>@endif
                @if($group === 'Redes sociales')<div class="card-footer small text-muted">Deja vacía una red para que no aparezca en la página.</div>@endif
            </section>
        @endforeach

        <section class="card content-settings-card">
            <div class="card-header"><h2 class="h6 mb-0"><i class="bi bi-credit-card" aria-hidden="true"></i> Medios de pago que se muestran en la página</h2></div>
            <div class="card-body">
                <p class="text-muted small mb-3">Activa los medios de pago disponibles. Para los medios virtuales, indica el número/cuenta a consignar: se enviará por WhatsApp al cliente cuando los seleccione al reservar o tomar turno.</p>
                <div class="row g-3">
                @php
                    $accountFields = [
                        'nequi' => ['Cuenta/Número Nequi', 'Ej. 3001234567'],
                        'daviplata' => ['Cuenta/Número Daviplata', 'Ej. 3001234567'],
                        'transfer' => ['Cuenta para transferencia', 'Ej. Bancolombia Ahorros 123-456789-00 a nombre de...'],
                        'breb' => ['Llave/Cuenta Bre-B', 'Ej. Llave o número registrado'],
                    ];
                @endphp
                @foreach(\App\Models\SiteSetting::PAYMENT_METHODS as $method => $label)
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="form-check form-switch mb-2">
                                <input type="hidden" name="data[payment_{{ $method }}]" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="payment-{{ $method }}" name="data[payment_{{ $method }}]" value="1" @checked(old('data.payment_'.$method, $settings['payment_'.$method] ?? true))>
                                <label class="form-check-label fw-semibold" for="payment-{{ $method }}">{{ $label }}</label>
                            </div>
                            @if(isset($accountFields[$method]))
                                <label class="form-label small text-muted mb-1" for="account-{{ $method }}">{{ $accountFields[$method][0] }} (para consignar)</label>
                                <input id="account-{{ $method }}" type="text" name="data[account_{{ $method }}]" value="{{ old('data.account_'.$method, $settings['account_'.$method] ?? '') }}" class="form-control" placeholder="{{ $accountFields[$method][1] }}">
                            @else
                                <div class="small text-muted">Pago en el local (no requiere número de cuenta).</div>
                            @endif
                        </div>
                    </div>
                @endforeach
                </div>
            </div>
        </section>

        <section class="card content-settings-card">
            <div class="card-header"><h2 class="h6 mb-0"><i class="bi bi-image" aria-hidden="true"></i> Logo</h2></div>
            <div class="card-body"><div class="row g-3 align-items-center">
                <div class="col-md-4">
                    <div class="settings-image-preview"><img src="{{ !empty($settings['logo_path']) ? asset('storage/'.$settings['logo_path']) : asset('images/logo-una-barberia-512.webp') }}" alt="Logo actual" decoding="async"></div>
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-semibold" for="logo">Cambiar logo (opcional)</label>
                    <input id="logo" type="file" name="logo" accept="image/png,image/webp,image/jpeg,image/svg+xml" class="form-control">
                    <div class="form-text">PNG o WebP sin fondo, hasta 20 MB. Si no eliges otro, se conserva el actual.</div>
                    @if(!empty($settings['logo_path']))
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="removeLogo"><label class="form-check-label small" for="removeLogo">Volver al logo original</label></div>
                    @endif
                </div>
            </div></div>
        </section>

        <div class="form-actions"><button class="btn btn-gold"><i class="bi bi-check2-circle me-1"></i> Guardar configuración</button></div>
    </form>

    {{-- Cuenta del administrador: formulario aparte, con su propio botón, para no reenviar todo lo del negocio. --}}
    @php
        $admin = auth()->user();
        $accountErrors = $errors->getBag('account');
        $passwordFields = [
            'current_password' => ['Contraseña actual', 'current-password', 'Obligatoria para guardar cualquier cambio de esta sección.'],
            'password' => ['Contraseña nueva', 'new-password', 'Déjala vacía si no quieres cambiarla. Mínimo 10 caracteres, con letras y números.'],
            'password_confirmation' => ['Repite la contraseña nueva', 'new-password', ''],
        ];
    @endphp
    <section class="card content-settings-card" id="cuenta-admin" aria-labelledby="cuenta-admin-title">
        <div class="card-header"><h2 class="h6 mb-0" id="cuenta-admin-title"><i class="bi bi-shield-lock" aria-hidden="true"></i> Cuenta del administrador</h2></div>
        <div class="card-body">
            <p class="text-muted small mb-3">Con este correo y esta contraseña entras al panel. Solo el administrador puede iniciar sesión.</p>

            @if($accountErrors->any())
                <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($accountErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif

            <form method="POST" action="{{ route('site-settings.account') }}" autocomplete="on">
                @csrf
                @method('PUT')
                <div class="row g-3">
                    <div class="col-12">
                        <label for="account-email" class="form-label fw-semibold">Correo para ingresar</label>
                        <input id="account-email" type="email" name="email" value="{{ old('email', $admin->email) }}" class="form-control @if($accountErrors->has('email')) is-invalid @endif" required maxlength="150" autocomplete="username">
                    </div>
                    @foreach($passwordFields as $field => [$label, $autocomplete, $help])
                        <div class="col-md-{{ $field === 'current_password' ? '12' : '6' }}">
                            <label for="account-{{ $field }}" class="form-label fw-semibold">{{ $label }}</label>
                            <div class="input-group">
                                <input id="account-{{ $field }}" type="password" name="{{ $field }}" class="form-control @if($accountErrors->has($field === 'password_confirmation' ? 'password' : $field)) is-invalid @endif" autocomplete="{{ $autocomplete }}" @if($field === 'current_password') required @endif @if($help) aria-describedby="account-{{ $field }}-help" @endif>
                                <button type="button" class="btn btn-outline-secondary btn-icon" data-password-toggle="account-{{ $field }}" aria-controls="account-{{ $field }}" aria-pressed="false" aria-label="Mostrar {{ Str::lower($label) }}" title="Mostrar"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                            @if($help)<div class="form-text" id="account-{{ $field }}-help">{{ $help }}</div>@endif
                        </div>
                    @endforeach
                </div>
                <div class="d-flex justify-content-end mt-3"><button class="btn btn-gold"><i class="bi bi-check2-circle me-1"></i> Guardar cuenta</button></div>
            </form>
        </div>
    </section>
@endsection

@push('scripts')
<script>
    // Horario: al marcar "Cerrado" se desactivan las horas de ese día.
    document.querySelectorAll('[data-hours-day]').forEach((row) => {
        const toggle = row.querySelector('[data-hours-closed]');
        const times = row.querySelectorAll('input[type="time"]');
        const sync = () => {
            row.classList.toggle('is-closed', toggle.checked);
            times.forEach((input) => { input.disabled = toggle.checked; input.required = !toggle.checked; });
        };
        toggle.addEventListener('change', sync);
        sync();
    });
    // Ojo de cada contraseña: muestra u oculta lo escrito en ese campo.
    document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
        const input = document.getElementById(btn.dataset.passwordToggle);
        if (!input) return;
        const label = btn.getAttribute('aria-label').replace(/^Mostrar /, '');
        btn.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', String(show));
            btn.setAttribute('aria-label', (show ? 'Ocultar ' : 'Mostrar ') + label);
            btn.title = show ? 'Ocultar' : 'Mostrar';
            btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    });
    // Al enviar, las contraseñas vuelven a ocultarse (el navegador no las guarda como texto visible).
    document.querySelector('#cuenta-admin form')?.addEventListener('submit', (e) => {
        e.currentTarget.querySelectorAll('input[id*="password"]').forEach((i) => { i.type = 'password'; });
    });
</script>
@endpush

@push('styles')
<style>
    .content-settings-card { overflow: hidden; }
    .content-settings-card .card-body { padding: 20px; }
    /* El logo completo cabe en el recuadro, sin recortarse ni salirse (el % de alto no funciona dentro de grid). */
    .settings-image-preview { height: 180px; padding: 12px; border-radius: var(--ui-radius); background: #182224; overflow: hidden; }
    .settings-image-preview img { display: block; width: 100%; height: 100%; max-width: none; object-fit: contain; }
    @media(max-width:575px) { .content-settings-card .card-body { padding: 15px; } }
    /* Horario: una fila por día con interruptor de cerrado y rango de horas. */
    .hours-week { display: grid; gap: 8px; }
    .hours-day { display: grid; grid-template-columns: 110px auto minmax(0, 1fr); align-items: center; gap: 8px 16px; padding: 10px 14px; border: 1px solid var(--ui-border); border-radius: var(--ui-radius); background: var(--ui-surface-2); }
    .hours-day-name { font-weight: 600; }
    .hours-day-switch { margin: 0; min-width: 110px; }
    .hours-day-range { display: flex; align-items: center; gap: 8px; min-width: 0; }
    .hours-day-range .form-control { flex: 1 1 0; min-width: 0; max-width: 150px; }
    .hours-day-sep { color: var(--bs-secondary-color, #6c757d); }
    .hours-day.is-closed .hours-day-range { opacity: .4; }
    .hours-day.is-invalid { border-color: var(--ui-danger); }
    .hours-day-error { grid-column: 1 / -1; color: var(--ui-danger); font-size: .85rem; }
    @media(max-width:575px) {
        .hours-day { grid-template-columns: 1fr auto; }
        .hours-day-range { grid-column: 1 / -1; }
        .hours-day-range .form-control { max-width: none; }
    }
</style>
@endpush
