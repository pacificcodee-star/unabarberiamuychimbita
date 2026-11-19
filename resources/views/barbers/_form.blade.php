@php $editing = ($barber ?? null) !== null; @endphp

<form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('barbers.update', $barber) : route('barbers.store') }}">
    @csrf
    @if($editing)
        @method('PUT')
    @endif

    <div class="card">
        <div class="card-header">
            <i class="bi bi-person-badge me-1 text-warning"></i>
            {{ $editing ? 'Editar barbero' : 'Datos del nuevo barbero' }}
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Nombre completo <span class="text-danger">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $barber->name ?? '') }}" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Teléfono</label>
                    <input type="text" name="phone" value="{{ old('phone', $barber->phone ?? '') }}" class="form-control" placeholder="Opcional">
                </div>
                <div class="col-md-6 barber-only">
                    <label class="form-label fw-semibold">Modalidad de atención</label>
                    <select name="attention_mode" class="form-select">
                        <option value="llegada" @selected(old('attention_mode', $barber->attention_mode ?? 'llegada') === 'llegada')>Por orden de llegada</option>
                        <option value="citas" @selected(old('attention_mode', $barber->attention_mode ?? 'llegada') === 'citas')>Por citas</option>
                    </select>
                </div>
                <div class="col-md-6 barber-only">
                    <label class="form-label fw-semibold">Especialidad</label>
                    <input type="text" name="specialty" value="{{ old('specialty', $barber->specialty ?? '') }}" class="form-control" maxlength="120" placeholder="Ej. Cortes clásicos y fades">
                </div>
                <div class="col-12 barber-only">
                    <label class="form-label fw-semibold">Descripción del barbero</label>
                    <textarea name="bio" rows="3" class="form-control" maxlength="600" placeholder="Presentación que verán los clientes en la página principal">{{ old('bio', $barber->bio ?? '') }}</textarea>
                </div>
                <div class="col-12 barber-only">
                    <label class="form-label fw-semibold">Foto del barbero</label>
                    @if($editing && $barber->photo_path)
                        <div class="mb-2"><img src="{{ asset('storage/'.$barber->photo_path) }}" alt="Foto actual de {{ $barber->name }}" width="96" height="96" style="width:96px; height:96px; border-radius:50%; object-fit:cover; object-position:center top; background:#211f1a; border:3px solid #e3c27a"></div>
                    @endif
                    <input type="file" name="photo" id="barberPhotoInput" class="form-control" accept="image/*">
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <button type="button" id="removeBarberPhotoBackground" class="btn btn-outline-dark btn-sm" disabled>
                            <i class="bi bi-magic me-1"></i> Quitar fondo automáticamente
                        </button>
                        <span id="barberPhotoStatus" class="form-text m-0">Sube una imagen vertical de cuerpo completo (hasta 20 MB).</span>
                    </div>
                </div>
                <div class="col-12 barber-only">
                    <label class="form-label fw-semibold" for="barberVideoInput">Video de avatar (opcional)</label>
                    @if($editing && $barber->video_path)
                        <div class="mb-2 barber-video-preview">
                            <video src="{{ asset('storage/'.$barber->video_path) }}" muted loop autoplay playsinline></video>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="remove_video" value="1" id="removeVideo">
                            <label class="form-check-label" for="removeVideo">Quitar el video actual</label>
                        </div>
                    @endif
                    <input type="file" name="video" id="barberVideoInput" class="form-control" accept="video/webm,video/mp4,video/quicktime">
                    <input type="file" name="video_alpha" id="barberVideoAlphaInput" class="d-none" accept="video/mp4" tabindex="-1" aria-hidden="true">
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <button type="button" id="removeVideoBackground" class="btn btn-outline-dark btn-sm" disabled>
                            <i class="bi bi-magic me-1"></i> Quitar fondo del video
                        </button>
                        <span id="barberVideoStatus" class="form-text m-0" aria-live="polite"></span>
                    </div>
                    <progress id="barberVideoProgress" class="w-100 mt-2" max="100" value="0" hidden></progress>
                    <div class="form-text">Se reproduce en la pestaña Barberos. Ideal: <strong>WebM con fondo transparente</strong>, de cuerpo completo, 5 a 10 segundos, hasta 50 MB. Si subes MP4 se verá con su fondo. La foto se sigue usando como imagen fija (por ejemplo en iPhone).</div>
                </div>
            </div>

            @php
                $serviceRates = $editing ? $barber->serviceCommissions->pluck('pivot.percentage', 'id') : collect();
                $supplyRates = $editing ? $barber->supplyPercentages->pluck('pivot.percentage', 'id') : collect();
            @endphp
            <div class="row g-4 mt-1">
                <div class="col-lg-6">
                    <h3 class="h6 fw-bold mb-1"><i class="bi bi-scissors text-warning me-1"></i> Comisión por servicio</h3>
                    <p class="small text-muted mb-2">Cuánto gana el barbero (en %) en cada servicio.</p>
                    @if(($services ?? collect())->isEmpty())
                        <div class="small text-muted border rounded p-3">Primero crea servicios en el módulo <a href="{{ route('services.index') }}">Servicios</a>.</div>
                    @else
                        <div class="table-responsive border rounded rate-table">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Servicio</th><th class="text-end">Precio</th><th style="width:130px">% que gana</th></tr></thead>
                                <tbody>
                                @foreach($services as $service)
                                    <tr>
                                        <td>{{ $service->name }} @unless($service->active)<span class="badge-soft red ms-1">Inactivo</span>@endunless</td>
                                        <td class="text-end small text-muted">{{ format_money($service->price) }}</td>
                                        <td>
                                            <div class="input-group input-group-sm">
                                                <input type="number" name="service_commissions[{{ $service->id }}]" value="{{ old('service_commissions.'.$service->id, isset($serviceRates[$service->id]) ? (float) $serviceRates[$service->id] : ($editing ? (float) $barber->commission_percentage : '')) }}" class="form-control" min="0" max="100" step="0.01" placeholder="0" required aria-label="% de {{ $service->name }}">
                                                <span class="input-group-text">%</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
                <div class="col-lg-6">
                    <h3 class="h6 fw-bold mb-1"><i class="bi bi-box-seam text-warning me-1"></i> Lo que gana por insumos</h3>
                    <p class="small text-muted mb-2">Qué % del valor de cada insumo gana el barbero. Vacío = el % general del insumo.</p>
                    @if(($supplies ?? collect())->isEmpty())
                        <div class="small text-muted border rounded p-3">Primero registra los insumos en el módulo <a href="{{ route('supplies.index') }}">Insumos</a>.</div>
                    @else
                        <div class="table-responsive border rounded rate-table">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Insumo</th><th class="text-end">Costo</th><th style="width:130px">% que gana</th></tr></thead>
                                <tbody>
                                @foreach($supplies as $supply)
                                    <tr>
                                        <td>{{ $supply->name }}@if($supply->unit) <span class="small text-muted">· {{ $supply->unit }}</span>@endif</td>
                                        <td class="text-end small text-muted">{{ format_money($supply->cost) }}</td>
                                        <td>
                                            <div class="input-group input-group-sm">
                                                <input type="number" name="supply_percentages[{{ $supply->id }}]" value="{{ old('supply_percentages.'.$supply->id, isset($supplyRates[$supply->id]) ? (float) $supplyRates[$supply->id] : '') }}" class="form-control" min="0" max="100" step="0.01" placeholder="{{ rtrim(rtrim(number_format((float) $supply->default_percentage, 2, '.', ''), '0'), '.') }}" aria-label="% de {{ $supply->name }}">
                                                <span class="input-group-text">%</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Cortes realizados: fotos de los trabajos del barbero. Salen en su perfil público y en "Nuestros trabajos" del inicio. --}}
            @php $barberCuts = $editing ? \App\Support\BarberCuts::for($barber) : collect(); @endphp
            <div class="border-top mt-4 pt-4">
                <h3 class="h6 fw-bold mb-1"><i class="bi bi-images text-warning me-1"></i> Cortes realizados</h3>
                <p class="small text-muted mb-3">Sube fotos de los cortes que ha hecho. Aparecen debajo de su perfil en la página de barberos y en la galería «Nuestros trabajos» del inicio. Se muestran completas, sin recortarlas ni deformarlas.</p>
                <label class="form-label" for="barberCutsInput">Agregar fotos <span class="text-muted">(puedes elegir varias; JPG, PNG o WebP, máx. 20 MB cada una)</span></label>
                <input type="file" name="cuts[]" id="barberCutsInput" class="form-control @error('cuts') is-invalid @enderror @error('cuts.*') is-invalid @enderror" accept="image/*" multiple>
                @error('cuts')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @error('cuts.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Máximo {{ \App\Support\BarberCuts::MAX_PER_BARBER }} fotos por barbero.@if($barberCuts->isNotEmpty()) Tiene {{ $barberCuts->count() }}.@endif</div>

                @if($barberCuts->isNotEmpty())
                    <div class="d-flex flex-wrap gap-2 mt-3" role="group" aria-label="Fotos de cortes ya subidas">
                        @foreach($barberCuts as $cut)
                            <label class="border rounded p-1 text-center" style="width: 116px; cursor: pointer;">
                                <img src="{{ $cut['url'] }}" alt="Corte {{ $loop->iteration }}" loading="lazy" decoding="async" style="width: 100%; height: 104px; object-fit: contain; background: rgba(127,127,127,.12); border-radius: 4px;">
                                <span class="form-check d-flex justify-content-center gap-1 mt-1 mb-0 small">
                                    <input class="form-check-input" type="checkbox" name="remove_cuts[]" value="{{ $cut['name'] }}">
                                    <span>Quitar</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <div class="form-text">Marca «Quitar» en las fotos que quieras borrar y guarda los cambios.</div>
                @endif
            </div>

            <div class="form-check form-switch mt-4">
                <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" @checked(old('active', $barber->active ?? true))>
                <label class="form-check-label" for="active">Barbero activo (aparece en la página y en reservas)</label>
            </div>


        </div>
        <div class="card-body border-top d-flex justify-content-end gap-2">
            <a href="{{ route('barbers.index') }}" class="btn btn-outline-dark">Cancelar</a>
            <button class="btn btn-gold">
                <i class="bi bi-check2-circle me-1"></i> {{ $editing ? 'Guardar cambios' : 'Crear barbero' }}
            </button>
        </div>
    </div>
</form>


@push('scripts')
<script type="module" src="{{ asset('js/video-alpha-maker.js') }}?v=1"></script>
@endpush

@push('styles')
<style>
    .barber-video-preview { width: 120px; aspect-ratio: 9 / 16; max-height: 210px; margin: 0 auto; display: grid; place-items: center; border-radius: 12px; background-color: #fff; background-image: linear-gradient(45deg, #ece6da 25%, transparent 25%), linear-gradient(-45deg, #ece6da 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #ece6da 75%), linear-gradient(-45deg, transparent 75%, #ece6da 75%); background-size: 18px 18px; background-position: 0 0, 0 9px, 9px -9px, -9px 0; overflow: hidden; }
    .barber-video-preview video { width: 100%; height: 100%; object-fit: contain; object-position: center; }
    .rate-table { max-height: 340px; overflow-y: auto; }
    .rate-table thead th { position: sticky; top: 0; background: #f8f1f0; z-index: 1; font-size: .75rem; }
</style>
@endpush
