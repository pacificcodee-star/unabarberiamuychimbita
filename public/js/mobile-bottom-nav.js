/* Barra inferior (celular).
   - Un toque basta: la pestaña tocada se resalta al instante y aparece una barrita de carga mientras llega
     la página, así nunca parece trabada.
   - Tocar otra vez la misma pestaña mientras carga no reinicia la navegación (antes la reiniciaba y se sentía
     más lenta). Tocar la pestaña en la que ya estás sube al inicio de la página.
   - Las páginas de la barra se descargan por adelantado (partials/speculation-rules) para que abran casi
     al instante en Chrome y Android.
   - El resaltado anima desde la sección anterior guardada en la sesión. */
(() => {
    const nav = document.getElementById('mobileBottomNav');
    const track = nav?.querySelector('.mnav__track');
    if (!nav || !track) return;

    const indicator = track.querySelector('.mnav__indicator');
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
            try { sessionStorage.setItem('mnavIndex', String(serverActive)); } catch (error) {}
        });
    });

    // Al volver con "atrás" (página guardada por el navegador) se restablece la pestaña real.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        pending = null;
        nav.classList.remove('is-navigating');
        setActive(serverActive);
    });

    /* Animación del resaltado desde la sección anterior. */
    if (!indicator || serverActive < 0) return;
    let previous = -1;
    try {
        const stored = Number(sessionStorage.getItem('mnavIndex'));
        if (Number.isInteger(stored) && stored >= 0 && stored < items.length) previous = stored;
        sessionStorage.setItem('mnavIndex', String(serverActive));
    } catch (error) {
        previous = -1;
    }
    if (reducedMotion || previous < 0 || previous === serverActive) return;

    const finish = () => {
        indicator.style.removeProperty('transform');
        indicator.style.removeProperty('transition');
    };
    indicator.style.transition = 'none';
    indicator.style.transform = `translateX(${previous * 100}%)`;
    void indicator.offsetWidth;
    indicator.style.removeProperty('transition');
    indicator.addEventListener('transitionend', finish, { once: true });
    requestAnimationFrame(() => {
        indicator.style.transform = `translateX(${serverActive * 100}%)`;
    });
})();
