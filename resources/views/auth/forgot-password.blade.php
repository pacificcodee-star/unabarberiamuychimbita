@extends('auth.layout')

@section('title', 'Recuperar contraseña')

@section('card')
            <a href="{{ route('login') }}" class="back-link"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a ingresar</a>
            <h2>Recuperar contraseña</h2>
            <p class="lead-copy">Escribe el correo de tu cuenta y te enviamos un enlace para crear una contraseña nueva.</p>

            @if(session('status'))
                <div class="alert alert-success mb-4" role="status">
                    <i class="bi bi-envelope-check me-1" aria-hidden="true"></i> {{ session('status') }}
                </div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i> {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf
                <div class="mb-4">
                    <label class="form-label" for="email">Correo electrónico</label>
                    <input type="email" class="form-control" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" maxlength="150">
                </div>
                <button type="submit" class="btn btn-gold btn-lg w-100">Enviar enlace <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
            </form>
@endsection
