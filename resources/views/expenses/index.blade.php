@extends('layouts.app')

@section('title', 'Gastos')

@section('actions')
    <a href="{{ route('expenses.create') }}" class="btn btn-gold">
        <i class="bi bi-plus-lg me-1"></i> Registrar gasto
    </a>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('expenses.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Desde</label>
                    <input type="date" name="from" value="{{ request('from') }}" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Hasta</label>
                    <input type="date" name="to" value="{{ request('to') }}" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Categoría</label>
                    <select name="category" class="form-select">
                        <option value="">Todas</option>
                        @foreach(\App\Models\Expense::CATEGORIES as $key => $label)
                            <option value="{{ $key }}" @selected(request('category') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-auto">
                    <button class="btn btn-dark"><i class="bi bi-funnel me-1"></i> Filtrar</button>
                </div>
                @if(request()->hasAny(['from', 'to', 'category']))
                    <div class="col-lg-auto">
                        <a href="{{ route('expenses.index') }}" class="btn btn-outline-dark">Limpiar</a>
                    </div>
                @endif
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <div class="label text-muted text-uppercase small fw-semibold">Total de gastos filtrados</div>
                <div class="fs-3 fw-bold" style="font-family: 'Playfair Display', serif;">{{ format_money($total) }}</div>
            </div>
            <div class="icon" style="width: 52px; height: 52px; border-radius: 14px; display: grid; place-items: center; background: rgba(192,57,43,.08); color: #a63225; font-size: 1.3rem;">
                <i class="bi bi-wallet2"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Categoría</th>
                    <th>Descripción</th>
                    <th>Registrado por</th>
                    <th class="text-end">Monto</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse($expenses as $expense)
                    <tr>
                        <td>{{ $expense->expense_date->format('d/m/Y') }}</td>
                        <td><span class="badge-soft">{{ $expense->categoryLabel() }}</span></td>
                        <td>{{ $expense->description }}</td>
                        <td class="text-muted small">{{ $expense->user?->name ?? '—' }}</td>
                        <td class="text-end fw-semibold text-danger">{{ format_money($expense->amount) }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('expenses.edit', $expense) }}" class="btn btn-sm btn-outline-dark"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="{{ route('expenses.destroy', $expense) }}" class="d-inline" onsubmit="return confirm('¿Eliminar este gasto?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="bi bi-wallet2"></i>
                                No hay gastos registrados para estos filtros.
                                <div class="mt-3"><a href="{{ route('expenses.create') }}" class="btn btn-gold">Registrar gasto</a></div>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($expenses->hasPages())
            <div class="card-body border-top">{{ $expenses->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
@endsection
