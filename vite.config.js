import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament-fonts.css',
                'resources/css/landing.css',
                'resources/js/app.js',
                'resources/js/owner-chart.js',
            ],
            refresh: true,
        }),
    ],
});
