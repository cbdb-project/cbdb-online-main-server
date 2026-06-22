import React, { useEffect, useState } from 'react';
import { Modal } from '../ui/Modal';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import { FormField } from '../ui/FormField';
import CodeAutocomplete from './shared/CodeAutocomplete';
import { getCsrfToken } from './shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';

export interface SocialInstitutionEditorRow {
    pk: {
        c_personid: number;
        c_inst_code: number | null;
        c_inst_name_code: number | null;
        c_bi_role_code: number | null;
    };
    role_code: number | null;
    role_chn: string | null;
    role: string | null;
    inst_code: number | null;
    inst_name_code: number | null;
    inst_name_chn: string | null;
    inst_name: string | null;
    first_year: number | null;
    last_year: number | null;
    by_nh_code: number | null;
    by_nh_year: number | null;
    by_range: number | null;
    ey_nh_code: number | null;
    ey_nh_year: number | null;
    ey_range: number | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    open: boolean;
    mode: 'create' | 'edit';
    /** true 時送出提案（mode:'proposal'）而非直接寫入。 */
    proposalMode?: boolean;
    personId: number;
    createEndpoint: string;
    mutateEndpoint: string;
    /** 編輯模式下的既有資料。 */
    row?: SocialInstitutionEditorRow | null;
    /** 機構（c_inst_code-c_inst_name_code）顯示文字，編輯模式初始顯示。 */
    instInitialLabel?: string | null;
    /** 機構角色（c_bi_role_code）顯示文字，編輯模式初始顯示。 */
    roleInitialLabel?: string | null;
    /** 出處（c_source）顯示文字，編輯模式初始顯示。 */
    sourceInitialLabel?: string | null;
    onClose: () => void;
    onSaved: () => void;
}

type FieldErrors = Record<string, string[]>;

interface FormState {
    // PK 欄位（除 c_personid 外）：機構為合併代碼 "c_inst_code-c_inst_name_code"
    inst_pair: string;
    inst_pair_label: string;
    c_bi_role_code: string;
    c_bi_role_code_label: string;
    // 非 PK 欄位
    c_bi_begin_year: string;
    c_bi_by_nh_code: string;
    c_bi_by_nh_code_label: string;
    c_bi_by_nh_year: string;
    c_bi_by_range: string;
    c_bi_by_range_label: string;
    c_bi_end_year: string;
    c_bi_ey_nh_code: string;
    c_bi_ey_nh_code_label: string;
    c_bi_ey_nh_year: string;
    c_bi_ey_range: string;
    c_bi_ey_range_label: string;
    c_source: string;
    c_source_label: string;
    c_pages: string;
    c_notes: string;
}

function emptyState(): FormState {
    return {
        inst_pair: '',
        inst_pair_label: '',
        c_bi_role_code: '',
        c_bi_role_code_label: '',
        c_bi_begin_year: '',
        c_bi_by_nh_code: '',
        c_bi_by_nh_code_label: '',
        c_bi_by_nh_year: '',
        c_bi_by_range: '',
        c_bi_by_range_label: '',
        c_bi_end_year: '',
        c_bi_ey_nh_code: '',
        c_bi_ey_nh_code_label: '',
        c_bi_ey_nh_year: '',
        c_bi_ey_range: '',
        c_bi_ey_range_label: '',
        c_source: '',
        c_source_label: '',
        c_pages: '',
        c_notes: '',
    };
}

// 將代碼/數值欄位的哨兵值（0 / -999）視為「未詳」，編輯時顯示空白而非 0。
function sentinelToBlank(value: number | null | undefined): string {
    if (value == null || value === 0 || value === -999) {
        return '';
    }
    return String(value);
}

