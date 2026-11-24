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

    $slotHeight = 64; // px por cada 30 min
    $pxPerMinute = $slotHeight / 30;
    $rangeMinutesStart = $hourStart * 60;
    $rangeMinutesEnd = $hourEnd * 60;
    $totalHeight = max(1, ($rangeMinutesEnd - $rangeMinutesStart)) * $pxPerMinute;
    $statusColors = [
        'pendiente' => ['bg' => 'var(--c-amber-soft)', 'border' => 'var(--c-amber)', 'text' => 'var(--ui-text)'],
        'confirmada' => ['bg' => 'var(--c-blue-soft)', 'border' => 'var(--c-blue)', 'text' => 'var(--ui-text)'],
        'atendida' => ['bg' => 'var(--c-emerald-soft)', 'border' => 'var(--c-emerald)', 'text' => 'var(--ui-text)'],
        'cancelada' => ['bg' => 'var(--c-red-soft)', 'border' => 'var(--c-red)', 'text' => 'var(--ui-text)'],
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
    <div class="card gc-toolbar">
        <div class="card-body">
            {{-- Navegación semanal/mes-año removida del encabezado según solicitud --}}
            <form method="GET" action="{{ route('appointments.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small text-muted mb-1">Ir a fecha</label>
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
                        @foreach(\App\Models\Appointment::STATUSES as $key => $label)
                            <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-dark"><i class="bi bi-funnel"></i> Aplicar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="status-strip" data-booking-region="counts">
        @foreach(\App\Models\Appointment::STATUSES as $key => $label)
            <div class="status-chip">
                <span>{{ $label }}</span>
                <span class="badge-soft {{ $key === 'cancelada' ? 'red' : ($key === 'atendida' ? 'green' : ($key === 'confirmada' ? 'blue' : 'gold')) }}">{{ $counts[$key] ?? 0 }}</span>
            </div>
        @endforeach
    </div>

    @php
        $weekStart = $days->first();
        $weekEnd = $days->last();
        $weekLabel = $weekStart->isSameMonth($weekEnd)
            ? $weekStart->day.' – '.$weekEnd->locale('es')->translatedFormat('j \d\e F Y')
            : $weekStart->locale('es')->translatedFormat('j \d\e M').' – '.$weekEnd->locale('es')->translatedFormat('j \d\e M Y');
        $statusDots = ['pendiente' => 'amber', 'confirmada' => 'blue', 'atendida' => 'emerald', 'cancelada' => 'red'];
    @endphp

    <div class="card cal">
        {{-- Barra del calendario: semana, mes, hoy y leyenda --}}
        <div class="cal-toolbar">
            <div class="cal-nav">
                <a class="cal-today" href="{{ route('appointments.index', array_merge($queryBase, ['date' => $today])) }}">Hoy</a>
                <div class="cal-arrows" role="group" aria-label="Cambiar de semana">
                    <a href="{{ route('appointments.index', array_merge($queryBase, ['date' => $prevDate])) }}" title="Semana anterior" aria-label="Semana anterior"><i class="bi bi-chevron-left"></i></a>
                    <a href="{{ route('appointments.index', array_merge($queryBase, ['date' => $nextDate])) }}" title="Semana siguiente" aria-label="Semana siguiente"><i class="bi bi-chevron-right"></i></a>
                </div>
                <div class="cal-title">
                    <strong>{{ $weekLabel }}</strong>
                    <span>{{ $appointments->count() }} {{ $appointments->count() === 1 ? 'cita' : 'citas' }} esta semana</span>
                </div>
            </div>
            <div class="cal-tools">
                <ul class="cal-legend" aria-label="Colores por estado">
                    @foreach(\App\Models\Appointment::STATUSES as $key => $label)
                        <li><span class="cal-dot" data-tone="{{ $statusDots[$key] ?? 'slate' }}"></span>{{ $label }}</li>
                    @endforeach
                </ul>
                <div class="cal-month" role="group" aria-label="Cambiar de mes">
                    <a href="{{ route('appointments.index', array_merge($queryBase, ['date' => $prevMonthDate])) }}" title="Mes anterior" aria-label="Mes anterior"><i class="bi bi-chevron-double-left"></i></a>
                    <span>{{ $current->locale('es')->translatedFormat('F Y') }}</span>
                    <a href="{{ route('appointments.index', array_merge($queryBase, ['date' => $nextMonthDate])) }}" title="Mes siguiente" aria-label="Mes siguiente"><i class="bi bi-chevron-double-right"></i></a>
                </div>
            </div>
        </div>

        <div class="cal-scroll">
            <div class="cal-grid" style="--days: {{ $days->count() }}; --slot-h: {{ $slotHeight }}px; --body-h: {{ $totalHeight }}px;">
                {{-- Encabezado de los días --}}
                <div class="cal-head">
                    <div class="cal-corner"><i class="bi bi-clock" aria-hidden="true"></i></div>
                    @foreach($days as $day)
                        @php $isToday = $day->isToday(); $dayCount = $grouped->get($day->toDateString(), collect())->count(); @endphp
                        <a class="cal-day-head {{ $isToday ? 'is-today' : '' }} {{ $day->isWeekend() ? 'is-weekend' : '' }}" href="{{ route('appointments.index', array_merge($queryBase, ['date' => $day->toDateString()])) }}" @if($isToday) aria-current="date" @endif>
                            <span class="cal-dow">{{ rtrim($day->locale('es')->isoFormat('ddd'), '.') }}</span>
                            <span class="cal-num">{{ $day->day }}</span>
                            <span class="cal-count {{ $dayCount ? '' : 'is-empty' }}">{{ $dayCount ? $dayCount.' '.($dayCount === 1 ? 'cita' : 'citas') : 'Libre' }}</span>
                        </a>
                    @endforeach
                </div>

                {{-- Cuerpo: horas y columnas de cada día --}}
                <div class="cal-body">
                    <div class="cal-times">
                        @for($h = $hourStart; $h < $hourEnd; $h++)
                            <div class="cal-time" style="height: {{ $slotHeight * 2 }}px;">
                                <span>{{ \Carbon\Carbon::createFromTime($h, 0)->format('g a') }}</span>
                            </div>
                        @endfor
                    </div>
                    @foreach($days as $day)
                        @php
                            $dayKey = $day->toDateString();
                            $dayItems = $grouped->get($dayKey, collect());
                            // Citas que se cruzan: se reparten en carriles lado a lado para que no se tapen.
                            $spans = $dayItems->map(function ($a) {
                                [$h, $m] = array_map('intval', explode(':', substr((string) $a->appointment_time, 0, 5)));
                                $start = $h * 60 + $m;
                                return ['id' => $a->id, 'start' => $start, 'end' => $start + (int) ($a->service?->duration_minutes ?: 30)];
                            })->sortBy('start')->values();
                            $lanes = []; $clusters = []; $laneEnds = []; $clusterId = -1; $clusterEnd = -1;
                            foreach ($spans as $span) {
                                if ($span['start'] >= $clusterEnd) { $clusterId++; $laneEnds = []; }
                                $lane = 0;
                                while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $span['start']) { $lane++; }
                                $laneEnds[$lane] = $span['end'];
                                $clusterEnd = max($clusterEnd, $span['end']);
                                $lanes[$span['id']] = [$lane, $clusterId];
                                $clusters[$clusterId] = max($clusters[$clusterId] ?? 1, $lane + 1);
                            }
                        @endphp
                        <div data-booking-region="day-{{ $dayKey }}" class="cal-col {{ $day->isToday() ? 'is-today' : '' }} {{ $day->isWeekend() ? 'is-weekend' : '' }}" data-date="{{ $dayKey }}">
                            <div class="cal-slots">
                                @for($h = $hourStart; $h < $hourEnd; $h++)
                                    @foreach([0, 30] as $min)
                                        @php $slotTime = sprintf('%02d:%02d', $h, $min); $slotPast = $day->copy()->setTime($h, $min)->isPast(); @endphp
                                        @if($slotPast)
                                            <div class="cal-slot {{ $min ? 'is-half' : '' }} is-past" style="height: {{ $slotHeight }}px;"></div>
                                        @else
                                            <a class="cal-slot {{ $min ? 'is-half' : '' }}" style="height: {{ $slotHeight }}px;" href="{{ route('appointments.create', ['date' => $dayKey, 'time' => $slotTime]) }}" tabindex="-1" title="Nueva cita a las {{ \Carbon\Carbon::createFromTime($h, $min)->format('g:i A') }}"><span><i class="bi bi-plus-lg"></i> {{ \Carbon\Carbon::createFromTime($h, $min)->format('g:i A') }}</span></a>
                                        @endif
                                    @endforeach
                                @endfor
                            </div>
                            @if($day->isToday() && now()->hour >= $hourStart && now()->hour < $hourEnd)
                                @php $nowTop = ((now()->hour * 60 + now()->minute) - $rangeMinutesStart) * $pxPerMinute; @endphp
                                <div class="cal-now" style="top: {{ $nowTop }}px;"><span>{{ now()->format('H:i') }}</span></div>
                            @endif
                            <div class="cal-events">
                                @foreach($dayItems as $appointment)
                                    @php
                                        $timeStr = substr((string) $appointment->appointment_time, 0, 5);
                                        [$hh, $mm] = array_map('intval', explode(':', $timeStr));
                                        $startMin = $hh * 60 + $mm;
                                        $duration = (int) ($appointment->service?->duration_minutes ?: 30);
                                        $top = max(0, ($startMin - $rangeMinutesStart) * $pxPerMinute);
                                        $height = max(30, $duration * $pxPerMinute - 4);
                                        $height = min($height, $totalHeight - $top);
                                        $canEdit = $appointment->status !== 'atendida';
                                        $editUrl = route('appointments.edit', $appointment);
                                        $deleteUrl = route('appointments.destroy', $appointment);
                                        $timeLabel = \Carbon\Carbon::createFromTime($hh, $mm)->format('h:i A');
                                        $rangeLabel = \Carbon\Carbon::createFromTime($hh, $mm)->format('g:i').' – '.\Carbon\Carbon::createFromTime($hh, $mm)->addMinutes($duration)->format('g:i A');
                                        $dateLabel = \Carbon\Carbon::parse($appointment->appointment_date)->locale('es')->translatedFormat('l, d \\d\\e F');
                                        $barberName = $appointment->barber?->name ?? '—';
                                        $serviceName = $appointment->service?->name ?? 'Por definir';
                                        $statusLabel = \App\Models\Appointment::STATUSES[$appointment->status] ?? $appointment->status;
                                        [$lane, $cluster] = $lanes[$appointment->id] ?? [0, 0];
                                        $laneCount = $clusters[$cluster] ?? 1;
                                        $phoneDigits = preg_replace('/\D+/', '', (string) $appointment->client_phone);
                                        $waPhone = strlen($phoneDigits) === 10 ? '57'.$phoneDigits : $phoneDigits;
                                    @endphp
                                    @if($startMin >= $rangeMinutesStart && $startMin < $rangeMinutesEnd)
                                        <a href="{{ $canEdit ? $editUrl : '#' }}" class="cal-event {{ $height < 56 ? 'is-compact' : ($height < 84 ? 'is-short' : '') }}" data-tone="{{ $statusDots[$appointment->status] ?? 'slate' }}" style="top: {{ $top + 2 }}px; height: {{ $height }}px; left: calc({{ $lane }} * 100% / {{ $laneCount }}); width: calc(100% / {{ $laneCount }} - {{ $laneCount > 1 ? 3 : 0 }}px);"
                                           data-appt-trigger
                                           data-tone-key="{{ $statusDots[$appointment->status] ?? 'slate' }}"
                                           data-range="{{ e($rangeLabel) }}"
                                           data-duration="{{ $duration }}"
                                           data-wa="{{ $waPhone }}"
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
                                           title="{{ $appointment->client_name }} · {{ $timeLabel }} · {{ $statusLabel }}">
                                            @if(auth()->user()->isAdmin() && $appointment->barber)
                                                <span class="cal-event-barber" title="{{ $barberName }}">{{ mb_strtoupper(mb_substr($barberName, 0, 1)) }}</span>
                                            @endif
                                            <span class="cal-event-time">{{ $rangeLabel }}</span>
                                            <span class="cal-event-title">{{ $appointment->client_name }}</span>
                                            <span class="cal-event-sub">{{ $serviceName }}@if(auth()->user()->isAdmin() && $appointment->barber) · {{ $appointment->barber->name }}@endif</span>
                                            <span class="cal-event-status">{{ $statusLabel }}</span>
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="cal-foot">
            <span><i class="bi bi-hand-index" aria-hidden="true"></i> Toca una cita para ver sus detalles.</span>
            <span><i class="bi bi-plus-circle" aria-hidden="true"></i> Toca un espacio libre para agendar a esa hora.</span>
            <span><i class="bi bi-calendar-event" aria-hidden="true"></i> Toca un día para saltar a esa fecha.</span>
        </div>
    </div>

    {{-- Ventana de detalle de la cita --}}
    <div class="modal fade appt-modal" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" data-appt-tone>
                <div class="appt-hero">
                    <button type="button" class="appt-close" data-bs-dismiss="modal" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
                    <span class="appt-avatar" data-appt-field="initial" aria-hidden="true"></span>
                    <div class="appt-hero-text">
                        <span class="appt-status" data-appt-field="status"></span>
                        <h2 class="appt-name" id="appointmentModalTitle" data-appt-field="client"></h2>
                        <span class="appt-when"><i class="bi bi-clock" aria-hidden="true"></i> <span data-appt-field="range"></span></span>
                    </div>
                </div>

                <div class="appt-quick">
                    <a class="appt-quick-btn" data-appt-call href="#"><i class="bi bi-telephone"></i><span>Llamar</span></a>
                    <a class="appt-quick-btn is-wa" data-appt-wa href="#" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i><span>WhatsApp</span></a>
                    <a class="appt-quick-btn" data-appt-edit-quick href="#"><i class="bi bi-pencil-square"></i><span>Editar</span></a>
                </div>

                <div class="modal-body">
                    <div class="appt-grid">
                        <div class="appt-tile"><i class="bi bi-calendar3" aria-hidden="true"></i><span>Fecha</span><strong data-appt-field="date"></strong></div>
                        <div class="appt-tile"><i class="bi bi-hourglass-split" aria-hidden="true"></i><span>Duración</span><strong data-appt-field="duration"></strong></div>
                        <div class="appt-tile"><i class="bi bi-person-badge" aria-hidden="true"></i><span>Barbero</span><strong data-appt-field="barber"></strong></div>
                        <div class="appt-tile"><i class="bi bi-scissors" aria-hidden="true"></i><span>Servicio</span><strong data-appt-field="service"></strong></div>
                        <div class="appt-tile is-wide"><i class="bi bi-telephone" aria-hidden="true"></i><span>Teléfono</span><strong data-appt-field="phone"></strong></div>
                    </div>
                    <div class="appt-notes" data-appt-notes-box>
                        <span><i class="bi bi-sticky" aria-hidden="true"></i> Notas</span>
                        <p data-appt-field="notes"></p>
                    </div>
                    <div class="appt-locked" data-appt-locked hidden><i class="bi bi-lock-fill" aria-hidden="true"></i> Esta cita ya fue atendida y generó su ingreso: no se puede editar.</div>
                </div>

                <div class="modal-footer">
                    <form method="POST" data-appt-delete-form onsubmit="return confirm('¿Eliminar esta cita? Si ya generó un ingreso, también se eliminará ese registro.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-link appt-delete"><i class="bi bi-trash3"></i> Eliminar cita</button>
                    </form>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cerrar</button>
                        <a href="#" class="btn btn-gold" data-appt-edit><i class="bi bi-pencil"></i> Editar cita</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
