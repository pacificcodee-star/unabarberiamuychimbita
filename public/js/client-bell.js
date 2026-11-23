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

    /* Modo local: el navegador no tiene servicio push (Brave lo trae apagado y ninguna página puede encenderlo).
       Mientras la página o la app estén abiertas, aunque sea en segundo plano, la campana muestra la
       notificación del sistema con sonido, igual que un push. */
    const localMode = () => {
        try { return 'Notification' in window && Notification.permission === 'granted' && localStorage.getItem('ubmc-push-local') === '1'; } catch (e) { return false; }
    };
    const notifyLocally = async (items, since) => {
        try {
            const registration = await navigator.serviceWorker.ready;
            items.forEach(({ booking, state }) => state.notifications
                .filter((n) => n.id > since)
                .forEach((n) => registration.showNotification(n.title, {
                    body: n.body || '', icon: '/icons/icon-192.png', badge: '/icons/icon-192.png',
                    tag: 'local-' + booking.key + '-' + n.id, renotify: true, silent: false, requireInteraction: true,
                    vibrate: [300, 120, 300], data: { url: booking.url || '/' },
                })));
        } catch (e) {}
    };

    /* Avisos borrados con la papelera: por reserva se guarda el último aviso borrado y solo se muestran los
       que lleguen después. No se borra nada del servidor ni la reserva guardada (la siguen usando los avisos
       push y la recuperación del dispositivo). */
    const clearedOf = () => read('ubmc-bell-cleared', {});
    const visible = () => {
        const cleared = clearedOf();
        return results
            .map(({ booking, state }) => ({ booking, state, notifications: state.notifications.filter((n) => !(booking.key in cleared) || n.id > cleared[booking.key]) }))
            .filter(({ booking, notifications }) => !(booking.key in cleared) || notifications.length > 0);
    };

    const unreadCount = () => {
        const seen = read('ubmc-bell-seen', {});
        return visible().reduce((total, { booking, notifications }) => total + notifications.filter((n) => n.id > (seen[booking.key] || 0)).length, 0);
    };

    const paintBadge = () => {
        const count = unreadCount();
        badge.hidden = count === 0;
        badge.textContent = count > 9 ? '9+' : String(count);
        bell.setAttribute('aria-label', count ? `Avisos: ${count} sin leer` : 'Avisos');
    };

    const paintPanel = () => {
        body.replaceChildren();
        const groups = visible();
        if (clearButton) clearButton.hidden = groups.length === 0;
        if (groups.length === 0) {
            const empty = el('div', 'bell-empty');
            empty.append(el('i', 'bi bi-bell'), el('p', '', 'Aquí verás los avisos de tu turno o cita.'));
            const link = el('a', 'btn btn-gold btn-sm btn-booking-blue', 'Reservar');
            link.href = panel.dataset.bookingUrl;
            empty.append(link);
            body.append(empty);
            return;
        }
        groups.forEach(({ booking, state, notifications }) => {
            const group = el('section', 'bell-group');
            // Solo informa (Confirmada, Cancelada…): no lleva a ninguna página.
            const head = el('div', 'bell-group-head');
            head.append(el('strong', '', booking.label), el('span', '', state.label));
            group.append(head, el('p', 'bell-detail', state.detail));
            if (notifications.length === 0) {
                group.append(el('p', 'bell-none', 'Todavía no hay avisos.'));
            }
            notifications.forEach((n) => {
                const item = el('article', 'bell-item');
                const top = el('div', 'bell-item-top');
                top.append(el('strong', '', n.title), el('time', '', n.at));
                item.append(top, el('p', '', n.body));
                group.append(item);
            });
            body.append(group);
        });
    };

    // Papelera: borra todos los avisos de la campana con un desvanecido breve.
    const clearButton = panel.querySelector('[data-bell-clear]');
    let clearing = false;
    const clearAll = () => {
        if (clearing) return;
        clearing = true;
        const cleared = clearedOf();
        results.forEach(({ booking, state }) => {
            cleared[booking.key] = state.notifications.reduce((max, n) => Math.max(max, n.id), cleared[booking.key] || 0);
        });
        write('ubmc-bell-cleared', cleared);
        markSeen();
        const groups = Array.from(body.querySelectorAll('.bell-group'));
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced || !groups.length || !groups[0].animate) { clearing = false; paintPanel(); return; }
        clearButton.classList.add('is-busy');
        const done = groups.map((group, i) => group.animate([
            { opacity: 1, transform: 'translateX(0)', height: group.offsetHeight + 'px' },
            { opacity: 0, transform: 'translateX(14px)', height: group.offsetHeight + 'px', offset: .7 },
            { opacity: 0, transform: 'translateX(14px)', height: '0px', paddingTop: '0px', paddingBottom: '0px' },
        ], { duration: 320, delay: i * 50, easing: 'cubic-bezier(.22, 1, .36, 1)', fill: 'forwards' }).finished.catch(() => {}));
        Promise.all(done).then(() => {
            clearing = false;
            clearButton.classList.remove('is-busy');
            paintPanel();
            const empty = body.querySelector('.bell-empty');
            empty?.animate?.([{ opacity: 0 }, { opacity: 1 }], { duration: 200, easing: 'ease-out' });
        });
    };
    clearButton?.addEventListener('click', clearAll);

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
        if (lastNewest !== null && newest > lastNewest) {
            window.ubmcChime?.();
            if (localMode()) notifyLocally(next, lastNewest);
        }
        lastNewest = newest;
        results = next;
        paintBadge();
        if (!panel.hidden && !clearing) { paintPanel(); markSeen(); }
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
    // Con la página a la vista cada 30 s. En modo local también en segundo plano (el navegador lo limita
    // a ~1 vez por minuto), para que el aviso llegue aunque la pestaña esté minimizada.
    setInterval(() => { if ((!document.hidden || localMode()) && read('ubmc-bookings', []).length) load(); }, 30000);
    load();
})();
