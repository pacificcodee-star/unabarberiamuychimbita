@extends('errors.layout')
@section('title', 'Problema en el servidor')
@section('code', '500')
@section('heading', 'Algo falló de nuestro lado.')
@section('message', 'No es tu culpa. El equipo ya quedó avisado. Intenta de nuevo en un momento; si estabas reservando, revisa primero tu WhatsApp o el enlace de seguimiento antes de reservar otra vez.')
@section('actions')
    <a class="btn" href="javascript:location.reload()">Intentar de nuevo</a>
    <a class="btn btn--ghost" href="/">Ir al inicio</a>
@endsection
