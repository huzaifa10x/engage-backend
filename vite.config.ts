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
        port: 5173,
        strictPort: true,
        // 127.0.0.1, not "localhost": macOS resolves localhost to [::1] first, and any other
        // process listening there (a native `npm run dev`, another project) silently wins.
        origin: 'http://127.0.0.1:5173', // written to public/hot; the browser loads assets from here
        hmr: { host: '127.0.0.1', port: 5173 },
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});