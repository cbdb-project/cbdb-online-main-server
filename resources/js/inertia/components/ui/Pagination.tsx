import React from 'react';
import { Button } from './Button';
import { cn } from '../../lib/utils';

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface PaginationProps {
    meta: PaginationMeta;
    onPageChange: (page: number) => void;
    /** 顯示「第 from–to 筆，共 total 筆」摘要的本地化模板，{from}/{to}/{total} 取代。 */
    summaryTemplate?: string;
    labels?: { previous?: string; next?: string };
    className?: string;
}

/** 計算要顯示的頁碼（目前頁前後各 2，含首末頁與省略號）。 */
function pageWindow(current: number, last: number): (number | '…')[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }
    const pages: (number | '…')[] = [1];
    const start = Math.max(2, current - 2);
    const end = Math.min(last - 1, current + 2);
    if (start > 2) pages.push('…');
    for (let p = start; p <= end; p++) pages.push(p);
    if (end < last - 1) pages.push('…');
    pages.push(last);
    return pages;
}

export function Pagination({ meta, onPageChange, summaryTemplate, labels, className }: PaginationProps) {
    // 單頁時仍顯示摘要，但不渲染頁碼按鈕。
    const showPageButtons = meta.last_page > 1;
    const pages = showPageButtons ? pageWindow(meta.current_page, meta.last_page) : [];
    const summary = (summaryTemplate ?? '{from}–{to} / {total}')
        .replace('{from}', String(meta.from ?? 0))
        .replace('{to}', String(meta.to ?? 0))
        .replace('{total}', String(meta.total));

    return (
        <div className={cn('flex flex-wrap items-center justify-between gap-2', className)}>
            <span className="text-sm text-muted-foreground">{summary}</span>
            <div className="flex items-center gap-1">
                <Button
                    size="sm"
                    variant="outline"
                    disabled={meta.current_page <= 1}
                    onClick={() => onPageChange(meta.current_page - 1)}
                >
                    {labels?.previous ?? 'Previous'}
                </Button>
                {pages.map((p, i) =>
                    p === '…' ? (
                        <span key={`gap-${i}`} className="px-2 text-muted-foreground">
                            …
                        </span>
                    ) : (
                        <Button
                            key={p}
                            size="sm"
                            variant={p === meta.current_page ? 'default' : 'outline'}
                            onClick={() => onPageChange(p)}
                        >
                            {p}
                        </Button>
                    )
                )}
                <Button
                    size="sm"
                    variant="outline"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => onPageChange(meta.current_page + 1)}
                >
                    {labels?.next ?? 'Next'}
                </Button>
            </div>
        </div>
    );
}
