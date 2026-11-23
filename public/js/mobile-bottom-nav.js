/* Barra inferior del celular (se inserta en línea al final de <nav class="mnav">: está lista desde el primer
   cuadro de la página, sin esperar a que terminen de cargar los demás scripts).

   Un solo estado decide qué pestaña se ve resaltada: render(índice) pone la clase .is-active (texto e ícono)
   y la variable --mnav-index (posición del óvalo) en el mismo paso, así nunca se contradicen.
   - El resaltado cambia apenas empieza la navegación (evento navigate de la Navigation API; en navegadores
     sin ella, el clic). Sirve igual para la barra, para cualquier otro enlace de la página y para Atrás/Adelante.
   - Si la navegación se cancela o la reemplaza otra, el resaltado vuelve a la página real o pasa a la nueva.
   - Mientras llega la página nueva, el navegador deja congelado el último cuadro de la anterior. Ese cuadro
     (posición del óvalo y cuánto le faltaba) se guarda en pageswap/pagehide, y la página nueva sigue el
     desplazamiento desde ahí, sin saltos. Si el destino resultó ser otro (redirección), muestra la pestaña real.
   - aria-current y data-mnav-current no se tocan: siguen marcando la página abierta. De data-mnav-current
     dependen las reglas de precarga (partials/speculation-rules); si cambiara al tocar, Chrome descartaba
     la página precargada justo cuando iba a usarla y la volvía a pedir al servidor. */
