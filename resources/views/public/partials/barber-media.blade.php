{{--
    Avatar del barbero: video con transparencia si existe, si no su foto.
    Si el video es WebM y existe su versión ".alpha.mp4" (color + máscara), se usa esa con WebGL,
    que funciona en todos los navegadores sin mostrar el fondo de cuadros.
--}}
@php
    $mediaVideo = $barber->video_path ?? null;
    $mediaPoster = $barber->photo_path ? asset('storage/'.$barber->photo_path) : null;
    $alphaPath = $mediaVideo ? preg_replace('/\.(webm|mp4|mov)$/i', '.alpha.mp4', $mediaVideo) : null;
    $hasAlpha = $alphaPath && $alphaPath !== $mediaVideo && \Illuminate\Support\Facades\Storage::disk('public')->exists($alphaPath);
    $mediaPreload = $mediaPreload ?? 'metadata';
@endphp
@if($hasAlpha)
    <div class="alpha-video" data-alpha-video data-barber-video data-src="{{ asset('storage/'.$alphaPath) }}" @if(str_ends_with(strtolower($mediaVideo), '.webm')) data-webm="{{ asset('storage/'.$mediaVideo) }}" @endif data-preload="{{ $mediaPreload }}" @if(!empty($mediaAutoplay)) data-autoplay="1" @endif role="img" aria-label="Video de presentación de {{ $barber->name }}">
        @if($mediaPoster)<img class="alpha-video-poster" src="{{ $mediaPoster }}" alt="" width="440" height="960" loading="{{ $mediaPreload === 'auto' ? 'eager' : 'lazy' }}" decoding="async">@endif
    </div>
@elseif($mediaVideo)
    <video data-barber-video muted loop playsinline preload="{{ $mediaPreload === 'auto' ? 'auto' : 'none' }}" @if($mediaPoster) poster="{{ $mediaPoster }}" @endif aria-label="Video de presentación de {{ $barber->name }}" @if(!empty($mediaAutoplay)) autoplay @endif>
        <source src="{{ asset('storage/'.$mediaVideo) }}" type="{{ str_ends_with(strtolower($mediaVideo), '.webm') ? 'video/webm' : 'video/mp4' }}">
    </video>
@elseif($mediaPoster)
    <img src="{{ $mediaPoster }}" alt="Retrato de {{ $barber->name }}" width="700" height="900" loading="{{ $mediaPreload === 'auto' ? 'eager' : 'lazy' }}" decoding="async">
@else
    <span aria-hidden="true">{{ mb_strtoupper(mb_substr($barber->name, 0, 1)) }}</span>
@endif
