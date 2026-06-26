import { useCallback } from 'react';
import { router } from '@inertiajs/react';
import type { OnChangeFn, SortingState } from '@tanstack/react-table';

/** query 參數值型別（Inertia FormDataConvertible 的常用子集）。 */
type QueryValue = string | number | boolean | null | undefined;

interface UseDataTableQueryOptions {
    /** 目前的 query 參數（由頁面 props 帶入，通常即後端 filters）。 */
    params: Record<string, QueryValue>;
    /** Inertia partial reload 只刷新這些 props（如 ['logs']），避免整頁重載。 */
    only?: string[];
    /** 目標 URL，預設為目前頁面（保留現有路徑）。 */
    url?: string;
    /** 目前排序狀態（供 onSortingChange 計算下一步）。 */
    sorting?: SortingState;
}

/**
 * 把 DataTable 的換頁/排序/篩選轉成 Inertia partial reload（preserveState + only），
 * 並把狀態同步進 URL query（可分享連結，對齊舊 withQueryString 語意）。
 */
export function useDataTableQuery({ params, only, url, sorting }: UseDataTableQueryOptions) {
    const visit = useCallback(
        (next: Record<string, QueryValue>) => {
            // 合併現有參數與變更；移除 null/空字串以保持 URL 乾淨。
            const merged: Record<string, QueryValue> = { ...params, ...next };
            Object.keys(merged).forEach((k) => {
                if (merged[k] === null || merged[k] === undefined || merged[k] === '') {
                    delete merged[k];
                }
            });
            router.get(url ?? window.location.pathname, merged, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                ...(only ? { only } : {}),
            });
        },
        [params, only, url]
    );

    const onPageChange = useCallback((page: number) => visit({ page }), [visit]);

    const onFilterChange = useCallback(
        (filters: Record<string, QueryValue>) => visit({ ...filters, page: 1 }),
        [visit]
    );

    const onSortingChange: OnChangeFn<SortingState> = useCallback(
        (updater) => {
            const nextSorting = typeof updater === 'function' ? updater(sorting ?? []) : updater;
            const first = nextSorting[0];
            visit({
                sort: first ? first.id : null,
                direction: first ? (first.desc ? 'desc' : 'asc') : null,
                page: 1,
            });
        },
        [visit, sorting]
    );

    return { visit, onPageChange, onFilterChange, onSortingChange };
}
