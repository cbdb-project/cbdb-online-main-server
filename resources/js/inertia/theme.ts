/*
 * 品牌色改以語意 CSS 變數（resources/css/inertia.css）表達，使所有 APP_THEME 取用點
 * 自動隨深色模式（.dark）翻轉。淺色值與原硬編碼一致（零退化）：
 *   --primary #4E88C7、--destructive #A51C30、--card #fff、--foreground≈#212529、
 *   --brand-surface-strong #95B5DF、--brand-border #93A1AD、--primary-foreground #fff。
 * 深色模式各變數於 .dark 區塊加深/提亮。
 */
export const APP_THEME = {
    canvas: 'var(--card)',
    brand: 'var(--primary)',
    brandStrong: 'var(--foreground)',
    brandAccent: 'var(--destructive)',
    brandSurface: 'var(--card)',
    brandSurfaceStrong: 'var(--brand-surface-strong)',
    brandBorder: 'var(--brand-border)',
    brandText: 'var(--primary)',
    brandTextStrong: 'var(--foreground)',
    brandOnDark: 'var(--primary-foreground)',
} as const;
