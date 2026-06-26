import React, { useEffect, useState } from 'react';
import { Modal } from '../ui/Modal';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import { FormField } from '../ui/FormField';
import CodeAutocomplete from './shared/CodeAutocomplete';
import { getCsrfToken } from './shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';

export interface AssociationEditorRow {
    pk: {
        c_personid: number;
        c_assoc_code: number | null;
        c_assoc_id: number | null;
        c_kin_code: number | null;
        c_kin_id: number | null;
        c_assoc_kin_code: number | null;
        c_assoc_kin_id: number | null;
        c_text_title: string | null;
        c_assoc_first_year: number | null;
    };
    assoc_code: number | null;
    assoc_desc_chn: string | null;
    assoc_desc: string | null;
    assoc_person_id: number | null;
    assoc_person_name_chn: string | null;
    assoc_person_name: string | null;
    first_year: number | null;
    last_year: number | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
    // Task 27：補回舊表單可錄入欄位。
    topic_code: number | null;
    occasion_code: number | null;
    tertiary_personid: number | null;
    tertiary_type_notes: string | null;
    assoc_claimer_id: number | null;
    addr_id: number | null;
    inst_code: number | null;
    inst_name_code: number | null;
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
    row?: AssociationEditorRow | null;
    /** 社會關係類型（c_assoc_code）顯示文字，編輯模式初始顯示。 */
    assocCodeInitialLabel?: string | null;
    /** 關係對象人物（c_assoc_id）顯示文字，編輯模式初始顯示。 */
    assocPersonInitialLabel?: string | null;
    /** 出處（c_source）顯示文字，編輯模式初始顯示。 */
    sourceInitialLabel?: string | null;
    onClose: () => void;
    onSaved: () => void;
}

type FieldErrors = Record<string, string[]>;

interface FormState {
    // 9-key PK 中與編輯器相關的欄位
    c_assoc_code: string;
    c_assoc_code_label: string;
    c_assoc_id: string;
    c_assoc_id_label: string;
    c_kin_code: string;
    c_kin_code_label: string;
    c_kin_id: string;
    c_kin_id_label: string;
    c_assoc_kin_code: string;
    c_assoc_kin_code_label: string;
    c_assoc_kin_id: string;
    c_assoc_kin_id_label: string;
    c_text_title: string;
    c_assoc_first_year: string;
    // 非 PK 欄位
    c_assoc_last_year: string;
    c_source: string;
    c_source_label: string;
    c_pages: string;
    c_notes: string;
    // Task 27：補回欄位
    c_topic_code: string;
    c_occasion_code: string;
    c_tertiary_personid: string;
    c_tertiary_personid_label: string;
    c_tertiary_type_notes: string;
    c_assoc_claimer_id: string;
    c_assoc_claimer_id_label: string;
    c_addr_id: string;
    c_addr_id_label: string;
    // c_inst_code 以「c_inst_code-c_inst_name_code」組合值表達（與 legacy 一致，存檔時拆分）。
    c_inst_code: string;
    c_inst_code_label: string;
}

