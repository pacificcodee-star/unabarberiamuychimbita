@extends('layouts.app')

@section('title', 'Registrar gasto')

@section('actions')
    <a href="{{ route('expenses.index') }}" class="btn btn-outline-dark">
        <i class="bi bi-arrow-left me-1"></i> Volver
    </a>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            @include('expenses._form', ['expense' => null])
        </div>
    </div>
@endsection
