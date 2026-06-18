import React from 'react';

export interface Crumb {
    label: string;
    url?: string;
}

/**
 * 內容標頭麵包屑。對齊舊 header-v3 的 breadcrumb 行為：最後一項為 active（不可點），
 * 其餘為連結。無資料時不渲染。
 */
export default function Breadcrumbs({ crumbs }: { crumbs?: Crumb[] }) {
    if (!crumbs || crumbs.length === 0) {
        return null;
    }

    return (
        <ol className="flex flex-wrap items-center gap-1 text-sm text-muted-foreground">
            {crumbs.map((crumb, i) => {
                const isLast = i === crumbs.length - 1;
                return (
                    <li key={i} className="flex items-center gap-1">
                        {i > 0 && <span className="opacity-50">/</span>}
                        {isLast || !crumb.url ? (
                            <span className="font-medium text-foreground">{crumb.label}</span>
                        ) : (
                            <a href={crumb.url} className="hover:text-primary">
                                {crumb.label}
                            </a>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