<style>
    /* ---------- Calendario semanal ---------- */
    .cal { overflow: hidden; }
    .cal-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 20px; padding: 14px 20px; border-bottom: 1px solid var(--ui-border); background: linear-gradient(180deg, color-mix(in srgb, var(--tone) 6%, var(--ui-surface)), var(--ui-surface)); }
    .cal-nav, .cal-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
    .cal-today { display: inline-flex; align-items: center; height: 36px; padding: 0 16px; border: 1px solid var(--ui-border-strong); border-radius: 999px; background: var(--ui-surface); color: var(--ui-text); font-size: .84rem; font-weight: 700; text-decoration: none; }
    .cal-today:hover { border-color: var(--tone); color: var(--tone); }
    .cal-arrows, .cal-month { display: inline-flex; align-items: center; gap: 2px; padding: 3px; border-radius: 999px; background: var(--ui-surface-2); border: 1px solid var(--ui-border); }
    .cal-arrows a, .cal-month a { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; color: var(--ui-text-2); text-decoration: none; }
    .cal-arrows a:hover, .cal-month a:hover { background: var(--ui-surface); color: var(--tone); box-shadow: var(--ui-shadow-sm); }
    .cal-month span { min-width: 128px; padding: 0 6px; text-align: center; font-size: .82rem; font-weight: 700; text-transform: capitalize; }
    .cal-title { display: grid; line-height: 1.25; }
    .cal-title strong { font-size: 1.05rem; font-weight: 800; letter-spacing: -.02em; }
    .cal-title span { color: var(--ui-text-3); font-size: .78rem; }
    .cal-legend { display: flex; flex-wrap: wrap; gap: 6px 14px; margin: 0; padding: 0; list-style: none; color: var(--ui-text-2); font-size: .78rem; font-weight: 600; }
    .cal-legend li { display: inline-flex; align-items: center; gap: 6px; }
    .cal-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--tone); box-shadow: 0 0 0 3px var(--tone-soft); }
    [data-tone="red"] { --tone: var(--c-red); --tone-soft: var(--c-red-soft); }

    .cal-scroll { overflow: auto; max-height: calc(100vh - 250px); min-height: 420px; background: var(--ui-surface); scrollbar-width: thin; }
    .cal-grid { min-width: 920px; }
    .cal-head { position: sticky; top: 0; z-index: 6; display: grid; grid-template-columns: 64px repeat(var(--days), minmax(118px, 1fr)); gap: 6px; padding: 10px 10px 10px 0; background: color-mix(in srgb, var(--ui-surface) 92%, transparent); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); border-bottom: 1px solid var(--ui-border); }
    .cal-corner { display: grid; place-items: center; color: var(--ui-text-3); font-size: 1rem; }
    .cal-day-head { display: grid; justify-items: center; gap: 2px; padding: 8px 4px; border-radius: 14px; color: var(--ui-text); text-decoration: none; transition: background-color .15s ease; }
    .cal-day-head:hover { background: var(--ui-surface-2); color: var(--ui-text); }
    .cal-day-head.is-weekend .cal-dow { color: var(--c-rose); }
    .cal-dow { color: var(--ui-text-3); font-size: .7rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
    .cal-num { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; font-size: 1.25rem; font-weight: 800; letter-spacing: -.02em; }
    .cal-count { padding: 2px 8px; border-radius: 999px; background: var(--tone-soft); color: var(--tone); font-size: .68rem; font-weight: 700; }
    .cal-count.is-empty { background: transparent; color: var(--ui-text-3); font-weight: 600; }
    .cal-day-head.is-today { background: linear-gradient(160deg, color-mix(in srgb, var(--tone) 14%, var(--ui-surface)), color-mix(in srgb, var(--tone) 4%, var(--ui-surface))); }
    .cal-day-head.is-today .cal-dow { color: var(--tone); }
    .cal-day-head.is-today .cal-num { background: var(--tone); color: #fff; box-shadow: 0 6px 14px color-mix(in srgb, var(--tone) 35%, transparent); }
    html.admin-dark .cal-day-head.is-today .cal-num { color: #111; }

    .cal-body { display: grid; grid-template-columns: 64px repeat(var(--days), minmax(118px, 1fr)); gap: 0 6px; padding-right: 10px; }
    .cal-time { position: relative; padding-right: 12px; text-align: right; }
    .cal-time span { position: relative; top: -8px; color: var(--ui-text-3); font-size: .7rem; font-weight: 700; text-transform: uppercase; }
    .cal-col { position: relative; min-height: var(--body-h); border-radius: 12px; }
    .cal-col.is-weekend { background: color-mix(in srgb, var(--c-rose) 3%, transparent); }
    .cal-col.is-today { background: color-mix(in srgb, var(--tone) 5%, transparent); }
    .cal-slots { position: absolute; inset: 0; }
    .cal-slot { border-top: 1px solid var(--ui-border); }
    .cal-slot.is-half { border-top: 1px dashed color-mix(in srgb, var(--ui-border) 70%, transparent); }
    .cal-now { position: absolute; left: -6px; right: 0; z-index: 4; height: 2px; background: var(--c-brand); pointer-events: none; }
    .cal-now::before { content: ''; position: absolute; left: 0; top: -4px; width: 10px; height: 10px; border-radius: 50%; background: var(--c-brand); box-shadow: 0 0 0 4px var(--c-brand-soft); }
    .cal-now span { position: absolute; right: 4px; top: -11px; padding: 2px 6px; border-radius: 999px; background: var(--c-brand); color: #fff; font-size: .64rem; font-weight: 800; }
    .cal-events { position: absolute; inset: 0 4px; pointer-events: none; }
    .cal-event { pointer-events: auto; }

    /* Espacios libres: al pasar el mouse invitan a agendar a esa hora */
    .cal-slots .cal-slot { display: flex; align-items: center; padding: 0 8px; color: var(--tone); text-decoration: none; }
    .cal-slots a.cal-slot span { opacity: 0; display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 999px; background: var(--tone-soft); font-size: .68rem; font-weight: 800; transition: opacity .15s ease; }
    .cal-slots a.cal-slot:hover { background: color-mix(in srgb, var(--tone) 6%, transparent); }
    .cal-slots a.cal-slot:hover span { opacity: 1; }
    .cal-slot.is-past { background: repeating-linear-gradient(135deg, transparent 0 6px, color-mix(in srgb, var(--ui-text) 2.5%, transparent) 6px 7px); }

    /* Citas como tarjetas de color según su estado */
    .cal-event {
        position: absolute; left: 0; right: 0; z-index: 2;
        display: flex; flex-direction: column; gap: 1px;
        padding: 7px 10px 7px 12px;
        overflow: hidden;
        border-radius: 12px;
        border: 1px solid color-mix(in srgb, var(--tone) 25%, transparent);
        background: linear-gradient(160deg, color-mix(in srgb, var(--tone) 16%, var(--ui-surface)), color-mix(in srgb, var(--tone) 7%, var(--ui-surface)));
        color: var(--ui-text);
        text-decoration: none;
        box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
        transition: transform .15s ease, box-shadow .15s ease;
    }
    .cal-event::before { content: ''; position: absolute; left: 0; top: 8px; bottom: 8px; width: 3px; border-radius: 0 3px 3px 0; background: var(--tone); }
    .cal-event:hover { z-index: 3; transform: translateY(-1px); box-shadow: 0 10px 24px color-mix(in srgb, var(--tone) 25%, transparent); color: var(--ui-text); }
    .cal-event:focus-visible { outline: 2px solid var(--tone); outline-offset: 2px; }
    .cal-event-barber { position: absolute; top: 6px; right: 6px; display: grid; place-items: center; width: 20px; height: 20px; border-radius: 50%; background: var(--tone); color: #fff; font-size: .62rem; font-weight: 800; }
    html.admin-dark .cal-event-barber { color: #111; }
    .cal-event.is-compact .cal-event-barber { top: 50%; transform: translateY(-50%); }
    .cal-event-time, .cal-event-title { padding-right: 18px; }
    .cal-event-time { color: var(--tone); font-size: .68rem; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cal-event-title { font-size: .82rem; font-weight: 800; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cal-event-sub { color: var(--ui-text-2); font-size: .7rem; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cal-event-status { align-self: flex-start; margin-top: auto; padding: 1px 7px; border-radius: 999px; background: var(--tone); color: #fff; font-size: .62rem; font-weight: 800; }
    html.admin-dark .cal-event-status { color: #111; }
    .cal-event.is-compact { justify-content: center; gap: 0; padding-top: 3px; padding-bottom: 3px; }
    .cal-event.is-compact .cal-event-title { order: -1; font-size: .78rem; }
    .cal-event.is-compact .cal-event-time { font-size: .64rem; }
    .cal-event.is-compact .cal-event-sub, .cal-event.is-compact .cal-event-status { display: none; }
    .cal-event.is-short { justify-content: center; }
    .cal-event.is-short .cal-event-title { order: -1; }
    .cal-event.is-short .cal-event-status, .cal-event.is-short .cal-event-sub { display: none; }
    .cal-foot span { display: inline-flex; align-items: center; gap: 6px; }
    .cal-foot { flex-wrap: wrap; justify-content: center; column-gap: 24px;
    display: flex; align-items: center; gap: 8px; padding: 12px 20px; border-top: 1px solid var(--ui-border); background: var(--ui-surface-2); color: var(--ui-text-3); font-size: .8rem; }

    /* ---------- Ventana de detalle de la cita ---------- */
    .appt-modal .modal-dialog { max-width: 540px; }
    .appt-modal .modal-content { overflow: hidden; border: 0; border-radius: 22px; }
    .appt-hero { position: relative; display: flex; align-items: center; gap: 16px; padding: 28px 24px 22px; background: linear-gradient(140deg, color-mix(in srgb, var(--tone) 92%, #fff), color-mix(in srgb, var(--tone) 70%, #000)); color: #fff; }
    .appt-hero::after { content: ''; position: absolute; right: -40px; top: -60px; width: 200px; height: 200px; border-radius: 50%; background: rgba(255, 255, 255, .12); pointer-events: none; }
    .appt-close { position: absolute; top: 12px; right: 12px; z-index: 1; display: grid; place-items: center; width: 34px; height: 34px; border: 0; border-radius: 50%; background: rgba(255, 255, 255, .18); color: #fff; }
    .appt-close:hover { background: rgba(255, 255, 255, .3); }
    .appt-avatar { flex: none; display: grid; place-items: center; width: 64px; height: 64px; border-radius: 20px; background: rgba(255, 255, 255, .22); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .35); font-size: 1.7rem; font-weight: 800; }
    .appt-hero-text { position: relative; z-index: 1; display: grid; gap: 4px; min-width: 0; }
    .appt-status { justify-self: start; padding: 3px 10px; border-radius: 999px; background: rgba(255, 255, 255, .22); font-size: .7rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
    .appt-name { margin: 0; color: #fff; font-family: var(--font-display); font-size: 1.5rem; font-weight: 600; letter-spacing: -.02em; line-height: 1.15; overflow-wrap: anywhere; }
    .appt-when { display: inline-flex; align-items: center; gap: 6px; font-size: .84rem; opacity: .92; }
    html.admin-dark .appt-hero { color: #111; }
    html.admin-dark .appt-name { color: #111; }
    html.admin-dark .appt-close { color: #111; background: rgba(0, 0, 0, .12); }

    .appt-quick { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding: 14px 20px; border-bottom: 1px solid var(--ui-border); }
    .appt-quick-btn { display: grid; justify-items: center; gap: 4px; padding: 10px 4px; border-radius: 14px; background: var(--tone-soft); color: var(--tone); font-size: .76rem; font-weight: 700; text-decoration: none; transition: transform .15s ease, background-color .15s ease; }
    .appt-quick-btn i { font-size: 1.15rem; }
    .appt-quick-btn:hover { transform: translateY(-1px); background: color-mix(in srgb, var(--tone) 18%, var(--ui-surface)); color: var(--tone); }
    .appt-quick-btn.is-wa { background: rgba(37, 211, 102, .14); color: #128c4a; }
    html.admin-dark .appt-quick-btn.is-wa { color: #4fd18b; }
    .appt-quick-btn.disabled { opacity: .4; pointer-events: none; }

    .appt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .appt-tile { display: grid; grid-template-columns: auto 1fr; grid-template-areas: "icon label" "icon value"; column-gap: 12px; row-gap: 1px; align-content: center; align-items: center; padding: 12px; border: 1px solid var(--ui-border); border-radius: 14px; background: var(--ui-surface-2); }
    .appt-tile.is-wide { grid-column: 1 / -1; }
    .appt-tile i { grid-area: icon; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 10px; background: var(--tone-soft); color: var(--tone); }
    .appt-tile span { grid-area: label; color: var(--ui-text-3); font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
    .appt-tile strong { grid-area: value; font-size: .86rem; line-height: 1.3; overflow-wrap: anywhere; }
    .appt-notes { margin-top: 12px; padding: 12px 14px; border-radius: 14px; background: var(--c-amber-soft); }
    .appt-notes span { display: inline-flex; align-items: center; gap: 6px; color: var(--c-amber); font-size: .72rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
    .appt-notes p { margin: 4px 0 0; font-size: .86rem; white-space: pre-line; }
    .appt-locked { margin-top: 12px; padding: 10px 14px; border-radius: 12px; background: var(--ui-surface-2); color: var(--ui-text-2); font-size: .8rem; }
    .appt-modal .modal-footer { justify-content: space-between; flex-wrap: nowrap; }
    .appt-modal .modal-footer form { margin: 0; }
    .appt-delete { color: var(--c-red) !important; font-weight: 700; display: inline-flex; gap: 6px; }
    .appt-delete:hover { color: var(--c-red) !important; text-decoration: underline; }
    .appt-modal .btn.disabled { opacity: .45; pointer-events: none; }
    @media (max-width: 575.98px) { .appt-grid { grid-template-columns: 1fr; } }

    @media (prefers-reduced-motion: reduce) { .cal-event, .cal-day-head, .appt-quick-btn { transition: none; } }

</style>
@endpush

@push('scripts')
<script>
    (() => {
        const modalEl = document.getElementById('appointmentModal');
        if (modalEl && window.bootstrap) {
            const modal = new bootstrap.Modal(modalEl);
            const content = modalEl.querySelector('[data-appt-tone]');
            const deleteForm = modalEl.querySelector('[data-appt-delete-form]');
            const editLinks = modalEl.querySelectorAll('[data-appt-edit], [data-appt-edit-quick]');
            const call = modalEl.querySelector('[data-appt-call]');
            const wa = modalEl.querySelector('[data-appt-wa]');
            const field = (name) => modalEl.querySelector('[data-appt-field="' + name + '"]');
            const setLink = (link, href) => {
                link.href = href || '#';
                link.classList.toggle('disabled', !href);
                if (href) link.removeAttribute('aria-disabled'); else link.setAttribute('aria-disabled', 'true');
            };

            document.addEventListener('click', (e) => {
                const el = e.target.closest('[data-appt-trigger]');
                if (!el) return;
                e.preventDefault();
                const d = el.dataset;
                content.dataset.tone = d.toneKey || 'slate';
                field('initial').textContent = (d.client || '?').trim().charAt(0).toUpperCase();
                field('client').textContent = d.client || 'Cliente';
                field('status').textContent = d.status || '—';
                field('range').textContent = (d.range || d.time || '') + ' · ' + (d.date || '');
                field('date').textContent = d.date || '—';
                field('duration').textContent = d.duration ? d.duration + ' minutos' : '—';
                field('barber').textContent = d.barber || '—';
                field('service').textContent = d.service || '—';
                field('phone').textContent = d.phone || 'Sin teléfono';
                field('notes').textContent = d.notes || '';
                modalEl.querySelector('[data-appt-notes-box]').hidden = !d.notes;

                setLink(call, d.phone ? 'tel:' + d.phone.replace(/[^\d+]/g, '') : '');
                setLink(wa, d.wa ? 'https://wa.me/' + d.wa : '');
                const canEdit = d.canEdit === '1' && d.editUrl;
                editLinks.forEach((link) => setLink(link, canEdit ? d.editUrl : ''));
                modalEl.querySelector('[data-appt-locked]').hidden = !!canEdit;
                if (deleteForm) deleteForm.action = d.deleteUrl || '#';
                modal.show();
            });
        }

        // Al abrir, el calendario baja hasta la hora actual o la primera cita.
        const scroller = document.querySelector('.cal-scroll');
        if (scroller) {
            const head = scroller.querySelector('.cal-head');
            const target = scroller.querySelector('.cal-now') || [...scroller.querySelectorAll('.cal-event')].sort((a, b) => a.offsetTop - b.offsetTop)[0];
            if (target) scroller.scrollTop = Math.max(0, target.offsetTop - 48);
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
