@extends('layouts.app')

@section('title', $review ? 'Editar reseña' : 'Agregar reseña')

@section('actions')
    <a href="{{ route('reviews.index') }}" class="btn btn-outline-dark"><i class="bi bi-arrow-left me-1"></i> Volver</a>
@endsection

@section('content')
    <div class="row justify-content-center"><div class="col-lg-8">
        <form method="POST" action="{{ $review ? route('reviews.update', $review) : route('reviews.store') }}">
            @csrf
            @if($review) @method('PUT') @endif
            <div class="card">
                <div class="card-header">{{ $review ? 'Editar reseña' : 'Nueva reseña' }}</div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label fw-semibold">Nombre del cliente</label><input name="client_name" value="{{ old('client_name', $review->client_name ?? '') }}" class="form-control" maxlength="120" required></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Calificación</label><select name="rating" class="form-select" required>@for($rating = 5; $rating >= 1; $rating--)<option value="{{ $rating }}" @selected(old('rating', $review->rating ?? 5) == $rating)>{{ $rating }} {{ $rating === 1 ? 'estrella' : 'estrellas' }}</option>@endfor</select></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Comentario</label><textarea name="comment" rows="5" maxlength="1500" class="form-control" required>{{ old('comment', $review->comment ?? '') }}</textarea></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="approved" name="approved" value="1" @checked(old('approved', $review->approved ?? true))><label class="form-check-label" for="approved">Mostrar en la página pública</label></div>
                </div>
                <div class="card-body border-top d-flex justify-content-end gap-2"><a href="{{ route('reviews.index') }}" class="btn btn-outline-dark">Cancelar</a><button class="btn btn-gold">Guardar reseña</button></div>
            </div>
        </form>
    </div></div>
@endsection
