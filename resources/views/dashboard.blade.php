@extends('layouts.app')

@section('title', 'Panel general')

@section('actions')
    <div class="page-actions no-print">
        @if($isAdmin)
            <a href="{{ route('expenses.create') }}" class="btn btn-outline-dark">
                <i class="bi bi-wallet2 me-1"></i> Gasto
            </a>
        @endif
        <a href="{{ route('sales.create') }}" class="btn btn-gold">
            <i class="bi bi-scissors me-1"></i> Tomar servicio
        </a>
    </div>
@endsection

@section('content')
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card stat-card" data-tone="emerald">
                <div class="icon"><i class="bi bi-cash-coin"></i></div>
                <div class="label">Ventas de hoy</div>
                <div class="value">{{ format_money($salesTotal) }}</div>
                <div class="hint">{{ $salesCount }} venta{{ $salesCount === 1 ? '' : 's' }} registrada{{ $salesCount === 1 ? '' : 's' }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card" data-tone="blue">
                <div class="icon"><i class="bi bi-scissors"></i></div>
                <div class="label">Servicios realizados</div>
                <div class="value">{{ $servicesCount }}</div>
                <div class="hint">Cortes y servicios de hoy</div>
            </div>
        </div>
        @if($isAdmin)
            <div class="col-6 col-xl-3">
                <div class="card stat-card" data-tone="red">
                    <div class="icon"><i class="bi bi-wallet2"></i></div>
                    <div class="label">Gastos de hoy</div>
                    <div class="value">{{ format_money($expensesToday) }}</div>
                    <div class="hint">Salidas registradas</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card stat-card" data-tone="violet">
                    <div class="icon"><i class="bi bi-graph-up-arrow"></i></div>
                    <div class="label">Ganancia aproximada</div>
                    <div class="value">{{ format_money($profitToday) }}</div>
                    <div class="hint">Ventas − gastos − nómina del día</div>
                </div>
            </div>
        @else
            <div class="col-6 col-xl-3">
                <div class="card stat-card" data-tone="violet">
                    <div class="icon"><i class="bi bi-percent"></i></div>
                    <div class="label">Comisión de hoy</div>
                    <div class="value">{{ format_money($commissionsToday) }}</div>
                    <div class="hint">Generada con tus ventas</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card stat-card" data-tone="amber">
                    <div class="icon"><i class="bi bi-hourglass-split"></i></div>
                    <div class="label">Nómina por pagar</div>
                    <div class="value">{{ format_money($pendingCommissions) }}</div>
                    <div class="hint">Total por cobrar</div>
                </div>
            </div>
        @endif
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bar-chart-line me-1 text-warning"></i> Ventas de los últimos 7 días</span>
                    <span class="badge-soft gold">{{ $isAdmin ? 'Barbería completa' : 'Mis ventas' }}</span>
                </div>
                <div class="card-body">
                    <div class="chart-box"><canvas id="salesChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-cash-stack me-1 text-warning"></i> Estado de la caja</div>
                <div class="card-body">
                    @if($activeSession)
                        <div class="data-row"><span>Estado</span><span class="badge-soft green"><i class="bi bi-unlock"></i> Abierta</span></div>
                        <div class="data-row"><span>Base inicial</span><strong>{{ format_money($activeSession->initial_amount) }}</strong></div>
                        <div class="data-row"><span>Abierta por</span><span>{{ $activeSession->openedBy?->name }}</span></div>
                        <div class="data-row mb-3"><span>Hora de apertura</span><span>{{ $activeSession->opened_at->format('h:i A') }}</span></div>
                        <a href="{{ route('cash.show', $activeSession) }}" class="btn btn-dark w-100">
                            Ver caja y cerrar día
                        </a>
                    @else
                        <div class="empty-state py-4">
                            <i class="bi bi-cash-stack"></i>
                            <p class="mb-3">No hay una caja abierta.</p>
                            <a href="{{ route('cash.index') }}" class="btn btn-gold">Abrir caja</a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-receipt me-1 text-warning"></i> Últimas ventas</span>
                    <a href="{{ route('sales.index') }}" class="small text-decoration-none">Ver todas</a>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Barbero</th>
                            <th>Servicios</th>
                            <th class="text-end">Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($recentSales as $sale)
                            <tr>
                                <td class="text-nowrap">{{ $sale->sold_at->format('h:i A') }}</td>
                                <td class="text-nowrap">{{ $sale->barber?->name }}</td>
                                <td class="text-muted small">{{ $sale->items->pluck('service_name')->join(', ') }}</td>
                                <td class="text-end fw-semibold">{{ format_money($sale->total) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state py-4">
                                        <i class="bi bi-receipt"></i>
                                        Todavía no hay ventas registradas hoy.
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100" data-booking-region="today">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-{{ $isWalkIn ? 'list-ol' : 'calendar2-week' }} me-1 text-warning"></i> {{ $isWalkIn ? 'Turnos de hoy' : 'Citas de hoy' }}</span>
                    <span class="badge-soft">{{ $isWalkIn ? $turnsToday : $appointmentsToday }}</span>
                </div>
                <div class="card-body">
                    @if($isWalkIn)
                        @if($waitingTurns > 0)
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-hourglass-split"></i> {{ $waitingTurns }} turno{{ $waitingTurns === 1 ? '' : 's' }} esperando
                            </div>
                        @endif
                        @forelse($nextTurns as $turn)
                            @if($loop->first)<ul class="agenda-list">@endif
                            <li class="agenda-item">
                                <span class="agenda-time">#{{ $turn->turn_number }}</span>
                                <div class="agenda-text">
                                    <strong>{{ $turn->client_name }}</strong>
                                    <span>{{ $turn->service?->name ?? 'Servicio por definir' }} · {{ $turn->barber?->name }}</span>
                                </div>
                                <span class="badge-soft {{ $turn->status === 'cancelado' ? 'red' : ($turn->status === 'atendido' ? 'green' : 'blue') }}">
                                    {{ $turn->statusLabel() }}
                                </span>
                            </li>
                            @if($loop->last)</ul>@endif
                        @empty
                            <div class="empty-state py-4">
                                <i class="bi bi-list-ol"></i>
                                No hay turnos pendientes para hoy.
                            </div>
                        @endforelse
                        <a href="{{ route('turns.index') }}" class="btn btn-outline-dark w-100 mt-3">Gestionar turnos</a>
                    @else
                        @forelse($nextAppointments as $appointment)
                            @if($loop->first)<ul class="agenda-list">@endif
                            <li class="agenda-item">
                                <span class="agenda-time">{{ \Carbon\Carbon::parse($appointment->appointment_time)->format('h:i A') }}</span>
                                <div class="agenda-text">
                                    <strong>{{ $appointment->client_name }}</strong>
                                    <span>{{ $appointment->service?->name ?? 'Servicio por definir' }} · {{ $appointment->barber?->name }}</span>
                                </div>
                                <span class="badge-soft {{ $appointment->status === 'cancelada' ? 'red' : ($appointment->status === 'atendida' ? 'green' : 'blue') }}">
                                    {{ $appointment->statusLabel() }}
                                </span>
                            </li>
                            @if($loop->last)</ul>@endif
                        @empty
                            <div class="empty-state py-4">
                                <i class="bi bi-calendar-x"></i>
                                No hay citas agendadas para hoy.
                            </div>
                        @endforelse
                        <a href="{{ route('appointments.index') }}" class="btn btn-outline-dark w-100 mt-3">Gestionar citas</a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($topServices->isNotEmpty())
        <div class="card">
            <div class="card-header"><i class="bi bi-tags"></i> Servicios más vendidos hoy</div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach($topServices as $service)
                        <div class="col-sm-6 col-xl-3">
                            <div class="mini-stat">
                                <span class="mini-stat-label">{{ $service->service_name }}</span>
                                <div class="mini-stat-row">
                                    <strong>{{ $service->quantity }}×</strong>
                                    <span>{{ format_money($service->total) }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        const salesCtx = document.getElementById('salesChart');
        if (salesCtx) {
            new Chart(salesCtx, {
                type: 'line',
                data: {
                    labels: @json($chartSales->pluck('label')),
                    datasets: [{
                        label: 'Ventas',
                        data: @json($chartSales->pluck('total')),
                        borderColor: panelTone(),
                        backgroundColor: panelTone(.10),
                        borderWidth: 2.5,
                        fill: true,
                        tension: .35,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: panelTone(),
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (context) => ' $' + Number(context.raw).toLocaleString('es-CO')
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(22,22,22,.05)' },
                            ticks: { callback: (value) => '$' + Number(value).toLocaleString('es-CO') }
                        },
                        x: { grid: { display: false } }
                    }
                }
            });
        }
    </script>
@endpush
