/* Reconoce otra vez este dispositivo cuando el servidor ya no lo identifica.
   El navegador guarda el enlace de seguimiento de cada reserva (ubmc-bookings). Si la cookie de
   «Mis servicios» se perdió pero ese enlace sigue aquí, se pide al servidor vincular el dispositivo
   y la página se recarga ya con «Mis servicios» en el menú. */
(() => {
    if (document.body?.dataset.deviceKnown === '1') return;

    const guardKey = 'ubmc-relink';
    try {
        const attempted = Number(sessionStorage.getItem(guardKey) || 0);
        if (attempted && Date.now() - attempted < 10 * 60 * 1000) return;
        sessionStorage.setItem(guardKey, String(Date.now()));
    } catch (e) {
        return;
    }

    let booking = null;
    try {
        const list = JSON.parse(localStorage.getItem('ubmc-bookings') || '[]');
        booking = list.find((item) => item && typeof item.url === 'string' && item.url.startsWith(location.origin + '/'));
    } catch (e) {
        return;
    }
    const match = booking && booking.url.match(/\/seguimiento\/(cita|turno)\/([^/?#]+)/);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!match || !csrf) return;

    fetch('/mis-servicios/vincular', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ type: match[1], token: decodeURIComponent(match[2]) }),
    })
        .then((response) => (response.ok ? location.reload() : null))
        .catch(() => {});
})();
