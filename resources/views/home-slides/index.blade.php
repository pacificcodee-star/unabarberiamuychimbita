@extends('layouts.app')

@section('title', 'Imágenes del inicio')

@section('actions')
    @if($slides->count() < 10)
        <a href="{{ route('home-slides.create') }}" class="btn btn-gold"><i class="bi bi-plus-lg me-1"></i> Agregar imagen</a>
    @endif
@endsection

@section('content')
    <div class="alert alert-light border mb-4">
        <i class="bi bi-info-circle text-warning me-1"></i>
        Administra hasta 10 imágenes para el carrusel principal. Se muestran cada 5 segundos. Para conservar el fondo transparente, sube archivos PNG o WebP con transparencia.
        <span class="d-block mt-1">{{ $slides->count() }} de 10 imágenes agregadas.</span>
    </div>

    <div class="row g-4">
        @forelse($slides as $slide)
            <div class="col-sm-6 col-xl-4">
                <article class="card h-100 overflow-hidden">
                    <div class="home-slide-preview">
                        <img src="{{ asset('storage/'.$slide->image_path) }}" alt="{{ $slide->title ?: 'Imagen del carrusel' }}">
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div>
                                <div class="fw-semibold">{{ $slide->title ?: 'Imagen sin título' }}</div>
                                <div class="small text-muted">Posición {{ $slide->sort_order + 1 }}</div>
                            </div>
                            <span class="badge-soft {{ $slide->active ? 'green' : 'red' }}">{{ $slide->active ? 'Visible' : 'Oculta' }}</span>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <a href="{{ route('home-slides.edit', $slide) }}" class="btn btn-sm btn-outline-dark"><i class="bi bi-pencil me-1"></i> Editar</a>
                            <form method="POST" action="{{ route('home-slides.destroy', $slide) }}" onsubmit="return confirm('¿Retirar esta imagen del carrusel?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i> Quitar</button>
                            </form>
                        </div>
                    </div>
                </article>
            </div>
        @empty
            <div class="col-12">
                <div class="card"><div class="empty-state"><i class="bi bi-images"></i>Aún no hay imágenes en el carrusel.<div class="mt-3"><a href="{{ route('home-slides.create') }}" class="btn btn-gold">Agregar la primera imagen</a></div></div></div>
            </div>
        @endforelse
    </div>
@endsection

@push('styles')
<style>
    .home-slide-preview { height: 270px; display: grid; place-items: center; padding: 16px; background: radial-gradient(circle at 50% 75%, rgba(201,162,39,.23), transparent 48%), linear-gradient(145deg,#171717,#29251b); }
    .home-slide-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
</style>
@endpush
