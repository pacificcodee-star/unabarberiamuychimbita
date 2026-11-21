@extends('errors.layout')
@section('title', 'Inicia sesión')
@section('code', '401')
@section('heading', 'Necesitas iniciar sesión.')
@section('message', 'Esta sección es solo para el equipo de la barbería.')
@section('actions')
    <a class="btn" href="/login">Iniciar sesión</a>
    <a class="btn btn--ghost" href="/">Ir al inicio</a>
@endsection
