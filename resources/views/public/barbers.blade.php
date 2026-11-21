@extends('layouts.public')

@section('title', 'Barberos · '.($siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita'))
@section('meta_description', $siteSettings['barbers_hero_description'] ?? $siteSettings['footer_description'])

@section('content')
    @if($barbers->isNotEmpty())
        @php $barberTotal = $barbers->count(); @endphp
        {{-- Equipo: carrusel horizontal. Se pasa de barbero con las flechas, arrastrando con el mouse,
             deslizando con el dedo o con las flechas del teclado. El video de cada barbero se repite en bucle. --}}
        <section class="bx" data-bx data-cx aria-roledescription="carrusel" aria-label="Equipo de barberos">
            <h1 class="visually-hidden">{{ $siteSettings['barbers_hero_title'] }}</h1>

            <div class="bx-viewport" data-bx-viewport>
                @foreach($barbers as $barber)
                    @php
                        $photoExtension = $barber->photo_path ? strtolower(pathinfo($barber->photo_path, PATHINFO_EXTENSION)) : null;
                        $isCutout = in_array($photoExtension, ['png', 'webp'], true);
                        $bookingType = $barber->attention_mode === 'citas' ? 'cita' : 'turno';
                        $firstName = (string) \Illuminate\Support\Str::of($barber->name)->before(' ');
                        $specialties = $barber->specialty ? array_filter(array_map('trim', preg_split('/[,;|]+/', $barber->specialty))) : [];
                        $cutsCount = \App\Support\BarberCuts::for($barber)->count();
                    @endphp
                    <article class="bx-slide {{ $loop->first ? 'is-active' : '' }}" data-bx-slide data-barber="{{ $barber->id }}" data-name="{{ $firstName }}"
                             role="group" aria-roledescription="diapositiva" aria-label="{{ $barber->name }}"
                             @if(!$loop->first) aria-hidden="true" inert @endif>
                        {{-- Nombre gigante detrás del barbero: da profundidad y se mueve con el mouse --}}
                        <div class="bx-word" aria-hidden="true"><span data-bx-word>{{ $firstName }}</span></div>

                        <div class="bx-figure {{ $barber->video_path ? 'is-video' : ($barber->photo_path ? ($isCutout ? 'is-cutout' : 'is-photo') : 'is-empty') }}">
                            @include('public.partials.barber-media', ['barber' => $barber, 'mediaPreload' => $loop->first ? 'auto' : 'metadata'])
                        </div>

                        <div class="bx-copy">
                            <span class="bx-mode">
                                <i class="bi {{ $barber->attention_mode === 'citas' ? 'bi-calendar2-week' : 'bi-person-walking' }}" aria-hidden="true"></i>
                                {{ $barber->attentionModeLabel() }}
                            </span>
                            <h2 class="bx-name" data-bx-name>{{ $barber->name }}</h2>
                            @if($specialties)
                                <ul class="bx-tags" aria-label="Especialidades">
                                    @foreach($specialties as $specialty)
                                        <li>{{ $specialty }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if($barber->bio)
                                <p class="bx-bio">{{ $barber->bio }}</p>
                            @endif
                            <div class="bx-actions">
                                <a href="{{ route('public.booking', ['barbero' => $barber->id, 'tipo' => $bookingType]) }}" class="xbtn">
                                    <span>{{ $bookingType === 'cita' ? 'Reservar con '.$firstName : 'Tomar turno con '.$firstName }}</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </a>
                                <a href="#cortes-{{ $barber->id }}" class="xlink">Ver sus cortes @if($cutsCount)({{ $cutsCount }})@endif</a>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="bx-controls">
                @if($barberTotal > 1)
                    <button type="button" class="bx-arrow" data-bx-prev aria-label="Barbero anterior"><i class="bi bi-arrow-left" aria-hidden="true"></i></button>
                    <div class="bx-dots" role="group" aria-label="Elegir barbero">
                        @foreach($barbers as $barber)
                            <button type="button" class="{{ $loop->first ? 'is-active' : '' }}" data-bx-go="{{ $loop->index }}" aria-label="Ver a {{ $barber->name }}" @if($loop->first) aria-current="true" @endif>
                                <span>{{ \Illuminate\Support\Str::of($barber->name)->before(' ') }}</span>
                            </button>
                        @endforeach
                    </div>
                    <button type="button" class="bx-arrow" data-bx-next aria-label="Barbero siguiente"><i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                @endif
            </div>
        </section>

        {{-- Cortes realizados por cada barbero (se suben en el panel: Barberos → editar → "Cortes realizados").
             Solo se muestra la galería del barbero que está en pantalla. --}}
        @foreach($barbers as $barber)
            @php $firstName = (string) \Illuminate\Support\Str::of($barber->name)->before(' '); @endphp
            @include('public.partials.work-gallery', [
                'galleryId' => 'cortes-'.$barber->id,
                'kicker' => 'Cortes realizados',
                'title' => 'Cortes de '.$firstName,
                'lead' => 'Trabajos hechos por '.$barber->name.' en nuestra barbería.',
                'items' => \App\Support\BarberCuts::for($barber)->map(fn ($cut) => \Illuminate\Support\Arr::except($cut, ['barber'])),
                'hidden' => !$loop->first,
                'ghost' => false,
                'emptyText' => $firstName.' todavía no ha publicado fotos de sus cortes.',
            ])
        @endforeach
    @else
        <section class="section">
            <div class="container">
                <div class="empty-state">
                    <i class="bi bi-people" aria-hidden="true"></i>
                    <h3>Muy pronto conocerás al equipo</h3>
                    <p>Estamos preparando los perfiles de nuestros barberos.</p>
                </div>
            </div>
        </section>
    @endif
@endsection

@push('scripts')
<script>
    (() => {
        const root = document.querySelector('[data-bx]');
        if (!root) return;
        const slides = [...root.querySelectorAll('[data-bx-slide]')];
        const dots = [...root.querySelectorAll('[data-bx-go]')];
        const viewport = root.querySelector('[data-bx-viewport]');
        const pauseButton = root.querySelector('[data-bx-pause]');
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        let current = 0;
        let pausedByVisitor = reduced.matches; // con "reducir movimiento" el video no arranca solo
        let visible = true;

        // En móvil (y tablets) los barberos se pasan solos cada pocos segundos. Cualquier toque,
        // deslizamiento o tecla toma el control y detiene el pase automático un rato.
        const autoScreen = window.matchMedia('(max-width: 1099.98px)');
        const AUTO_MS = 7000;
        let autoTimer = 0;
        let userPauseUntil = 0;
        let touching = false;
        const holdAuto = () => { userPauseUntil = Date.now() + 15000; };
        const scheduleAuto = () => {
            window.clearTimeout(autoTimer);
            if (!autoScreen.matches || reduced.matches) return;
            autoTimer = window.setTimeout(function tick() {
                if (!document.hidden && !touching && Date.now() > userPauseUntil) go(current + 1, 1);
                scheduleAuto();
            }, AUTO_MS);
        };

        root.classList.add('is-enhanced');

        // Nombre: se parte en letras para que entren una por una (accesible: el lector de pantalla lee el nombre completo).
        slides.forEach((slide) => {
            slide.querySelectorAll('[data-bx-name], [data-bx-word]').forEach((el) => {
                const text = el.textContent.trim();
                if (el.hasAttribute('data-bx-name')) el.setAttribute('aria-label', text);
                el.textContent = '';
                // Cada palabra va en un bloque que no se parte: el nombre solo baja de línea entre palabras.
                let i = 0;
                text.split(/\s+/).forEach((word, w, all) => {
                    const wrap = document.createElement('span');
                    wrap.className = 'bx-w';
                    wrap.setAttribute('aria-hidden', 'true');
                    [...word].forEach((ch) => {
                        const span = document.createElement('span');
                        span.className = 'bx-ch';
                        span.style.setProperty('--c', i++);
                        span.textContent = ch;
                        wrap.appendChild(span);
                    });
                    el.appendChild(wrap);
                    if (w < all.length - 1) { el.appendChild(document.createTextNode(' ')); i++; }
                });
            });
        });

        // Videos: el del barbero en pantalla se repite en bucle; los demás quedan en pausa.
        const mediaOf = (slide) => slide.querySelector('[data-barber-video]');
        const play = (el) => (el.tagName === 'VIDEO' ? el.play().catch(() => {}) : el.dispatchEvent(new Event('alpha:play')));
        const pause = (el) => (el.tagName === 'VIDEO' ? el.pause() : el.dispatchEvent(new Event('alpha:pause')));
        const syncVideos = () => slides.forEach((slide, index) => {
            const media = mediaOf(slide);
            if (!media) return;
            if (index === current && visible && !document.hidden && !pausedByVisitor) play(media); else pause(media);
        });
        const hasVideo = slides.some((slide) => mediaOf(slide));
        const updatePauseButton = () => {
            if (!pauseButton) return;
            pauseButton.hidden = !mediaOf(slides[current]);
            pauseButton.setAttribute('aria-pressed', pausedByVisitor ? 'true' : 'false');
            pauseButton.querySelector('span').textContent = pausedByVisitor ? 'Reproducir video' : 'Pausar video';
            pauseButton.querySelector('i').className = pausedByVisitor ? 'bi bi-play-fill' : 'bi bi-pause-fill';
        };
        pauseButton?.addEventListener('click', () => { pausedByVisitor = !pausedByVisitor; updatePauseButton(); syncVideos(); });

        // Galería de cortes: solo la del barbero activo.
        const showGallery = () => {
            const id = slides[current].dataset.barber;
            document.querySelectorAll('[data-wg][id^="cortes-"]').forEach((gallery) => { gallery.hidden = gallery.id !== `cortes-${id}`; });
            document.dispatchEvent(new CustomEvent('wg:refresh'));
        };

        const go = (target, direction) => {
            const next = (target + slides.length) % slides.length;
            if (next === current) return;
            const dir = direction ?? (next > current ? 1 : -1);
            root.dataset.dir = dir > 0 ? 'next' : 'prev';
            const from = slides[current];
            const to = slides[next];
            slides.forEach((slide) => slide.classList.remove('is-leaving'));
            from.classList.add('is-leaving');
            from.classList.remove('is-active');
            from.setAttribute('aria-hidden', 'true'); from.setAttribute('inert', '');
            void to.offsetWidth; // reinicia la animación de entrada
            to.classList.add('is-active');
            to.removeAttribute('aria-hidden'); to.removeAttribute('inert');
            current = next;
            dots.forEach((dot, index) => {
                dot.classList.toggle('is-active', index === current);
                index === current ? dot.setAttribute('aria-current', 'true') : dot.removeAttribute('aria-current');
            });
            window.setTimeout(() => from.classList.remove('is-leaving'), 700);
            updatePauseButton();
            syncVideos();
            showGallery();
        };

        root.querySelector('[data-bx-prev]')?.addEventListener('click', () => { holdAuto(); go(current - 1, -1); });
        root.querySelector('[data-bx-next]')?.addEventListener('click', () => { holdAuto(); go(current + 1, 1); });
        dots.forEach((dot) => dot.addEventListener('click', () => { holdAuto(); go(Number(dot.dataset.bxGo)); }));
        root.addEventListener('keydown', (event) => {
            if (event.target.closest('input, textarea, select')) return;
            if (event.key === 'ArrowRight') { holdAuto(); go(current + 1, 1); }
            if (event.key === 'ArrowLeft') { holdAuto(); go(current - 1, -1); }
        });

        // Arrastrar con el mouse o deslizar con el dedo (izquierda → siguiente, derecha → anterior).
        let startX = null;
        let startY = null;
        let dragged = false;
        const finish = (x, y) => {
            if (startX === null) return;
            const dx = x - startX;
            const dy = y - startY;
            startX = null;
            viewport.classList.remove('is-dragging');
            viewport.style.removeProperty('--drag');
            if (Math.abs(dx) > 56 && Math.abs(dx) > Math.abs(dy) * 1.2) { dragged = true; holdAuto(); go(current + (dx < 0 ? 1 : -1), dx < 0 ? 1 : -1); }
        };
        viewport.addEventListener('touchstart', (e) => { touching = true; if (e.touches.length === 1) { startX = e.touches[0].clientX; startY = e.touches[0].clientY; } }, { passive: true });
        viewport.addEventListener('touchend', (e) => { touching = false; finish(e.changedTouches[0].clientX, e.changedTouches[0].clientY); }, { passive: true });
        viewport.addEventListener('touchcancel', () => { touching = false; startX = null; }, { passive: true });
        viewport.addEventListener('pointerdown', (e) => {
            if (e.pointerType === 'touch' || e.button !== 0 || e.target.closest('a, button')) return;
            startX = e.clientX; startY = e.clientY; dragged = false;
            viewport.classList.add('is-dragging');
        });
        window.addEventListener('pointermove', (e) => {
            if (startX === null || e.pointerType === 'touch') return;
            viewport.style.setProperty('--drag', `${Math.max(-120, Math.min(120, (e.clientX - startX) * 0.35)).toFixed(1)}px`);
        }, { passive: true });
        window.addEventListener('pointerup', (e) => { if (e.pointerType !== 'touch') finish(e.clientX, e.clientY); });
        viewport.addEventListener('click', (e) => { if (dragged) { e.preventDefault(); dragged = false; } }, true);
        viewport.addEventListener('dragstart', (e) => e.preventDefault());

        // Profundidad: el nombre gigante y la figura se mueven en sentidos opuestos con el mouse.
        if (finePointer && !reduced.matches) {
            let frame = 0;
            let mx = 0;
            let my = 0;
            root.addEventListener('pointermove', (e) => {
                const rect = root.getBoundingClientRect();
                mx = (e.clientX - rect.left) / rect.width - 0.5;
                my = (e.clientY - rect.top) / rect.height - 0.5;
                if (!frame) frame = requestAnimationFrame(() => { frame = 0; root.style.setProperty('--mx', mx.toFixed(3)); root.style.setProperty('--my', my.toFixed(3)); });
            }, { passive: true });
        }

        if ('IntersectionObserver' in window) {
            new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; syncVideos(); }, { threshold: 0.15 }).observe(root);
        }
        document.addEventListener('visibilitychange', syncVideos);
        if (document.readyState === 'complete') syncVideos(); else window.addEventListener('load', syncVideos, { once: true });
        document.addEventListener('DOMContentLoaded', () => { updatePauseButton(); syncVideos(); });
        if (hasVideo) updatePauseButton();

        autoScreen.addEventListener?.('change', scheduleAuto);
        reduced.addEventListener?.('change', scheduleAuto);
        scheduleAuto();
    })();
</script>
@endpush
