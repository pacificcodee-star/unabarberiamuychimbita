@extends('layouts.app')

@section('title', 'Nómina · '.$barber->name)


@section('content')
    {{-- Filtro de fechas --}}
    <div class="card mb-3 no-print">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Rangos rápidos">
                @foreach(['todo' => 'Todo', 'hoy' => 'Hoy', 'semana' => 'Esta semana', 'quincena' => 'Esta quincena', 'mes' => 'Este mes', 'mes-pasado' => 'Mes pasado'] as $key => $label)
                    <a href="{{ route('commissions.show', [$barber, 'rango' => $key, 'embed' => request()->boolean('embed') ? 1 : null]) }}" class="btn btn-sm {{ $preset === $key ? 'btn-gold' : 'btn-outline-dark' }}">{{ $label }}</a>
                @endforeach
            </div>
            <form method="GET" action="{{ route('commissions.show', $barber) }}" class="row g-2 align-items-end">
                @if(request()->boolean('embed'))<input type="hidden" name="embed" value="1">@endif
                <div class="col-sm-4 col-md-3">
                    <label class="form-label small text-muted mb-1" for="desde">Desde</label>
                    <input type="date" id="desde" name="desde" value="{{ $from->toDateString() }}" class="form-control" required>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label small text-muted mb-1" for="hasta">Hasta</label>
                    <input type="date" id="hasta" name="hasta" value="{{ $to->toDateString() }}" class="form-control" required>
                </div>
                <div class="col-auto">
                    <button class="btn btn-dark"><i class="bi bi-funnel me-1"></i> Filtrar</button>
                </div>
                <div class="col-auto ms-auto">
                    <button type="button" class="btn btn-outline-dark" onclick="window.print()"><i class="bi bi-printer me-1"></i> Imprimir</button>
                </div>
            </form>
            @if($errors->any())<div class="text-danger small mt-2">{{ $errors->first() }}</div>@endif
        </div>
    </div>

    <div class="mb-3">
        <h1 class="h4 mb-1">{{ $barber->name }}</h1>
        <div class="text-muted small">
            @if($preset === 'todo')
                <strong>Todos los servicios realizados</strong> (desde el {{ $from->translatedFormat('d \d\e F Y') }}) — coincide con la tabla de Nómina.
            @else
                Del <strong>{{ $from->translatedFormat('d \d\e F Y') }}</strong> al <strong>{{ $to->translatedFormat('d \d\e F Y') }}</strong>
            @endif
        </div>
    </div>

    {{-- Resumen del período --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3 h-100">
                <div class="label">Producción por servicios</div>
                <div class="value fs-3">{{ format_money($earnedServices) }}</div>
                <div class="hint">{{ $items->sum('quantity') }} servicios realizados</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3 h-100">
                <div class="label">+ Producción por insumos</div>
                <div class="value fs-3">{{ format_money($earnedSupplies) }}</div>
                <div class="hint">{{ $supplies->sum('quantity') }} insumos</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3 h-100" style="border-color: rgba(160,46,37,.45);">
                <div class="label">= Total acumulado en el período</div>
                <div class="value fs-3">{{ format_money($totalEarned) }}</div>
                <div class="hint">Abonado en el período: {{ format_money($paidInRange) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card stat-card py-3 h-100">
                <div class="label">Saldo pendiente total</div>
                <div class="value fs-3 {{ $pendingAll > 0 ? 'text-danger' : 'text-success' }}">{{ format_money($pendingAll) }}</div>
                <div class="hint">Todo lo pendiente, sin importar las fechas</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-scissors me-1 text-warning"></i> Resumen por servicio</div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>Servicio realizado</th><th class="text-end">Veces</th><th class="text-end">Valor cobrado</th><th class="text-end">Ganó</th></tr></thead>
                        <tbody>
                        @forelse($byService as $row)
                            <tr><td>{{ $row->name }}</td><td class="text-end">{{ $row->qty }}</td><td class="text-end text-muted">{{ format_money($row->charged) }}</td><td class="text-end fw-semibold">{{ format_money($row->earned) }}</td></tr>
                        @empty
                            <tr><td colspan="4"><div class="empty-state py-3"><i class="bi bi-scissors"></i> No realizó servicios en estas fechas.</div></td></tr>
                        @endforelse
                        </tbody>
                        @if($byService->isNotEmpty())
                            <tfoot><tr class="fw-bold"><td>Total</td><td class="text-end">{{ $byService->sum('qty') }}</td><td class="text-end">{{ format_money($byService->sum('charged')) }}</td><td class="text-end">{{ format_money($earnedServices) }}</td></tr></tfoot>
                        @endif
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-list-ul me-1 text-warning"></i> Servicio por servicio</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th>Fecha</th><th>Cliente</th><th>Servicio</th><th class="text-end">Valor</th><th class="text-end">%</th><th class="text-end">Ganó</th></tr></thead>
                        <tbody>
                        @forelse($items as $item)
                            <tr>
                                <td class="text-nowrap small">{{ \Carbon\Carbon::parse($item->sold_at)->format('d/m/Y h:i A') }}</td>
                                <td class="small">{{ $item->client_name ?: '—' }}</td>
                                <td>{{ $item->service_name }}@if($item->quantity > 1) <span class="text-muted small">× {{ $item->quantity }}</span>@endif</td>
                                <td class="text-end text-muted">{{ format_money($item->subtotal) }}</td>
                                <td class="text-end small">{{ rtrim(rtrim(number_format((float) $item->percentage, 2, ',', '.'), '0'), ',') }}%</td>
                                <td class="text-end fw-semibold">{{ format_money($item->earned) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted small p-3">Sin servicios en estas fechas.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-box-seam me-1 text-warning"></i> Lo que ganó por insumos</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th>Fecha</th><th>Insumo</th><th class="text-end">Cant.</th><th class="text-end">Ganó</th></tr></thead>
                        <tbody>
                        @forelse($supplies as $supply)
                            <tr>
                                <td class="small text-nowrap">{{ $supply->delivered_at->format('d/m/Y') }}</td>
                                <td>{{ $supply->supply_name }} <span class="text-muted small">· {{ rtrim(rtrim(number_format((float) $supply->percentage, 2, ',', '.'), '0'), ',') }}%</span></td>
                                <td class="text-end">{{ $supply->quantity }}</td>
                                <td class="text-end fw-semibold">{{ format_money($supply->amount) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted small p-3">Sin insumos en estas fechas.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-cash-coin me-1 text-warning"></i> Pagos de nómina en el período</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th>Fecha</th><th>Notas</th><th class="text-end">Monto</th></tr></thead>
                        <tbody>
                        @forelse($payments as $payment)
                            <tr><td class="small text-nowrap">{{ $payment->paid_at->format('d/m/Y') }}</td><td class="small text-muted">{{ $payment->notes ?: '—' }}</td><td class="text-end fw-semibold text-success">{{ format_money($payment->amount) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="text-muted small p-3">Sin pagos en estas fechas.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-body border-top small">
                    <div class="d-flex justify-content-between"><span>Total acumulado en el período</span><strong>{{ format_money($totalEarned) }}</strong></div>
                    <div class="d-flex justify-content-between text-success"><span>− Abonado en el período</span><strong>{{ format_money($paidInRange) }}</strong></div>
                    <div class="d-flex justify-content-between border-top mt-2 pt-2 fs-6"><span>Diferencia del período</span><strong>{{ format_money($totalEarned - $paidInRange) }}</strong></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
<style>
    @media print {
        .sidebar, .no-print, .topbar, .app-topbar, header, nav, .btn { display: none !important; }
        .main, .app-main, main { margin: 0 !important; padding: 0 !important; }
        .card { break-inside: avoid; box-shadow: none !important; }
    }
</style>
@endpush
