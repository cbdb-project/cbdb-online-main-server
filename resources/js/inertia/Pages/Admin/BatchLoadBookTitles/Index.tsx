import React, { useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { FormField } from '../../../components/ui/FormField';
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';
import { cn } from '../../../lib/utils';

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
    urls: { store: string; undo: string; reset: string };
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

    useEffect(() => {
        setToastShown(toast);
        if (toast) {
            const id = setTimeout(() => setToastShown(null), 3000);
            return () => clearTimeout(id);
        }
    }, [toast]);

    const submit = (force: boolean) => {
        form.transform((d) => (force ? { entries: d.entries, force: '1' } : { entries: d.entries }));
        form.post(urls.store, { preserveScroll: true });
    };

    const undo = () => {
        if (batch_id) {
            router.post(urls.undo, { batch_id }, { preserveScroll: true });
        }
    };

    const copyResults = () => {
        const payload = results.map((r) => `${r.c_textid}\t${r.title}`).join('\n');
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

                {batch_errors.length > 0 && (
                    <div className="mb-3 rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">
                        <p className="font-semibold">{t('batch_import_failed')}</p>
                        <ul className="mt-1">
                            {batch_errors.map((m, i) => <li key={i}>・{m}</li>)}
                        </ul>
                    </div>
                )}

                <form onSubmit={(e) => { e.preventDefault(); submit(false); }}>
                    <FormField label={t('batch_data_tab_sep')} htmlFor="entries" error={form.errors.entries}>
                        <textarea
                            id="entries"
                            rows={10}
                            spellCheck={false}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            placeholder={t('batch_book_placeholder')}
                            value={form.data.entries}
                            onChange={(e) => form.setData('entries', e.target.value)}
                        />
                    </FormField>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={form.processing}>{t('batch_submit')}</Button>
                        <Button type="button" variant="secondary" disabled={form.processing} onClick={() => setConfirmForce(true)}>
                            {t('batch_force_submit')}
                        </Button>
                        <a href={urls.reset} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                            {t('batch_clear_reset')}
                        </a>
                    </div>
                </form>

                {results.length > 0 && (
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
                                    {results.map((r) => (
                                        <tr key={`${r.line}-${r.c_textid}`} className="border-t border-border">
                                            <td className="px-3 py-1.5">{r.line}</td>
                                            <td className="px-3 py-1.5">{r.author_id}</td>
                                            <td className="px-3 py-1.5">{r.title}</td>
                                            <td className="px-3 py-1.5">{r.title_pinyin}</td>
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
