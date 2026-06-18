import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
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
    const { lists, pagination } = props;
    const t = useTranslation('operations');
    const tc = useTranslation('codes');
    const tcom = useTranslation('common');
    const tnav = useTranslation('nav');

    const [dataModal, setDataModal] = useState<CrowdRow | null>(null);
    const [diffModal, setDiffModal] = useState<CrowdRow | null>(null);

    const onPageChange = (page: number) => {
        router.get(window.location.pathname, { page }, { preserveScroll: true, preserveState: true });
    };

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

                <div className="overflow-x-auto rounded-md border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="px-3 py-2 text-left font-medium">{t('modified_resource')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('modified_value')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('resource_tts')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('operation_type')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('modified_by')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('count')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('entry_time')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('status_label')}</th>
                                <th className="px-3 py-2 text-left font-medium">{tc('actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lists.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="px-3 py-6 text-center text-muted-foreground">
                                        —
                                    </td>
                                </tr>
                            )}
                            {lists.map((row) => (
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
                    meta={pagination}
                    onPageChange={onPageChange}
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
