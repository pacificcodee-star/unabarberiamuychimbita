@extends('layouts.app')

@section('title', $slide ? 'Editar imagen del inicio' : 'Agregar imagen al inicio')

@section('actions')
    <a href="{{ route('home-slides.index') }}" class="btn btn-outline-dark"><i class="bi bi-arrow-left me-1"></i> Volver</a>
@endsection

@section('content')
    <div class="row justify-content-center"><div class="col-lg-8">
        <form method="POST" enctype="multipart/form-data" action="{{ $slide ? route('home-slides.update', $slide) : route('home-slides.store') }}">
            @csrf
            @if($slide) @method('PUT') @endif
            <div class="card">
                <div class="card-header">{{ $slide ? 'Editar diapositiva' : 'Nueva diapositiva' }}</div>
                <div class="card-body">
                    @if($slide)
                        <div class="home-slide-current mb-3"><img src="{{ asset('storage/'.$slide->image_path) }}" alt="Imagen actual"></div>
                    @endif
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Imagen {{ $slide ? '(opcional para conservar la actual)' : '*' }}</label>
                        <input type="file" name="image" class="form-control" accept="image/*" {{ $slide ? '' : 'required' }}>
                        <div class="form-text">Acepta formatos de imagen comunes, hasta 20 MB. Para que el carrusel luzca mejor, usa una foto horizontal.</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label fw-semibold">Texto alternativo / título</label><input name="title" value="{{ old('title', $slide->title ?? '') }}" maxlength="120" class="form-control" placeholder="Ej. Nuestro equipo"></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Orden</label><input type="number" name="sort_order" value="{{ old('sort_order', $slide->sort_order ?? 0) }}" min="0" max="999" class="form-control" required></div>
                    </div>
                    <div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" @checked(old('active', $slide->active ?? true))><label class="form-check-label" for="active">Mostrar en el carrusel</label></div>
                </div>
                <div class="card-body border-top d-flex justify-content-end gap-2"><a href="{{ route('home-slides.index') }}" class="btn btn-outline-dark">Cancelar</a><button class="btn btn-gold">Guardar imagen</button></div>
            </div>
        </form>
    </div></div>
@endsection

@push('styles')
<style>.home-slide-current { height: 220px; display:grid; place-items:center; padding:12px; border-radius:14px; background:linear-gradient(145deg,#171717,#29251b); }.home-slide-current img { max-height:100%; max-width:100%; object-fit:contain; }</style>
@endpush
