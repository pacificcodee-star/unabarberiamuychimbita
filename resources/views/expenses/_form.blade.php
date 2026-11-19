@php($editing = $expense !== null)

<form method="POST" action="{{ $editing ? route('expenses.update', $expense) : route('expenses.store') }}">
    @csrf
    @if($editing)
        @method('PUT')
    @endif

    <div class="card">
        <div class="card-header">
            <i class="bi bi-wallet2 me-1 text-warning"></i>
            {{ $editing ? 'Editar gasto' : 'Datos del gasto' }}
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label fw-semibold">Categoría <span class="text-danger">*</span></label>
                    <select name="category" class="form-select" required>
                        <option value="">Selecciona una categoría</option>
                        @foreach(\App\Models\Expense::CATEGORIES as $key => $label)
                            <option value="{{ $key }}" @selected(old('category', $expense->category ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Fecha <span class="text-danger">*</span></label>
                    <input type="date" name="expense_date" value="{{ old('expense_date', isset($expense) ? $expense->expense_date->format('Y-m-d') : now()->toDateString()) }}" class="form-control" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Descripción <span class="text-danger">*</span></label>
                    <input type="text" name="description" value="{{ old('description', $expense->description ?? '') }}" class="form-control" required placeholder="Ej: Pago de arriendo de octubre">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Monto <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" name="amount" value="{{ old('amount', $expense->amount ?? '') }}" class="form-control" min="0.01" step="0.01" required>
                    </div>
                </div>
            </div>
            <div class="small text-muted mt-3">
                Si hay una caja abierta, este gasto se descontará automáticamente del efectivo esperado al cerrar el día.
            </div>
        </div>
        <div class="card-body border-top d-flex justify-content-end gap-2">
            <a href="{{ route('expenses.index') }}" class="btn btn-outline-dark">Cancelar</a>
            <button class="btn btn-gold">
                <i class="bi bi-check2-circle me-1"></i> {{ $editing ? 'Guardar cambios' : 'Registrar gasto' }}
            </button>
        </div>
    </div>
</form>
