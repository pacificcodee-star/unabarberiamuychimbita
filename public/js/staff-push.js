/* Avisos push del equipo (panel).
   Un solo módulo para el aviso flotante "Activa los avisos del equipo" y para la fila
   "Avisos en este dispositivo" de la campana:
   - dice el estado real (activos, desactivados, bloqueados, con error) — nunca "activados" si falló;
   - explica cómo desbloquearlos (candado del navegador, opción de Brave);
   - "Probar" manda un aviso real desde el servidor a todos tus dispositivos y suena aquí mismo. */
(() => {
    const box = document.getElementById('staffPushPrompt');
    if (!box) return;

    const cfg = box.dataset;
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const isBrave = !!navigator.brave;
    const supported = 'Notification' in window && 'PushManager' in window && 'serviceWorker' in navigator && !!cfg.vapid;
    const listeners = new Set();
    let state = { name: supported ? 'checking' : 'unsupported', message: '' };

    const set = (name, message = '') => {
        state = { name, message };
        listeners.forEach((fn) => { try { fn(state); } catch (e) {} });
    };

    const bytes = (base64) => {
        const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
    };

    const blockedHelp = 'Están bloqueados. Toca el candado junto a la dirección → Notificaciones → Permitir, y recarga.';

    const explain = (error) => {
        const text = String((error && (error.name + ' ' + error.message)) || error || '');
        if (/AbortError|push service|Registration failed|NotAllowed.*service/i.test(text)) {
            return isBrave
                ? 'Brave bloquea los avisos push. Abre brave://settings/privacy, activa «Usar los servicios de Google para la mensajería push» y recarga esta página.'
                : 'El navegador no pudo conectarse con su servicio de avisos. Revisa tu conexión o prueba en Chrome o Edge.';
        }
        if (/applicationServerKey|InvalidAccess/i.test(text)) return 'La clave de avisos del sitio no es válida. Avísale al administrador.';
        return (error && error.message) || 'No se pudieron activar los avisos en este dispositivo.';
    };

    const swReady = supported
        ? navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' })
            .then((registration) => { registration.update().catch(() => {}); return navigator.serviceWorker.ready; })
            .catch(() => null)
        : Promise.resolve(null);

    const save = async (subscription) => {
        const encodings = PushManager.supportedContentEncodings || ['aes128gcm'];
        const payload = Object.assign(subscription.toJSON(), { contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm' });
        const response = await fetch(cfg.store, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(payload),
        });
        if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || 'No se pudo registrar este dispositivo en el servidor.');
    };

    /** Con permiso concedido: suscribe (o reutiliza) y lo registra en el servidor. Devuelve true solo si quedó activo. */
    const sync = async () => {
        if (!supported) { set('unsupported', 'Este navegador no admite avisos push.'); return false; }
        if (Notification.permission === 'denied') { set('denied', blockedHelp); return false; }
        if (Notification.permission !== 'granted') { set('off', 'Actívalos para recibir reservas y cambios de turno al instante.'); return false; }
        try {
            const registration = await swReady;
            if (!registration) throw new Error('El navegador no pudo iniciar el servicio de avisos. Recarga la página.');
            const options = { userVisibleOnly: true, applicationServerKey: bytes(cfg.vapid) };
            let subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                subscription = await registration.pushManager.subscribe(options);
            }
            try {
                await save(subscription);
            } catch (error) {
                // Suscripción vieja hecha con otra clave del servidor: se reemplaza una vez.
                await subscription.unsubscribe().catch(() => {});
                subscription = await registration.pushManager.subscribe(options);
                await save(subscription);
            }
            set('on', 'Activos. Te llegarán con sonido aunque el panel esté cerrado.');
            return true;
        } catch (error) {
            set('error', explain(error));
            return false;
        }
    };

    const enable = async () => {
        if (!supported) return sync();
        set('working', 'Conectando los avisos a este dispositivo…');
        let permission = Notification.permission;
        if (permission !== 'granted') {
            try { permission = await Notification.requestPermission(); } catch (e) { permission = 'default'; }
        }
        if (permission === 'denied') { set('denied', blockedHelp); return false; }
        if (permission !== 'granted') { set('off', 'Para activarlos, elige «Permitir» cuando el navegador pregunte.'); return false; }
        return sync();
    };

    /** Aviso de prueba real: sale del servidor y vuelve por el servicio push del navegador. */
    const test = async () => {
        window.ubmcChime?.({ force: true });
        const response = await fetch(cfg.test, {
            method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'No se pudo enviar el aviso de prueba.');
        return data;
    };

    window.staffPush = { get state() { return state; }, onChange: (fn) => { listeners.add(fn); fn(state); }, enable, sync, test, isBrave };

    /* ----- Aviso flotante ----- */
    const enableBtn = box.querySelector('[data-staff-push-enable]');
    const closeBtn = box.querySelector('[data-staff-push-close]');
    const status = box.querySelector('[data-staff-push-status]');
    const key = 'staff-push-dismissed-until';
    const dismissed = () => { try { return Number(localStorage.getItem(key) || 0) > Date.now(); } catch (e) { return false; } };
    const snooze = () => { try { localStorage.setItem(key, String(Date.now() + 3 * 864e5)); } catch (e) {} box.hidden = true; };

    closeBtn.addEventListener('click', snooze);
    enableBtn.addEventListener('click', async () => {
        enableBtn.disabled = true;
        const ok = await enable();
        enableBtn.disabled = false;
        if (ok) { enableBtn.textContent = 'Avisos activos'; window.setTimeout(() => { box.hidden = true; }, 2400); }
    });
    window.staffPush.onChange(({ name, message }) => {
        if (message) status.textContent = message;
        enableBtn.hidden = name === 'denied' || name === 'unsupported' || name === 'on';
    });

    (async () => {
        const ok = await sync();
        // Ya activos: el aviso flotante no aparece. Si no, se muestra (salvo que se haya pospuesto).
        if (!ok && !dismissed() && state.name !== 'unsupported') box.hidden = false;
    })();
})();
