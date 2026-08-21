import React, { useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { FormField } from '../../../components/ui/FormField';
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog';
import { LineNumberedTextarea } from '../../../components/ui/LineNumberedTextarea';
import { useTranslation } from '../../../hooks/useTranslation';
import { getCsrfToken } from '../../../components/PersonBrowser/shared/csrf';
import type { SharedProps } from '../../../types/page';
import { cn } from '../../../lib/utils';

interface VariantReplacement {
    from: string;
    to: string;
}

interface ResultRow {
    line: number;
    author_id: string | number;
    title: string;
    title_pinyin: string;
    source: string | number | null;
    dynasty: string | number | null;
    text_type: string;
    created_by: string | null;
    created_date: string | null;
    c_textid: number;
    variant_replacements?: VariantReplacement[];
}

interface Toast {
    msg: string;
    type?: 'success' | 'error' | 'warning';
}

interface BatchBooksPageProps extends SharedProps {
    input: string;
    results: ResultRow[];
    batch_errors: string[];
    batch_id: string | null;
    toast: Toast | null;
    urls: { store: string; undo: string; reset: string; update_pinyin: string; check_rare_chars: string };
}

interface RareCharMissingRow {
    line: number;
    title: string;
    chars: { char: string; codepoint: string }[];
}

interface RareCharResult {
    checked: number;
    parse_errors: string[];
    missing: RareCharMissingRow[];
    unique_char_count: number;
}

export default function BatchLoadBookTitles() {
    const props = usePage<BatchBooksPageProps>().props;
    const { results, batch_errors, batch_id, toast, urls } = props;
    const t = useTranslation('admin');
    const tc = useTranslation('common');

    const form = useForm<{ entries: string; force: string }>({ entries: props.input ?? '', force: '' });
    const [confirmForce, setConfirmForce] = useState(false);
    const [confirmUndo, setConfirmUndo] = useState(false);
    const [toastShown, setToastShown] = useState<Toast | null>(toast);

    // 逐列拼音就地編輯狀態（重建舊 Blade 版的直接編輯拼音功能）。
    const [rows, setRows] = useState<ResultRow[]>(results);
    const [editId, setEditId] = useState<number | null>(null);
    const [draft, setDraft] = useState('');
    const [busyId, setBusyId] = useState<number | null>(null);
    const [rowStatus, setRowStatus] = useState<{ id: number; msg: string; kind: 'success' | 'error' } | null>(null);

    // 罕見字檢測（只查 pinyin 表）狀態。發現的罕見字/解析錯誤顯示於既有的訊息窗口
    // （與匯入錯誤共用同一個 box）；「未發現/空輸入/網路錯誤」等單行狀態走頁面既有的 toast。
    const [rareChecking, setRareChecking] = useState(false);
    const [rareResult, setRareResult] = useState<RareCharResult | null>(null);

    useEffect(() => {
        setToastShown(toast);
        if (toast) {
            const id = setTimeout(() => setToastShown(null), 3000);
            return () => clearTimeout(id);
        }
    }, [toast]);

    // 重新匯入/回退後 props.results 會變，同步本地列並清空編輯狀態。
    useEffect(() => {
        setRows(results);
        setEditId(null);
        setDraft('');
        setRowStatus(null);
        setRareResult(null);
    }, [results]);

    const flashToast = (tt: Toast) => {
        setToastShown(tt);
        setTimeout(() => setToastShown(null), 3000);
    };

    const startEdit = (r: ResultRow) => {
        setEditId(r.c_textid);
        setDraft(r.title_pinyin ?? '');
        setRowStatus(null);
    };
    const cancelEdit = () => {
        setEditId(null);
        setDraft('');
        setRowStatus(null);
    };

    const savePinyin = async (r: ResultRow) => {
        if (busyId !== null) {
            return; // 防止進行中重複送出（避免重複審計記錄）
        }
        const value = draft.trim();
        if (value === '') {
            setRowStatus({ id: r.c_textid, msg: t('batch_pinyin_empty'), kind: 'error' });
            return;
        }
        if (!batch_id) {
            return;
        }
        setBusyId(r.c_textid);
        setRowStatus(null);
        try {
            const res = await fetch(urls.update_pinyin, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ c_textid: r.c_textid, batch_id, pinyin: value }),
            });
            const json = await res.json().catch(() => null);
            if (!res.ok || !json || json.ok === false) {
                const msg = (json && json.message) || t('batch_pinyin_save_failed');
                setRowStatus({ id: r.c_textid, msg, kind: 'error' });
                flashToast({ msg, type: 'error' });
                return;
            }
            // 以伺服器實際存回的值更新該列（可能經正規化/截斷）。
            const stored = String(json.c_title ?? '');
            setRows((prev) => prev.map((x) => (x.c_textid === r.c_textid ? { ...x, title_pinyin: stored } : x)));
            setEditId(null);
            setDraft('');
            setRowStatus({ id: r.c_textid, msg: t('batch_pinyin_saved') + stored, kind: 'success' });
            flashToast({ msg: t('batch_pinyin_updated') + json.c_textid, type: 'success' });
            // 成功狀態 4 秒後自動清除（對齊舊版行為）。
            setTimeout(() => setRowStatus((cur) => (cur?.id === r.c_textid && cur.kind === 'success' ? null : cur)), 4000);
        } catch {
            setRowStatus({ id: r.c_textid, msg: t('batch_network_error'), kind: 'error' });
        } finally {
            setBusyId(null);
        }
    };

    const checkRareChars = async () => {
        if (rareChecking) {
            return;
        }
        if (form.data.entries.trim() === '') {
            setRareResult(null);
            flashToast({ msg: t('batch_check_rare_chars_empty_input'), type: 'warning' });
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
        setRareChecking(true);
        try {
            const res = await fetch(urls.check_rare_chars, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ entries: form.data.entries }),
            });
            const json = await res.json().catch(() => null);
            if (!res.ok || !json || json.ok === false) {
                setRareResult(null);
                flashToast({ msg: (json && json.message) || t('batch_network_error'), type: 'error' });
                return;
            }
            const result: RareCharResult = {
                checked: json.checked ?? 0,
                parse_errors: Array.isArray(json.parse_errors) ? json.parse_errors : [],
                missing: Array.isArray(json.missing) ? json.missing : [],
                unique_char_count: json.unique_char_count ?? 0,
            };
            setRareResult(result);
            // 有罕見字或解析錯誤 → 顯示於訊息窗口（下方 box）；否則全部通過 → 綠色 toast。
            if (result.missing.length === 0 && result.parse_errors.length === 0) {
                flashToast({ msg: t('batch_check_rare_chars_none', { count: String(result.checked) }), type: 'success' });
            }
        } catch {
            setRareResult(null);
            flashToast({ msg: t('batch_network_error'), type: 'error' });
        } finally {
            setRareChecking(false);
            // 訊息窗口／toast 都在頁面頂端，檢測後捲回頂端讓結果立即可見。
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const submit = (force: boolean) => {
        setRareResult(null);
        form.transform((d) => (force ? { entries: d.entries, force: '1' } : { entries: d.entries }));
        form.post(urls.store, { preserveScroll: true });
    };

    const undo = () => {
        if (batch_id) {
            router.post(urls.undo, { batch_id }, { preserveScroll: true });
        }
    };

    const copyResults = () => {
        const payload = rows.map((r) => `${r.c_textid}\t${r.title}`).join('\n');
        navigator.clipboard?.writeText(payload);
    };

    return (
        <DashboardLayout title={t('batch_book_title_page_title')}>
            {toastShown && (
                <div
                    className={cn(
                        'mb-3 rounded px-4 py-2 text-sm',
                        toastShown.type === 'error' ? 'bg-red-100 text-red-800'
                            : toastShown.type === 'warning' ? 'bg-yellow-100 text-yellow-800'
                            : 'bg-green-100 text-green-800'
                    )}
                >
                    {toastShown.msg}
                </div>
            )}

            <div className="rounded-lg border border-border bg-card p-4">
                <p className="mb-3 text-sm text-muted-foreground" dangerouslySetInnerHTML={{ __html: t('batch_book_title_desc') }} />

                {batch_id && (
                    <div className="mb-3 rounded border border-blue-300 bg-blue-50 px-4 py-2 text-sm text-blue-800">
                        {t('batch_this_batch_id')} <code>{batch_id}</code>
                    </div>
                )}

                {(batch_errors.length > 0 || (rareResult && (rareResult.missing.length > 0 || rareResult.parse_errors.length > 0))) && (
                    <div className="mb-3 rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">
                        {batch_errors.length > 0 && (
                            <>
                                <p className="font-semibold">{t('batch_import_failed')}</p>
                                <ul className="mt-1">
                                    {batch_errors.map((m, i) => <li key={i}>・{m}</li>)}
                                </ul>
                            </>
                        )}
                        {rareResult && (rareResult.missing.length > 0 || rareResult.parse_errors.length > 0) && (
                            <div className={batch_errors.length > 0 ? 'mt-3 border-t border-red-200 pt-2' : ''}>
                                <p className="font-semibold">{t('batch_check_rare_chars_title')}</p>
                                {rareResult.missing.length > 0 && (
                                    <>
                                        <p className="mt-1">
                                            {t('batch_check_rare_chars_summary', {
                                                count: String(rareResult.checked),
                                                chars: String(rareResult.unique_char_count),
                                                lines: String(rareResult.missing.length),
                                            })}
                                        </p>
                                        <ul className="mt-1 space-y-0.5">
                                            {rareResult.missing.map((row) => (
                                                <li key={row.line}>
                                                    <span className="font-medium">{t('batch_check_rare_chars_line', { line: String(row.line) })}</span>
                                                    {'：'}
                                                    {row.chars.map((c, i) => (
                                                        <span key={c.codepoint} className="font-mono">
                                                            {i > 0 ? ' ' : ''}
                                                            「{c.char}」({c.codepoint})
                                                        </span>
                                                    ))}
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                )}
                                {rareResult.parse_errors.length > 0 && (
                                    <div className={rareResult.missing.length > 0 ? 'mt-2 border-t border-red-200 pt-2' : 'mt-1'}>
                                        <p>{t('batch_check_rare_chars_parse_note', { count: String(rareResult.parse_errors.length) })}</p>
                                        <ul className="mt-1">
                                            {rareResult.parse_errors.map((m, i) => <li key={i}>・{m}</li>)}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}

                <form onSubmit={(e) => { e.preventDefault(); submit(false); }}>
                    <FormField label={t('batch_data_tab_sep')} htmlFor="entries" error={form.errors.entries}>
                        <LineNumberedTextarea
                            value={form.data.entries}
                            onChange={(v) => form.setData('entries', v)}
                            placeholder={t('batch_book_placeholder')}
                        />
                    </FormField>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={form.processing}>{t('batch_submit')}</Button>
                        <Button type="button" variant="secondary" disabled={form.processing} onClick={() => setConfirmForce(true)}>
                            {t('batch_force_submit')}
                        </Button>
                        <Button type="button" variant="outline" disabled={rareChecking} onClick={checkRareChars}>
                            {rareChecking ? t('batch_check_rare_chars_checking') : t('batch_check_rare_chars')}
                        </Button>
                        <a href={urls.reset} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                            {t('batch_clear_reset')}
                        </a>
                    </div>
                </form>

                {rows.length > 0 && (
                    <>
                        <div className="mt-5 flex items-center gap-2">
                            <Button type="button" variant="outline" size="sm" onClick={copyResults}>{t('batch_copy_textid_btn')}</Button>
                            {batch_id && (
                                <Button type="button" variant="destructive" size="sm" onClick={() => setConfirmUndo(true)}>
                                    {t('batch_undo_import')}
                                </Button>
                            )}
                        </div>
                        <div className="mt-3 overflow-x-auto rounded-md border border-border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        {['batch_col_line', 'batch_col_author_id', 'batch_col_title_cleaned', 'batch_col_title_pinyin', 'batch_col_source_textid', 'batch_col_dynasty', 'batch_col_text_type', 'batch_col_batch_id', 'batch_col_created_by', 'batch_col_created_date', 'batch_col_new_textid'].map((k) => (
                                            <th key={k} className="whitespace-nowrap px-3 py-2 text-left font-medium">{t(k)}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((r) => (
                                        <tr key={`${r.line}-${r.c_textid}`} className="border-t border-border">
                                            <td className="px-3 py-1.5">{r.line}</td>
                                            <td className="px-3 py-1.5">{r.author_id}</td>
                                            <td className="px-3 py-1.5">
                                                <div>{r.title}</div>
                                                {r.variant_replacements && r.variant_replacements.length > 0 && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {t('batch_variant_replaced_hint', {
                                                            pairs: r.variant_replacements.map((v) => `${v.from}→${v.to}`).join('、'),
                                                        })}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-1.5">
                                                {batch_id && editId === r.c_textid ? (
                                                    <div className="flex flex-col gap-1">
                                                        <div className="flex items-center gap-1">
                                                            <input
                                                                autoFocus
                                                                value={draft}
                                                                spellCheck={false}
                                                                disabled={busyId === r.c_textid}
                                                                onFocus={(e) => e.target.select()}
                                                                onChange={(e) => setDraft(e.target.value)}
                                                                onKeyDown={(e) => {
                                                                    if (e.key === 'Enter') { e.preventDefault(); savePinyin(r); }
                                                                    if (e.key === 'Escape') { cancelEdit(); }
                                                                }}
                                                                className="w-44 rounded border border-input bg-background px-2 py-1 font-mono text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                            />
                                                            <Button type="button" size="sm" disabled={busyId === r.c_textid} onClick={() => savePinyin(r)}>
                                                                {t('batch_pinyin_save')}
                                                            </Button>
                                                            <Button type="button" size="sm" variant="secondary" disabled={busyId === r.c_textid} onClick={cancelEdit}>
                                                                {tc('cancel')}
                                                            </Button>
                                                        </div>
                                                        {(busyId === r.c_textid || rowStatus?.id === r.c_textid) && (
                                                            <span
                                                                className={cn(
                                                                    'text-xs',
                                                                    busyId === r.c_textid ? 'text-muted-foreground'
                                                                        : rowStatus?.kind === 'error' ? 'text-red-600' : 'text-green-600'
                                                                )}
                                                            >
                                                                {busyId === r.c_textid ? t('batch_pinyin_saving') : rowStatus?.msg}
                                                            </span>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <div className="flex items-center gap-2">
                                                        <span>{r.title_pinyin}</span>
                                                        {batch_id && (
                                                            <button
                                                                type="button"
                                                                onClick={() => startEdit(r)}
                                                                title={t('batch_pinyin_edit_title')}
                                                                aria-label={t('batch_pinyin_edit_title')}
                                                                className="text-muted-foreground hover:text-primary"
                                                            >
                                                                <i className="fas fa-pen text-xs" aria-hidden="true" />
                                                            </button>
                                                        )}
                                                        {rowStatus?.id === r.c_textid && rowStatus.kind === 'success' && (
                                                            <span className="text-xs text-green-600">{rowStatus.msg}</span>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-1.5">{r.source}</td>
                                            <td className="px-3 py-1.5">{r.dynasty}</td>
                                            <td className="px-3 py-1.5">{r.text_type}</td>
                                            <td className="px-3 py-1.5">{batch_id ? `[${batch_id}]` : ''}</td>
                                            <td className="px-3 py-1.5">{r.created_by}</td>
                                            <td className="px-3 py-1.5">{r.created_date}</td>
                                            <td className="px-3 py-1.5">{r.c_textid}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>

            <ConfirmDialog
                open={confirmForce}
                onOpenChange={setConfirmForce}
                title={t('batch_force_submit')}
                description={t('batch_force_confirm')}
                confirmLabel={t('batch_force_submit')}
                cancelLabel={tc('cancel')}
                onConfirm={() => { setConfirmForce(false); submit(true); }}
            />
            <ConfirmDialog
                open={confirmUndo}
                onOpenChange={setConfirmUndo}
                title={t('batch_undo_import')}
                description={t('batch_undo_confirm')}
                confirmLabel={t('batch_undo_import')}
                cancelLabel={tc('cancel')}
                destructive
                onConfirm={() => { setConfirmUndo(false); undo(); }}
            />
        </DashboardLayout>
    );
}
