@extends('layouts.app')

@section('title', 'Historial de ventas')

@section('actions')
    <a href="{{ route('pos.index') }}" class="btn btn-gold"><i class="bi bi-cart-check me-1"></i> Ir a venta POS</a>
    <a href="{{ route('pos.inventory') }}" class="btn btn-outline-dark"><i class="bi bi-box-seam me-1"></i> Inventario</a>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('pos.history') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Desde</label>
                    <input type="date" name="from" value="{{ $from }}" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Hasta</label>
                    <input type="date" name="to" value="{{ $to }}" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Método de pago</label>
                    <select name="payment_method" class="form-select">
                        <option value="">Todos</option>
                        @foreach(\App\Models\Sale::PAYMENT_METHODS as $key => $label)
                            <option value="{{ $key }}" @selected(($paymentMethod ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Buscar (cliente o # comprobante)</label>
                    <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="Nombre o ID">
                </div>
                <div class="col-auto">
                    <button class="btn btn-dark"><i class="bi bi-funnel me-1"></i> Filtrar</button>
                    <a href="{{ route('pos.history') }}" class="btn btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card stat-card">
                <div class="icon"><i class="bi bi-bag-check"></i></div>
                <div class="label">Ventas</div>
                <div class="value">{{ $totals['count'] }}</div>
                <div class="hint">comprobantes en el periodo</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card">
                <div class="icon"><i class="bi bi-cash-coin"></i></div>
                <div class="label">Total vendido</div>
                <div class="value">{{ format_money($totals['amount']) }}</div>
                <div class="hint">ingresos POS</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card">
                <div class="icon"><i class="bi bi-receipt"></i></div>
                <div class="label">Promedio por venta</div>
                <div class="value">{{ format_money($totals['average']) }}</div>
                <div class="hint">ticket promedio</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card">
                <div class="icon"><i class="bi bi-credit-card"></i></div>
                <div class="label">Métodos usados</div>
                <div class="value">{{ $byPayment->count() }}</div>
                <div class="hint">formas de pago</div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-graph-up me-1 text-warning"></i> Ventas por día</span>
            <span class="badge-soft">{{ $dailySales->count() }} días</span>
        </div>
        <div class="card-body">
            @if($dailySales->isEmpty())
                <div class="empty-state py-3"><i class="bi bi-bar-chart-line"></i> No hay ventas POS en el periodo seleccionado.</div>
            @else
                <canvas id="posHistoryChart" height="90"></canvas>
            @endif
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-credit-card me-1 text-warning"></i> Totales por método de pago</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Método</th><th class="text-center">Ventas</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                        @forelse($byPayment as $row)
                            <tr>
                                <td>{{ \App\Models\Sale::PAYMENT_METHODS[$row->payment_method] ?? $row->payment_method }}</td>
                                <td class="text-center">{{ $row->count }}</td>
                                <td class="text-end fw-semibold">{{ format_money($row->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><div class="empty-state py-4"><i class="bi bi-wallet2"></i> Sin datos.</div></td></tr>
                        @endforelse
                        </tbody>
                        @if($byPayment->isNotEmpty())
                            <tfoot><tr class="fw-bold"><td>Total</td><td class="text-center">{{ $byPayment->sum('count') }}</td><td class="text-end">{{ format_money($byPayment->sum('total')) }}</td></tr></tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-receipt me-1 text-warning"></i> Historial de ventas POS</span>
                    <span class="badge-soft">{{ $sales->total() }} registros</span>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead>
                        <tr>
                            <th>Comprobante</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Productos</th>
                            <th>Método</th>
                            <th class="text-end">Total</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($sales as $sale)
                            <tr>
                                <td class="fw-semibold">#{{ str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT) }}</td>
                                <td class="text-nowrap">{{ $sale->sold_at?->format('d/m/Y h:i A') }}</td>
                                <td>{{ $sale->client_name ?: 'Cliente ocasional' }}</td>
                                <td class="small text-muted">{{ $sale->items->pluck('service_name')->join(', ') }}</td>
                                <td><span class="badge-soft gold">{{ \App\Models\Sale::PAYMENT_METHODS[$sale->payment_method] ?? $sale->payment_method }}</span></td>
                                <td class="text-end fw-semibold">{{ format_money($sale->total) }}</td>
                                <td class="text-end">
                                    <a href="{{ route('sales.show', $sale) }}" class="btn btn-sm btn-outline-dark btn-icon" title="Ver" aria-label="Ver detalle"><i class="bi bi-eye"></i></a>
                                    <a href="{{ route('invoices.show', $sale) }}" class="btn btn-sm btn-outline-secondary btn-icon" title="Comprobante"><i class="bi bi-receipt-cutoff"></i></a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><div class="empty-state py-4"><i class="bi bi-receipt"></i> No hay ventas POS para mostrar.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($sales->hasPages())
                    <div class="card-footer">{{ $sales->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    const posHistoryCanvas = document.getElementById('posHistoryChart');
    if (posHistoryCanvas) {
        new Chart(posHistoryCanvas, {
            type: 'line',
            data: {
                labels: @json($dailySales->map(fn ($row) => \Carbon\Carbon::parse($row->day)->format('d/m'))->values()),
                datasets: [{
                    label: 'Ventas POS',
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
                        ticks: { callback: (value) => '$' + Number(value).toLocaleString('es-CO') }
                    }
                }
            }
        });
    }
</script>
@endpush
