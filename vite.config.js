import { defineConfig } from 'vite';
import { fileURLToPath, URL } from 'node:url';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Modern World (AdminLTE v3 + Vue 3)
                'resources/js/app.js',          // Main entry: AdminLTE v3 + base setup
                'resources/js/datatables.js',   // DataTables plugin
                'resources/js/historical-maps/app.js',
                // CHGIS 地圖 modal（addresses/offices Place Name 連結）
                'resources/js/chgis-map/app.js',
                // Inertia + React entry（Tailwind 樣式由 app.tsx 內 import inertia.css 載入）
                'resources/js/inertia/app.tsx',
            ],
            refresh: true,
        }),
        // Tailwind v4：只有 resources/css/inertia.css 含 @import 'tailwindcss'，
        // 故樣式僅打包進 inertia bundle，不影響 AdminLTE/Blade 頁面。
        tailwindcss(),
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
            // shadcn/ui 慣例別名，指向 inertia 原始碼根目錄（與 tsconfig paths 對齊）
            '@': fileURLToPath(new URL('./resources/js/inertia', import.meta.url)),
            '@inertia': fileURLToPath(new URL('./resources/js/inertia', import.meta.url)),
        },
    },
});
