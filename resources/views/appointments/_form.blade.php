@php
    $editing = $appointment !== null;
    $selectedBarber = old('barber_id', $appointment->barber_id ?? request('barber_id', auth()->id()));
    $selectedService = old('service_id', $appointment->service_id ?? '');
    $selectedStatus = old('status', $appointment->status ?? 'pendiente');
    $appointmentDate = old('appointment_date', isset($appointment) ? $appointment->appointment_date->format('Y-m-d') : request('date', request('appointment_date', now()->toDateString())));
    $appointmentTime = old('appointment_time', isset($appointment) ? substr($appointment->appointment_time, 0, 5) : request('time', request('appointment_time', '')));
@endphp

<form method="POST" action="{{ $editing ? route('appointments.update', $appointment) : route('appointments.store') }}">
    @csrf
    @if($editing)
        @method('PUT')
    @endif

    <div class="card">
        <div class="card-header">
            <i class="bi bi-calendar2-week me-1 text-warning"></i>
            {{ $editing ? 'Editar cita' : 'Datos de la cita' }}
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Barbero <span class="text-danger">*</span></label>
                    <select name="barber_id" class="form-select" required>
                        <option value="">Selecciona el barbero</option>
                        @foreach($barbers as $barber)
                            <option value="{{ $barber->id }}" @selected($selectedBarber == $barber->id)>
                                {{ $barber->name }} · {{ $barber->attentionModeLabel() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Servicio</label>
                    <select name="service_id" class="form-select">
                        <option value="">Por definir</option>
                        @foreach($services as $service)
                            <option value="{{ $service->id }}" @selected($selectedService == $service->id)>
                                {{ $service->name }} · {{ format_money($service->price) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Cliente <span class="text-danger">*</span></label>
                    <input type="text" name="client_name" value="{{ old('client_name', $appointment->client_name ?? '') }}" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Teléfono</label>
                    <input type="text" name="client_phone" value="{{ old('client_phone', $appointment->client_phone ?? '') }}" class="form-control" placeholder="Opcional">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Fecha <span class="text-danger">*</span></label>
                    <input type="date" name="appointment_date" value="{{ $appointmentDate }}" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Hora <span class="text-danger">*</span></label>
                    <input type="time" name="appointment_time" value="{{ $appointmentTime }}" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Estado <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        @foreach(\App\Models\Appointment::STATUSES as $key => $label)
                            <option value="{{ $key }}" @selected($selectedStatus === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Notas</label>
                    <textarea name="notes" rows="2" class="form-control" placeholder="Observaciones de la cita (opcional)">{{ old('notes', $appointment->notes ?? '') }}</textarea>
                </div>
            </div>
        </div>
        <div class="card-body border-top d-flex justify-content-end gap-2">
            <a href="{{ route('appointments.index') }}" class="btn btn-outline-dark">Cancelar</a>
            <button class="btn btn-gold">
                <i class="bi bi-check2-circle me-1"></i> {{ $editing ? 'Guardar cambios' : 'Agendar cita' }}
            </button>
        </div>
    </div>
</form>
