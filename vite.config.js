import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Modern World (AdminLTE v3 + Vue 3)
                'resources/js/app.js',          // Main entry: AdminLTE v3 + base setup
                'resources/js/datatables.js',   // DataTables plugin
                // Inertia + React entry (PoC)
                'resources/js/inertia/app.tsx',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        react(),
    ],
    resolve: {
        alias: {
            'vue': 'vue/dist/vue.esm-bundler.js',
        },
    },
});
