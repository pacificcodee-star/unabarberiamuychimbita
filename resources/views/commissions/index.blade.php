@extends('layouts.app')

@section('title', 'Nómina')

@section('content')
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3 h-100">
                <div class="label">Producción por servicios</div>
                <div class="value fs-3">{{ format_money($totalCommissions) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3 h-100">
                <div class="label">+ Producción por insumos</div>
                <div class="value fs-3">{{ format_money($totalSupplies) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3 h-100">
                <div class="label">− Abonado</div>
                <div class="value fs-3 text-success">{{ format_money($totalPaid) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3 h-100" style="border-color: rgba(160,46,37,.45);">
                <div class="label">= Pendiente</div>
                <div class="value fs-3">{{ format_money($totalPending) }}</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><i class="bi bi-people me-1 text-warning"></i> Nómina por barbero</div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle payroll-table">
                        <thead>
                        <tr>
                            <th>Barbero</th>
                            <th class="text-end">Producción<br><small class="text-muted fw-normal">servicios</small></th>
                            <th class="text-end">Producción<br><small class="text-muted fw-normal">insumos</small></th>
                            <th class="text-end">Total acumulado</th>
                            <th class="text-end">Abonado</th>
                            <th class="text-end">Pendiente</th>
                            <th class="text-end"></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($barbers as $barber)
                            @php
                                $generated = (float) ($barber->total_commission ?? 0);
                                $supplyTotal = (float) ($barber->total_supplies ?? 0);
                                $paid = (float) ($barber->total_paid ?? 0);
                                $net = round($generated + $supplyTotal, 2);
                                $pending = max(0, round($net - $paid, 2));
                                $earnedRows = $earned[$barber->id] ?? collect();
                                $supplyRows = $supplies[$barber->id] ?? collect();
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $barber->name }}</div>
                                </td>
                                <td class="text-end">{{ format_money($generated) }}</td>
                                <td class="text-end">{{ $supplyTotal > 0 ? format_money($supplyTotal) : '—' }}</td>
                                <td class="text-end fw-semibold">{{ format_money($net) }}</td>
                                <td class="text-end text-success">{{ $paid > 0 ? format_money($paid) : '—' }}</td>
                                <td class="text-end fw-bold {{ $pending > 0 ? 'text-danger' : 'text-success' }}">{{ format_money($pending) }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center justify-content-end gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-dark payroll-detail-btn" data-url="{{ route('commissions.show', [$barber, 'embed' => 1]) }}" data-name="{{ $barber->name }}" title="Ver detalle y filtrar por fechas" aria-label="Ver detalle de {{ $barber->name }}">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        @if($pending > 0)
                                            <button type="button" class="btn btn-sm btn-gold pay-button" data-barber="{{ $barber->id }}" data-pending="{{ $pending }}">Pagar</button>
                                        @elseif($net <= 0)
                                            <span class="badge-soft" title="No ha realizado servicios ni generado ingresos">Sin ingresos</span>
                                        @else
                                            <span class="badge-soft green">Al día</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7"><div class="empty-state"><i class="bi bi-people"></i> No hay barberos registrados.</div></td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><i class="bi bi-clock-history me-1 text-warning"></i> Pagos de nómina</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Barbero</th>
                            <th>Notas</th>
                            <th>Registrado por</th>
                            <th class="text-end">Monto</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($payments as $payment)
                            <tr>
                                <td>{{ $payment->paid_at->format('d/m/Y') }}</td>
                                <td>{{ $payment->barber?->name }}</td>
                                <td class="small text-muted">{{ $payment->notes ?: '—' }}</td>
                                <td class="small text-muted">{{ $payment->registeredBy?->name ?? '—' }}</td>
                                <td class="text-end fw-semibold">{{ format_money($payment->amount) }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('commissions.destroy', $payment) }}" onsubmit="return confirm('¿Eliminar este pago de nómina?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6"><div class="empty-state py-4"><i class="bi bi-clock-history"></i> Todavía no se han registrado pagos de nómina.</div></td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($payments->hasPages())
                    <div class="card-body border-top">{{ $payments->links('pagination::bootstrap-5') }}</div>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card position-sticky" style="top: 20px;">
                <div class="card-header"><i class="bi bi-cash-coin me-1 text-warning"></i> Registrar pago de nómina</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('commissions.store') }}" id="paymentForm">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Barbero <span class="text-danger">*</span></label>
                            <select name="barber_id" id="paymentBarber" class="form-select" required>
                                <option value="">Selecciona un barbero</option>
                                @foreach($barbers as $barber)
                                    @php($pending = max(0, (float) ($barber->total_commission ?? 0) + (float) ($barber->total_supplies ?? 0) - (float) ($barber->total_paid ?? 0)))
                                    <option value="{{ $barber->id }}" data-pending="{{ $pending }}" @disabled($pending <= 0)>{{ $barber->name }}{{ $pending <= 0 ? ' · sin saldo por pagar' : ' · '.format_money($pending) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Monto a pagar <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="amount" id="paymentAmount" value="{{ old('amount') }}" class="form-control" min="0.01" step="0.01" required>
                            </div>
                            <div class="form-text" id="pendingHint"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Fecha de pago <span class="text-danger">*</span></label>
                            <input type="date" name="paid_at" value="{{ now()->toDateString() }}" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Notas</label>
                            <textarea name="notes" rows="2" class="form-control" placeholder="Opcional"></textarea>
                        </div>
                        <button class="btn btn-gold w-100">
                            <i class="bi bi-check2-circle me-1"></i> Registrar pago
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Ventana emergente con el detalle del barbero (en esta misma página) --}}
    <div class="modal fade" id="payrollModal" tabindex="-1" aria-labelledby="payrollModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="payrollModalTitle"><i class="bi bi-eye text-warning me-1"></i> Nómina · <span data-payroll-name></span></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="payroll-loading" data-payroll-loading><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span> Cargando…</div>
                    <iframe title="Detalle de nómina" data-payroll-frame class="payroll-frame"></iframe>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
<style>
    .payroll-frame { display: block; width: 100%; height: 78vh; border: 0; }
    .payroll-loading { position: absolute; inset: 0; display: grid; place-items: center; color: #6b6458; background: #fff; }
    .payroll-loading[hidden] { display: none; }
    #payrollModal .modal-body { position: relative; min-height: 78vh; }
    .payroll-table [aria-expanded="true"] .bi-chevron-down { transform: rotate(180deg); }
    .payroll-table .bi-chevron-down { display: inline-block; transition: transform .2s ease; }
</style>
@endpush

@push('scripts')
    <script>
        // Abre el detalle del barbero en una ventana emergente, sin salir de Nómina.
        (() => {
            const modalEl = document.getElementById('payrollModal');
            if (!modalEl || !window.bootstrap) return;
            const modal = new bootstrap.Modal(modalEl);
            const frame = modalEl.querySelector('[data-payroll-frame]');
            const loading = modalEl.querySelector('[data-payroll-loading]');
            frame.addEventListener('load', () => { loading.hidden = true; });
            document.querySelectorAll('.payroll-detail-btn').forEach((btn) => btn.addEventListener('click', () => {
                modalEl.querySelector('[data-payroll-name]').textContent = btn.dataset.name;
                loading.hidden = false;
                frame.src = btn.dataset.url;
                modal.show();
            }));
            modalEl.addEventListener('hidden.bs.modal', () => { frame.src = 'about:blank'; });
        })();
        const paymentBarber = document.getElementById('paymentBarber');
        const paymentAmount = document.getElementById('paymentAmount');
        const pendingHint = document.getElementById('pendingHint');

        function fillPending() {
            const option = paymentBarber.selectedOptions[0];
            const pending = option ? parseFloat(option.dataset.pending || 0) : 0;
            pendingHint.textContent = pending > 0
                ? 'Pendiente actual: ' + new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(pending)
                : '';
        }

        // No se puede pagar más de lo pendiente.
        const capAmount = () => {
            const option = paymentBarber.selectedOptions[0];
            const pending = option ? parseFloat(option.dataset.pending || 0) : 0;
            if (pending > 0) paymentAmount.max = pending; else paymentAmount.removeAttribute('max');
        };
        paymentBarber.addEventListener('change', capAmount);
        paymentBarber.addEventListener('change', () => {
            const option = paymentBarber.selectedOptions[0];
            if (option && option.value) {
                paymentAmount.value = parseFloat(option.dataset.pending || 0) || '';
            }
            fillPending();
        });

        document.querySelectorAll('.pay-button').forEach((button) => {
            button.addEventListener('click', () => {
                paymentBarber.value = button.dataset.barber;
                paymentAmount.value = button.dataset.pending;
                fillPending();
                document.getElementById('paymentForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
                paymentAmount.focus();
            });
        });
    </script>
@endpush
