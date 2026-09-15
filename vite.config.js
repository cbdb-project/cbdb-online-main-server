import { defineConfig } from 'vite';
import { fileURLToPath, URL } from 'node:url';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // ── 2026-09-15（Blade 下架環節 5b）─────────────────────────────
            // 這裡原本還有兩個入口：`resources/js/app.js`（AdminLTE v3 + jQuery + Vue 掛載，
            // 1,283 行）與 `resources/js/datatables.js`。兩者的唯一消費端是 AdminLTE layout，
            // 已於環節 5a 實體刪除 ⇒ 入口與檔案一併移除。
            // **不要把 jquery-global.js 找回來**：它不是 entry，是被那兩支 import 的模組。
            input: [
                'resources/js/historical-maps/app.js',
                // CHGIS 地圖 modal（addresses/offices Place Name 連結）
                'resources/js/chgis-map/app.js',
                // Inertia + React entry（Tailwind 樣式由 app.tsx 內 import inertia.css 載入）
                'resources/js/inertia/app.tsx',
            ],
            refresh: true,
        }),
        // Tailwind v4：只有 resources/css/inertia.css 含 @import 'tailwindcss'，
        // 故樣式僅打包進 inertia bundle（另外兩個入口是地圖，不吃 Tailwind）。
        tailwindcss(),
        // ── 2026-09-15（環節 5b）：`vue()` plugin 已移除——全站唯一的 .vue 檔
        // （`resources/js/components/Select.vue`，只被 app.js 使用）隨本環節刪除。
        react(),
    ],
    resolve: {
        alias: {
            // 'vue' 別名已於環節 5b 移除（無 .vue 檔、無 Vue 執行期）。
            // shadcn/ui 慣例別名，指向 inertia 原始碼根目錄（與 tsconfig paths 對齊）
            '@': fileURLToPath(new URL('./resources/js/inertia', import.meta.url)),
            '@inertia': fileURLToPath(new URL('./resources/js/inertia', import.meta.url)),
        },
    },
});
