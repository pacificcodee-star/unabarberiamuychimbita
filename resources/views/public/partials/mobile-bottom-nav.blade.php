{{-- Navegación inferior flotante: solo móvil, reutiliza las rutas públicas del encabezado.
     La pestaña de la página abierta se decide aquí (servidor) y queda marcada con data-mnav-current y
     aria-current; el óvalo dorado toma su posición de --mnav-index desde el primer cuadro. --}}
@php
    $mnavItems = $navItems ?? [];
    array_splice($mnavItems, 2, 0, [['route' => 'public.booking', 'label' => 'Reservar', 'icon' => 'bi-calendar2-check']]);
    $mnavActive = -1;
    foreach ($mnavItems as $mnavIndex => $mnavItem) {
        if (request()->routeIs($mnavItem['route'])) {
            $mnavActive = $mnavIndex;
            break;
        }
    }
    $mnavScript = public_path('js/mobile-bottom-nav.js');
@endphp

<nav class="mnav" id="mobileBottomNav" aria-label="Navegación móvil">
    <ul style="--mnav-count: {{ count($mnavItems) }}; --mnav-index: {{ max($mnavActive, 0) }}" class="mnav__track{{ $mnavActive < 0 ? ' is-idle' : '' }}">
        <li class="mnav__indicator" aria-hidden="true"></li>
        @foreach($mnavItems as $mnavIndex => $mnavItem)
            @php
                $mnavIsActive = $mnavIndex === $mnavActive;
                $mnavIsCta = $mnavItem['route'] === 'public.booking';
            @endphp
            <li class="mnav__cell">
                <a href="{{ route($mnavItem['route']) }}"
                   class="mnav__item{{ $mnavIsActive ? ' is-active' : '' }}{{ $mnavIsCta ? ' mnav__item--cta' : '' }}"
                   @if($mnavIsActive) aria-current="page" data-mnav-current @endif>
                    <span class="mnav__icon" aria-hidden="true"><i class="bi {{ $mnavItem['icon'] }}"></i></span>
                    <span class="mnav__label">{{ $mnavItem['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
    {{-- En línea y dentro de la barra: corre antes del primer cuadro (la página espera a #mobileBottomNav,
         ver <link rel="expect"> del layout) y atiende los toques aunque el resto de la página siga cargando. --}}
    @if(is_file($mnavScript))
        <script>{!! file_get_contents($mnavScript) !!}</script>
    @endif
</nav>
