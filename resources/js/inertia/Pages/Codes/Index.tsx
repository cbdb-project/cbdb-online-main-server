import React, { useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Input } from '../../components/ui/Input';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';
import { cn } from '../../lib/utils';

interface CodeTable {
    name: string;
    description: string;
    url: string;
}

interface CodesIndexPageProps extends SharedProps {
    tables: CodeTable[];
}

type SortCol = 'name' | 'description' | null;
type SortDir = 'asc' | 'desc';

export default function CodesIndex() {
    const { tables } = usePage<CodesIndexPageProps>().props;
    const tNav = useTranslation('nav');
    const t = useTranslation('codes');
    const tc = useTranslation('common');

    const [search, setSearch] = useState('');
    const [sortCol, setSortCol] = useState<SortCol>(null);
    const [sortDir, setSortDir] = useState<SortDir>('asc');

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        let rows = tables.filter(
            (r) => !q || r.name.toLowerCase().includes(q) || r.description.toLowerCase().includes(q)
        );
        if (sortCol) {
            rows = [...rows].sort((a, b) => {
                const av = a[sortCol].toLowerCase();
                const bv = b[sortCol].toLowerCase();
                return sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
            });
        }
        return rows;
    }, [tables, search, sortCol, sortDir]);

    // 點欄位：asc → desc → 取消（與舊頁三態一致）。
    const toggleSort = (col: 'name' | 'description') => {
        if (sortCol !== col) {
            setSortCol(col);
            setSortDir('asc');
        } else if (sortDir === 'asc') {
            setSortDir('desc');
        } else {
            setSortCol(null);
            setSortDir('asc');
        }
    };

    const sortIcon = (col: 'name' | 'description') =>
        sortCol !== col ? '⇅' : sortDir === 'asc' ? '▲' : '▼';

    return (
        <DashboardLayout title={tNav('all_tables')} description={tNav('all_tables_desc')}>
            <div className="rounded-lg border border-border bg-card p-4">
                <Input
                    value={search}
                    placeholder={t('search_tables')}
                    onChange={(e) => setSearch(e.target.value)}
                    className="mb-3 max-w-md"
                    autoComplete="off"
                />
                <div className="overflow-x-auto rounded-md border border-border">
                    {/* 字號／內距與 app/view 的 DataTable 對齊（同一套設計語言）：body 1rem、表頭 0.92rem/600、cell px-3 py-2。 */}
                    <table className="w-full text-base">
                        {/* 表頭樣式／配色與 app/view 列表對齊：實色 muted 底、灰字、2px 底線、0.92rem/600（保留排序）。 */}
                        <thead className="bg-muted">
                            <tr>
                                {(['name', 'description'] as const).map((col) => (
                                    <th
                                        key={col}
                                        className="cursor-pointer select-none border-b-2 border-border px-3 py-2.5 text-left text-[0.92rem] font-semibold text-muted-foreground hover:bg-muted"
                                        onClick={() => toggleSort(col)}
                                    >
                                        {col === 'name' ? t('table_name') : t('description')}{' '}
                                        <span aria-hidden className="text-muted-foreground">
                                            {sortIcon(col)}
                                        </span>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((row, i) => (
                                <tr
                                    key={row.name}
                                    className={cn(
                                        'border-t border-border',
                                        // 斑馬紋間隔色：與 app/view 列表用完全相同的 token（偶數列 --card、奇數列 --surface-sunken）。
                                        i % 2 === 1 && 'bg-[var(--surface-sunken)]'
                                    )}
                                >
                                    <td className="px-3 py-2">
                                        <a href={row.url} className="text-primary hover:underline">
                                            {row.name}
                                        </a>
                                    </td>
                                    <td className="px-3 py-2">{row.description}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {visible.length === 0 && <p className="py-2 text-sm text-muted-foreground">{tc('no_data')}</p>}
            </div>
        </DashboardLayout>
    );
}
