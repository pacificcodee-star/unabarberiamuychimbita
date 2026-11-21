/* Avisos push del cliente para un turno o cita (sin cuenta).
   Se activan solos: si el navegador ya dio permiso, el dispositivo se registra sin tocar nada; si no, se le pide
   al navegador que muestre su pregunta (esa pregunta es del navegador y ninguna página puede saltársela).
   Los recordatorios los envía el servidor: aquí no hay temporizadores de avisos. */
(() => {
    const box = document.getElementById('pushBox');
    if (!box) return;

    const cfg = box.dataset;
    const flagKey = 'push-on:' + cfg.subject;
    const show = (name) => box.querySelectorAll('[data-push-state]').forEach((el) => { el.hidden = el.dataset.pushState !== name; });
    const errorEl = box.querySelector('[data-push-error]');
    const setError = (text) => { if (errorEl) { errorEl.textContent = text || ''; errorEl.hidden = !text; } };
    const flag = { // '1' = activados, '0' = el cliente los desactivó a propósito (no se reactivan solos)
        get: (suffix) => { try { return localStorage.getItem(flagKey + (suffix || '')); } catch (e) { return null; } },
        set: (value, suffix) => { try { localStorage.setItem(flagKey + (suffix || ''), value); } catch (e) {} },
    };

    // Se recuerda la reserva en este dispositivo para mostrarla en la campana de la página.
    try {
        if (cfg.trackUrl && cfg.statusUrl) {
            const list = JSON.parse(localStorage.getItem('ubmc-bookings') || '[]').filter((item) => item && item.key !== cfg.subject);
            list.unshift({ key: cfg.subject, label: cfg.label || 'Tu reserva', url: cfg.trackUrl, statusUrl: cfg.statusUrl, savedAt: Date.now() });
            localStorage.setItem('ubmc-bookings', JSON.stringify(list.slice(0, 5)));
            window.dispatchEvent(new Event('ubmc:bookings'));
        }
    } catch (e) {}

    const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    // iPhone/iPad: al "Agregar a inicio" desde esta página, la app instalada abre directamente este turno o cita.
    if (isIOS && !standalone && cfg.manifestUrl) {
        const link = document.querySelector('link[rel="manifest"]');
        if (link) link.href = cfg.manifestUrl;
    }

    const keyToBytes = (base64) => {
        const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
    };

    const friendly = (error) => {
        const text = String((error && error.message) || error || '');
        if (/push service/i.test(text) || (error && error.name === 'AbortError')) {
            return 'Tu navegador no pudo conectarse con su servicio de notificaciones. Si usas Brave, activa "Usar los servicios de Google para la mensajería push" en brave://settings/privacy y vuelve a cargar. En otros casos revisa tu conexión o abre la página en Chrome o Edge. Mientras tanto, tus avisos aparecen en la campana de arriba.';
        }
        if (/applicationServerKey|InvalidAccess/i.test(text)) {
            return 'La clave de notificaciones del sitio no es válida. Avísale a la barbería.';
        }
        return text || 'No se pudieron activar los avisos en este dispositivo.';
    };

    const send = (method, body) => fetch(cfg.pushUrl, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    const getSubscription = async (create) => {
        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();
        if (subscription || !create) return subscription;
        const options = { userVisibleOnly: true, applicationServerKey: keyToBytes(cfg.vapid) };
        try {
            return await registration.pushManager.subscribe(options);
        } catch (error) {
            // Quedaba una suscripción vieja hecha con otra clave: se reemplaza.
            subscription = await registration.pushManager.getSubscription();
            if (!subscription) throw error;
            await subscription.unsubscribe();
            return registration.pushManager.subscribe(options);
        }
    };

    const save = async (subscription) => {
        const encodings = (window.PushManager && PushManager.supportedContentEncodings) || ['aes128gcm'];
        const payload = Object.assign(subscription.toJSON(), { contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm' });
        const response = await send('POST', payload);
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.message || 'No pudimos guardar tu dispositivo. Inténtalo de nuevo.');
        }
        flag.set('1');
        flag.set(subscription.endpoint, ':endpoint');
    };

    // Con el permiso ya concedido no se vuelve a preguntar nada: se reutiliza la suscripción
    // (o se crea en silencio) y solo se avisa al servidor si cambió el dispositivo.
    const activate = async (silent) => {
        const known = flag.get(':endpoint');
        if (!silent || !known) show('working');
        try {
            const subscription = await getSubscription(true);
            if (known === subscription.endpoint && flag.get() === '1') { show('on'); return; }
            await save(subscription);
            show('on');
        } catch (error) {
            show('ask');
            setError(friendly(error));
        }
    };

    const refresh = async () => {
        setError('');
        if (cfg.open !== '1') { box.hidden = true; return; }
        if (!cfg.vapid) return show('unavailable');
        if (isIOS && !standalone) return show('ios');
        if (!supported) return show('unsupported');
        if (Notification.permission === 'denied') return show('denied');
        if (flag.get() === '0') return show('ask'); // el cliente los apagó: se respeta
        if (Notification.permission === 'granted') return activate(true); // ya hay permiso: se activan solos, sin preguntar

        // Sin permiso todavía: se le pide al navegador que pregunte una sola vez por dispositivo.
        // Chrome y Edge muestran la pregunta de inmediato; Firefox y Safari exigen un toque, y ahí queda el botón.
        if (cfg.auto === '1' && !flag.get(':asked')) {
            flag.set('1', ':asked');
            show('working');
            let permission = 'default';
            try { permission = await Notification.requestPermission(); } catch (e) {}
            if (permission === 'granted') return activate(false);
            return show(permission === 'denied' ? 'denied' : 'ask');
        }
        show('ask');
    };

    box.querySelector('[data-push-enable]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        setError('');
        try {
            const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
            if (permission !== 'granted') {
                show(permission === 'denied' ? 'denied' : 'ask');
                return;
            }
            await activate(false);
        } finally {
            button.disabled = false;
        }
    });

    box.querySelector('[data-push-disable]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        setError('');
        try {
            const subscription = await getSubscription(false);
            if (subscription) {
                await send('DELETE', { endpoint: subscription.endpoint }).catch(() => {});
                await subscription.unsubscribe().catch(() => {});
            }
            flag.set('0');
            flag.set('', ':endpoint');
            show('ask');
        } catch (error) {
            setError('No se pudieron desactivar los avisos. Inténtalo de nuevo.');
        } finally {
            button.disabled = false;
        }
    });

    refresh();

    /* Estado en vivo (posición en la fila, hora estimada). Solo actualiza lo que se ve en pantalla. */
    const live = document.getElementById('trackLive');
    if (!live || !cfg.statusUrl) return;
    const set = (name, text) => { const el = live.querySelector('[data-live="' + name + '"]'); if (el) el.textContent = text; };
    let lastStatus = live.dataset.status;
    const poll = async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(cfg.statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) return;
            const state = await response.json();
            set('label', state.label);
            set('detail', state.detail);
            const eta = live.querySelector('[data-live-eta]');
            if (eta) { eta.hidden = !state.call_at; set('call_at', state.call_at || ''); }
            const cancel = document.getElementById('trackCancel');
            if (cancel) cancel.hidden = !state.can_cancel;
            live.dataset.status = state.status;
            if (state.status !== lastStatus) {
                lastStatus = state.status;
                cfg.open = state.open ? '1' : '0';
                if (!state.open) box.hidden = true;
            }
        } catch (e) {}
    };
    setInterval(poll, 15000);
    document.addEventListener('visibilitychange', poll);
})();
