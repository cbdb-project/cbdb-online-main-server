import { useState, useMemo, useEffect } from 'react';

const DEFAULT_PAGE_SIZE = 20;

/**
 * Tab-local 分頁 hook。
 * 管理頁碼、計算當前頁面的 slice、總頁數。
 * 支援「顯示全部」模式，展開所有記錄。
 */
export function useTabPager<T>(items: T[], pageSize: number = DEFAULT_PAGE_SIZE) {
    const [currentPage, setCurrentPage] = useState(1);
    const [showAll, setShowAll] = useState(false);

    const totalPages = Math.max(1, Math.ceil(items.length / pageSize));

    // 確保頁碼在合法範圍（透過 useEffect 避免 render 中 setState）
    useEffect(() => {
        if (currentPage > totalPages) {
            setCurrentPage(totalPages);
        }
    }, [currentPage, totalPages]);

    const safePage = Math.min(Math.max(1, currentPage), totalPages);

    const pageItems = useMemo(() => {
        if (showAll) return items;
        const start = (safePage - 1) * pageSize;
        return items.slice(start, start + pageSize);
    }, [items, safePage, pageSize, showAll]);

    return {
        currentPage: safePage,
        totalPages,
        pageItems,
        setCurrentPage,
        totalItems: items.length,
        showAll,
        setShowAll,
    };
}
