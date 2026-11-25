{{-- Grupo de opciones del menú lateral.
     Uso: <x-sidebar.section title="Agenda" tone="blue"> ... </x-sidebar.section>
     tone: color del grupo (brand, blue, emerald, amber, violet, rose, teal, slate). --}}
@props(['title' => null, 'tone' => 'brand'])

<li {{ $attributes->class('menu-section') }} data-tone="{{ $tone }}">
    @if($title)
        <span class="menu-section-title">{{ $title }}</span>
    @endif
    <ul class="menu-list">
        {{ $slot }}
    </ul>
</li>
