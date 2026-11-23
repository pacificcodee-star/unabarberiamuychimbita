@extends('layouts.app')

@section('title', 'Notificaciones')

@push('styles')
<style>
    .nt-page-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
    .nt-tabs { display: inline-flex; gap: 4px; padding: 4px; border: 1px solid var(--bs-border-color); border-radius: 12px; background: var(--bs-body-bg); }
    .nt-tabs a { display: inline-flex; align-items: center; gap: 7px; min-height: 36px; padding: 0 14px; border-radius: 9px; color: var(--bs-secondary-color); font-size: .84rem; font-weight: 700; text-decoration: none; transition: background-color .15s ease, color .15s ease; }
    .nt-tabs a:hover { color: var(--bs-body-color); background: rgba(127, 127, 127, .08); }
    .nt-tabs a.is-active { background: var(--gold); color: #fff; }
    .nt-card { overflow: hidden; }
    .nt-card .nt-head { padding: 18px 22px 14px; }
    .nt-card .nt-head h2 { font-size: 1.05rem; }
    .nt-card .nt-day { padding: 12px 22px 6px; position: static; }
    .nt-card .nt-item { padding: 14px 22px; }
    .nt-card .nt-text span.nt-full { -webkit-line-clamp: unset; display: block; }
    .modal .nt-ico { width: 48px; height: 48px; font-size: 1.25rem; }
    @media (max-width: 575.98px) {
        .nt-tabs { width: 100%; }
        .nt-tabs a { flex: 1; justify-content: center; padding: 0 8px; font-size: .78rem; }
        .nt-page-bar .btn { width: 100%; }
    }
</style>
@endpush

@section('content')
    @php
        $statusColors = ['enviada' => 'green', 'fallida' => 'red', 'expirada' => 'red', 'cancelada' => '', 'pendiente' => 'gold', 'enviando' => 'gold'];
        $statusLabels = ['enviada' => 'Enviada', 'fallida' => 'Falló', 'expirada' => 'Caducó sin entregarse', 'cancelada' => 'Cancelada', 'pendiente' => 'Pendiente', 'enviando' => 'Enviando'];
    @endphp

    <div class="nt-page-bar">
        <nav class="nt-tabs" aria-label="Tipo de avisos">
            <a class="{{ $tab === 'equipo' ? 'is-active' : '' }}" href="{{ route('staff-notifications.index') }}" @if($tab === 'equipo') aria-current="page" @endif><i class="bi bi-bell" aria-hidden="true"></i> Para el equipo</a>
            <a class="{{ $tab === 'clientes' ? 'is-active' : '' }}" href="{{ route('staff-notifications.index', ['tab' => 'clientes']) }}" @if($tab === 'clientes') aria-current="page" @endif><i class="bi bi-send" aria-hidden="true"></i> Enviados a clientes</a>
        </nav>
        @if($tab === 'equipo' && $staff->total() > 0)
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#clearNotificationsModal">
                <i class="bi bi-trash3" aria-hidden="true"></i> Eliminar todas
            </button>
        @endif
    </div>

    @if($tab === 'equipo')
        <div class="card nt-card">
            <div class="nt-head">
                <div>
                    <h2>Avisos para el equipo</h2>
                    <p>{{ $staff->total() ? $staff->total().' '.($staff->total() === 1 ? 'notificación' : 'notificaciones') : 'Estás al día' }}</p>
                </div>
            </div>
            @if($staff->isEmpty())
                <div class="nt-empty">
                    <span class="nt-ico tone-neutral" aria-hidden="true"><i class="bi bi-bell-slash"></i></span>
                    <strong>No tienes notificaciones</strong>
                    <p>Aquí verás los turnos y citas nuevos, las cancelaciones de clientes y el avance automático de la fila.</p>
                </div>
            @else
                @php $currentDay = null; @endphp
                @foreach($staff as $item)
                    @if($item->dayLabel() !== $currentDay)
                        @php $currentDay = $item->dayLabel(); @endphp
                        <div class="nt-day">{{ $currentDay }}</div>
                    @endif
                    @php $tag = $item->url ? 'a' : 'div'; @endphp
                    <{{ $tag }} class="nt-item" @if($item->url) href="{{ $item->url }}" @endif>
                        <span class="nt-ico tone-{{ $item->tone() }}" aria-hidden="true"><i class="bi {{ $item->icon() }}"></i></span>
                        <span class="nt-text">
                            <strong>{{ $item->title }}</strong>
                            @if($item->body)<span class="nt-full">{{ $item->body }}</span>@endif
                            <time datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->format('g:i a') }} · {{ $item->created_at->locale('es')->diffForHumans() }}@if(auth()->user()->isAdmin() && $item->barber) · {{ $item->barber->name }}@endif</time>
                        </span>
                    </{{ $tag }}>
                @endforeach
            @endif
        </div>
        <div class="mt-3">{{ $staff->links('pagination::bootstrap-5') }}</div>

        <div class="modal fade" id="clearNotificationsModal" tabindex="-1" aria-labelledby="clearNotificationsTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ route('staff-notifications.clear') }}">
                    @csrf
                    @method('DELETE')
                    <div class="modal-body p-4">
                        <span class="nt-ico tone-red mb-3" aria-hidden="true"><i class="bi bi-trash3"></i></span>
                        <h2 class="h5 fw-bold mb-2" id="clearNotificationsTitle">¿Eliminar todas tus notificaciones?</h2>
                        <p class="text-muted small mb-0">Se borrarán las {{ $staff->total() }} notificaciones de tu bandeja. Las nuevas seguirán llegando como siempre. Las de los demás miembros del equipo no se modifican.</p>
                    </div>
                    <div class="modal-footer border-0 pt-0 px-4 pb-4">
                        <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger"><i class="bi bi-trash3" aria-hidden="true"></i> Eliminar todas</button>
                    </div>
                </form>
            </div>
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <th>Programado</th>
                        <th>Cliente</th>
                        <th>Aviso</th>
                        <th>Canal</th>
                        <th>Estado</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($clients as $item)
                        <tr>
                            <td class="text-nowrap">{{ $item->scheduled_for?->format('d/m h:i A') }}</td>
                            <td>
                                <div class="fw-semibold">{{ $item->notifiable?->client_name ?? 'Registro eliminado' }}</div>
                                <div class="small text-muted">{{ $item->notifiable_type === 'turn' ? 'Turno' : 'Cita' }} #{{ $item->notifiable_id }}</div>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $item->title }}</div>
                                <div class="small text-muted">{{ $item->body }}</div>
                            </td>
                            <td>{{ $item->channel === 'whatsapp' ? 'WhatsApp' : 'Push' }}</td>
                            <td>
                                <span class="badge-soft {{ $statusColors[$item->status] ?? '' }}">{{ $statusLabels[$item->status] ?? ucfirst($item->status) }}</span>
                                @if($item->status === 'pendiente' && $item->channel === 'push' && $item->scheduled_for?->isPast())
                                    <div class="small text-muted mt-1">El cliente aún no ha activado los avisos.</div>
                                @elseif($item->last_error && $item->status !== 'enviada')
                                    <div class="small text-muted mt-1">{{ \Illuminate\Support\Str::limit($item->last_error, 110) }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <i class="bi bi-send"></i>
                                    Todavía no se ha programado ningún aviso para clientes.
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $clients->links('pagination::bootstrap-5') }}</div>
    @endif
@endsection
