/*
 * Portada inmersiva de "Cortes destacados".
 * Al hacer scroll, la imagen principal de cada corte se deshace en partículas doradas
 * que giran y se vuelven a armar con la forma del siguiente corte.
 * Sin dependencias. Si el visitante prefiere menos movimiento, se muestra estático.
 */
(() => {
    const show = document.querySelector('[data-cuts-show]');
    if (!show) return;

    const scenes = [...show.querySelectorAll('[data-cut-scene]')];
    const stage = show.querySelector('.cuts-stage');
    const canvas = show.querySelector('.cuts-canvas');
    const counter = show.querySelector('[data-cuts-current]');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');

    if (reduced.matches || scenes.length < 2 || !canvas?.getContext) {
        show.classList.add('is-static');
        return;
    }

    show.classList.add('is-animated');
    const ctx = canvas.getContext('2d');
    const clamp = (v, a = 0, b = 1) => Math.min(b, Math.max(a, v));
    const easeInOut = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);
    const GOLD = [214, 176, 98];

    let width = 0;
    let height = 0;
    let dpr = 1;
    let targets = [];        // puntos (x, y, r, g, b) de cada escena
    let seeds = [];          // dirección y fuerza aleatoria de cada partícula
    let count = 0;
    let ready = false;
    let position = 0;
    let mouseX = 0;
    let mouseY = 0;
    let ticking = false;

    const loadImage = (img) => new Promise((resolve) => {
        if (img.complete && img.naturalWidth) return resolve(img);
        img.addEventListener('load', () => resolve(img), { once: true });
        img.addEventListener('error', () => resolve(null), { once: true });
        img.loading = 'eager';
    });

    // Convierte una imagen en una nube de puntos con su color.
    const sample = (img, budget) => {
        // Medimos dónde se dibuja realmente la imagen para que las partículas salgan de ella.
        const stageRect = stage.getBoundingClientRect();
        const r = img.getBoundingClientRect();
        let w = Math.round(r.width);
        let h = Math.round(r.height);
        let ox = r.left - stageRect.left;
        let oy = r.top - stageRect.top;
        if (w < 2 || h < 2) {
            const scale = Math.min((width * 0.4) / img.naturalWidth, (height * 0.78) / img.naturalHeight);
            w = Math.max(1, Math.round(img.naturalWidth * scale));
            h = Math.max(1, Math.round(img.naturalHeight * scale));
            ox = width / 2 - w / 2;
            oy = height - h;
        }
        const off = document.createElement('canvas');
        off.width = w;
        off.height = h;
        const octx = off.getContext('2d', { willReadFrequently: true });
        octx.drawImage(img, 0, 0, w, h);
        let data;
        try {
            data = octx.getImageData(0, 0, w, h).data;
        } catch (e) {
            return []; // imagen de otro dominio: no se puede leer
        }
        let opaque = 0;
        for (let i = 3; i < data.length; i += 4) if (data[i] > 120) opaque++;
        const step = Math.max(2, Math.round(Math.sqrt(opaque / budget)));
        const points = [];
        for (let y = 0; y < h; y += step) {
            for (let x = 0; x < w; x += step) {
                const k = (y * w + x) * 4;
                if (data[k + 3] > 120) points.push([ox + x, oy + y, data[k], data[k + 1], data[k + 2]]);
            }
        }
        return points;
    };

    const build = async () => {
        ready = false;
        const rect = stage.getBoundingClientRect();
        width = rect.width;
        height = rect.height;
        dpr = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);
        canvas.style.width = `${width}px`;
        canvas.style.height = `${height}px`;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        const budget = width < 768 ? 1800 : 4200;
        const images = await Promise.all(scenes.map((scene) => loadImage(scene.querySelector('[data-cut-main]'))));
        targets = images.map((img) => (img ? sample(img, budget) : []));
        targets = targets.map((t) => (t.length ? t : [[width / 2, height / 2, ...GOLD]]));
        count = Math.max(...targets.map((t) => t.length));
        seeds = Array.from({ length: count }, () => {
            const angle = Math.random() * Math.PI * 2;
            const r = Math.pow(Math.random(), 1.6);
            return { angle, force: 0.15 + r * 0.75, spin: 0.6 + Math.random() * 1.4, size: 1.1 + Math.random() * 1.8, phase: Math.random() };
        });
        ready = true;
        request();
    };

    const draw = (from, to, t) => {
        ctx.clearRect(0, 0, width, height);
        if (t <= 0.001 || t >= 0.999) return;
        const A = targets[from];
        const B = targets[to];
        const burst = Math.sin(Math.PI * t);                 // 0 → 1 → 0
        const e = easeInOut(t);
        const cx = width / 2 + (width < 768 ? 0 : width * 0.08);
        const cy = height * 0.55;
        const radius = Math.min(width, height) * 0.3;
        ctx.globalCompositeOperation = 'lighter';
        for (let i = 0; i < count; i++) {
            const a = A[i % A.length];
            const b = B[i % B.length];
            const s = seeds[i];
            // Posición base entre las dos formas
            let x = a[0] + (b[0] - a[0]) * e;
            let y = a[1] + (b[1] - a[1]) * e;
            // Explosión y remolino alrededor del centro
            const swirl = burst * s.spin * 1.6;
            const dx = x - cx;
            const dy = y - cy;
            x = cx + dx * Math.cos(swirl) - dy * Math.sin(swirl);
            y = cy + dx * Math.sin(swirl) + dy * Math.cos(swirl);
            // Se condensan en una nube que gira bajo el haz de luz
            const pull = burst * 0.75;
            x += (cx - x) * pull + Math.cos(s.angle + burst * s.spin * 2.4) * radius * s.force * burst;
            y += (cy - y) * pull + Math.sin(s.angle + burst * s.spin * 2.4) * radius * s.force * burst * 0.8 - burst * 40 * s.phase;
            // Color: se tiñe de dorado en medio de la transición
            const gold = burst * 0.75;
            const r = (a[2] + (b[2] - a[2]) * e) * (1 - gold) + GOLD[0] * gold;
            const g = (a[3] + (b[3] - a[3]) * e) * (1 - gold) + GOLD[1] * gold;
            const bl = (a[4] + (b[4] - a[4]) * e) * (1 - gold) + GOLD[2] * gold;
            const size = s.size * (1 + burst * 0.8);
            ctx.globalAlpha = 0.55 + 0.45 * (1 - burst * 0.4);
            ctx.fillStyle = `rgb(${r | 0},${g | 0},${bl | 0})`;
            ctx.fillRect(x, y, size, size);
        }
        ctx.globalAlpha = 1;
        ctx.globalCompositeOperation = 'source-over';
    };

    const render = () => {
        ticking = false;
        const rect = show.getBoundingClientRect();
        const travel = Math.max(1, rect.height - window.innerHeight);
        const progress = clamp(-rect.top / travel);
        const raw = progress * (scenes.length - 1);
        const base = Math.min(scenes.length - 2, Math.floor(raw));
        const frac = clamp(raw - base);
        // Cada corte se queda quieto un tramo antes de transformarse.
        const t = clamp((frac - 0.18) / 0.64);
        position = base + t;

        scenes.forEach((scene, i) => {
            const d = position - i;                          // <0 viene, 0 activo, >0 ya pasó
            const near = clamp(1 - Math.abs(d) * 4);         // visible solo cuando está quieto
            const text = clamp(1 - Math.abs(d) * 2.6);
            scene.style.setProperty('--near', near.toFixed(3));
            scene.style.setProperty('--text', text.toFixed(3));
            scene.style.setProperty('--d', clamp(d, -1, 1).toFixed(3));
            scene.classList.toggle('is-current', Math.round(position) === i);
            scene.style.visibility = Math.abs(d) >= 1 ? 'hidden' : 'visible';
        });

        if (counter) counter.textContent = String(Math.round(position) + 1).padStart(2, '0');
        show.style.setProperty('--progress', progress.toFixed(4));
        stage.style.setProperty('--mx', mouseX.toFixed(3));
        stage.style.setProperty('--my', mouseY.toFixed(3));

        if (ready) draw(base, base + 1, t);
    };

    function request() {
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(render);
        }
    }

    window.addEventListener('scroll', request, { passive: true });
    let resizeTimer;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(build, 200);
        request();
    });
    if (finePointer.matches) {
        stage.addEventListener('pointermove', (event) => {
            const rect = stage.getBoundingClientRect();
            mouseX = (event.clientX - rect.left) / rect.width - 0.5;
            mouseY = (event.clientY - rect.top) / rect.height - 0.5;
            request();
        });
        stage.addEventListener('pointerleave', () => { mouseX = 0; mouseY = 0; request(); });
    }

    // Si alguien llega con el teclado a un botón de otra escena, lo llevamos a esa escena.
    scenes.forEach((scene, index) => {
        scene.addEventListener('focusin', () => {
            const top = show.getBoundingClientRect().top + window.scrollY;
            const travel = show.offsetHeight - window.innerHeight;
            window.scrollTo({ top: top + travel * (index / (scenes.length - 1)), behavior: 'auto' });
        });
    });

    render();
    build();
})();
