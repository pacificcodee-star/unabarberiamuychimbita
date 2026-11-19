@extends('layouts.app')

@section('title', 'Animaciones')

@section('content')
    <p class="text-muted mb-4">Aquí subes lo que se ve en las animaciones de la página: la <strong>pantalla de carga</strong> (al abrir el sitio) y la <strong>espiral de cortes</strong> del inicio.</p>

    @if($errors->any())
        <div class="alert alert-danger"><strong>Revisa estos campos:</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    {{-- 0. Fondo de la portada --}}
    <section class="card mb-4" aria-labelledby="heroBgTitle">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-image text-warning"></i><h2 class="h6 mb-0" id="heroBgTitle">Fondo de la portada (inicio)</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('animations.loader.update') }}" enctype="multipart/form-data" class="row g-3 align-items-start">
                @csrf
                @method('PUT')
                <div class="col-md-5">
                    <div class="anim-preview"><img src="{{ !empty($settings['hero_image_path']) ? asset('storage/'.$settings['hero_image_path']) : asset('images/portada-barberia.jpg') }}" alt="Fondo actual de la portada" style="width:100%;height:100%;object-fit:cover;filter:brightness(.45)"></div>
                </div>
                <div class="col-md-7">
                    <label class="form-label fw-semibold" for="heroImage">Cambiar imagen de fondo</label>
                    <input id="heroImage" type="file" name="hero_image" accept="image/*" class="form-control">
                    @if(!empty($settings['hero_image_path']))
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_hero_image" value="1" id="removeHero"><label class="form-check-label small" for="removeHero">Volver a la imagen por defecto</label></div>
                    @endif
                    <div class="form-text">Se ve detrás del título grande del inicio, oscurecida para que el texto se lea bien. Ideal horizontal (1920 × 1080).</div>
                    <button class="btn btn-sm btn-gold mt-3"><i class="bi bi-check2 me-1"></i> Guardar fondo</button>
                </div>
            </form>
        </div>
    </section>

    {{-- 0b. Fondo de la espiral --}}
    <section class="card mb-4" aria-labelledby="spiralBgTitle">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-image-alt text-warning"></i><h2 class="h6 mb-0" id="spiralBgTitle">Fondo de la espiral de cortes</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('animations.loader.update') }}" enctype="multipart/form-data" class="row g-3 align-items-start">
                @csrf
                @method('PUT')
                <div class="col-md-5">
                    <div class="anim-preview">@if(!empty($settings['spiral_image_path']))<img src="{{ asset('storage/'.$settings['spiral_image_path']) }}" alt="Fondo actual de la espiral" style="width:100%;height:100%;object-fit:cover;filter:brightness(.4)">@else<span class="text-muted small"><i class="bi bi-image"></i> Sin imagen (fondo oscuro)</span>@endif</div>
                </div>
                <div class="col-md-7">
                    <label class="form-label fw-semibold" for="spiralImage">Cambiar imagen de fondo</label>
                    <input id="spiralImage" type="file" name="spiral_image" accept="image/*" class="form-control">
                    @if(!empty($settings['spiral_image_path']))
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_spiral_image" value="1" id="removeSpiralBg"><label class="form-check-label small" for="removeSpiralBg">Quitar imagen (dejar fondo oscuro)</label></div>
                    @endif
                    <div class="form-text">Opcional. Si subes una, se ve oscurecida detrás de la espiral; si no, el fondo queda oscuro.</div>
                    <button class="btn btn-sm btn-gold mt-3"><i class="bi bi-check2 me-1"></i> Guardar fondo</button>
                </div>
            </form>
        </div>
    </section>

    {{-- 0c. Fondo de Contacto --}}
    <section class="card mb-4" aria-labelledby="contactBgTitle">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-geo-alt text-warning"></i><h2 class="h6 mb-0" id="contactBgTitle">Fondo de la pestaña Contacto</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('animations.loader.update') }}" enctype="multipart/form-data" class="row g-3 align-items-start">
                @csrf
                @method('PUT')
                <div class="col-md-5">
                    <div class="anim-preview">@if(!empty($settings['contact_image_path']))<img src="{{ asset('storage/'.$settings['contact_image_path']) }}" alt="Fondo actual de Contacto" style="width:100%;height:100%;object-fit:cover;filter:brightness(.4)">@else<span class="text-muted small"><i class="bi bi-image"></i> Sin imagen (fondo oscuro)</span>@endif</div>
                </div>
                <div class="col-md-7">
                    <label class="form-label fw-semibold" for="contactImage">Cambiar imagen de fondo</label>
                    <input id="contactImage" type="file" name="contact_image" accept="image/*" class="form-control">
                    @if(!empty($settings['contact_image_path']))
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_contact_image" value="1" id="removeContactBg"><label class="form-check-label small" for="removeContactBg">Quitar imagen (dejar fondo oscuro)</label></div>
                    @endif
                    <div class="form-text">Opcional. Si subes una, se ve oscurecida detrás del título de Contacto; si no, el fondo queda oscuro.</div>
                    <button class="btn btn-sm btn-gold mt-3"><i class="bi bi-check2 me-1"></i> Guardar fondo</button>
                </div>
            </form>
        </div>
    </section>

    {{-- 1. Pantalla de carga --}}
    <section class="card mb-4" aria-labelledby="loaderTitle">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-hourglass-split text-warning"></i><h2 class="h6 mb-0" id="loaderTitle">Pantalla de carga</h2></div>
        <div class="card-body">
            <p class="small text-muted">Al abrir la página aparece un número gigante que cuenta de 00 a 100. La imagen o el video que subas se ve <strong>dentro del número</strong>. Si subes video, se usa el video; si no hay ninguno, salen franjas de barbero.</p>
            <form method="POST" action="{{ route('animations.loader.update') }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="loaderImage">Imagen</label>
                        <div class="anim-preview mb-2">
                            @if($settings['loader_image_path'] ?? null)
                                <div class="anim-digits" style="background-image:url('{{ asset('storage/'.$settings['loader_image_path']) }}')">00</div>
                            @else
                                <span class="text-muted small"><i class="bi bi-image"></i> Sin imagen</span>
                            @endif
                        </div>
                        <input id="loaderImage" type="file" name="loader_image" accept="image/*" class="form-control">
                        @if($settings['loader_image_path'] ?? null)
                            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_loader_image" value="1" id="removeLoaderImage"><label class="form-check-label small" for="removeLoaderImage">Quitar imagen</label></div>
                        @endif
                        <div class="form-text">JPG, PNG o WebP hasta 20 MB. Mejor una foto con buena luz (vertical u horizontal).</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="loaderVideo">Video (opcional)</label>
                        <div class="anim-preview mb-2">
                            @if($settings['loader_video_path'] ?? null)
                                <video src="{{ asset('storage/'.$settings['loader_video_path']) }}" muted loop playsinline autoplay preload="metadata"></video>
                            @else
                                <span class="text-muted small"><i class="bi bi-camera-video"></i> Sin video</span>
                            @endif
                        </div>
                        <input id="loaderVideo" type="file" name="loader_video" accept="video/mp4,video/webm,video/quicktime" class="form-control">
                        @if($settings['loader_video_path'] ?? null)
                            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_loader_video" value="1" id="removeLoaderVideo"><label class="form-check-label small" for="removeLoaderVideo">Quitar video</label></div>
                        @endif
                        <div class="form-text">MP4, WebM o MOV hasta 50 MB. Se reproduce en silencio y en bucle. Recomendado: corto (5–10 s) y liviano.</div>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-3"><button class="btn btn-gold"><i class="bi bi-check2 me-1"></i> Guardar pantalla de carga</button></div>
            </form>
        </div>
    </section>

    {{-- 3. Espiral: cortes (imágenes o videos) --}}
    <section class="card mb-4" aria-labelledby="cutsTitle">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2"><i class="bi bi-scissors text-warning"></i><h2 class="h6 mb-0" id="cutsTitle">Espiral de cortes · imágenes y videos</h2></div>
            <a href="{{ route('featured-cuts.create') }}" class="btn btn-sm btn-gold"><i class="bi bi-plus-lg me-1"></i> Agregar corte</a>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('animations.cuts.store') }}" enctype="multipart/form-data" class="anim-bulk mb-4">
                @csrf
                <label class="form-label fw-semibold" for="bulkMedia"><i class="bi bi-cloud-arrow-up me-1"></i> Subir imágenes o videos</label>
                <input id="bulkMedia" type="file" name="media[]" multiple accept="image/jpeg,image/png,image/webp,image/avif,image/gif,video/mp4,video/webm,video/quicktime" class="form-control">
                <div class="form-text">Puedes elegir <strong>varias imágenes y videos a la vez</strong>, sin límite de cantidad. Imágenes JPG, PNG o WebP; videos MP4, WebM o MOV (hasta 50 MB cada archivo). Las tarjetas son cuadradas (1:1): lo que no sea cuadrado se recorta al centro.</div>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">
                    <span class="form-text m-0">Cada archivo se vuelve una tarjeta de la espiral (el nombre del archivo queda como título; luego lo puedes editar).</span>
                    <button class="btn btn-sm btn-gold"><i class="bi bi-upload me-1"></i> Subir a la espiral</button>
                </div>
            </form>
            <p class="small text-muted">Cada corte es una tarjeta que gira alrededor del texto del centro. Puede tener <strong>imagen, video o ambos</strong> (el video se reproduce y la imagen sirve de portada). Tienes {{ $cuts->count() }} {{ $cuts->count() === 1 ? 'corte' : 'cortes' }}; puedes agregar todos los que quieras.</p>
            <div class="row g-4">
                @forelse($cuts as $cut)
                    <div class="col-sm-6 col-xl-3">
                        <article class="card h-100 overflow-hidden">
                            <div class="anim-cut">
                                @if($cut->video_path)
                                    <video src="{{ asset('storage/'.$cut->video_path) }}" @if($cut->image_path) poster="{{ asset('storage/'.$cut->image_path) }}" @endif muted loop playsinline preload="metadata" onmouseenter="this.play()" onmouseleave="this.pause()"></video>
                                    <span class="anim-badge"><i class="bi bi-camera-video-fill"></i> Video</span>
                                @elseif($cut->image_path)
                                    <img src="{{ asset('storage/'.$cut->image_path) }}" alt="{{ $cut->title }}">
                                @else
                                    <img src="{{ asset('images/logo-una-barberia.png') }}" alt="{{ $cut->title }}" style="object-fit:contain;padding:16px">
                                @endif
                            </div>
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <div>
                                        <div class="fw-semibold">{{ $cut->title }}</div>
                                        <div class="small text-muted">Posición {{ $cut->sort_order + 1 }}{{ $cut->service ? ' · '.$cut->service->name : '' }}</div>
                                    </div>
                                    <span class="badge-soft {{ $cut->active ? 'green' : 'red' }}">{{ $cut->active ? 'Visible' : 'Oculto' }}</span>
                                </div>
                                <div class="d-flex gap-2 mt-3">
                                    <a href="{{ route('featured-cuts.edit', $cut) }}" class="btn btn-sm btn-outline-dark"><i class="bi bi-pencil me-1"></i> Editar</a>
                                    <form method="POST" action="{{ route('featured-cuts.destroy', $cut) }}" onsubmit="return confirm('¿Quitar este corte de la espiral? Se borrarán su imagen y su video.')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i> Quitar</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="empty-state"><i class="bi bi-scissors"></i>Aún no hay cortes en la espiral. Mientras tanto se usan las fotos de los servicios de corte (si tienen).<div class="mt-3"><a href="{{ route('featured-cuts.create') }}" class="btn btn-gold">Agregar el primer corte</a></div></div>
                    </div>
                @endforelse
            </div>
        </div>
    </section>
@endsection

@push('styles')
<style>
    .anim-preview { height: 200px; border-radius: 10px; display: grid; place-items: center; overflow: hidden; background: #0a0807; }
    .anim-preview video { width: 100%; height: 100%; object-fit: cover; }
    .anim-digits { font: 800 9rem/1 Montserrat, system-ui, sans-serif; letter-spacing: -.06em; color: transparent; -webkit-background-clip: text; background-clip: text; background-size: cover; background-position: center; }
    .anim-cut { position: relative; aspect-ratio: 4 / 5; background: #14110d; display: grid; place-items: center; overflow: hidden; }
    .anim-cut img, .anim-cut video { width: 100%; height: 100%; object-fit: cover; }
    .anim-badge { position: absolute; left: 10px; top: 10px; padding: 3px 9px; border-radius: 999px; background: rgba(0,0,0,.65); color: #f4efe6; font-size: .72rem; }
</style>
@endpush
