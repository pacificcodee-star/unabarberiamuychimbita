@extends('errors.layout')
@section('title', 'Sin permiso')
@section('code', '403')
@section('heading', 'No tienes permiso para ver esto.')
@section('message', 'Tu usuario no tiene acceso a esta sección o a este registro. Si crees que es un error, habla con el administrador.')
@section('actions')
    <a class="btn" href="javascript:history.back()">Volver</a>
    <a class="btn btn--ghost" href="/">Ir al inicio</a>
@endsection
