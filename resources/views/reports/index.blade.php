@extends('layouts.app')

@section('title', 'Informes')

@section('actions')
    <button type="button" class="btn btn-gold no-print" onclick="window.print()">
        <i class="bi bi-printer me-1"></i> Imprimir informe
    </button>
@endsection

@section('content')
    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Desde</label>
                    <input type="date" name="start" value="{{ $start }}" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Hasta</label>
                    <input type="date" name="end" value="{{ $end }}" class="form-control">
                </div>
                <div class="col-auto">
                    <button class="btn btn-dark"><i class="bi bi-funnel me-1"></i> Generar informe</button>
                </div>
                <div class="col-auto d-flex flex-wrap gap-2">
                    <a href="{{ route('reports.index') }}" class="btn {{ $start === $allStart && $end === now()->toDateString() ? 'btn-gold' : 'btn-outline-dark' }}">Todo</a>
                    <a href="{{ route('reports.index', ['start' => now()->toDateString(), 'end' => now()->toDateString()]) }}" class="btn btn-outline-dark">Hoy</a>
                    <a href="{{ route('reports.index', ['start' => now()->startOfWeek()->toDateString(), 'end' => now()->endOfWeek()->toDateString()]) }}" class="btn btn-outline-dark">Semana</a>
                    <a href="{{ route('reports.index', ['start' => now()->startOfMonth()->toDateString(), 'end' => now()->toDateString()]) }}" class="btn btn-outline-dark">Mes</a>
                    <a href="{{ route('reports.index', ['start' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'end' => now()->subMonthNoOverflow()->endOfMonth()->toDateString()]) }}" class="btn btn-outline-dark">Mes pasado</a>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
            <h4 class="mb-0">Informe financiero</h4>
            <div class="text-muted small">{{ \Carbon\Carbon::parse($start)->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($end)->format('d/m/Y') }}</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <span class="badge-soft gold">{{ $servicesCount }} servicios realizados</span>
            <span class="badge-soft">{{ $appointmentsCount }} citas</span>
            <span class="badge-soft">{{ $turnsCount }} turnos</span>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3">
                <div class="label">Ingresos totales</div>
                <div class="value fs-3">{{ format_money($totalSales) }}</div>
                <div class="hint">{{ $salesCount }} cobros · promedio {{ format_money($averageTicket) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3">
                <div class="label">Gastos</div>
                <div class="value fs-3 text-danger">{{ format_money($totalExpenses) }}</div>
                <div class="hint">Salidas del periodo</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3">
                <div class="label">Nómina generada</div>
                <div class="value fs-3">{{ format_money($totalCommissions + $totalSupplies) }}</div>
                <div class="hint">Servicios {{ format_money($totalCommissions) }} · Insumos {{ format_money($totalSupplies) }} · Pagado {{ format_money($totalPayouts) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3">
                <div class="label">Ganancia neta</div>
                <div class="value fs-3 {{ $profit >= 0 ? 'text-success' : 'text-danger' }}">{{ format_money($profit) }}</div>
                <div class="hint">Ingresos totales − gastos − nómina de barberos (comisiones/insumos)</div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-bar-chart-line me-1 text-warning"></i> Ingresos por día</span>
            <span class="badge-soft gold" title="Lo que se les debe hoy a todos los barberos (igual que en Nómina)">Nómina por pagar: {{ format_money($globalPending) }}</span>
        </div>
        <div class="card-body">
            @if($dailySales->isEmpty())
                <div class="empty-state py-3"><i class="bi bi-bar-chart-line"></i> No hay ingresos en el periodo seleccionado.</div>
            @else
                <canvas id="reportChart" height="95"></canvas>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-tags me-1 text-warning"></i> Servicios realizados</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>Servicio</th>
                            <th class="text-center">Cantidad</th>
                            <th class="text-end">Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($byService as $row)
                            <tr>
                                <td>{{ $row->service_name }}</td>
                                <td class="text-center">{{ $row->quantity }}</td>
                                <td class="text-end fw-semibold">{{ format_money($row->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><div class="empty-state py-4"><i class="bi bi-tags"></i> Sin datos.</div></td></tr>
                        @endforelse
                        </tbody>
                        @if($byService->isNotEmpty())
                            <tfoot><tr class="fw-bold"><td>Total</td><td class="text-center">{{ $byService->sum('quantity') }}</td><td class="text-end">{{ format_money($byService->sum('total')) }}</td></tr></tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-people me-1 text-warning"></i> Rendimiento por barbero (nómina del período)</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>Barbero</th>
                            <th class="text-center">Servicios</th>
                            <th class="text-end">Cobrado</th>
                            <th class="text-end">Ganó</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($byBarber as $row)
                            <tr>
                                <td>{{ $row->barber?->name ?? 'Sin barbero' }}</td>
                                <td class="text-center">{{ $row->services }}</td>
                                <td class="text-end fw-semibold">{{ format_money($row->total) }}</td>
                                <td class="text-end">
                                    {{ format_money($row->commission + $row->supplies) }}
                                    @if($row->supplies > 0)<div class="small text-muted">servicios {{ format_money($row->commission) }} + insumos {{ format_money($row->supplies) }}</div>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><div class="empty-state py-4"><i class="bi bi-people"></i> Sin datos.</div></td></tr>
                        @endforelse
                        </tbody>
                        @if($byBarber->isNotEmpty())
                            <tfoot><tr class="fw-bold"><td>Total</td><td class="text-center">{{ $byBarber->sum('services') }}</td><td class="text-end">{{ format_money($byBarber->sum('total')) }}</td><td class="text-end">{{ format_money($byBarber->sum('commission') + $byBarber->sum('supplies')) }}</td></tr></tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-credit-card me-1 text-warning"></i> Ingresos por medio de pago</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>Medio de pago</th>
                            <th class="text-center">Cobros</th>
                            <th class="text-end">Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($byPayment as $row)
                            <tr>
                                <td>{{ \App\Models\Sale::PAYMENT_METHODS[$row->payment_method] ?? ucfirst($row->payment_method) }}</td>
                                <td class="text-center">{{ $row->sales_count }}</td>
                                <td class="text-end fw-semibold">{{ format_money($row->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><div class="empty-state py-4"><i class="bi bi-credit-card"></i> Sin datos.</div></td></tr>
                        @endforelse
                        </tbody>
                        @if($byPayment->isNotEmpty())
                            <tfoot><tr class="fw-bold"><td>Total</td><td class="text-center">{{ $byPayment->sum('sales_count') }}</td><td class="text-end">{{ format_money($byPayment->sum('total')) }}</td></tr></tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-wallet2 me-1 text-warning"></i> Gastos por categoría</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>Categoría</th>
                            <th class="text-center">Registros</th>
                            <th class="text-end">Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($byCategory as $row)
                            <tr>
                                <td>{{ \App\Models\Expense::CATEGORIES[$row->category] ?? ucfirst($row->category) }}</td>
                                <td class="text-center">{{ $row->count }}</td>
                                <td class="text-end fw-semibold text-danger">{{ format_money($row->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><div class="empty-state py-4"><i class="bi bi-wallet2"></i> Sin datos.</div></td></tr>
                        @endforelse
                        </tbody>
                        @if($byCategory->isNotEmpty())
                            <tfoot><tr class="fw-bold"><td>Total</td><td class="text-center">{{ $byCategory->sum('count') }}</td><td class="text-end text-danger">{{ format_money($byCategory->sum('total')) }}</td></tr></tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const reportCanvas = document.getElementById('reportChart');
        if (reportCanvas) {
            new Chart(reportCanvas, {
                type: 'line',
                data: {
                    labels: @json($dailySales->map(fn ($row) => \Carbon\Carbon::parse($row->day)->format('d/m'))->values()),
                    datasets: [{
                        label: 'Ingresos',
                        data: @json($dailySales->pluck('total')->map(fn ($value) => (float) $value)->values()),
                        borderColor: '#a02e25',
                        backgroundColor: 'rgba(160, 46, 37, .12)',
                        fill: true,
                        tension: .35,
                        pointBackgroundColor: '#161616',
                        pointBorderColor: '#a02e25',
                        pointRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
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
