@extends('layouts.app')

@section('title', $cut ? 'Editar corte destacado' : 'Agregar corte destacado')

@section('actions')
    <a href="{{ route('featured-cuts.index') }}" class="btn btn-outline-dark"><i class="bi bi-arrow-left me-1"></i> Volver</a>
@endsection

@section('content')
    <div class="row justify-content-center"><div class="col-xl-9">
        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" enctype="multipart/form-data" action="{{ $cut ? route('featured-cuts.update', $cut) : route('featured-cuts.store') }}">
            @csrf
            @if($cut) @method('PUT') @endif
            <div class="card mb-4">
                <div class="card-header">Textos del corte</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold" for="cutTitle">Nombre del corte <span class="text-danger">*</span></label>
                            <input id="cutTitle" name="title" value="{{ old('title', $cut->title ?? '') }}" maxlength="120" class="form-control" required placeholder="Ej. Fade con textura">
                            <div class="form-text">Aparece en grande, en dorado, detrás de la imagen.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="cutOrder">Orden</label>
                            <input id="cutOrder" type="number" name="sort_order" value="{{ old('sort_order', $cut->sort_order ?? 0) }}" min="0" max="999" class="form-control" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold" for="cutSubtitle">Frase corta</label>
                            <input id="cutSubtitle" name="subtitle" value="{{ old('subtitle', $cut->subtitle ?? '') }}" maxlength="160" class="form-control" placeholder="Ej. Precisión en cada línea">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="cutService">Servicio para reservar</label>
                            <select id="cutService" name="service_id" class="form-select">
                                <option value="">Sin servicio (reserva general)</option>
                                @foreach($services as $service)
                                    <option value="{{ $service->id }}" @selected((string) old('service_id', $cut->service_id ?? '') === (string) $service->id)>{{ $service->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="cutDescription">Descripción</label>
                            <textarea id="cutDescription" name="description" rows="3" maxlength="500" class="form-control" placeholder="Cuenta en una o dos frases cómo es este corte.">{{ old('description', $cut->description ?? '') }}</textarea>
                        </div>
                    </div>
                    <div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" @checked(old('active', $cut->active ?? true))><label class="form-check-label" for="active">Mostrar en el inicio</label></div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Imagen y video</div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <label class="form-label fw-semibold" for="cutImage">Imagen {{ $cut ? '(opcional para conservar la actual)' : '(o sube un video abajo)' }}</label>
                            <div class="cut-dropzone checker mb-2" data-preview="cutImage">
                                @if($cut && $cut->image_path)<img src="{{ asset('storage/'.$cut->image_path) }}" alt="Imagen principal actual">@else<span><i class="bi bi-person-bounding-box"></i> Sin imagen</span>@endif
                            </div>
                            <input id="cutImage" type="file" name="image" accept="image/png,image/webp,image/avif,image/jpeg" class="form-control">
                            <div class="form-text">Foto del corte (JPG) o PNG/WebP <strong>sin fondo</strong>. Aparece en la tarjeta de la espiral del inicio.</div>

                            <label class="form-label fw-semibold mt-4" for="cutVideo">Video del corte (opcional)</label>
                            @if($cut && $cut->video_path)
                                <video class="w-100 rounded mb-2" src="{{ asset('storage/'.$cut->video_path) }}" muted loop playsinline controls preload="metadata" style="max-height:220px;background:#111"></video>
                                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_video" value="1" id="removeVideo"><label class="form-check-label small" for="removeVideo">Quitar video</label></div>
                            @endif
                            <input id="cutVideo" type="file" name="video" accept="video/mp4,video/webm,video/quicktime" class="form-control">
                            <div class="form-text">MP4, WebM o MOV hasta 50 MB. Si subes video, en la espiral se reproduce el video (en silencio y en bucle) y la imagen se usa como portada mientras carga. Debes subir al menos una imagen o un video.</div>
                        </div>
                    </div>
                </div>
                <div class="card-body border-top d-flex justify-content-end gap-2"><a href="{{ route('animations.index') }}" class="btn btn-outline-dark">Cancelar</a><button class="btn btn-gold">Guardar corte</button></div>
            </div>
        </form>
    </div></div>
@endsection

@push('styles')
<style>
    .cut-dropzone { display: grid; place-items: center; height: 280px; border-radius: 14px; overflow: hidden; color: #8b8273; background: linear-gradient(145deg, #171510, #29251d); }
    .cut-dropzone.checker { background-color: #fff; background-image: linear-gradient(45deg, #ece6da 25%, transparent 25%), linear-gradient(-45deg, #ece6da 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #ece6da 75%), linear-gradient(-45deg, transparent 75%, #ece6da 75%); background-size: 18px 18px; background-position: 0 0, 0 9px, 9px -9px, -9px 0; }
    .cut-dropzone img { max-width: 100%; max-height: 100%; object-fit: contain; padding: 8px; }
    .cut-dropzone img.cover { width: 100%; height: 100%; object-fit: cover; padding: 0; }
    .cut-dropzone.small-zone { height: 130px; font-size: .8rem; }
</style>
@endpush

@push('scripts')
<script>
    document.querySelectorAll('[data-preview]').forEach((zone) => {
        const input = document.getElementById(zone.dataset.preview);
        input?.addEventListener('change', () => {
            const file = input.files?.[0];
            if (!file) return;
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.alt = 'Vista previa';
            if (zone.classList.contains('small-zone')) img.className = 'cover';
            img.onload = () => URL.revokeObjectURL(img.src);
            zone.replaceChildren(img);
        });
    });
</script>
@endpush
