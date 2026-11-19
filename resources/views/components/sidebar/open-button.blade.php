{{-- Botón hamburguesa que abre el menú lateral en tablet y teléfono. --}}
<button type="button"
        {{ $attributes->class('sidebar-open-btn') }}
        data-sidebar-open
        aria-controls="sidebar"
        aria-expanded="false"
        aria-label="Abrir menú"
        title="Menú">
    <i class="fa-solid fa-bars" aria-hidden="true"></i>
</button>
