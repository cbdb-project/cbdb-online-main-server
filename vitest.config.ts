import { defineConfig } from 'vitest/config';

// 最小化前端單元測試設定：預設純函式（node 環境），不載入 laravel-vite-plugin，避免測試環境需要 PHP／manifest。
// 只測 resources/js 下的 *.test.ts；需要 DOM 的檔案（如 utils/markdown，DOMPurify 依賴 window）
// 於檔首以 `// @vitest-environment jsdom` 個別覆寫，不把整包測試都推上 jsdom。
export default defineConfig({
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.ts'],
    },
});
