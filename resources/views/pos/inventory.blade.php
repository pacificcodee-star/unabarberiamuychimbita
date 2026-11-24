@extends('layouts.app')

@section('title', 'Inventario POS')

@section('actions')
    <a href="{{ route('pos.index') }}" class="btn btn-gold"><i class="bi bi-cart-check me-1"></i> Ir a venta POS</a>
@endsection

@section('content')
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
            <div class="card stat-card"><div class="icon"><i class="bi bi-box-seam"></i></div><div class="label">Productos</div><div class="value">{{ $stats['count'] }}</div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card stat-card"><div class="icon"><i class="bi bi-stack"></i></div><div class="label">Unidades en stock</div><div class="value">{{ $stats['stock'] }}</div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card stat-card tone-danger"><div class="icon"><i class="bi bi-exclamation-triangle"></i></div><div class="label">Stock bajo</div><div class="value {{ $stats['low'] > 0 ? 'text-danger' : '' }}">{{ $stats['low'] }}</div></div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card sticky-panel">
                <div class="card-header"><i class="bi bi-plus-circle me-1 text-warning"></i> Nuevo producto</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('pos.products.store') }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="Ej: Gaseosa, Papas, Agua" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Referencia / Código</label>
                            <input type="text" name="reference" class="form-control" placeholder="Opcional">
                        </div>
                        <div class="mb-2 category-picker">
                            <label class="form-label fw-semibold">Categoría</label>
                            <select class="form-select category-select">
                                <option value="">— Seleccionar categoría —</option>
                                @foreach(($categories ?? collect()) as $cat)
                                    <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                                <option value="__new__">+ Agregar nueva categoría...</option>
                            </select>
                            <input type="text" name="category" class="form-control mt-2 category-input" placeholder="Selecciona una categoría o escribe una nueva" value="">
                            <div class="form-text">Selecciona una categoría existente o elige “Agregar nueva categoría…” para escribirla. Se guarda al registrar el producto.</div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Precio venta <span class="text-danger">*</span></label>
                                <input type="number" name="sale_price" class="form-control" min="0" step="0.01" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Costo</label>
                                <input type="number" name="cost_price" class="form-control" min="0" step="0.01">
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Stock inicial <span class="text-danger">*</span></label>
                                <input type="number" name="stock" class="form-control" min="0" step="1" value="0" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Stock mínimo</label>
                                <input type="number" name="min_stock" class="form-control" min="0" step="1">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Fecha de caducidad</label>
                            <input type="date" name="expiry_date" class="form-control">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="active" value="1" id="newActive" checked>
                            <label class="form-check-label" for="newActive">Activo para venta</label>
                        </div>
                        <button class="btn btn-gold w-100"><i class="bi bi-check2-circle me-1"></i> Guardar producto</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-box-seam me-1 text-warning"></i> Inventario de productos (POS)</span>
                    <form method="GET" action="{{ route('pos.inventory') }}" class="d-flex gap-2">
                        <input type="text" name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="Buscar producto o referencia" style="min-width:220px;">
                        <button class="btn btn-sm btn-dark">Buscar</button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="text-end">Precio</th>
                            <th class="text-end">Costo</th>
                            <th class="text-end">Stock</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $product->name }}</div>
                                    @if($product->category)<div class="small text-muted">Categoría: {{ $product->category }}</div>@endif
                                    @if($product->reference)<div class="small text-muted">Ref: {{ $product->reference }}</div>@endif
                                    @if($product->min_stock !== null)<div class="small text-muted">Mín: {{ $product->min_stock }}</div>@endif
                                    @if($product->expiry_date)<div class="small text-muted">Caduca: {{ $product->expiry_date->format('d/m/Y') }}</div>@endif
                                </td>
                                <td class="text-end">{{ format_money($product->sale_price) }}</td>
                                <td class="text-end">{{ $product->cost_price !== null ? format_money($product->cost_price) : '—' }}</td>
                                <td class="text-end">
                                    <span class="badge-soft {{ $product->isLowStock() ? 'red' : ($product->stock == 0 ? 'red' : 'green') }}">{{ $product->stock }}</span>
                                </td>
                                <td><span class="badge-soft {{ $product->active ? 'green' : 'red' }}">{{ $product->active ? 'Activo' : 'Inactivo' }}</span></td>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                        <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#edit-{{ $product->id }}" title="Editar"><i class="bi bi-pencil"></i></button>
                                        <form method="POST" action="{{ route('pos.products.stock', $product) }}" class="d-inline-flex gap-1">
                                            @csrf
                                            <input type="number" name="adjustment" class="form-control form-control-sm" style="width:80px;" placeholder="± Cant" required>
                                            <button class="btn btn-sm btn-outline-dark btn-icon" title="Ajustar stock"><i class="bi bi-plus-slash-minus"></i></button>
                                        </form>
                                        <form method="POST" action="{{ route('pos.products.destroy', $product) }}" onsubmit="return confirm('¿Eliminar este producto del inventario?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger btn-icon" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr class="collapse" id="edit-{{ $product->id }}">
                                <td colspan="6" class="bg-light">
                                    <form method="POST" action="{{ route('pos.products.update', $product) }}" class="row g-2 align-items-end p-2">
                                        @csrf
                                        @method('PUT')
                                        <div class="col-md-2">
                                            <label class="form-label small mb-1">Nombre</label>
                                            <input type="text" name="name" class="form-control form-control-sm" value="{{ $product->name }}" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small mb-1">Referencia</label>
                                            <input type="text" name="reference" class="form-control form-control-sm" value="{{ $product->reference }}">
                                        </div>
                                        <div class="col-md-2 category-picker">
                                            <label class="form-label small mb-1">Categoría</label>
                                            <select class="form-select form-select-sm category-select">
                                                <option value="">— Seleccionar —</option>
                                                @foreach(($categories ?? collect()) as $cat)
                                                    <option value="{{ $cat }}" @selected($product->category === $cat)>{{ $cat }}</option>
                                                @endforeach
                                                @if($product->category && !($categories ?? collect())->contains($product->category))
                                                    <option value="{{ $product->category }}" selected>{{ $product->category }}</option>
                                                @endif
                                                <option value="__new__">+ Agregar nueva...</option>
                                            </select>
                                            <input type="text" name="category" class="form-control form-control-sm mt-1 category-input" value="{{ $product->category }}" placeholder="Nueva categoría">
                                        </div>
                                        <div class="col-md-1">
                                            <label class="form-label small mb-1">Precio venta</label>
                                            <input type="number" name="sale_price" class="form-control form-control-sm" min="0" step="0.01" value="{{ $product->sale_price }}" required>
                                        </div>
                                        <div class="col-md-1">
                                            <label class="form-label small mb-1">Costo</label>
                                            <input type="number" name="cost_price" class="form-control form-control-sm" min="0" step="0.01" value="{{ $product->cost_price }}">
                                        </div>
                                        <div class="col-md-1">
                                            <label class="form-label small mb-1">Stock</label>
                                            <input type="number" name="stock" class="form-control form-control-sm" min="0" step="1" value="{{ $product->stock }}" required>
                                        </div>
                                        <div class="col-md-1">
                                            <label class="form-label small mb-1">Mín</label>
                                            <input type="number" name="min_stock" class="form-control form-control-sm" min="0" step="1" value="{{ $product->min_stock }}">
                                        </div>
                                        <div class="col-md-1">
                                            <label class="form-label small mb-1">Caducidad</label>
                                            <input type="date" name="expiry_date" class="form-control form-control-sm" value="{{ optional($product->expiry_date)->format('Y-m-d') }}">
                                        </div>
                                        <div class="col-md-1">
                                            <div class="form-check mt-4">
                                                <input class="form-check-input" type="checkbox" name="active" value="1" id="act-{{ $product->id }}" @checked($product->active)>
                                                <label class="form-check-label small" for="act-{{ $product->id }}">Activo</label>
                                            </div>
                                        </div>
                                        <div class="col-md-1">
                                            <button class="btn btn-sm btn-gold w-100">Guardar</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><div class="empty-state py-4"><i class="bi bi-box-seam"></i> No hay productos en el inventario.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(() => {
    function initCategoryPicker(picker) {
        const select = picker.querySelector('.category-select');
        const input = picker.querySelector('.category-input');
        if (!select || !input) return;

        function syncFromSelect() {
            if (select.value === '__new__') {
                input.style.display = '';
                input.value = '';
                input.focus();
            } else {
                input.value = select.value || '';
                input.style.display = 'none';
            }
        }

        if (select.value === '__new__') {
            input.style.display = '';
        } else {
            if (input.value && select.value !== input.value) {
                const opt = Array.from(select.options).find(o => o.value === input.value);
                if (opt) select.value = opt.value; else { select.value = '__new__'; input.style.display = ''; }
            } else {
                input.style.display = 'none';
            }
        }

        select.addEventListener('change', syncFromSelect);

        const form = picker.closest('form');
        if (form) {
            form.addEventListener('submit', () => {
                if (select.value !== '__new__') {
                    input.value = select.value || '';
                }
            });
        }
    }

    document.querySelectorAll('.category-picker').forEach(initCategoryPicker);
})();
</script>
@endpush
