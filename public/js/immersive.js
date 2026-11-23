/*
 * Piezas inmersivas que NO dependen de GSAP (funcionan aunque el guion de scroll no cargue):
 *  1. Galería "carrete de cine": tarjetas iguales en abanico 3D. Se recorre con flechas, arrastrando con el cursor,
 *     clic en las fotos de atrás, rueda/trackpad de lado, dedo o teclado. Clic en la del frente: visor cinematográfico.
 *  2. Efectos de entrada para textos (tipo presentación): aparecer, barrido, volar hacia adentro, zoom y palabra por palabra.
 *     El texto SIEMPRE termina nítido y legible; con "reducir movimiento" no se anima nada.
 */
(() => {
    const root = document.documentElement;
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- 1. Galería ---------- */
    // Visor cinematográfico (uno para toda la página): la foto crece desde su tarjeta hasta llenar la pantalla.
    const viewer = (() => {
        const dialog = document.createElement('dialog');
        dialog.className = 'wg-viewer';
        dialog.setAttribute('aria-label', 'Foto ampliada');
        dialog.innerHTML = `
            <div class="wg-viewer-bg" aria-hidden="true"></div>
            <div class="wg-viewer-bars" aria-hidden="true"><span></span><span></span></div>
            <figure class="wg-viewer-stage"><img class="wg-viewer-img" alt=""><figcaption class="wg-viewer-caption"></figcaption></figure>
            <button type="button" class="wg-viewer-btn wg-viewer-close" data-v-close aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            <button type="button" class="wg-viewer-btn wg-viewer-prev" data-v-prev aria-label="Foto anterior"><i class="bi bi-arrow-left" aria-hidden="true"></i></button>
            <button type="button" class="wg-viewer-btn wg-viewer-next" data-v-next aria-label="Foto siguiente"><i class="bi bi-arrow-right" aria-hidden="true"></i></button>
            <p class="wg-viewer-count" aria-live="polite"></p>`;
        document.body.appendChild(dialog);
        const img = dialog.querySelector('.wg-viewer-img');
        const bg = dialog.querySelector('.wg-viewer-bg');
        const caption = dialog.querySelector('.wg-viewer-caption');
        const count = dialog.querySelector('.wg-viewer-count');
        let gallery = null; // { cards, get current, go, sourceOf }
        let busy = false;

        // Tamaño final de la foto: lo más grande posible sin deformarla (proporción original).
        const fit = (w, h) => {
            const maxW = window.innerWidth * (window.innerWidth < 768 ? 0.94 : 0.86);
            const maxH = window.innerHeight * (window.innerWidth < 768 ? 0.72 : 0.8);
            const s = Math.min(maxW / w, maxH / h);
            return { width: Math.round(w * s), height: Math.round(h * s) };
        };
        const show = (card) => {
            const source = card.querySelector('.wg-frame img');
            img.src = source.currentSrc || source.src;
            img.alt = source.alt;
            const size = fit(source.naturalWidth || 4, source.naturalHeight || 5);
            img.style.width = `${size.width}px`;
            img.style.height = `${size.height}px`;
            bg.style.backgroundImage = `url("${img.src}")`;
            caption.textContent = card.querySelector('.wg-caption')?.textContent.trim() || '';
            count.textContent = `${gallery.current + 1} / ${gallery.cards.length}`;
        };
        // Rectángulo visible de la foto dentro de su tarjeta (la foto va completa, centrada).
        const visibleRect = (card) => {
            const frame = card.querySelector('.wg-frame');
            const source = card.querySelector('.wg-frame img');
            const r = frame.getBoundingClientRect();
            const ratio = (source.naturalWidth || 4) / (source.naturalHeight || 5);
            let w = r.width, h = r.width / ratio;
            if (h > r.height) { h = r.height; w = h * ratio; }
            return { left: r.left + (r.width - w) / 2, top: r.top + (r.height - h) / 2, width: w, height: h };
        };
        const flip = (from, reverse) => {
            const to = img.getBoundingClientRect();
            const dx = from.left + from.width / 2 - (to.left + to.width / 2);
            const dy = from.top + from.height / 2 - (to.top + to.height / 2);
            const s = from.width / to.width;
            const start = { transform: `translate(${dx}px, ${dy}px) scale(${s})`, borderRadius: '22px' };
            const end = { transform: 'none', borderRadius: '6px' };
            return img.animate(reverse ? [end, start] : [start, end], { duration: reverse ? 420 : 680, easing: reverse ? 'cubic-bezier(.55,0,.75,.3)' : 'cubic-bezier(.16,1,.3,1)', fill: 'both' });
        };
        const open = (g, card) => {
            if (busy) return;
            gallery = g;
            show(card);
            document.documentElement.classList.add('wg-locked');
            dialog.showModal();
            dialog.classList.add('is-open');
            if (!reduced && img.animate) { busy = true; flip(visibleRect(card)).finished.finally(() => { busy = false; }); }
            dialog.querySelector('[data-v-close]').focus({ preventScroll: true });
        };
        const settle = () => { img.getAnimations().forEach((a) => a.finish()); busy = false; };
        const close = () => {
            if (!dialog.open) return;
            settle();
            const card = gallery?.cards[gallery.current];
            dialog.classList.remove('is-open');
            let finished = false;
            const done = () => { if (finished) return; finished = true; dialog.close(); img.getAnimations().forEach((a) => a.cancel()); document.documentElement.classList.remove('wg-locked'); card?.querySelector('.wg-frame')?.focus?.({ preventScroll: true }); busy = false; };
            window.setTimeout(done, 700); // seguro: se cierra aunque el navegador congele la animación
            if (!reduced && img.animate && card) { busy = true; flip(visibleRect(card), true).finished.then(done, done); } else done();
        };
        const step = (delta) => {
            if (!gallery) return;
            settle();
            gallery.go(gallery.current + delta);
            const card = gallery.cards[gallery.current];
            count.textContent = `${gallery.current + 1} / ${gallery.cards.length}`;
            if (!reduced && img.animate) {
                busy = true;
                img.animate([{ opacity: 1, transform: 'none' }, { opacity: 0, transform: `translateX(${-delta * 60}px) scale(.96)` }], { duration: 180, easing: 'ease-in', fill: 'forwards' }).finished.then(() => {
                    show(card);
                    return img.animate([{ opacity: 0, transform: `translateX(${delta * 80}px) scale(.96)` }, { opacity: 1, transform: 'none' }], { duration: 420, easing: 'cubic-bezier(.16,1,.3,1)', fill: 'forwards' }).finished;
                }).finally(() => { busy = false; });
            } else show(card);
        };
        dialog.querySelector('[data-v-close]').addEventListener('click', close);
        dialog.querySelector('[data-v-prev]').addEventListener('click', () => step(-1));
        dialog.querySelector('[data-v-next]').addEventListener('click', () => step(1));
        dialog.addEventListener('cancel', (e) => { e.preventDefault(); close(); }); // tecla Esc
        dialog.addEventListener('click', (e) => { if (e.target === dialog || e.target === bg || e.target.classList.contains('wg-viewer-stage')) close(); });
        dialog.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
        });
        // Deslizar con el dedo dentro del visor.
        let tx = null;
        dialog.addEventListener('touchstart', (e) => { tx = e.touches[0].clientX; }, { passive: true });
        dialog.addEventListener('touchend', (e) => { if (tx === null) return; const dx = e.changedTouches[0].clientX - tx; tx = null; if (Math.abs(dx) > 50) step(dx < 0 ? 1 : -1); }, { passive: true });
        window.addEventListener('resize', () => { if (dialog.open && gallery) show(gallery.cards[gallery.current]); });
        return { open };
    })();

    const setupGallery = (section) => {
        const reel = section.querySelector('[data-wg-reel]');
        const cards = [...section.querySelectorAll('[data-wg-item]')];
        if (!reel || !cards.length) return;
        const bar = section.querySelector('[data-wg-bar]');
        const ghost = section.querySelector('[data-wg-ghost]');
        const total = cards.length;
        const loop = total > 4; // con pocas fotos no se da la vuelta
        let current = 0;

        section.classList.add('is-carousel', 'is-placing');

        const offsetOf = (index) => {
            let d = index - current;
            if (loop) {
                if (d > total / 2) d -= total;
                if (d < -total / 2) d += total;
            }
            return d;
        };
        const render = () => {
            cards.forEach((card, index) => {
                const d = offsetOf(index);
                const abs = Math.abs(d);
                card.style.setProperty('--d', d);
                card.style.setProperty('--abs', abs);
                card.classList.toggle('is-current', d === 0);
                card.classList.toggle('is-far', abs > 3);
                card.setAttribute('aria-hidden', abs > 2 ? 'true' : 'false');
                const frame = card.querySelector('.wg-frame');
                if (frame) {
                    frame.tabIndex = d === 0 ? 0 : -1;
                    frame.setAttribute('role', 'button');
                    frame.setAttribute('aria-label', d === 0 ? 'Ampliar foto' : `Ver foto ${index + 1}`);
                }
                // Las fotos cercanas se piden ya; las lejanas, cuando se acerquen.
                if (abs <= 2) { const img = card.querySelector('img'); if (img && img.loading === 'lazy') img.loading = 'eager'; }
            });
            if (bar) bar.style.transform = `scaleX(${((current + 1) / total).toFixed(4)})`;
            if (ghost) ghost.style.transform = `translate3d(${(-(current / Math.max(1, total - 1)) * 18).toFixed(2)}%, 0, 0)`;
        };
        const go = (target) => {
            const next = loop ? (target + total) % total : Math.min(total - 1, Math.max(0, target));
            if (next === current) return;
            current = next;
            render();
        };
        const api = { cards, go, get current() { return current; } };

        section.querySelector('[data-wg-prev]')?.addEventListener('click', () => go(current - 1));
        section.querySelector('[data-wg-next]')?.addEventListener('click', () => go(current + 1));
        reel.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowRight') { event.preventDefault(); go(current + 1); }
            if (event.key === 'ArrowLeft') { event.preventDefault(); go(current - 1); }
            if (event.key === 'Home') { event.preventDefault(); go(0); }
            if (event.key === 'End') { event.preventDefault(); go(total - 1); }
            if ((event.key === 'Enter' || event.key === ' ') && event.target.closest('.wg-card.is-current')) { event.preventDefault(); viewer.open(api, cards[current]); }
        });

        // Clic: en una foto de atrás la trae al frente; en la del frente abre el visor cinematográfico.
        let moved = false;
        cards.forEach((card, index) => card.addEventListener('click', () => {
            if (moved) return;
            if (index === current) viewer.open(api, card); else go(index);
        }));

        // Arrastrar con el cursor (o el dedo): el carrete sigue al puntero y al soltar pasa a la foto que corresponde.
        let startX = null;
        let startY = null;
        let dragX = 0;
        const cardStep = () => (cards[0].offsetWidth || 300) * 0.64;
        const setDrag = (px) => { dragX = px; reel.style.setProperty('--drag', `${px.toFixed(1)}px`); };
        const end = (x, y) => {
            if (startX === null) return;
            const dx = x - startX;
            const dy = y - startY;
            startX = null;
            reel.classList.remove('is-dragging');
            setDrag(0);
            if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.2) {
                moved = true;
                go(current - Math.max(1, Math.round(Math.abs(dx) / cardStep())) * Math.sign(dx));
                window.setTimeout(() => { moved = false; }, 80);
            }
        };
        reel.addEventListener('touchstart', (e) => { if (e.touches.length === 1) { startX = e.touches[0].clientX; startY = e.touches[0].clientY; } }, { passive: true });
        reel.addEventListener('touchmove', (e) => { if (startX !== null) setDrag((e.touches[0].clientX - startX) * 0.6); }, { passive: true });
        reel.addEventListener('touchend', (e) => end(e.changedTouches[0].clientX, e.changedTouches[0].clientY), { passive: true });
        reel.addEventListener('touchcancel', () => { startX = null; setDrag(0); reel.classList.remove('is-dragging'); }, { passive: true });
        reel.addEventListener('pointerdown', (e) => {
            if (e.pointerType === 'touch' || e.button !== 0) return;
            startX = e.clientX; startY = e.clientY; moved = false;
            reel.classList.add('is-dragging');
        });
        window.addEventListener('pointermove', (e) => {
            if (startX === null || e.pointerType === 'touch') return;
            const dx = e.clientX - startX;
            if (Math.abs(dx) > 6) moved = true;
            setDrag(dx * 0.6);
        }, { passive: true });
        window.addEventListener('pointerup', (e) => { if (e.pointerType !== 'touch') end(e.clientX, e.clientY); });
        reel.addEventListener('dragstart', (e) => e.preventDefault());

        // Rueda del mouse / trackpad: deslizar de lado (o Shift + rueda) cambia de foto. El scroll vertical normal no se toca.
        let wheelAcc = 0;
        let wheelLock = 0;
        reel.addEventListener('wheel', (e) => {
            const horizontal = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : (e.shiftKey ? e.deltaY : 0);
            if (!horizontal) return;
            e.preventDefault();
            if (Date.now() < wheelLock) return;
            wheelAcc += horizontal;
            if (Math.abs(wheelAcc) > 60) { go(current + Math.sign(wheelAcc)); wheelAcc = 0; wheelLock = Date.now() + 320; }
        }, { passive: false });

        render();
        void reel.offsetWidth; // aplica la posición inicial antes de activar las transiciones
        requestAnimationFrame(() => section.classList.remove('is-placing'));
    };
    document.querySelectorAll('[data-wg]').forEach(setupGallery);

    /* ---------- 1b. Fila de opiniones: botón para pausar el movimiento ---------- */
    document.querySelectorAll('[data-rv-pause]').forEach((button) => {
        const row = button.closest('[data-rv-row]');
        button.addEventListener('click', () => {
            const paused = row.classList.toggle('is-paused');
            button.setAttribute('aria-pressed', paused ? 'true' : 'false');
            button.querySelector('span').textContent = paused ? 'Mover opiniones' : 'Pausar opiniones';
            button.querySelector('i').className = paused ? 'bi bi-play-fill' : 'bi bi-pause-fill';
        });
    });

    /* ---------- 2. Efectos de entrada de texto ---------- */
    if (reduced || !('IntersectionObserver' in window)) return;
    root.classList.add('fx-on');

    const mark = (selector, effect, stagger) => document.querySelectorAll(selector).forEach((el, index) => {
        if (el.hasAttribute('data-fx') || el.closest('[data-cx-hero]')) return;
        el.setAttribute('data-fx', effect);
        if (stagger) el.style.setProperty('--fx-delay', `${Math.min(index, 6) * stagger}ms`);
    });
    // Frase de la esencia: palabra por palabra (cada una sube, gira un poco y queda nítida).
    // Propósito: la etiqueta vuela desde su lado y el texto se descubre con un barrido.
    // Textos de apoyo, rótulos y tarjetas: efectos variados.
    mark('main .section-kicker, main .ct-kicker, main .breadcrumb-public', 'wipe');
    mark('main .section-lead, main .cx-film-lead, main .wg-lead, main .cx-outro-lead, main .page-hero p, main .ct-lead, main .svc-desc', 'float');
    mark('main .cx-film-step', 'fly', 90);
    mark('main .mission-vision, main .value-card, main .review-card', 'zoom', 80);
    mark('main .about-promises span, main .bx-tags li', 'float', 70);

    const observer = new IntersectionObserver((entries) => entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('fx-in');
        observer.unobserve(entry.target);
    }), { rootMargin: '0px 0px -12% 0px', threshold: 0.15 });
    document.querySelectorAll('[data-fx]').forEach((el) => observer.observe(el));

    // Seguro: si algo impide observar (pestaña en segundo plano, error), el texto se muestra de todos modos.
    window.setTimeout(() => document.querySelectorAll('[data-fx]:not(.fx-in)').forEach((el) => {
        const rect = el.getBoundingClientRect();
        if (rect.top < window.innerHeight && rect.bottom > 0) el.classList.add('fx-in');
    }), 4000);
})();
