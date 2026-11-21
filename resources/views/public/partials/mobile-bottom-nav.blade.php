{{-- Navegación inferior flotante: solo móvil, reutiliza las rutas públicas del encabezado. --}}
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
@endphp

<nav class="mnav" id="mobileBottomNav" aria-label="Navegación móvil">
    <ul style="--mnav-count: {{ count($mnavItems) }}" class="mnav__track{{ $mnavActive < 0 ? ' is-idle' : '' }}">
        <li class="mnav__indicator" aria-hidden="true"></li>
        @foreach($mnavItems as $mnavIndex => $mnavItem)
            @php
                $mnavIsActive = $mnavIndex === $mnavActive;
                $mnavIsCta = $mnavItem['route'] === 'public.booking';
            @endphp
            <li class="mnav__cell">
                <a href="{{ route($mnavItem['route']) }}"
                   class="mnav__item{{ $mnavIsActive ? ' is-active' : '' }}{{ $mnavIsCta ? ' mnav__item--cta' : '' }}"
                   @if($mnavIsActive) aria-current="page" @endif>
                    <span class="mnav__icon" aria-hidden="true"><i class="bi {{ $mnavItem['icon'] }}"></i></span>
                    <span class="mnav__label">{{ $mnavItem['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>

<script src="{{ asset('js/mobile-bottom-nav.js') }}?v={{ file_exists(public_path('js/mobile-bottom-nav.js')) ? filemtime(public_path('js/mobile-bottom-nav.js')) : '1' }}" defer></script>
