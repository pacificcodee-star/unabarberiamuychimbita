@extends('layouts.app')

@section('title', 'Cortes destacados')

@section('actions')
    @if(true)
        <a href="{{ route('featured-cuts.create') }}" class="btn btn-gold"><i class="bi bi-plus-lg me-1"></i> Agregar corte</a>
    @endif
@endsection

@section('content')
    <div class="alert alert-light border mb-4">
        <i class="bi bi-stars text-warning me-1"></i>
        Estos cortes son las <strong>tarjetas cuadradas que giran en el inicio</strong> (la espiral). Solo se muestran los que agregues aquí, en el orden de su posición.
        Las tarjetas son cuadradas (1:1): sube fotos cuadradas o se recortarán al centro.
        <span class="d-block mt-1">{{ $cuts->count() }} cortes, sin límite. Si no hay ninguno visible, la espiral no aparece en el inicio.</span>
    </div>

    <div class="row g-4">
        @forelse($cuts as $cut)
            <div class="col-sm-6 col-xl-4">
                <article class="card h-100 overflow-hidden">
                    <div class="cut-preview">
                        <img src="{{ $cut->image_path ? asset('storage/'.$cut->image_path) : asset('images/logo-una-barberia.png') }}" alt="{{ $cut->title }}">
                        @if(count($cut->galleryPaths()))
                            <span class="cut-preview-count"><i class="bi bi-images"></i> +{{ count($cut->galleryPaths()) }}</span>
                        @endif
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div>
                                <div class="fw-semibold">{{ $cut->title }}</div>
                                <div class="small text-muted">Posición {{ $cut->sort_order + 1 }}{{ $cut->service ? ' · '.$cut->service->name : '' }}</div>
                            </div>
                            <span class="badge-soft {{ $cut->active ? 'green' : 'red' }}">{{ $cut->active ? 'Visible' : 'Oculto' }}</span>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <a href="{{ route('featured-cuts.edit', $cut) }}" class="btn btn-sm btn-outline-dark"><i class="bi bi-pencil me-1"></i> Editar</a>
                            <form method="POST" action="{{ route('featured-cuts.destroy', $cut) }}" onsubmit="return confirm('¿Retirar este corte del inicio? Se borrarán sus imágenes.')">
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
                <div class="card"><div class="empty-state"><i class="bi bi-stars"></i>Aún no hay cortes destacados.<div class="mt-3"><a href="{{ route('featured-cuts.create') }}" class="btn btn-gold">Agregar el primer corte</a></div></div></div>
            </div>
        @endforelse
    </div>
@endsection

@push('styles')
<style>
    .cut-preview { position: relative; height: 270px; display: grid; place-items: center; padding: 16px; background: radial-gradient(circle at 60% 20%, rgba(200,165,90,.35), transparent 45%), linear-gradient(145deg, #0f0e0c, #29251d); }
    .cut-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .cut-preview-count { position: absolute; right: 12px; bottom: 12px; padding: 4px 10px; border-radius: 999px; background: rgba(0,0,0,.6); color: #f4efe6; font-size: .75rem; }
</style>
@endpush
