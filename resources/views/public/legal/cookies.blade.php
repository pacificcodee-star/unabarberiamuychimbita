@extends('layouts.public')

@section('title', 'Aviso de cookies · '.($siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita'))
@section('meta_description', 'Qué cookies usa el sitio de '.($siteSettings['brand_name'] ?? 'la barbería').' y cómo cambiar tu elección.')

@section('content')

    <article class="legal-doc" aria-labelledby="legal-title">
        <div class="container">
            <nav aria-label="Ruta de navegación">
                <ol class="breadcrumb-public">
                    <li><a href="{{ route('landing') }}">Inicio</a></li>
                    <li><span aria-current="page">Cookies</span></li>
                </ol>
            </nav>

            <header class="legal-head">
                <div class="section-kicker">Información legal</div>
                <h1 id="legal-title">Aviso de cookies</h1>
                <p class="legal-updated">Última actualización: {{ $legalUpdated }}</p>
            </header>

            <div class="legal-body">
                <p class="legal-lead">Las cookies son pequeños archivos que el sitio guarda en tu navegador. Usamos las mínimas necesarias. Las de analítica solo se activan si las aceptas.</p>

                <h2>1. Cookies necesarias (siempre activas)</h2>
                <p>Sin ellas el sitio no funciona. No sirven para identificarte con fines publicitarios.</p>
                <div class="legal-table-wrap">
                    <table class="legal-table">
                        <thead><tr><th scope="col">Nombre</th><th scope="col">Para qué sirve</th><th scope="col">Duración</th></tr></thead>
                        <tbody>
                            <tr><td><code>{{ config('session.cookie') }}</code></td><td>Mantiene tu sesión mientras llenas una reserva.</td><td>{{ config('session.lifetime') }} minutos</td></tr>
                            <tr><td><code>XSRF-TOKEN</code></td><td>Protege los formularios contra envíos falsos.</td><td>{{ config('session.lifetime') }} minutos</td></tr>
                            <tr><td><code>mis_servicios</code></td><td>Reconoce este navegador para mostrarte tus citas y turnos sin crear una cuenta. Es una credencial privada, no una cookie publicitaria.</td><td>1 año</td></tr>
                        </tbody>
                    </table>
                </div>
                <p>También usamos el almacenamiento local del navegador (no son cookies) para recordar tu elección de cookies, si cerraste el aviso para instalar la app y tus reservas activas para la campana de avisos.</p>

                <h2>2. Cookies de analítica (opcionales)</h2>
                @if(config('web.ga4_id'))
                    <p>Si las aceptas, usamos Google Analytics 4 para contar visitas y saber qué páginas se usan más. La información se ve de forma agregada y la dirección IP no se guarda completa.</p>
                    <div class="legal-table-wrap">
                        <table class="legal-table">
                            <thead><tr><th scope="col">Nombre</th><th scope="col">Proveedor</th><th scope="col">Para qué sirve</th><th scope="col">Duración</th></tr></thead>
                            <tbody>
                                <tr><td><code>_ga</code></td><td>Google</td><td>Distingue visitantes anónimos.</td><td>2 años</td></tr>
                                <tr><td><code>_ga_*</code></td><td>Google</td><td>Guarda el estado de la visita.</td><td>2 años</td></tr>
                            </tbody>
                        </table>
                    </div>
                @else
                    <p>Por ahora no usamos cookies de analítica. Si las activamos, te pediremos permiso antes.</p>
                @endif

                <h2>3. Servicios de terceros</h2>
                <p>El mapa de Google en la página de contacto y los videos o tipografías que cargamos de otros servidores pueden guardar sus propias cookies, según las políticas de esas empresas.</p>

                <h2>4. Cómo cambiar tu elección</h2>
                <p>Puedes borrar las cookies desde la configuración de tu navegador.</p>

                <h2>5. Más información</h2>
                <p>Lee nuestra <a href="{{ route('legal.privacy') }}">política de privacidad y tratamiento de datos</a>.</p>
            </div>

            <div class="legal-foot">
                <a href="{{ route('landing') }}" class="btn btn-ghost-light"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al inicio</a>
            </div>
        </div>
    </article>
@endsection
