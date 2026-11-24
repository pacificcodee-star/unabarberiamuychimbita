@extends('layouts.app')

@section('title', 'Insumos')

@section('actions')
    <a href="{{ route('supplies.create') }}" class="btn btn-gold"><i class="bi bi-plus-lg me-1"></i> Nuevo insumo</a>
@endsection

@section('content')
    <div class="alert alert-light">
        <i class="bi bi-info-circle"></i>
        Registra aquí los <strong>insumos</strong> del negocio con su valor. Cada vez que un barbero use o entregue uno, regístralo:
        el barbero <strong>gana el % de su valor</strong> que le pusiste en <a href="{{ route('barbers.index') }}">Barberos</a> (o el % general del insumo) y se <strong>suma a su nómina</strong>.
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-box-seam me-1 text-warning"></i> Insumos registrados</div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead>
                        <tr>
                            <th>Insumo</th>
                            <th class="text-end">Valor</th>
                            <th class="text-end">% general que gana</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($supplies as $supply)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $supply->name }}</div>
                                    @if($supply->unit)<div class="small text-muted">{{ $supply->unit }}</div>@endif
                                </td>
                                <td class="text-end">{{ format_money($supply->cost) }}</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format((float) $supply->default_percentage, 2, ',', '.'), '0'), ',') }}%</td>
                                <td><span class="badge-soft {{ $supply->active ? 'green' : 'red' }}">{{ $supply->active ? 'Activo' : 'Inactivo' }}</span></td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('supplies.edit', $supply) }}" class="btn btn-sm btn-outline-dark btn-icon" aria-label="Editar" title="Editar"><i class="bi bi-pencil"></i></a>
                                    <form method="POST" action="{{ route('supplies.destroy', $supply) }}" class="d-inline" onsubmit="return confirm('¿Eliminar este insumo?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger btn-icon" aria-label="Eliminar" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty-state"><i class="bi bi-box-seam"></i> Aún no hay insumos. Crea el primero con «Nuevo insumo».</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-clock-history me-1 text-warning"></i> Insumos registrados por barbero</span>
                    <form method="GET" class="d-flex gap-2">
                        <select name="barber" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filtrar por barbero">
                            <option value="">Todos los barberos</option>
                            @foreach($barbers as $barber)
                                <option value="{{ $barber->id }}" @selected((string) request('barber') === (string) $barber->id)>{{ $barber->name }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Barbero</th>
                            <th>Insumo</th>
                            <th class="text-end">Cant.</th>
                            <th class="text-end">Gana</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($deliveries as $delivery)
                            <tr>
                                <td class="text-nowrap">{{ $delivery->delivered_at->format('d/m/Y') }}</td>
                                <td>{{ $delivery->barber?->name }}</td>
                                <td>
                                    <div>{{ $delivery->supply_name }}</div>
                                    <div class="small text-muted">{{ format_money($delivery->unit_cost) }} c/u · {{ rtrim(rtrim(number_format((float) $delivery->percentage, 2, ',', '.'), '0'), ',') }}%@if($delivery->notes) · {{ $delivery->notes }}@endif</div>
                                </td>
                                <td class="text-end">{{ $delivery->quantity }}</td>
                                <td class="text-end fw-semibold text-success">{{ format_money($delivery->amount) }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('supplies.deliveries.destroy', $delivery) }}" onsubmit="return confirm('¿Eliminar este registro? Ya no se sumará a la nómina.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger btn-icon" aria-label="Eliminar" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><div class="empty-state py-4"><i class="bi bi-clock-history"></i> Todavía no hay insumos registrados a barberos.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($deliveries->hasPages())
                    <div class="card-body border-top">{{ $deliveries->links('pagination::bootstrap-5') }}</div>
                @endif
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card sticky-panel">
                <div class="card-header"><i class="bi bi-box-arrow-right me-1 text-warning"></i> Registrar insumo de un barbero</div>
                <div class="card-body">
                    @php($activeSupplies = $supplies->where('active', true))
                    @if($activeSupplies->isEmpty() || $barbers->isEmpty())
                        <div class="empty-state py-4"><i class="bi bi-box-seam"></i>Necesitas al menos un insumo activo y un barbero activo.</div>
                    @else
                        <form method="POST" action="{{ route('supplies.deliveries.store') }}" id="deliveryForm">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="deliveryBarber">Barbero <span class="text-danger">*</span></label>
                                <select name="barber_id" id="deliveryBarber" class="form-select" required>
                                    <option value="">Selecciona un barbero</option>
                                    @foreach($barbers as $barber)
                                        <option value="{{ $barber->id }}" @selected(old('barber_id') == $barber->id)>{{ $barber->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="deliverySupply">Insumo <span class="text-danger">*</span></label>
                                <select name="supply_id" id="deliverySupply" class="form-select" required>
                                    <option value="">Selecciona un insumo</option>
                                    @foreach($activeSupplies as $supply)
                                        <option value="{{ $supply->id }}" data-cost="{{ (float) $supply->cost }}" @selected(old('supply_id') == $supply->id)>{{ $supply->name }} · {{ format_money($supply->cost) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-5">
                                    <label class="form-label fw-semibold" for="deliveryQty">Cantidad</label>
                                    <input type="number" name="quantity" id="deliveryQty" value="{{ old('quantity', 1) }}" class="form-control" min="1" max="1000" required>
                                </div>
                                <div class="col-7">
                                    <label class="form-label fw-semibold" for="deliveryDate">Fecha</label>
                                    <input type="date" name="delivered_at" id="deliveryDate" value="{{ old('delivered_at', now()->toDateString()) }}" class="form-control" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="deliveryNotes">Notas</label>
                                <input type="text" name="notes" id="deliveryNotes" value="{{ old('notes') }}" class="form-control" maxlength="300" placeholder="Opcional">
                            </div>
                            <div class="mini-stat mb-3">
                                <span class="mini-stat-label">El barbero gana (se suma a su nómina)</span>
                                <div class="mini-stat-value text-success" id="deliveryPreview">$0</div>
                                <div class="form-text mt-1" id="deliveryDetail">Elige barbero e insumo.</div>
                            </div>
                            <button class="btn btn-gold w-100"><i class="bi bi-check2-circle me-1"></i> Registrar</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    (() => {
        const form = document.getElementById('deliveryForm');
        if (!form) return;
        const rates = @json($rates);
        const barber = document.getElementById('deliveryBarber');
        const supply = document.getElementById('deliverySupply');
        const qty = document.getElementById('deliveryQty');
        const preview = document.getElementById('deliveryPreview');
        const detail = document.getElementById('deliveryDetail');
        const money = (n) => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n);
        const update = () => {
            const option = supply.selectedOptions[0];
            if (!barber.value || !option || !option.value) { preview.textContent = money(0); detail.textContent = 'Elige barbero e insumo.'; return; }
            const cost = parseFloat(option.dataset.cost || 0);
            const pct = parseFloat((rates[barber.value] || {})[option.value] ?? 100);
            const q = Math.max(1, parseInt(qty.value || 1, 10));
            preview.textContent = money(Math.round(cost * q * pct) / 100);
            detail.textContent = `${q} × ${money(cost)} × ${pct}%`;
        };
        [barber, supply, qty].forEach((el) => el.addEventListener('input', update));
        update();
    })();
</script>
@endpush
