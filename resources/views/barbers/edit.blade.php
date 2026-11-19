@extends('layouts.app')

@section('title', 'Editar barbero')

@section('actions')
    <a href="{{ route('barbers.index') }}" class="btn btn-outline-dark">
        <i class="bi bi-arrow-left me-1"></i> Volver
    </a>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            @include('barbers._form', ['barber' => $barber])
        </div>
    </div>
@endsection
