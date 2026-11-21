@extends('layouts.app')

@section('title', 'Citas')

@section('actions')
    <a href="{{ route('appointments.create', ['date' => $date]) }}" class="btn btn-gold">
        <i class="bi bi-calendar-plus me-1"></i> Nueva cita
    </a>
@endsection

@php
    $current = \Carbon\Carbon::parse($date);
    $today = now()->toDateString();
    $prevDate = $current->copy()->subWeek()->toDateString();
    $nextDate = $current->copy()->addWeek()->toDateString();
    $prevMonth = $current->copy()->subMonthNoOverflow()->startOfMonth();
    $nextMonth = $current->copy()->addMonthNoOverflow()->startOfMonth();
    $prevMonthDate = ($prevMonth->isSameMonth(now()) && $prevMonth->isSameYear(now())) ? $today : $prevMonth->toDateString();
    $nextMonthDate = ($nextMonth->isSameMonth(now()) && $nextMonth->isSameYear(now())) ? $today : $nextMonth->toDateString();

    $slotHeight = 48; // px por cada 30 min
    $pxPerMinute = $slotHeight / 30;
    $rangeMinutesStart = $hourStart * 60;
    $rangeMinutesEnd = $hourEnd * 60;
    $totalHeight = max(1, ($rangeMinutesEnd - $rangeMinutesStart)) * $pxPerMinute;
    $statusColors = [
        'pendiente' => ['bg' => '#efe9e8', 'border' => '#29251d', 'text' => '#29251d'],
        'confirmada' => ['bg' => '#dbeafe', 'border' => '#3b82f6', 'text' => '#1e3a8a'],
        'atendida' => ['bg' => '#dcfce7', 'border' => '#22c55e', 'text' => '#14532d'],
        'cancelada' => ['bg' => '#fee2e2', 'border' => '#ef4444', 'text' => '#7f1d1d'],
    ];
    $grouped = $appointments->groupBy(fn($a) => \Carbon\Carbon::parse($a->appointment_date)->toDateString());
    $queryBase = request()->except('date', 'view');
    $pickerBase = route('appointments.index', $queryBase);
    $pickerMonths = [];
    for ($m = 1; $m <= 12; $m++) {
        $pickerMonths[] = ['num' => $m, 'label' => \Carbon\Carbon::create()->month($m)->locale('es')->isoFormat('MMMM')];
    }
@endphp

