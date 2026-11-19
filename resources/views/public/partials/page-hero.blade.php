<header class="page-hero">
    {{-- Palabra gigante de fondo: refuerza el nombre de la sección (decorativa) --}}
    <span class="page-hero-ghost" aria-hidden="true">{{ $crumb }}</span>
    <div class="container">
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
</header>
