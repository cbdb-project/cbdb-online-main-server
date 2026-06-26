import React, { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { Input } from '../../../components/ui/Input';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';

interface TableInfo {
    table_key: string;
    name: string;
    name_chn: string;
    description: string;
    command: string;
    icon: string;
    color: string;
    exists: boolean;
    count: number;
}
interface MaintenancePageProps extends SharedProps {
    tables: TableInfo[];
    urls: { rebuild: string; progress_base: string };
}

function xsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

const COLOR_BG: Record<string, string> = { blue: 'bg-blue-500', green: 'bg-green-500', orange: 'bg-orange-500' };

export default function CbdbTableMaintenanceIndex() {
    const props = usePage<MaintenancePageProps>().props;
    const t = useTranslation('admin');

    return (
        <DashboardLayout title={t('table_maint_page_title')} breadcrumbs={[{ label: t('table_maint_page_title') }]}>
            <div className="space-y-5">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {props.tables.map((tbl) => (
                        <TableCard key={tbl.table_key} table={tbl} urls={props.urls} t={t} />
                    ))}
                </div>
                <InfoPanel t={t} />
            </div>
        </DashboardLayout>
    );
}

function TableCard({ table, urls, t }: { table: TableInfo; urls: { rebuild: string; progress_base: string }; t: (k: string, r?: Record<string, string>) => string }) {
    const isNameFts = table.table_key === 'CBDB__NAME_FTS';
    const [truncate, setTruncate] = useState(!isNameFts); // 繁簡映射表預設勾選，姓名索引預設不勾
    const [idFrom, setIdFrom] = useState('');
    const [idTo, setIdTo] = useState('');
    const [busy, setBusy] = useState(false);
    const [progress, setProgress] = useState<{ progress: number; message: string; status: string } | null>(null);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const stopPoll = () => {
        if (pollRef.current) {
            clearInterval(pollRef.current);
            pollRef.current = null;
        }
    };

    const poll = async (taskId: string) => {
        try {
            const res = await fetch(urls.progress_base + taskId, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            const json = await res.json();
            if (!json.success) {
                stopPoll();
                setBusy(false);
                window.alert(t('table_maint_js_progress_error') + (json.message ?? t('table_maint_js_check_logs')));
                setProgress(null);
                return;
            }
            const pr = json.progress ?? {};
            setProgress({ progress: pr.progress ?? 0, message: pr.message ?? '', status: pr.status ?? 'running' });
            if (pr.status === 'completed') {
                stopPoll();
                window.setTimeout(() => {
                    window.alert(t('table_maint_js_success') + (pr.message ?? t('table_maint_js_index_done')));
                    router.reload();
                }, 500);
            } else if (pr.status === 'error') {
                stopPoll();
                setBusy(false);
                window.alert(t('table_maint_js_failed') + (pr.message ?? t('table_maint_js_check_logs')));
                setProgress(null);
            }
        } catch {
            stopPoll();
            setBusy(false);
            window.alert(t('table_maint_js_progress_error') + t('table_maint_js_progress_fail'));
            setProgress(null);
        }
    };

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!window.confirm(t('table_maint_js_confirm', { name: table.name_chn }))) return;
        setBusy(true);
        setProgress(null);
        const body: Record<string, unknown> = { table_name: table.table_key };
        if (truncate) body.truncate = '1';
        if (isNameFts) {
            if (idFrom) body.id_from = idFrom;
            if (idTo) body.id_to = idTo;
        }
        try {
            const res = await fetch(urls.rebuild, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            let json: Record<string, unknown> = {};
            try { json = await res.json(); } catch { json = {}; }
            if (res.ok && json.success && json.task_id) {
                const id = String(json.task_id);
                setProgress({ progress: 5, message: String(json.message ?? t('table_maint_js_scheduled')), status: 'running' });
                poll(id);
                pollRef.current = setInterval(() => poll(id), 5000);
            } else if (res.ok && json.success) {
                window.alert(t('table_maint_js_success') + String(json.message ?? ''));
                router.reload();
            } else {
                const msg = (json.message as string)
                    ?? (res.status === 0 ? t('table_maint_js_network') : t('table_maint_js_server_error', { status: String(res.status) }));
                window.alert(t('table_maint_js_error_prefix') + msg);
                setBusy(false);
            }
        } catch {
            window.alert(t('table_maint_js_error_prefix') + t('table_maint_js_network'));
            setBusy(false);
        }
    };

    const pct = progress ? Math.max(0, Math.min(100, Math.round(progress.progress))) : 0;

    return (
        <div className="rounded-lg border border-border bg-card">
            <div className={`rounded-t-lg px-4 py-2 font-semibold text-white ${COLOR_BG[table.color] ?? 'bg-slate-500'}`}>
                <i className={`fa fa-${table.icon} mr-1`} aria-hidden /> {table.name_chn}
            </div>
            <div className="space-y-2 p-4 text-sm">
                <p><strong>{t('table_maint_db_table')}</strong><code className="rounded bg-muted px-1">{table.name}</code></p>
                <p><strong>{t('table_maint_description')}</strong>{table.description}</p>
                <p><strong>{t('table_maint_artisan_cmd')}</strong><code className="rounded bg-muted px-1">{table.command}</code></p>
                {table.exists ? (
                    <p><strong>{t('table_maint_record_count')}</strong>
                        <span className={`ml-1 rounded px-2 py-0.5 text-xs text-white ${COLOR_BG[table.color] ?? 'bg-slate-500'}`}>{table.count.toLocaleString()} {t('table_maint_records_unit')}</span>
                    </p>
                ) : (
                    <p><strong>{t('table_maint_status')}</strong>
                        <span className="ml-1 rounded bg-amber-500 px-2 py-0.5 text-xs text-white">{t('table_maint_table_missing')}</span>
                    </p>
                )}

                <hr className="border-border" />

                <form onSubmit={submit} className="space-y-3">
                    {!isNameFts && (
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={truncate} onChange={(e) => setTruncate(e.target.checked)} />
                            {t('table_maint_truncate_rebuild')}
                        </label>
                    )}
                    {isNameFts && (
                        <>
                            <div>
                                <label className="flex items-center gap-2">
                                    <input type="checkbox" checked={truncate} onChange={(e) => setTruncate(e.target.checked)} />
                                    {t('table_maint_truncate_rebuild')}
                                </label>
                                <span className="mt-1 block text-xs text-muted-foreground">{t('table_maint_incremental_hint')}</span>
                            </div>
                            <div>
                                <label className="mb-1 block font-medium">{t('table_maint_id_range')}</label>
                                <div className="grid grid-cols-2 gap-2">
                                    <Input type="number" min={1} placeholder={t('table_maint_id_from')} value={idFrom} onChange={(e) => setIdFrom(e.target.value)} />
                                    <Input type="number" min={1} placeholder={t('table_maint_id_to')} value={idTo} onChange={(e) => setIdTo(e.target.value)} />
                                </div>
                                <span className="mt-1 block text-xs text-muted-foreground">{t('table_maint_id_blank_hint')}</span>
                            </div>
                            {progress && (
                                <div>
                                    <div className="h-5 w-full overflow-hidden rounded bg-muted">
                                        <div className="flex h-full items-center justify-center bg-green-500 text-xs text-white transition-all" style={{ width: `${pct}%` }}>{pct}%</div>
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">{progress.message || t('table_maint_progress_placeholder')}</p>
                                </div>
                            )}
                        </>
                    )}
                    <div>
                        <Button type="submit" disabled={busy}>
                            {busy ? <><i className="fa fa-spinner fa-spin mr-1" aria-hidden />{t('table_maint_js_processing')}</> : <><i className="fa fa-refresh mr-1" aria-hidden />{t('table_maint_rebuild_btn')}</>}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function InfoPanel({ t }: { t: (k: string) => string }) {
    const html = (k: string) => ({ __html: t(k) });
    return (
        <div className="rounded-lg border border-blue-200 bg-blue-50/40 p-4 text-sm">
            <h3 className="mb-2 font-semibold"><i className="fa fa-info-circle mr-1" aria-hidden />{t('table_maint_info_title')}</h3>
            <h4 className="mt-2 font-semibold">{t('table_maint_trad_simp_h4')}</h4>
            <ul className="list-disc space-y-1 pl-5">
                <li>{t('table_maint_trad_simp_li1')}</li>
                <li>{t('table_maint_trad_simp_li2')}</li>
                <li>{t('table_maint_trad_simp_li3')}</li>
                <li>{t('table_maint_trad_simp_li4')}</li>
            </ul>
            <h4 className="mt-3 font-semibold">{t('table_maint_name_fts_h4')}</h4>
            <ul className="list-disc space-y-1 pl-5">
                <li>{t('table_maint_name_fts_li1')}</li>
                <li>{t('table_maint_name_fts_li2')}</li>
                <li>{t('table_maint_name_fts_li3')}</li>
                <li dangerouslySetInnerHTML={html('table_maint_name_fts_li4')} />
                <li dangerouslySetInnerHTML={html('table_maint_name_fts_li5')} />
                <li dangerouslySetInnerHTML={html('table_maint_name_fts_li6')} />
                <li>{t('table_maint_name_fts_li7')}</li>
            </ul>
            <p className="mt-2 text-red-600" dangerouslySetInnerHTML={html('table_maint_danger_note')} />
        </div>
    );
}
