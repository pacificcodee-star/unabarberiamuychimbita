@extends('layouts.public')

@section('title', 'Servicios · '.($siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita'))
@section('meta_description', $siteSettings['services_hero_description'] ?? $siteSettings['footer_description'])

@section('content')
    @php
        $usedCategories = $services->map(fn ($service) => $service->categoryKey())->unique()->values();
        $categoryLabels = \App\Models\Service::CATEGORIES;
        $total = $services->count();
    @endphp

    <section class="svc-page" aria-labelledby="svc-title">
        <h1 id="svc-title" class="visually-hidden">{{ $siteSettings['services_hero_title'] }}</h1>

        {{-- El espacio de la cabecera fija; el fondo de toda la página es el mismo negro del escenario. --}}
        <div class="svc-head-spacer"></div>

        @if($services->isNotEmpty())
            <div class="svc-carousel" data-svc-carousel role="region" aria-roledescription="carrusel" aria-label="Servicios de la barbería">
                <div class="svc-stage">
                    <div class="svc-gridlines" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>

                    {{-- Selector fijo: vive fuera de las diapositivas para que no se mueva al cambiar de servicio. --}}
                    @if($usedCategories->count() > 1)
                        <div class="container svc-stage-head">
                            <div class="svc-filters" role="group" aria-label="Filtrar servicios por categoría" data-svc-filters>
                                <button type="button" class="is-on" data-filter="all" aria-pressed="true">Todos <span>{{ $total }}</span></button>
                                @foreach($categoryLabels as $key => $label)
                                    @if($usedCategories->contains($key))
                                        <button type="button" data-filter="{{ $key }}" aria-pressed="false">{{ $label }} <span>{{ $services->filter(fn ($s) => $s->categoryKey() === $key)->count() }}</span></button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="svc-track">
                    @foreach($services as $service)
                        @php
                            $cat = $service->categoryKey();
                            // Versión WebP optimizada (si existe) de la infografía subida en el panel.
                            $svcImage = null;
                            if ($service->image_path) {
                                $webp = preg_replace('/\.(png|jpe?g)$/i', '.webp', $service->image_path);
                                $svcImage = asset('storage/'.(\Illuminate\Support\Facades\Storage::disk('public')->exists($webp) ? $webp : $service->image_path));
                            }
                        @endphp
                        <article class="svc-slide cat-{{ $cat }} {{ $loop->first ? 'is-active' : '' }}"
                                 data-svc-slide data-index="{{ $loop->index }}" data-category="{{ $cat }}"
                                 @if($svcImage) style="--svc-image: url('{{ $svcImage }}')" @endif
                                 role="group" aria-roledescription="diapositiva" aria-label="{{ $loop->iteration }} de {{ $total }}: {{ $service->name }}"
                                 @if(!$loop->first) aria-hidden="true" inert @endif>
                            <div class="container svc-slide-inner">
                                {{-- La infografía ya incluye el nombre y los detalles: se usa completa como fondo. --}}
                                <div class="svc-visual" aria-hidden="true">
                                    @unless($service->image_path)
                                        {{-- Sin imagen: se muestra el logo del negocio --}}
                                        <img class="is-logo" src="{{ !empty($siteSettings['logo_path']) ? asset('storage/'.$siteSettings['logo_path']) : asset('images/logo-una-barberia.png') }}" alt="" width="800" height="800" loading="{{ $loop->index < 2 ? 'eager' : 'lazy' }}" decoding="async">
                                    @endunless
                                </div>

                                {{-- El nombre y la descripción se conservan para lectores de pantalla y buscadores. --}}
                                <h2 class="visually-hidden">{{ $service->name }}</h2>
                                @php
                                    $included = $service->includedItems();
                                    $notes = $service->descriptionNotes();
                                @endphp
                                @if($included)
                                    <div class="svc-includes reveal-line">
                                        <div>
                                            <h3 class="svc-includes-title">Incluye</h3>
                                            <ul>
                                                @foreach($included as $item)
                                                    <li><i class="bi bi-check2" aria-hidden="true"></i> {{ $item }}</li>
                                                @endforeach
                                            </ul>
                                            @foreach($notes as $note)
                                                <p class="svc-note"><i class="bi bi-info-circle" aria-hidden="true"></i> {{ $note }}</p>
                                            @endforeach
                                        </div>
                                    </div>
                                @elseif($service->description)
                                    <p class="svc-includes svc-note">{{ $service->description }}</p>
                                @endif

                                <div class="svc-meta">
                                    <div class="svc-price reveal-line">
                                        <div>
                                            <small>Precio</small>
                                            <strong>{{ format_money($service->price) }} <span>COP</span></strong>
                                        </div>
                                    </div>
                                    <div class="svc-cta reveal-line">
                                        <div><a href="{{ route('public.booking', ['servicio' => $service->id]) }}" class="btn btn-gold"><i class="bi bi-calendar2-check" aria-hidden="true"></i> Reservar este servicio</a></div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    @endforeach

                    <div class="svc-progress" aria-hidden="true">
                        @foreach($services as $service)
                            <span data-progress="{{ $loop->index }}" class="{{ $loop->first ? 'is-on' : '' }}"></span>
                        @endforeach
                    </div>

                    @if($total > 1)
                        <div class="container svc-controls">
                            <span class="svc-counter" aria-live="polite"><strong data-svc-current>01</strong> / <span data-svc-total>{{ str_pad((string) $total, 2, '0', STR_PAD_LEFT) }}</span></span>
                            <span class="svc-swipe-hint" aria-hidden="true"><i class="bi bi-hand-index"></i> Desliza para ver más</span>
                            <div class="svc-buttons" role="group" aria-label="Cambiar servicio">
                                <button type="button" class="svc-arrow svc-arrow--previous" data-svc-step="-1" aria-label="Servicio anterior"><i class="bi bi-arrow-left" aria-hidden="true"></i></button>
                                <button type="button" class="svc-arrow svc-arrow--next" data-svc-step="1" aria-label="Servicio siguiente"><i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                            </div>
                        </div>
                    @endif
                    </div>{{-- /.svc-track --}}
                </div>

            </div>
        @else
            <div class="container">
                <div class="empty-state mb-5">
                    <i class="bi bi-scissors" aria-hidden="true"></i>
                    <h3>Estamos preparando la carta</h3>
                    <p>Pronto encontrarás aquí nuestros servicios disponibles. Mientras tanto, escríbenos y te contamos.</p>
                    @if(!empty($siteSettings['whatsapp']))
                        <a href="https://wa.me/{{ $siteSettings['whatsapp'] }}" class="btn btn-ghost-light mt-2" target="_blank" rel="noopener"><i class="bi bi-whatsapp" aria-hidden="true"></i> Escríbenos</a>
                    @endif
                </div>
            </div>
        @endif
    </section>
@endsection

@push('scripts')
<script>
    (() => {
        const root = document.querySelector('[data-svc-carousel]');
        if (!root) return;
        const slides = [...root.querySelectorAll('[data-svc-slide]')];
        const thumbs = [...root.querySelectorAll('[data-go]')];
        const thumbList = root.querySelector('[data-svc-thumbs]');
        const bars = [...root.querySelectorAll('[data-progress]')];
        const counter = root.querySelector('[data-svc-current]');
        const totalLabel = root.querySelector('[data-svc-total]');
        const autoplayButton = root.querySelector('[data-svc-autoplay]');
        const stepButtons = [...root.querySelectorAll('[data-svc-step]')];
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        const pad = (n) => String(n).padStart(2, '0');

        let order = slides.map((_, i) => i);   // índices visibles según el filtro
        let current = 0;
        let busy = false;
        let timer;
        let pausedByVisitor = false;
        let hovered = false;
        let focused = false;

        root.classList.add('is-enhanced');

        const go = (target, direction = 1) => {
            if (target === current || busy || !slides[target]) return;
            const from = slides[current];
            const to = slides[target];
            busy = !reduced.matches;

            slides.forEach((s) => s.classList.remove('from-left', 'from-right', 'leave-left', 'leave-right'));
            to.classList.add(direction > 0 ? 'from-right' : 'from-left');
            from.classList.add(direction > 0 ? 'leave-left' : 'leave-right');
            void to.offsetWidth;
            from.classList.remove('is-active');
            to.classList.add('is-active');
            from.setAttribute('aria-hidden', 'true'); from.setAttribute('inert', '');
            to.removeAttribute('aria-hidden'); to.removeAttribute('inert');

            current = target;
            const position = order.indexOf(target);
            if (counter) counter.textContent = pad(position + 1);
            thumbs.forEach((t) => {
                const on = Number(t.dataset.go) === target;
                t.classList.toggle('is-on', on);
                on ? t.setAttribute('aria-current', 'true') : t.removeAttribute('aria-current');
                if (on && thumbList) {
                    const item = t.parentElement;
                    thumbList.scrollTo({ left: item.offsetLeft - (thumbList.clientWidth - item.offsetWidth) / 2, behavior: reduced.matches ? 'auto' : 'smooth' });
                }
            });
            bars.forEach((b) => b.classList.toggle('is-on', Number(b.dataset.progress) === target));
            window.setTimeout(() => { busy = false; from.classList.remove('leave-left', 'leave-right'); }, reduced.matches ? 0 : 950);
        };
        const step = (delta) => {
            const position = order.indexOf(current);
            const next = order[(position + delta + order.length) % order.length];
            go(next, delta);
        };

        stepButtons.forEach((button) => button.addEventListener('click', () => {
            step(Number(button.dataset.svcStep));
            schedule();
        }));

        thumbs.forEach((t) => t.addEventListener('click', () => {
            const target = Number(t.dataset.go);
            go(target, order.indexOf(target) >= order.indexOf(current) ? 1 : -1);
            schedule();
        }));
        root.addEventListener('keydown', (event) => {
            if (event.target.closest('input, textarea, select')) return;
            if (event.key === 'ArrowRight') { step(1); schedule(); }
            if (event.key === 'ArrowLeft') { step(-1); schedule(); }
        });

        // Cambiar de servicio deslizando: con el dedo (izquierda → siguiente, derecha → anterior) o arrastrando con el mouse.
        const stage = root.querySelector('.svc-stage');
        let startX = null;
        let startY = null;
        const swipe = (endX, endY) => {
            if (startX === null) return;
            const dx = endX - startX;
            const dy = endY - startY;
            startX = null;
            if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.2) { step(dx < 0 ? 1 : -1); schedule(); }
        };
        // Táctil (celulares): touch events funcionan aunque el navegador esté manejando el scroll vertical.
        stage.addEventListener('touchstart', (e) => {
            if (e.touches.length !== 1) { startX = null; return; }
            startX = e.touches[0].clientX; startY = e.touches[0].clientY;
        }, { passive: true });
        stage.addEventListener('touchend', (e) => { const t = e.changedTouches[0]; swipe(t.clientX, t.clientY); }, { passive: true });
        stage.addEventListener('touchcancel', () => { startX = null; }, { passive: true });
        // Mouse (computador): arrastrar a un lado.
        stage.addEventListener('pointerdown', (e) => {
            if (e.pointerType === 'touch' || e.target.closest('a, button')) return;
            startX = e.clientX; startY = e.clientY;
        });
        stage.addEventListener('pointerup', (e) => { if (e.pointerType !== 'touch') swipe(e.clientX, e.clientY); });
        stage.addEventListener('pointercancel', (e) => { if (e.pointerType !== 'touch') startX = null; });

        // Reproducción automática: avanza hacia la derecha, se pausa al pasar el mouse o enfocar.
        function schedule() {
            window.clearInterval(timer);
            if (pausedByVisitor || hovered || focused || document.hidden || reduced.matches || order.length < 2) return;
            timer = window.setInterval(() => step(1), 6000);
        }
        autoplayButton?.addEventListener('click', () => {
            pausedByVisitor = !pausedByVisitor;
            autoplayButton.setAttribute('aria-pressed', pausedByVisitor ? 'true' : 'false');
            autoplayButton.setAttribute('aria-label', `${pausedByVisitor ? 'Reanudar' : 'Pausar'} la presentación de servicios`);
            autoplayButton.querySelector('span').textContent = pausedByVisitor ? 'Reanudar' : 'Pausar';
            autoplayButton.querySelector('i').className = pausedByVisitor ? 'bi bi-play-fill' : 'bi bi-pause-fill';
            schedule();
        });
        stage.addEventListener('mouseenter', () => { hovered = true; schedule(); });
        stage.addEventListener('mouseleave', () => { hovered = false; schedule(); });
        root.addEventListener('focusin', () => { focused = true; schedule(); });
        root.addEventListener('focusout', (e) => { if (!root.contains(e.relatedTarget)) { focused = false; schedule(); } });
        document.addEventListener('visibilitychange', schedule);

        // Filtros: la barra es única y está fuera de las diapositivas, así que no se mueve al cambiar de servicio.
        const filters = document.querySelector('[data-svc-filters]');
        filters?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-filter]');
            if (!button) return;
            const filter = button.dataset.filter;
            filters.querySelectorAll('[data-filter]').forEach((b) => {
                const on = b === button;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            order = slides.map((s, i) => (filter === 'all' || s.dataset.category === filter ? i : -1)).filter((i) => i > -1);
            thumbs.forEach((t) => { t.parentElement.hidden = !order.includes(Number(t.dataset.go)); });
            bars.forEach((b) => { b.hidden = !order.includes(Number(b.dataset.progress)); });
            if (totalLabel) totalLabel.textContent = pad(order.length);
            busy = false;
            // Enfocar la categoría elegida: muestra su primer servicio…
            if (order[0] !== current) go(order[0], 1);
            else if (counter) counter.textContent = pad(1);
            // …lleva la vista al escenario y centra el botón elegido en la barra (útil en celular).
            const smooth = reduced.matches ? 'auto' : 'smooth';
            if (filters.scrollWidth > filters.clientWidth) {
                const b = button.getBoundingClientRect(), f = filters.getBoundingClientRect();
                filters.scrollTo({ left: filters.scrollLeft + (b.left - f.left) - (f.width - b.width) / 2, behavior: smooth });
            }
            const top = stage.getBoundingClientRect().top + window.scrollY - (parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header-h')) || 70) - 16;
            if (Math.abs(window.scrollY - top) > 8) window.scrollTo({ top: Math.max(0, top), behavior: smooth });
            schedule();
        });

        schedule();
    })();
</script>
@endpush
