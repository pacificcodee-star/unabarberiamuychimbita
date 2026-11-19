@extends('layouts.app')

@section('title', 'Nueva cita')

@section('actions')
    <a href="{{ route('appointments.index') }}" class="btn btn-outline-dark">
        <i class="bi bi-arrow-left me-1"></i> Volver
    </a>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            @include('appointments._form', ['appointment' => null])
        </div>
    </div>
@endsection
