import React, { useEffect, useState } from 'react';
import { Modal } from '../ui/Modal';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import { FormField } from '../ui/FormField';
import CodeAutocomplete from './shared/CodeAutocomplete';
import { getCsrfToken } from './shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';

export interface KinshipEditorRow {
    pk: {
        c_personid: number;
        c_kin_id: number | null;
        c_kin_code: number | null;
    };
    kin_code: number | null;
    relation_chn: string | null;
    relation: string | null;
    kin_person_id: number | null;
    kin_person_name_chn: string | null;
    kin_person_name: string | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
    autogen_notes: string | null;
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
    row?: KinshipEditorRow | null;
    /** 親屬類型（c_kin_code）顯示文字，編輯模式初始顯示。 */
    kinCodeInitialLabel?: string | null;
    /** 親屬人物（c_kin_id）顯示文字，編輯模式初始顯示。 */
    kinPersonInitialLabel?: string | null;
    /** 出處（c_source）顯示文字，編輯模式初始顯示。 */
    sourceInitialLabel?: string | null;
    onClose: () => void;
    onSaved: () => void;
}

type FieldErrors = Record<string, string[]>;

interface FormState {
    // PK 欄位（除 c_personid 外）
    c_kin_id: string;
    c_kin_id_label: string;
    c_kin_code: string;
    c_kin_code_label: string;
    // 非 PK 欄位
    c_source: string;
    c_source_label: string;
    c_pages: string;
    c_notes: string;
    c_autogen_notes: string;
}

function emptyState(): FormState {
    return {
        c_kin_id: '',
        c_kin_id_label: '',
        c_kin_code: '',
        c_kin_code_label: '',
        c_source: '',
        c_source_label: '',
        c_pages: '',
        c_notes: '',
        c_autogen_notes: '',
    };
}

// 將代碼/人物欄位的哨兵值（0 / -999）視為「未詳」，編輯時顯示空白而非 0。
function sentinelToBlank(value: number | null | undefined): string {
    if (value == null || value === 0 || value === -999) {
        return '';
    }
    return String(value);
}

function stateFromRow(
    row: KinshipEditorRow,
    kinCodeLabel?: string | null,
    kinPersonLabel?: string | null,
    sourceLabel?: string | null,
): FormState {
    const kinId = sentinelToBlank(row.pk.c_kin_id);
    const kinCode = sentinelToBlank(row.pk.c_kin_code);

    return {
        c_kin_id: kinId,
        c_kin_id_label: kinPersonLabel ?? (kinId || ''),
        c_kin_code: kinCode,
        c_kin_code_label: kinCodeLabel ?? (kinCode || ''),
        c_source: sentinelToBlank(row.source_id),
        c_source_label: sourceLabel ?? sentinelToBlank(row.source_id),
        c_pages: row.pages ?? '',
        c_notes: row.notes ?? '',
        c_autogen_notes: row.autogen_notes ?? '',
    };
}

export default function KinshipEditorModal({
    open,
    mode,
    proposalMode = false,
    personId,
    createEndpoint,
    mutateEndpoint,
    row,
    kinCodeInitialLabel,
    kinPersonInitialLabel,
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
            setForm(stateFromRow(row, kinCodeInitialLabel, kinPersonInitialLabel, sourceInitialLabel));
        } else {
            setForm(emptyState());
        }
    }, [open, mode, row, kinCodeInitialLabel, kinPersonInitialLabel, sourceInitialLabel]);

    const setField = (key: keyof FormState, value: string) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    // 數值 PK 欄位：留空＝未詳，送 -999 由後端 normalizeSentinelValues 轉成 '0'。
    // 注意（P4-6 教訓）：空 PK 數值欄務必送 -999，不可送 ''——'' 經 middleware 轉 null，
    // normalizeSentinelValues 不處理 null，可能寫成 0 或出錯。
    const numericKeyOrSentinel = (raw: string): number => (raw === '' ? -999 : Number(raw));

    // 3-key PK 對應欄位（create 直接放 target.pk；edit 放 changes 以允許改 PK）。
    const buildKeyFields = () => ({
        c_kin_id: numericKeyOrSentinel(form.c_kin_id),
        c_kin_code: numericKeyOrSentinel(form.c_kin_code),
    });

    // 非 PK 欄位。留空送 null 以允許清空（c_source 為數值欄，空送 null）。
    // 未納入此處的 allowedField（c_supplement）update 時不送即不被清空。
    const buildNonKeyChanges = () => ({
        c_source: form.c_source === '' ? null : Number(form.c_source),
        c_pages: form.c_pages || null,
        c_notes: form.c_notes || null,
        c_autogen_notes: form.c_autogen_notes || null,
    });

    const handleSubmit = async () => {
        setErrors({});
        setGlobalError(null);

        // 親屬人物（c_kin_id）必填。
        if (form.c_kin_id === '') {
            setErrors({ c_kin_id: [t('required') ?? '必填'] });
            return;
        }
        // 親屬關係類型（c_kin_code）必填。
        if (form.c_kin_code === '') {
            setErrors({ c_kin_code: [t('required') ?? '必填'] });
            return;
        }

        setSaving(true);

        const keyFields = buildKeyFields();
        const endpoint = mode === 'create' ? createEndpoint : mutateEndpoint;

        const body: Record<string, unknown> = {
            resource: 'kinship',
            person_id: personId,
            mode: proposalMode ? 'proposal' : 'direct',
        };

        if (mode === 'create') {
            // 3-key PK 全部由 client 提供（c_personid 帶當前人物）。
            body.target = { pk: { c_personid: personId, ...keyFields } };
            // create 需把 PK 欄位與非 PK 欄位一併放進 changes 以建立列。
            body.changes = { c_personid: personId, ...keyFields, ...buildNonKeyChanges() };
        } else {
            body.operation = 'update';
            body.target = { pk: row ? row.pk : { c_personid: personId, ...keyFields } };
            // 編輯模式允許修改 PK 欄位（後端泛型 handler 會檢查衝突）。
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
            title={mode === 'create' ? t('kinship_editor_create_title') : t('kinship_editor_edit_title')}
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

                <FormField label={t('kin_relation_label')} required error={errors.c_kin_code}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/kincode"
                        value={form.c_kin_code}
                        initialLabel={form.c_kin_code_label}
                        placeholder={t('kin_code_placeholder')}
                        onChange={(v, label) => {
                            setField('c_kin_code', v);
                            setField('c_kin_code_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('kin_person')} required error={errors.c_kin_id}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/biog"
                        value={form.c_kin_id}
                        initialLabel={form.c_kin_id_label}
                        placeholder={t('person_search_placeholder')}
                        onChange={(v, label) => {
                            setField('c_kin_id', v);
                            setField('c_kin_id_label', label);
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

                {/* Task 27 補回：c_autogen_notes（KIN_DATA 真實欄；舊表單以 textarea 暴露為可錄入）。 */}
                <FormField label={t('autogen_notes_label')} error={errors.c_autogen_notes}>
                    <textarea
                        value={form.c_autogen_notes}
                        onChange={(e) => setField('c_autogen_notes', e.target.value)}
                        rows={3}
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
