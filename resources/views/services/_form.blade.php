@php($editing = $service !== null)

<form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('services.update', $service) : route('services.store') }}">
    @csrf
    @if($editing)
        @method('PUT')
    @endif

    <div class="card">
        <div class="card-header">
            <i class="bi bi-tags me-1 text-warning"></i>
            {{ $editing ? 'Editar servicio' : 'Datos del nuevo servicio' }}
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label fw-semibold" for="serviceName">Nombre <span class="text-danger">*</span></label>
                <input type="text" id="serviceName" name="name" value="{{ old('name', $service->name ?? '') }}" class="form-control" required placeholder="Ej: Corte clásico">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="serviceCategory">Categoría</label>
                <select id="serviceCategory" name="category" class="form-select">
                    <option value="">Automática según el nombre</option>
                    @foreach(\App\Models\Service::CATEGORIES as $key => $label)
                        <option value="{{ $key }}" @selected(old('category', $service->category ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="form-text">Se usa para los filtros de la página pública de Servicios.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="serviceDescription">Descripción</label>
                <textarea id="serviceDescription" name="description" rows="3" class="form-control" placeholder="Detalle del servicio (opcional)">{{ old('description', $service->description ?? '') }}</textarea>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="serviceImage">Imagen del servicio (sin fondo)</label>
                <div class="service-image-uploader">
                    <div class="service-image-preview" id="serviceImagePreview" aria-live="polite">
                        @if($editing && $service->image_path)
                            <img src="{{ asset('storage/'.$service->image_path) }}" alt="Imagen actual de {{ $service->name }}">
                        @else
                            <span><i class="bi bi-image"></i> Sin imagen</span>
                        @endif
                    </div>
                    <div>
                        <input id="serviceImage" type="file" name="image" accept="image/png,image/webp,image/avif,image/jpeg" class="form-control" aria-describedby="serviceImageHelp">
                        <div class="form-text" id="serviceImageHelp">
                            Usa <strong>PNG o WebP con fondo transparente</strong> para que el producto o el resultado flote sobre el diseño de la página. Hasta 20 MB.
                            El fondo de cuadros te muestra si la imagen realmente no tiene fondo.
                        </div>
                        <div class="form-text text-warning d-none" id="serviceImageWarning"><i class="bi bi-exclamation-triangle"></i> Las imágenes JPG no tienen transparencia: se verán con su fondo rectangular.</div>
                    </div>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Precio <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" name="price" value="{{ old('price', $service->price ?? '') }}" class="form-control" min="0" step="0.01" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Duración (minutos) <span class="text-danger">*</span></label>
                    <input type="number" name="duration_minutes" value="{{ old('duration_minutes', $service->duration_minutes ?? '') }}" class="form-control" min="5" max="480" placeholder="Ej: 30" required>
                </div>
            </div>
            <div class="form-check form-switch mt-4">
                <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" @checked(old('active', $service->active ?? true))>
                <label class="form-check-label" for="active">Servicio activo para ventas y citas</label>
            </div>
        </div>
        <div class="card-body border-top d-flex justify-content-end gap-2">
            <a href="{{ route('services.index') }}" class="btn btn-outline-dark">Cancelar</a>
            <button class="btn btn-gold">
                <i class="bi bi-check2-circle me-1"></i> {{ $editing ? 'Guardar cambios' : 'Crear servicio' }}
            </button>
        </div>
    </div>
</form>

@push('styles')
<style>
    .service-image-uploader { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 16px; align-items: start; }
    .service-image-preview {
        display: grid; place-items: center; aspect-ratio: 1; border: 1px solid rgba(22,19,14,.12); border-radius: 12px; overflow: hidden; color: #6b6356; font-size: .85rem;
        background-color: #fff;
        background-image: linear-gradient(45deg, #ece6da 25%, transparent 25%), linear-gradient(-45deg, #ece6da 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #ece6da 75%), linear-gradient(-45deg, transparent 75%, #ece6da 75%);
        background-size: 18px 18px; background-position: 0 0, 0 9px, 9px -9px, -9px 0;
    }
    .service-image-preview img { width: 100%; height: 100%; object-fit: contain; padding: 8px; }
    @media (max-width: 575.98px) { .service-image-uploader { grid-template-columns: 1fr; } .service-image-preview { max-width: 200px; } }
</style>
@endpush

@push('scripts')
<script>
    (() => {
        const input = document.getElementById('serviceImage');
        const preview = document.getElementById('serviceImagePreview');
        const warning = document.getElementById('serviceImageWarning');
        input?.addEventListener('change', () => {
            const file = input.files?.[0];
            if (!file) return;
            const img = document.createElement('img');
            img.alt = 'Vista previa de la nueva imagen';
            img.src = URL.createObjectURL(file);
            img.onload = () => URL.revokeObjectURL(img.src);
            preview.replaceChildren(img);
            warning?.classList.toggle('d-none', !/jpe?g$/i.test(file.type));
        });
    })();
</script>
@endpush
