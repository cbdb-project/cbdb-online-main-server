import React, { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { FormField } from '../../components/ui/FormField';
import { PinyinUmlautConfirmDialog } from '../../components/PinyinUmlautConfirmDialog';
import { collectUmlautConversions, type Tier2UmlautHit } from '../../utils/pinyinUmlaut';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

interface CodesCreatePageProps extends SharedProps {
    table: string;
    columns: string[];
    defaults: Record<string, string | number>;
    can_propose: boolean;
    tier2_fields?: string[];
    urls: { store: string; propose: string; show: string };
}

// 特定表的欄位輔助說明（沿用舊頁 help-block 文案）。
const COLUMN_HINTS: Record<string, Record<string, { text: string; link?: { href: string; label: string } }>> = {
    ADDR_BELONGS_DATA: {
        c_addr_id: { text: '請從 ADDR_CODES 表中複製 c_addr_id 填入', link: { href: '/codes/ADDR_CODES', label: 'ADDR_CODES' } },
        c_belongs_to: { text: '請從 ADDR_CODES 表中複製 c_addr_id 填入', link: { href: '/codes/ADDR_CODES', label: 'ADDR_CODES' } },
    },
    TEXT_INSTANCE_DATA: {
        c_textid: { text: '請確保 TEXT_CODES 表中存在這本書的 c_textid，再複製 ID 填入', link: { href: '/codes/TEXT_CODES', label: 'TEXT_CODES' } },
    },
};

export default function CodesCreate() {
    const props = usePage<CodesCreatePageProps>().props;
    const { table, columns, defaults, can_propose, tier2_fields, urls } = props;
    const t = useTranslation('codes');
    const tc = useTranslation('common');
    const tb = useTranslation('biogmains'); // 復用 AltnameEditor 的彈窗字串

    const initial: Record<string, string> = {};
    columns.forEach((c) => {
        initial[c] = defaults[c] != null ? String(defaults[c]) : '';
    });
    initial.__proposal_comment = '';

    const form = useForm<Record<string, string>>(initial);
    const [umlautPrompt, setUmlautPrompt] = useState<{ hits: Tier2UmlautHit[]; run: (overrides?: Record<string, string>) => void } | null>(null);

    // 送出（Tier 2 確認後）：overrides 以 transform 保證此次提交送出使用者選擇的值，避免 setData 非同步問題。
    const submitPost = (url: string, overrides?: Record<string, string>) => {
        if (overrides) {
            form.transform((d) => ({ ...d, ...overrides }));
        }
        form.post(url, { preserveScroll: true, onFinish: () => form.transform((d) => d) });
    };
    // §D-6 Tier 2 閘：提交前掃描 Tier 2 欄，有命中先彈窗由使用者決定。
    const gate = (run: (overrides?: Record<string, string>) => void) => {
        const hits = collectUmlautConversions(tier2_fields ?? [], form.data);
        if (hits.length > 0) {
            setUmlautPrompt({ hits, run });

            return;
        }
        run();
    };
    const tableHints = COLUMN_HINTS[table] ?? {};

    return (
        <DashboardLayout
            title={`${tc('add')} — ${table}`}
            breadcrumbs={[{ label: 'Codes', url: '/app/codes' }, { label: table, url: urls.show }, { label: tc('add') }]}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    gate((ov) => submitPost(urls.store, ov));
                }}
                className="max-w-3xl space-y-3 rounded-lg border border-border bg-card p-4"
            >
                {columns.map((col) => {
                    const hint = tableHints[col];
                    return (
                        <FormField key={col} label={col} htmlFor={col} error={form.errors[col]}>
                            <Input
                                id={col}
                                value={form.data[col] ?? ''}
                                onChange={(e) => form.setData(col, e.target.value)}
                            />
                            {hint && (
                                <p className="text-xs text-muted-foreground">
                                    {hint.text}
                                    {hint.link && (
                                        <>
                                            {' '}
                                            <a href={hint.link.href} target="_blank" rel="noreferrer" className="text-primary hover:underline">
                                                {hint.link.label}
                                            </a>
                                        </>
                                    )}
                                </p>
                            )}
                        </FormField>
                    );
                })}

                {can_propose && (
                    <>
                        <FormField label={t('proposal_desc')} htmlFor="__proposal_comment" hint={t('proposal_desc_hint')}>
                            <textarea
                                id="__proposal_comment"
                                rows={3}
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                placeholder={t('proposal_desc_hint')}
                                value={form.data.__proposal_comment ?? ''}
                                onChange={(e) => form.setData('__proposal_comment', e.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">如果直接儲存，此欄位會被忽略。</p>
                        </FormField>

                        <div className="flex gap-2">
                            <Button type="submit" disabled={form.processing}>{t('save_direct')}</Button>
                            <Button type="button" variant="secondary" disabled={form.processing} onClick={() => gate((ov) => submitPost(urls.propose, ov))}>
                                {t('submit_proposal')}
                            </Button>
                        </div>
                    </>
                )}
            </form>

            <PinyinUmlautConfirmDialog
                hits={umlautPrompt?.hits ?? null}
                t={tb}
                onCancel={() => setUmlautPrompt(null)}
                onKeep={() => { const p = umlautPrompt; setUmlautPrompt(null); p?.run(); }}
                onConvert={() => {
                    const p = umlautPrompt;
                    setUmlautPrompt(null);
                    if (!p) return;
                    const overrides: Record<string, string> = {};
                    p.hits.forEach((h) => { overrides[h.field] = h.converted; form.setData(h.field, h.converted); });
                    p.run(overrides);
                }}
            />
        </DashboardLayout>
    );
}
