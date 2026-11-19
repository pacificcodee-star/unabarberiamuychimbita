/* Indicador de la barra inferior: anima desde la sección anterior guardada en la sesión. */
(() => {
    const track = document.querySelector('.mnav__track');
    if (!track) return;

    const indicator = track.querySelector('.mnav__indicator');
    const items = Array.from(track.querySelectorAll('.mnav__item'));
    const activeIndex = items.findIndex((item) => item.classList.contains('is-active'));
    if (!indicator || activeIndex < 0) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let previous = -1;

    try {
        const stored = Number(sessionStorage.getItem('mnavIndex'));
        if (Number.isInteger(stored) && stored >= 0 && stored < items.length) previous = stored;
        sessionStorage.setItem('mnavIndex', String(activeIndex));
    } catch (error) {
        previous = -1;
    }

    if (reducedMotion || previous < 0 || previous === activeIndex) return;

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
        indicator.style.transform = `translateX(${activeIndex * 100}%)`;
    });
})();
