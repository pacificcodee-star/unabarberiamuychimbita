/*
 * Envío resiliente de las reservas (cita y turno). Sin este archivo el formulario se envía normal.
 *
 * - Guarda un borrador en sessionStorage (solo esta pestaña, 30 min, se borra al reservar) y lo
 *   restaura si la página se recarga. No guarda nada en almacenamiento permanente.
 * - Envía con fetch y tiempo límite. Cada formulario lleva una clave única (idempotency_key):
 *   reintentar NUNCA crea una segunda reserva; el servidor devuelve la que ya existe.
 * - Si se pierde la respuesta (sin internet, tiempo agotado, error del servidor) NO dice "confirmada":
 *   pregunta al servidor si la reserva quedó hecha y solo entonces muestra la confirmación.
 * - Errores de validación junto a cada campo; sesión vencida (419) se renueva sola y se reintenta.
 */
(function () {
    'use strict';
    if (!window.fetch || !window.safeFetch) return; // sin soporte: envío tradicional

    var DRAFT_TTL = 30 * 60 * 1000;
    var FIELDS = ['barber_id', 'service_id', 'appointment_date', 'appointment_time', 'client_name', 'client_phone', 'client_email', 'payment_method', 'whatsapp_consent', 'notes', 'idempotency_key'];
    var storage = (function () { try { var s = window.sessionStorage; s.setItem('__t', '1'); s.removeItem('__t'); return s; } catch (e) { return null; } })();

    // --- Borrador ----------------------------------------------------------------------------
    function draftKey(form) { return 'reserva-borrador:' + form.dataset.bookingForm; }

    function saveDraft(form) {
        if (!storage) return;
        var data = { savedAt: Date.now(), values: {} };
        FIELDS.forEach(function (name) {
            var fields = form.querySelectorAll('[name="' + name + '"]');
            fields.forEach(function (field) {
                if (field.type === 'hidden' && name === 'whatsapp_consent') return;
                if (field.type === 'radio') { if (field.checked) data.values[name] = field.value; }
                else if (field.type === 'checkbox') data.values[name] = field.checked ? '1' : '';
                else data.values[name] = field.value;
            });
        });
        try { storage.setItem(draftKey(form), JSON.stringify(data)); } catch (e) {}
    }

    function readDraft(form) {
        if (!storage) return null;
        try {
            var data = JSON.parse(storage.getItem(draftKey(form)) || 'null');
            if (!data || Date.now() - data.savedAt > DRAFT_TTL) { storage.removeItem(draftKey(form)); return null; }
            return data.values;
        } catch (e) { return null; }
    }

    function clearDrafts() {
        if (!storage) return;
        ['cita', 'turno'].forEach(function (type) { try { storage.removeItem('reserva-borrador:' + type); } catch (e) {} });
    }

    function restoreDraft(form) {
        var values = readDraft(form);
        // Si el servidor devolvió el formulario con errores (old()), lo del servidor manda.
        if (!values || form.querySelector('.is-invalid')) return;
        Object.keys(values).forEach(function (name) {
            var value = values[name];
            form.querySelectorAll('[name="' + name + '"]').forEach(function (field) {
                if (field.type === 'hidden' && name !== 'idempotency_key' && name !== 'appointment_date') return;
                if (field.type === 'checkbox') field.checked = value === '1';
                else if (field.type !== 'radio' && value !== undefined && value !== '') field.value = value;
            });
        });
        // La hora se marca cuando la agenda termine de cargar.
        var slots = form.querySelector('#appointmentTimes');
        if (slots && values.appointment_time) slots.dataset.selected = values.appointment_time;
        form.dataset.restored = '1';
        document.dispatchEvent(new CustomEvent('booking:draft-restored', { detail: { form: form.dataset.bookingForm } }));
    }

    // --- Mensajes ----------------------------------------------------------------------------
    function feedbackBox(form) {
        var box = form.querySelector('[data-booking-feedback]');
        if (!box) {
            box = document.createElement('div');
            box.className = 'booking-feedback';
            box.setAttribute('data-booking-feedback', '');
            box.setAttribute('role', 'alert');
            box.hidden = true;
            var button = form.querySelector('[type="submit"]');
            button.parentNode.insertBefore(box, button);
        }
        return box;
    }

    function showFeedback(form, tone, text, action) {
        var box = feedbackBox(form);
        box.className = 'booking-feedback booking-feedback--' + tone;
        box.replaceChildren();
        var icon = document.createElement('i');
        icon.className = 'bi ' + (tone === 'info' ? 'bi-hourglass-split' : tone === 'warn' ? 'bi-exclamation-triangle' : 'bi-exclamation-circle');
        icon.setAttribute('aria-hidden', 'true');
        var span = document.createElement('span');
        span.textContent = text;
        box.append(icon, span);
        if (action) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'booking-feedback-action';
            btn.textContent = action.label;
            btn.addEventListener('click', action.run);
            box.appendChild(btn);
        }
        box.hidden = false;
    }

    function hideFeedback(form) { var box = form.querySelector('[data-booking-feedback]'); if (box) box.hidden = true; }

    function setBusy(form, busy, label) {
        var button = form.querySelector('[type="submit"]');
        if (!button) return;
        if (!button.dataset.originalHtml) button.dataset.originalHtml = button.innerHTML;
        button.disabled = busy;
        if (!busy) form.dataset.sending = '';
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) {
            button.innerHTML = '<span class="spinner" aria-hidden="true"></span> ';
            button.appendChild(document.createTextNode(label || 'Enviando…'));
        } else {
            button.innerHTML = button.dataset.originalHtml;
        }
    }

    function showFieldErrors(form, errors) {
        form.querySelectorAll('[data-js-error]').forEach(function (el) { el.remove(); });
        var first = null;
        Object.keys(errors || {}).forEach(function (name) {
            var field = form.querySelector('[name="' + name + '"]:not([type="hidden"])');
            var message = [].concat(errors[name])[0];
            if (!field) { showFeedback(form, 'error', message); return; }
            if (field.type === 'radio') {
                var help = document.getElementById('appointmentTimeHelp');
                if (help) { help.textContent = message; help.classList.add('text-danger'); }
                first = first || field;
                return;
            }
            field.classList.add('is-invalid');
            var feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            feedback.setAttribute('data-js-error', '');
            feedback.innerHTML = '<i class="bi bi-exclamation-circle" aria-hidden="true"></i>';
            feedback.appendChild(document.createTextNode(message));
            var old = field.parentNode.querySelector('.invalid-feedback:not([data-js-error])');
            if (old) old.remove();
            field.insertAdjacentElement('afterend', feedback);
            first = first || field;
        });
        if (first) first.focus();
        // Si la hora ya la tomó otra persona, se refresca la agenda (sin esa hora) y el aviso queda a la vista.
        if (errors && errors.appointment_time && window.reloadBookingSlots) {
            var help = document.getElementById('appointmentTimeHelp');
            var message = [].concat(errors.appointment_time)[0];
            var slots = form.querySelector('#appointmentTimes');
            if (slots) slots.dataset.selected = '';
            form.querySelectorAll('[name="appointment_time"]').forEach(function (r) { r.checked = false; });
            Promise.resolve(window.reloadBookingSlots()).then(function () {
                if (help) { help.textContent = message; help.classList.add('text-danger'); }
            });
        }
    }

    // --- Envío -------------------------------------------------------------------------------
    function freshToken() {
        return window.safeFetch('/sesion/token', { headers: { Accept: 'application/json' } }, 10000)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                document.querySelectorAll('input[name="_token"]').forEach(function (input) { input.value = data.token; });
                var meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) meta.content = data.token;
            });
    }

    function goTo(url) {
        clearDrafts();
        window.location.assign(url);
    }

    function post(form) {
        return window.safeFetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json' },
        }, 20000).then(function (response) { return response.json(); });
    }

    // ¿Quedó hecha? Se pregunta con calma (2s, 4s, 8s) antes de rendirse; nunca se reenvía sola.
    function verify(form, attempt) {
        attempt = attempt || 0;
        var key = form.querySelector('[name="idempotency_key"]').value;
        showFeedback(form, 'info', 'Estamos verificando tu reserva…');
        setBusy(form, true, 'Verificando…');
        return window.safeFetch('/reservar/estado/' + encodeURIComponent(key), { headers: { Accept: 'application/json' } }, 10000)
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.status === 'done') return goTo(result.redirect);
                if (result.status === 'processing' && attempt < 4) return delay(2000 * Math.pow(2, attempt)).then(function () { return verify(form, attempt + 1); });
                setBusy(form, false);
                showFeedback(form, 'warn', 'Tu reserva no alcanzó a registrarse. Tus datos siguen aquí: puedes confirmarla de nuevo sin riesgo de duplicarla.');
            })
            .catch(function () {
                if (attempt < 2) return delay(2000 * Math.pow(2, attempt)).then(function () { return verify(form, attempt + 1); });
                setBusy(form, false);
                form.dataset.pendingVerify = '1';
                showFeedback(form, 'warn', 'No pudimos confirmar el resultado. Consulta el estado antes de volver a reservar.', {
                    label: 'Consultar estado',
                    run: function () { verify(form, 0); },
                });
            });
    }

    function delay(ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }

    function send(form, retriedSession) {
        if (form.dataset.sending === '1') return;
        form.dataset.sending = '1';
        saveDraft(form);
        hideFeedback(form);
        setBusy(form, true, 'Enviando…');

        post(form).then(function (result) {
            if (result && result.redirect) return goTo(result.redirect);
            throw Object.assign(new Error('respuesta'), { kind: 'server' });
        }).catch(function (error) {
            switch (error.kind) {
                case 'validation':
                    setBusy(form, false);
                    showFieldErrors(form, error.errors);
                    showFeedback(form, 'error', 'Revisa los datos marcados. Lo demás que escribiste sigue aquí.');
                    break;
                case 'session':
                    // Sesión vencida: token nuevo y un solo reintento (la clave evita duplicar).
                    if (retriedSession) { setBusy(form, false); showFeedback(form, 'error', 'Tu sesión se venció. Recarga la página: tus datos se recuperan solos.'); break; }
                    form.dataset.sending = '';
                    return freshToken().then(function () { send(form, true); }, function () {
                        setBusy(form, false);
                        showFeedback(form, 'error', 'Tu sesión se venció. Recarga la página: tus datos se recuperan solos.');
                    });
                case 'ratelimit':
                    setBusy(form, false);
                    showFeedback(form, 'error', 'Hiciste varios intentos seguidos. Espera un minuto y vuelve a confirmar.');
                    break;
                case 'http':
                    if (error.status === 409) { return delay(3000).then(function () { form.dataset.sending = ''; verify(form); }); }
                    setBusy(form, false);
                    showFeedback(form, 'error', 'No pudimos procesar la reserva. Inténtalo de nuevo.');
                    break;
                default:
                    // network, timeout, server: la reserva pudo haberse creado. Se verifica, no se reenvía.
                    form.dataset.sending = '';
                    return verify(form);
            }
        });
    }

    // --- Permiso de avisos en el mismo toque de "Confirmar" -------------------------------------
    var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var canAskPush = 'Notification' in window && 'PushManager' in window && 'serviceWorker' in navigator
        && Notification.permission === 'default' && !(isIOS && !standalone);
    if (canAskPush) document.querySelectorAll('[data-push-hint]').forEach(function (hint) { hint.hidden = false; });

    function askPushThen(form, next) {
        if (!canAskPush || form.dataset.permissionAsked) return next();
        form.dataset.permissionAsked = '1';
        var done = false;
        var go = function () { if (!done) { done = true; next(); } };
        try { Promise.resolve(Notification.requestPermission()).then(go, go); } catch (e) { go(); }
        setTimeout(go, 12000); // si no responde, la reserva se envía igual
    }

    window.BookingSubmit = {
        submit: function (form) { askPushThen(form, function () { send(form); }); },
    };

    document.querySelectorAll('[data-booking-form]').forEach(function (form) {
        restoreDraft(form);
        var timer;
        form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { saveDraft(form); }, 400); });
        form.addEventListener('change', function () { saveDraft(form); });
    });

    // Si vuelve la conexión y había una reserva sin confirmar, se consulta sola.
    window.addEventListener('online', function () {
        document.querySelectorAll('[data-booking-form][data-pending-verify="1"]').forEach(function (form) {
            form.dataset.pendingVerify = '';
            verify(form, 0);
        });
    });

    // Reserva confirmada (pantalla de éxito): se borran los borradores.
    if (document.getElementById('bookingSuccess')) clearDrafts();
})();
