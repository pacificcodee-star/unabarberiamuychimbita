@extends('auth.layout')

@section('title', 'Nueva contraseña')

@section('card')
            <h2>Crea tu contraseña nueva</h2>
            <p class="lead-copy">Mínimo 10 caracteres, con letras y números. Al guardarla se cierran las sesiones abiertas de tu cuenta.</p>

            @if($errors->any())
                <div class="alert alert-danger mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i> {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="mb-3">
                    <label class="form-label" for="email">Correo electrónico</label>
                    <input type="email" class="form-control" id="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username" maxlength="150">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Contraseña nueva</label>
                    <input type="password" class="form-control" id="password" name="password" required minlength="10" autocomplete="new-password">
                </div>
                <div class="mb-4">
                    <label class="form-label" for="password_confirmation">Repite la contraseña</label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required minlength="10" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-gold btn-lg w-100">Guardar contraseña <i class="bi bi-check2" aria-hidden="true"></i></button>
            </form>
@endsection
