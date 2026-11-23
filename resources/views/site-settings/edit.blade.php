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
            'Horario de atención' => collect(\App\Models\SiteSetting::DAYS)->mapWithKeys(fn ($day, $key) => [
                'hours_'.$key => [$day[0], 'text', 'Ej. 9:00 a. m. - 12:00 m. y 2:00 - 8:00 p. m.  ·  o «Cerrado»'],
            ])->all() + [
                'booking_start' => ['Primera hora para reservar citas', 'time', ''],
                'booking_end' => ['Última hora para reservar citas', 'time', ''],
            ],
            'Redes sociales' => [
                'whatsapp' => ['WhatsApp (solo números, con indicativo)', 'text', 'Ej. 573001234567'],
                'instagram_url' => ['Instagram', 'url', 'https://www.instagram.com/tu_cuenta'],
                'facebook_url' => ['Facebook', 'url', 'https://www.facebook.com/tu_pagina'],
                'tiktok_url' => ['TikTok', 'url', 'https://www.tiktok.com/@tu_cuenta'],
            ],
        ];
        $optional = ['map_coords', 'instagram_url', 'facebook_url', 'tiktok_url', 'hours_mon', 'hours_tue', 'hours_wed', 'hours_thu', 'hours_fri', 'hours_sat', 'hours_sun'];
        $icons = ['whatsapp' => 'bi-whatsapp', 'instagram_url' => 'bi-instagram', 'facebook_url' => 'bi-facebook', 'tiktok_url' => 'bi-tiktok'];
    @endphp

    <div class="mb-4">
        <h1 class="h3 mb-1">Configuración del negocio</h1>
        <p class="text-muted mb-0">Datos de contacto, horarios, redes, medios de pago y logo. Los textos de la página se cambian en el código.</p>
    </div>

    @if($errors->any())
        <div class="alert alert-danger"><strong>Revisa estos campos:</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('site-settings.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        @foreach($groups as $group => $fields)
            <section class="card mb-4 content-settings-card">
                <div class="card-header"><h2 class="h6 mb-0">{{ $group }}</h2></div>
                <div class="card-body"><div class="row g-3">
                    @foreach($fields as $key => [$label, $type, $placeholder])
                        <div class="col-md-{{ $key === 'map_url' ? '12' : '6' }}">
                            <label for="setting-{{ $key }}" class="form-label fw-semibold">@isset($icons[$key])<i class="bi {{ $icons[$key] }} me-1" aria-hidden="true"></i>@endisset{{ $label }}</label>
                            <input id="setting-{{ $key }}" type="{{ $type }}" name="data[{{ $key }}]" value="{{ old('data.'.$key, $settings[$key] ?? '') }}" class="form-control" placeholder="{{ $placeholder }}" @unless(in_array($key, $optional, true)) required @endunless>
                        </div>
                    @endforeach
                </div></div>
                @if($group === 'Horario de atención')<div class="card-footer bg-transparent small text-muted">Escribe el horario como quieras que se vea en la página. Escribe <strong>Cerrado</strong> (o déjalo vacío) en los días que no abren: ese día no se podrán reservar citas.</div>@endif
                @if($group === 'Redes sociales')<div class="card-footer bg-transparent small text-muted">Deja vacía una red para que no aparezca en la página.</div>@endif
            </section>
        @endforeach

        <section class="card mb-4 content-settings-card">
            <div class="card-header"><h2 class="h6 mb-0">Medios de pago que se muestran en la página</h2></div>
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

        <section class="card mb-4 content-settings-card">
            <div class="card-header"><h2 class="h6 mb-0">Logo</h2></div>
            <div class="card-body"><div class="row g-3 align-items-center">
                <div class="col-md-4">
                    <div class="settings-image-preview"><img src="{{ !empty($settings['logo_path']) ? asset('storage/'.$settings['logo_path']) : asset('images/logo-una-barberia-512.webp') }}" alt="Logo actual" loading="lazy" decoding="async"></div>
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

        <div class="d-flex justify-content-end mb-4"><button class="btn btn-gold"><i class="bi bi-check2-circle me-1"></i> Guardar configuración</button></div>
    </form>
@endsection

@push('styles')
<style>
    .content-settings-card { border: 1px solid rgba(24,34,36,.09); border-radius: 16px; overflow: hidden; box-shadow: 0 8px 24px rgba(16,24,24,.045); }
    .content-settings-card .card-header { padding: 15px 20px; background: linear-gradient(100deg,rgba(160,46,37,.12),rgba(160,46,37,.025)); }
    .content-settings-card .card-body { padding: 20px; }
    .settings-image-preview { display: grid; height: 140px; place-items: center; padding: 10px; border-radius: 12px; background: #182224; }
    .settings-image-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
    @media(max-width:575px) { .content-settings-card .card-body { padding: 15px; } }
</style>
@endpush
