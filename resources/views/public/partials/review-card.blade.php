@php
    // Nombre autorizado por el cliente ("Andrés G." o anónimo); nunca el nombre completo.
    $publicName = $review->publicName();
    $initial = mb_strtoupper(mb_substr($publicName, 0, 1));
    $hue = (crc32($publicName) % 360);
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
            <strong>{{ $publicName }}</strong>
            <small>
                @if($review->isVerified())<span class="rv-verified" title="Respondió la encuesta después de atenderse"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Servicio verificado</span> · @endif
                @if($review->service)<span class="rv-service">{{ $review->service->name }}</span> · @endif
                {{ ($review->published_at ?? $review->created_at)?->locale('es')->translatedFormat('F Y') }}
            </small>
        </span>
    </figcaption>
</figure>
