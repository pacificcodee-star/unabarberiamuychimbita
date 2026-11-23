/* Avisos del cliente en la página pública y en la app instalada (PWA).
   - Al cargar: si los avisos todavía no están activados, aparece un aviso con "Activar".
     Si ya están activos (o el navegador los bloqueó), no aparece nada.
   - Al activarlos: se registra este dispositivo para cada cita o turno guardado aquí
     (ya no hace falta abrir la página de seguimiento).
   - Sin servicio push (Brave lo trae apagado; ninguna página puede encenderlo): "modo local".
     La campana revisa el estado y muestra la notificación del sistema con sonido mientras
     la página o la app estén abiertas (client-bell.js). */
(() => {
    if (!('Notification' in window) || !('serviceWorker' in navigator)) return;

    const vapid = document.querySelector('meta[name="vapid-public-key"]')?.content || '';
    const SNOOZE = 'ubmc-notify-snooze';
    const LOCAL = 'ubmc-push-local';
    const store = {
        get: (key) => { try { return localStorage.getItem(key); } catch (e) { return null; } },
        set: (key, value) => { try { localStorage.setItem(key, value); } catch (e) {} },
    };
    const bookings = () => { try { return JSON.parse(localStorage.getItem('ubmc-bookings') || '[]'); } catch (e) { return []; } };
    const bytes = (base64) => {
        const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
    };

    /** Registra el dispositivo en cada reserva guardada. Sin servicio push, pasa a modo local. */
    const subscribeBookings = async () => {
        if (Notification.permission !== 'granted') return;
        const targets = bookings().filter((b) => b && b.pushUrl);
        if (!('PushManager' in window) || !vapid) { store.set(LOCAL, '1'); return; }
        try {
            const registration = await navigator.serviceWorker.ready;
            let subscription = await registration.pushManager.getSubscription();
            if (!subscription) subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes(vapid) });
            store.set(LOCAL, '0');
            if (!targets.length) return;
            const encodings = PushManager.supportedContentEncodings || ['aes128gcm'];
            const payload = JSON.stringify(Object.assign(subscription.toJSON(), { contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm' }));
            const csrf = (await window.ubmcCsrf?.()) || document.querySelector('meta[name="csrf-token"]')?.content || '';
            await Promise.all(targets.map((b) => fetch(b.pushUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: payload,
            }).catch(() => {})));
        } catch (error) {
            // Brave sin "servicios de Google para mensajería push", o el servicio no respondió:
            // los avisos siguen llegando con la página abierta (modo local).
            store.set(LOCAL, '1');
        }
        window.dispatchEvent(new Event('ubmc:push-mode'));
    };

    const banner = () => {
        const box = document.createElement('div');
        box.className = 'notify-ask';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-label', 'Activar notificaciones');
        box.innerHTML = '<span class="notify-ask-ico" aria-hidden="true"><i class="bi bi-bell-fill"></i></span>'
            + '<div class="notify-ask-text"><strong>Activa las notificaciones</strong>'
            + '<span>Te avisamos al instante y con sonido cuando cambie tu cita o turno.</span></div>'
            + '<button type="button" class="notify-ask-yes">Activar</button>'
            + '<button type="button" class="notify-ask-no" aria-label="Ahora no"><i class="bi bi-x-lg" aria-hidden="true"></i></button>';
        // "Ahora no" lo oculta solo en esta visita: al volver a abrir la página se pide otra vez.
        const close = (snooze) => {
            box.classList.remove('is-visible');
            setTimeout(() => box.remove(), 300);
            if (snooze) { try { sessionStorage.setItem(SNOOZE, '1'); } catch (e) {} }
        };
        box.querySelector('.notify-ask-no').addEventListener('click', () => close(true));
        box.querySelector('.notify-ask-yes').addEventListener('click', async () => {
            let permission = 'default';
            try { permission = await Notification.requestPermission(); } catch (e) {}
            close(permission !== 'granted');
            if (permission === 'granted') {
                await subscribeBookings();
                try {
                    const registration = await navigator.serviceWorker.ready;
                    registration.showNotification('Notificaciones activadas', {
                        body: 'Te avisaremos aquí cuando cambie tu cita o turno.',
                        icon: '/icons/icon-192.png', badge: '/icons/icon-192.png', tag: 'notify-enabled', silent: false,
                    });
                } catch (e) {}
            }
        });
        document.body.appendChild(box);
        requestAnimationFrame(() => box.classList.add('is-visible'));
    };

    const start = () => {
        if (Notification.permission === 'granted') { subscribeBookings(); return; } // ya activos: sin aviso
        if (Notification.permission === 'denied') return;                          // bloqueados por la persona
        try { if (sessionStorage.getItem(SNOOZE) === '1') return; } catch (e) {}
        if (document.getElementById('pushBox')) return; // la página de seguimiento ya trae su propio "Activar avisos"
        // Espera a que termine la pantalla de carga para no taparla.
        const wait = () => document.documentElement.classList.contains('is-loading') ? setTimeout(wait, 300) : setTimeout(banner, 900);
        wait();
    };

    window.addEventListener('ubmc:bookings', subscribeBookings);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
