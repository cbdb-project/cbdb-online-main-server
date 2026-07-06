import { defineConfig } from 'vitest/config';

// 最小化前端單元測試設定：純函式（node 環境），不載入 laravel-vite-plugin，避免測試環境需要 PHP／manifest。
// 目前僅測 resources/js 下的 *.test.ts（如 utils/pinyinUmlaut）；不含需要 DOM 的元件測試。
export default defineConfig({
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.ts'],
    },
});
