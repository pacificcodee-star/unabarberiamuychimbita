/* Panel instalable (PWA)
   - Registra el service worker del sitio (/sw.js) si aún no lo está.
   - Muestra el botón "Instalar" del menú lateral solo cuando el navegador permite instalar.
   - En iPhone/iPad (Safari no tiene botón de instalar) explica cómo agregarlo a la pantalla de inicio. */
(() => {
    const button = document.querySelector('[data-pwa-install]');
    const standalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    let deferred = null;

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistration('/').then((registration) => {
            if (!registration) navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(() => {});
        }).catch(() => {});
    }

    if (!button || standalone) return;

    const toast = (text) => {
        let box = document.getElementById('pwaToast');
        if (!box) {
            box = document.createElement('div');
            box.id = 'pwaToast';
            box.className = 'pwa-toast';
            box.setAttribute('role', 'status');
            document.body.append(box);
        }
        box.textContent = text;
        box.hidden = false;
        clearTimeout(box._timer);
        box._timer = setTimeout(() => { box.hidden = true; }, 7000);
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferred = event;
        button.hidden = false;
    });

    window.addEventListener('appinstalled', () => {
        deferred = null;
        button.hidden = true;
        toast('Listo: el panel quedó instalado como app.');
    });

    if (isIos) button.hidden = false;

    button.addEventListener('click', async () => {
        if (deferred) {
            deferred.prompt();
            const { outcome } = await deferred.userChoice;
            deferred = null;
            if (outcome === 'accepted') button.hidden = true;
            return;
        }
        if (isIos) toast('En Safari toca Compartir y luego «Agregar a inicio» para instalar el panel.');
    });
})();
