import { useState, useMemo, useEffect } from 'react';

const DEFAULT_PAGE_SIZE = 20;

/**
 * Tab-local 分頁 hook。
 * 管理頁碼、計算當前頁面的 slice、總頁數。
 */
export function useTabPager<T>(items: T[], pageSize: number = DEFAULT_PAGE_SIZE) {
    const [currentPage, setCurrentPage] = useState(1);

    const totalPages = Math.max(1, Math.ceil(items.length / pageSize));

    // 確保頁碼在合法範圍（透過 useEffect 避免 render 中 setState）
    useEffect(() => {
        if (currentPage > totalPages) {
            setCurrentPage(totalPages);
        }
    }, [currentPage, totalPages]);

    const safePage = Math.min(Math.max(1, currentPage), totalPages);

    const pageItems = useMemo(() => {
        const start = (safePage - 1) * pageSize;
        return items.slice(start, start + pageSize);
    }, [items, safePage, pageSize]);

    return {
        currentPage: safePage,
        totalPages,
        pageItems,
        setCurrentPage,
        totalItems: items.length,
    };
}
