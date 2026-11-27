@extends('layouts.app')

@section('title', 'Chat de WhatsApp')

@section('actions')
    <a href="{{ route('whatsapp.index') }}" class="btn btn-outline-dark"><i class="bi bi-qr-code me-1"></i> Conexión y QR</a>
@endsection

@section('content')
    <div class="alert alert-light border small mb-4">
        <i class="bi bi-info-circle text-warning me-1"></i>
        Conversaciones de WhatsApp con los clientes. Aquí <strong>responde el equipo a mano</strong>;
        el bot automático contesta solo mientras esté activo (se pausa chat por chat desde cada conversación).
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-chat-left-dots me-1 text-warning"></i> Conversaciones
                @if($unreadTotal) <span class="badge rounded-pill text-bg-danger">{{ $unreadTotal }} sin leer</span> @endif
            </span>
            <form method="GET" class="d-flex gap-2">
                <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Buscar nombre, @usuario o número…" aria-label="Buscar conversación">
                <button class="btn btn-sm btn-outline-dark">Buscar</button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Último mensaje</th>
                    <th class="text-end">Estado</th>
                </tr>
                </thead>
                <tbody>
                @forelse($conversations as $conversation)
                    <tr>
                        <td>
                            <a href="{{ route('whatsapp.chat.show', $conversation) }}" class="text-decoration-none text-reset d-flex align-items-center gap-2">
                                <span class="sidebar-user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($conversation->contactName(), 0, 1)) }}</span>
                                <span>
                                    <span class="fw-semibold d-block">{{ $conversation->contactName() }}</span>
                                    <span class="small text-muted">{{ $conversation->contactDetail() }}</span>
                                </span>
                            </a>
                        </td>
                        <td>
                            <div class="text-truncate" style="max-width: 42ch;">
                                @if($conversation->lastMessage?->direction === 'out')
                                    <i class="bi bi-arrow-up-right text-success"></i>
                                @endif
                                {{ $conversation->last_message_preview ?: '—' }}
                            </div>
                            <div class="small text-muted">{{ $conversation->last_message_at?->format('d/m/Y H:i') }}</div>
                        </td>
                        <td class="text-end text-nowrap">
                            @if($conversation->unread_count)
                                <span class="badge rounded-pill text-bg-success">{{ $conversation->unread_count }} nuevas</span>
                            @endif
                            @if(! $conversation->bot_active)
                                <span class="badge-soft red" title="Bot pausado en este chat">bot pausado</span>
                            @endif
                            <a href="{{ route('whatsapp.chat.show', $conversation) }}" class="btn btn-sm btn-gold">Abrir</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">
                            <div class="empty-state">
                                <i class="bi bi-chat-square-dots"></i>
                                @if($search)
                                    Sin resultados para «{{ $search }}».
                                @else
                                    Aún no llegan mensajes. Conecta el QR y activa el webhook desde
                                    <a href="{{ route('whatsapp.index') }}">Conexión de WhatsApp</a>.
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($conversations->hasPages())
            <div class="card-footer">{{ $conversations->links() }}</div>
        @endif
    </div>
@endsection
