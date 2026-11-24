@extends('layouts.app')

@section('title', 'Venta POS')

@section('actions')
    <a href="{{ route('pos.inventory') }}" class="btn btn-outline-dark"><i class="bi bi-box-seam me-1"></i> Inventario</a>
    <a href="{{ route('pos.history') }}" class="btn btn-dark"><i class="bi bi-clock-history me-1"></i> Historial de venta</a>
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <span><i class="bi bi-box-seam me-1 text-warning"></i> Productos disponibles</span>
                    <span class="badge-soft">{{ $products->count() }} activos</span>
                </div>
                <div class="card-toolbar">
                    <div class="search-input">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" id="posProductSearch" class="form-control" placeholder="Buscar por nombre o código" aria-label="Buscar producto">
                    </div>
                </div>
                <div class="table-responsive" style="max-height: calc(100vh - 320px); min-height: 420px;">
                    <table class="table table-sm mb-0 align-middle" id="posAvailableTable">
                        <thead><tr><th>Producto</th><th class="text-end text-nowrap" style="min-width: 110px;">Precio</th><th class="text-end">Stock</th><th style="width: 150px;">Vender</th></tr></thead>
                        <tbody>
                        @forelse($products as $p)
                            <tr class="pos-available-row" data-id="{{ $p->id }}" data-name="{{ e($p->name) }}" data-ref="{{ e($p->reference ?? '') }}" data-stock="{{ $p->stock }}">
                                <td>
                                    <div class="fw-semibold">{{ $p->name }}</div>
                                    <div class="small text-muted">
                                        @if($p->category){{ $p->category }}@endif
                                        @if($p->category && $p->reference) · @endif
                                        @if($p->reference)Ref: {{ $p->reference }}@endif
                                    </div>
                                </td>
                                <td class="text-end text-nowrap">{{ format_money($p->sale_price) }}</td>
                                <td class="text-end"><span class="badge-soft {{ $p->isLowStock() ? 'red' : ($p->stock == 0 ? 'red' : 'green') }}">{{ $p->stock }}</span></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <input type="number" class="form-control form-control-sm pos-add-qty" min="1" max="{{ $p->stock }}" value="1" style="width:70px;" @disabled($p->stock < 1)>
                                        <button type="button" class="btn btn-sm btn-gold pos-add-btn" data-id="{{ $p->id }}" @disabled($p->stock < 1) title="Agregar a la venta"><i class="bi bi-plus-lg"></i></button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted small p-3">Sin productos. <a href="{{ route('pos.inventory') }}">Ir a inventario</a>.</td></tr>
                        @endforelse
                        <tr id="posAvailableNoMatch" style="display:none;"><td colspan="4" class="text-muted small p-3">No hay productos que coincidan con la búsqueda.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-cart-check"></i> Punto de venta</div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Selecciona productos desde la lista de disponibles, indica la cantidad y registra la venta.</p>
                    @if($products->isEmpty())
                        <div class="alert alert-warning mb-3">No hay productos activos en inventario. <a href="{{ route('pos.inventory') }}">Agrega productos</a> para poder vender.</div>
                    @endif
                    <form method="POST" action="{{ route('pos.store') }}" id="posForm">
                        @csrf
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Método de pago <span class="text-danger">*</span></label>
                                <select name="payment_method" class="form-select" required>
                                    @foreach(\App\Models\Sale::PAYMENT_METHODS as $key => $label)
                                        <option value="{{ $key }}" @selected(old('payment_method', 'efectivo') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Cliente</label>
                                <input type="text" name="client_name" value="{{ old('client_name') }}" class="form-control" placeholder="Opcional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Fecha y hora</label>
                                <input type="datetime-local" name="sold_at" value="{{ old('sold_at', now()->format('Y-m-d\TH:i')) }}" class="form-control">
                            </div>
                        </div>

                        <div class="table-responsive mb-3 border rounded" style="min-height: 180px;">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th style="min-width:240px;">Producto</th>
                                    <th style="width:140px;">Precio</th>
                                    <th style="width:150px;">Cant.</th>
                                    <th class="text-end" style="width:140px;">Subtotal</th>
                                    <th style="width:50px;"></th>
                                </tr>
                                </thead>
                                <tbody id="posItemsBody">
                                    <tr id="posEmptyRow"><td colspan="5" class="text-center text-muted py-4">Agrega productos desde <strong>Productos disponibles</strong>.</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="total-row mb-3">
                            <span>Total</span>
                            <strong id="posTotal">$0</strong>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Notas</label>
                            <textarea name="notes" rows="2" class="form-control" placeholder="Opcional">{{ old('notes') }}</textarea>
                        </div>

                        <button class="btn btn-gold w-100" @disabled($products->isEmpty())><i class="bi bi-check2-circle me-1"></i> Registrar venta</button>
                    </form>
                    <div class="form-text mt-3"><i class="bi bi-info-circle me-1"></i> La venta descuenta stock automáticamente y se suma a los ingresos del negocio (ventas, caja, informes y comprobantes).</div>
                </div>
            </div>


        </div>
    </div>

    <template id="productRowTpl">
        <tr>
            <td>
                <select name="__NAME__[product_id]" class="form-select form-select-sm pos-product" required>
                    <option value="">Selecciona un producto</option>
                    @foreach($products as $p)
                        <option value="{{ $p->id }}" data-price="{{ $p->sale_price }}" data-stock="{{ $p->stock }}" data-name="{{ e($p->name) }}" data-ref="{{ e($p->reference ?? '') }}">{{ $p->name }}@if($p->category) — {{ $p->category }}@endif (Stock: {{ $p->stock }})</option>
                    @endforeach
                </select>
                <div class="small text-muted mt-1 pos-stock-hint"></div>
            </td>
            <td><input type="number" name="__NAME__[price]" class="form-control form-control-sm pos-price" min="0" step="0.01" required></td>
            <td><input type="number" name="__NAME__[quantity]" class="form-control form-control-sm pos-qty text-center" min="1" max="9999" step="1" value="1" required style="min-width: 90px;"></td>
            <td class="text-end fw-semibold pos-subtotal">$0</td>
            <td><button type="button" class="btn btn-sm btn-outline-danger pos-remove btn-icon" title="Quitar" aria-label="Quitar"><i class="bi bi-x"></i></button></td>
        </tr>
    </template>
@endsection

@push('scripts')
<script>
(() => {
    const body = document.getElementById('posItemsBody');
    const totalEl = document.getElementById('posTotal');
    const tpl = document.getElementById('productRowTpl')?.innerHTML || '';
    const addBtn = document.getElementById('addProductRow');
    if (!body || !totalEl || !tpl) return;
    let idx = 0;

    const fmt = (n) => '$' + Number(n || 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });

    const norm = (s) => (s || '').toString().trim().toLowerCase();
    const p3 = (s) => norm(s).slice(0, 3);
    function matchesProduct(el, q) {
        if (!q) return true;
        const k = q.slice(0, 3);
        const n = p3(el.dataset.name), r = p3(el.dataset.ref);
        if (q.length >= 3) return n === k || r === k;
        return n.startsWith(q) || r.startsWith(q);
    }
    function filterProducts() {
        const q = norm(document.getElementById('posProductSearch')?.value || '');
        const rows = document.querySelectorAll('.pos-available-row');
        let visible = 0;
        rows.forEach((row) => {
            const show = matchesProduct(row, q);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        const noMatch = document.getElementById('posAvailableNoMatch');
        if (noMatch) noMatch.style.display = (rows.length && visible === 0) ? '' : 'none';

        document.querySelectorAll('select.pos-product').forEach((sel) => {
            sel.querySelectorAll('option').forEach((opt) => {
                if (!opt.value) { opt.hidden = false; return; }
                opt.hidden = !matchesProduct(opt, q);
            });
        });
    }
    document.getElementById('posProductSearch')?.addEventListener('input', filterProducts);

    function toggleEmptyRow() {
        const empty = document.getElementById('posEmptyRow');
        const hasItems = body.querySelectorAll('tr:not(#posEmptyRow)').length > 0;
        if (empty) empty.style.display = hasItems ? 'none' : '';
    }

    function recalc() {
        let total = 0;
        body.querySelectorAll('tr:not(#posEmptyRow)').forEach((tr) => {
            const price = parseFloat(tr.querySelector('.pos-price')?.value || 0);
            const qty = parseInt(tr.querySelector('.pos-qty')?.value || 0, 10);
            const sub = Math.round((price * qty) * 100) / 100;
            total += sub;
            const cell = tr.querySelector('.pos-subtotal');
            if (cell) cell.textContent = fmt(sub);
        });
        totalEl.textContent = fmt(total);
        toggleEmptyRow();
    }

    function addRow(productId = '', quantity = 1) {
        const html = tpl.replaceAll('__NAME__', `items[${idx++}]`);
        body.insertAdjacentHTML('beforeend', html);
        const tr = body.lastElementChild;
        const sel = tr.querySelector('.pos-product');
        const priceInput = tr.querySelector('.pos-price');
        const qtyInput = tr.querySelector('.pos-qty');
        const hint = tr.querySelector('.pos-stock-hint');

        const sync = () => {
            const opt = sel?.selectedOptions[0];
            if (opt && opt.value) {
                if (priceInput && (!priceInput.value || priceInput.dataset.autofill === '1')) {
                    priceInput.value = opt.dataset.price || 0;
                    priceInput.dataset.autofill = '1';
                }
                if (qtyInput) qtyInput.max = opt.dataset.stock || 9999;
                if (hint) hint.textContent = `Disponible: ${opt.dataset.stock || 0}`;
            } else {
                if (hint) hint.textContent = '';
            }
            recalc();
        };

        sel?.addEventListener('change', () => { if (priceInput) { priceInput.value = ''; priceInput.dataset.autofill = '1'; } sync(); });
        priceInput?.addEventListener('input', () => { priceInput.dataset.autofill = '0'; recalc(); });
        qtyInput?.addEventListener('input', recalc);
        tr.querySelector('.pos-remove')?.addEventListener('click', () => { tr.remove(); recalc(); });

        if (productId && sel) {
            sel.value = String(productId);
            if (priceInput) { priceInput.value = ''; priceInput.dataset.autofill = '1'; }
            if (qtyInput) qtyInput.value = Math.max(1, parseInt(quantity || 1, 10));
        }
        sync();
        filterProducts();
        toggleEmptyRow();
        return tr;
    }

    addBtn?.addEventListener('click', () => { addRow(); });
    filterProducts();

    document.querySelectorAll('.pos-add-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const row = btn.closest('.pos-available-row');
            const id = btn.dataset.id;
            const stock = parseInt(row?.dataset.stock || btn.dataset.stock || 0, 10);
            const qtyInputAvail = row?.querySelector('.pos-add-qty');
            let qty = parseInt(qtyInputAvail?.value || 1, 10);
            if (!id) return;
            if (stock < 1) { alert('Este producto no tiene stock disponible.'); return; }
            if (!Number.isFinite(qty) || qty < 1) qty = 1;
            if (qty > stock) { qty = stock; if (qtyInputAvail) qtyInputAvail.value = stock; }

            // Si el producto ya está en la venta, sumar cantidad en la misma fila
            let existing = null;
            body.querySelectorAll('tr').forEach((tr) => {
                const sel = tr.querySelector('select.pos-product');
                if (sel && sel.value === String(id)) existing = tr;
            });
            if (existing) {
                const qtyInput = existing.querySelector('.pos-qty');
                const max = parseInt(qtyInput?.max || stock || 9999, 10);
                const current = parseInt(qtyInput?.value || 0, 10) || 0;
                const next = Math.min(current + qty, max);
                if (qtyInput) qtyInput.value = next;
                recalc();
            } else {
                addRow(id, qty);
            }

            if (qtyInputAvail) qtyInputAvail.value = 1;
        });
    });

    document.getElementById('posForm')?.addEventListener('submit', (e) => {
        if (body.querySelectorAll('tr:not(#posEmptyRow)').length === 0) {
            e.preventDefault();
            alert('Agrega al menos un producto a la venta.');
        }
    });
})();
</script>
@endpush
