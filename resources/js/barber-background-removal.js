const input = document.getElementById('barberPhotoInput');
const button = document.getElementById('removeBarberPhotoBackground');
const status = document.getElementById('barberPhotoStatus');

if (input && button && status) {
    let segmenterPromise;

    input.addEventListener('change', () => {
        if (input.files?.[0]?.size > 5 * 1024 * 1024) {
            input.value = '';
            button.disabled = true;
            status.textContent = 'La foto original debe pesar máximo 5 MB.';
            return;
        }
        button.disabled = !input.files?.[0];
        status.textContent = input.files?.[0]
            ? 'Imagen lista. Puedes quitarle el fondo antes de guardar.'
            : 'Sube una foto vertical de cuerpo completo (máximo 5 MB).';
    });

    button.addEventListener('click', async () => {
        const file = input.files?.[0];
        if (!file || button.disabled) return;

        button.disabled = true;
        input.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Procesando…';
        status.textContent = 'La primera vez se descarga el modelo de IA. La foto se procesa en este dispositivo.';

        try {
            const { pipeline, RawImage } = await import('@huggingface/transformers');
            segmenterPromise ??= pipeline('background-removal', 'Xenova/modnet', { dtype: 'fp32' });
            const segmenter = await segmenterPromise;
            const source = await RawImage.read(file);
            const results = await segmenter(source);
            const cutout = Array.isArray(results) ? results[0] : results;
            if (!cutout) throw new Error('No se pudo detectar la figura en la foto.');
            const rgba = cutout.channels === 4 ? cutout : cutout.rgba();
            const canvas = document.createElement('canvas');
            canvas.width = rgba.width;
            canvas.height = rgba.height;
            canvas.getContext('2d').putImageData(new ImageData(rgba.data, rgba.width, rgba.height), 0, 0);

            const png = await new Promise((resolve, reject) => canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('No se pudo crear el PNG.')), 'image/png'));
            const cleanFile = new File([png], `${file.name.replace(/\.[^.]+$/, '')}-sin-fondo.png`, { type: 'image/png' });
            const transfer = new DataTransfer();
            transfer.items.add(cleanFile);
            input.files = transfer.files;
            status.textContent = 'Fondo eliminado. Se guardará la versión PNG transparente.';
        } catch (error) {
            console.error('Error al quitar el fondo:', error);
            status.textContent = 'No se pudo quitar el fondo. Revisa tu conexión e inténtalo de nuevo.';
        } finally {
            input.disabled = false;
            button.innerHTML = '<i class="bi bi-magic me-1"></i> Quitar fondo automáticamente';
            button.disabled = !input.files?.[0];
        }
    });
}
