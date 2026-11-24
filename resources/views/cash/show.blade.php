@extends('layouts.app')

@section('title', 'Caja · '.$cashSession->opened_at->format('d/m/Y'))

@section('actions')
    <a href="{{ route('cash.index') }}" class="btn btn-outline-dark">
        <i class="bi bi-arrow-left me-1"></i> Volver a caja
    </a>
@endsection

@section('content')
    @php
        $totalSales = (float) $cashSession->sales->sum('total');
        $totalCommissions = (float) $cashSession->sales->sum('commission_amount');
        $totalExpenses = (float) $cashSession->expenses->sum('amount');
        $net = round($totalSales - $totalExpenses - $totalCommissions, 2);
    @endphp

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card stat-card" data-tone="emerald">
                <div class="icon"><i class="bi bi-cash-coin"></i></div>
                <div class="label">Ventas de la caja</div>
                <div class="value">{{ format_money($totalSales) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card" data-tone="violet">
                <div class="icon"><i class="bi bi-person-badge"></i></div>
                <div class="label">Comisiones</div>
                <div class="value">{{ format_money($totalCommissions) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card" data-tone="red">
                <div class="icon"><i class="bi bi-wallet2"></i></div>
                <div class="label">Gastos</div>
                <div class="value text-danger">{{ format_money($totalExpenses) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card" data-tone="teal">
                <div class="icon"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="label">Ganancia de la caja</div>
                <div class="value text-success">{{ format_money($net) }}</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-info-circle me-1 text-warning"></i> Resumen de caja</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Estado</span>
                        <span class="badge-soft {{ $cashSession->status === 'abierta' ? 'green' : '' }}">{{ ucfirst($cashSession->status) }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Apertura</span>
                        <span>{{ $cashSession->opened_at->format('d/m/Y h:i A') }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Abierta por</span>
                        <span>{{ $cashSession->openedBy?->name }}</span>
                    </div>
                    @if($cashSession->closed_at)
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Cierre</span>
                            <span>{{ $cashSession->closed_at->format('d/m/Y h:i A') }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Cerrada por</span>
                            <span>{{ $cashSession->closedBy?->name }}</span>
                        </div>
                    @endif
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Base inicial</span>
                        <span class="fw-semibold">{{ format_money($cashSession->initial_amount) }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Ventas en efectivo</span>
                        <span>{{ format_money($cashSession->cashSales()) }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Gastos de la caja</span>
                        <span class="text-danger">- {{ format_money($cashSession->cashExpenses()) }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 fs-5">
                        <span>Efectivo esperado</span>
                        <span class="fw-bold">{{ format_money($expected) }}</span>
                    </div>
                    @if($cashSession->counted_amount !== null)
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Efectivo contado</span>
                            <span class="fw-semibold">{{ format_money($cashSession->counted_amount) }}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Diferencia</span>
                            <span class="fw-bold {{ (float) $cashSession->difference < 0 ? 'text-danger' : 'text-success' }}">{{ format_money($cashSession->difference) }}</span>
                        </div>
                    @endif
                    @if($cashSession->notes)
                        <hr>
                        <div class="small text-muted text-uppercase fw-semibold mb-1">Notas</div>
                        <div class="small" style="white-space: pre-line;">{{ $cashSession->notes }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            @if($cashSession->status === 'abierta')
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-lock me-1 text-warning"></i> Cerrar caja</div>
                    <div class="card-body">
                        <p class="text-muted small">Cuenta el dinero físico que hay en caja. El sistema lo comparará con el efectivo esperado y guardará la diferencia.</p>
                        <form method="POST" action="{{ route('cash.close', $cashSession) }}" class="row g-2 align-items-end" onsubmit="return confirm('¿Confirmas el cierre de caja?');">
                            @csrf
                            <div class="col-md-5">
                                <label class="form-label small text-muted mb-1">Dinero contado</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="counted_amount" class="form-control" min="0" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1">Notas</label>
                                <input type="text" name="notes" class="form-control" placeholder="Opcional">
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-dark w-100">Cerrar caja</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header"><i class="bi bi-receipt me-1 text-warning"></i> Ventas de la caja</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Barbero</th>
                            <th>Pago</th>
                            <th class="text-end">Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($cashSession->sales as $sale)
                            <tr>
                                <td>{{ $sale->sold_at->format('h:i A') }}</td>
                                <td>{{ $sale->barber?->name }}</td>
                                <td><span class="badge-soft">{{ $sale->paymentLabel() }}</span></td>
                                <td class="text-end fw-semibold">{{ format_money($sale->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><div class="empty-state py-4"><i class="bi bi-receipt"></i> No hay ventas en esta apertura.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="bi bi-wallet2 me-1 text-warning"></i> Gastos de la caja</div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Categoría</th>
                    <th>Descripción</th>
                    <th class="text-end">Monto</th>
                </tr>
                </thead>
                <tbody>
                @forelse($cashSession->expenses as $expense)
                    <tr>
                        <td>{{ $expense->expense_date->format('d/m/Y') }}</td>
                        <td><span class="badge-soft">{{ $expense->categoryLabel() }}</span></td>
                        <td>{{ $expense->description }}</td>
                        <td class="text-end fw-semibold text-danger">{{ format_money($expense->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><div class="empty-state py-4"><i class="bi bi-wallet2"></i> No hay gastos asociados a esta apertura.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