@section('content')
    <div class="card mb-3 gc-toolbar">
        <div class="card-body py-2">
            {{-- Navegación semanal/mes-año removida del encabezado según solicitud --}}
            <form method="GET" action="{{ route('appointments.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Ir a fecha</label>
                    <input type="date" name="date" value="{{ $date }}" class="form-control form-control-sm">
                </div>
                @if(auth()->user()->isAdmin())
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label small text-muted mb-1">Barbero</label>
                        <select name="barber_id" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            @foreach($barbers as $barber)
                                <option value="{{ $barber->id }}" @selected(request('barber_id') == $barber->id)>{{ $barber->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small text-muted mb-1">Estado</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach(\App\Models\Appointment::STATUSES as $key => $label)
                            <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-dark btn-sm"><i class="bi bi-funnel me-1"></i> Aplicar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-2 mb-3" data-booking-region="counts">
        @foreach(\App\Models\Appointment::STATUSES as $key => $label)
            <div class="col-6 col-lg-3">
                <div class="card py-2 px-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted">{{ $label }}</span>
                        <span class="badge-soft {{ $key === 'cancelada' ? 'red' : ($key === 'atendida' ? 'green' : ($key === 'confirmada' ? 'blue' : 'gold')) }}">{{ $counts[$key] ?? 0 }}</span>
                    </div>

                </div>
            </div>
        @endforeach
    </div>

    <div class="d-flex align-items-center justify-content-center gap-3 mb-2">
        <a class="btn btn-outline-dark btn-sm" href="{{ route('appointments.index', array_merge($queryBase, ['date' => $prevMonthDate])) }}" title="Mes anterior" aria-label="Mes anterior"><i class="bi bi-chevron-left"></i></a>
        <div class="fw-semibold text-capitalize" style="min-width: 180px; text-align: center;">{{ $current->locale('es')->translatedFormat('F Y') }}</div>
        <a class="btn btn-outline-dark btn-sm" href="{{ route('appointments.index', array_merge($queryBase, ['date' => $nextMonthDate])) }}" title="Mes siguiente" aria-label="Mes siguiente"><i class="bi bi-chevron-right"></i></a>
    </div>

    <div class="card gc-calendar">
        <div class="gc-scroll">
            <div class="gc-grid" style="--days: {{ $days->count() }}; --slot-h: {{ $slotHeight }}px; --body-h: {{ $totalHeight }}px;">
                <div class="gc-header">
                    <div class="gc-gutter gc-head-corner"></div>
                    @foreach($days as $day)
                        @php $isToday = $day->isToday(); @endphp
                        <div class="gc-day-head {{ $isToday ? 'is-today' : '' }}">
                            <div class="gc-dow">{{ $day->locale('es')->isoFormat('ddd') }}</div>
                            <a class="gc-date {{ $isToday ? 'is-today' : '' }}" href="{{ route('appointments.index', array_merge($queryBase, ['date' => $day->toDateString()])) }}">{{ $day->day }}</a>
                        </div>
                    @endforeach
                </div>
                <div class="gc-body">
                    <div class="gc-gutter gc-times">
                        @for($h = $hourStart; $h < $hourEnd; $h++)
                            <div class="gc-time" style="height: {{ $slotHeight * 2 }}px;">
                                <span>{{ \Carbon\Carbon::createFromTime($h, 0)->format('g A') }}</span>
                            </div>
                        @endfor
                    </div>
                    @foreach($days as $day)
                        @php $dayKey = $day->toDateString(); $dayItems = $grouped->get($dayKey, collect()); @endphp
                        <div data-booking-region="day-{{ $dayKey }}" class="gc-day-col {{ $day->isToday() ? 'is-today' : '' }}" data-date="{{ $dayKey }}">
                            <div class="gc-slots">
                                @for($h = $hourStart; $h < $hourEnd; $h++)
                                    <div class="gc-slot" style="height: {{ $slotHeight }}px;"></div>
                                    <div class="gc-slot" style="height: {{ $slotHeight }}px;"></div>
                                @endfor
                                @if($day->isToday() && now()->hour >= $hourStart && now()->hour < $hourEnd)
                                    @php $nowTop = ((now()->hour * 60 + now()->minute) - $rangeMinutesStart) * $pxPerMinute; @endphp
                                    <div class="gc-now" style="top: {{ $nowTop }}px;"><span></span></div>
                                @endif
                            </div>
                            <div class="gc-events">
                                @foreach($dayItems as $appointment)
                                    @php
                                        $timeStr = substr((string) $appointment->appointment_time, 0, 5);
                                        [$hh, $mm] = array_map('intval', explode(':', $timeStr));
                                        $startMin = $hh * 60 + $mm;
                                        $duration = (int) ($appointment->service?->duration_minutes ?: 30);
                                        $top = max(0, ($startMin - $rangeMinutesStart) * $pxPerMinute);
                                        $height = max(22, $duration * $pxPerMinute - 2);
                                        $bottomLimit = $totalHeight - $top;
                                        $height = min($height, $bottomLimit);
                                        $colors = $statusColors[$appointment->status] ?? $statusColors['pendiente'];
                                        $canEdit = $appointment->status !== 'atendida';
                                        $editUrl = route('appointments.edit', $appointment);
                                        $deleteUrl = route('appointments.destroy', $appointment);
                                        $timeLabel = \Carbon\Carbon::createFromTime($hh, $mm)->format('h:i A');
                                        $dateLabel = \Carbon\Carbon::parse($appointment->appointment_date)->locale('es')->translatedFormat('l, d \\d\\e F');
                                        $barberName = $appointment->barber?->name ?? '—';
                                        $serviceName = $appointment->service?->name ?? 'Por definir';
                                        $statusLabel = \App\Models\Appointment::STATUSES[$appointment->status] ?? $appointment->status;
                                    @endphp
                                    @if($startMin >= $rangeMinutesStart && $startMin < $rangeMinutesEnd)
                                        <a href="{{ $canEdit ? $editUrl : '#' }}" class="gc-event status-{{ $appointment->status }}" style="top: {{ $top }}px; height: {{ $height }}px; --ev-bg: {{ $colors['bg'] }}; --ev-border: {{ $colors['border'] }}; --ev-text: {{ $colors['text'] }};"
                                           data-appt-trigger
                                           data-id="{{ $appointment->id }}"
                                           data-client="{{ e($appointment->client_name) }}"
                                           data-phone="{{ e($appointment->client_phone ?? '') }}"
                                           data-date="{{ e($dateLabel) }}"
                                           data-time="{{ e($timeLabel) }}"
                                           data-barber="{{ e($barberName) }}"
                                           data-service="{{ e($serviceName) }}"
                                           data-status="{{ e($statusLabel) }}"
                                           data-notes="{{ e($appointment->notes ?? '') }}"
                                           data-edit-url="{{ e($editUrl) }}"
                                           data-delete-url="{{ e($deleteUrl) }}"
                                           data-can-edit="{{ $canEdit ? '1' : '0' }}"
                                           title="{{ $appointment->client_name }} · {{ $timeLabel }}">
                                            <div class="gc-event-time">{{ $timeLabel }}</div>
                                            <div class="gc-event-title">{{ $appointment->client_name }}</div>
                                            <div class="gc-event-sub">{{ $appointment->service?->name ?? 'Cita' }}@if(auth()->user()->isAdmin() && $appointment->barber) · {{ $appointment->barber->name }}@endif</div>
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i> Haz clic en una cita para ver sus detalles, editarla o eliminarla. Usa las flechas de arriba para cambiar de mes.</div>

    {{-- Modal detalle de cita --}}
    <div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="appointmentModalTitle"><i class="bi bi-calendar-event text-warning me-1"></i> Detalle de la cita</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <dl class="row mb-0 small">
                        <dt class="col-4 text-muted">Cliente</dt><dd class="col-8 fw-semibold" data-appt-field="client"></dd>
                        <dt class="col-4 text-muted">Teléfono</dt><dd class="col-8" data-appt-field="phone"></dd>
                        <dt class="col-4 text-muted">Fecha</dt><dd class="col-8" data-appt-field="date"></dd>
                        <dt class="col-4 text-muted">Hora</dt><dd class="col-8" data-appt-field="time"></dd>
                        <dt class="col-4 text-muted">Barbero</dt><dd class="col-8" data-appt-field="barber"></dd>
                        <dt class="col-4 text-muted">Servicio</dt><dd class="col-8" data-appt-field="service"></dd>
                        <dt class="col-4 text-muted">Estado</dt><dd class="col-8" data-appt-field="status"></dd>
                        <dt class="col-4 text-muted">Notas</dt><dd class="col-8" data-appt-field="notes"></dd>
                    </dl>
                </div>
                <div class="modal-footer justify-content-between">
                    <form method="POST" data-appt-delete-form onsubmit="return confirm('¿Eliminar esta cita? Si ya generó un ingreso, también se eliminará ese registro.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i> Eliminar</button>
                    </form>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cerrar</button>
                        <a href="#" class="btn btn-gold" data-appt-edit><i class="bi bi-pencil me-1"></i> Editar</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
<style>
    /* Estilo tipo Google Calendar (vista semanal) */
    .gc-toolbar.card { border: 1px solid #dadce0; box-shadow: none; border-radius: 8px; }
    .gc-calendar { overflow: hidden; border: 1px solid #dadce0; border-radius: 8px; box-shadow: none; }
    .gc-scroll { overflow: auto; background: #fff; }
    .gc-grid { min-width: 980px; }
    .gc-header { display: grid; grid-template-columns: 56px repeat(var(--days), minmax(120px, 1fr)); border-bottom: 1px solid #dadce0; position: sticky; top: 0; background: #fff; z-index: 5; }
    .gc-gutter { width: 56px; }
    .gc-head-corner { border-right: 1px solid #dadce0; }
    .gc-day-head { text-align: center; padding: 8px 4px 10px; border-right: 1px solid #dadce0; }
    .gc-dow { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: #70757a; font-weight: 500; }
    .gc-day-head.is-today .gc-dow { color: #1a73e8; font-weight: 600; }
    .gc-date { display: inline-grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; text-decoration: none; color: #3c4043; font-weight: 400; font-size: 1.35rem; margin-top: 2px; line-height: 1; }
    .gc-date:hover { background: #f1f3f4; color: #3c4043; }
    .gc-date.is-today { background: #1a73e8; color: #fff; font-weight: 500; }
    .gc-body { display: grid; grid-template-columns: 56px repeat(var(--days), minmax(120px, 1fr)); }
    .gc-times { border-right: 1px solid #dadce0; background: #fff; }
    .gc-time { position: relative; padding-right: 8px; text-align: right; font-size: .65rem; color: #70757a; }
    .gc-time span { position: relative; top: -7px; background: #fff; padding-left: 2px; }
    .gc-day-col { position: relative; border-right: 1px solid #dadce0; min-height: var(--body-h); background: #fff; }
    .gc-day-col.is-today { background: #fff; }
    .gc-slots { position: absolute; inset: 0; }
    .gc-slot { border-bottom: 1px solid #f1f3f4; box-sizing: border-box; }
    .gc-slot:nth-child(even) { border-bottom-color: #dadce0; }
    .gc-events { position: absolute; inset: 0 6px 0 2px; }
    .gc-event { position: absolute; left: 2px; right: 2px; border-radius: 4px; padding: 2px 6px 2px 8px; text-decoration: none; color: #3c4043; background: var(--ev-bg, #e8f0fe); border: 0; border-left: 4px solid var(--ev-border, #1a73e8); box-shadow: none; overflow: hidden; font-size: .75rem; line-height: 1.25; z-index: 2; cursor: pointer; }
    .gc-event:hover { filter: brightness(.96); z-index: 3; }
    .gc-event-time { font-weight: 500; font-size: .7rem; color: inherit; opacity: .95; }
    .gc-event-title { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .gc-event-sub { opacity: .85; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: .7rem; }
    .gc-now { position: absolute; left: 0; right: 0; height: 2px; background: #ea4335; z-index: 4; pointer-events: none; }
    .gc-now span { position: absolute; left: -5px; top: -4px; width: 10px; height: 10px; border-radius: 50%; background: #ea4335; }
    .gc-range-title { text-transform: capitalize; color: #3c4043; font-weight: 400; }
    .gc-title-wrap { position: relative; }
    .gc-title-btn { display: inline-flex; align-items: center; gap: 6px; background: transparent; border: 0; padding: 6px 10px; border-radius: 8px; color: #3c4043; cursor: pointer; font-weight: 400; font-size: 1.25rem; }
    .gc-title-btn:hover, .gc-title-btn[aria-expanded="true"] { background: #f1f3f4; }
    .gc-title-caret { font-size: .7rem; color: #5f6368; }
    .gc-picker { position: absolute; top: calc(100% + 6px); left: 0; z-index: 20; width: 300px; background: #fff; border: 1px solid #dadce0; border-radius: 8px; box-shadow: 0 1px 3px rgba(60,64,67,.3), 0 4px 8px 3px rgba(60,64,67,.15); padding: 12px; }
    .gc-picker[hidden] { display: none; }
    .gc-picker-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
    .gc-picker-year { flex: 1; background: transparent; border: 0; font-weight: 500; font-size: 1rem; color: #3c4043; border-radius: 8px; padding: 4px 6px; }
    .gc-picker-year:hover { background: #f1f3f4; }
    .gc-picker-months { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; }
    .gc-pick-month { border: 0; background: transparent; padding: 8px 6px; border-radius: 18px; font-size: .85rem; font-weight: 500; color: #3c4043; text-transform: capitalize; }
    .gc-pick-month:hover { background: #f1f3f4; }
    .gc-pick-month.is-current { background: #1a73e8; color: #fff; }
    .gc-picker-foot { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding-top: 10px; border-top: 1px solid #dadce0; }

    @media (max-width: 767.98px) {
        .gc-grid { min-width: 860px; }
        .gc-title-btn { font-size: 1.05rem; }
    }
</style>
@endpush

@push('scripts')
<script>
    (() => {
        const modalEl = document.getElementById('appointmentModal');
        if (modalEl && window.bootstrap) {
            const modal = new bootstrap.Modal(modalEl);
            const deleteForm = modalEl.querySelector('[data-appt-delete-form]');
            const editBtn = modalEl.querySelector('[data-appt-edit]');

            document.addEventListener('click', (e) => {
                    const el = e.target.closest('[data-appt-trigger]');
                    if (!el) return;
                    e.preventDefault();
                    const d = el.dataset;
                    modalEl.querySelector('[data-appt-field="client"]').textContent = d.client || '—';
                    modalEl.querySelector('[data-appt-field="phone"]').textContent = d.phone || '—';
                    modalEl.querySelector('[data-appt-field="date"]').textContent = d.date || '—';
                    modalEl.querySelector('[data-appt-field="time"]').textContent = d.time || '—';
                    modalEl.querySelector('[data-appt-field="barber"]').textContent = d.barber || '—';
                    modalEl.querySelector('[data-appt-field="service"]').textContent = d.service || '—';
                    modalEl.querySelector('[data-appt-field="status"]').textContent = d.status || '—';
                    modalEl.querySelector('[data-appt-field="notes"]').textContent = d.notes || '—';

                    if (deleteForm) deleteForm.action = d.deleteUrl || '#';
                    if (editBtn) {
                        if (d.canEdit === '1' && d.editUrl) {
                            editBtn.href = d.editUrl;
                            editBtn.classList.remove('disabled');
                            editBtn.removeAttribute('aria-disabled');
                        } else {
                            editBtn.href = '#';
                            editBtn.classList.add('disabled');
                            editBtn.setAttribute('aria-disabled', 'true');
                        }
                    }
                    modal.show();
            });
        }

        // Selector de mes/año tipo Google Calendar
        const toggle = document.getElementById('gcPickerToggle');
        const picker = document.getElementById('gcPicker');
        if (toggle && picker) {
            let pickerYear = parseInt(picker.dataset.year || new Date().getFullYear(), 10);
            const currentMonth = parseInt(picker.dataset.month || '1', 10);
            const baseUrl = picker.dataset.baseUrl || window.location.href;
            const yearLabel = picker.querySelector('[data-gc-year-label]');

            const renderYear = () => {
                if (yearLabel) yearLabel.textContent = pickerYear;
                picker.querySelectorAll('.gc-pick-month').forEach((btn) => {
                    const m = parseInt(btn.dataset.month, 10);
                    btn.classList.toggle('is-current', pickerYear === parseInt(picker.dataset.year, 10) && m === currentMonth);
                });
            };

            const open = () => { picker.hidden = false; toggle.setAttribute('aria-expanded', 'true'); renderYear(); };
            const close = () => { picker.hidden = true; toggle.setAttribute('aria-expanded', 'false'); pickerYear = parseInt(picker.dataset.year || pickerYear, 10); };

            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                picker.hidden ? open() : close();
            });
            picker.querySelector('.gc-picker-close')?.addEventListener('click', close);
            picker.querySelector('.gc-pick-year-prev')?.addEventListener('click', () => { pickerYear -= 1; renderYear(); });
            picker.querySelector('.gc-pick-year-next')?.addEventListener('click', () => { pickerYear += 1; renderYear(); });
            picker.querySelectorAll('.gc-pick-month').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const month = String(btn.dataset.month).padStart(2, '0');
                    const date = `${pickerYear}-${month}-01`;
                    const url = new URL(baseUrl, window.location.origin);
                    url.searchParams.set('date', date);
                    url.searchParams.delete('view');
                    window.location.href = url.toString();
                });
            });
            document.addEventListener('click', (e) => {
                if (!picker.hidden && !picker.contains(e.target) && !toggle.contains(e.target)) close();
            });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
        }
    })();
</script>
@endpush