function stateFromRow(
    row: SocialInstitutionEditorRow,
    instLabel?: string | null,
    roleLabel?: string | null,
    sourceLabel?: string | null,
): FormState {
    // 機構合併代碼："c_inst_code-c_inst_name_code"（socialinstcode endpoint 回傳格式）。
    const instCode = row.pk.c_inst_code;
    const instNameCode = row.pk.c_inst_name_code;
    const instPair = instCode != null && instNameCode != null ? `${instCode}-${instNameCode}` : '';
    const roleCode = sentinelToBlank(row.pk.c_bi_role_code);

    return {
        inst_pair: instPair,
        inst_pair_label: instLabel ?? (instPair || ''),
        c_bi_role_code: roleCode,
        c_bi_role_code_label: roleLabel ?? (roleCode || ''),
        c_bi_begin_year: sentinelToBlank(row.first_year),
        // 始/終年的年號/時限（Task 27 補回）；sentinel 0/-999→空白，編輯只改備註時帶回原值不被清空。
        c_bi_by_nh_code: sentinelToBlank(row.by_nh_code),
        c_bi_by_nh_code_label: sentinelToBlank(row.by_nh_code),
        c_bi_by_nh_year: row.by_nh_year != null ? String(row.by_nh_year) : '',
        c_bi_by_range: sentinelToBlank(row.by_range),
        c_bi_by_range_label: sentinelToBlank(row.by_range),
        c_bi_end_year: sentinelToBlank(row.last_year),
        c_bi_ey_nh_code: sentinelToBlank(row.ey_nh_code),
        c_bi_ey_nh_code_label: sentinelToBlank(row.ey_nh_code),
        c_bi_ey_nh_year: row.ey_nh_year != null ? String(row.ey_nh_year) : '',
        c_bi_ey_range: sentinelToBlank(row.ey_range),
        c_bi_ey_range_label: sentinelToBlank(row.ey_range),
        c_source: sentinelToBlank(row.source_id),
        c_source_label: sourceLabel ?? sentinelToBlank(row.source_id),
        c_pages: row.pages ?? '',
        c_notes: row.notes ?? '',
    };
}

