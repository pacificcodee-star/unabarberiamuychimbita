(() => {
    const root = document.querySelector('[data-services]');
    if (!root) return;

    const list = root.querySelector('#service-list');
    const dialog = root.querySelector('dialog');
    const form = dialog.querySelector('form');
    const schedule = form.querySelector('fieldset');
    const date = form.elements.appointment_date;
    const times = form.querySelector('#service-times');
    const calendar = form.querySelector('[data-reschedule-calendar]');
    const back = form.querySelector('#service-back');
    const submit = form.querySelector('#service-submit');
    const message = form.querySelector('[role=status]');
    const feedback = root.querySelector('#service-feedback');
    const tabs = Array.from(root.querySelectorAll('[data-service-tab]'));
    let activeTab = 'activos';
    let trigger;
    let saving = false;
    let listRequest = 0;
    let slotRequest = 0;

    function showTab(name) {
        if (!tabs.some(tab => tab.dataset.serviceTab === name)) name = 'activos';
        activeTab = name;
        try { sessionStorage.setItem('ubmc-mis-tab', name); } catch (e) {}
        tabs.forEach(tab => {
            const selected = tab.dataset.serviceTab === name;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
        });
        list.querySelectorAll('[data-service-panel]').forEach(panel => {
            panel.hidden = panel.dataset.servicePanel !== name;
        });
    }

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => showTab(tab.dataset.serviceTab));
        tab.addEventListener('keydown', event => {
            const last = tabs.length - 1;
            const target = event.key === 'ArrowRight' ? (index === last ? 0 : index + 1)
                : event.key === 'ArrowLeft' ? (index === 0 ? last : index - 1)
                : event.key === 'Home' ? 0 : event.key === 'End' ? last : null;
            if (target === null) return;
            event.preventDefault();
            tabs[target].focus();
            showTab(tabs[target].dataset.serviceTab);
        });
    });

    // Al volver o tras una actualización automática se conserva la pestaña que estaba abierta.
    try {
        const savedTab = sessionStorage.getItem('ubmc-mis-tab');
        if (savedTab) showTab(savedTab);
    } catch (e) {}

    async function refresh(force = false) {
        if (document.hidden || (dialog.open && !force)) return;
        const request = ++listRequest;
        try {
            const response = await fetch(location.href, { headers: { 'X-Service-List': '1' }, cache: 'no-store' });
            if (!response.ok) throw Error();
            const html = await response.text();
            if (request !== listRequest || dialog.open) return;
            const content = document.createElement('template');
            content.innerHTML = html;
            if (!content.content.querySelector('.service-panel')) throw Error();
            const focusedUrl = document.activeElement?.dataset.url;
            if (list.innerHTML.trim() !== content.innerHTML.trim()) {
                list.replaceChildren(content.content);
                showTab(activeTab);
                if (focusedUrl) Array.from(list.querySelectorAll('[data-url]')).find(el => el.dataset.url === focusedUrl)?.focus();
            }
        } catch {
            feedback.textContent = 'No pudimos actualizar tus reservas. Volveremos a intentarlo.';
        }
    }

    const to12h = (value) => {
        const [h, m] = value.split(':').map(Number);
        return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h >= 12 ? 'p. m.' : 'a. m.'}`;
    };
    const chosenTime = () => times.querySelector('input:checked')?.value || '';
    const syncSubmit = () => { submit.disabled = saving || !chosenTime(); };

    function slotStatus(icon, text) {
        const box = document.createElement('div');
        box.className = 'slot-status';
        box.innerHTML = `<i class="bi ${icon}" aria-hidden="true"></i><span></span>`;
        box.querySelector('span').textContent = text;
        times.replaceChildren(box);
    }

    // Horas libres del barbero ese día, como franjas (igual que en Reservar).
    async function loadTimes(quiet = false) {
        const request = ++slotRequest;
        if (!date.value) { slotStatus('bi-calendar3', 'Elige un día en el calendario.'); syncSubmit(); return; }
        if (!quiet) {
            times.setAttribute('aria-busy', 'true');
            times.replaceChildren(...Array.from({ length: 6 }, () => Object.assign(document.createElement('div'), { className: 'slot-skeleton' })));
            message.textContent = '';
            syncSubmit();
        }
        const url = new URL(root.dataset.slots);
        url.searchParams.set('barber_id', trigger.dataset.barber);
        url.searchParams.set('date', date.value);
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw Error();
            const { times: free } = await response.json();
            if (request !== slotRequest || saving || !dialog.open) return;
            const shown = Array.from(times.querySelectorAll('input')).map(input => input.value);
            if (quiet && JSON.stringify(shown) === JSON.stringify(free) && shown.length) return;
            const selected = chosenTime();
            if (!free.length) {
                slotStatus('bi-calendar-x', 'No quedan horas libres ese día. Prueba otro día.');
            } else {
                times.replaceChildren(...free.map(value => {
                    const wrap = document.createElement('div');
                    wrap.className = 'slot';
                    const id = `rs-slot-${value.replace(':', '')}`;
                    wrap.innerHTML = `<input type="radio" name="appointment_time" id="${id}" value="${value}" required><label for="${id}"></label>`;
                    wrap.querySelector('label').textContent = to12h(value);
                    if (value === selected) wrap.querySelector('input').checked = true;
                    return wrap;
                }));
            }
        } catch {
            if (request !== slotRequest || saving || !dialog.open) return;
            if (!quiet) slotStatus('bi-wifi-off', 'No pudimos consultar la agenda. Volveremos a intentarlo.');
        } finally {
            if (request === slotRequest) times.removeAttribute('aria-busy');
            syncSubmit();
        }
    }
    times.addEventListener('change', syncSubmit);

    /* Calendario de Reservar: 30 días desde hoy, sin los días cerrados. Al tocar un día se cargan sus horas. */
    const cal = (() => {
        const grid = calendar.querySelector('[data-cal-grid]');
        const title = calendar.querySelector('[data-cal-title]');
        const prev = calendar.querySelector('[data-cal-prev]');
        const next = calendar.querySelector('[data-cal-next]');
        const pad = (n) => String(n).padStart(2, '0');
        const toKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        const fromKey = (value) => new Date(`${value}T00:00:00`);
        const capitalize = (text) => text.charAt(0).toUpperCase() + text.slice(1);
        const closed = new Set(JSON.parse(calendar.dataset.closed || '[]'));
        const minDate = fromKey(date.min);
        const maxDate = fromKey(date.max);
        const todayKey = toKey(new Date());
        const canPick = (d) => d >= minDate && d <= maxDate && !closed.has(toKey(d));
        let view = new Date(minDate.getFullYear(), minDate.getMonth(), 1);

        const render = () => {
            title.textContent = capitalize(view.toLocaleDateString('es-CO', { month: 'long', year: 'numeric' }));
            prev.disabled = view <= new Date(minDate.getFullYear(), minDate.getMonth(), 1);
            next.disabled = view >= new Date(maxDate.getFullYear(), maxDate.getMonth(), 1);
            const offset = (new Date(view.getFullYear(), view.getMonth(), 1).getDay() + 6) % 7;
            const daysInMonth = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
            const cells = [];
            for (let i = 0; i < Math.ceil((offset + daysInMonth) / 7) * 7; i++) {
                const n = i - offset + 1;
                const day = new Date(view.getFullYear(), view.getMonth(), n);
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'cal-day';
                button.innerHTML = `<span>${day.getDate()}</span>`;
                if (n < 1 || n > daysInMonth) {
                    button.classList.add('is-outside');
                    button.disabled = true;
                    button.tabIndex = -1;
                    button.setAttribute('aria-hidden', 'true');
                } else {
                    const key = toKey(day);
                    const label = capitalize(day.toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' }));
                    if (key === todayKey) button.classList.add('is-today');
                    if (!canPick(day)) {
                        button.classList.add('is-off');
                        button.disabled = true;
                        button.setAttribute('aria-label', `${label} (no disponible)`);
                    } else {
                        const selected = key === date.value;
                        button.classList.toggle('is-selected', selected);
                        button.setAttribute('aria-label', label + (key === todayKey ? ' (hoy)' : ''));
                        button.setAttribute('aria-pressed', String(selected));
                        button.insertAdjacentHTML('beforeend', '<i class="cal-dot" aria-hidden="true"></i>');
                        button.addEventListener('click', () => {
                            if (date.value === key) return;
                            date.value = key;
                            render();
                            loadTimes();
                        });
                    }
                }
                cells.push(button);
            }
            grid.replaceChildren(...cells);
        };
        prev.addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); render(); });
        next.addEventListener('click', () => { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); render(); });

        return {
            // Abre en el día actual de la cita (si sigue disponible) o en el primer día que se pueda elegir.
            open(initial) {
                let key = initial && canPick(fromKey(initial)) ? initial : '';
                if (!key) {
                    for (const d = new Date(minDate); d <= maxDate; d.setDate(d.getDate() + 1)) {
                        if (canPick(d)) { key = toKey(d); break; }
                    }
                }
                date.value = key;
                const base = key ? fromKey(key) : minDate;
                view = new Date(base.getFullYear(), base.getMonth(), 1);
                render();
            },
        };
    })();

    async function sendAction(button) {
        if (saving) return;
        saving = true;
        ++listRequest;
        button.disabled = true;
        try {
            const response = await fetch(button.dataset.url, {
                method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
            });
            const result = await response.json();
            if (!response.ok) {
                const detail = Object.values(result.errors || {}).flat()[0];
                throw new Error(detail || ({419: 'La sesión venció. Abre Mis servicios de nuevo.', 429: 'Espera un momento antes de volver a intentarlo.'})[response.status] || 'No se pudo aplicar el cambio. Revisa el estado de la reserva.');
            }
            try { localStorage.setItem('ubmc-service-change', String(Date.now())); } catch {}
            feedback.textContent = result.message;
            feedback.focus();
            await refresh(true);
        } catch (error) {
            feedback.textContent = error instanceof TypeError || error instanceof SyntaxError
                ? 'Se interrumpió la conexión. Vuelve a Mis servicios para comprobar el estado.' : error.message;
            feedback.focus();
        } finally {
            saving = false;
            button.disabled = false;
        }
    }

    list.addEventListener('click', event => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        trigger = button;
        // Confirmar y cancelar se aplican de inmediato; solo reprogramar abre el diálogo.
        if (button.dataset.action !== 'reprogramar') {
            sendAction(button);
            return;
        }
        ++listRequest;
        schedule.hidden = schedule.disabled = false;
        form.reset();
        times.replaceChildren();
        form.querySelector('h2').textContent = 'Reprogramar cita';
        form.querySelector('#service-context').textContent = `Ahora: ${button.dataset.context}`;
        submit.textContent = 'Guardar horario';
        message.textContent = '';
        cal.open(button.dataset.date);
        dialog.showModal();
        loadTimes();
    });

    // Cierre con animación corta (en el celular la hoja baja); sin animación si el sistema pide reducir movimiento.
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const closeDialog = after => {
        if (!dialog.open || dialog.classList.contains('is-closing')) return;
        if (reduceMotion.matches || typeof dialog.getAnimations !== 'function') { dialog.close(); after?.(); return; }
        dialog.classList.add('is-closing');
        let finished = false;
        const done = () => { if (finished) return; finished = true; dialog.classList.remove('is-closing'); dialog.close(); after?.(); };
        Promise.all(dialog.getAnimations().map(animation => animation.finished)).then(done, done);
        setTimeout(done, 260);
    };
    back.addEventListener('click', () => closeDialog());
    dialog.addEventListener('cancel', event => { event.preventDefault(); if (!saving) closeDialog(); });
    dialog.addEventListener('close', () => { ++slotRequest; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving || submit.disabled) return;
        saving = true;
        ++slotRequest;
        const body = new FormData(form);
        submit.disabled = back.disabled = true;
        message.textContent = 'Guardando…';
        try {
            const response = await fetch(trigger.dataset.url, {
                method: 'POST', body, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
            });
            const result = await response.json();
            if (!response.ok) {
                const detail = Object.values(result.errors || {}).flat()[0];
                throw new Error(detail || ({419: 'La sesión venció. Abre Mis servicios de nuevo.', 429: 'Espera un momento antes de volver a intentarlo.'})[response.status] || 'No se pudo guardar el cambio. Revisa el estado de la reserva.');
            }
            try { localStorage.setItem('ubmc-service-change', String(Date.now())); } catch {} 
            feedback.textContent = result.message;
            // El aviso recibe el foco cuando la ventana ya se cerró (mientras está abierta, el resto de la página no lo acepta).
            closeDialog(() => feedback.focus());
            await refresh(true);
        } catch (error) {
            message.textContent = error instanceof TypeError || error instanceof SyntaxError
                ? 'Se interrumpió la conexión. Vuelve a Mis servicios para comprobar el estado.' : error.message;
        } finally {
            saving = false;
            back.disabled = false;
            syncSubmit();
        }
    });

    function sync() {
        if (document.hidden || saving) return;
        if (!dialog.open) refresh();
        else if (!schedule.disabled) loadTimes(true);
    }
    window.addEventListener('storage', event => { if (event.key === 'ubmc-service-change') sync(); });
    navigator.serviceWorker?.addEventListener('message', event => { if (event.data === 'booking-change') sync(); });
    setInterval(sync, 5000);
    document.addEventListener('visibilitychange', sync);
})();
