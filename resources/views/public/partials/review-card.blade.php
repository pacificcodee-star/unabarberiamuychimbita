{{-- Tarjeta de opinión de un cliente (solo se muestran las aprobadas en el panel). --}}
@php
    $initial = mb_strtoupper(mb_substr(trim($review->client_name), 0, 1));
    $hue = (crc32($review->client_name) % 360);
@endphp
<figure class="rv-card {{ $revealClass ?? '' }}">
    <div class="rv-top">
        <div class="rv-stars" role="img" aria-label="{{ $review->rating }} de 5 estrellas">
            @for($i = 1; $i <= 5; $i++)
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" class="{{ $i <= $review->rating ? 'is-on' : '' }}"><path d="M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.4l-5.8 3.1 1.1-6.5L2.6 9.4l6.5-.9z"/></svg>
            @endfor
        </div>
        <svg class="rv-mark" viewBox="0 0 48 48" width="40" height="40" aria-hidden="true"><path d="M20 10C12 13 7 19 7 27v11h13V25h-7c0-5 3-9 9-11zm21 0c-8 3-13 9-13 17v11h13V25h-7c0-5 3-9 9-11z"/></svg>
    </div>
    <blockquote class="rv-text"><p>{{ $review->comment }}</p></blockquote>
    <figcaption class="rv-author">
        <span class="rv-avatar" aria-hidden="true" style="--h: {{ $hue }}">{{ $initial }}</span>
        <span class="rv-who">
            <strong>{{ $review->client_name }}</strong>
            <small>Cliente verificado · {{ $review->created_at?->locale('es')->translatedFormat('F Y') }}</small>
        </span>
    </figcaption>
</figure>
