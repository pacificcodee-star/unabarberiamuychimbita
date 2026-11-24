{{--
    Menú lateral del panel.
    Estilos: public/css/admin-ui.css (§14) · Comportamiento: public/vendor/sidebar/js/sidebar.js
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
            <span class="header-logo-mark"><img src="{{ asset('images/logo-una-barberia-128.webp') }}" alt="Una Barbería Muy Chimbita" width="42" height="42"></span>
            <span class="header-logo-text">
                <strong>Una Barbería<br>Muy Chimbita</strong>
                <small>Control total</small>
            </span>
        </a>
        <button type="button" class="sidebar-button-toggle" data-sidebar-collapse aria-controls="sidebar" aria-expanded="true" aria-label="Contraer menú" title="Contraer menú">
            <i class="bi bi-chevron-left" aria-hidden="true"></i>
        </button>
    </header>

    <div class="sidebar-content">
        <form class="search-form" role="search" action="{{ url()->current() }}" title="Buscar en el menú">
            <i class="bi bi-search search-icon" aria-hidden="true"></i>
            <input class="search" type="search" placeholder="Buscar..." aria-label="Buscar en el menú" autocomplete="off">
        </form>
        <p class="sidebar-search-empty" data-sidebar-empty hidden>Sin resultados</p>

        <ul class="menu-sections">
            <x-sidebar.section title="Inicio" tone="brand">
                <x-sidebar.item route="dashboard" icon="bi bi-grid-1x2" label="Panel" />
            </x-sidebar.section>

            @if($showAppointments || $showTurns)
                <x-sidebar.section title="Agenda" tone="blue">
                    @if($showAppointments)
                        <x-sidebar.item route="appointments.index" active="appointments.*" icon="bi bi-calendar2-check" label="Citas" />
                    @endif
                    @if($showTurns)
                        <x-sidebar.item route="turns.index" active="turns.*" icon="bi bi-list-ol" label="Turnos" />
                    @endif
                </x-sidebar.section>
            @endif

            <x-sidebar.section title="Ventas" tone="emerald">
                <x-sidebar.item route="sales.index" active="sales.*" icon="bi bi-receipt" label="Ventas y servicios" />
                @if($isAdmin)
                    <x-sidebar.item route="pos.index" active="pos.*" icon="bi bi-shop" label="Venta POS" />
                    <x-sidebar.item route="invoices.index" active="invoices.*" icon="bi bi-file-earmark-text" label="Comprobantes" />
                @endif
                <x-sidebar.item route="cash.index" active="cash.*" icon="bi bi-safe2" label="Caja" />
            </x-sidebar.section>

            @if($isAdmin)
                <x-sidebar.section title="Catálogo" tone="amber">
                    <x-sidebar.item route="services.index" active="services.*" icon="bi bi-scissors" label="Servicios" />
                    <x-sidebar.item route="supplies.index" active="supplies.*" icon="bi bi-box-seam" label="Insumos" />
                </x-sidebar.section>

                <x-sidebar.section title="Personal" tone="violet">
                    <x-sidebar.item route="barbers.index" active="barbers.*" icon="bi bi-people" label="Barberos" />
                    <x-sidebar.item route="availability.index" active="availability.*" icon="bi bi-calendar2-x" label="Disponibilidad" />
                    <x-sidebar.item route="commissions.index" active="commissions.*" icon="bi bi-wallet2" label="Nómina" />
                </x-sidebar.section>

                <x-sidebar.section title="Clientes" tone="rose">
                    <x-sidebar.item route="surveys.index" active="surveys.*" icon="bi bi-star-half" label="Satisfacción y opiniones" />
                </x-sidebar.section>

                <x-sidebar.section title="Finanzas y reportes" tone="teal">
                    <x-sidebar.item route="expenses.index" active="expenses.*" icon="bi bi-cash-stack" label="Gastos" />
                    <x-sidebar.item route="reports.index" active="reports.*" icon="bi bi-bar-chart-line" label="Informes" />
                </x-sidebar.section>

                <x-sidebar.section title="Configuración" tone="slate">
                    @php($waUnread = \App\Models\WhatsAppConversation::unreadTotal())
                    <x-sidebar.item route="whatsapp.index" active="whatsapp.*" icon="bi bi-whatsapp" label="WhatsApp" :badge="$waUnread ?: null" />
                    <x-sidebar.item route="site-settings.edit" active="site-settings.*" icon="bi bi-gear" label="Configuración del negocio" />
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

        <div class="sidebar-tools">
            <button type="button" class="sidebar-icon-btn is-install" data-pwa-install hidden title="Instalar el panel como app" aria-label="Instalar el panel como app">
                <i class="bi bi-download" aria-hidden="true"></i>
            </button>
            <button type="button" class="sidebar-icon-btn button-change-theme" aria-pressed="false" title="Cambiar a modo oscuro" aria-label="Cambiar a modo oscuro">
                <span class="icon-theme"><i class="bi bi-moon-stars" aria-hidden="true"></i></span>
            </button>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="sidebar-icon-btn is-danger" title="Cerrar sesión" aria-label="Cerrar sesión">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                </button>
            </form>
        </div>
    </footer>
</aside>
