(() => {
    const regions = Array.from(document.querySelectorAll('[data-booking-region]'));
    if (!regions.length) return;
    let pending = true;
    let loading = false;

    async function refresh() {
        if (!pending || loading || document.hidden) return;
        loading = true;
        pending = false;
        try {
            const response = await fetch(location.href, { cache: 'no-store' });
            if (!response.ok || response.redirected) throw Error();
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            for (const region of regions) {
                const next = html.querySelector('[data-booking-region="' + region.dataset.bookingRegion + '"]');
                if (!next || next.innerHTML === region.innerHTML) continue;
                if (region.contains(document.activeElement) || region.querySelector('.attended-confirm')) {
                    pending = true;
                    continue;
                }
                region.replaceChildren(...next.childNodes);
            }
        } catch {
            pending = true;
        } finally {
            loading = false;
        }
    }
    function changed() { pending = true; refresh(); }
    window.addEventListener('booking:changed', changed);
    window.addEventListener('storage', event => { if (event.key === 'ubmc-service-change') changed(); });
    navigator.serviceWorker?.addEventListener('message', event => { if (event.data === 'booking-change') changed(); });
    document.addEventListener('visibilitychange', changed);
    document.addEventListener('focusout', () => setTimeout(refresh, 0));
    // Incluye cambios manuales del panel que no generan un aviso para el equipo.
    setInterval(changed, 30000);
})();
