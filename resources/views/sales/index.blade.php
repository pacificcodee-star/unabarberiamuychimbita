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
                <div class="col-12 d-flex flex-wrap gap-2">
                    @php $q = request()->only(['barber_id', 'payment_method']); @endphp
                    <a href="{{ route('sales.index', $q) }}" class="btn btn-sm {{ !request()->filled('from') && !request()->filled('to') ? 'btn-gold' : 'btn-outline-dark' }}">Todo</a>
                    <a href="{{ route('sales.index', $q + ['from' => now()->toDateString(), 'to' => now()->toDateString()]) }}" class="btn btn-sm btn-outline-dark">Hoy</a>
                    <a href="{{ route('sales.index', $q + ['from' => now()->startOfWeek()->toDateString(), 'to' => now()->endOfWeek()->toDateString()]) }}" class="btn btn-sm btn-outline-dark">Semana</a>
                    <a href="{{ route('sales.index', $q + ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]) }}" class="btn btn-sm btn-outline-dark">Mes</a>
                    <a href="{{ route('sales.index', $q + ['from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString()]) }}" class="btn btn-sm btn-outline-dark">Mes pasado</a>
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
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="small text-muted">Período: <strong>{{ $periodLabel }}</strong></div>
        <a class="small" href="{{ route('reports.index', array_filter(['start' => request('from'), 'end' => request('to')])) }}"><i class="bi bi-graph-up-arrow me-1"></i>Ver este período en Informes</a>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3">
                <div class="label">Ingresos</div>
                <div class="value fs-3">{{ format_money($totals['total']) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3">
                <div class="label">Servicios realizados</div>
                <div class="value fs-3">{{ $totals['services'] }}</div>
                <div class="hint">en {{ $totals['count'] }} cobros</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3">
                <div class="label">Nómina por servicios</div>
                <div class="value fs-3">{{ format_money($totals['commission']) }}</div>
                <div class="hint">Lo que ganan los barberos</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3">
                <div class="label">Queda para el negocio</div>
                <div class="value fs-3">{{ format_money($totals['total'] - $totals['commission']) }}</div>
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
                            <a href="{{ route('sales.show', $sale) }}" class="btn btn-sm btn-outline-dark" title="Ver detalle">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="{{ route('sales.receipt', $sale) }}" class="btn btn-sm btn-outline-dark" title="Comprobante">
                                <i class="bi bi-printer"></i>
                            </a>
                            @if(auth()->user()->isAdmin())
                                <form method="POST" action="{{ route('sales.destroy', $sale) }}" class="d-inline" onsubmit="return confirm('¿Eliminar esta venta? Esta acción no se puede deshacer.');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
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
