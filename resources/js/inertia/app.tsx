import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
// Tailwind v4 + 設計 token（僅此 inertia bundle 載入，不影響 Blade/AdminLTE）
import '../../css/inertia.css';
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
