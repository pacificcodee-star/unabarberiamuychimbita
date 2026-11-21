@extends('errors.layout')
@section('title', 'En mantenimiento')
@section('code', '503')
@section('heading', 'Estamos haciendo ajustes.')
@section('message', 'El sitio vuelve en unos minutos. Si necesitas algo ya, escríbenos por WhatsApp.')
@section('actions')
    <a class="btn" href="javascript:location.reload()">Intentar de nuevo</a>
@endsection
