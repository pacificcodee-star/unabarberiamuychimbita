{{--
    Menú lateral del panel — diseño "Sidebar Menu Template" de FathyHesham.
    Estilos: public/vendor/sidebar/css/sidebar.css · Comportamiento: public/vendor/sidebar/js/sidebar.js
    Uso en el layout: <x-sidebar />
--}}
@php
    $user = auth()->user();
    $isAdmin = $user->isAdmin();
    $showAppointments = $isAdmin || $user->attention_mode === 'citas';
    $showTurns = $isAdmin || $user->attention_mode === 'llegada';
@endphp

<div class="sidebar-overlay" data-sidebar-overlay aria-hidden="true"></div>

<aside class="sidebar" id="sidebar" aria-label="Menú principal">
    {{-- Aplica el estado contraído guardado antes de pintar (sin saltos visuales). --}}
    <script>try{if(window.matchMedia('(min-width: 992px)').matches&&localStorage.getItem('sidebar:collapsed')==='1'){document.getElementById('sidebar').classList.add('close')}}catch(e){}</script>

    <header class="sidebar-header">
        <a href="{{ route('dashboard') }}" class="header-logo" title="Ir al panel">
            <span class="header-logo-mark"><img src="{{ asset('images/logo-una-barberia.png') }}" alt="Una Barbería Muy Chimbita"></span>
            <span class="header-logo-text">
                <strong>Una Barbería<br>Muy Chimbita</strong>
                <small>Control total</small>
            </span>
        </a>
        <button type="button" class="sidebar-button-toggle" data-sidebar-collapse aria-controls="sidebar" aria-expanded="true" aria-label="Contraer menú" title="Contraer menú">
            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
        </button>
    </header>

    <div class="sidebar-content">
        <form class="search-form" role="search" action="{{ url()->current() }}" title="Buscar en el menú">
            <i class="fa-solid fa-magnifying-glass search-icon" aria-hidden="true"></i>
            <input class="search" type="search" placeholder="Buscar..." aria-label="Buscar en el menú" autocomplete="off">
        </form>
        <p class="sidebar-search-empty" data-sidebar-empty hidden>Sin resultados</p>

        <ul class="menu-sections">
            <x-sidebar.section title="Inicio">
                <x-sidebar.item route="dashboard" icon="fa-solid fa-grip" label="Panel" />
            </x-sidebar.section>

            @if($showAppointments || $showTurns)
                <x-sidebar.section title="Agenda">
                    @if($showAppointments)
                        <x-sidebar.item route="appointments.index" active="appointments.*" icon="fa-solid fa-calendar-check" label="Citas" />
                    @endif
                    @if($showTurns)
                        <x-sidebar.item route="turns.index" active="turns.*" icon="fa-solid fa-list-ol" label="Turnos" />
                    @endif
                </x-sidebar.section>
            @endif

            <x-sidebar.section title="Ventas">
                <x-sidebar.item route="sales.index" active="sales.*" icon="fa-solid fa-receipt" label="Ventas y servicios" />
                @if($isAdmin)
                    <x-sidebar.item route="pos.index" active="pos.*" icon="fa-solid fa-cash-register" label="Venta POS" />
                    <x-sidebar.item route="invoices.index" active="invoices.*" icon="fa-solid fa-file-invoice" label="Comprobantes" />
                @endif
                <x-sidebar.item route="cash.index" active="cash.*" icon="fa-solid fa-sack-dollar" label="Caja" />
            </x-sidebar.section>

            @if($isAdmin)
                <x-sidebar.section title="Catálogo">
                    <x-sidebar.item route="services.index" active="services.*" icon="fa-solid fa-scissors" label="Servicios" />
                    <x-sidebar.item route="supplies.index" active="supplies.*" icon="fa-solid fa-box-open" label="Insumos" />
                </x-sidebar.section>

                <x-sidebar.section title="Personal">
                    <x-sidebar.item route="barbers.index" active="barbers.*" icon="fa-solid fa-user-group" label="Barberos" />
                    <x-sidebar.item route="availability.index" active="availability.*" icon="fa-solid fa-calendar-xmark" label="Disponibilidad" />
                    <x-sidebar.item route="commissions.index" active="commissions.*" icon="fa-solid fa-wallet" label="Nómina" />
                </x-sidebar.section>

                <x-sidebar.section title="Finanzas y reportes">
                    <x-sidebar.item route="expenses.index" active="expenses.*" icon="fa-solid fa-money-bill-wave" label="Gastos" />
                    <x-sidebar.item route="reports.index" active="reports.*" icon="fa-solid fa-square-poll-vertical" label="Informes" />
                </x-sidebar.section>

                <x-sidebar.section title="Configuración">
                    @php($waUnread = \App\Models\WhatsAppConversation::unreadTotal())
                    <x-sidebar.item route="whatsapp.index" active="whatsapp.*" icon="fa-brands fa-whatsapp" :label="$waUnread ? 'WhatsApp ('.$waUnread.' sin leer)' : 'WhatsApp'" />
                    <x-sidebar.item route="site-settings.edit" active="site-settings.*" icon="fa-solid fa-gear" label="Configuración del negocio" />
                </x-sidebar.section>
            @endif
        </ul>
    </div>

    <footer class="sidebar-footer">
        <div class="sidebar-user" title="{{ $user->name }}">
            <span class="sidebar-user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
            <span class="sidebar-user-info">
                <strong>{{ $user->name }}</strong>
                <small>{{ $isAdmin ? 'Administrador' : 'Barbero' }}</small>
            </span>
        </div>

        <a href="{{ route('landing') }}" class="sidebar-footer-link" title="Ver página principal">
            <i class="fa-solid fa-globe" aria-hidden="true"></i>
            <span class="menu-label">Ver página principal</span>
        </a>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="sidebar-footer-link" title="Cerrar sesión">
                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                <span class="menu-label">Cerrar sesión</span>
            </button>
        </form>

        <button type="button" class="button-change-theme" aria-pressed="false" title="Cambiar a modo oscuro">
            <span class="icon-theme">
                <i class="fa-solid fa-moon" aria-hidden="true"></i>
                <span class="theme-text">Modo oscuro</span>
            </span>
            <span class="change-theme-track" aria-hidden="true"><span class="change-theme-indicator"></span></span>
        </button>
    </footer>
</aside>
