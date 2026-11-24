@extends('layouts.app')

@section('title', 'Ventas y servicios')

@section('actions')
    <a href="{{ route('sales.create') }}" class="btn btn-gold no-print">
        <i class="bi bi-scissors me-1"></i> Tomar servicio
    </a>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('sales.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Desde</label>
                    <input type="date" name="from" value="{{ request('from') }}" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Hasta</label>
                    <input type="date" name="to" value="{{ request('to') }}" class="form-control">
                </div>
                @if(auth()->user()->isAdmin())
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label small text-muted mb-1">Barbero</label>
                        <select name="barber_id" class="form-select">
                            <option value="">Todos</option>
                            @foreach($barbers as $barber)
                                <option value="{{ $barber->id }}" @selected(request('barber_id') == $barber->id)>{{ $barber->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Método de pago</label>
                    <select name="payment_method" class="form-select">
                        <option value="">Todos</option>
                        @foreach(\App\Models\Sale::PAYMENT_METHODS as $key => $label)
                            <option value="{{ $key }}" @selected(request('payment_method') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-auto">
                    <button class="btn btn-dark w-100"><i class="bi bi-funnel me-1"></i> Filtrar</button>
                </div>
                <div class="col-12">
                    @php
                        $q = request()->only(['barber_id', 'payment_method']);
                        $ranges = [
                            'Todo' => [],
                            'Hoy' => ['from' => now()->toDateString(), 'to' => now()->toDateString()],
                            'Semana' => ['from' => now()->startOfWeek()->toDateString(), 'to' => now()->endOfWeek()->toDateString()],
                            'Mes' => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()],
                            'Mes pasado' => ['from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
                        ];
                    @endphp
                    <nav class="segmented" aria-label="Rangos rápidos">
                        @foreach($ranges as $rangeLabel => $range)
                            @php $isCurrent = (string) request('from') === ($range['from'] ?? '') && (string) request('to') === ($range['to'] ?? ''); @endphp
                            <a href="{{ route('sales.index', $q + $range) }}" class="{{ $isCurrent ? 'is-active' : '' }}" @if($isCurrent) aria-current="true" @endif>{{ $rangeLabel }}</a>
                        @endforeach
                    </nav>
                </div>
                @if(request()->hasAny(['from', 'to', 'barber_id', 'payment_method']))
                    <div class="col-lg-auto">
                        <a href="{{ route('sales.index') }}" class="btn btn-outline-dark w-100">Limpiar</a>
                    </div>
                @endif
            </form>
        </div>
    </div>

    @php
        $periodLabel = request()->filled('from') || request()->filled('to')
            ? (request('from') ? \Carbon\Carbon::parse(request('from'))->format('d/m/Y') : 'el inicio').' — '.(request('to') ? \Carbon\Carbon::parse(request('to'))->format('d/m/Y') : 'hoy')
            : 'Todo (desde el primer servicio)';
    @endphp
    <div class="period-bar">
        <span><i class="bi bi-calendar3" aria-hidden="true"></i> Período: <strong>{{ $periodLabel }}</strong></span>
        <a href="{{ route('reports.index', array_filter(['start' => request('from'), 'end' => request('to')])) }}"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i> Ver este período en Informes</a>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card stat-card">
                <div class="icon"><i class="bi bi-cash-coin"></i></div>
                <div class="label">Ingresos</div>
                <div class="value">{{ format_money($totals['total']) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card">
                <div class="icon"><i class="bi bi-scissors"></i></div>
                <div class="label">Servicios realizados</div>
                <div class="value">{{ $totals['services'] }}</div>
                <div class="hint">en {{ $totals['count'] }} cobros</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card">
                <div class="icon"><i class="bi bi-person-badge"></i></div>
                <div class="label">Nómina por servicios</div>
                <div class="value">{{ format_money($totals['commission']) }}</div>
                <div class="hint">Lo que ganan los barberos</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card tone-success">
                <div class="icon"><i class="bi bi-piggy-bank"></i></div>
                <div class="label">Queda para el negocio</div>
                <div class="value">{{ format_money($totals['total'] - $totals['commission']) }}</div>
                <div class="hint">Antes de gastos</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Barbero</th>
                    <th>Servicios</th>
                    <th>Pago</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Gana barbero</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse($sales as $sale)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $sale->sold_at->format('d/m/Y') }}</div>
                            <div class="small text-muted">{{ $sale->sold_at->format('h:i A') }}</div>
                        </td>
                        <td>{{ $sale->client_name ?: 'Cliente ocasional' }}</td>
                        <td>{{ $sale->barber?->name ?? 'Venta POS' }}</td>
                        <td>
                            <div class="small">{{ $sale->items->pluck('service_name')->join(', ') }}</div>
                            <div class="small text-muted">{{ $sale->items->sum('quantity') }} servicio(s)</div>
                        </td>
                        <td><span class="badge-soft">{{ $sale->paymentLabel() }}</span></td>
                        <td class="text-end fw-semibold">{{ format_money($sale->total) }}</td>
                        <td class="text-end">
                            <div>{{ format_money($sale->commission_amount) }}</div>
                            <div class="small text-muted">{{ number_format((float) $sale->commission_percentage, 0) }}%</div>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('sales.show', $sale) }}" class="btn btn-sm btn-outline-dark btn-icon" title="Ver detalle" aria-label="Ver detalle">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="{{ route('sales.receipt', $sale) }}" class="btn btn-sm btn-outline-dark btn-icon" title="Comprobante" aria-label="Imprimir">
                                <i class="bi bi-printer"></i>
                            </a>
                            @if(auth()->user()->isAdmin())
                                <form method="POST" action="{{ route('sales.destroy', $sale) }}" class="d-inline" onsubmit="return confirm('¿Eliminar esta venta? Esta acción no se puede deshacer.');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger btn-icon" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash"></i></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="bi bi-receipt"></i>
                                No hay ventas para los filtros seleccionados.
                                <div class="mt-3">
                                    <a href="{{ route('sales.create') }}" class="btn btn-gold">Tomar primer servicio</a>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($sales->hasPages())
            <div class="card-body border-top">
                {{ $sales->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>
@endsection
