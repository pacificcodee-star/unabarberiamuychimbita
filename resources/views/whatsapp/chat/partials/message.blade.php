{{-- Un globo del chat dentro del iPhone. Separador de día cuando cambia la fecha ($previousDay). --}}
@php
    $incoming = $message->direction === \App\Models\WhatsAppMessage::DIRECTION_IN;
    $day = $message->created_at?->format('d/m/Y');
    $ticks = ['read' => 'is-read', 'delivered' => 'is-delivered', 'sent' => 'is-sent', 'failed' => 'is-failed'][$message->status] ?? 'is-sent';
@endphp
@if(($previousDay ?? null) !== $day && $day)
    <div class="ios-day" data-day="{{ $day }}"><span>{{ $message->created_at->isToday() ? 'Hoy' : ($message->created_at->isYesterday() ? 'Ayer' : $message->created_at->locale('es')->translatedFormat('j \d\e F')) }}</span></div>
@endif
<div class="ios-row {{ $incoming ? 'is-in' : 'is-out' }}">
    <div class="ios-bubble">
        @if($message->type === 'text' && filled($message->body))
            <span class="ios-text">{{ $message->body }}</span>
        @else
            <em class="ios-text ios-media">[{{ $message->type === 'text' ? 'sin texto' : $message->type }}]</em>
        @endif
        <span class="ios-meta">
            {{ $message->created_at?->format('H:i') }}
            @unless($incoming)
                <span class="ios-ticks {{ $ticks }}" title="{{ $message->statusLabel() }}" aria-label="{{ $message->statusLabel() }}">
                    @if($message->status === 'failed')<i class="bi bi-exclamation-circle-fill"></i>@elseif(in_array($message->status, ['read', 'delivered'], true))<i class="bi bi-check2-all"></i>@else<i class="bi bi-check2"></i>@endif
                </span>
            @endunless
        </span>
    </div>
</div>
