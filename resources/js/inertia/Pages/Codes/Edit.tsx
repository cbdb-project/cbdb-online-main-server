import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Button } from '../../components/ui/Button';
import { FormField } from '../../components/ui/FormField';
import { CodesColumnField, type ColumnBehaviour } from '../../components/Codes/CodesColumnField';
import { useLoadTextTitle } from '../../components/Codes/useLoadTextTitle';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { ProposalModeDialog } from '../../components/ui/ProposalModeDialog';
import { PinyinUmlautConfirmDialog } from '../../components/PinyinUmlautConfirmDialog';
import { collectUmlautConversions, type Tier2UmlautHit } from '../../utils/pinyinUmlaut';
import { TextCodesAuthorList, type TextAuthors } from '../../components/TextCodesAuthorList';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

// 安全開關：停用碼表刪除的前端入口。碼表多被人物資料以 ON DELETE CASCADE 外鍵引用，
// 刪一列可能連帶刪除數萬筆人物列且無法乾淨復原；刪除無引用護欄前一律隱藏入口。
// 純前端隱藏，後端刪除路由仍存在，之後須另補後端護欄。
const RISKY_DELETE_DISABLED = true;

interface CodesEditPageProps extends SharedProps {
    table: string;
    id: string;
    columns: string[];
    values: Record<string, string | number | null>;
    key_columns: string[];
    required_columns?: string[];
    can_propose: boolean;
    tier2_fields?: string[];
    /** 僅 TEXT_CODES 有值（該書的作者／編者等關係人）；其餘碼表為 null。 */
    text_authors?: TextAuthors | null;
    /** 逐欄特殊行為（稽核欄唯讀＋替換預覽、欄位提示、Load Data 動作），由後端供給。 */
    column_behaviour?: Record<string, ColumnBehaviour>;
    text_title_endpoint?: string;
    urls: { update: string; propose: string; destroy: string; show: string };
}

export default function CodesEdit() {
    const props = usePage<CodesEditPageProps>().props;
    const {
        table, columns, values, key_columns, required_columns, can_propose, tier2_fields,
        text_authors, column_behaviour, text_title_endpoint, urls,
    } = props;
    const behaviourOf = column_behaviour ?? {};
    const requiredSet = new Set(required_columns ?? []);
    const t = useTranslation('codes');
    const tc = useTranslation('common');
    const tb = useTranslation('biogmains'); // 復用 AltnameEditor 的彈窗字串

    const initial: Record<string, string> = { __proposal_comment: '' };
    columns.forEach((c) => {
        initial[c] = values[c] != null ? String(values[c]) : '';
    });

    const form = useForm<Record<string, string>>(initial);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [confirmProposalMode, setConfirmProposalMode] = useState(false);
    const [umlautPrompt, setUmlautPrompt] = useState<{ hits: Tier2UmlautHit[]; run: (overrides?: Record<string, string>) => void } | null>(null);
    // 具直接保存權限者（非眾包用戶）點擊「提交提案」前先確認是否改為直接保存。
    const canWriteDirectly = !!props.auth.user?.can.write_directly;

    // 送出（Tier 2 確認後）：overrides 以 transform 保證此次提交使用者選擇的值，避免 setData 非同步問題。
    const submitVia = (method: 'patch' | 'post', url: string, overrides?: Record<string, string>) => {
        if (overrides) {
            form.transform((d) => ({ ...d, ...overrides }));
        }
        form[method](url, { preserveScroll: true, onFinish: () => form.transform((d) => d) });
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

    // app.codes.update 接受 PUT/PATCH；useForm.patch 直接送出。
    const saveDirect = () => gate((ov) => submitVia('patch', urls.update, ov));
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        saveDirect();
    };
    const propose = () => gate((ov) => submitVia('post', urls.propose, ov));

    // TEXT_INSTANCE_DATA：依 c_textid 帶入書名（只填空欄）。
    const loadTitle = useLoadTextTitle({
        endpoint: text_title_endpoint ?? '',
        getField: (c) => form.data[c] ?? '',
        setField: (c, v) => form.setData(c, v),
        t,
    });

    return (
        <DashboardLayout
            title={`${tc('edit')} — ${table}`}
            breadcrumbs={[{ label: 'Codes', url: '/app/codes' }, { label: table, url: urls.show }, { label: tc('edit') }]}
        >
            <form onSubmit={submit} className="max-w-3xl space-y-3 rounded-lg border border-border bg-card p-4">
                {/* TEXT_CODES：作者／編者等關係人（唯讀錄入參考），置於欄位之上、對齊舊版版位。
                    不用 FormField：它會把 id 注入單一子節點並讓 <label for> 指過去，而這一區沒有
                    表單控制項，label 指向 <div> 是無效關聯；改用純標題文字。 */}
                {text_authors && (
                    <div className="space-y-1">
                        <p className="text-sm font-medium">{t('author_label')}</p>
                        <TextCodesAuthorList authors={text_authors} t={t} />
                    </div>
                )}

                {columns.map((col) => (
                    <CodesColumnField
                        key={col}
                        column={col}
                        value={form.data[col] ?? ''}
                        // 動一下表單就清掉上次帶入的訊息與黃底（那是對上一次動作的描述）。
                        onChange={(v) => { form.setData(col, v); loadTitle.reset(); }}
                        error={form.errors[col]}
                        required={requiredSet.has(col)}
                        isKey={key_columns.includes(col)}
                        behaviour={behaviourOf[col]}
                        actionLabel={t('load_text_title_btn')}
                        onAction={() => void loadTitle.run()}
                        actionPending={loadTitle.pending}
                        // 訊息就近顯示在觸發它的欄位下方（TEXT_INSTANCE_DATA 欄位多，放表單底部會離按鈕太遠）。
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
                        {/* 舊版 codes/edit.blade.php 與 create.blade.php 兩頁都有這句，React 只有 Create 有。 */}
                        <p className="text-xs text-muted-foreground">{t('proposal_ignore_hint')}</p>
                    </FormField>
                )}

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" disabled={form.processing}>{t('save_direct')}</Button>
                    {can_propose && (
                        <Button type="button" variant="secondary" disabled={form.processing} onClick={() => (canWriteDirectly ? setConfirmProposalMode(true) : propose())}>
                            {t('submit_proposal')}
                        </Button>
                    )}
                    {!RISKY_DELETE_DISABLED && (
                        <Button type="button" variant="destructive" disabled={form.processing} onClick={() => setConfirmDelete(true)}>
                            {tc('delete')}
                        </Button>
                    )}
                    <a href={urls.show} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                        {tc('cancel')}
                    </a>
                </div>
            </form>

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={t('confirm_delete')}
                confirmLabel={tc('delete')}
                cancelLabel={tc('cancel')}
                destructive
                onConfirm={() => {
                    setConfirmDelete(false);
                    router.delete(urls.destroy, { preserveScroll: true });
                }}
            />

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

            <ProposalModeDialog
                open={confirmProposalMode}
                onOpenChange={setConfirmProposalMode}
                title={t('direct_save_prompt_title')}
                description={t('direct_save_prompt_desc')}
                saveDirectLabel={t('save_direct')}
                submitProposalLabel={t('submit_proposal')}
                cancelLabel={tc('cancel')}
                loading={form.processing}
                onSaveDirect={() => { setConfirmProposalMode(false); saveDirect(); }}
                onSubmitProposal={() => { setConfirmProposalMode(false); propose(); }}
            />
        </DashboardLayout>
    );
}
