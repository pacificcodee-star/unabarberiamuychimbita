/* Timbre corto para avisos que llegan con la página abierta.
   Con la página cerrada o en segundo plano suena la notificación del sistema (sw.js) con el tono del
   celular o del computador. Este timbre cubre el otro caso: la página está a la vista y el aviso llega
   por la campana. Si el dispositivo ya tiene los avisos push activados, el sistema ya hace sonar la
   notificación y aquí no se repite.
   Los navegadores solo dejan sonar audio después de que la persona toca la página una vez. */
(() => {
    if (window.ubmcChime) return;

    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    let ctx = null;

    const unlock = () => {
        if (!AudioCtx) return;
        try {
            ctx = ctx || new AudioCtx();
            if (ctx.state === 'suspended') ctx.resume().catch(() => {});
        } catch (e) {}
    };
    ['pointerdown', 'keydown', 'touchstart'].forEach((type) => window.addEventListener(type, unlock, { passive: true }));

    const tone = (frequency, start, length) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.35, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + length);
        osc.connect(gain).connect(ctx.destination);
        osc.start(start);
        osc.stop(start + length + 0.05);
    };

    const pushHandlesSound = () => 'Notification' in window && Notification.permission === 'granted';

    /** Suena solo con la página a la vista y cuando el push no va a sonar por su cuenta. */
    window.ubmcChime = ({ force = false } = {}) => {
        if (document.hidden || !ctx || ctx.state !== 'running') return false;
        if (!force && pushHandlesSound()) return false;
        try {
            const now = ctx.currentTime + 0.01;
            tone(880, now, 0.22);
            tone(1318.5, now + 0.16, 0.38);
        } catch (e) {
            return false;
        }
        if (navigator.vibrate) navigator.vibrate([200, 80, 200]);
        return true;
    };
})();
