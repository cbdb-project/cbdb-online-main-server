import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { FormField } from '../../../components/ui/FormField';
import { LineNumberedTextarea } from '../../../components/ui/LineNumberedTextarea';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';

interface ResultRow {
    line: number;
    name: string;
    name_code: string | number | null;
    name_pinyin: string | null;
    name_created: boolean;
    inst_code: string | number | null;
    type_label: string | null;
    type_code: string | number | null;
    dynasty_label: string | null;
    dynasty_code: string | number | null;
    addr_id: string | number | null;
    source_id: string | number | null;
}

interface BatchSocialPageProps extends SharedProps {
    input: string;
    results: ResultRow[];
    batch_errors: string[];
    urls: { store: string; reset: string };
}

const COLS = [
    'batch_col_line', 'batch_col_inst_name', 'batch_col_name_code', 'batch_col_name_pinyin',
    'batch_col_name_new', 'batch_col_inst_code', 'batch_col_type_code', 'batch_col_dynasty_code',
    'batch_col_addr_id', 'batch_col_source_textid',
];

export default function BatchLoadSocialInstitutes() {
    const props = usePage<BatchSocialPageProps>().props;
    const { results, batch_errors, urls } = props;
    const t = useTranslation('admin');

    const form = useForm<{ entries: string }>({ entries: props.input ?? '' });

    return (
        <DashboardLayout title={t('batch_social_page_title')}>
            <div className="rounded-lg border border-border bg-card p-4">
                <p className="mb-3 text-sm text-muted-foreground" dangerouslySetInnerHTML={{ __html: t('batch_social_desc') }} />

                {batch_errors.length > 0 && (
                    <div className="mb-3 rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">
                        <p className="font-semibold">{t('batch_import_failed')}</p>
                        <ul className="mt-1">
                            {batch_errors.map((m, i) => <li key={i}>・{m}</li>)}
                        </ul>
                    </div>
                )}

                <form onSubmit={(e) => { e.preventDefault(); form.post(urls.store, { preserveScroll: true }); }}>
                    <FormField label={t('batch_data_tab_sep')} htmlFor="entries" error={form.errors.entries}>
                        <LineNumberedTextarea
                            value={form.data.entries}
                            onChange={(v) => form.setData('entries', v)}
                            placeholder={t('batch_social_placeholder')}
                        />
                    </FormField>
                    <div className="flex gap-2">
                        <Button type="submit" disabled={form.processing}>{t('batch_submit')}</Button>
                        <a href={urls.reset} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                            {t('batch_clear_reset')}
                        </a>
                    </div>
                </form>

                {results.length > 0 && (
                    <>
                        <p className="mt-4 text-sm text-muted-foreground">{t('batch_social_added', { count: String(results.length) })}</p>
                        <div className="mt-2 overflow-x-auto rounded-md border border-border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        {COLS.map((k) => (
                                            <th key={k} className="whitespace-nowrap px-3 py-2 text-left font-medium">{t(k)}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {results.map((r) => (
                                        <tr key={`${r.line}-${r.inst_code}`} className="border-t border-border">
                                            <td className="px-3 py-1.5">{r.line}</td>
                                            <td className="px-3 py-1.5">{r.name}</td>
                                            <td className="px-3 py-1.5">{r.name_code}</td>
                                            <td className="px-3 py-1.5">{r.name_pinyin}</td>
                                            <td className="px-3 py-1.5">{r.name_created ? t('batch_yes') : t('batch_no')}</td>
                                            <td className="px-3 py-1.5">{r.inst_code}</td>
                                            <td className="px-3 py-1.5">{r.type_label} / {r.type_code}</td>
                                            <td className="px-3 py-1.5">{r.dynasty_label} / {r.dynasty_code}</td>
                                            <td className="px-3 py-1.5">{r.addr_id}</td>
                                            <td className="px-3 py-1.5">{r.source_id}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </DashboardLayout>
    );
}
