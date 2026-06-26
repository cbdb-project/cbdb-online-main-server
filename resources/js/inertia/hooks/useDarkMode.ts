import { useCallback, useEffect, useState } from 'react';

/**
 * 深色模式 hook。沿用既有 localStorage['darkMode'] 鍵（值 'true'/'false'），
 * 與 Blade dashboard-v3 的深色模式狀態相容；在 <html> 掛上 .dark class，
 * 對應 Tailwind 的 @custom-variant dark (&:is(.dark *))。
 */
const STORAGE_KEY = 'darkMode';

function readInitial(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }
    return window.localStorage.getItem(STORAGE_KEY) === 'true';
}

function applyToDocument(enabled: boolean): void {
    if (typeof document === 'undefined') {
        return;
    }
    document.documentElement.classList.toggle('dark', enabled);
}

export function useDarkMode(): { isDark: boolean; toggle: () => void } {
    const [isDark, setIsDark] = useState<boolean>(readInitial);

    useEffect(() => {
        applyToDocument(isDark);
    }, [isDark]);

    const toggle = useCallback(() => {
        setIsDark((prev) => {
            const next = !prev;
            try {
                window.localStorage.setItem(STORAGE_KEY, next ? 'true' : 'false');
            } catch {
                // localStorage 不可用時靜默忽略（仍套用於本次 session）。
            }
            return next;
        });
    }, []);

    return { isDark, toggle };
}