(() => {
    const nav = document.getElementById('mobileBottomNav');
    const track = nav && nav.querySelector('.mnav__track');
    if (!track || track.dataset.mnavReady) return;
    track.dataset.mnavReady = '1';

    const items = Array.from(track.querySelectorAll('.mnav__item'));
    const indicator = track.querySelector('.mnav__indicator');
    const current = items.findIndex((item) => item.hasAttribute('data-mnav-current'));
    const KEY = 'mnav-move';
    const DURATION = 220; // igual que --mnav-dur en cinema.css
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');

    const pathOf = (url) => {
        try {
            const u = new URL(url, location.href);
            return u.origin === location.origin ? (u.pathname.replace(/\/+$/, '') || '/') : null;
        } catch (e) { return null; }
    };
    const paths = items.map((item) => pathOf(item.href));
    const indexOf = (url) => { const path = pathOf(url); return path === null ? -1 : paths.indexOf(path); };

    let shown = current; // pestaña resaltada en pantalla (-1: ninguna)
    let pending = -1;    // pestaña hacia la que se está navegando
    let token = 0;

    // Posición visible del óvalo en celdas (0 = primera), incluida la que tiene a mitad de un desplazamiento.
    const position = () => {
        const width = indicator.offsetWidth;
        if (!width) return null;
        try { return new DOMMatrixReadOnly(getComputedStyle(indicator).transform).m41 / width; } catch (e) { return null; }
    };

    const place = (value, instant) => {
        if (instant) {
            track.classList.add('is-instant');
            track.style.setProperty('--mnav-index', value);
            void indicator.offsetWidth; // aplica la posición sin transición antes de reactivarla
            track.classList.remove('is-instant');
        } else {
            track.style.setProperty('--mnav-index', value);
        }
    };

    const render = (index, instant) => {
        const fromIdle = shown < 0;
        shown = index;
        items.forEach((item, i) => item.classList.toggle('is-active', i === index));
        track.classList.toggle('is-idle', index < 0);
        // Desde "ninguna" el óvalo aparece en su sitio; no viaja desde la primera pestaña.
        if (index >= 0) place(index, instant || fromIdle);
    };

    // Mientras hay una navegación en curso se anota, cuadro a cuadro, dónde se pintó el óvalo: es lo que
    // queda congelado en pantalla cuando el navegador cambia de página.
    let movedAt = 0, lastAt = 0, lastPos = null, watching = 0;
    const watch = (now) => {
        if (pending < 0) { watching = 0; return; }
        lastPos = position();
        lastAt = now;
        watching = requestAnimationFrame(watch);
    };

    const remember = () => {
        if (pending < 0) return;
        try {
            sessionStorage.setItem(KEY, JSON.stringify({
                to: paths[pending],
                from: lastPos === null ? null : lastPos / items.length,
                left: Math.max(0, DURATION - (lastAt - movedAt)),
                t: Date.now(),
            }));
        } catch (e) {}
    };

    // La navegación hacia la pestaña `index` ya empezó: se refleja al instante.
    const go = (index) => {
        const id = ++token;
        if (index === pending) return id;
        lastPos = shown >= 0 ? position() : null; // desde "ninguna" no hay recorrido que continuar
        movedAt = lastAt = performance.now();
        pending = index;
        render(index);
        nav.classList.add('is-navigating');
        if (!watching) watching = requestAnimationFrame(watch);
        return id;
    };

    // Se canceló: vuelve a la pestaña de la página que sigue abierta.
    const settle = () => {
        pending = -1;
        nav.classList.remove('is-navigating');
        render(current);
    };

    // Página recién abierta (o devuelta por Atrás): continúa el viaje del óvalo si venía de otra pestaña.
    const resume = () => {
        let move = null;
        try { move = JSON.parse(sessionStorage.getItem(KEY) || 'null'); sessionStorage.removeItem(KEY); } catch (e) {}
        shown = -1;
        if (current < 0) { render(-1); return; }
        const age = move ? Date.now() - move.t : Infinity;
        // Sin dato reciente, otro destino, o el óvalo ya había llegado en la página anterior: queda en su sitio.
        if (!move || move.to !== paths[current] || move.from === null || age < 0 || age > 10000 || move.left < 40 || reduced.matches) {
            render(current, true);
            return;
        }
        items.forEach((item, i) => item.classList.toggle('is-active', i === current));
        track.classList.remove('is-idle');
        shown = current;
        place(move.from * items.length, true); // el mismo punto del último cuadro de la página anterior
        indicator.style.transitionDuration = `${Math.round(move.left)}ms`;
        place(current);                        // y termina el recorrido en el tiempo que le faltaba
        setTimeout(() => { indicator.style.transitionDuration = ''; }, move.left + 50);
    };

    resume();

    // Toques en la propia barra.
    track.addEventListener('click', (event) => {
        const item = event.target.closest('.mnav__item');
        if (!item || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const index = items.indexOf(item);
        // Ya estás en esta sección: sube al inicio en vez de recargar.
        if (index === current && pending < 0) {
            event.preventDefault();
            window.scrollTo({ top: 0, behavior: reduced.matches ? 'auto' : 'smooth' });
            return;
        }
        // Ya va en camino a esta misma página: un segundo toque no la reinicia.
        if (index === pending) event.preventDefault();
    });

    if (window.navigation && 'onnavigate' in window.navigation) {
        // Una sola entrada para todo: barra, otros enlaces, formularios GET, location.assign y Atrás/Adelante.
        window.navigation.addEventListener('navigate', (event) => {
            if (event.hashChange || event.downloadRequest !== null || event.formData) return;
            const index = indexOf(event.destination.url);
            if (index < 0) return;
            const id = go(index);
            event.signal.addEventListener('abort', () => { if (id === token) settle(); }, { once: true });
        });
    } else {
        // Respaldo: el clic, una vez que ningún otro script lo canceló (fase de burbuja en window).
        window.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const link = event.target.closest && event.target.closest('a[href]');
            if (!link || (link.target && link.target !== '_self') || link.hasAttribute('download')) return;
            const url = new URL(link.href, location.href);
            if (url.hash && url.pathname === location.pathname && url.search === location.search) return; // ancla en la misma página
            const index = indexOf(url.href);
            if (index >= 0) go(index);
        });
    }

    // pageswap: justo antes del último cuadro (Chrome, Safari 18.2+). pagehide: respaldo en los demás.
    window.addEventListener('pageswap', remember);
    window.addEventListener('pagehide', () => { if (!('onpageswap' in window)) remember(); });

    // Al volver con Atrás/Adelante desde la memoria del navegador (bfcache) se muestra la pestaña real.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        pending = -1;
        token++;
        nav.classList.remove('is-navigating');
        resume();
    });
})();
