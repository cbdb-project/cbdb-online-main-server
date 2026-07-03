import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
// Tailwind v4 + 設計 token（僅此 inertia bundle 載入，不影響 Blade/AdminLTE）
import '../../css/inertia.css';
// Source Sans Pro / Noto Sans TC 由 inertia.blade.php 的 Google Fonts link 載入（與 dashboard-v3 同法）；
// 不再由 @fontsource 進入 Vite bundle，避免每次 build 產生大量 hashed Noto webfont。
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
