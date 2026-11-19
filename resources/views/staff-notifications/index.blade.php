@extends('layouts.app')

@section('title', 'Notificaciones')

@section('content')
    @php
        $statusColors = ['enviada' => 'green', 'fallida' => 'red', 'expirada' => 'red', 'cancelada' => '', 'pendiente' => 'gold', 'enviando' => 'gold'];
        $statusLabels = ['enviada' => 'Enviada', 'fallida' => 'Falló', 'expirada' => 'Caducó sin entregarse', 'cancelada' => 'Cancelada', 'pendiente' => 'Pendiente', 'enviando' => 'Enviando'];
    @endphp

    <ul class="nav nav-pills mb-3 gap-2">
        <li class="nav-item">
            <a class="btn {{ $tab === 'equipo' ? 'btn-gold' : 'btn-outline-dark' }}" href="{{ route('staff-notifications.index') }}"><i class="bi bi-bell me-1"></i> Avisos para el equipo</a>
        </li>
        <li class="nav-item">
            <a class="btn {{ $tab === 'clientes' ? 'btn-gold' : 'btn-outline-dark' }}" href="{{ route('staff-notifications.index', ['tab' => 'clientes']) }}"><i class="bi bi-send me-1"></i> Avisos enviados a clientes</a>
        </li>
    </ul>

    @if($tab === 'equipo')
        <div class="card">
            <div class="list-group list-group-flush">
                @forelse($staff as $item)
                    <a class="list-group-item list-group-item-action d-flex gap-3 align-items-start" href="{{ $item->url ?: '#' }}">
                        <i class="bi {{ $item->icon() }} fs-4 text-warning"></i>
                        <span class="flex-grow-1">
                            <span class="fw-semibold d-block">{{ $item->title }}</span>
                            @if($item->body)<span class="small text-muted d-block">{{ $item->body }}</span>@endif
                        </span>
                        <span class="small text-muted text-nowrap">{{ $item->created_at->locale('es')->diffForHumans() }}</span>
                    </a>
                @empty
                    <div class="empty-state">
                        <i class="bi bi-bell"></i>
                        Todavía no hay avisos. Aquí verás los turnos y citas nuevos, las cancelaciones de clientes y el avance automático de la fila.
                    </div>
                @endforelse
            </div>
        </div>
        <div class="mt-3">{{ $staff->links('pagination::bootstrap-5') }}</div>
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
