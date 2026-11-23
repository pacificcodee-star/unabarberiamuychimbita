/*
 * Dinamismo de toda la página pública:
 *  - Títulos que se arman palabra por palabra al aparecer.
 *  - Imágenes que se revelan con una cortina al hacer scroll.
 *  - Texto detrás del objeto (portada y barberos) con profundidad al mover el mouse y al hacer scroll.
 *  - Barra de progreso de lectura, botones magnéticos y luz dorada que sigue al cursor.
 *  - Transiciones suaves entre páginas (View Transitions + cortina de respaldo).
 * Respeta "reducir movimiento" del sistema.
 */
(() => {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const root = document.documentElement;
    root.classList.add('motion-ready');

    /* ---------- 1. Títulos palabra por palabra ---------- */
    const SKIP = '.booking-page .page-hero, .contact-page, [data-xh], [data-xq], [data-cx-chars], [data-bx], .svc-slide, .home-quote, .barber-chapter-word, .xq-card, .nav-links, .ct-map';
    const splitTargets = [...document.querySelectorAll('main h1, main h2, main .section-kicker, main [data-split]')]
        .filter((el) => !el.closest(SKIP) && !el.querySelector('.word, .xh-line, .qw, .sw') && el.textContent.trim().length > 0 && el.children.length <= 2);
    splitTargets.forEach((el) => {
        if (el.querySelector('a, button, img, svg, i')) return;
        const text = el.textContent.replace(/\s+/g, ' ').trim();
        const emphasis = el.querySelector('em, strong, .is-accent');
        const emText = emphasis ? emphasis.textContent.trim() : null;
        el.setAttribute('aria-label', text);
        let i = 0;
        const words = text.split(' ').map((w) => {
            const isEm = emText && emText.split(' ').includes(w);
            return `<span class="sw" aria-hidden="true"><span style="--w:${i++}"${isEm ? ' class="sw-em"' : ''}>${w.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]))}</span></span>`;
        });
        el.innerHTML = words.join(' ');
        el.classList.add('is-split');
    });

    /* ---------- 2. Revelado de imágenes ---------- */
    const imgTargets = [...document.querySelectorAll('main img, main video')]
        .filter((el) => !el.closest('[data-xh], [data-xq], [data-cx], [data-cx-scene], .svc-carousel, .barber-story, .ct-map, .alpha-video, .whatsapp-float, .payment-logo, .booking-page .page-hero') && el.offsetWidth > 120);
    imgTargets.forEach((el) => (el.parentElement.classList.contains('img-reveal') ? null : el.parentElement.classList.add('img-reveal')));

    /* ---------- Observador común ---------- */
    const toWatch = [...splitTargets, ...document.querySelectorAll('.img-reveal, [data-anim]')];
    if (reduced || !('IntersectionObserver' in window)) {
        toWatch.forEach((el) => el.classList.add('in-view'));
    } else {
        const io = new IntersectionObserver((entries) => entries.forEach((e) => {
            if (e.isIntersecting) { e.target.classList.add('in-view'); io.unobserve(e.target); }
        }), { rootMargin: '0px 0px -12% 0px', threshold: 0.12 });
        toWatch.forEach((el) => io.observe(el));
    }

    /* ---------- 3. Barra de progreso + variables de scroll ---------- */
    const bar = document.createElement('div');
    bar.className = 'scroll-progress';
    bar.setAttribute('aria-hidden', 'true');
    document.body.appendChild(bar);

    // Rendimiento: aquí solo se escribe (transform de la barra y dos variables de la portada).
    // El alto de la página se mide solo al cambiar de tamaño, nunca en cada fotograma de scroll,
    // y ya no se cambia ninguna variable en <html> (obligaba al navegador a recalcular TODA la página).
    let mx = 0, my = 0, tx = 0, ty = 0, ticking = false, maxScroll = 1, heroVisible = true;
    const hero = document.querySelector('[data-xh]');
    const heroImg = hero?.querySelector('.cx-hero-img');
    const measure = () => { maxScroll = Math.max(1, document.documentElement.scrollHeight - innerHeight); };
    measure();
    if ('ResizeObserver' in window) new ResizeObserver(measure).observe(document.body);
    if (hero && 'IntersectionObserver' in window) new IntersectionObserver(([entry]) => { heroVisible = entry.isIntersecting; }).observe(hero);

    const frame = () => {
        ticking = false;
        bar.style.transform = `scaleX(${Math.min(1, scrollY / maxScroll).toFixed(4)})`;
        // Profundidad con el mouse, solo mientras la portada está en pantalla.
        if (!hero || !heroVisible) return;
        tx += (mx - tx) * 0.08;
        ty += (my - ty) * 0.08;
        // Se mueve solo la foto: antes se cambiaban variables en la portada entera y el navegador recalculaba su árbol.
        if (heroImg) heroImg.style.translate = `${(tx * -16).toFixed(1)}px ${(ty * -10).toFixed(1)}px`;
        if (Math.abs(mx - tx) > 0.002 || Math.abs(my - ty) > 0.002) request();
    };
    const request = () => { if (!ticking) { ticking = true; requestAnimationFrame(frame); } };
    addEventListener('scroll', request, { passive: true });
    addEventListener('resize', () => { measure(); request(); });
    if (!reduced && finePointer) {
        addEventListener('pointermove', (e) => { mx = e.clientX / innerWidth - 0.5; my = e.clientY / innerHeight - 0.5; if (heroVisible) request(); }, { passive: true });
    }
    request();

    /* ---------- 4. Botones magnéticos + luz que sigue al cursor ---------- */
    if (finePointer && !reduced) {
        document.querySelectorAll('.btn-gold, .xbtn, .btn-ghost-light, .ct-card, .whatsapp-float').forEach((btn) => {
            btn.classList.add('is-magnetic');
            btn.addEventListener('pointermove', (e) => {
                const r = btn.getBoundingClientRect();
                const x = (e.clientX - r.left - r.width / 2) / r.width;
                const y = (e.clientY - r.top - r.height / 2) / r.height;
                const force = btn.classList.contains('ct-card') ? 6 : 10;
                btn.style.translate = `${(x * force).toFixed(1)}px ${(y * force).toFixed(1)}px`;
                btn.style.setProperty('--gx', `${((x + 0.5) * 100).toFixed(1)}%`);
                btn.style.setProperty('--gy', `${((y + 0.5) * 100).toFixed(1)}%`);
            });
            btn.addEventListener('pointerleave', () => { btn.style.translate = ''; });
        });
        const glow = document.createElement('div');
        glow.className = 'cursor-glow';
        glow.setAttribute('aria-hidden', 'true');
        document.body.appendChild(glow);
        // Un solo cambio por fotograma, aunque el mouse envíe muchos eventos.
        let gx = 0, gy = 0, glowFrame = 0;
        addEventListener('pointermove', (e) => {
            gx = e.clientX; gy = e.clientY;
            if (!glowFrame) glowFrame = requestAnimationFrame(() => { glowFrame = 0; glow.style.transform = `translate3d(${gx}px, ${gy}px, 0)`; });
        }, { passive: true });
    }

    /* ---------- 5. Transición entre páginas (respaldo si no hay View Transitions) ---------- */
    if (!reduced && !('onpagereveal' in window)) {
        const curtain = document.createElement('div');
        curtain.className = 'page-curtain';
        curtain.setAttribute('aria-hidden', 'true');
        document.body.appendChild(curtain);
        document.addEventListener('click', (e) => {
            const a = e.target.closest('a[href]');
            if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            const url = new URL(a.href, location.href);
            if (a.target === '_blank' || a.hasAttribute('download') || url.origin !== location.origin || (url.pathname === location.pathname && url.hash)) return;
            if (/^(mailto|tel|https?:\/\/wa\.me)/.test(a.getAttribute('href'))) return;
            e.preventDefault();
            curtain.classList.add('is-leaving');
            setTimeout(() => { location.href = url.href; }, 420);
        });
        addEventListener('pageshow', () => curtain.classList.remove('is-leaving'));
    }
})();
