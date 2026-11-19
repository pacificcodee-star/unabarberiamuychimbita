@extends('layouts.app')

@section('title', 'Nuevo servicio')

@section('actions')
    <a href="{{ route('services.index') }}" class="btn btn-outline-dark">
        <i class="bi bi-arrow-left me-1"></i> Volver
    </a>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            @include('services._form', ['service' => null])
        </div>
    </div>
@endsection
