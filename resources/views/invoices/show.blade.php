@extends('layouts.app')

@section('title', 'Comprobante #'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT))

@section('actions')
    <div class="page-actions no-print">
        <a href="{{ route('invoices.index') }}" class="btn btn-outline-dark">
            <i class="bi bi-arrow-left me-1"></i> Volver a comprobantes
        </a>
        <button type="button" class="btn btn-gold" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Imprimir
        </button>
    </div>
@endsection

@section('content')
    @php
        $siteSettings = \App\Models\SiteSetting::currentData();
        $logoUrl = !empty($siteSettings['logo_path'] ?? '') ? asset('storage/'.$siteSettings['logo_path']) : asset('images/logo-una-barberia.png');
    @endphp
    <div class="receipt">
        <div class="text-center mb-4">
            <div class="mb-2">
                <img src="{{ $logoUrl }}" alt="Logo" style="width: 72px; height: 72px; object-fit: contain;">
            </div>
            <h2 class="mb-1" style="font-size: 1.7rem;">Una Barberia Muy Chimbita</h2>
            <div class="text-muted small text-uppercase" style="letter-spacing:.08em;">Comprobante de venta</div>
            <div class="mt-2 fw-semibold fs-5">N° {{ str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT) }}</div>
        </div>

        <div class="row g-2 small border-top border-bottom py-3 mb-3">
            <div class="col-6">
                <div class="text-muted">Fecha de emisión</div>
                <div class="fw-semibold">{{ $sale->sold_at?->format('d/m/Y h:i A') }}</div>
            </div>
            <div class="col-6 text-end">
                <div class="text-muted">Método de pago</div>
                <div class="fw-semibold">{{ $sale->paymentLabel() }}</div>
            </div>
            <div class="col-6">
                <div class="text-muted">Cliente</div>
                <div class="fw-semibold">{{ $sale->client_name ?: 'Cliente ocasional' }}</div>
            </div>
            <div class="col-6 text-end">
                <div class="text-muted">Atendido por</div>
                <div class="fw-semibold">{{ $sale->barber?->name ?? 'Venta POS' }}</div>
            </div>
            @if($sale->cashSession)
                <div class="col-6">
                    <div class="text-muted">Caja</div>
                    <div class="fw-semibold">#{{ $sale->cashSession->id }}</div>
                </div>
            @endif
        </div>

        <table class="table table-sm mb-3">
            <thead>
            <tr>
                <th>{{ $sale->barber_id === null ? 'Producto' : 'Servicio / Descripción' }}</th>
                <th class="text-center">Cant.</th>
                <th class="text-end">Valor unit.</th>
                <th class="text-end">Subtotal</th>
            </tr>
            </thead>
            <tbody>
            @foreach($sale->items as $item)
                <tr>
                    <td>{{ $item->service_name }}</td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td class="text-end">{{ format_money($item->price) }}</td>
                    <td class="text-end">{{ format_money($item->subtotal) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="d-flex justify-content-between align-items-center border-top pt-3">
            <span class="fs-5">Total</span>
            <span class="fs-3 fw-bold" style="font-family: 'Playfair Display', serif;">{{ format_money($sale->total) }}</span>
        </div>

        @if($sale->notes)
            <div class="small text-muted mt-3"><strong>Notas:</strong> {{ $sale->notes }}</div>
        @endif

        <div class="text-center small text-muted mt-4 pt-3 border-top">
            ¡Gracias por tu visita!<br>
            Una Barberia Muy Chimbita · {{ now()->year }}
        </div>
    </div>
@endsection

@if(request()->boolean('print'))
    @push('scripts')
        <script>window.addEventListener('load', () => window.print());</script>
    @endpush
@endif
