import React, { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import type { FlashMessage, SharedProps } from '../../types/page';
import { cn } from '../../lib/utils';

/**
 * 統一渲染 share() 的 flash 訊息（取代 Blade flash::message partial）。
 * level 對映 AdminLTE/Bootstrap 語意色；可手動關閉。每次 flash prop 變動即重置。
 */
const LEVEL_CLASS: Record<string, string> = {
    success: 'bg-green-50 border-green-400 text-green-800',
    info: 'bg-blue-50 border-blue-400 text-blue-800',
    warning: 'bg-yellow-50 border-yellow-400 text-yellow-800',
    danger: 'bg-red-50 border-red-400 text-red-800',
    error: 'bg-red-50 border-red-400 text-red-800',
};

export default function FlashMessages() {
    const { flash } = usePage<SharedProps>().props;
    const [dismissed, setDismissed] = useState<Set<number>>(new Set());

    // flash 內容變動（新導覽）時清空已關閉集合。
    useEffect(() => {
        setDismissed(new Set());
    }, [flash]);

    const messages: FlashMessage[] = Array.isArray(flash) ? flash : [];
    const visible = messages
        .map((m, i) => ({ m, i }))
        .filter(({ i }) => !dismissed.has(i));

    if (visible.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2" role="status" aria-live="polite">
            {visible.map(({ m, i }) => (
                <div
                    key={i}
                    className={cn(
                        'flex items-start justify-between gap-3 rounded border px-4 py-3 text-sm',
                        LEVEL_CLASS[m.level] ?? LEVEL_CLASS.info
                    )}
                >
                    <div>
                        {m.title ? <strong className="mr-1">{m.title}</strong> : null}
                        {/* 訊息可能含 HTML（laracasts/flash 允許），與 Blade 行為一致。 */}
                        <span dangerouslySetInnerHTML={{ __html: m.message }} />
                    </div>
                    <button
                        type="button"
                        aria-label="Dismiss"
                        className="shrink-0 text-lg leading-none opacity-60 hover:opacity-100"
                        onClick={() => setDismissed((prev) => new Set(prev).add(i))}
                    >
                        &times;
                    </button>
                </div>
            ))}
        </div>
    );
}
