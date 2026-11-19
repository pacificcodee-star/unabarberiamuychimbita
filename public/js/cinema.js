/*
 * Scroll cinematográfico del sitio público.
 *  - Lenis suaviza el scroll (solo con mouse/trackpad) y se sincroniza con GSAP ScrollTrigger.
 *  - Portada: el fotograma se abre a pantalla completa mientras la sección queda fija (pin + scrub).
 *  - "La experiencia": video controlado por el scroll (currentTime ↔ progreso) o fotogramas si no hay video.
 *  - Espiral, frase y propósito del inicio: mismas animaciones de antes, ahora dirigidas por ScrollTrigger.
 * Con "reducir movimiento" o si GSAP no carga, todo queda en su estado final y legible (clase cx-static).
 */
(() => {
    const root = document.documentElement;
    const gsap = window.gsap;
    const ScrollTrigger = window.ScrollTrigger;
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');

    if (!gsap || !ScrollTrigger || reduced.matches) {
        root.classList.add('cx-static');
        return;
    }

    gsap.registerPlugin(ScrollTrigger);
    root.classList.add('cx-ready');

    const clamp = (v, min = 0, max = 1) => Math.min(max, Math.max(min, v));
    const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const mm = gsap.matchMedia();
    const DESKTOP = '(min-width: 992px)';
    const MOBILE = '(max-width: 991.98px)';
    const cleanups = [];

    /* ---------- 1. Lenis + ScrollTrigger ---------- */
    let lenis = null;
    if (window.Lenis && finePointer) {
        const headerH = () => parseFloat(getComputedStyle(root).getPropertyValue('--header-h')) || 80;
        lenis = new window.Lenis({
            lerp: 0.11,
            wheelMultiplier: 1,
            autoRaf: false, // el reloj lo lleva GSAP para que ambos avancen en el mismo fotograma
            anchors: { offset: -headerH() },
            // Zonas con scroll propio (avisos, mapa, ventanas modales, filtros): se dejan con scroll nativo.
            prevent: (node) => !!node.closest?.('[data-lenis-prevent], .bell-panel, .modal, .ct-map, .svc-filters, .pwa-prompt, textarea, select'),
        });
        lenis.on('scroll', ScrollTrigger.update);
        // Cada vez que GSAP recalcula la página (secciones fijadas, imágenes que cargan, cambio de tamaño) Lenis vuelve
        // a medir el alto: si no, se queda con una altura vieja y el scroll se detiene antes del final de la página.
        const syncLenis = () => lenis?.resize();
        ScrollTrigger.addEventListener('refresh', syncLenis);
        window.addEventListener('load', syncLenis, { once: true });
        cleanups.push(() => ScrollTrigger.removeEventListener('refresh', syncLenis));
        const raf = (time) => lenis.raf(time * 1000);
        gsap.ticker.add(raf);
        gsap.ticker.lagSmoothing(0);
        cleanups.push(() => { gsap.ticker.remove(raf); lenis.destroy(); lenis = null; });

        // Mientras hay pantalla de carga o el menú móvil está abierto, el scroll se detiene.
        const syncLock = () => {
            const locked = root.classList.contains('is-loading') || document.body.classList.contains('nav-locked');
            if (locked) lenis?.stop(); else lenis?.start();
        };
        const lockObserver = new MutationObserver(syncLock);
        lockObserver.observe(root, { attributes: true, attributeFilter: ['class'] });
        lockObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
        cleanups.push(() => lockObserver.disconnect());
        syncLock();
    }

    const whenLoaderDone = (callback) => {
        if (!root.classList.contains('is-loading')) { callback(); return; }
        const observer = new MutationObserver(() => {
            if (root.classList.contains('is-loading')) return;
            observer.disconnect();
            callback();
        });
        observer.observe(root, { attributes: true, attributeFilter: ['class'] });
    };

    /* ---------- 1b. Letras con profundidad ---------- */
    // Parte un titular en palabras y letras (conserva las cursivas <em>). El lector de pantalla sigue leyendo el texto completo.
    const splitDeep = (el) => {
        if (el.dataset.cxSplit) return [...el.querySelectorAll('.cx-char')];
        el.dataset.cxSplit = '1';
        if (!el.hasAttribute('aria-label')) el.setAttribute('aria-label', el.textContent.replace(/\s+/g, ' ').trim());
        const walk = (node) => {
            [...node.childNodes].forEach((child) => {
                if (child.nodeType === 1) { child.setAttribute('aria-hidden', 'true'); walk(child); return; }
                if (child.nodeType !== 3 || !child.textContent.trim()) return;
                const fragment = document.createDocumentFragment();
                child.textContent.split(/(\s+)/).forEach((part) => {
                    if (!part) return;
                    if (/^\s+$/.test(part)) { fragment.appendChild(document.createTextNode(' ')); return; }
                    const word = document.createElement('span');
                    word.className = 'cx-word';
                    word.setAttribute('aria-hidden', 'true');
                    [...part].forEach((ch) => { const c = document.createElement('span'); c.className = 'cx-char'; c.textContent = ch; word.appendChild(c); });
                    fragment.appendChild(word);
                });
                node.replaceChild(fragment, child);
            });
        };
        walk(el);
        return [...el.querySelectorAll('.cx-char')];
    };
    // Estado inicial: lejos (eje Z), desenfocadas y giradas; "from" decide de qué lado llegan.
    const DEPTH_FROM = {
        back: { z: -700, yPercent: 30, rotateX: -55 },
        below: { z: -260, yPercent: 120, rotateX: -80 },
        left: { z: -520, xPercent: -160, rotateY: 70 },
        right: { z: -520, xPercent: 160, rotateY: -70 },
        zoom: { z: 420, scale: 2.2 },
        spin: { z: -200, yPercent: 90, rotation: -38 },
        flip: { z: -160, rotateY: 95 },
    };
    const depthHide = (chars, from = 'back') => gsap.set(chars, { ...DEPTH_FROM[from], autoAlpha: 0, filter: 'blur(8px)', transformOrigin: '50% 100%' });
    const depthShow = (chars, options = {}) => gsap.to(chars, {
        z: 0, xPercent: 0, yPercent: 0, rotateX: 0, rotateY: 0, rotation: 0, scale: 1, autoAlpha: 1, filter: 'blur(0px)',
        duration: options.duration ?? 1.4, ease: 'expo.out', delay: options.delay ?? 0,
        stagger: { each: 0.065, from: options.order ?? 'start', amount: Math.min(2.2, chars.length * 0.065) },
        overwrite: true,
        onComplete: () => gsap.set(chars, { clearProps: 'filter,transform,willChange' }),
    });

    // Titulares de sección: llegan desde el fondo cuando entran en pantalla (lados alternos para dar ritmo).
    // "Tu pausa tiene su propio estilo.": entrada propia, línea por línea, con la última palabra resaltada.
    const filmTitle = document.querySelector('main .cx-film-title');
    if (filmTitle) {
        filmTitle.setAttribute('data-cx-chars', '');
        const chars = splitDeep(filmTitle);
        const words = [...filmTitle.querySelectorAll('.cx-word')];
        const last = words[words.length - 1];
        if (last) {
            last.classList.add('is-accent');
            const line = document.createElement('span');
            line.className = 'cx-film-underline';
            line.setAttribute('aria-hidden', 'true');
            last.appendChild(line);
        }
        // Línea de cada letra según dónde cae su palabra (así cada renglón entra después del anterior).
        const lineOf = new Map();
        let tops = [];
        const measure = () => {
            tops = [...new Set(words.map((w) => w.offsetTop))].sort((a, b) => a - b);
            words.forEach((w) => [...w.querySelectorAll('.cx-char')].forEach((c) => lineOf.set(c, tops.indexOf(w.offsetTop))));
        };
        measure();
        // Las letras van apareciendo una por una a medida que se baja (atado al scroll; al subir se esconden).
        // Cada renglón empieza cuando el anterior va por la mitad.
        gsap.set(chars, { yPercent: 90, rotateX: -80, autoAlpha: 0, filter: 'blur(6px)', transformOrigin: '50% 100%' });
        const redColor = last ? getComputedStyle(last).color : null;
        const baseColor = getComputedStyle(filmTitle).color;
        const reveal = gsap.timeline({
            defaults: { ease: 'power2.out' },
            scrollTrigger: {
                trigger: filmTitle, start: 'top 92%', end: 'top 28%', scrub: 0.6, invalidateOnRefresh: true,
                pinnedContainer: filmTitle.closest('[data-cx-film-stage]') || undefined,
                onRefresh: measure,
                onUpdate: (self) => filmTitle.classList.toggle('is-live', self.progress > 0.98),
            },
        });
        chars.forEach((c, i) => {
            const line = lineOf.get(c) || 0;
            reveal.to(c, { yPercent: 0, rotateX: 0, autoAlpha: 1, filter: 'blur(0px)', duration: 0.5 }, line * 0.6 + i * 0.07);
        });
        if (last && redColor) {
            gsap.set(last, { color: baseColor });
            reveal.to(last, { color: redColor, duration: 0.6 }, '>-0.3');
        }
        // La línea roja bajo "estilo." se dibuja mientras se recorre la sección.
        const underline = filmTitle.querySelector('.cx-film-underline');
        if (underline) {
            gsap.fromTo(underline, { scaleX: 0 }, { scaleX: 1, ease: 'none', scrollTrigger: { trigger: filmTitle.closest('[data-cx-film]') || filmTitle, start: 'top 30%', end: 'bottom bottom', scrub: 0.5, refreshPriority: -1 } });
        }
    }

    // Títulos de todas las secciones y páginas: las letras aparecen UNA POR UNA siguiendo el scroll
    // (al bajar aparecen, al subir se esconden). Funciona igual en computador y en celular.
    // Si el título ya se ve al cargar la página (parte de arriba), entra solo, despacio, al terminar la pantalla de carga.
    const lettersOnScroll = (el, { start = 'top 90%', end = 'top 38%' } = {}) => {
        el.setAttribute('data-cx-chars', ''); // motion.js no lo vuelve a partir
        const chars = splitDeep(el);
        if (!chars.length) return;
        gsap.set(chars, { yPercent: 100, rotateX: -75, autoAlpha: 0, filter: 'blur(6px)', transformOrigin: '50% 100%' });
        const show = { yPercent: 0, rotateX: 0, autoAlpha: 1, filter: 'blur(0px)', ease: 'power3.out' };
        const top = el.getBoundingClientRect().top;
        if (top < window.innerHeight * 0.82) {
            whenLoaderDone(() => {
                const tw = gsap.to(chars, { ...show, duration: 1.3, delay: 0.35, stagger: { each: 0.075 } });
                gsap.ticker.wake();
                // Seguro: un título nunca se queda invisible si el navegador no entrega fotogramas (pestaña en segundo plano).
                setTimeout(() => { if (tw.progress() < 0.05) tw.progress(1); }, 3500);
            });
            return;
        }
        const tl = gsap.timeline({ scrollTrigger: { trigger: el, start, end, scrub: 0.9, refreshPriority: -1 } });
        chars.forEach((c, i) => tl.to(c, { ...show, duration: 0.6 }, i * 0.12));
    };
    gsap.utils.toArray('main .purpose-title, main .purpose-label h3, main .cx-outro-title, main .page-hero h1, main .ct-title, main .section-title, main .cta-band h2, main .ct-subtitle, main .rv-title, main .cx-proof-title, main .svc-head h1').forEach((el) => lettersOnScroll(el));

    // Textos largos: palabra por palabra con el scroll ("Nuestra esencia", Misión y Visión).
    const wordsOnScroll = (el, words, { start = 'top 88%', end = 'center 52%' } = {}) => {
        if (!words.length) return;
        gsap.set(words, { yPercent: 70, autoAlpha: 0, filter: 'blur(4px)' });
        const tl = gsap.timeline({ scrollTrigger: { trigger: el, start, end, scrub: 0.9, refreshPriority: -1 } });
        words.forEach((w, i) => tl.to(w, { yPercent: 0, autoAlpha: 1, filter: 'blur(0px)', duration: 0.6, ease: 'power2.out' }, i * 0.18));
    };
    const quoteText = document.querySelector('.home-quote-text p');
    if (quoteText) wordsOnScroll(quoteText, [...quoteText.querySelectorAll('.qw')]);
    document.querySelectorAll('.purpose-body p').forEach((p) => {
        if (!p.dataset.cxSplit) {
            p.dataset.cxSplit = '1';
            p.setAttribute('aria-label', p.textContent.trim());
            p.innerHTML = p.textContent.trim().split(/\s+/).map((w) => `<span class="cx-w" aria-hidden="true">${w.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))}</span>`).join(' ');
        }
        wordsOnScroll(p, [...p.querySelectorAll('.cx-w')], { start: 'top 92%', end: 'bottom 60%' });
    });

    /* ---------- 2. Portada ---------- */
    const hero = document.querySelector('[data-cx-hero]');
    if (hero) {
        const frame = hero.querySelector('[data-cx-hero-frame]');
        const image = hero.querySelector('.cx-hero-img');
        const title = hero.querySelector('[data-cx-hero-title]');
        const lines = hero.querySelectorAll('[data-cx-line]');
        const extras = hero.querySelectorAll('[data-cx-in]');

        // Entrada: el fotograma se descubre, el titular sube línea por línea y luego llega el resto.
        if (title) title.setAttribute('aria-label', title.textContent.replace(/\s+/g, ' ').trim());
        if (title) title.setAttribute('data-echo', title.textContent.replace(/\s+/g, ' ').trim().split(/[,.]/)[0]);
        const heroChars = [...lines].flatMap((line) => splitDeep(line));
        lines.forEach((line) => { line.removeAttribute('aria-label'); line.setAttribute('aria-hidden', 'true'); });
        depthHide(heroChars, 'back');
        gsap.set(extras, { autoAlpha: 0, y: 26 });
        gsap.set(frame, { '--open': 0 });
        const intro = gsap.timeline({ paused: true, defaults: { ease: 'expo.out' } })
            .to(frame, { '--open': 1, duration: 1.7, ease: 'expo.inOut' }, 0)
            .add(depthShow(heroChars, { duration: 1.5, order: 'start' }), 0.25)
            .to(extras, { autoAlpha: 1, y: 0, duration: 1.1, stagger: 0.09 }, 0.75);
        whenLoaderDone(() => {
            intro.play();
            // Seguro: si el navegador no entregó fotogramas (pestaña recién mostrada, ahorro de energía),
            // la portada nunca se queda vacía: se despierta el reloj y, si sigue quieta, se muestra completa.
            gsap.ticker.wake();
            setTimeout(() => { if (intro.progress() < 0.05) { intro.progress(1); gsap.set(heroChars, { clearProps: 'all' }); } }, 3200);
        });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) gsap.ticker.wake(); });

        // Escritorio: la portada queda fija y el fotograma crece hasta ocupar toda la pantalla.
        mm.add(DESKTOP, () => {
            const tl = gsap.timeline({
                defaults: { ease: 'none' },
                scrollTrigger: { trigger: hero, start: 'top top', end: '+=95%', pin: true, scrub: true, anticipatePin: 1, invalidateOnRefresh: true },
            });
            tl.to(frame, { '--ft': '0%', '--fr': '0%', '--fb': '0%', '--fl': '0%', '--shade': 0.66, duration: 1 }, 0);
            if (image) tl.fromTo(image, { scale: 1.22 }, { scale: 1, duration: 1 }, 0);
            tl.to(title, { yPercent: -10, scale: 1.07, transformOrigin: '0% 100%', duration: 1 }, 0)
                .fromTo(extras, { autoAlpha: 1, y: 0 }, { autoAlpha: 0, y: -36, duration: 0.45, stagger: 0.03, immediateRender: false }, 0.5);
        });

        // Celular y tablet: sin fijar; solo una profundidad leve en la foto.
        mm.add(MOBILE, () => {
            if (!image) return;
            gsap.fromTo(image, { yPercent: 0 }, { yPercent: 12, ease: 'none', scrollTrigger: { trigger: hero, start: 'top top', end: 'bottom top', scrub: true } });
        });
    }

    /* ---------- 3. La experiencia: video o fotogramas controlados por el scroll ---------- */
    const film = document.querySelector('[data-cx-film]');
    if (film) {
        const stage = film.querySelector('[data-cx-film-stage]');
        const media = film.querySelector('[data-cx-film-media]');
        const steps = [...film.querySelectorAll('[data-cx-film-step]')];
        const frames = [...film.querySelectorAll('[data-cx-frame]')];
        const bar = film.querySelector('[data-cx-film-bar]');
        const label = film.querySelector('[data-cx-film-label]');
        const timeLabel = film.querySelector('[data-cx-film-time]');
        const video = film.querySelector('[data-cx-scrub]');
        const toggle = film.querySelector('[data-cx-film-toggle]');
        const timecode = (s) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

        let scrubbing = false; // true en escritorio: el scroll manda sobre currentTime
        let target = 0;
        let shown = 0;
        let seekFrame = 0;
        let activeStep = -1;
        let lastProgress = 0;

        // Búsqueda de fotogramas suavizada: nunca se pide un salto mientras el anterior no ha terminado.
        const seekTick = () => {
            seekFrame = 0;
            if (!video || !scrubbing || !video.duration) return;
            shown += (target - shown) * 0.2;
            if (Math.abs(target - shown) < 0.012) shown = target;
            if (!video.seeking && Math.abs(video.currentTime - shown) > 0.02) video.currentTime = shown;
            if (timeLabel) timeLabel.textContent = timecode(shown);
            if (shown !== target || video.seeking) seekFrame = requestAnimationFrame(seekTick);
        };

        const stepWords = []; // la frase gigante sobre la foto se quitó a pedido del cliente
        const showStepWord = (index, previous) => {
            if (previous >= 0 && stepWords[previous]) {
                gsap.to(stepWords[previous], { z: 260, yPercent: -40, autoAlpha: 0, filter: 'blur(10px)', duration: 0.45, ease: 'power2.in', stagger: 0.012, overwrite: true });
            }
            if (!stepWords[index]) return;
            depthHide(stepWords[index], index > previous ? 'back' : 'below');
            depthShow(stepWords[index], { delay: previous >= 0 ? 0.18 : 0, duration: 1.1 });
        };

        const setProgress = (p) => {
            lastProgress = p;
            if (bar) bar.style.transform = `scaleX(${p.toFixed(4)})`;
            const index = Math.min(steps.length - 1, Math.floor(p * steps.length));
            if (index !== activeStep) {
                showStepWord(index, activeStep);
                activeStep = index;
                steps.forEach((step, i) => step.classList.toggle('is-active', i === index));
                if (label && steps[index]) label.textContent = steps[index].querySelector('h3')?.textContent || '';
            }
            // Fotogramas: cada foto se descubre con una máscara cuando llega su tramo.
            if (frames.length > 1) {
                const x = p * frames.length;
                frames.forEach((frame, i) => { if (i > 0) frame.style.setProperty('--r', clamp((x - (i - 0.42)) / 0.42).toFixed(4)); });
            }
            if (video && scrubbing && video.duration) {
                target = clamp(p) * Math.max(0, video.duration - 0.05);
                if (!seekFrame) seekFrame = requestAnimationFrame(seekTick);
            }
        };

        // Carga diferida: el video solo se descarga cuando la sección se acerca a la pantalla.
        let objectUrl = null;
        const loadVideo = (desktop) => {
            if (!video || video.dataset.loaded) return;
            video.dataset.loaded = '1';
            if (navigator.connection?.saveData) return; // ahorro de datos: se queda la imagen de portada
            const canMp4 = video.canPlayType('video/mp4; codecs="avc1.42E01E"') !== '';
            const src = (!desktop && video.dataset.srcMobile) || (canMp4 && video.dataset.srcMp4) || video.dataset.srcWebm || video.dataset.srcMp4;
            if (!src) return;
            if (!desktop) { video.loop = true; video.preload = 'metadata'; video.src = src; return; }
            // Escritorio: si pesa poco se baja completo a memoria, así cualquier fotograma responde al instante.
            const abort = new AbortController();
            fetch(src, { signal: abort.signal })
                .then((response) => {
                    const size = Number(response.headers.get('content-length') || 0);
                    if (!response.ok || size > 40 * 1024 * 1024) { abort.abort(); throw new Error('directo'); }
                    return response.blob();
                })
                .then((blob) => { objectUrl = URL.createObjectURL(blob); video.src = objectUrl; })
                .catch(() => { video.preload = 'auto'; video.src = src; });
        };
        cleanups.push(() => { if (objectUrl) URL.revokeObjectURL(objectUrl); cancelAnimationFrame(seekFrame); });
        // Las fotos ocultas tras su máscara no cuentan como "visibles" para la carga diferida: se piden al acercarse.
        if (frames.length) {
            ScrollTrigger.create({ trigger: film, start: 'top 220%', once: true, onEnter: () => frames.forEach((frame) => { const img = frame.querySelector('img'); if (img) img.loading = 'eager'; }) });
        }
        video?.addEventListener('loadedmetadata', () => { film.classList.add('is-video-ready'); setProgress(lastProgress); });
        video?.addEventListener('error', () => film.classList.add('is-video-error'));

        mm.add(DESKTOP, () => {
            scrubbing = true;
            video?.pause();
            if (video) video.loop = false;
            if (toggle) toggle.hidden = true;
            const loader = video ? ScrollTrigger.create({ trigger: film, start: 'top 220%', once: true, onEnter: () => loadVideo(true) }) : null;
            const pin = ScrollTrigger.create({
                trigger: stage,
                start: 'top top',
                end: () => `+=${Math.round(window.innerHeight * (video ? 3 : 2.4))}`,
                pin: true,
                anticipatePin: 1,
                invalidateOnRefresh: true,
                onUpdate: (self) => setProgress(self.progress),
                onRefresh: (self) => setProgress(self.progress),
            });
            return () => { scrubbing = false; loader?.kill(); pin.kill(); };
        });

        mm.add(MOBILE, () => {
            // Alternativa móvil: sin fijar la sección. El video se reproduce solo mientras se ve; las fotos avanzan al pasar.
            const passing = ScrollTrigger.create({
                trigger: media, start: 'top 85%', end: 'bottom 25%',
                onUpdate: (self) => setProgress(self.progress),
            });
            if (!video) return () => passing.kill();

            let pausedByVisitor = false;
            let visible = false;
            const sync = () => {
                if (!video.src) return;
                if (visible && !pausedByVisitor && !document.hidden) video.play().catch(() => {}); else video.pause();
            };
            const observer = new IntersectionObserver(([entry]) => {
                visible = entry.isIntersecting;
                if (visible) loadVideo(false);
                sync();
            }, { rootMargin: '200px 0px' });
            observer.observe(media);
            const onTime = () => { if (timeLabel) timeLabel.textContent = timecode(video.currentTime); };
            const onToggle = () => {
                pausedByVisitor = !pausedByVisitor;
                toggle.setAttribute('aria-pressed', pausedByVisitor ? 'true' : 'false');
                toggle.querySelector('span').textContent = pausedByVisitor ? 'Reproducir video' : 'Pausar video';
                toggle.querySelector('i').className = pausedByVisitor ? 'bi bi-play-fill' : 'bi bi-pause-fill';
                sync();
            };
            video.addEventListener('timeupdate', onTime);
            video.addEventListener('loadedmetadata', sync);
            document.addEventListener('visibilitychange', sync);
            if (toggle) { toggle.hidden = false; toggle.addEventListener('click', onToggle); }
            return () => {
                passing.kill(); observer.disconnect(); video.pause();
                video.removeEventListener('timeupdate', onTime);
                video.removeEventListener('loadedmetadata', sync);
                document.removeEventListener('visibilitychange', sync);
                toggle?.removeEventListener('click', onToggle);
            };
        });
    }

    /* ---------- 4. Espiral de cortes (inicio) ---------- */
    const spiral = document.querySelector('[data-xq]');
    if (spiral) {
        const cards = [...spiral.querySelectorAll('[data-xq-card]')];
        const videos = [...spiral.querySelectorAll('[data-xq-video]')];
        spiral.classList.add('is-animated');
        const render = () => {
            const vh = window.innerHeight;
            const rect = spiral.getBoundingClientRect();
            const p = clamp(-rect.top / Math.max(1, rect.height - vh));
            spiral.style.setProperty('--qin', clamp((vh - rect.top) / vh).toFixed(3));
            spiral.style.setProperty('--qout', clamp((vh - rect.bottom) / (vh * 0.6)).toFixed(3));
            const n = cards.length;
            const spacing = 0.55 * Math.min(1, 12 / Math.max(1, n));
            const radius = Math.min(window.innerWidth * (window.innerWidth < 768 ? 0.2 : 0.3), 470);
            cards.forEach((card, i) => {
                const u = -1.15 + p * (2.3 + (n - 1) * spacing) - i * spacing; // -1.15 abajo … 1.15 arriba
                const angle = u * Math.PI * 1.15 + i * 0.9;
                const x = Math.sin(angle) * radius;
                const z = Math.cos(angle) * radius;
                const y = -u * vh * 0.5;
                card.style.transform = `translate(-50%, -50%) translate3d(${x.toFixed(1)}px, ${y.toFixed(1)}px, ${z.toFixed(1)}px) rotateY(${(Math.sin(angle) * 28).toFixed(2)}deg) rotateZ(${(-u * 6).toFixed(2)}deg)`;
                card.style.opacity = clamp(1.35 - Math.abs(u)).toFixed(3);
                card.style.zIndex = z > 0 ? 3 : 1;
                card.style.filter = z < 0 ? `brightness(${(0.55 + 0.45 * (1 + z / radius)).toFixed(2)})` : 'none';
            });
        };
        ScrollTrigger.create({
            trigger: spiral, start: 'top bottom', end: 'bottom top', onUpdate: render, onRefresh: render,
            // Los videos de la espiral solo se reproducen mientras la sección está en pantalla.
            onToggle: (self) => videos.forEach((v) => (self.isActive && !document.hidden ? v.play().catch(() => {}) : v.pause())),
        });
        render();
    }

    /* ---------- 5. Frase que se ilumina palabra por palabra ---------- */
    const quote = null; // antes: se iluminaba con el scroll y quedaba ilegible al inicio
    if (quote) {
        const words = [...quote.querySelectorAll('.qw')];
        quote.classList.add('is-animated');
        const render = () => {
            const rect = quote.getBoundingClientRect();
            const p = clamp(-rect.top / Math.max(1, rect.height - window.innerHeight));
            const lit = p * 1.15 * words.length;
            words.forEach((word, i) => word.style.setProperty('--lit', clamp(lit - i).toFixed(3)));
            quote.style.setProperty('--p', p.toFixed(3));
        };
        ScrollTrigger.create({ trigger: quote, start: 'top bottom', end: 'bottom top', onUpdate: render, onRefresh: render });
        render();
    }

    /* ---------- 6. Propósito: misión y visión ---------- */
    const purpose = null; // ahora lo anima immersive.js con efectos de entrada
    if (purpose) {
        const rows = [...purpose.querySelectorAll('[data-purpose-row]')];
        purpose.classList.add('is-animated');
        const render = () => {
            const vh = window.innerHeight;
            rows.forEach((row) => {
                const rect = row.getBoundingClientRect();
                const raw = clamp((vh * 0.95 - rect.top) / (vh * 0.6));
                row.style.setProperty('--in', (1 - Math.pow(1 - raw, 3)).toFixed(3));
                row.style.setProperty('--travel', clamp((vh - rect.top) / (vh + rect.height)).toFixed(3));
            });
        };
        ScrollTrigger.create({ trigger: purpose, start: 'top bottom', end: 'bottom top', onUpdate: render, onRefresh: render });
        render();
    }

    /* ---------- 6b. Galería de trabajos (inicio y perfil de cada barbero) ---------- */
    // Computador: la sección queda fija y, al bajar, las fotos pasan de lado con profundidad (pin + scrub).
    // Celular: se desliza con el dedo; la misma profundidad se calcula con el scroll horizontal nativo.
    const galleries = new Map();
    const splitChars = (el) => {
        if (el.dataset.cxSplit) return [...el.querySelectorAll('.cx-char')];
        const text = el.textContent.replace(/\s+/g, ' ').trim();
        el.dataset.cxSplit = '1';
        el.setAttribute('aria-label', text);
        el.textContent = '';
        text.split(' ').forEach((word, index, all) => {
            const wordEl = document.createElement('span');
            wordEl.className = 'cx-word';
            wordEl.setAttribute('aria-hidden', 'true');
            [...word].forEach((ch) => { const c = document.createElement('span'); c.className = 'cx-char'; c.textContent = ch; wordEl.appendChild(c); });
            el.appendChild(wordEl);
            if (index < all.length - 1) el.appendChild(document.createTextNode(' '));
        });
        return [...el.querySelectorAll('.cx-char')];
    };
    const mountGallery = (el) => {
        const stage = el.querySelector('[data-wg-stage]');
        const viewport = el.querySelector('[data-wg-viewport]');
        const track = el.querySelector('[data-wg-track]');
        const bar = el.querySelector('[data-wg-bar]');
        const ghost = el.querySelector('[data-wg-ghost]');
        const title = el.querySelector('[data-cx-chars]');
        const items = [...el.querySelectorAll('[data-wg-item]')];
        const local = gsap.matchMedia();
        const disposers = [];

        // Titular: cascada de letras que suben girando desde abajo, empezando por el centro; el subtítulo entra palabra
        // por palabra justo después. Al terminar, cada letra se levanta al pasar el mouse (clase is-live).
        if (title) {
            const chars = splitDeep(title);
            const lead = el.querySelector('.wg-lead');
            const kicker = el.querySelector('.wg-head .section-kicker');
            let words = [];
            if (lead && !lead.dataset.cxSplit) {
                lead.dataset.cxSplit = '1';
                const text = lead.textContent.trim();
                lead.setAttribute('aria-label', text);
                lead.innerHTML = text.split(/\s+/).map((w) => `<span class="wg-lead-w" aria-hidden="true">${w.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))}</span>`).join(' ');
                words = [...lead.querySelectorAll('.wg-lead-w')];
            }
            gsap.set(chars, { yPercent: 120, rotateX: -95, z: -160, autoAlpha: 0, filter: 'blur(8px)', transformOrigin: '50% 100%' });
            if (words.length) gsap.set(words, { y: 22, autoAlpha: 0 });
            if (kicker) gsap.set(kicker, { autoAlpha: 0, x: -24 });
            const intro = gsap.timeline({
                scrollTrigger: {
                    trigger: el, start: 'top 88%', end: 'top 22%', scrub: 0.6, refreshPriority: -1,
                    onUpdate: (self) => title.classList.toggle('is-live', self.progress > 0.98),
                },
            })
                .to(kicker || {}, { autoAlpha: 1, x: 0, duration: 0.7, ease: 'power3.out' }, 0)
                .to(chars, { yPercent: 0, rotateX: 0, z: 0, autoAlpha: 1, filter: 'blur(0px)', duration: 0.9, ease: 'power3.out', stagger: { each: 0.11, from: 'start' } }, 0.45)
                .to(words, { y: 0, autoAlpha: 1, duration: 0.6, ease: 'power3.out', stagger: 0.12 }, '>-0.2');
            const reveal = intro.scrollTrigger;
            // La palabra gigante del fondo se desliza en sentido contrario mientras pasa la sección (capa de fondo).
            const drift = ghost ? gsap.fromTo(ghost, { '--gx': '22%' }, { '--gx': '-22%', ease: 'none', scrollTrigger: { trigger: el, start: 'top bottom', end: 'bottom top', scrub: true } }) : null;
            disposers.push(() => {
                reveal?.kill(); intro.kill(); drift?.scrollTrigger?.kill(); drift?.kill();
                gsap.set([...chars, ...words, kicker].filter(Boolean), { clearProps: 'all' });
                title.classList.remove('is-live');
            });
        }
        if (!track || !viewport || !items.length) return () => { local.revert(); disposers.forEach((fn) => fn()); };

        // Las fotos que aún no han entrado en pantalla se piden cuando la galería se acerca (no al cargar la página).
        const preload = ScrollTrigger.create({ trigger: el, start: 'top 250%', once: true, onEnter: () => items.forEach((item) => { const img = item.querySelector('img'); if (img) img.loading = 'eager'; }) });
        disposers.push(() => preload.kill());

        // Profundidad: cada foto sabe qué tan lejos está del centro de la pantalla (--p de -1 a 1, --a su valor absoluto).
        const depth = () => {
            const half = window.innerWidth / 2;
            const range = window.innerWidth * 0.62;
            items.forEach((item) => {
                const rect = item.getBoundingClientRect();
                const p = clamp((rect.left + rect.width / 2 - half) / range, -1, 1);
                item.style.setProperty('--p', p.toFixed(3));
                item.style.setProperty('--a', Math.abs(p).toFixed(3));
            });
        };
        const setProgress = (p) => {
            if (bar) bar.style.transform = `scaleX(${clamp(p).toFixed(4)})`;
            if (ghost) ghost.style.transform = `translate3d(${(-p * 22).toFixed(2)}%, 0, 0)`;
        };

        local.add(DESKTOP, () => {
            const distance = () => Math.max(0, track.scrollWidth - el.clientWidth);
            if (distance() < 40) { depth(); return undefined; } // pocas fotos: caben sin moverse
            el.classList.add('is-pinned');
            viewport.scrollLeft = 0;
            const tween = gsap.to(track, {
                x: () => -distance(), ease: 'none',
                scrollTrigger: {
                    trigger: stage, start: 'top top', end: () => `+=${Math.round(Math.max(window.innerHeight * 0.9, distance()))}`,
                    pin: true, scrub: true, anticipatePin: 1, invalidateOnRefresh: true,
                    onUpdate: (self) => { depth(); setProgress(self.progress); },
                    onRefresh: (self) => { depth(); setProgress(self.progress); },
                },
            });
            depth();
            return () => { tween.scrollTrigger?.kill(); tween.kill(); gsap.set(track, { clearProps: 'transform' }); el.classList.remove('is-pinned'); };
        });
        local.add(MOBILE, () => {
            let frame = 0;
            const onScroll = () => {
                if (frame) return;
                frame = requestAnimationFrame(() => { frame = 0; depth(); setProgress(viewport.scrollLeft / Math.max(1, viewport.scrollWidth - viewport.clientWidth)); });
            };
            viewport.addEventListener('scroll', onScroll, { passive: true });
            const enter = ScrollTrigger.create({ trigger: el, start: 'top bottom', end: 'bottom top', onEnter: depth, onEnterBack: depth });
            depth();
            return () => { viewport.removeEventListener('scroll', onScroll); cancelAnimationFrame(frame); enter.kill(); };
        });
        return () => { local.revert(); disposers.forEach((fn) => fn()); items.forEach((item) => { item.style.removeProperty('--p'); item.style.removeProperty('--a'); }); };
    };
    const syncGalleries = () => {
        document.querySelectorAll('[data-wg]').forEach((el) => {
            if (el.hidden && galleries.has(el)) { galleries.get(el)(); galleries.delete(el); }
            if (!el.hidden && !galleries.has(el)) galleries.set(el, mountGallery(el));
        });
        ScrollTrigger.refresh();
    };
    syncGalleries();
    // La página de barberos avisa cuando cambia el barbero en pantalla (se muestra otra galería).
    document.addEventListener('wg:refresh', syncGalleries);
    cleanups.push(() => { document.removeEventListener('wg:refresh', syncGalleries); galleries.forEach((dispose) => dispose()); galleries.clear(); });

    /* ---------- 6c. Escena con foto (Nuestra esencia → Visión) ----------
       1) La foto aparece como una ventana pequeña con esquinas redondeadas y se va expandiendo hasta cubrir
          toda la pantalla mientras la sección entra (clip-path + escala, atado al scroll).
       2) Ya cubierta, la foto se acerca muy despacio (efecto cámara) hasta el final de Visión.
       3) Las tarjetas de texto flotan a distinta velocidad que la foto: capas con profundidad. */
    const scene = document.querySelector('[data-cx-scene]');
    if (scene) {
        const frame = scene.querySelector('.cx-scene-bg picture');
        const layers = [...scene.querySelectorAll('.home-quote-text, .purpose-head, .purpose-row')];
        if (frame) {
            gsap.fromTo(frame,
                { clipPath: 'inset(16% 20% 16% 20% round 36px)', scale: 1.18 },
                { clipPath: 'inset(0% 0% 0% 0% round 0px)', scale: 1, ease: 'none', scrollTrigger: { trigger: scene, start: 'top 85%', end: 'top 5%', scrub: 0.6 } });
            // El halo oscuro detrás del texto aparece a la par de la foto (antes se vería como una mancha sobre el azul).
            gsap.fromTo(scene, { '--open': 0 }, { '--open': 1, ease: 'none', scrollTrigger: { trigger: scene, start: 'top 60%', end: 'top 5%', scrub: 0.6 } });
        }
        layers.forEach((layer, i) => {
            const depth = [70, 110, 90, 130][i % 4];
            gsap.fromTo(layer, { y: depth }, { y: -depth * 0.6, ease: 'none', scrollTrigger: { trigger: layer, start: 'top bottom', end: 'bottom top', scrub: true } });
        });
    }

    /* ---------- 7. Cierre del inicio y profundidad en páginas internas ---------- */
    const marquee = document.querySelector('[data-cx-marquee]');
    if (marquee) {
        gsap.fromTo(marquee, { xPercent: 0 }, { xPercent: -28, ease: 'none', scrollTrigger: { trigger: marquee, start: 'top bottom', end: 'bottom top', scrub: true } });
    }
    // Profundidad en las fotos de Contacto y Nosotros (también en celular).
    gsap.utils.toArray('.ct-hero-bg img, .about-photo').forEach((el) => {
        gsap.fromTo(el, { yPercent: -6 }, { yPercent: 6, ease: 'none', scrollTrigger: { trigger: el.parentElement, start: 'top bottom', end: 'bottom top', scrub: true } });
    });

    /* ---------- 8. Mantenimiento ---------- */
    // Al cerrarse la pantalla de carga vuelve la barra de scroll y cambia el ancho útil: se recalculan las secciones fijadas.
    whenLoaderDone(() => requestAnimationFrame(() => ScrollTrigger.refresh()));
    // Las medidas cambian cuando terminan de cargar fuentes e imágenes.
    document.fonts?.ready.then(() => ScrollTrigger.refresh());
    window.addEventListener('load', () => ScrollTrigger.refresh(), { once: true });
    // Limpieza al salir (no en bfcache: la página puede volver tal cual con el botón "atrás").
    window.addEventListener('pagehide', (event) => {
        if (event.persisted) return;
        mm.revert();
        ScrollTrigger.getAll().forEach((trigger) => trigger.kill());
        cleanups.forEach((fn) => fn());
    });

    window.Cinema = { get lenis() { return lenis; }, refresh: () => ScrollTrigger.refresh() };
})();
