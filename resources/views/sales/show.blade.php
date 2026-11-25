@extends('layouts.app')

@section('title', 'Venta #'.$sale->id)

@section('actions')
    <div class="page-actions no-print">
        <a href="{{ route('sales.index') }}" class="btn btn-outline-dark">
            <i class="bi bi-arrow-left me-1"></i> Volver
        </a>
        <a href="{{ route('sales.receipt', $sale) }}" class="btn btn-gold">
            <i class="bi bi-printer me-1"></i> Comprobante
        </a>
    </div>
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-receipt me-1 text-warning"></i> Detalle de la venta</span>
                    <span class="badge-soft gold">{{ $sale->sold_at->format('d/m/Y h:i A') }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>Servicio / Producto</th>
                            <th class="text-end">Precio</th>
                            <th class="text-center">Cant.</th>
                            <th class="text-end">Subtotal</th>
                            @if(auth()->user()->isAdmin())
                                <th class="text-end">Devolución</th>
                            @endif
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($sale->items as $item)
                            <tr>
                                <td>
                                    {{ $item->service_name }}
                                    @if($item->returned_quantity > 0)
                                        <div class="small text-muted">Devuelto: {{ $item->returned_quantity }}</div>
                                    @endif
                                </td>
                                <td class="text-end">{{ format_money($item->price) }}</td>
                                <td class="text-center">{{ $item->quantity }}</td>
                                <td class="text-end fw-semibold">{{ format_money($item->subtotal) }}</td>
                                @if(auth()->user()->isAdmin())
                                    <td class="text-end">
                                        @if($item->pos_product_id && $item->returnableQuantity() > 0)
                                            <form method="POST" action="{{ route('sales.items.return', [$sale, $item]) }}" class="d-inline-flex gap-1 justify-content-end" onsubmit="return confirm('¿Registrar devolución de este producto y regresar stock?');">
                                                @csrf
                                                <input type="number" name="quantity" min="1" max="{{ $item->returnableQuantity() }}" value="1" class="form-control form-control-sm" style="width:80px;" title="Cantidad a devolver">
                                                <button class="btn btn-sm btn-outline-warning btn-icon" title="Devolver al stock"><i class="bi bi-arrow-counterclockwise"></i></button>
                                            </form>
                                        @elseif($item->pos_product_id)
                                            <span class="badge-soft green">Devuelto</span>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr>
                            <td colspan="3" class="text-end text-muted">Total</td>
                            <td class="text-end fs-5 fw-bold">{{ format_money($sale->total) }}</td>
                            @if(auth()->user()->isAdmin())<td></td>@endif
                        </tr>
                        </tfoot>
                    </table>
                </div>
                @if($sale->notes)
                    <div class="card-body border-top">
                        <div class="small text-muted text-uppercase fw-semibold mb-1">Notas</div>
                        <p class="mb-0">{{ $sale->notes }}</p>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-info-circle me-1 text-warning"></i> Información</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Cliente</span>
                        <span class="fw-semibold">{{ $sale->client_name ?: 'Cliente ocasional' }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Barbero</span>
                        <span class="fw-semibold">{{ $sale->barber?->name ?? 'Venta POS' }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Método de pago</span>
                        <span class="badge-soft">{{ $sale->paymentLabel() }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Caja</span>
                        <span>{{ $sale->cashSession ? '#'.$sale->cashSession->id : 'Sin caja' }}</span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Comisión del barbero</span>
                        <span class="fw-semibold">{{ format_money($sale->commission_amount) }}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Para la barbería</span>
                        <span class="fw-semibold">{{ format_money((float) $sale->total - (float) $sale->commission_amount) }}</span>
                    </div>
                </div>
            </div>

            @if(auth()->user()->isAdmin())
                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="{{ route('sales.destroy', $sale) }}" onsubmit="return confirm('¿Eliminar esta venta? Esta acción no se puede deshacer.');">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-outline-danger w-100">
                                <i class="bi bi-trash me-1"></i> Eliminar venta
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
