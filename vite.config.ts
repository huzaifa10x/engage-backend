import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/js/admin/app.tsx'], refresh: true }),
        react(),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        origin: 'http://localhost:5173', // written to public/hot; the browser loads assets from here
        hmr: { host: 'localhost' },
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
