/*
 * Video con transparencia compatible con todos los navegadores.
 * El MP4 trae el color arriba y la máscara (blanco = visible) abajo; aquí se combinan con WebGL.
 * Uso: <div data-alpha-video data-src="video.alpha.mp4" data-poster="foto.png"> … </div>
 * Control: el.dispatchEvent(new Event('alpha:play')) / new Event('alpha:pause').
 */
(() => {
    const VERT = 'attribute vec2 p;varying vec2 uv;void main(){uv=vec2(p.x*.5+.5,.5-p.y*.5);gl_Position=vec4(p,0.,1.);}';
    // La máscara se "encoge" ~1,5 px (se toma el valor mínimo de los píxeles vecinos) y se le sube el umbral:
    // así desaparece el borde blanquecino que quedaba alrededor de la persona al quitar el fondo.
    const FRAG = 'precision mediump float;varying vec2 uv;uniform sampler2D t;uniform vec2 px;'
        + 'float m(vec2 o){vec2 q=vec2(uv.x,uv.y*.5+.5)+o;q.y=clamp(q.y,.5+px.y,1.-px.y);return texture2D(t,q).r;}'
        + 'void main(){vec3 c=texture2D(t,vec2(uv.x,uv.y*.5)).rgb;'
        + 'float a=min(m(vec2(0.)),min(min(m(vec2(px.x,0.)),m(vec2(-px.x,0.))),min(m(vec2(0.,px.y)),m(vec2(0.,-px.y)))));'
        + 'a=min(a,min(min(m(px),m(-px)),min(m(vec2(px.x,-px.y)),m(vec2(-px.x,px.y)))));'
        + 'a=smoothstep(.42,.97,a);gl_FragColor=vec4(c*a,a);}';

    // Chrome, Edge y Firefox reproducen WebM con transparencia de forma nativa (lo decodifica la tarjeta de video,
    // casi sin costo). Safari y los iPhone no: allí se usa el MP4 "color + máscara" combinado con WebGL.
    // Medido: con WebGL la página bajaba a 7–13 fps mientras el video corría; con el WebM nativo se queda en 60.
    const ua = navigator.userAgent;
    const isSafari = /^((?!chrome|chromium|crios|fxios|edg|android).)*safari/i.test(ua) || /iphone|ipad|ipod/i.test(ua)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const nativeAlpha = !isSafari && document.createElement('video').canPlayType('video/webm; codecs="vp9"') !== '';

    const setupNative = (root) => {
        const video = document.createElement('video');
        video.className = 'alpha-video-canvas alpha-video-native';
        video.setAttribute('aria-hidden', 'true');
        video.muted = true;
        video.loop = true;
        video.playsInline = true;
        video.setAttribute('playsinline', '');
        video.disablePictureInPicture = true;
        video.preload = root.dataset.preload || 'metadata';
        video.src = root.dataset.webm;
        root.appendChild(video);
        let wantsPlay = false;
        const ready = () => root.classList.add('is-ready');
        video.addEventListener('loadeddata', ready);
        video.addEventListener('error', () => root.classList.add('is-fallback'));
        const start = () => { wantsPlay = true; video.preload = 'auto'; video.play().then(ready).catch(() => {}); };
        const stop = () => { wantsPlay = false; video.pause(); };
        root.addEventListener('alpha:play', start);
        root.addEventListener('alpha:pause', stop);
        root.alphaVideo = { play: start, pause: stop, video, get playing() { return wantsPlay; } };
        if (root.dataset.autoplay === '1') start();
    };

    const setup = (root) => {
        if (root.dataset.ready) return;
        root.dataset.ready = '1';
        if (nativeAlpha && root.dataset.webm) { setupNative(root); return; }
        const canvas = document.createElement('canvas');
        canvas.className = 'alpha-video-canvas';
        canvas.setAttribute('aria-hidden', 'true');
        root.appendChild(canvas);
        const gl = canvas.getContext('webgl', { premultipliedAlpha: true, alpha: true, antialias: false });
        if (!gl) { root.classList.add('is-fallback'); return; }

        const compile = (type, src) => { const s = gl.createShader(type); gl.shaderSource(s, src); gl.compileShader(s); return s; };
        const prog = gl.createProgram();
        gl.attachShader(prog, compile(gl.VERTEX_SHADER, VERT));
        gl.attachShader(prog, compile(gl.FRAGMENT_SHADER, FRAG));
        gl.linkProgram(prog);
        if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) { root.classList.add('is-fallback'); return; }
        gl.useProgram(prog);
        const pxLoc = gl.getUniformLocation(prog, 'px');
        const buf = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, buf);
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
        const loc = gl.getAttribLocation(prog, 'p');
        gl.enableVertexAttribArray(loc);
        gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
        const tex = gl.createTexture();
        gl.bindTexture(gl.TEXTURE_2D, tex);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
        gl.pixelStorei(gl.UNPACK_PREMULTIPLY_ALPHA_WEBGL, false);

        const video = document.createElement('video');
        video.muted = true;
        video.loop = true;
        video.playsInline = true;
        video.setAttribute('playsinline', '');
        video.preload = root.dataset.preload || 'metadata';
        video.crossOrigin = 'anonymous';
        video.src = root.dataset.src;

        let wantsPlay = false;
        let drawing = false;
        const draw = () => {
            if (video.readyState < 2 || !video.videoWidth) return;
            const w = video.videoWidth;
            const h = video.videoHeight / 2;
            if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; gl.viewport(0, 0, w, h); gl.uniform2f(pxLoc, 1.5 / w, 1.5 / video.videoHeight); }
            gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGB, gl.RGB, gl.UNSIGNED_BYTE, video);
            gl.clearColor(0, 0, 0, 0);
            gl.clear(gl.COLOR_BUFFER_BIT);
            gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
            root.classList.add('is-ready');
        };
        const loop = () => {
            if (!drawing) return;
            draw();
            if ('requestVideoFrameCallback' in video) video.requestVideoFrameCallback(loop);
            else requestAnimationFrame(loop);
        };
        const start = () => {
            wantsPlay = true;
            video.preload = 'auto';
            video.play().then(() => { if (!drawing) { drawing = true; loop(); } }).catch(() => { draw(); });
        };
        const stop = () => { wantsPlay = false; drawing = false; video.pause(); };
        video.addEventListener('loadeddata', draw);
        video.addEventListener('seeked', draw); // al mover el video con el scroll (sin reproducir) se pinta el fotograma nuevo
        video.addEventListener('error', () => root.classList.add('is-fallback'));
        root.addEventListener('alpha:play', start);
        root.addEventListener('alpha:pause', stop);
        root.alphaVideo = { play: start, pause: stop, video, get playing() { return wantsPlay; } };
        if (root.dataset.autoplay === '1') start();
    };

    const init = () => document.querySelectorAll('[data-alpha-video]').forEach(setup);
    window.AlphaVideo = { init };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
