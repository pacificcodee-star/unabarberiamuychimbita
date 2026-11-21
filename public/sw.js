/* Service worker de Una Barberia Muy Chimbita
   - Páginas públicas: primero la red; si no hay internet, la última copia guardada o la página "sin conexión".
   - CSS, JS, imágenes y fuentes: se sirven del caché y se actualizan en segundo plano.
   - El panel de administración y los formularios (POST) nunca se guardan en caché.
   - Notificaciones push: muestra los avisos de turnos y citas aunque la página o el navegador estén cerrados. */
const VERSION = 'v3'; // v3: avisos con sonido y vibración siempre (renotify + requireInteraction)
const PAGES = `pages-${VERSION}`;
const ASSETS = `assets-${VERSION}`;
const OFFLINE_URL = '/offline.html';
const PUBLIC_PAGES = ['/', '/servicios', '/barberos', '/resenas', '/contacto', '/reservar'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(PAGES).then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon-192.png'])).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => ![PAGES, ASSETS].includes(k)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

const isPublicPage = (url) => PUBLIC_PAGES.includes(url.pathname.replace(/\/+$/, '') || '/');
const isAsset = (url) => /^\/(css|js|images|icons|storage)\//.test(url.pathname) || url.pathname === '/manifest.webmanifest' || url.hostname.includes('fonts.g') || url.hostname.includes('cdn.jsdelivr.net');

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);

    if (request.mode === 'navigate') {
        if (url.origin !== self.location.origin || !isPublicPage(url)) return; // admin, login, etc.: siempre en vivo
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(PAGES).then((cache) => cache.put(url.pathname, copy));
                    }
                    return response;
                })
                .catch(async () => (await caches.match(url.pathname)) || (await caches.match(OFFLINE_URL)))
        );
        return;
    }

    if (isAsset(url) && !request.headers.has('range')) {
        event.respondWith(
            caches.open(ASSETS).then(async (cache) => {
                const cached = await cache.match(request);
                const network = fetch(request)
                    .then((response) => {
                        if (response.ok || response.type === 'opaque') cache.put(request, response.clone());
                        return response;
                    })
                    .catch(() => cached);
                return cached || network;
            })
        );
    }
});

/* ---------- Notificaciones push (turnos y citas) ---------- */

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }
    const title = data.title || 'Una Barberia Muy Chimbita';
    // Sonido: no se puede elegir un archivo de audio; el sistema usa el TONO DE NOTIFICACIÓN POR DEFECTO
    // del celular o del computador. Para que suene siempre:
    //  - silent: false (nunca en silencio),
    //  - renotify: true con una etiqueta: si el aviso reemplaza a otro, igual vuelve a sonar y vibrar,
    //  - requireInteraction: queda visible hasta que la persona lo toque o lo cierre.
    const tag = data.tag || ('aviso-' + Date.now());
    event.waitUntil(
        self.registration.showNotification(title, {
            body: data.body || '',
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            tag,
            renotify: true,
            requireInteraction: true,
            vibrate: [300, 120, 300, 120, 300],
            lang: 'es',
            silent: false,
            timestamp: Date.now(),
            data: { url: data.url || '/' },
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (windows) => {
            // Si la app (PWA) o una pestaña ya está abierta, se enfoca y navega dentro de ella:
            // así el aviso no abre una segunda ventana ni saca al cliente de la app instalada.
            const open = windows.find((client) => client.url === target)
                || windows.find((client) => client.url.startsWith(self.location.origin));
            if (open) {
                await open.focus();
                if (open.url !== target && 'navigate' in open) {
                    try { await open.navigate(target); } catch (e) {}
                }
                return open;
            }
            return self.clients.openWindow(target);
        })
    );
});

// El navegador puede renovar la suscripción por su cuenta: se le informa al servidor para no perder los avisos.
self.addEventListener('pushsubscriptionchange', (event) => {
    const oldEndpoint = event.oldSubscription ? event.oldSubscription.endpoint : null;
    const options = event.oldSubscription ? event.oldSubscription.options : null;
    if (!oldEndpoint) return;
    event.waitUntil(
        (event.newSubscription ? Promise.resolve(event.newSubscription)
            : (options ? self.registration.pushManager.subscribe(options) : Promise.reject(new Error('sin opciones'))))
            .then((subscription) => fetch('/push/renovar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ old_endpoint: oldEndpoint, subscription: subscription.toJSON() }),
            }))
            .catch(() => {})
    );
});
