@extends('layouts.app')

@section('title', 'Servicios')

@section('actions')
    <a href="{{ route('services.create') }}" class="btn btn-gold">
        <i class="bi bi-plus-lg me-1"></i> Nuevo servicio
    </a>
@endsection

@push('styles')
<style>
    .service-thumb { width: 44px; height: 44px; flex: 0 0 44px; display: grid; place-items: center; border-radius: 10px; background: #f6e6e3; color: #a02e25; overflow: hidden; }
    .service-thumb img { width: 100%; height: 100%; object-fit: contain; padding: 3px; }
</style>
@endpush

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('services.index') }}" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small text-muted mb-1">Buscar servicio</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Nombre del servicio">
                </div>
                <div class="col-auto">
                    <button class="btn btn-dark"><i class="bi bi-search me-1"></i> Buscar</button>
                </div>
                @if(request('search'))
                    <div class="col-auto">
                        <a href="{{ route('services.index') }}" class="btn btn-outline-dark">Limpiar</a>
                    </div>
                @endif
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                <tr>
                    <th>Servicio</th>
                    <th>Categoría</th>
                    <th>Descripción</th>
                    <th>Duración</th>
                    <th class="text-end">Precio</th>
                    <th class="text-end" title="Total desde el primer servicio, igual que en Informes">Realizados</th>
                    <th class="text-end" title="Total desde el primer servicio, igual que en Informes">Ingresos</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse($services as $service)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="service-thumb">
                                    @if($service->image_path)
                                        <img src="{{ asset('storage/'.$service->image_path) }}" alt="" loading="lazy">
                                    @else
                                        <i class="bi bi-image" aria-hidden="true"></i>
                                    @endif
                                </span>
                                <span class="fw-semibold">{{ $service->name }}</span>
                            </div>
                        </td>
                        <td><span class="badge-soft gold">{{ $service->categoryLabel() }}</span></td>
                        <td class="text-muted small">{{ $service->description ?: '—' }}</td>
                        <td>{{ $service->duration_minutes }} min</td>
                        <td class="text-end fw-semibold">{{ format_money($service->price) }}</td>
                        <td class="text-end">{{ (int) ($stats[$service->id]->qty ?? 0) }}</td>
                        <td class="text-end">{{ format_money((float) ($stats[$service->id]->total ?? 0)) }}</td>
                        <td>
                            <span class="badge-soft {{ $service->active ? 'green' : 'red' }}">
                                {{ $service->active ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('services.edit', $service) }}" class="btn btn-sm btn-outline-dark">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="{{ route('services.destroy', $service) }}" class="d-inline" onsubmit="return confirm('¿Eliminar o desactivar este servicio?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <i class="bi bi-tags"></i>
                                No hay servicios registrados.
                                <div class="mt-3"><a href="{{ route('services.create') }}" class="btn btn-gold">Crear servicio</a></div>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($services->hasPages())
            <div class="card-body border-top">{{ $services->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
@endsection
