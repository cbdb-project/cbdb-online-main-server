import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
// Tailwind v4 + 設計 token（僅此 inertia bundle 載入，不影響 Blade/AdminLTE）
import '../../css/inertia.css';
// 字體與主站對齊：Noto Sans TC 自托管（@fontsource，與 app.js 一致），修正 zh-TW 偏細；
// Source Sans Pro 由 inertia.blade.php 的 Google Font link 載入（與 dashboard-v3 同法）。
// 先前 /app 只在 inertia.css 宣告字體名卻未載入字體，導致中文掉回系統 CJK（偏細）。
import '@fontsource/noto-sans-tc/300.css';
import '@fontsource/noto-sans-tc/400.css';
import '@fontsource/noto-sans-tc/700.css';
import { installNumberInputWheelGuard } from '../utils/disableNumberInputWheel';

installNumberInputWheelGuard();

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
        return pages[`./Pages/${name}.tsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
