@extends('layouts.app')

@section('title', 'Caja')

@section('content')
    @if($openSession)
        @php
            $expected = $openSession->calculateExpected();
            $cashSales = $openSession->cashSales();
            $cashExpenses = $openSession->cashExpenses();
        @endphp
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-unlock me-1 text-success"></i> Caja de la barbería · abierta</span>
                <span class="badge-soft green">Desde {{ $openSession->opened_at->format('d/m/Y h:i A') }}</span>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="p-3 rounded-4 h-100" style="background: rgba(22,22,22,.04);">
                            <div class="small text-muted">Base inicial</div>
                            <div class="fs-5 fw-bold">{{ format_money($openSession->initial_amount) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="p-3 rounded-4 h-100" style="background: rgba(31,138,91,.07);">
                            <div class="small text-muted">Ventas en efectivo</div>
                            <div class="fs-5 fw-bold text-success">{{ format_money($cashSales) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="p-3 rounded-4 h-100" style="background: rgba(192,57,43,.06);">
                            <div class="small text-muted">Gastos en efectivo</div>
                            <div class="fs-5 fw-bold text-danger">{{ format_money($cashExpenses) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="p-3 rounded-4 h-100" style="background: rgba(160,46,37,.12);">
                            <div class="small text-muted">Efectivo esperado</div>
                            <div class="fs-5 fw-bold">{{ format_money($expected) }}</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <div class="small text-muted">
                        Abierta el {{ $openSession->opened_at->format('d/m/Y h:i A') }} por {{ $openSession->openedBy?->name }}.
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('cash.show', $openSession) }}" class="btn btn-outline-dark">
                            <i class="bi bi-eye me-1"></i> Ver movimientos
                        </a>
                        <button class="btn btn-gold" type="button" data-bs-toggle="collapse" data-bs-target="#closeForm">
                            <i class="bi bi-lock me-1"></i> Cerrar caja
                        </button>
                    </div>
                </div>

                <div class="collapse mt-3" id="closeForm">
                    <div class="border rounded-4 p-3">
                        <form method="POST" action="{{ route('cash.close', $openSession) }}" class="row g-2 align-items-end" onsubmit="return confirm('¿Confirmas el cierre de caja? Las ventas y gastos que se registren después quedarán para la próxima apertura.');">
                            @csrf
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1">Dinero contado en caja</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="counted_amount" class="form-control" min="0" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small text-muted mb-1">Notas del cierre</label>
                                <input type="text" name="notes" class="form-control" placeholder="Opcional">
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-dark w-100"><i class="bi bi-check2-circle me-1"></i> Confirmar cierre</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="row g-3 mb-4">
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header"><i class="bi bi-cash-stack me-1 text-warning"></i> Abrir la caja</div>
                    <div class="card-body">
                        <p class="text-muted">La barbería tiene <strong>una sola caja</strong> para todos. Registra el dinero con el que empieza para comparar al cerrarla.</p>
                        <form method="POST" action="{{ route('cash.open') }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Dinero inicial <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="initial_amount" class="form-control" min="0" step="0.01" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Notas</label>
                                <input type="text" name="notes" class="form-control" placeholder="Opcional">
                            </div>
                            <button class="btn btn-gold w-100"><i class="bi bi-unlock me-1"></i> Abrir caja</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <div class="empty-state py-3">
                            <i class="bi bi-cash-stack"></i>
                            <h4 class="mb-2">La caja está cerrada</h4>
                            <p class="mb-0">Ábrela al iniciar el día. Todas las ventas en efectivo y los gastos de todos los barberos entran a esta misma caja.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header"><i class="bi bi-clock-history me-1 text-warning"></i> Aperturas y cierres de la caja</div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                <tr>
                    <th>Día</th>
                    <th>Apertura</th>
                    <th>Cierre</th>
                    <th class="text-end">Inicial</th>
                    <th class="text-end">Esperado</th>
                    <th class="text-end">Contado</th>
                    <th class="text-end">Diferencia</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($sessions as $session)
                    <tr>
                        <td class="fw-semibold text-nowrap">{{ $session->opened_at->translatedFormat('D d M') }}</td>
                        <td>
                            <div>{{ $session->opened_at->format('d/m/Y') }}</div>
                            <div class="small text-muted">{{ $session->opened_at->format('h:i A') }} · {{ $session->openedBy?->name }}</div>
                        </td>
                        <td>
                            @if($session->closed_at)
                                <div>{{ $session->closed_at->format('d/m/Y') }}</div>
                                <div class="small text-muted">{{ $session->closed_at->format('h:i A') }} · {{ $session->closedBy?->name }}</div>
                            @else
                                <span class="badge-soft green">En curso</span>
                            @endif
                        </td>
                        <td class="text-end">{{ format_money($session->initial_amount) }}</td>
                        <td class="text-end">{{ $session->expected_amount !== null ? format_money($session->expected_amount) : '—' }}</td>
                        <td class="text-end">{{ $session->counted_amount !== null ? format_money($session->counted_amount) : '—' }}</td>
                        <td class="text-end fw-semibold {{ (float) $session->difference < 0 ? 'text-danger' : ((float) $session->difference > 0 ? 'text-success' : '') }}">
                            {{ $session->difference !== null ? format_money($session->difference) : '—' }}
                        </td>
                        <td>
                            <span class="badge-soft {{ $session->status === 'abierta' ? 'green' : '' }}">{{ ucfirst($session->status) }}</span>
                        </td>
                        <td class="text-end">
                            <a href="{{ route('cash.show', $session) }}" class="btn btn-sm btn-outline-dark"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9"><div class="empty-state"><i class="bi bi-cash-stack"></i> La caja aún no se ha abierto.</div></td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($sessions->hasPages())
            <div class="card-body border-top">{{ $sessions->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
@endsection
