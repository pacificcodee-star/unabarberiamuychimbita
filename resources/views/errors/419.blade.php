@extends('errors.layout')
@section('title', 'Sesión vencida')
@section('code', '419')
@section('heading', 'La página estuvo abierta mucho tiempo.')
@section('message', 'Por seguridad, la sesión se venció y el envío no se procesó. Vuelve atrás: tus datos siguen en el formulario. Recarga la página y envíalo de nuevo.')
@section('actions')
    <a class="btn" href="javascript:history.back()">Volver al formulario</a>
    <a class="btn btn--ghost" href="/">Ir al inicio</a>
@endsection
