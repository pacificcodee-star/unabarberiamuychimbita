{{-- Grupo de opciones del menú lateral. Uso: <x-sidebar.section title="Agenda"> ... </x-sidebar.section> --}}
@props(['title' => null])

<li {{ $attributes->class('menu-section') }}>
    @if($title)
        <span class="menu-section-title">{{ $title }}</span>
    @endif
    <ul class="menu-list">
        {{ $slot }}
    </ul>
</li>
