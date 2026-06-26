import React, { useEffect, useState } from 'react';
import { Modal } from '../ui/Modal';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import { FormField } from '../ui/FormField';
import CodeAutocomplete from './shared/CodeAutocomplete';
import { getCsrfToken } from './shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';

export interface EntryEditorRow {
    pk: {
        c_personid: number;
        c_entry_code: number | null;
        c_sequence: number | null;
        c_kin_code: number | null;
        c_assoc_code: number | null;
        c_kin_id: number | null;
        c_year: number | null;
        c_assoc_id: number | null;
        c_inst_code: number | null;
        c_inst_name_code: number | null;
    };
    sequence: number | null;
    entry_code: number | null;
    entry_desc_chn: string | null;
    entry_desc: string | null;
    year: number | null;
    kin_id: number | null;
    kin_summary: string | null;
    assoc_id: number | null;
    assoc_summary: string | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
    // Task 27 補回欄位（由 tabEntries select+return；防編輯態清空）。
    exam_rank: string | null;
    attempt_count: number | null;
    exam_field: string | null;
    parental_status: number | null;
    age: number | null;
    posting_notes: string | null;
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
    row?: EntryEditorRow | null;
    /** 入仕方式（c_entry_code）顯示文字，編輯模式初始顯示。 */
    entryCodeInitialLabel?: string | null;
    /** 出處（c_source）顯示文字，編輯模式初始顯示。 */
    sourceInitialLabel?: string | null;
    onClose: () => void;
    onSaved: () => void;
}

type FieldErrors = Record<string, string[]>;

interface FormState {
    // PK 欄位（除 c_personid 外）
    c_entry_code: string;
    c_entry_code_label: string;
    c_sequence: string;
    c_year: string;
    c_kin_code: string;
    c_kin_code_label: string;
    c_kin_id: string;
    c_kin_id_label: string;
    c_assoc_code: string;
    c_assoc_code_label: string;
    c_assoc_id: string;
    c_assoc_id_label: string;
    // 非 PK 欄位
    c_source: string;
    c_source_label: string;
    c_pages: string;
    c_notes: string;
    // Task 27 補回欄位
    c_exam_rank: string;
    c_attempt_count: string;
    c_exam_field: string;
    c_parental_status_code: string;
    c_parental_status_label: string;
    c_age: string;
    c_posting_notes: string;
}

