/* Barra inferior (celular).
   - Un toque basta: la pestaña tocada se resalta al instante y aparece una barrita de carga mientras llega
     la página, así nunca parece trabada.
   - Tocar otra vez la misma pestaña mientras carga no reinicia la navegación (antes la reiniciaba y se sentía
     más lenta). Tocar la pestaña en la que ya estás sube al inicio de la página.
   - Las páginas de la barra se descargan por adelantado (partials/speculation-rules) para que abran casi
     al instante en Chrome y Android.
   - El resaltado aparece directo en la pestaña actual (sin deslizarse de una a otra), para que la barra
     no se vea deformada mientras cambia la sección. */
(() => {
    const nav = document.getElementById('mobileBottomNav');
    const track = nav?.querySelector('.mnav__track');
    if (!nav || !track) return;

    const items = Array.from(track.querySelectorAll('.mnav__item'));
    const serverActive = items.findIndex((item) => item.classList.contains('is-active'));
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let pending = null;

    const setActive = (index) => {
        items.forEach((item, i) => {
            item.classList.toggle('is-active', i === index);
            if (i === index) item.setAttribute('aria-current', 'page'); else item.removeAttribute('aria-current');
        });
        track.classList.toggle('is-idle', index < 0);
    };

    items.forEach((item, index) => {
        item.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

            // Ya estás en esta sección: sube al inicio en vez de recargar.
            if (!pending && index === serverActive) {
                event.preventDefault();
                window.scrollTo({ top: 0, behavior: reducedMotion ? 'auto' : 'smooth' });
                return;
            }

            // Ya va en camino a esta misma página: un segundo toque no la reinicia.
            if (pending === item.href) {
                event.preventDefault();
                return;
            }

            // El navegador navega de inmediato (sin esperas); aquí solo se muestra que el toque se recibió.
            pending = item.href;
            setActive(index);
            nav.classList.add('is-navigating');
        });
    });

    // Al volver con "atrás" (página guardada por el navegador) se restablece la pestaña real.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        pending = null;
        nav.classList.remove('is-navigating');
        setActive(serverActive);
    });
})();
