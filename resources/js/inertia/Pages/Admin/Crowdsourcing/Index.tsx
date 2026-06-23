import React, { useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { Modal } from '../../../components/ui/Modal';
import { Pagination } from '../../../components/ui/Pagination';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';

interface DiffRow {
    field: string;
    before?: string | number | null;
    after?: string | number | null;
    current?: string | number | null;
    matches_current?: boolean;
    matches_before?: boolean;
}

interface ResourceDiff {
    type?: string;
    rows?: DiffRow[];
    addresses?: unknown[];
    note?: string | null;
}

interface CrowdRow {
    id: number;
    resource: string;
    resource_id: string | number | null;
    op_type: string | number | null;
    user_name: string | null;
    rate: number | null;
    created_utc: string;
    created_display: string;
    crowdsourcing_status: number | string | null;
    resource_data: Record<string, unknown> | string | null;
    resource_diff: ResourceDiff | string | null;
    has_diff: boolean;
    can_review: boolean;
    confirm_url: string;
    reject_url: string;
}

interface CrowdsourcingPageProps extends SharedProps {
    lists: CrowdRow[];
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
}

/** 以本地時區顯示時間；解析失敗則回退顯示字串。 */
function formatLocal(iso: string, fallback: string): string {
    if (!iso) {
        return fallback;
    }
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? fallback : d.toLocaleString();
}

export default function CrowdsourcingIndex() {
    const props = usePage<CrowdsourcingPageProps>().props;
    const { lists } = props;
    const t = useTranslation('operations');
    const tc = useTranslation('codes');
    const tcom = useTranslation('common');
    const tnav = useTranslation('nav');

    const [dataModal, setDataModal] = useState<CrowdRow | null>(null);
    const [diffModal, setDiffModal] = useState<CrowdRow | null>(null);

    // 客戶端搜尋 / 排序 / 每頁筆數（對齊舊 DataTables；資料上限 100 筆，前端處理足夠）。
    const [search, setSearch] = useState('');
    const [sortKey, setSortKey] = useState<keyof CrowdRow | null>(null);
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');
    const [pageSize, setPageSize] = useState(25);
    const [page, setPage] = useState(1);

    const toggleSort = (key: keyof CrowdRow) => {
        if (sortKey === key) {
            setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSortKey(key);
            setSortDir('asc');
        }
        setPage(1);
    };

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return lists;
        return lists.filter((r) =>
            [r.resource, r.resource_id, r.op_type, r.user_name, r.rate, r.created_display, r.crowdsourcing_status]
                .map((v) => (v == null ? '' : String(v)).toLowerCase())
                .some((s) => s.includes(q)),
        );
    }, [lists, search]);

    const sorted = useMemo(() => {
        if (!sortKey) return filtered;
        const arr = [...filtered];
        const k = sortKey;
        arr.sort((a, b) => {
            const av = a[k];
            const bv = b[k];
            const an = typeof av === 'number' ? av : Number(av);
            const bn = typeof bv === 'number' ? bv : Number(bv);
            const numeric = !Number.isNaN(an) && !Number.isNaN(bn)
                && av !== null && bv !== null && av !== '' && bv !== '';
            const cmp = numeric ? an - bn : String(av ?? '').localeCompare(String(bv ?? ''));
            return sortDir === 'asc' ? cmp : -cmp;
        });
        return arr;
    }, [filtered, sortKey, sortDir]);

    const total = sorted.length;
    const lastPage = Math.max(1, Math.ceil(total / pageSize));
    const safePage = Math.min(page, lastPage);
    const pageRows = sorted.slice((safePage - 1) * pageSize, safePage * pageSize);
    const fromRow = total === 0 ? 0 : (safePage - 1) * pageSize + 1;
    const toRow = Math.min(safePage * pageSize, total);

    return (
        <DashboardLayout
            title={tnav('crowdsourcing_records')}
            breadcrumbs={[{ label: tnav('crowdsourcing_records') }]}
        >
            <div className="rounded-lg border border-border bg-card p-4">
                <p className="mb-3 text-sm text-muted-foreground">
                    {t('crowdsourcing_op_type_desc')}
                    <br />
                    {t('crowdsourcing_status_desc')}
                </p>

                {/* 客戶端搜尋 + 每頁筆數（對齊舊 DataTables）。 */}
                <div className="mb-3 flex flex-wrap items-center gap-3">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                        placeholder={tcom('search')}
                        className="rounded-md border border-input px-3 py-1.5 text-sm"
                    />
                    <select
                        value={pageSize}
                        onChange={(e) => { setPageSize(Number(e.target.value)); setPage(1); }}
                        className="rounded-md border border-input px-2 py-1.5 text-sm"
                        aria-label="per-page"
                    >
                        {[10, 25, 50, 100].map((n) => <option key={n} value={n}>{n}</option>)}
                    </select>
                    <span className="ml-auto text-sm text-muted-foreground">{fromRow}–{toRow} / {total}</span>
                </div>

                <div className="overflow-x-auto rounded-md border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                {(() => {
                                    const sortableTh = (key: keyof CrowdRow, label: string) => (
                                        <th key={String(key)} className="px-3 py-2 text-left font-medium">
                                            <button type="button" className="inline-flex items-center gap-1 hover:underline" onClick={() => toggleSort(key)}>
                                                {label}{sortKey === key ? (sortDir === 'asc' ? ' ▲' : ' ▼') : ''}
                                            </button>
                                        </th>
                                    );
                                    return (
                                        <>
                                            {sortableTh('resource', t('modified_resource'))}
                                            <th className="px-3 py-2 text-left font-medium">{t('modified_value')}</th>
                                            {sortableTh('resource_id', t('resource_tts'))}
                                            {sortableTh('op_type', t('operation_type'))}
                                            {sortableTh('user_name', t('modified_by'))}
                                            {sortableTh('rate', t('count'))}
                                            {sortableTh('created_utc', t('entry_time'))}
                                            {sortableTh('crowdsourcing_status', t('status_label'))}
                                            <th className="px-3 py-2 text-left font-medium">{tc('actions')}</th>
                                        </>
                                    );
                                })()}
                            </tr>
                        </thead>
                        <tbody>
                            {total === 0 && (
                                <tr>
                                    <td colSpan={9} className="px-3 py-6 text-center text-muted-foreground">
                                        —
                                    </td>
                                </tr>
                            )}
                            {pageRows.map((row) => (
                                <tr key={row.id} className="border-t border-border align-top">
                                    <td className="px-3 py-1.5">{row.resource}</td>
                                    <td className="px-3 py-1.5">
                                        <div className="flex flex-wrap gap-1">
                                            <Button size="sm" variant="outline" onClick={() => setDataModal(row)}>
                                                resource_data
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={!row.has_diff}
                                                onClick={() => setDiffModal(row)}
                                            >
                                                compare
                                            </Button>
                                        </div>
                                    </td>
                                    <td className="px-3 py-1.5">{row.resource_id}</td>
                                    <td className="px-3 py-1.5">{row.op_type}</td>
                                    <td className="px-3 py-1.5">{row.user_name}</td>
                                    <td className="px-3 py-1.5">{row.rate}</td>
                                    <td className="px-3 py-1.5" title={row.created_utc}>
                                        {formatLocal(row.created_utc, row.created_display)}
                                    </td>
                                    <td className="px-3 py-1.5">{row.crowdsourcing_status}</td>
                                    <td className="px-3 py-1.5">
                                        {row.can_review && (
                                            <div className="flex flex-wrap gap-1">
                                                <a
                                                    href={row.confirm_url}
                                                    className="inline-flex items-center rounded-md bg-green-600 px-3 py-1 text-xs text-white hover:bg-green-700"
                                                >
                                                    {t('confirm_btn')}
                                                </a>
                                                <a
                                                    href={row.reject_url}
                                                    className="inline-flex items-center rounded-md bg-red-600 px-3 py-1 text-xs text-white hover:bg-red-700"
                                                >
                                                    {t('reject_btn')}
                                                </a>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    className="mt-4"
                    meta={{ current_page: safePage, last_page: lastPage, per_page: pageSize, total, from: fromRow || null, to: toRow || null }}
                    onPageChange={(p) => setPage(p)}
                    labels={{ previous: tcom('previous'), next: tcom('next') }}
                />
            </div>

            <Modal
                open={dataModal !== null}
                onOpenChange={(open) => !open && setDataModal(null)}
                title="resource_data"
                className="max-w-2xl"
                footer={
                    <Button variant="outline" onClick={() => setDataModal(null)}>
                        {tcom('close')}
                    </Button>
                }
            >
                {dataModal && <KeyValueTable data={dataModal.resource_data} />}
            </Modal>

            <Modal
                open={diffModal !== null}
                onOpenChange={(open) => !open && setDiffModal(null)}
                title="compare"
                className="max-w-4xl"
                footer={
                    <Button variant="outline" onClick={() => setDiffModal(null)}>
                        {tcom('close')}
                    </Button>
                }
            >
                {diffModal && <DiffTable diff={diffModal.resource_diff} t={t} />}
            </Modal>
        </DashboardLayout>
    );
}

/** resource_data 鍵值表呈現。 */
function KeyValueTable({ data }: { data: Record<string, unknown> | string | null }) {
    if (data === null || typeof data === 'string') {
        return <pre className="max-h-[60vh] overflow-auto whitespace-pre-wrap break-all text-sm">{data ?? '(null)'}</pre>;
    }
    const entries = Object.entries(data);
    return (
        <div className="max-h-[60vh] overflow-auto">
            <table className="w-full text-sm">
                <tbody>
                    {entries.map(([k, v]) => (
                        <tr key={k} className="border-t border-border align-top">
                            <td className="px-2 py-1 font-mono text-xs">{k}</td>
                            <td className="px-2 py-1 break-all">
                                {v === null ? '(null)' : typeof v === 'object' ? JSON.stringify(v) : String(v)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** compare 差異表（四欄：field/before/after/current）。 */
function DiffTable({ diff, t }: { diff: ResourceDiff | string | null; t: (k: string) => string }) {
    if (!diff) {
        return <p className="text-sm text-muted-foreground">—</p>;
    }
    // 對齊 Blade：resource_diff 為 null 時回退 resource_original（原始字串）。
    if (typeof diff === 'string') {
        return <pre className="max-h-[60vh] overflow-auto whitespace-pre-wrap break-all text-xs">{diff}</pre>;
    }
    if (diff.type === 'POSTED_TO_ADDR_DATA') {
        return (
            <pre className="max-h-[60vh] overflow-auto whitespace-pre-wrap break-all text-xs">
                {JSON.stringify(diff.addresses ?? [], null, 2)}
            </pre>
        );
    }
    const rows = diff.rows ?? [];
    return (
        <div className="max-h-[60vh] overflow-auto">
            {diff.note && <p className="mb-2 text-sm text-muted-foreground">{diff.note}</p>}
            <table className="w-full text-sm">
                <thead className="bg-muted/50">
                    <tr>
                        <th className="px-2 py-1 text-left">{t('diff_field')}</th>
                        <th className="px-2 py-1 text-left">{t('diff_before')}</th>
                        <th className="px-2 py-1 text-left">{t('diff_after')}</th>
                        <th className="px-2 py-1 text-left">{t('diff_current')}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((d, i) => (
                        <tr key={`${d.field}-${i}`} className="border-t border-border align-top">
                            <td className="px-2 py-1 font-mono text-xs">{d.field}</td>
                            <td className={`px-2 py-1 break-all ${d.matches_before ? 'bg-sky-50' : ''}`}>{d.before}</td>
                            <td className={`px-2 py-1 break-all ${d.matches_current ? 'bg-green-50' : ''}`}>{d.after}</td>
                            <td className="px-2 py-1 break-all">
                                {d.current ?? t('not_retrieved')}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
