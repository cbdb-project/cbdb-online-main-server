import React from 'react';

/**
 * 日誌頁共用的折疊區塊（NL Query Logs / AI Fill Logs）。
 *
 * 兩頁原各自內聯一份 <details>，已出現樣式漂移（一邊多了 mb-2）。此處統一為
 * 無外距版本，垂直間距交由父層 `space-y-3` 控制，確保兩頁一致。
 */
export function LogCollapsible({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <details className="rounded border border-border">
            <summary className="cursor-pointer px-3 py-1.5 text-sm font-medium hover:bg-muted">{label}</summary>
            <div className="border-t border-border p-2">{children}</div>
        </details>
    );
}
