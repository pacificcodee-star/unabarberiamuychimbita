{{--
    Opción del menú lateral.
    Uso: <x-sidebar.item route="appointments.index" active="appointments.*" icon="bi bi-calendar2-check" label="Citas" />
    - route:  nombre de la ruta Laravel (se genera con route()).
    - active: patrón(es) de rutas que la marcan como activa (por defecto, la misma ruta).
    - icon:   clases de Bootstrap Icons (p. ej. "bi bi-calendar2-check").
    - badge:  número opcional (p. ej. mensajes sin leer).
--}}
@props([
    'route',
    'label',
    'icon' => 'bi bi-circle',
    'badge' => null,
    'active' => null,
    'params' => [],
])

@php
    $patterns = (array) ($active ?? $route);
    $href = route($route, $params);
    $isActive = request()->routeIs(...$patterns);

    // Respaldo: mantiene la selección activa aunque el nombre de ruta no coincida
    // (p. ej. submódulos o variaciones), comparando la URL del enlace.
    if (! $isActive) {
        $current = rtrim(request()->url(), '/');
        $link = rtrim(url($href), '/');
        $isActive = $current === $link || str_starts_with($current.'/', $link.'/');
    }
@endphp

<li class="menu-item">
    <a href="{{ $href }}"
       {{ $attributes->class(['menu-link', 'active' => $isActive]) }}
       title="{{ $label }}{{ $badge ? ' ('.$badge.' sin leer)' : '' }}"
       data-label="{{ $label }}"
       @if($isActive) aria-current="page" @endif>
        <i class="{{ $icon }}" aria-hidden="true"></i>
        <span class="menu-label">{{ $label }}</span>
        @if($badge)
            <span class="menu-badge" aria-label="{{ $badge }} sin leer">{{ $badge > 99 ? '99+' : $badge }}</span>
        @endif
    </a>
</li>
