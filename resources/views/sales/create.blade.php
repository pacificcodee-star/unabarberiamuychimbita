@extends('layouts.app')

@section('title', 'Tomar servicio')

@section('actions')
    <a href="{{ route('sales.index') }}" class="btn btn-outline-dark">
        <i class="bi bi-arrow-left me-1"></i> Volver
    </a>
@endsection

@section('content')
    @php
        $oldItems = old('items', [['service_id' => '', 'price' => '', 'quantity' => 1]]);
        $nextIndex = count($oldItems);
    @endphp

    <form method="POST" action="{{ route('sales.store') }}" id="saleForm">
        @csrf
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-person-badge me-1 text-warning"></i> Datos del servicio</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Barbero <span class="text-danger">*</span></label>
                                <select name="barber_id" class="form-select" required>
                                    <option value="">Selecciona el barbero</option>
                                    @foreach($barbers as $barber)
                                        <option value="{{ $barber->id }}" @selected(old('barber_id', auth()->id()) == $barber->id)>
                                            {{ $barber->name }} · {{ $barber->attentionModeLabel() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Cliente</label>
                                <input type="text" name="client_name" value="{{ old('client_name') }}" class="form-control" placeholder="Cliente ocasional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Método de pago <span class="text-danger">*</span></label>
                                <select name="payment_method" class="form-select" required>
                                    @foreach(\App\Models\Sale::PAYMENT_METHODS as $key => $label)
                                        <option value="{{ $key }}" @selected(old('payment_method', 'efectivo') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Fecha y hora</label>
                                <input type="datetime-local" name="sold_at" value="{{ old('sold_at', now()->format('Y-m-d\TH:i')) }}" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Notas</label>
                                <textarea name="notes" rows="2" class="form-control" placeholder="Observaciones del servicio (opcional)">{{ old('notes') }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-scissors me-1 text-warning"></i> Servicios realizados</span>
                        <button type="button" class="btn btn-sm btn-outline-dark" id="addItem">
                            <i class="bi bi-plus-lg me-1"></i> Agregar servicio
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                            <tr>
                                <th style="min-width: 220px;">Servicio</th>
                                <th style="min-width: 130px;">Precio</th>
                                <th style="width: 100px;">Cantidad</th>
                                <th class="text-end">Subtotal</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody id="itemsBody">
                            @foreach($oldItems as $index => $item)
                                <tr class="item-row">
                                    <td>
                                        <select name="items[{{ $index }}][service_id]" class="form-select service-select" required>
                                            <option value="">Selecciona un servicio</option>
                                            @foreach($services as $service)
                                                <option value="{{ $service->id }}" data-price="{{ $service->price }}" @selected(($item['service_id'] ?? '') == $service->id)>
                                                    {{ $service->name }} · {{ format_money($service->price) }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="items[{{ $index }}][price]" class="form-control price-input" value="{{ $item['price'] ?? '' }}" min="0" step="0.01" required>
                                    </td>
                                    <td>
                                        <input type="number" name="items[{{ $index }}][quantity]" class="form-control quantity-input" value="{{ $item['quantity'] ?? 1 }}" min="1" max="20" required>
                                    </td>
                                    <td class="text-end fw-semibold subtotal">{{ format_money(0) }}</td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-item" title="Quitar">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card position-sticky" style="top: 20px;">
                    <div class="card-header"><i class="bi bi-calculator me-1 text-warning"></i> Resumen</div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-muted">Total del servicio</span>
                            <span class="fs-2 fw-bold" id="saleTotal" style="font-family: 'Playfair Display', serif;">{{ format_money(0) }}</span>
                        </div>
                        <div class="small text-muted mb-4">
                            La comisión del barbero se calcula sola, servicio por servicio, con el % que le pusiste en Barberos.
                        </div>
                        <button type="submit" class="btn btn-gold w-100 btn-lg">
                            <i class="bi bi-check2-circle me-1"></i> Registrar servicio
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <template id="itemTemplate">
        <tr class="item-row">
            <td>
                <select name="items[__INDEX__][service_id]" class="form-select service-select" required>
                    <option value="">Selecciona un servicio</option>
                    @foreach($services as $service)
                        <option value="{{ $service->id }}" data-price="{{ $service->price }}">
                            {{ $service->name }} · {{ format_money($service->price) }}
                        </option>
                    @endforeach
                </select>
            </td>
            <td>
                <input type="number" name="items[__INDEX__][price]" class="form-control price-input" min="0" step="0.01" required>
            </td>
            <td>
                <input type="number" name="items[__INDEX__][quantity]" class="form-control quantity-input" value="1" min="1" max="20" required>
            </td>
            <td class="text-end fw-semibold subtotal">{{ format_money(0) }}</td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-danger remove-item" title="Quitar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </td>
        </tr>
    </template>
@endsection

@push('scripts')
    <script>
        const itemsBody = document.getElementById('itemsBody');
        const itemTemplate = document.getElementById('itemTemplate');
        let nextIndex = {{ $nextIndex }};

        const money = (value) => new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            maximumFractionDigits: 0
        }).format(value || 0);

        function updateTotal() {
            let total = 0;
            itemsBody.querySelectorAll('.item-row').forEach((row) => {
                const price = parseFloat(row.querySelector('.price-input').value) || 0;
                const quantity = parseInt(row.querySelector('.quantity-input').value) || 0;
                total += price * quantity;
            });
            document.getElementById('saleTotal').textContent = money(total);
        }

        function updateRow(row) {
            const price = parseFloat(row.querySelector('.price-input').value) || 0;
            const quantity = parseInt(row.querySelector('.quantity-input').value) || 0;
            row.querySelector('.subtotal').textContent = money(price * quantity);
            updateTotal();
        }

        function bindRow(row) {
            const serviceSelect = row.querySelector('.service-select');
            const priceInput = row.querySelector('.price-input');

            serviceSelect.addEventListener('change', () => {
                const option = serviceSelect.selectedOptions[0];
                if (option && option.dataset.price !== undefined) {
                    priceInput.value = option.dataset.price;
                }
                updateRow(row);
            });

            row.querySelectorAll('.price-input, .quantity-input').forEach((input) => {
                input.addEventListener('input', () => updateRow(row));
            });

            row.querySelector('.remove-item').addEventListener('click', () => {
                if (itemsBody.querySelectorAll('.item-row').length > 1) {
                    row.remove();
                    updateTotal();
                }
            });

            if (!priceInput.value) {
                const option = serviceSelect.selectedOptions[0];
                if (option && option.dataset.price !== undefined && option.value) {
                    priceInput.value = option.dataset.price;
                }
            }

            updateRow(row);
        }

        itemsBody.querySelectorAll('.item-row').forEach(bindRow);

        document.getElementById('addItem').addEventListener('click', () => {
            const html = itemTemplate.innerHTML.replaceAll('__INDEX__', nextIndex++);
            itemsBody.insertAdjacentHTML('beforeend', html);
            bindRow(itemsBody.lastElementChild);
        });
    </script>
@endpush
