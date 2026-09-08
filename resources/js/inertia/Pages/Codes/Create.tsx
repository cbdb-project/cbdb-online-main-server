import React, { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { FormField } from '../../components/ui/FormField';
import { CodesColumnField, type ColumnBehaviour } from '../../components/Codes/CodesColumnField';
import { useLoadTextTitle } from '../../components/Codes/useLoadTextTitle';
import { PinyinUmlautConfirmDialog } from '../../components/PinyinUmlautConfirmDialog';
import { SaveSplitButton } from '../../components/ui/SaveSplitButton';
import { collectUmlautConversions, type Tier2UmlautHit } from '../../utils/pinyinUmlaut';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

interface CodesCreatePageProps extends SharedProps {
    table: string;
    columns: string[];
    defaults: Record<string, string | number>;
    required_columns?: string[];
    can_propose: boolean;
    tier2_fields?: string[];
    /**
     * 逐欄特殊行為（稽核欄唯讀、欄位提示、Load Data 動作），由後端 codeColumnBehaviour() 供給。
     * 先前此頁的欄位提示是前端硬編碼中文（英文語境會漏字，且與 codes.* 既有鍵重複），已移除。
     */
    column_behaviour?: Record<string, ColumnBehaviour>;
    text_title_endpoint?: string;
    urls: { store: string; propose: string; show: string };
}

export default function CodesCreate() {
    const props = usePage<CodesCreatePageProps>().props;
    const {
        table, columns, defaults, required_columns, can_propose, tier2_fields,
        column_behaviour, text_title_endpoint, urls,
    } = props;
    const behaviourOf = column_behaviour ?? {};
    const requiredSet = new Set(required_columns ?? []);
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
    // 具直接保存權限者（非眾包用戶）：直接保存為主按鈕，提案收進箭頭選單；只可提案者只有提案鈕。
    const canWriteDirectly = !!props.auth.user?.can.write_directly;

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
    const saveDirect = () => gate((ov) => submitPost(urls.store, ov));
    const propose = () => gate((ov) => submitPost(urls.propose, ov));

    // TEXT_INSTANCE_DATA：依 c_textid 帶入書名（只填空欄）。
    const loadTitle = useLoadTextTitle({
        endpoint: text_title_endpoint ?? '',
        getField: (c) => form.data[c] ?? '',
        setField: (c, v) => form.setData(c, v),
        t,
    });

    return (
        <DashboardLayout
            title={`${tc('add')} — ${table}`}
            breadcrumbs={[{ label: 'Codes', url: '/app/codes' }, { label: table, url: urls.show }, { label: tc('add') }]}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    saveDirect();
                }}
                className="max-w-3xl space-y-3 rounded-lg border border-border bg-card p-4"
            >
                {columns.map((col) => (
                    <CodesColumnField
                        key={col}
                        column={col}
                        value={form.data[col] ?? ''}
                        // 動一下表單就清掉上次帶入的訊息與黃底（那是對上一次動作的描述）。
                        onChange={(v) => { form.setData(col, v); loadTitle.reset(); }}
                        error={form.errors[col]}
                        required={requiredSet.has(col)}
                        behaviour={behaviourOf[col]}
                        actionLabel={t('load_text_title_btn')}
                        onAction={() => void loadTitle.run()}
                        actionPending={loadTitle.pending}
                        actionMessage={behaviourOf[col]?.action ? loadTitle.message : null}
                        actionFailed={loadTitle.failed}
                        highlighted={loadTitle.filled.includes(col)}
                    />
                ))}

                {can_propose && (
                    <FormField label={t('proposal_desc')} htmlFor="__proposal_comment" hint={t('proposal_desc_hint')}>
                        <textarea
                            id="__proposal_comment"
                            rows={3}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            placeholder={t('proposal_desc_hint')}
                            value={form.data.__proposal_comment ?? ''}
                            onChange={(e) => form.setData('__proposal_comment', e.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">{t('proposal_ignore_hint')}</p>
                    </FormField>
                )}

                {/* 送出鈕不包在 can_propose 裡：非活躍帳號／訪客仍要看到「直接保存」（app.codes.create 無 auth
                    middleware，由後端擋），否則一個完整表單一顆按鈕都沒有。與 Edit.tsx 的結構對齊。
                    直接保存走原生 form submit（含拼音 ü 確認閘）。 */}
                <div className="flex flex-wrap gap-2">
                    <SaveSplitButton
                        directAvailable={canWriteDirectly || !can_propose}
                        proposalAvailable={can_propose}
                        disabled={form.processing}
                        saveDirectType="submit"
                        labels={{ saveDirect: t('save_direct'), submitProposal: t('submit_proposal') }}
                        onSubmitProposal={propose}
                    />
                    <a href={urls.show} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                        {tc('cancel')}
                    </a>
                </div>
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
