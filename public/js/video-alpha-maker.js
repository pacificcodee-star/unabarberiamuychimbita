/*
 * Quita el fondo de un video en el navegador (panel de Barberos).
 * Cada cuadro pasa por un modelo de IA (MODNet, el mismo de las fotos) y se arma un MP4 "apilado":
 * arriba el color y abajo la máscara (blanco = visible). La página pública (alpha-video.js) los combina
 * con WebGL para mostrar al barbero sin fondo en todos los navegadores.
 * No necesita compilar nada: se carga como módulo y descarga las librerías desde jsDelivr.
 */
const input = document.getElementById('barberVideoInput');
const button = document.getElementById('removeVideoBackground');
const status = document.getElementById('barberVideoStatus');
const alphaInput = document.getElementById('barberVideoAlphaInput');
const bar = document.getElementById('barberVideoProgress');

const MAX_SECONDS = 12;
const FPS = 20;
const MAX_HEIGHT = 720;

if (input && button && status && alphaInput) {
    let segmenterPromise;

    const reset = (text) => {
        button.disabled = !input.files?.[0];
        button.innerHTML = '<i class="bi bi-magic me-1"></i> Quitar fondo del video';
        if (text) status.textContent = text;
        input.disabled = false;
    };

    input.addEventListener('change', () => {
        alphaInput.value = '';
        if (bar) bar.hidden = true;
        reset(input.files?.[0] ? 'Video listo. Pulsa «Quitar fondo del video» antes de guardar para que el barbero se vea sin fondo.' : '');
    });

    const loadVideo = (file) => new Promise((resolve, reject) => {
        const video = document.createElement('video');
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        video.src = URL.createObjectURL(file);
        video.onloadeddata = () => resolve(video);
        video.onerror = () => reject(new Error('No se pudo leer el video.'));
    });

    const seek = (video, time) => new Promise((resolve) => {
        const done = () => { video.removeEventListener('seeked', done); resolve(); };
        video.addEventListener('seeked', done);
        video.currentTime = time;
    });

    button.addEventListener('click', async () => {
        const file = input.files?.[0];
        if (!file || button.disabled) return;
        if (!('VideoEncoder' in window)) {
            status.textContent = 'Este navegador no puede procesar video. Usa Google Chrome o Microsoft Edge actualizados.';
            return;
        }

        button.disabled = true;
        input.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Quitando fondo…';
        if (bar) { bar.hidden = false; bar.value = 0; }
        status.textContent = 'Cargando el modelo de IA (la primera vez tarda un poco)…';

        try {
            const [{ pipeline, RawImage, env }, { Muxer, ArrayBufferTarget }] = await Promise.all([
                import('https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.7.5'),
                import('https://cdn.jsdelivr.net/npm/mp4-muxer@5.2.1/+esm'),
            ]);
            env.allowLocalModels = false;
            const device = 'gpu' in navigator ? 'webgpu' : 'wasm';
            segmenterPromise ??= pipeline('background-removal', 'Xenova/modnet', { device, dtype: 'fp32' })
                .catch(() => pipeline('background-removal', 'Xenova/modnet', { dtype: 'fp32' }));
            const segmenter = await segmenterPromise;

            const video = await loadVideo(file);
            const duration = Math.min(video.duration || 0, MAX_SECONDS);
            if (!duration) throw new Error('El video no tiene duración.');
            const scale = Math.min(1, MAX_HEIGHT / video.videoHeight);
            const w = Math.round((video.videoWidth * scale) / 2) * 2;
            const h = Math.round((video.videoHeight * scale) / 2) * 2;

            const frame = Object.assign(document.createElement('canvas'), { width: w, height: h });
            const frameCtx = frame.getContext('2d', { willReadFrequently: true });
            const stacked = Object.assign(document.createElement('canvas'), { width: w, height: h * 2 });
            const stackedCtx = stacked.getContext('2d');
            const maskData = stackedCtx.createImageData(w, h);

            let config = { codec: 'avc1.640028', width: w, height: h * 2, bitrate: 3_000_000, framerate: FPS };
            if (!(await VideoEncoder.isConfigSupported(config)).supported) config = { ...config, codec: 'avc1.42E028' };
            if (!(await VideoEncoder.isConfigSupported(config)).supported) throw new Error('Este equipo no puede crear MP4. Prueba en Chrome o Edge.');

            const muxer = new Muxer({ target: new ArrayBufferTarget(), video: { codec: 'avc', width: w, height: h * 2 }, fastStart: 'in-memory' });
            let encodeError;
            const encoder = new VideoEncoder({ output: (chunk, meta) => muxer.addVideoChunk(chunk, meta), error: (e) => { encodeError = e; } });
            encoder.configure(config);

            const total = Math.max(1, Math.floor(duration * FPS));
            for (let i = 0; i < total; i++) {
                await seek(video, i / FPS);
                frameCtx.drawImage(video, 0, 0, w, h);
                const result = await segmenter(RawImage.fromCanvas(frame));
                const cut = (Array.isArray(result) ? result[0] : result);
                const rgba = cut.channels === 4 ? cut : cut.rgba();
                const cutW = rgba.width;
                const cutH = rgba.height;
                // Máscara: alfa del recorte → gris (escalada si el modelo devolvió otro tamaño).
                for (let y = 0; y < h; y++) {
                    const sy = Math.min(cutH - 1, Math.floor((y * cutH) / h));
                    for (let x = 0; x < w; x++) {
                        const sx = Math.min(cutW - 1, Math.floor((x * cutW) / w));
                        const a = rgba.data[(sy * cutW + sx) * 4 + 3];
                        const o = (y * w + x) * 4;
                        maskData.data[o] = maskData.data[o + 1] = maskData.data[o + 2] = a;
                        maskData.data[o + 3] = 255;
                    }
                }
                stackedCtx.drawImage(frame, 0, 0);
                stackedCtx.putImageData(maskData, 0, h);

                const vf = new VideoFrame(stacked, { timestamp: Math.round((i * 1_000_000) / FPS), duration: Math.round(1_000_000 / FPS) });
                encoder.encode(vf, { keyFrame: i % (FPS * 2) === 0 });
                vf.close();
                if (encodeError) throw encodeError;

                const pct = Math.round(((i + 1) / total) * 100);
                if (bar) bar.value = pct;
                status.textContent = `Quitando el fondo… ${pct}% (cuadro ${i + 1} de ${total}). No cierres esta pestaña.`;
            }
            await encoder.flush();
            muxer.finalize();
            URL.revokeObjectURL(video.src);

            const blob = new Blob([muxer.target.buffer], { type: 'video/mp4' });
            const transfer = new DataTransfer();
            transfer.items.add(new File([blob], `${file.name.replace(/\.[^.]+$/, '')}.alpha.mp4`, { type: 'video/mp4' }));
            alphaInput.files = transfer.files;
            reset(`¡Listo! Fondo eliminado (${(blob.size / 1024 / 1024).toFixed(1)} MB${video.duration > MAX_SECONDS ? `, primeros ${MAX_SECONDS} s` : ''}). Pulsa «Guardar cambios».`);
            button.disabled = true;
        } catch (error) {
            console.error('Error al quitar el fondo del video:', error);
            reset('No se pudo quitar el fondo: ' + (error?.message || 'revisa tu conexión e inténtalo de nuevo.'));
            if (bar) bar.hidden = true;
        }
    });
}
