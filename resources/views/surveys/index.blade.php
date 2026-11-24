@extends('layouts.app')

@section('title', 'Satisfacción de clientes')

@section('content')
    @include('surveys._tabs')

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('surveys.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1" for="f-barber">Barbero</label>
                    <select name="barber_id" id="f-barber" class="form-select">
                        <option value="">Todos</option>
                        @foreach($barbers as $barber)<option value="{{ $barber->id }}" @selected(request('barber_id') == $barber->id)>{{ $barber->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1" for="f-service">Servicio</label>
                    <select name="service_id" id="f-service" class="form-select">
                        <option value="">Todos</option>
                        @foreach($services as $service)<option value="{{ $service->id }}" @selected(request('service_id') == $service->id)>{{ $service->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1" for="f-from">Desde</label>
                    <input type="date" name="from" id="f-from" value="{{ request('from') }}" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1" for="f-to">Hasta</label>
                    <input type="date" name="to" id="f-to" value="{{ request('to') }}" class="form-control">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1" for="f-status">Estado</label>
                    <select name="estado" id="f-status" class="form-select">
                        <option value="">Todos</option>
                        @foreach(\App\Models\SurveyInvitation::STATUSES as $key => $label)<option value="{{ $key }}" @selected(request('estado') === $key)>{{ $label }}</option>@endforeach
                        <option value="atencion" @selected(request('estado') === 'atencion')>Requieren atención</option>
                    </select>
                </div>
                <div class="col-lg-auto"><button class="btn btn-dark"><i class="bi bi-funnel me-1" aria-hidden="true"></i> Filtrar</button></div>
                @if(request()->hasAny(['barber_id', 'service_id', 'from', 'to', 'estado']))
                    <div class="col-lg-auto"><a href="{{ route('surveys.index') }}" class="btn btn-outline-dark">Limpiar</a></div>
                @endif
            </form>
        </div>
    </div>

    @php
        $fmt = fn ($v, $suffix = '') => $v === null ? '—' : ($suffix === '%' ? number_format($v, 0, ',', '.').'%' : number_format($v, 1, ',', '.').$suffix);
        $kpis = [
            ['Calificación promedio', $summary['avg_experience'] === null ? '—' : $fmt($summary['avg_experience']).' / 5', $summary['rated'] ? 'Con '.$summary['rated'].' respuestas' : 'Aún sin respuestas', 'bi-star'],
            ['Atención del barbero', $summary['avg_barber'] === null ? '—' : $fmt($summary['avg_barber']).' / 5', '', 'bi-person-check'],
            ['Encuestas respondidas', $summary['completed'], $summary['sent'] ? 'de '.$summary['sent'].' enviadas' : 'Ninguna enviada aún', 'bi-clipboard-check'],
            ['Tasa de respuesta', $fmt($summary['response_rate'], '%'), $summary['pending'].' pendientes', 'bi-reply'],
            ['Recomendarían', $fmt($summary['recommend_rate'], '%'), '', 'bi-hand-thumbs-up'],
            ['Opiniones públicas', $summary['public_reviews'], $summary['in_review'] ? $summary['in_review'].' en revisión' : '', 'bi-chat-quote'],
            ['Requieren atención', $summary['needs_attention'], 'Calificación baja o expectativa no cumplida', 'bi-exclamation-triangle'],
        ];
    @endphp
    <div class="row g-3 mb-3">
        @foreach($kpis as [$label, $value, $hint, $icon])
            <div class="col-6 col-xl-3">
                <div class="card stat-card {{ $label === 'Requieren atención' && $value ? 'tone-danger' : '' }}">
                    <div class="icon"><i class="bi {{ $icon }}" aria-hidden="true"></i></div>
                    <div class="label">{{ $label }}</div>
                    <div class="value">{{ $value }}</div>
                    @if($hint)<div class="hint">{{ $hint }}</div>@endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-3">
        @foreach([['Por barbero', $byBarber, $barbers], ['Por servicio', $byService, $services]] as [$heading, $rows, $names])
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header"><i class="bi {{ $heading === 'Por barbero' ? 'bi-people' : 'bi-scissors' }}" aria-hidden="true"></i> {{ $heading }}</div><div class="card-body">
                    @if($rows->isEmpty())
                        <div class="empty-state py-4"><i class="bi bi-inbox"></i>Todavía no hay respuestas con este filtro.</div>
                    @else
                        <table class="table table-sm mb-0">
                            <thead><tr><th scope="col">Nombre</th><th scope="col" class="text-end">Encuestas</th><th scope="col" class="text-end">Promedio</th></tr></thead>
                            <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    <td>{{ $names[$row['id']]->name ?? 'Eliminado' }}</td>
                                    <td class="text-end">{{ $row['count'] }}</td>
                                    <td class="text-end">@if($row['enough']){{ number_format($row['avg'], 1, ',', '') }} / 5 @else <span class="text-muted small" title="Se necesitan al menos {{ $minSample }} encuestas para comparar">Muestra insuficiente</span>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div></div>
            </div>
        @endforeach
    </div>

    @if($monthly->isNotEmpty())
        <div class="card"><div class="card-header"><i class="bi bi-graph-up" aria-hidden="true"></i> Evolución mensual</div><div class="card-body">
            <div class="d-flex flex-wrap gap-3 align-items-end" role="list" aria-label="Calificación promedio por mes">
                @foreach($monthly as $m)
                    <div role="listitem" class="text-center" style="min-width: 72px;">
                        <div style="height: 90px; display: flex; align-items: flex-end; justify-content: center;">
                            <div style="width: 28px; height: {{ max(6, $m['avg'] / 5 * 90) }}px; background: var(--ui-brand); border-radius: 6px 6px 0 0;" aria-hidden="true"></div>
                        </div>
                        <div class="fw-bold small">{{ number_format($m['avg'], 1, ',', '') }}</div>
                        <div class="small text-muted">{{ $m['label'] }} · {{ $m['count'] }}</div>
                    </div>
                @endforeach
            </div>
        </div></div>
    @endif

    <div class="card">
        <div class="card-header"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Encuestas enviadas</div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr><th scope="col">Atención</th><th scope="col">Cliente</th><th scope="col">Barbero · servicio</th><th scope="col">Estado</th><th scope="col">Experiencia</th><th scope="col"><span class="visually-hidden">Acciones</span></th></tr></thead>
                <tbody>
                @forelse($invitations as $invitation)
                    @php($session = $invitation->session)
                    @php($rating = $session?->answerFor('experiencia')?->value)
                    <tr>
                        <td>{{ $invitation->service_date->format('d/m/Y') }}</td>
                        <td>{{ $invitation->subject?->client_name ?? '—' }}</td>
                        <td>{{ $invitation->barber?->name ?? '—' }}@if($invitation->service) · <span class="text-muted">{{ $invitation->service->name }}</span>@endif</td>
                        <td><span class="badge text-bg-{{ ['completada' => 'success', 'enviada' => 'primary', 'programada' => 'secondary', 'cancelada' => 'light', 'vencida' => 'light'][$invitation->status] ?? 'light' }}">{{ \App\Models\SurveyInvitation::STATUSES[$invitation->status] ?? $invitation->status }}</span>
                            @if($session && $session->status === 'en_curso')<span class="small text-muted d-block">Empezada ({{ $session->answers->count() }} respuestas)</span>@endif</td>
                        <td>@if($rating){{ str_repeat('★', (int) $rating) }}<span class="text-muted">{{ str_repeat('★', 5 - (int) $rating) }}</span>@else — @endif</td>
                        <td class="text-end">@if($session)<a href="{{ route('surveys.show', $session) }}" class="btn btn-sm btn-outline-dark">Ver respuestas</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-clipboard"></i>Todavía no hay encuestas. Se crean solas cuando un servicio queda como atendido.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($invitations->hasPages())<div class="card-body border-top">{{ $invitations->links() }}</div>@endif
    </div>
@endsection
