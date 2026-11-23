(() => {
    const root = document.querySelector('[data-services]');
    if (!root) return;

    const list = root.querySelector('#service-list');
    const dialog = root.querySelector('dialog');
    const form = dialog.querySelector('form');
    const schedule = form.querySelector('fieldset');
    const date = form.elements.appointment_date;
    const time = form.elements.appointment_time;
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

    async function loadTimes(quiet = false) {
        const request = ++slotRequest;
        if (!quiet) {
            time.replaceChildren();
            submit.disabled = true;
            message.textContent = date.value ? 'Buscando horarios…' : 'Elige una fecha.';
        }
        if (!date.value || !date.validity.valid) return;
        const url = new URL(root.dataset.slots);
        url.searchParams.set('barber_id', trigger.dataset.barber);
        url.searchParams.set('date', date.value);
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw Error();
            const { times } = await response.json();
            if (request !== slotRequest || saving || !dialog.open) return;
            const selected = time.value;
            if (!quiet || JSON.stringify(times) !== JSON.stringify(Array.from(time.options).slice(1).map(o => o.value))) {
                time.replaceChildren(new Option('Elige una hora', ''), ...times.map(value => new Option(value, value)));
                if (times.includes(selected)) time.value = selected;
            }
            submit.disabled = !times.length;
            if (!quiet || !times.length) message.textContent = times.length ? '' : 'No quedan horas disponibles ese día.';
        } catch {
            if (request !== slotRequest || saving || !dialog.open) return;
            time.replaceChildren();
            submit.disabled = true;
            message.textContent = 'No pudimos consultar la agenda. Volveremos a intentarlo.';
        }
    }

    list.addEventListener('click', event => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        trigger = button;
        ++listRequest;
        const move = button.dataset.action === 'reprogramar';
        schedule.hidden = schedule.disabled = !move;
        form.reset();
        date.value = button.dataset.date;
        form.querySelector('h2').textContent = button.textContent;
        form.querySelector('#service-context').textContent = button.dataset.context;
        submit.textContent = move ? 'Guardar nuevo horario' : button.textContent;
        message.textContent = button.dataset.action === 'cancelar' ? '¿Quieres cancelar esta reserva?' : '';
        submit.disabled = false;
        dialog.showModal();
        if (move) loadTimes();
    });

    back.addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
    dialog.addEventListener('close', () => { ++slotRequest; });
    date.addEventListener('change', () => loadTimes());
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
            dialog.close();
            feedback.textContent = result.message;
            feedback.focus();
            await refresh(true);
        } catch (error) {
            message.textContent = error instanceof TypeError || error instanceof SyntaxError
                ? 'Se interrumpió la conexión. Vuelve a Mis servicios para comprobar el estado.' : error.message;
        } finally {
            saving = false;
            submit.disabled = back.disabled = false;
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
