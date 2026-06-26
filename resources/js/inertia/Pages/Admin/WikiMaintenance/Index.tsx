import React, { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { Input } from '../../../components/ui/Input';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';

interface WikiRecord {
    c_personid: number;
    c_name_chn: string | null;
    c_textid: number;
    c_pages: string | null;
    link: string | null;
}
interface SourceInfo { id: number; name: string; count: number }
interface ProgressData { progress: number; message: string; status: string }

interface WikiPageProps extends SharedProps {
    records: WikiRecord[];
    current_source_id: number;
    sources: SourceInfo[];
    pagination: { page: number; per_page: number; total: number; has_next: boolean; has_prev: boolean };
    urls: { index: string; import: string; delete_all: string; progress_base: string; cancel_base: string };
}

function xsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export default function WikiMaintenanceIndex() {
    const props = usePage<WikiPageProps>().props;
    const { records, current_source_id, sources, pagination, urls } = props;
    const t = useTranslation('admin');

    const currentSource = sources.find((s) => s.id === current_source_id);

    const [importUrl, setImportUrl] = useState('');
    const [importing, setImporting] = useState(false);
    const [progress, setProgress] = useState<ProgressData | null>(null);
    const [taskId, setTaskId] = useState<string | null>(null);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const stopPoll = () => {
        if (pollRef.current) {
            clearInterval(pollRef.current);
            pollRef.current = null;
        }
    };

    const reset = () => {
        setImporting(false);
        setProgress(null);
        setTaskId(null);
        stopPoll();
    };

    const poll = async (id: string) => {
        try {
            const res = await fetch(urls.progress_base + id, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const json = await res.json();
            if (json.success && json.progress) {
                const pr: ProgressData = json.progress;
                setProgress(pr);
                if (pr.status === 'completed' || pr.status === 'error' || pr.status === 'cancelled') {
                    stopPoll();
                    setImporting(false);
                    window.setTimeout(() => {
                        if (pr.status === 'completed') {
                            window.alert(t('wiki_js_import_success') + pr.message);
                            router.reload();
                        } else if (pr.status === 'cancelled') {
                            window.alert(t('wiki_js_import_cancelled') + pr.message);
                            reset();
                        } else {
                            window.alert(t('wiki_js_import_error') + pr.message);
                            reset();
                        }
                    }, 1000);
                }
            }
        } catch {
            /* 查詢進度失敗時繼續嘗試（與舊頁一致） */
        }
    };

    const submitImport = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!window.confirm(t('wiki_import_confirm'))) return;
        setImporting(true);
        setProgress(null);
        try {
            const res = await fetch(urls.import, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ import_url: importUrl, target_source: current_source_id }),
            });
            let json: Record<string, unknown> = {};
            try { json = await res.json(); } catch { json = {}; }
            if (res.ok && json.success) {
                const id = String(json.task_id);
                setTaskId(id);
                setProgress({ progress: 0, message: t('wiki_progress_ready'), status: 'running' });
                poll(id);
                pollRef.current = setInterval(() => poll(id), 2000);
            } else {
                const msg = (json.message as string)
                    ?? (res.status === 0 ? t('wiki_js_network_fail') : t('wiki_js_server_error', { status: String(res.status) }));
                window.alert(t('wiki_js_error_prefix') + msg);
                reset();
            }
        } catch {
            window.alert(t('wiki_js_error_prefix') + t('wiki_js_network_fail'));
            reset();
        }
    };

    const cancelImport = async () => {
        if (!taskId) return;
        if (!window.confirm(t('wiki_import_confirm'))) return;
        try {
            await fetch(urls.cancel_base + taskId, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            // 取消成功後由進度輪詢自動更新狀態
        } catch {
            window.alert(t('wiki_js_cancel_failed'));
        }
    };

    const deleteAll = () => {
        if (!currentSource) return;
        const msg = t('wiki_delete_all_confirm', { source: currentSource.name, count: currentSource.count.toLocaleString() });
        if (!window.confirm(msg)) return;
        router.post(urls.delete_all, { source_id: current_source_id }, { preserveScroll: true });
    };

    const goSource = (id: number) => router.get(urls.index, { source_id: id }, { preserveScroll: true });
    const goPage = (page: number) => router.get(urls.index, { source_id: current_source_id, page }, { preserveScroll: true });

    const pct = progress ? Math.round(progress.progress) : 0;
    const barColor = progress?.status === 'error' ? 'bg-red-500' : progress?.status === 'cancelled' ? 'bg-amber-500' : progress?.status === 'completed' ? 'bg-green-500' : 'bg-sky-500';

    const { page, per_page, total, has_next, has_prev } = pagination;
    const lastPage = Math.max(1, Math.ceil(total / per_page));
    const startPage = Math.max(1, page - 2);
    const endPage = Math.min(lastPage, page + 2);
    const pages: number[] = [];
    for (let i = startPage; i <= endPage; i++) pages.push(i);

    return (
        <DashboardLayout title={t('wiki_page_title')} breadcrumbs={[{ label: t('wiki_page_title') }]}>
            <div className="space-y-5">
                {/* 統計來源選擇 */}
                <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    {sources.map((s) => (
                        <button
                            key={s.id}
                            type="button"
                            onClick={() => goSource(s.id)}
                            className={`flex items-center gap-3 rounded-lg border p-3 text-left transition hover:shadow ${s.id === current_source_id ? 'border-sky-500 ring-2 ring-sky-200' : 'border-border'}`}
                        >
                            <span className={`flex h-12 w-12 items-center justify-center rounded text-white ${s.id === 60795 ? 'bg-blue-500' : s.id === 68942 ? 'bg-green-500' : 'bg-orange-500'}`}>
                                <i className={s.id === 68942 ? 'fas fa-globe' : 'fab fa-wikipedia-w'} aria-hidden />
                            </span>
                            <span>
                                <span className="block text-sm text-muted-foreground">{s.name}</span>
                                <span className="block text-lg font-semibold">{s.count.toLocaleString()} {t('wiki_records_unit')}</span>
                            </span>
                        </button>
                    ))}
                </div>

                {/* URL 導入 */}
                <div className="rounded-lg border border-border bg-card">
                    <div className="rounded-t-lg bg-sky-600 px-4 py-2 font-semibold text-white">
                        <i className="fa fa-download mr-1" aria-hidden /> {t('wiki_import_title')}
                    </div>
                    <div className="space-y-4 p-4">
                        <form onSubmit={submitImport} className="space-y-4">
                            <div>
                                <label className="mb-1 block text-sm font-medium">{t('wiki_import_url_label')}</label>
                                <Input value={importUrl} onChange={(e) => setImportUrl(e.target.value)} required
                                    placeholder="https://cbdb-dev.linshuang.net/wikidata_20251105.json.gz" />
                                <span className="mt-1 block text-xs text-muted-foreground">{t('wiki_import_url_hint')}</span>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium">{t('wiki_target_source_label')}</label>
                                <p className="font-semibold">{currentSource?.name}</p>
                                <span className="mt-1 block text-xs text-muted-foreground">{t('wiki_target_source_hint')}</span>
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Button type="submit" disabled={importing}>
                                    {importing ? <><i className="fa fa-spinner fa-spin mr-1" aria-hidden />{t('wiki_js_preparing')}</> : <><i className="fa fa-download mr-1" aria-hidden />{t('wiki_import_btn')}</>}
                                </Button>
                                <Button type="button" variant="destructive" onClick={deleteAll}>
                                    <i className="fa fa-trash mr-1" aria-hidden />{t('wiki_delete_all_btn', { source: currentSource?.name ?? '' })}
                                </Button>
                            </div>
                            <p className="text-xs text-muted-foreground" dangerouslySetInnerHTML={{ __html: t('wiki_import_note') }} />
                        </form>

                        {progress && (
                            <div className="rounded-lg border border-border p-4">
                                <h4 className="mb-2 font-semibold">{t('wiki_progress_title')}</h4>
                                <div className="h-5 w-full overflow-hidden rounded bg-muted">
                                    <div className={`flex h-full items-center justify-center text-xs text-white transition-all ${barColor}`} style={{ width: `${pct}%` }}>{pct}%</div>
                                </div>
                                <p className="mt-2 text-sm text-muted-foreground">{progress.message}</p>
                                {taskId && <div className="text-xs text-muted-foreground">{t('wiki_js_task_id')}{taskId}</div>}
                                {progress.status === 'running' && (
                                    <div className="mt-3 text-center">
                                        <Button type="button" variant="secondary" onClick={cancelImport}>
                                            <i className="fa fa-stop mr-1" aria-hidden />{t('wiki_cancel_btn')}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                {/* 記錄列表 */}
                <div className="overflow-x-auto rounded-md border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="px-3 py-2 text-left font-medium">{t('wiki_col_person_id')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('wiki_col_name_chn')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('wiki_col_text_id')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('wiki_col_page')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {records.length === 0 && (
                                <tr><td colSpan={4} className="px-3 py-6 text-center text-muted-foreground">{t('wiki_no_records')}</td></tr>
                            )}
                            {records.map((r) => (
                                <tr key={`${r.c_personid}-${r.c_textid}-${r.c_pages}`} className="border-t border-border">
                                    <td className="px-3 py-1.5">
                                        <a className="text-primary hover:underline" href={`/app/basicinformation/${r.c_personid}/sources/edit-v2`} target="_blank" rel="noreferrer">{r.c_personid}</a>
                                    </td>
                                    <td className="px-3 py-1.5">{r.c_name_chn ?? '-'}</td>
                                    <td className="px-3 py-1.5">{r.c_textid}</td>
                                    <td className="px-3 py-1.5">
                                        {r.link ? <a className="text-primary hover:underline" href={r.link} target="_blank" rel="noreferrer">{r.c_pages}</a> : (r.c_pages ?? '-')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* 分頁 */}
                {total > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-sm text-muted-foreground">
                            {t('wiki_showing', { from: String((page - 1) * per_page + 1), to: String(Math.min(page * per_page, total)), total: total.toLocaleString() })}
                        </p>
                        <div className="flex items-center gap-1">
                            <Button size="sm" variant="outline" disabled={!has_prev} onClick={() => goPage(page - 1)}>«</Button>
                            {pages.map((i) => (
                                <Button key={i} size="sm" variant={i === page ? 'default' : 'outline'} onClick={() => goPage(i)}>{i}</Button>
                            ))}
                            <Button size="sm" variant="outline" disabled={!has_next} onClick={() => goPage(page + 1)}>»</Button>
                        </div>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}
