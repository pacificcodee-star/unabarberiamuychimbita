(() => {
    const legal = document.querySelector('.footer-legal');
    if (!legal) return;

    const apply = (on) => document.body.classList.toggle('footer-legal-in-view', on);

    if (!('IntersectionObserver' in window)) {
        const check = () => {
            const rect = legal.getBoundingClientRect();
            apply(rect.top < window.innerHeight && rect.bottom > 0);
        };
        check();
        window.addEventListener('scroll', check, { passive: true });
        return;
    }

    new IntersectionObserver((entries) => apply(entries[0].isIntersecting)).observe(legal);
})();