function emptyState(): FormState {
    return {
        c_assoc_code: '',
        c_assoc_code_label: '',
        c_assoc_id: '',
        c_assoc_id_label: '',
        c_kin_code: '',
        c_kin_code_label: '',
        c_kin_id: '',
        c_kin_id_label: '',
        c_assoc_kin_code: '',
        c_assoc_kin_code_label: '',
        c_assoc_kin_id: '',
        c_assoc_kin_id_label: '',
        c_text_title: '',
        c_assoc_first_year: '',
        c_assoc_last_year: '',
        c_source: '',
        c_source_label: '',
        c_pages: '',
        c_notes: '',
        c_topic_code: '',
        c_occasion_code: '',
        c_tertiary_personid: '',
        c_tertiary_personid_label: '',
        c_tertiary_type_notes: '',
        c_assoc_claimer_id: '',
        c_assoc_claimer_id_label: '',
        c_addr_id: '',
        c_addr_id_label: '',
        c_inst_code: '',
        c_inst_code_label: '',
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
    row: AssociationEditorRow,
    assocCodeLabel?: string | null,
    assocPersonLabel?: string | null,
    sourceLabel?: string | null,
): FormState {
    const assocCode = sentinelToBlank(row.pk.c_assoc_code);
    const assocId = sentinelToBlank(row.pk.c_assoc_id);
    const kinCode = sentinelToBlank(row.pk.c_kin_code);
    const kinId = sentinelToBlank(row.pk.c_kin_id);
    const assocKinCode = sentinelToBlank(row.pk.c_assoc_kin_code);
    const assocKinId = sentinelToBlank(row.pk.c_assoc_kin_id);
    // c_text_title 以 '[n/a]' 為未知哨兵，編輯時顯示空白。
    const textTitle = row.pk.c_text_title != null && row.pk.c_text_title !== '[n/a]' ? row.pk.c_text_title : '';
    // c_assoc_first_year 以 -9999 為未知哨兵。
    const firstYear =
        row.pk.c_assoc_first_year != null && row.pk.c_assoc_first_year !== -9999 ? String(row.pk.c_assoc_first_year) : '';

    return {
        c_assoc_code: assocCode,
        c_assoc_code_label: assocCodeLabel ?? (assocCode || ''),
        c_assoc_id: assocId,
        c_assoc_id_label: assocPersonLabel ?? (assocId || ''),
        c_kin_code: kinCode,
        c_kin_code_label: kinCode || '',
        c_kin_id: kinId,
        c_kin_id_label: kinId || '',
        c_assoc_kin_code: assocKinCode,
        c_assoc_kin_code_label: assocKinCode || '',
        c_assoc_kin_id: assocKinId,
        c_assoc_kin_id_label: assocKinId || '',
        c_text_title: textTitle,
        c_assoc_first_year: firstYear,
        c_assoc_last_year: row.last_year != null && row.last_year !== -9999 ? String(row.last_year) : '',
        c_source: sentinelToBlank(row.source_id),
        c_source_label: sourceLabel ?? sentinelToBlank(row.source_id),
        c_pages: row.pages ?? '',
        c_notes: row.notes ?? '',
        c_topic_code: sentinelToBlank(row.topic_code),
        c_occasion_code: sentinelToBlank(row.occasion_code),
        c_tertiary_personid: sentinelToBlank(row.tertiary_personid),
        c_tertiary_personid_label: sentinelToBlank(row.tertiary_personid),
        c_tertiary_type_notes: row.tertiary_type_notes ?? '',
        c_assoc_claimer_id: sentinelToBlank(row.assoc_claimer_id),
        c_assoc_claimer_id_label: sentinelToBlank(row.assoc_claimer_id),
        c_addr_id: sentinelToBlank(row.addr_id),
        c_addr_id_label: sentinelToBlank(row.addr_id),
        // c_inst_code / c_inst_name_code 皆 0/null 視為未填；否則組成 legacy 的「code-namecode」值。
        c_inst_code: composeInstValue(row.inst_code, row.inst_name_code),
        c_inst_code_label: composeInstValue(row.inst_code, row.inst_name_code),
    };
}

// 將 c_inst_code / c_inst_name_code 組成 legacy socialinstcode 搜尋控件的「code-namecode」值。
function composeInstValue(instCode: number | null | undefined, instNameCode: number | null | undefined): string {
    const code = instCode ?? 0;
    const nameCode = instNameCode ?? 0;
    if (code === 0 && nameCode === 0) {
        return '';
    }
    return `${code}-${nameCode}`;
}

export default function AssociationEditorModal({
    open,
    mode,
    proposalMode = false,
    personId,
    createEndpoint,
    mutateEndpoint,
    row,
    assocCodeInitialLabel,
    assocPersonInitialLabel,
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
            setForm(stateFromRow(row, assocCodeInitialLabel, assocPersonInitialLabel, sourceInitialLabel));
        } else {
            setForm(emptyState());
        }
    }, [open, mode, row, assocCodeInitialLabel, assocPersonInitialLabel, sourceInitialLabel]);

    const setField = (key: keyof FormState, value: string) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    // 數值關聯欄位：留空＝未詳，送 -999 由後端 emptyToSentinel 轉成 '0'。
    const numericKeyOrSentinel = (raw: string): number => (raw === '' ? -999 : Number(raw));

    // 組裝 9-key PK 對應欄位（create 直接放 target.pk；edit 放 changes 以允許改 PK）。
    const buildKeyFields = () => ({
        c_assoc_code: numericKeyOrSentinel(form.c_assoc_code),
        c_assoc_id: numericKeyOrSentinel(form.c_assoc_id),
        c_kin_code: numericKeyOrSentinel(form.c_kin_code),
        c_kin_id: numericKeyOrSentinel(form.c_kin_id),
        c_assoc_kin_code: numericKeyOrSentinel(form.c_assoc_kin_code),
        c_assoc_kin_id: numericKeyOrSentinel(form.c_assoc_kin_id),
        // c_text_title 空送 ''，後端轉 '[n/a]'。
        c_text_title: form.c_text_title,
        // c_assoc_first_year 空送 ''，後端轉 '-9999'。
        c_assoc_first_year: form.c_assoc_first_year === '' ? '' : Number(form.c_assoc_first_year),
    });

    // c_inst_code 以「code-namecode」組合值表達；拆分回兩欄（皆 NOT NULL DEFAULT 0，空送 0）。
    const splitInst = (): { c_inst_code: number; c_inst_name_code: number } => {
        if (form.c_inst_code === '') {
            return { c_inst_code: 0, c_inst_name_code: 0 };
        }
        const parts = form.c_inst_code.split('-');
        return {
            c_inst_code: Number(parts[0] || 0) || 0,
            c_inst_name_code: Number(parts[1] || 0) || 0,
        };
    };

    // 數值關聯欄位空送 null（nullable 欄可清空）。
    const numOrNull = (raw: string): number | null => (raw === '' ? null : Number(raw));

    // 非 PK 欄位。留空送 null 以允許清空（c_assoc_last_year/c_source 為數值欄，空送 null）。
    // 未納入此處的 allowedField（c_sequence/c_assoc_count）update 時不送即不被清空。
    const buildNonKeyChanges = () => ({
        c_assoc_last_year: form.c_assoc_last_year === '' ? null : Number(form.c_assoc_last_year),
        c_source: form.c_source === '' ? null : Number(form.c_source),
        c_pages: form.c_pages || null,
        c_notes: form.c_notes || null,
        // Task 27 補回欄位（nullable 欄空送 null；inst 兩欄空送 0）。
        c_topic_code: numOrNull(form.c_topic_code),
        c_occasion_code: numOrNull(form.c_occasion_code),
        c_tertiary_personid: numOrNull(form.c_tertiary_personid),
        c_tertiary_type_notes: form.c_tertiary_type_notes || null,
        c_assoc_claimer_id: numOrNull(form.c_assoc_claimer_id),
        c_addr_id: numOrNull(form.c_addr_id),
        ...splitInst(),
    });

    const handleSubmit = async () => {
        setErrors({});
        setGlobalError(null);

        // 關係對象人物（c_assoc_id）必填。
        if (form.c_assoc_id === '') {
            setErrors({ c_assoc_id: [t('required') ?? '必填'] });
            return;
        }

        setSaving(true);

        const keyFields = buildKeyFields();
        const endpoint = mode === 'create' ? createEndpoint : mutateEndpoint;

        const body: Record<string, unknown> = {
            resource: 'associations',
            person_id: personId,
            mode: proposalMode ? 'proposal' : 'direct',
        };

        if (proposalMode) {
            body.meta = { comment };
        }

        if (mode === 'create') {
            // 9-key PK 全部由 client 提供（c_personid 帶當前人物）。
            body.target = { pk: { c_personid: personId, ...keyFields } };
            // create 需把 PK 欄位與非 PK 欄位一併放進 changes 以建立列。
            body.changes = { c_personid: personId, ...keyFields, ...buildNonKeyChanges() };
        } else {
            body.operation = 'update';
            body.target = { pk: row ? row.pk : { c_personid: personId, ...keyFields } };
            // 編輯模式允許修改 PK 欄位（後端泛型 handler 會檢查衝突）。
            body.changes = { ...keyFields, ...buildNonKeyChanges() };
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
            title={mode === 'create' ? t('assoc_editor_create_title') : t('assoc_editor_edit_title')}
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

                <FormField label={t('relation')} error={errors.c_assoc_code}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/assoccode"
                        value={form.c_assoc_code}
                        initialLabel={form.c_assoc_code_label}
                        placeholder={t('assoc_code_placeholder')}
                        onChange={(v, label) => {
                            // assoccode 以 -999 代表「未詳」(0)；留待後端轉哨兵。
                            setField('c_assoc_code', v);
                            setField('c_assoc_code_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('related_person')} required error={errors.c_assoc_id}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/biog"
                        value={form.c_assoc_id}
                        initialLabel={form.c_assoc_id_label}
                        placeholder={t('person_search_placeholder')}
                        onChange={(v, label) => {
                            setField('c_assoc_id', v);
                            setField('c_assoc_id_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('assoc_kin_relation')} error={errors.c_kin_code}>
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

                <FormField label={t('assoc_kin_person')} error={errors.c_kin_id}>
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

                <FormField label={t('assoc_assoc_kin_relation')} error={errors.c_assoc_kin_code}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/kincode"
                        value={form.c_assoc_kin_code}
                        initialLabel={form.c_assoc_kin_code_label}
                        placeholder={t('kin_code_placeholder')}
                        onChange={(v, label) => {
                            setField('c_assoc_kin_code', v);
                            setField('c_assoc_kin_code_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('assoc_assoc_kin_person')} error={errors.c_assoc_kin_id}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/biog"
                        value={form.c_assoc_kin_id}
                        initialLabel={form.c_assoc_kin_id_label}
                        placeholder={t('person_search_placeholder')}
                        onChange={(v, label) => {
                            setField('c_assoc_kin_id', v);
                            setField('c_assoc_kin_id_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('assoc_text_title')} error={errors.c_text_title}>
                    <Input value={form.c_text_title} onChange={(e) => setField('c_text_title', e.target.value)} />
                </FormField>

                <FormField label={t('start_year_label')} error={errors.c_assoc_first_year}>
                    <Input
                        type="number"
                        value={form.c_assoc_first_year}
                        onChange={(e) => setField('c_assoc_first_year', e.target.value)}
                    />
                </FormField>

                <FormField label={t('end_year_label')} error={errors.c_assoc_last_year}>
                    <Input
                        type="number"
                        value={form.c_assoc_last_year}
                        onChange={(e) => setField('c_assoc_last_year', e.target.value)}
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

                {/* Task 27：補回舊表單可錄入欄位（主題/場合/第三方人/類型/聲稱者/地點/機構）。 */}
                <FormField label={t('assoc_topic_label')} error={errors.c_topic_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="topic"
                        idKey="c_topic_code"
                        labelKeys={['c_topic_code', 'c_topic_desc_chn', 'c_topic_desc']}
                        value={form.c_topic_code}
                        onChange={(v) => setField('c_topic_code', v)}
                    />
                </FormField>

                <FormField label={t('assoc_occasion_label')} error={errors.c_occasion_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="occasion"
                        idKey="c_occasion_code"
                        labelKeys={['c_occasion_code', 'c_occasion_desc_chn', 'c_occasion_desc']}
                        value={form.c_occasion_code}
                        onChange={(v) => setField('c_occasion_code', v)}
                    />
                </FormField>

                <FormField label={t('assoc_intermediary_label')} error={errors.c_tertiary_personid}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/biog"
                        value={form.c_tertiary_personid}
                        initialLabel={form.c_tertiary_personid_label}
                        placeholder={t('person_search_placeholder')}
                        onChange={(v, label) => {
                            setField('c_tertiary_personid', v === '-999' ? '' : v);
                            setField('c_tertiary_personid_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('assoc_intermediary_type_label')} error={errors.c_tertiary_type_notes}>
                    <Input
                        value={form.c_tertiary_type_notes}
                        onChange={(e) => setField('c_tertiary_type_notes', e.target.value)}
                    />
                </FormField>

                <FormField label={t('assoc_witness_label')} error={errors.c_assoc_claimer_id}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/biog"
                        value={form.c_assoc_claimer_id}
                        initialLabel={form.c_assoc_claimer_id_label}
                        placeholder={t('person_search_placeholder')}
                        onChange={(v, label) => {
                            setField('c_assoc_claimer_id', v === '-999' ? '' : v);
                            setField('c_assoc_claimer_id_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('assoc_location_label')} error={errors.c_addr_id}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/addr"
                        value={form.c_addr_id}
                        initialLabel={form.c_addr_id_label}
                        placeholder={t('addr_search_placeholder')}
                        onChange={(v, label) => {
                            setField('c_addr_id', v === '-999' ? '' : v);
                            setField('c_addr_id_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('assoc_institution_label')} error={errors.c_inst_code}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/socialinstcode"
                        value={form.c_inst_code}
                        initialLabel={form.c_inst_code_label}
                        placeholder={t('inst_search_placeholder')}
                        onChange={(v, label) => {
                            setField('c_inst_code', v);
                            setField('c_inst_code_label', label);
                        }}
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
