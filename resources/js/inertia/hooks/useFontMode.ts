import { useCallback, useEffect, useState } from 'react';

type FontMode = 'sans' | 'serif';

const STORAGE_KEY = 'fontMode';
const SERIF_CLASS = 'font-serif-mode';

function readInitial(): FontMode {
    if (typeof window === 'undefined') {
        return 'sans';
    }
    return window.localStorage.getItem(STORAGE_KEY) === 'serif' ? 'serif' : 'sans';
}

function applyToDocument(mode: FontMode): void {
    if (typeof document === 'undefined') {
        return;
    }
    document.documentElement.classList.toggle(SERIF_CLASS, mode === 'serif');
}

export function useFontMode(): { fontMode: FontMode; toggle: () => void } {
    const [fontMode, setFontMode] = useState<FontMode>(readInitial);

    useEffect(() => {
        applyToDocument(fontMode);
    }, [fontMode]);

    const toggle = useCallback(() => {
        setFontMode((prev) => {
            const next: FontMode = prev === 'serif' ? 'sans' : 'serif';
            try {
                window.localStorage.setItem(STORAGE_KEY, next);
            } catch {
                // localStorage 不可用時靜默忽略（仍套用於本次 session）。
            }
            return next;
        });
    }, []);

    return { fontMode, toggle };
}
