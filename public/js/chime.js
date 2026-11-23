/* Timbre corto para avisos que llegan con la página abierta.
   Suena en cualquier navegador (Brave incluido) sin instalar la app ni activar los avisos push.
   Si el navegador aún no deja sonar audio, el timbre queda pendiente y suena en cuanto la persona
   toca la página, que es la única condición que ponen los navegadores. */
(() => {
    if (window.ubmcChime) return;

    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    let ctx = null;
    let pendingAt = 0; // momento del aviso que quedó esperando para sonar

    const unlock = () => {
        if (!AudioCtx) return;
        try {
            ctx = ctx || new AudioCtx();
            if (ctx.state === 'suspended') {
                ctx.resume().then(replay).catch(() => {});
            } else {
                replay();
            }
        } catch (e) {}
    };
    ['pointerdown', 'keydown', 'touchstart', 'click'].forEach((type) => window.addEventListener(type, unlock, { passive: true }));

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

    const play = () => {
        if (document.hidden || !ctx || ctx.state !== 'running') return false;
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

    const replay = () => {
        if (!pendingAt) return;
        if (Date.now() - pendingAt > 60000) { pendingAt = 0; return; }
        if (play()) pendingAt = 0;
    };

    /** Suena siempre que la página esté a la vista; si el audio aún está bloqueado, queda pendiente. */
    window.ubmcChime = () => {
        if (document.hidden) return false;
        if (play()) return true;
        pendingAt = Date.now();
        return false;
    };

    document.addEventListener('visibilitychange', () => { if (!document.hidden) unlock(); });
})();
