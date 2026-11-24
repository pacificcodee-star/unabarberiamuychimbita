@extends('layouts.app')

@section('title', 'Barberos')

@section('actions')
    <a href="{{ route('barbers.create') }}" class="btn btn-gold">
        <i class="bi bi-person-plus me-1"></i> Nuevo barbero
    </a>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('barbers.index') }}" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small text-muted mb-1">Buscar</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Nombre o especialidad">
                </div>
                <div class="col-auto">
                    <button class="btn btn-dark"><i class="bi bi-search me-1"></i> Buscar</button>
                </div>
                @if(request()->filled('search'))
                    <div class="col-auto"><a href="{{ route('barbers.index') }}" class="btn btn-outline-dark">Limpiar</a></div>
                @endif
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                <tr>
                    <th>Barbero</th>
                    <th>Modalidad</th>
                    <th class="text-end">Comisión por servicio</th>
                    <th class="text-end">Servicios realizados</th>
                    <th class="text-end">Pendiente</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse($users as $user)
                    @php
                        $pending = max(0, (float) ($user->total_commission ?? 0) + (float) ($user->total_supplies ?? 0) - (float) ($user->total_paid ?? 0));
                    @endphp
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                @if($user->photo_path)
                                    <img src="{{ asset('storage/'.$user->photo_path) }}" alt="Foto de {{ $user->name }}" class="admin-barber-thumb" width="40" height="40" style="width:40px;height:40px;min-width:40px;border-radius:50%;object-fit:cover;object-position:center top;border:2px solid var(--c-violet-soft);background:var(--ui-surface-3)" loading="lazy">
                                @else
                                    <div class="avatar" style="width: 40px; height: 40px; min-width: 40px; font-size: .85rem;">{{ strtoupper(substr($user->name, 0, 1)) }}</div>
                                @endif
                                <div>
                                    <div class="fw-semibold">{{ $user->name }}</div>
                                    @if($user->phone)<div class="small text-muted">{{ $user->phone }}</div>@endif
                                    @if($user->specialty)
                                        <div class="small text-warning-emphasis">{{ $user->specialty }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="small">{{ $user->role === 'barbero' ? $user->attentionModeLabel() : '—' }}</td>
                        @php($rates = $user->serviceCommissions->pluck('pivot.percentage')->map(fn ($p) => (float) $p))
                        <td class="text-end small">{{ $rates->isEmpty() ? '—' : ($rates->min() === $rates->max() ? number_format($rates->min(), 0).'%' : number_format($rates->min(), 0).'–'.number_format($rates->max(), 0).'%') }}</td>
                        <td class="text-end">{{ format_money($user->total_sales ?? 0) }}</td>
                        <td class="text-end fw-semibold {{ $pending > 0 ? 'text-danger' : '' }}">{{ format_money($pending) }}</td>
                        <td>
                            <span class="badge-soft {{ $user->active ? 'green' : 'red' }}">{{ $user->active ? 'Activo' : 'Inactivo' }}</span>
                        </td>
                        <td class="text-end text-nowrap">
                            <form method="POST" action="{{ route('barbers.toggle', $user) }}" class="d-inline">
                                @csrf
                                @method('PATCH')
                                <button class="btn btn-sm {{ $user->active ? 'btn-outline-warning' : 'btn-outline-success' }}" title="{{ $user->active ? 'Deshabilitar barbero' : 'Habilitar barbero' }}">
                                    <i class="bi {{ $user->active ? 'bi-person-slash' : 'bi-person-check' }}"></i>
                                </button>
                            </form>
                            <a href="{{ route('barbers.edit', $user) }}" class="btn btn-sm btn-outline-dark btn-icon" title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                            @if($user->id !== auth()->id())
                                <form method="POST" action="{{ route('barbers.destroy', $user) }}" class="d-inline" onsubmit="return confirm('¿Eliminar o desactivar este barbero?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger btn-icon" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash"></i></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                No hay barberos registrados.
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($users->hasPages())
            <div class="card-body border-top">{{ $users->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
@endsection