export default function SocialInstitutionEditorModal({
    open,
    mode,
    proposalMode = false,
    personId,
    createEndpoint,
    mutateEndpoint,
    row,
    instInitialLabel,
    roleInitialLabel,
    sourceInitialLabel,
    onClose,
    onSaved,
}: Props) {
    const t = useTranslation('person');
    const [form, setForm] = useState<FormState>(emptyState);
    const [comment, setComment] = useState('');
    const [errors, setErrors] = useState<FieldErrors>({});
    const [globalError, setGlobalError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }
        setErrors({});
        setGlobalError(null);
        setComment('');
        if (mode === 'edit' && row) {
            setForm(stateFromRow(row, instInitialLabel, roleInitialLabel, sourceInitialLabel));
        } else {
            setForm(emptyState());
        }
    }, [open, mode, row, instInitialLabel, roleInitialLabel, sourceInitialLabel]);

    const setField = (key: keyof FormState, value: string) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    // 數值 PK 欄位：留空＝未詳，送 -999 由後端 normalizeSentinelValues 轉成 '0'。
    // 注意（P4-6 教訓）：空 PK 數值欄務必送 -999，不可送 ''。
    const numericKeyOrSentinel = (raw: string): number => (raw === '' ? -999 : Number(raw));

    // 拆解機構合併代碼回 c_inst_code / c_inst_name_code（兩者皆為 PK，無哨兵，必填）。
    const splitInstPair = (): { c_inst_code: number; c_inst_name_code: number } => {
        const [a, b] = form.inst_pair.split('-');
        return {
            c_inst_code: a == null || a === '' ? -999 : Number(a),
            c_inst_name_code: b == null || b === '' ? -999 : Number(b),
        };
    };

    // 4-key PK 對應欄位（create 直接放 target.pk；edit 放 changes 以允許改 PK）。
    // c_bi_role_code 經後端 normalizeSentinelValues。
    const buildKeyFields = () => ({
        ...splitInstPair(),
        c_bi_role_code: numericKeyOrSentinel(form.c_bi_role_code),
    });

    // 非 PK 欄位。留空送 null 以允許清空（數值欄空送 null）。
    // Task 27：補回始/終年的年號/時限（c_bi_by_nh_code/nh_year/range、c_bi_ey_nh_code/nh_year/range），
    // tabSocialInstitutions 已 select+return，編輯態預填、保存不清空。
    const buildNonKeyChanges = () => ({
        c_bi_begin_year: form.c_bi_begin_year === '' ? null : Number(form.c_bi_begin_year),
        c_bi_by_nh_code: form.c_bi_by_nh_code === '' ? null : Number(form.c_bi_by_nh_code),
        c_bi_by_nh_year: form.c_bi_by_nh_year === '' ? null : Number(form.c_bi_by_nh_year),
        c_bi_by_range: form.c_bi_by_range === '' ? null : Number(form.c_bi_by_range),
        c_bi_end_year: form.c_bi_end_year === '' ? null : Number(form.c_bi_end_year),
        c_bi_ey_nh_code: form.c_bi_ey_nh_code === '' ? null : Number(form.c_bi_ey_nh_code),
        c_bi_ey_nh_year: form.c_bi_ey_nh_year === '' ? null : Number(form.c_bi_ey_nh_year),
        c_bi_ey_range: form.c_bi_ey_range === '' ? null : Number(form.c_bi_ey_range),
        c_source: form.c_source === '' ? null : Number(form.c_source),
        c_pages: form.c_pages || null,
        c_notes: form.c_notes || null,
    });

    const handleSubmit = async () => {
        setErrors({});
        setGlobalError(null);

        // 機構（c_inst_code-c_inst_name_code）必填。
        if (form.inst_pair === '') {
            setErrors({ c_inst_code: [t('required') ?? '必填'] });
            return;
        }
        // 機構角色（c_bi_role_code）必填。
        if (form.c_bi_role_code === '') {
            setErrors({ c_bi_role_code: [t('required') ?? '必填'] });
            return;
        }

        setSaving(true);

        const keyFields = buildKeyFields();
        const endpoint = mode === 'create' ? createEndpoint : mutateEndpoint;

        const body: Record<string, unknown> = {
            resource: 'social_institutions',
            person_id: personId,
            mode: proposalMode ? 'proposal' : 'direct',
        };

        if (mode === 'create') {
            // 4-key PK 全部由 client 提供（c_personid 帶當前人物）。
            body.target = { pk: { c_personid: personId, ...keyFields } };
            // create 需把 PK 欄位與非 PK 欄位一併放進 changes 以建立列。
            body.changes = { c_personid: personId, ...keyFields, ...buildNonKeyChanges() };
        } else {
            body.operation = 'update';
            body.target = { pk: row ? row.pk : { c_personid: personId, ...keyFields } };
            // 編輯模式允許修改 PK 欄位（機構與角色皆於表單顯示，後端泛型 handler 會檢查衝突）。
            body.changes = { ...keyFields, ...buildNonKeyChanges() };
        }

        if (proposalMode) {
            body.meta = { comment };
        }

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const json = await response.json().catch(() => ({}));

            if (!response.ok || !json?.ok) {
                if (json?.errors && typeof json.errors === 'object') {
                    setErrors(json.errors as FieldErrors);
                }
                setGlobalError(json?.message || `${t('save_failed')}（HTTP ${response.status}）`);
                return;
            }

            onSaved();
            if (proposalMode) {
                window.alert(t('proposal_submitted'));
            }
            onClose();
        } catch (err) {
            setGlobalError(err instanceof Error ? err.message : t('save_failed'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
            title={mode === 'create' ? t('social_institution_editor_create_title') : t('social_institution_editor_edit_title')}
            footer={
                <>
                    <Button variant="outline" disabled={saving} onClick={onClose}>
                        {t('cancel_btn')}
                    </Button>
                    <Button disabled={saving} onClick={() => void handleSubmit()}>
                        {proposalMode
                            ? (saving ? t('submitting_proposal') : t('submit_proposal_btn'))
                            : (saving ? t('saving') : t('save_btn'))}
                    </Button>
                </>
            }
        >
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12, maxHeight: '60vh', overflowY: 'auto' }}>
                {globalError ? (
                    <div role="alert" style={alertStyle}>
                        {globalError}
                    </div>
                ) : null}

                <FormField label={t('institution_label')} required error={errors.c_inst_code}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/socialinstcode"
                        value={form.inst_pair}
                        initialLabel={form.inst_pair_label}
                        placeholder={t('institution_search_placeholder')}
                        onChange={(v, label) => {
                            setField('inst_pair', v);
                            setField('inst_pair_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('role_label')} required error={errors.c_bi_role_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="birole"
                        idKey="c_bi_role_code"
                        labelKeys={['c_bi_role_chn', 'c_bi_role_desc']}
                        value={form.c_bi_role_code}
                        initialLabel={form.c_bi_role_code_label}
                        placeholder={t('role_search_placeholder')}
                        onChange={(v, label) => {
                            setField('c_bi_role_code', v === '-999' ? '0' : v);
                            setField('c_bi_role_code_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('first_year_label')} error={errors.c_bi_begin_year}>
                    <Input
                        type="number"
                        value={form.c_bi_begin_year}
                        onChange={(e) => setField('c_bi_begin_year', e.target.value)}
                    />
                </FormField>

                <FormField label={t('first_year_nianhao_label')} error={errors.c_bi_by_nh_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="nianhao"
                        idKey="c_nianhao_id"
                        labelKeys={['c_nianhao_chn']}
                        value={form.c_bi_by_nh_code}
                        initialLabel={form.c_bi_by_nh_code_label}
                        placeholder={t('nianhao_placeholder')}
                        onChange={(v, label) => {
                            setField('c_bi_by_nh_code', v === '-999' ? '0' : v);
                            setField('c_bi_by_nh_code_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('first_year_nianhao_year_label')} error={errors.c_bi_by_nh_year}>
                    <Input
                        type="number"
                        value={form.c_bi_by_nh_year}
                        onChange={(e) => setField('c_bi_by_nh_year', e.target.value)}
                    />
                </FormField>

                <FormField label={t('first_year_range_label')} error={errors.c_bi_by_range}>
                    <CodeAutocomplete
                        mode="list"
                        model="range"
                        idKey="c_range_code"
                        labelKeys={['c_range_chn']}
                        value={form.c_bi_by_range}
                        initialLabel={form.c_bi_by_range_label}
                        placeholder={t('year_range_placeholder')}
                        onChange={(v, label) => {
                            setField('c_bi_by_range', v);
                            setField('c_bi_by_range_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('last_year_label')} error={errors.c_bi_end_year}>
                    <Input
                        type="number"
                        value={form.c_bi_end_year}
                        onChange={(e) => setField('c_bi_end_year', e.target.value)}
                    />
                </FormField>

                <FormField label={t('last_year_nianhao_label')} error={errors.c_bi_ey_nh_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="nianhao"
                        idKey="c_nianhao_id"
                        labelKeys={['c_nianhao_chn']}
                        value={form.c_bi_ey_nh_code}
                        initialLabel={form.c_bi_ey_nh_code_label}
                        placeholder={t('nianhao_placeholder')}
                        onChange={(v, label) => {
                            setField('c_bi_ey_nh_code', v === '-999' ? '0' : v);
                            setField('c_bi_ey_nh_code_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('last_year_nianhao_year_label')} error={errors.c_bi_ey_nh_year}>
                    <Input
                        type="number"
                        value={form.c_bi_ey_nh_year}
                        onChange={(e) => setField('c_bi_ey_nh_year', e.target.value)}
                    />
                </FormField>

                <FormField label={t('last_year_range_label')} error={errors.c_bi_ey_range}>
                    <CodeAutocomplete
                        mode="list"
                        model="range"
                        idKey="c_range_code"
                        labelKeys={['c_range_chn']}
                        value={form.c_bi_ey_range}
                        initialLabel={form.c_bi_ey_range_label}
                        placeholder={t('year_range_placeholder')}
                        onChange={(v, label) => {
                            setField('c_bi_ey_range', v);
                            setField('c_bi_ey_range_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('source_label')} error={errors.c_source}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/text"
                        value={form.c_source}
                        initialLabel={form.c_source_label}
                        placeholder={t('source_search_placeholder')}
                        onChange={(v, label) => {
                            setField('c_source', v === '-999' ? '0' : v);
                            setField('c_source_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('pages_label')} error={errors.c_pages}>
                    <Input value={form.c_pages} onChange={(e) => setField('c_pages', e.target.value)} />
                </FormField>

                <FormField label={t('remarks')} error={errors.c_notes}>
                    <textarea
                        value={form.c_notes}
                        onChange={(e) => setField('c_notes', e.target.value)}
                        rows={4}
                        style={textareaStyle}
                    />
                </FormField>

                {proposalMode ? (
                    <FormField label={t('proposal_comment_label')}>
                        <textarea
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                            rows={3}
                            placeholder={t('proposal_comment_placeholder')}
                            style={textareaStyle}
                        />
                    </FormField>
                ) : null}
            </div>
        </Modal>
    );
}

const alertStyle: React.CSSProperties = {
    padding: '8px 12px',
    borderRadius: 6,
    backgroundColor: '#fdecec',
    border: '1px solid #f5c2c2',
    color: '#b42318',
    fontSize: '0.85rem',
};

const textareaStyle: React.CSSProperties = {
    width: '100%',
    borderRadius: 6,
    border: '1px solid #cbd5e1',
    padding: '8px 10px',
    fontSize: '0.875rem',
    boxSizing: 'border-box',
    resize: 'vertical',
};
