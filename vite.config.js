import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    build: {
        // La vista del chat importa la función exportada desde el archivo compilado.
        rollupOptions: { preserveEntrySignatures: 'exports-only' },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/whatsapp-live.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
