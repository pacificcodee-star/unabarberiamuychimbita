(() => {
    // Páginas públicas que se actualizan solas cuando el panel cambia su contenido.
    const pages = new Set(['/', '/servicios', '/barberos', '/resenas', '/contacto', '/nosotros']);
    const path = location.pathname.replace(/\/+$/, '') || '/';
    const current = document.body?.dataset.contentVersion;
    if (!current || !pages.has(path)) return;

    let checking = false;

    const busy = () => {
        const el = document.activeElement;
        if (el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName))) return true;
        return Boolean(document.querySelector('dialog[open], .modal.show, .offcanvas.show, [aria-modal="true"]:not([hidden])'));
    };

    const check = async () => {
        if (checking || document.visibilityState !== 'visible') return;
        checking = true;
        try {
            const response = await fetch('/contenido/version', { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            if (data.v && data.v !== current && !busy()) {
                window.location.reload();
            }
        } catch (error) {
            // Sin conexión o servidor ocupado: se reintenta en el siguiente ciclo.
        } finally {
            checking = false;
        }
    };

    window.setInterval(check, 12000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') check();
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) check();
    });
})();
