@extends('auth.layout')

@section('title', 'Ingresar')

@section('card')
            <a href="{{ route('landing') }}" class="back-link"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al sitio</a>
            <h2>Bienvenido</h2>
            <p class="lead-copy">Ingresa con tu cuenta para administrar la barbería.</p>

            @if(session('status'))
                <div class="alert alert-success mb-4" role="status"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i> {{ session('status') }}</div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.store') }}" id="loginForm">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="email">Correo electrónico</label>
                    <input type="email" class="form-control {{ $errors->has('email') ? 'is-invalid' : '' }}" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="tucorreo@barberia.com">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Contraseña</label>
                    <div class="password-field">
                        <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password" placeholder="••••••••">
                        <button type="button" class="password-toggle" id="passwordToggle" aria-label="Mostrar contraseña" aria-controls="password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
                <p class="mb-3 text-end"><a href="{{ route('password.request') }}" class="forgot-link">¿Olvidaste tu contraseña?</a></p>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                    <label class="form-check-label" for="remember">Mantener la sesión iniciada</label>
                </div>
                <button type="submit" class="btn btn-gold btn-lg w-100">
                    Ingresar <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </button>
            </form>

            {{-- Solo en el equipo local: en producción jamás se muestran credenciales. --}}
            @if(app()->environment('local'))
            <div class="demo-box">
                <strong>Acceso de prueba</strong><br>
                Administrador: <code>admin@barberia.com</code> / <code>password</code><br>
                Barbero: <code>carlos@barberia.com</code> / <code>password</code>
            </div>
            @endif
@endsection

@push('scripts')
<script>
    (() => {
        const input = document.getElementById('password');
        const toggle = document.getElementById('passwordToggle');
        toggle?.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
            toggle.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
        document.getElementById('loginForm')?.addEventListener('submit', (event) => {
            if (!event.target.checkValidity()) return;
            const button = event.target.querySelector('[type="submit"]');
            button.disabled = true;
            button.innerHTML = '<span class="spinner" aria-hidden="true"></span> Ingresando…';
        });
    })();
</script>
@endpush
