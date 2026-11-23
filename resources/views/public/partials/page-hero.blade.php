<header class="page-hero{{ !empty($image ?? null) ? ' has-media' : '' }}">
    {{-- Palabra gigante de fondo: refuerza el nombre de la sección (decorativa). Se apaga con 'ghost' => false. --}}
    @if($ghost ?? true)
        <span class="page-hero-ghost" aria-hidden="true">{{ $crumb }}</span>
    @endif
    <div class="container">
        <div class="page-hero-grid">
            <div class="page-hero-copy">
                <nav aria-label="Ruta de navegación">
                    <ol class="breadcrumb-public">
                        <li><a href="{{ route('landing') }}">Inicio</a></li>
                        <li><span aria-current="page">{{ $crumb }}</span></li>
                    </ol>
                </nav>
                <h1>{{ $title }}</h1>
                @if(!empty($description))
                    <p>{{ $description }}</p>
                @endif
            </div>
            @if(!empty($image ?? null))
                <figure class="page-hero-media">
                    <picture>
                        @if(!empty($image_fallback ?? null))
                            <source srcset="{{ $image }}" type="image/webp">
                            <img src="{{ $image_fallback }}" alt="{{ $image_alt ?? '' }}" width="1122" height="1402" decoding="async">
                        @else
                            <img src="{{ $image }}" alt="{{ $image_alt ?? '' }}" width="{{ $image_width ?? 1122 }}" height="{{ $image_height ?? 1402 }}" decoding="async">
                        @endif
                    </picture>
                </figure>
            @endif
        </div>
    </div>
</header>
