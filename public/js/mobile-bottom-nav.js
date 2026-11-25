/* La barra se inicializa en línea. La selección visual usa una sola clase en cada enlace.
   aria-current y data-mnav-current siguen identificando la página real para accesibilidad y precarga. */
(() => {
    const nav = document.getElementById('mobileBottomNav');
    if (!nav) return;

    const items = Array.from(nav.querySelectorAll('.mnav__item'));
    const current = items.find((item) => item.hasAttribute('data-mnav-current'));
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    let pending = null;
    let frame = null;
    let loading = false;
    let navigationSignal = null;

    const pathOf = (url) => url.origin === location.origin ? url.pathname.replace(/\/+$/, '') || '/' : null;
    const byPath = new Map(items.map((item) => [pathOf(new URL(item.href)), item]));

    const select = (item) => {
        pending = item && item !== current ? item : null;
        nav.classList.toggle('is-navigating', pending !== null);
        items.forEach((link) => link.classList.toggle('is-active', link === (pending || current)));
    };

    const clearQueue = () => {
        if (frame !== null) cancelAnimationFrame(frame);
        frame = null;
    };

    const reset = () => {
        clearQueue();
        loading = false;
        navigationSignal = null;
        select(null);
    };

    const load = (item) => {
        clearQueue();
        loading = true;
        location.assign(item.href);
    };

    const plainClick = (event) => !event.defaultPrevented && event.button === 0
        && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;

    nav.addEventListener('click', (event) => {
        const item = event.target.closest('.mnav__item');
        if (!item || !plainClick(event)) return;

        event.preventDefault();
        if (item === pending) return;

        if (item === current) {
            if (loading) window.stop();
            reset();
            window.scrollTo({ top: 0, behavior: reduced.matches ? 'auto' : 'smooth' });
            return;
        }

        clearQueue();
        select(item);

        // Agrupa toques de un mismo cuadro. Una petición en curso se reemplaza en el acto.
        if (loading) load(item);
        else frame = requestAnimationFrame(() => {
            frame = null;
            load(item);
        });
    });

    const follow = (url) => {
        clearQueue();
        loading = true;
        select(byPath.get(pathOf(url)));
    };

    if (window.navigation && 'onnavigate' in window.navigation) {
        window.navigation.addEventListener('navigate', (event) => {
            if (event.hashChange || event.downloadRequest != null || event.formData) return;

            follow(new URL(event.destination.url));
            const signal = event.signal;
            navigationSignal = signal;
            signal.addEventListener('abort', () => {
                if (signal === navigationSignal) reset();
            }, { once: true });
        });
    } else {
        // Respaldo de Safari anteriores: los enlaces del contenido conservan la navegación nativa.
        window.addEventListener('click', (event) => {
            const link = event.target.closest?.('a[href]');
            if (!link || !plainClick(event) || (link.target && link.target !== '_self') || link.hasAttribute('download')) return;

            const url = new URL(link.href, location.href);
            if (!pathOf(url) || (url.hash && url.pathname === location.pathname && url.search === location.search)) return;
            follow(url);
        });
    }

    window.addEventListener('pagehide', clearQueue);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) reset();
    });
})();
