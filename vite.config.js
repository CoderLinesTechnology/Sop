import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // app.js is tiny and loads on every public page; checkout.js
            // (Alpine, CSP build) only loads on the order and order-status pages.
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/checkout.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        cssMinify: 'lightningcss',
        reportCompressedSize: false,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
