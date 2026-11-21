/*
 * Encuesta conversacional: una pregunta por pantalla, transición suave, guardado inmediato.
 * - Cada respuesta se envía al servidor; si falla la red queda pendiente (sessionStorage) y se
 *   reintenta sola al volver la conexión o al recargar. Nunca se pierde lo ya confirmado.
 * - Teclado: 1–5 en estrellas, flechas en opciones, Enter para continuar, Esc para volver.
 * - Con "reducir movimiento" no hay desplazamientos, solo un cambio de opacidad.
 */
(function () {
    'use strict';
    var root = document.querySelector('[data-survey]');
    if (!root || !window.safeFetch) return;

    var stage = root.querySelector('[data-sv-stage]');
    var statusEl = root.querySelector('[data-sv-status]');
    var progress = root.querySelector('[data-sv-progress]');
    var state = JSON.parse(root.querySelector('[data-sv-initial]').textContent);
    var storageKey = root.dataset.storageKey;
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var current = null;
    var busy = false;
    var csrf = function () { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; };
    var storage = (function () { try { sessionStorage.setItem('__t', '1'); sessionStorage.removeItem('__t'); return sessionStorage; } catch (e) { return null; } })();

    var STAR_WORDS = ['', 'Malo', 'Regular', 'Bien', 'Muy bien', 'Excelente'];

    function setStatus(text) { statusEl.textContent = text || ''; }

    function updateProgress() {
        var pct = state.total ? Math.round(state.answered / state.total * 100) : 0;
        progress.setAttribute('aria-valuenow', String(state.answered));
        progress.setAttribute('aria-valuemax', String(state.total));
        progress.firstElementChild.style.setProperty('--p', pct + '%');
    }

    function indexOf(key) {
        for (var i = 0; i < state.questions.length; i++) if (state.questions[i].key === key) return i;
        return -1;
    }

    // --- Transiciones --------------------------------------------------------------------------
    function swap(build, direction) {
        var old = stage.querySelector('[data-sv-card]');
        var card = document.createElement('div');
        card.className = 'sv-card';
        card.setAttribute('data-sv-card', '');
        build(card);
        card.classList.add(direction === 'back' ? 'from-left' : 'from-right');
        if (old) {
            old.classList.remove('is-in');
            old.classList.add(direction === 'back' ? 'to-right' : 'to-left');
            setTimeout(function () { old.remove(); }, reduced ? 0 : 320);
        }
        stage.appendChild(card);
        requestAnimationFrame(function () { requestAnimationFrame(function () { card.classList.add('is-in'); }); });
        var focusTarget = card.querySelector('[data-autofocus]') || card.querySelector('h1, h2');
        setTimeout(function () { if (focusTarget) focusTarget.focus({ preventScroll: true }); }, reduced ? 0 : 200);
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function title(text) {
        var h = el('h1', 'sv-title');
        h.id = 'sv-title';
        h.tabIndex = -1;
        // Aparición palabra por palabra (solo decorativa: el texto completo está en el DOM desde el inicio).
        text.split(' ').forEach(function (word, i) {
            var span = el('span', 'sv-word', word + ' ');
            span.style.setProperty('--i', i);
            h.appendChild(span);
        });
        return h;
    }

    // --- Pantallas -----------------------------------------------------------------------------
    function showQuestion(key, direction) {
        var i = indexOf(key);
        if (i < 0) return showEnd();
        var q = state.questions[i];
        current = key;
        swap(function (card) {
            card.appendChild(el('p', 'sv-step', 'Pregunta ' + (i + 1) + ' de ' + state.total));
            card.appendChild(title(q.text));
            if (q.help) card.appendChild(el('p', 'sv-help', q.help));
            var selected = q.answer ? q.answer.value : null;
            if (q.type === 'stars') card.appendChild(stars(q, selected));
            else if (q.type === 'text') card.appendChild(textInput(q, selected));
            else card.appendChild(choices(q, selected));
            card.appendChild(navigation(q, i));
        }, direction);
        updateProgress();
    }

    function stars(q, selected) {
        var group = el('div', 'sv-stars');
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-labelledby', 'sv-title');
        var hint = el('p', 'sv-star-word', selected ? STAR_WORDS[+selected] : ' ');
        for (var n = 1; n <= 5; n++) {
            (function (value) {
                var b = el('button', 'sv-star' + (selected && value <= +selected ? ' is-on' : ''));
                b.type = 'button';
                b.setAttribute('role', 'radio');
                b.setAttribute('aria-checked', String(selected == value));
                b.setAttribute('aria-label', value + ' de 5: ' + STAR_WORDS[value]);
                b.tabIndex = (selected ? selected == value : value === 1) ? 0 : -1;
                if (b.tabIndex === 0) b.setAttribute('data-autofocus', '');
                b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.4l-5.8 3.1 1.1-6.5L2.6 9.4l6.5-.9z"/></svg>';
                b.addEventListener('mouseenter', function () { paint(group, value); hint.textContent = STAR_WORDS[value]; });
                b.addEventListener('focus', function () { paint(group, value); hint.textContent = STAR_WORDS[value]; });
                b.addEventListener('click', function () { choose(q, String(value)); });
                group.appendChild(b);
            })(n);
        }
        group.addEventListener('mouseleave', function () { paint(group, selected ? +selected : 0); hint.textContent = selected ? STAR_WORDS[+selected] : ' '; });
        group.addEventListener('keydown', function (e) {
            var buttons = group.querySelectorAll('button');
            var idx = Array.prototype.indexOf.call(buttons, document.activeElement);
            if (/^[1-5]$/.test(e.key)) { e.preventDefault(); choose(q, e.key); return; }
            if (e.key === 'ArrowRight' || e.key === 'ArrowUp') { e.preventDefault(); buttons[Math.min(4, idx + 1)].focus(); }
            if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') { e.preventDefault(); buttons[Math.max(0, idx - 1)].focus(); }
        });
        var wrap = el('div', 'sv-field');
        wrap.appendChild(group);
        wrap.appendChild(hint);
        return wrap;
    }

    function paint(group, upto) {
        group.querySelectorAll('.sv-star').forEach(function (b, i) { b.classList.toggle('is-on', i < upto); });
    }

    function choices(q, selected) {
        var group = el('div', 'sv-options');
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-labelledby', 'sv-title');
        q.options.forEach(function (opt, i) {
            var b = el('button', 'sv-option' + (selected === opt.value ? ' is-selected' : ''));
            b.type = 'button';
            b.setAttribute('role', 'radio');
            b.setAttribute('aria-checked', String(selected === opt.value));
            b.tabIndex = (selected ? selected === opt.value : i === 0) ? 0 : -1;
            if (b.tabIndex === 0) b.setAttribute('data-autofocus', '');
            b.appendChild(el('span', 'sv-option-key', String(i + 1)));
            b.appendChild(el('span', 'sv-option-label', opt.label));
            b.addEventListener('click', function () { choose(q, opt.value); });
            group.appendChild(b);
        });
        group.addEventListener('keydown', function (e) {
            var buttons = group.querySelectorAll('button');
            var idx = Array.prototype.indexOf.call(buttons, document.activeElement);
            var n = parseInt(e.key, 10);
            if (n >= 1 && n <= buttons.length) { e.preventDefault(); buttons[n - 1].click(); return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); buttons[(idx + 1) % buttons.length].focus(); }
            if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { e.preventDefault(); buttons[(idx - 1 + buttons.length) % buttons.length].focus(); }
        });
        return group;
    }

    function textInput(q, selected) {
        var wrap = el('div', 'sv-field');
        var area = el('textarea', 'sv-textarea');
        area.rows = 4;
        area.maxLength = 600;
        area.value = selected || '';
        area.setAttribute('aria-labelledby', 'sv-title');
        area.setAttribute('data-autofocus', '');
        area.placeholder = 'Escribe aquí…';
        var counter = el('p', 'sv-counter', area.value.length + ' / 600');
        area.addEventListener('input', function () { counter.textContent = area.value.length + ' / 600'; });
        area.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); choose(q, area.value); }
        });
        var actions = el('div', 'sv-actions');
        var go = el('button', 'xbtn');
        go.type = 'button';
        go.innerHTML = '<span>Continuar</span><i class="bi bi-arrow-right" aria-hidden="true"></i>';
        go.addEventListener('click', function () {
            if (!area.value.trim() && !q.optional) { setStatus('Escribe una respuesta para continuar.'); area.focus(); return; }
            choose(q, area.value.trim() ? area.value : null);
        });
        actions.appendChild(go);
        wrap.appendChild(area);
        wrap.appendChild(counter);
        wrap.appendChild(actions);
        return wrap;
    }

    function navigation(q, i) {
        var nav = el('div', 'sv-nav');
        if (i > 0) {
            var back = el('button', 'sv-link');
            back.type = 'button';
            back.innerHTML = '<i class="bi bi-arrow-left" aria-hidden="true"></i> Atrás';
            back.addEventListener('click', function () { showQuestion(state.questions[i - 1].key, 'back'); });
            nav.appendChild(back);
        } else {
            nav.appendChild(el('span'));
        }
        if (q.optional && q.type !== 'text') {
            var skip = el('button', 'sv-link');
            skip.type = 'button';
            skip.textContent = 'Omitir';
            skip.addEventListener('click', function () { choose(q, null); });
            nav.appendChild(skip);
        } else if (q.optional) {
            var skipText = el('button', 'sv-link');
            skipText.type = 'button';
            skipText.textContent = 'Prefiero no responder';
            skipText.addEventListener('click', function () { choose(q, null); });
            nav.appendChild(skipText);
        }
        return nav;
    }

    function showEnd() {
        current = null;
        if (storage) try { storage.removeItem(storageKey); } catch (e) {}
        swap(function (card) {
            card.classList.add('sv-end');
            card.appendChild(el('span', 'sv-end-icon', '✓'));
            card.appendChild(title('¡Gracias por tu tiempo!'));
            var msg = 'Tus respuestas quedaron guardadas y nos ayudan a mejorar.';
            if (state.published === true) msg += ' Tu opinión ya aparece en nuestra página.';
            else if (state.review_status === 'revision' || state.review_status === 'pendiente') msg += ' Revisaremos tu comentario antes de publicarlo.';
            card.appendChild(el('p', 'sv-help', msg));
            var actions = el('div', 'sv-actions');
            var book = el('a', 'xbtn');
            book.href = '/reservar';
            book.innerHTML = '<span>Reservar de nuevo</span><i class="bi bi-arrow-right" aria-hidden="true"></i>';
            var home = el('a', 'xlink', 'Ir al inicio');
            home.href = '/';
            actions.appendChild(book);
            actions.appendChild(home);
            card.appendChild(actions);
        }, 'forward');
        state.answered = state.total;
        updateProgress();
    }

    // --- Guardado ------------------------------------------------------------------------------
    function savePending(item) { if (storage) try { storage.setItem(storageKey, JSON.stringify(item)); } catch (e) {} }
    function readPending() { try { return storage ? JSON.parse(storage.getItem(storageKey) || 'null') : null; } catch (e) { return null; } }

    function choose(q, value) {
        if (busy) return;
        var from = indexOf(q.key);
        q.answer = { value: value };
        send({ question: q.key, value: value }, from);
    }

    function send(item, fromIndex) {
        busy = true;
        savePending(item);
        setStatus('Guardando…');
        root.setAttribute('aria-busy', 'true');
        window.safeFetch(root.dataset.answerUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(item),
        }, 15000).then(function (r) { return r.json(); }).then(function (next) {
            if (storage) try { storage.removeItem(storageKey); } catch (e) {}
            state = next;
            setStatus('');
            if (state.status === 'completada') return showEnd();
            // Sigue la siguiente pregunta en orden; si ya estaba respondida, la primera que falte.
            var after = state.questions[indexOf(item.question) + 1];
            showQuestion(after && !after.answer ? after.key : (state.next || (after && after.key)), 'forward');
        }).catch(function (error) {
            if (error.kind === 'validation') {
                if (storage) try { storage.removeItem(storageKey); } catch (e) {}
                setStatus((error.errors && (error.errors.value || error.errors.question || [])[0]) || 'Revisa tu respuesta.');
                return;
            }
            if (error.kind === 'session') {
                return fetch('/sesion/token', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
                    var m = document.querySelector('meta[name="csrf-token"]'); if (m) m.content = d.token;
                    busy = false; send(item, fromIndex);
                });
            }
            setStatus(error.kind === 'ratelimit'
                ? 'Vas muy rápido. Espera un momento y vuelve a intentarlo.'
                : 'No pudimos guardar esta respuesta (sin conexión). La guardamos aquí y la enviaremos cuando vuelva la conexión.');
            showRetry(item, fromIndex);
        }).finally(function () { busy = false; root.removeAttribute('aria-busy'); });
    }

    function showRetry(item, fromIndex) {
        var card = stage.querySelector('[data-sv-card]');
        if (!card || card.querySelector('[data-sv-retry]')) return;
        var retry = el('button', 'sv-link sv-retry');
        retry.type = 'button';
        retry.setAttribute('data-sv-retry', '');
        retry.innerHTML = '<i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Reintentar';
        retry.addEventListener('click', function () { retry.remove(); send(item, fromIndex); });
        card.appendChild(retry);
    }

    window.addEventListener('online', function () {
        var pending = readPending();
        if (pending && !busy) send(pending, indexOf(pending.question));
    });

    // --- Inicio ----------------------------------------------------------------------------------
    var start = root.querySelector('[data-sv-start]');
    var pendingAtLoad = readPending();
    if (state.status === 'completada') return;
    if (pendingAtLoad) {
        // Había una respuesta sin confirmar (se cortó la conexión): se envía primero.
        send(pendingAtLoad, indexOf(pendingAtLoad.question));
    } else if (state.answered > 0 && state.next) {
        // Ya había empezado: se retoma donde iba, sin la pantalla de bienvenida.
        showQuestion(state.next, 'forward');
    }
    if (start) start.addEventListener('click', function () { showQuestion(state.next || state.questions[0].key, 'forward'); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && current) {
            var i = indexOf(current);
            if (i > 0) showQuestion(state.questions[i - 1].key, 'back');
        }
    });
})();
