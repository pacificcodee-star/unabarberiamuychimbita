@extends('layouts.app')

@section('title', $supply ? 'Editar insumo' : 'Nuevo insumo')

@section('content')
    <div class="row justify-content-center"><div class="col-lg-7">
        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" action="{{ $supply ? route('supplies.update', $supply) : route('supplies.store') }}" class="card">
            @csrf
            @if($supply) @method('PUT') @endif
            <div class="card-header"><i class="bi bi-box-seam me-1 text-warning"></i> {{ $supply ? 'Editar insumo' : 'Datos del insumo' }}</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold" for="supplyName">Nombre <span class="text-danger">*</span></label>
                        <input id="supplyName" type="text" name="name" value="{{ old('name', $supply->name ?? '') }}" class="form-control" maxlength="120" required placeholder="Ej. Cuchillas, talco, toallas">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold" for="supplyUnit">Presentación</label>
                        <input id="supplyUnit" type="text" name="unit" value="{{ old('unit', $supply->unit ?? '') }}" class="form-control" maxlength="40" placeholder="Ej. caja x 10, unidad">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="supplyCost">Valor por unidad <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text">$</span>
                            <input id="supplyCost" type="number" name="cost" value="{{ old('cost', isset($supply) ? (float) $supply->cost : '') }}" class="form-control" min="0" step="0.01" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="supplyPct">% general que gana el barbero <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input id="supplyPct" type="number" name="default_percentage" value="{{ old('default_percentage', isset($supply) ? (float) $supply->default_percentage : 10) }}" class="form-control" min="0" max="100" step="0.01" required>
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">Se usa si al barbero no le pusiste un % propio para este insumo.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="supplyNotes">Notas</label>
                        <textarea id="supplyNotes" name="notes" rows="2" class="form-control" maxlength="500" placeholder="Opcional">{{ old('notes', $supply->notes ?? '') }}</textarea>
                    </div>
                </div>
                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="supplyActive" name="active" value="1" @checked(old('active', $supply->active ?? true))>
                    <label class="form-check-label" for="supplyActive">Insumo activo (se puede entregar)</label>
                </div>
            </div>
            <div class="card-body border-top d-flex justify-content-end gap-2">
                <a href="{{ route('supplies.index') }}" class="btn btn-outline-dark">Cancelar</a>
                <button class="btn btn-gold"><i class="bi bi-check2-circle me-1"></i> {{ $supply ? 'Guardar cambios' : 'Registrar insumo' }}</button>
            </div>
        </form>
    </div></div>
@endsection