function emptyState(): FormState {
    return {
        c_entry_code: '',
        c_entry_code_label: '',
        c_sequence: '',
        c_year: '',
        c_kin_code: '',
        c_kin_code_label: '',
        c_kin_id: '',
        c_kin_id_label: '',
        c_assoc_code: '',
        c_assoc_code_label: '',
        c_assoc_id: '',
        c_assoc_id_label: '',
        c_source: '',
        c_source_label: '',
        c_pages: '',
        c_notes: '',
        c_exam_rank: '',
        c_attempt_count: '',
        c_exam_field: '',
        c_parental_status_code: '',
        c_parental_status_label: '',
        c_age: '',
        c_posting_notes: '',
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
    row: EntryEditorRow,
    entryCodeLabel?: string | null,
    sourceLabel?: string | null,
): FormState {
    const entryCode = sentinelToBlank(row.pk.c_entry_code);

    return {
        c_entry_code: entryCode,
        c_entry_code_label: entryCodeLabel ?? (entryCode || ''),
        c_sequence: sentinelToBlank(row.pk.c_sequence),
        c_year: sentinelToBlank(row.pk.c_year),
        c_kin_code: sentinelToBlank(row.pk.c_kin_code),
        c_kin_code_label: sentinelToBlank(row.pk.c_kin_code),
        c_kin_id: sentinelToBlank(row.pk.c_kin_id),
        c_kin_id_label: row.kin_summary ?? sentinelToBlank(row.pk.c_kin_id),
        c_assoc_code: sentinelToBlank(row.pk.c_assoc_code),
        c_assoc_code_label: sentinelToBlank(row.pk.c_assoc_code),
        c_assoc_id: sentinelToBlank(row.pk.c_assoc_id),
        c_assoc_id_label: row.assoc_summary ?? sentinelToBlank(row.pk.c_assoc_id),
        c_source: sentinelToBlank(row.source_id),
        c_source_label: sourceLabel ?? sentinelToBlank(row.source_id),
        c_pages: row.pages ?? '',
        c_notes: row.notes ?? '',
        c_exam_rank: row.exam_rank ?? '',
        c_attempt_count: row.attempt_count == null ? '' : String(row.attempt_count),
        c_exam_field: row.exam_field ?? '',
        c_parental_status_code: sentinelToBlank(row.parental_status),
        c_parental_status_label: sentinelToBlank(row.parental_status),
        c_age: row.age == null ? '' : String(row.age),
        c_posting_notes: row.posting_notes ?? '',
    };
}

export default function EntryEditorModal({
    open,
    mode,
    proposalMode = false,
    personId,
    createEndpoint,
    mutateEndpoint,
    row,
    entryCodeInitialLabel,
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
            setForm(stateFromRow(row, entryCodeInitialLabel, sourceInitialLabel));
        } else {
            setForm(emptyState());
        }
    }, [open, mode, row, entryCodeInitialLabel, sourceInitialLabel]);

    const setField = (key: keyof FormState, value: string) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    // 數值 PK 欄位：留空＝未詳，送 -999 由後端 normalizeSentinelValues 轉成 '0'。
    // 注意（P4-6 教訓）：空 PK 數值欄務必送 -999，不可送 ''——'' 經 middleware 轉 null。
    const numericKeyOrSentinel = (raw: string): number => (raw === '' ? -999 : Number(raw));

    // 表單可見的 PK 欄位（c_year/c_kin_id/c_assoc_id 為 PK 但無哨兵清單，空送 0 維持非空 PK）。
    const buildExposedKeyFields = () => ({
        c_entry_code: numericKeyOrSentinel(form.c_entry_code),
        c_sequence: form.c_sequence === '' ? 0 : Number(form.c_sequence),
        c_kin_code: numericKeyOrSentinel(form.c_kin_code),
        c_assoc_code: numericKeyOrSentinel(form.c_assoc_code),
        c_kin_id: form.c_kin_id === '' ? 0 : Number(form.c_kin_id),
        c_year: form.c_year === '' ? 0 : Number(form.c_year),
        c_assoc_id: form.c_assoc_id === '' ? 0 : Number(form.c_assoc_id),
    });

    // 編輯器未顯示的 PK 欄位，僅 create 需要（新列需完整 10-key PK；c_inst_code 哨兵→0、c_inst_name_code 0）。
    // ⚠️ 編輯模式**不可**送這些欄：它們是 PK，且既有列可能為非零值；
    //    若無條件送哨兵 0 會把既有 institution PK 改成 0,0（資料損毀）。edit 時由 target.pk(row.pk)
    //    經後端 buildNewPk 保留原值。
    const buildHiddenKeyFieldsForCreate = () => ({
        c_inst_code: numericKeyOrSentinel(''),
        c_inst_name_code: 0,
    });

    // 非 PK 欄位。留空送 null 以允許清空（c_source 為數值欄，空送 null）。
    // c_entry_addr_id/c_entry_nh_year/c_entry_range 仍暫不納入，update 時不送即不被清空。
    // Task 27 補回：c_exam_rank/c_attempt_count/c_exam_field/c_parental_status_code/c_age/c_posting_notes。
    const buildNonKeyChanges = () => ({
        c_source: form.c_source === '' ? null : Number(form.c_source),
        c_pages: form.c_pages || null,
        c_notes: form.c_notes || null,
        c_exam_rank: form.c_exam_rank || null,
        c_attempt_count: form.c_attempt_count === '' ? null : Number(form.c_attempt_count),
        c_exam_field: form.c_exam_field || null,
        c_parental_status_code: form.c_parental_status_code === '' ? null : Number(form.c_parental_status_code),
        c_age: form.c_age === '' ? null : Number(form.c_age),
        c_posting_notes: form.c_posting_notes || null,
    });

    const handleSubmit = async () => {
        setErrors({});
        setGlobalError(null);

        // 入仕方式（c_entry_code）必填。
        if (form.c_entry_code === '') {
            setErrors({ c_entry_code: [t('required') ?? '必填'] });
            return;
        }

        setSaving(true);

        const exposedKeys = buildExposedKeyFields();
        const endpoint = mode === 'create' ? createEndpoint : mutateEndpoint;

        const body: Record<string, unknown> = {
            resource: 'entries',
            person_id: personId,
            mode: proposalMode ? 'proposal' : 'direct',
        };
        if (proposalMode) {
            body.meta = { comment };
        }

        if (mode === 'create') {
            // 10-key PK 全部由 client 提供（c_personid + 可見 PK + 隱藏 PK 哨兵）。
            const fullKeys = { c_personid: personId, ...exposedKeys, ...buildHiddenKeyFieldsForCreate() };
            body.target = { pk: fullKeys };
            body.changes = { ...fullKeys, ...buildNonKeyChanges() };
        } else {
            body.operation = 'update';
            // target.pk = 原始完整 PK（含隱藏 institution 鍵）以正確定位記錄。
            body.target = { pk: row ? row.pk : { c_personid: personId, ...exposedKeys } };
            // changes 只送可見 PK + 非 PK；**不送隱藏 PK 欄**（c_inst_code/c_inst_name_code），
            // 後端 buildNewPk 會以 target.pk 保留其原值，避免把既有 institution PK 改成 0。
            body.changes = { ...exposedKeys, ...buildNonKeyChanges() };
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
            title={mode === 'create' ? t('entry_editor_create_title') : t('entry_editor_edit_title')}
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

                <FormField label={t('entry_type_label')} required error={errors.c_entry_code}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/entry"
                        value={form.c_entry_code}
                        initialLabel={form.c_entry_code_label}
                        placeholder={t('entry_code_placeholder')}
                        onChange={(v, label) => {
                            // searchEntry 以 -999 代表「未詳」(0)；後端 handler 會再轉回 0。
                            setField('c_entry_code', v === '-999' ? '0' : v);
                            setField('c_entry_code_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('seq_no')} error={errors.c_sequence}>
                    <Input
                        type="number"
                        value={form.c_sequence}
                        onChange={(e) => setField('c_sequence', e.target.value)}
                    />
                </FormField>

                <FormField label={t('year_label')} error={errors.c_year}>
                    <Input type="number" value={form.c_year} onChange={(e) => setField('c_year', e.target.value)} />
                </FormField>

                <FormField label={t('kin_relation')} error={errors.c_kin_code}>
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

                <FormField label={t('kin_person')} error={errors.c_kin_id}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/biog"
                        value={form.c_kin_id}
                        initialLabel={form.c_kin_id_label}
                        placeholder={t('person_search_placeholder')}
                        onChange={(v, label) => {
                            setField('c_kin_id', v === '-999' ? '' : v);
                            setField('c_kin_id_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('social_relation')} error={errors.c_assoc_code}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/assoccode"
                        value={form.c_assoc_code}
                        initialLabel={form.c_assoc_code_label}
                        placeholder={t('assoc_code_placeholder')}
                        onChange={(v, label) => {
                            setField('c_assoc_code', v);
                            setField('c_assoc_code_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('related_person')} error={errors.c_assoc_id}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/biog"
                        value={form.c_assoc_id}
                        initialLabel={form.c_assoc_id_label}
                        placeholder={t('person_search_placeholder')}
                        onChange={(v, label) => {
                            setField('c_assoc_id', v === '-999' ? '' : v);
                            setField('c_assoc_id_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('entry_exam_rank_label')} error={errors.c_exam_rank}>
                    <Input value={form.c_exam_rank} onChange={(e) => setField('c_exam_rank', e.target.value)} />
                </FormField>

                <FormField label={t('entry_attempt_count_label')} error={errors.c_attempt_count}>
                    <Input type="number" value={form.c_attempt_count} onChange={(e) => setField('c_attempt_count', e.target.value)} />
                </FormField>

                <FormField label={t('entry_exam_field_label')} error={errors.c_exam_field}>
                    <Input value={form.c_exam_field} onChange={(e) => setField('c_exam_field', e.target.value)} />
                </FormField>

                <FormField label={t('entry_parental_status_label')} error={errors.c_parental_status_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="parentstatus"
                        idKey="c_parental_status_code"
                        labelKeys={['c_parental_status_code', 'c_parental_status_desc_chn', 'c_parental_status_desc']}
                        value={form.c_parental_status_code}
                        initialLabel={form.c_parental_status_label}
                        placeholder={t('entry_parental_status_placeholder')}
                        onChange={(v, label) => {
                            setField('c_parental_status_code', v);
                            setField('c_parental_status_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('entry_age_label')} error={errors.c_age}>
                    <Input type="number" value={form.c_age} onChange={(e) => setField('c_age', e.target.value)} />
                </FormField>

                <FormField label={t('entry_posting_notes_label')} error={errors.c_posting_notes}>
                    <Input value={form.c_posting_notes} onChange={(e) => setField('c_posting_notes', e.target.value)} />
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
