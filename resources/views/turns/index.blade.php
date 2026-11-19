@extends('layouts.app')

@section('title', 'Turnos y citas')

@section('actions')
    <form method="POST" action="{{ route('turns.call-next') }}" class="d-flex align-items-center gap-2">
        @csrf
        @if(auth()->user()->isAdmin())
            <select name="barber_id" class="form-select form-select-sm" style="min-width: 190px;" required>
                <option value="">Barbero para llamar</option>
                @foreach($barbers->where('attention_mode', 'llegada') as $barber)
                    <option value="{{ $barber->id }}" @selected(request('barber_id') == $barber->id)>{{ $barber->name }}</option>
                @endforeach
            </select>
        @endif
        <button class="btn btn-gold" type="submit">
            <i class="bi bi-megaphone me-1"></i> Llamar siguiente
        </button>
    </form>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('turns.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Fecha</label>
                    <input type="date" name="date" value="{{ $date }}" class="form-control">
                </div>
                @if(auth()->user()->isAdmin())
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label small text-muted mb-1">Barbero</label>
                        <select name="barber_id" class="form-select">
                            <option value="">Todos</option>
                            @foreach($barbers as $barber)
                                <option value="{{ $barber->id }}" @selected(request('barber_id') == $barber->id)>{{ $barber->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Estado</label>
                    <select name="status" class="form-select">
                        <option value="">Todos</option>
                        @foreach(\App\Models\Turn::STATUSES as $key => $label)
                            <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-dark"><i class="bi bi-funnel me-1"></i> Filtrar</button>
                </div>
                <div class="col-auto">
                    <a href="{{ route('turns.index') }}" class="btn btn-outline-dark">Hoy</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-2 mb-3">
        @foreach(\App\Models\Turn::STATUSES as $key => $label)
            <div class="col-6 col-lg-3">
                <div class="card py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted">{{ $label }}</span>
                        <span class="badge-soft {{ $key === 'cancelado' ? 'red' : ($key === 'atendido' ? 'green' : ($key === 'atendiendo' ? 'blue' : 'gold')) }}">{{ $counts[$key] }}</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-person-plus me-1 text-warning"></i> Sacar turno o cita</span>
            <span class="small text-muted">Elige si el cliente va por orden de llegada o con cita</span>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('turns.store') }}" class="row g-2 align-items-end" data-turn-form>
                @csrf
                @php $typeOld = old('type', 'llegada'); @endphp
                <div class="col-12 mb-1">
                    <div class="btn-group" role="group" aria-label="Tipo de atención">
                        <input type="radio" class="btn-check" name="type" id="typeLlegada" value="llegada" @checked($typeOld === 'llegada')>
                        <label class="btn btn-outline-dark" for="typeLlegada"><i class="bi bi-list-ol me-1"></i> Por orden de llegada</label>
                        <input type="radio" class="btn-check" name="type" id="typeCita" value="cita" @checked($typeOld === 'cita')>
                        <label class="btn btn-outline-dark" for="typeCita"><i class="bi bi-calendar-event me-1"></i> Con cita</label>
                    </div>
                </div>
                @if(auth()->user()->isAdmin())
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Barbero</label>
                        <select name="barber_id" class="form-select" required data-barber-select>
                            <option value="">Selecciona</option>
                            @foreach($barbers as $barber)
                                {{-- La modalidad de cada barbero viene de la base de datos (Barberos → Modalidad de atención) --}}
                                <option value="{{ $barber->id }}" data-mode="{{ $barber->attention_mode === 'citas' ? 'cita' : 'llegada' }}" @selected(old('barber_id') == $barber->id)>{{ $barber->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text" data-barber-hint></div>
                    </div>
                @endif
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Cliente</label>
                    <input type="text" name="client_name" class="form-control" required maxlength="120" placeholder="Nombre del cliente">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Teléfono</label>
                    <input type="text" name="client_phone" class="form-control" maxlength="30" placeholder="Opcional">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">Servicio</label>
                    <select name="service_id" class="form-select">
                        <option value="">Por definir</option>
                        @foreach($services as $service)
                            <option value="{{ $service->id }}">{{ $service->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2" data-cita-field @if($typeOld !== 'cita') hidden @endif>
                    <label class="form-label small text-muted mb-1">Fecha de la cita</label>
                    <input type="date" name="appointment_date" value="{{ old('appointment_date', $date >= now()->toDateString() ? $date : now()->toDateString()) }}" min="{{ now()->toDateString() }}" class="form-control">
                </div>
                <div class="col-md-2" data-cita-field @if($typeOld !== 'cita') hidden @endif>
                    <label class="form-label small text-muted mb-1">Hora</label>
                    <input type="time" name="appointment_time" value="{{ old('appointment_time') }}" step="900" class="form-control">
                </div>
                <div class="col-auto">
                    <button class="btn btn-dark"><i class="bi bi-plus-lg me-1"></i> <span data-submit-label>{{ $typeOld === 'cita' ? 'Agendar cita' : 'Dar turno' }}</span></button>
                </div>
            </form>
        </div>
    </div>

    @if($appointments->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-event me-1 text-warning"></i> Citas de este día</span>
                <a href="{{ route('appointments.index', ['date' => $date]) }}" class="small">Gestionar en Citas →</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Hora</th><th>Cliente</th><th>Servicio</th><th>Barbero</th><th>Estado</th></tr></thead>
                    <tbody>
                    @foreach($appointments as $appointment)
                        <tr>
                            <td class="fw-semibold text-nowrap">{{ \Carbon\Carbon::parse($appointment->appointment_time)->format('h:i A') }}</td>
                            <td>{{ $appointment->client_name }}@if($appointment->client_phone)<div class="small text-muted">{{ $appointment->client_phone }}</div>@endif</td>
                            <td>{{ $appointment->service?->name ?? 'Por definir' }}</td>
                            <td>{{ $appointment->barber?->name }}</td>
                            <td><span class="badge-soft {{ $appointment->status === 'atendida' ? 'green' : ($appointment->status === 'cancelada' ? 'red' : '') }}">{{ $appointment->statusLabel() }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card mb-2"><div class="card-body py-2 small fw-semibold"><i class="bi bi-list-ol text-warning me-1"></i> Turnos por orden de llegada</div></div>
    <div class="card">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                <tr>
                    <th>Turno</th>
                    <th>Hora</th>
                    <th>Cliente</th>
                    <th>Servicio</th>
                    <th>Barbero</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse($turns as $turn)
                    <tr>
                        <td class="fw-bold fs-5">#{{ $turn->turn_number }}</td>
                        <td>{{ $turn->created_at->format('h:i A') }}</td>
                        <td>
                            <div class="fw-semibold">{{ $turn->client_name }}</div>
                            <div class="small text-muted">{{ $turn->client_phone ?: 'Sin teléfono' }}</div>
                        </td>
                        <td>{{ $turn->service?->name ?? 'Por definir' }}</td>
                        <td>{{ $turn->barber?->name }}</td>
                        <td>
                            @if(in_array($turn->status, ['atendida', 'atendido'], true))
                                <span class="badge-soft green"><i class="bi bi-lock-fill me-1"></i>{{ \App\Models\Turn::STATUSES['atendido'] }}</span>
                                @if($turn->sale)
                                    <div class="small text-success mt-1"><i class="bi bi-cash-coin"></i> Generó ingreso: <strong>{{ format_money($turn->sale->total) }}</strong> · {{ $turn->sale->paymentLabel() }}</div>
                                @else
                                    <form method="POST" action="{{ route('turns.status', $turn) }}" class="mt-1">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $turn->status }}">
                                        <div class="small text-danger mb-1"><i class="bi bi-exclamation-circle"></i> Sin ingreso registrado</div>
                                        <div class="input-group input-group-sm">
                                            <select name="payment_method" class="form-select" aria-label="Medio de pago">
                                                @foreach(\App\Models\Sale::PAYMENT_METHODS as $pm => $pmLabel)<option value="{{ $pm }}">{{ $pmLabel }}</option>@endforeach
                                            </select>
                                            <button class="btn btn-gold" title="Registrar el ingreso de este servicio">Registrar</button>
                                        </div>
                                    </form>
                                @endif
                            @else
                                <form method="POST" action="{{ route('turns.status', $turn) }}">
                                    @csrf
                                    @method('PATCH')
                                    <select name="status" class="form-select form-select-sm" onchange="attendedChange(this)" style="min-width: 130px;">
                                        @foreach(\App\Models\Turn::STATUSES as $key => $label)
                                            <option value="{{ $key }}" @selected($turn->status === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <select name="payment_method" class="form-select form-select-sm mt-1 attended-pay" aria-label="Medio de pago" title="¿Cómo pagó el cliente?" hidden>
                                        @foreach(\App\Models\Sale::PAYMENT_METHODS as $pm => $pmLabel)<option value="{{ $pm }}">{{ $pmLabel }}</option>@endforeach
                                    </select>
                                </form>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            @if($turn->status === 'esperando')
                                <form method="POST" action="{{ route('turns.status', $turn) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="atendiendo">
                                    <button class="btn btn-sm btn-outline-dark" title="Llamar este turno"><i class="bi bi-megaphone"></i></button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('turns.destroy', $turn) }}" class="d-inline" onsubmit="return confirm('{{ $turn->sale_id ? '¿Eliminar este turno? También se borrará el ingreso que generó.' : '¿Eliminar este turno?' }}');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="bi bi-list-ol"></i>
                                No hay turnos para esta fecha.
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Sacar turno o cita: muestra fecha y hora solo cuando es cita.
    (() => {
        const form = document.querySelector('[data-turn-form]');
        if (!form) return;
        const sync = () => {
            const isCita = form.querySelector('input[name="type"]:checked')?.value === 'cita';
            form.querySelectorAll('[data-cita-field]').forEach((el) => { el.hidden = !isCita; el.querySelector('input').required = isCita; });
            form.querySelector('[data-submit-label]').textContent = isCita ? 'Agendar cita' : 'Dar turno';
            // Solo los barberos de esa modalidad (según la base de datos).
            const select = form.querySelector('[data-barber-select]');
            if (select) {
                const mode = isCita ? 'cita' : 'llegada';
                let visible = 0;
                [...select.options].forEach((opt) => {
                    if (!opt.value) return;
                    const ok = opt.dataset.mode === mode;
                    opt.hidden = !ok; opt.disabled = !ok;
                    if (ok) visible++;
                    if (!ok && opt.selected) select.value = '';
                });
                const hint = form.querySelector('[data-barber-hint]');
                if (hint) hint.textContent = visible ? (isCita ? 'Barberos que atienden con cita.' : 'Barberos que atienden por orden de llegada.') : (isCita ? 'No hay barberos activos que atiendan con cita.' : 'No hay barberos activos por orden de llegada.');
            }
        };
        form.querySelectorAll('input[name="type"]').forEach((r) => r.addEventListener('change', sync));
        sync();
    })();

    // Al marcar como finalizado se pregunta el medio de pago antes de registrar el ingreso.
    function attendedChange(select) {
        const form = select.form;
        const pay = form.querySelector('.attended-pay');
        if (['atendida', 'atendido'].includes(select.value) && pay) {
            // Mostrar el medio de pago y un botón para confirmar (así no se guarda por accidente).
            pay.hidden = false;
            if (!form.querySelector('.attended-confirm')) {
                const btn = document.createElement('button');
                btn.type = 'submit';
                btn.className = 'btn btn-sm btn-gold w-100 mt-1 attended-confirm';
                btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Confirmar finalizado';
                form.appendChild(btn);
            }
            pay.focus();
            return;
        }
        form.submit();
    }
</script>
@endpush
