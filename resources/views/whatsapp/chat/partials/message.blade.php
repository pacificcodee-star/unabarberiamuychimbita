@php($incoming = $message->direction === \App\Models\WhatsAppMessage::DIRECTION_IN)
<div class="d-flex flex-column">
    <div class="wa-bubble {{ $incoming ? 'wa-in' : 'wa-out' }}">
        @if($message->type === 'text' && filled($message->body))
            {!! nl2br(e($message->body)) !!}
        @else
            <em class="text-muted">[{{ $message->type === 'text' ? 'sin texto' : $message->type }}]</em>
        @endif
        <div class="wa-meta">
            {{ $message->created_at?->format('d/m/Y H:i') }}
            @if($incoming)
                @if($message->sender_name) · {{ $message->sender_name }} @endif
            @elseif($message->status)
                · {{ $message->statusLabel() }}
            @endif
        </div>
    </div>
</div>
