/*
 * Red de seguridad del sitio (se carga primero, sin dependencias):
 * 1. Reporta al servidor los errores de JavaScript (máx. 5 por página, sin datos del formulario).
 * 2. Si falla un script de animación (GSAP, cinema, immersive, motion) el contenido queda visible
 *    y la navegación sigue: se activa el modo estático en vez de dejar textos ocultos.
 * 3. Expone window.safeFetch: fetch con tiempo límite y errores clasificados para toda la interfaz.
 */
(function () {
    'use strict';
    var root = document.documentElement;
    var sent = 0;
    var seen = {};
    var ANIMATION = /(gsap|ScrollTrigger|lenis|cinema|immersive|motion)(\.min)?\.js/i;

    function report(payload) {
        try {
            var key = payload.message + '|' + payload.line;
            if (sent >= 5 || seen[key]) return;
            seen[key] = true;
            sent++;
            payload.page = location.pathname;
            var body = JSON.stringify(payload);
            var url = '/errores-cliente';
            if (navigator.sendBeacon) navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }));
            else fetch(url, { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'application/json' } }).catch(function () {});
        } catch (e) { /* nunca romper la página por reportar */ }
    }

    function staticMode() {
        root.classList.add('cx-static', 'cx-failed');
        root.classList.remove('is-loading');
        var reveals = document.querySelectorAll('.reveal');
        for (var i = 0; i < reveals.length; i++) reveals[i].classList.add('visible');
    }
    window.__forceStaticMode = staticMode;

    window.addEventListener('error', function (event) {
        // Recurso que no cargó (imagen, script…): llega sin "message".
        if (!event.message && event.target && event.target !== window) {
            var src = event.target.src || event.target.href || '';
            if (event.target.tagName === 'SCRIPT' && ANIMATION.test(src)) staticMode();
            return;
        }
        if (event.filename && ANIMATION.test(event.filename)) staticMode();
        report({ type: 'error', message: String(event.message || ''), source: String(event.filename || ''), line: event.lineno || 0, stack: event.error && event.error.stack ? String(event.error.stack) : '' });
    }, true);

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event.reason || {};
        // Fallos de red esperables (sin conexión) no son errores del sitio.
        if (reason && (reason.name === 'AbortError' || reason.kind === 'network' || reason.kind === 'timeout')) return;
        report({ type: 'promise', message: String(reason.message || reason), stack: reason.stack ? String(reason.stack) : '' });
    });

    // Si el guion de animación nunca arrancó (script roto o bloqueado), se muestra todo igual.
    window.addEventListener('load', function () {
        setTimeout(function () {
            var waitLoader = function () {
                if (root.classList.contains('is-loading')) return setTimeout(waitLoader, 500);
                if (root.classList.contains('js') && !root.classList.contains('cx-ready') && !root.classList.contains('cx-static')) staticMode();
            };
            waitLoader();
        }, 1500);
    });

    /**
     * fetch con tiempo límite. Rechaza con un Error que trae .kind:
     * 'timeout' | 'network' | 'session' (419) | 'auth' (401) | 'forbidden' (403) | 'notfound' (404)
     * | 'validation' (422, con .errors) | 'ratelimit' (429) | 'server' (500+) | 'http'.
     * Nunca reintenta solo: quien llama decide si la operación es segura de repetir.
     */
    window.safeFetch = function (url, options, timeoutMs) {
        options = options || {};
        var controller = 'AbortController' in window ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, timeoutMs || 15000) : null;
        if (controller) options.signal = controller.signal;
        options.credentials = options.credentials || 'same-origin';
        options.headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest' }, options.headers || {});

        var fail = function (kind, status, extra) {
            var error = new Error(kind);
            error.kind = kind;
            error.status = status || 0;
            if (extra) Object.assign(error, extra);
            return error;
        };

        var request;
        try {
            request = fetch(url, options);
        } catch (syncError) {
            // fetch no disponible o argumentos inválidos: se trata como fallo de red controlado.
            if (timer) clearTimeout(timer);
            return Promise.reject(fail('network', 0));
        }

        return request.then(function (response) {
            if (timer) clearTimeout(timer);
            if (response.ok) return response;
            var s = response.status;
            var kind = s === 419 ? 'session' : s === 401 ? 'auth' : s === 403 ? 'forbidden' : s === 404 ? 'notfound'
                : s === 422 ? 'validation' : s === 429 ? 'ratelimit' : s >= 500 ? 'server' : 'http';
            return response.json().catch(function () { return {}; }).then(function (body) {
                throw fail(kind, s, { errors: body.errors || {}, body: body, requestId: response.headers.get('X-Request-Id') });
            });
        }, function (error) {
            if (timer) clearTimeout(timer);
            throw fail(error && error.name === 'AbortError' ? 'timeout' : 'network', 0);
        });
    };
})();
