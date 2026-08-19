import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

const browserHost = process.env.VITE_BROWSER_HOST || 'localhost';
const appOrigin = process.env.VITE_APP_ORIGIN || 'http://localhost:8000';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/adminlte.css', 'resources/js/adminlte.js'],
            refresh: true,
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: `http://${browserHost}:5173`,
        cors: {
            origin: appOrigin,
        },
        hmr: {
            host: browserHost,
            clientPort: 5173,
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
