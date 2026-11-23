(() => {
    // Páginas públicas que se actualizan solas cuando el panel o el código cambian.
    const pages = new Set(['/', '/servicios', '/barberos', '/resenas', '/contacto', '/nosotros', '/reservar', '/mis-servicios']);
    const path = location.pathname.replace(/\/+$/, '') || '/';
    const current = document.body?.dataset.contentVersion;
    if (!current || !pages.has(path)) return;

    let checking = false;
    let edited = false; // Hay algo escrito en un formulario: no se recarga para no borrarlo.

    const busy = () => {
        const el = document.activeElement;
        if (el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName))) return true;
        return Boolean(document.querySelector('dialog[open], .modal.show, .offcanvas.show, [aria-modal="true"]:not([hidden])'));
    };

    const markEdited = (event) => {
        const form = event.target.closest?.('form');
        if (form && !form.closest('dialog')) edited = true; // lo escrito en un diálogo no se pierde: se puede recargar al cerrarlo.
    };
    document.addEventListener('input', markEdited, true);
    document.addEventListener('change', markEdited, true);
    document.addEventListener('submit', () => { edited = false; }, true);

    const check = async () => {
        if (checking || edited || document.visibilityState !== 'visible') return;
        checking = true;
        try {
            const response = await fetch('/contenido/version', { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            if (data.v && data.v !== current && !busy() && !edited) {
                window.location.reload();
            }
        } catch (error) {
            // Sin conexión o servidor ocupado: se reintenta en el siguiente ciclo.
        } finally {
            checking = false;
        }
    };

    window.setInterval(check, 10000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') check();
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) check();
    });
})();
