@extends('errors.layout')
@section('title', 'Demasiados intentos')
@section('code', '429')
@section('heading', 'Vas un poco rápido.')
@section('message', 'Recibimos muchas solicitudes seguidas desde tu conexión. Espera un minuto y vuelve a intentarlo.')
@section('actions')
    <a class="btn" href="javascript:history.back()">Volver</a>
    <a class="btn btn--ghost" href="/">Ir al inicio</a>
@endsection
