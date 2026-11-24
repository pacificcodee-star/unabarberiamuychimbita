@extends('layouts.app')

@section('title', 'Comprobantes')

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('invoices.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Buscar</label>
                    <input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="N° comprobante o cliente">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Desde</label>
                    <input type="date" name="from" value="{{ request('from') }}" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Hasta</label>
                    <input type="date" name="to" value="{{ request('to') }}" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Barbero</label>
                    <select name="barber_id" class="form-select">
                        <option value="">Todos</option>
                        @foreach($barbers as $barber)
                            <option value="{{ $barber->id }}" @selected(request('barber_id') == $barber->id)>{{ $barber->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Método de pago</label>
                    <select name="payment_method" class="form-select">
                        <option value="">Todos</option>
                        @foreach(\App\Models\Sale::PAYMENT_METHODS as $key => $label)
                            <option value="{{ $key }}" @selected(request('payment_method') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-dark"><i class="bi bi-funnel me-1"></i> Filtrar</button>
                </div>
                @if(request()->hasAny(['q', 'from', 'to', 'barber_id', 'payment_method']))
                    <div class="col-auto">
                        <a href="{{ route('invoices.index') }}" class="btn btn-outline-dark">Limpiar</a>
                    </div>
                @endif
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card stat-card" data-tone="blue">
                <div class="icon"><i class="bi bi-file-earmark-text"></i></div>
                <div class="label">Comprobantes</div>
                <div class="value">{{ $totals['count'] }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card" data-tone="emerald">
                <div class="icon"><i class="bi bi-cash-stack"></i></div>
                <div class="label">Total</div>
                <div class="value">{{ format_money($totals['total']) }}</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                <tr>
                    <th>N° Comprobante</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Barbero</th>
                    <th>Servicios</th>
                    <th>Pago</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse($invoices as $invoice)
                    <tr>
                        <td class="fw-semibold">#{{ str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT) }}</td>
                        <td>
                            <div>{{ $invoice->sold_at?->format('d/m/Y') }}</div>
                            <div class="small text-muted">{{ $invoice->sold_at?->format('h:i A') }}</div>
                        </td>
                        <td>{{ $invoice->client_name ?: 'Cliente ocasional' }}</td>
                        <td>{{ $invoice->barber?->name ?? 'Venta POS' }}</td>
                        <td>
                            <div class="small">{{ $invoice->items->pluck('service_name')->join(', ') ?: '—' }}</div>
                            <div class="small text-muted">{{ $invoice->items->sum('quantity') }} servicio(s)</div>
                        </td>
                        <td><span class="badge-soft">{{ $invoice->paymentLabel() }}</span></td>
                        <td class="text-end fw-semibold">{{ format_money($invoice->total) }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-sm btn-outline-dark btn-icon" title="Ver comprobante" aria-label="Ver detalle">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="{{ route('invoices.show', $invoice) }}?print=1" class="btn btn-sm btn-gold btn-icon" title="Imprimir comprobante" target="_blank" aria-label="Imprimir">
                                <i class="bi bi-printer"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="bi bi-file-earmark-text"></i>
                                No hay comprobantes para los filtros seleccionados.
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($invoices->hasPages())
            <div class="card-body border-top">
                {{ $invoices->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>
@endsection
