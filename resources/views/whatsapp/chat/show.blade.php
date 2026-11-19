@extends('layouts.app')

@section('title', 'Chat · '.($conversation->client_name ?: $conversation->phone))

@section('actions')
    <a href="{{ route('whatsapp.chat.index') }}" class="btn btn-outline-dark"><i class="bi bi-arrow-left me-1"></i> Bandeja</a>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>{{ session('error') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="d-flex align-items-center gap-2">
                <span class="sidebar-user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($conversation->client_name ?: $conversation->phone, 0, 1)) }}</span>
                <span>
                    <strong class="d-block">{{ $conversation->client_name ?: 'Cliente sin nombre' }}</strong>
                    <span class="small text-muted">{{ $conversation->phone }} ·
                        <a href="https://wa.me/{{ $conversation->phone }}" target="_blank" rel="noopener">abrir en WhatsApp</a>
                    </span>
                </span>
            </span>
            <span class="d-flex flex-wrap gap-2 align-items-center">
                <span class="badge-soft {{ $conversation->bot_active ? 'green' : 'red' }}">
                    {{ $conversation->bot_active ? 'Bot activo' : 'Bot pausado' }}
                </span>
                <form method="POST" action="{{ route('whatsapp.chat.bot', $conversation) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-dark">
                        <i class="bi bi-robot me-1"></i>{{ $conversation->bot_active ? 'Pausar bot' : 'Reanudar bot' }}
                    </button>
                </form>
            </span>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body" id="wa-thread" style="max-height: 55vh; min-height: 320px; overflow-y: auto; background: var(--cream, #f4efe6);">
            <div class="d-flex flex-column gap-2" id="wa-messages" data-last="{{ $messages->last()?->id ?? 0 }}">
                @forelse($messages as $message)
                    @include('whatsapp.chat.partials.message', ['message' => $message])
                @empty
                    <div class="empty-state"><i class="bi bi-chat-square-dots"></i> Todavía no hay mensajes en esta conversación.</div>
                @endforelse
            </div>
        </div>

        <div class="card-footer">
            <form method="POST" action="{{ route('whatsapp.chat.send', $conversation) }}" class="d-flex gap-2">
                @csrf
                <label for="wa-body" class="visually-hidden">Mensaje</label>
                <input type="text" id="wa-body" name="body" class="form-control" maxlength="2000" autocomplete="off"
                       placeholder="Escribe la respuesta del equipo…" required>
                <button type="submit" class="btn btn-gold text-nowrap"><i class="bi bi-send me-1"></i> Enviar</button>
            </form>
            <div class="form-text">
                Si el equipo responde, conviene pausar el bot para este chat y evitar dobles respuestas.
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .wa-bubble { max-width: min(78%, 46rem); padding: .55rem .8rem; border-radius: 1rem; white-space: pre-wrap; word-break: break-word; }
        .wa-in    { align-self: flex-start; background: #fff; border: 1px solid rgba(0,0,0,.08); border-bottom-left-radius: .25rem; }
        .wa-out   { align-self: flex-end; background: #d9fdd3; border-bottom-right-radius: .25rem; }
        .wa-meta  { font-size: .7rem; opacity: .65; margin-top: .25rem; }
        .wa-empty { color: inherit; }
    </style>
@endpush

@push('scripts')
    <script>
        (function () {
            const thread = document.getElementById('wa-thread');
            const box = document.getElementById('wa-messages');
            const url = @json(route('whatsapp.chat.messages', $conversation));
            let last = parseInt(box.dataset.last || '0', 10);
            let busy = false;

            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

            function toBottom() { thread.scrollTop = thread.scrollHeight; }
            toBottom();

            async function refresh() {
                if (busy) return;
                busy = true;
                try {
                    const res = await fetch(url + '?after=' + last, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (!data.messages.length) return;
                    const empty = box.querySelector('.empty-state');
                    if (empty) empty.remove();
                    data.messages.forEach((m) => {
                        last = Math.max(last, m.id);
                        const wrap = document.createElement('div');
                        wrap.className = 'd-flex flex-column';
                        const body = m.type === 'text' ? esc(m.body) : '<em class="text-muted">[' + esc(m.type) + ']</em>';
                        const meta = (m.direction === 'out')
                            ? (m.status ? ' · ' + esc(m.status) : '')
                            : (m.sender_name ? ' · ' + esc(m.sender_name) : '');
                        wrap.innerHTML = '<div class="wa-bubble ' + (m.direction === 'out' ? 'wa-out' : 'wa-in') + '">' +
                            body + '<div class="wa-meta">' + esc(m.created_at) + meta + '</div></div>';
                        box.appendChild(wrap);
                    });
                    box.dataset.last = last;
                    toBottom();
                } finally { busy = false; }
            }

            setInterval(refresh, 5000);
            document.querySelector('form[action$="/send"]')?.addEventListener('submit', () => setTimeout(refresh, 400));
        })();
    </script>
@endpush
