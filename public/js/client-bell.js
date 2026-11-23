/* Campana de avisos del cliente (sin cuenta).
   Muestra las reservas hechas en este dispositivo y los avisos de cada una, aunque el push no haya llegado.
   Solo actualiza lo que se ve en pantalla; los avisos reales los envía el servidor. */
(() => {
    const bell = document.getElementById('clientBell');
    const panel = document.getElementById('clientBellPanel');
    const backdrop = document.getElementById('clientBellBackdrop');
    if (!bell || !panel || !backdrop) return;

    const badge = bell.querySelector('[data-bell-badge]');
    const body = panel.querySelector('[data-bell-body]');
    const read = (key, fallback) => { try { return JSON.parse(localStorage.getItem(key)) || fallback; } catch (e) { return fallback; } };
    const write = (key, value) => { try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) {} };
    const el = (tag, className, text) => { const node = document.createElement(tag); if (className) node.className = className; if (text) node.textContent = text; return node; };

    let results = []; // [{ booking, state }]
    let lastNewest = null; // id del aviso más reciente ya visto en esta página (null = primera carga)

    const unreadCount = () => {
        const seen = read('ubmc-bell-seen', {});
        return results.reduce((total, { booking, state }) => total + state.notifications.filter((n) => n.id > (seen[booking.key] || 0)).length, 0);
    };

    const paintBadge = () => {
        const count = unreadCount();
        badge.hidden = count === 0;
        badge.textContent = count > 9 ? '9+' : String(count);
        bell.setAttribute('aria-label', count ? `Avisos: ${count} sin leer` : 'Avisos');
    };

    const paintPanel = () => {
        body.replaceChildren();
        if (results.length === 0) {
            const empty = el('div', 'bell-empty');
            empty.append(el('i', 'bi bi-bell'), el('p', '', 'Aquí verás los avisos de tu turno o cita.'));
            const link = el('a', 'btn btn-gold btn-sm', 'Reservar');
            link.href = panel.dataset.bookingUrl;
            empty.append(link);
            body.append(empty);
            return;
        }
        results.forEach(({ booking, state }) => {
            const group = el('section', 'bell-group');
            const head = el('a', 'bell-group-head');
            head.href = booking.url;
            head.append(el('strong', '', booking.label), el('span', '', state.label));
            group.append(head, el('p', 'bell-detail', state.detail));
            if (state.notifications.length === 0) {
                group.append(el('p', 'bell-none', 'Todavía no hay avisos.'));
            }
            state.notifications.forEach((n) => {
                const item = el('article', 'bell-item');
                const top = el('div', 'bell-item-top');
                top.append(el('strong', '', n.title), el('time', '', n.at));
                item.append(top, el('p', '', n.body));
                group.append(item);
            });
            body.append(group);
        });
    };

    const load = async () => {
        const bookings = read('ubmc-bookings', []);
        const keep = [];
        const next = [];
        for (const booking of bookings) {
            try {
                const response = await fetch(booking.statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (response.status === 404) continue; // la reserva ya no existe: se olvida
                if (!response.ok) { keep.push(booking); continue; }
                const state = await response.json();
                const old = Date.now() - (booking.savedAt || 0) > 24 * 3600 * 1000;
                if (!state.open && old) continue; // ya terminó hace más de un día: se olvida
                keep.push(booking);
                next.push({ booking, state: Object.assign({ notifications: [] }, state) });
            } catch (e) {
                keep.push(booking);
            }
        }
        if (keep.length !== bookings.length) write('ubmc-bookings', keep);
        // Aviso nuevo con la página abierta: timbre (si el push está activo, ya suena la notificación del sistema).
        const newest = next.reduce((max, { state }) => state.notifications.reduce((m, n) => Math.max(m, n.id), max), 0);
        if (lastNewest !== null && newest > lastNewest) window.ubmcChime?.();
        lastNewest = newest;
        results = next;
        paintBadge();
        if (!panel.hidden) { paintPanel(); markSeen(); }
    };

    const markSeen = () => {
        const seen = read('ubmc-bell-seen', {});
        results.forEach(({ booking, state }) => {
            seen[booking.key] = state.notifications.reduce((max, n) => Math.max(max, n.id), seen[booking.key] || 0);
        });
        write('ubmc-bell-seen', seen);
        paintBadge();
    };

    const toggle = (open) => {
        panel.hidden = !open;
        backdrop.hidden = !open;
        bell.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { paintPanel(); markSeen(); panel.focus({ preventScroll: true }); }
    };

    bell.addEventListener('click', (event) => { event.stopPropagation(); toggle(panel.hidden); });
    panel.addEventListener('click', (event) => event.stopPropagation());
    backdrop.addEventListener('click', (event) => { event.stopPropagation(); toggle(false); bell.focus(); });
    panel.querySelector('[data-bell-close]')?.addEventListener('click', () => { toggle(false); bell.focus(); });
    document.addEventListener('click', () => { if (!panel.hidden) toggle(false); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !panel.hidden) { toggle(false); bell.focus(); } });

    window.addEventListener('ubmc:bookings', load);
    // Llegó un push (sw.js avisa a las pestañas abiertas): se actualiza al instante, sin esperar 30 segundos.
    navigator.serviceWorker?.addEventListener('message', (event) => { if (event.data === 'booking-change') load(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
    setInterval(() => { if (!document.hidden && read('ubmc-bookings', []).length) load(); }, 30000);
    load();
})();
