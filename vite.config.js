import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    build: { commonjsOptions: { include: [/node_modules/, /resources\/js\/vendor\//] } },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: ['resources/views/**/*', 'packages/ui/resources/views/**/*'],
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: Number(process.env.VITE_PORT || 5175),
        strictPort: true,
        hmr: { host: 'localhost' },
        cors: { origin: [/^https?:\/\/((?:[a-z0-9-]+\.)*localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/] },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
