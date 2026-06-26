import React, { useEffect, useState } from 'react';
import { Modal } from '../ui/Modal';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import { FormField } from '../ui/FormField';
import CodeAutocomplete from './shared/CodeAutocomplete';
import { getCsrfToken } from './shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';

export interface PostingEditorRow {
    pk: {
        c_office_id: number | null;
        c_posting_id: number | null;
    };
    sequence: number | null;
    office_id: number | null;
    office_chn: string | null;
    office: string | null;
    first_year: number | null;
    last_year: number | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
    appt_code?: number | null;
    appt_chn?: string | null;
    appt?: string | null;
    // Task 27 補回欄位
    assume_office_code?: number | null;
    dy?: number | null;
    inst_code?: number | null;
    inst_name_code?: number | null;
    office_category_id?: number | null;
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

interface Props {
    open: boolean;
    mode: 'create' | 'edit';
    /** true 時送出提案（mode:'proposal'）而非直接寫入。 */
    proposalMode?: boolean;
    personId: number;
    createEndpoint: string;
    mutateEndpoint: string;
    /** 編輯模式下的既有資料。 */
    row?: PostingEditorRow | null;
    /** 官職（c_office_id）顯示文字，編輯模式用於初始顯示。 */
    officeInitialLabel?: string | null;
    /** 出處（c_source）顯示文字，編輯模式用於初始顯示。 */
    sourceInitialLabel?: string | null;
    onClose: () => void;
    onSaved: () => void;
}

type FieldErrors = Record<string, string[]>;

interface FormState {
    c_office_id: string;
    c_office_label: string;
    c_sequence: string;
    c_firstyear: string;
    c_lastyear: string;
    c_appt_code: string;
    c_appt_label: string;
    c_source: string;
    c_source_label: string;
    c_pages: string;
    c_notes: string;
    // Task 27 補回欄位
    c_assume_office_code: string;
    c_assume_office_label: string;
    c_dy: string;
    c_dy_label: string;
    c_office_category_id: string;
    c_office_category_label: string;
    // 機構以「code-namecode」組合值（與 legacy/assoc 一致），存檔時拆分。
    c_inst_code: string;
    c_inst_code_label: string;
}

function emptyState(): FormState {
    return {
        c_office_id: '',
        c_office_label: '',
        c_sequence: '0',
        c_firstyear: '',
        c_lastyear: '',
        c_appt_code: '',
        c_appt_label: '',
        c_source: '',
        c_source_label: '',
        c_pages: '',
        c_notes: '',
        c_assume_office_code: '',
        c_assume_office_label: '',
        c_dy: '',
        c_dy_label: '',
        c_office_category_id: '',
        c_office_category_label: '',
        c_inst_code: '',
        c_inst_code_label: '',
    };
}

function stateFromRow(row: PostingEditorRow, officeLabel?: string | null, sourceLabel?: string | null): FormState {
    return {
        c_office_id: row.office_id != null ? String(row.office_id) : '',
        c_office_label:
            officeLabel ?? row.office_chn ?? row.office ?? (row.office_id != null ? String(row.office_id) : ''),
        c_sequence: row.sequence != null ? String(row.sequence) : '0',
        c_firstyear: row.first_year != null ? String(row.first_year) : '',
        c_lastyear: row.last_year != null ? String(row.last_year) : '',
        c_appt_code: row.appt_code != null ? String(row.appt_code) : '',
        c_appt_label: row.appt_chn ?? row.appt ?? (row.appt_code != null ? String(row.appt_code) : ''),
        c_source: row.source_id != null ? String(row.source_id) : '',
        c_source_label: sourceLabel ?? (row.source_id != null ? String(row.source_id) : ''),
        c_pages: row.pages ?? '',
        c_notes: row.notes ?? '',
        // Task 27 補回欄位（list 型由 CodeAutocomplete 依值解析標籤；inst 為組合值）
        c_assume_office_code: row.assume_office_code != null ? String(row.assume_office_code) : '',
        c_assume_office_label: row.assume_office_code != null ? String(row.assume_office_code) : '',
        c_dy: row.dy != null ? String(row.dy) : '',
        c_dy_label: row.dy != null ? String(row.dy) : '',
        c_office_category_id: row.office_category_id != null ? String(row.office_category_id) : '',
        c_office_category_label: row.office_category_id != null ? String(row.office_category_id) : '',
        c_inst_code: composeInstValue(row.inst_code, row.inst_name_code),
        c_inst_code_label: composeInstValue(row.inst_code, row.inst_name_code),
    };
}

export default function PostingEditorModal({
    open,
    mode,
    proposalMode = false,
    personId,
    createEndpoint,
    mutateEndpoint,
    row,
    officeInitialLabel,
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
            setForm(stateFromRow(row, officeInitialLabel, sourceInitialLabel));
        } else {
            setForm(emptyState());
        }
    }, [open, mode, row, officeInitialLabel, sourceInitialLabel]);

    const setField = (key: keyof FormState, value: string) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    // 非 PK 主欄位。沿用 addresses 取捨：農曆 nh/range/intercalary 欄位暫不在 React 編輯器處理。
    // 注意：c_office_id 屬 PK（c_office_id+c_posting_id）。編輯既有任官時**不可改 office**——
    // 泛型 update 只改 POSTED_TO_OFFICE_DATA，不會遷移以 (c_office_id,c_posting_id) 為鍵的 POSTED_TO_ADDR_DATA，
    // 改 office 會讓地址副表變孤兒。故 c_office_id 只在 create 送出（見 handleSubmit），編輯模式為唯讀。
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

    const buildChanges = () => {
        const inst = splitInst();
        const changes: Record<string, unknown> = {
            c_sequence: form.c_sequence === '' ? 0 : Number(form.c_sequence),
            c_firstyear: form.c_firstyear === '' ? null : Number(form.c_firstyear),
            c_lastyear: form.c_lastyear === '' ? null : Number(form.c_lastyear),
            c_appt_code: form.c_appt_code === '' ? null : Number(form.c_appt_code),
            c_source: form.c_source === '' ? null : Number(form.c_source),
            c_pages: form.c_pages || null,
            c_notes: form.c_notes || null,
            // Task 27 補回欄位（assume/dy/office_category 為代碼，空送 null；inst 兩欄空送 0）
            c_assume_office_code: form.c_assume_office_code === '' ? null : Number(form.c_assume_office_code),
            c_dy: form.c_dy === '' ? null : Number(form.c_dy),
            c_office_category_id: form.c_office_category_id === '' ? null : Number(form.c_office_category_id),
            c_inst_code: inst.c_inst_code,
            c_inst_name_code: inst.c_inst_name_code,
        };
        return changes;
    };

    const handleSubmit = async () => {
        setErrors({});
        setGlobalError(null);

        // c_office_id 為任官 PK 必要欄位（create 後端配發 c_posting_id），create 缺則先擋。
        // 編輯模式 c_office_id 為唯讀、不送出，無需驗證。
        if (mode === 'create' && form.c_office_id === '') {
            setErrors({ c_office_id: [t('required')] });
            return;
        }

        setSaving(true);

        // create：target.pk = {}（c_posting_id 由後端配發），欄位走 changes。
        // update：target.pk = row.pk（c_office_id + c_posting_id）。
        const endpoint = mode === 'create' ? createEndpoint : mutateEndpoint;
        const body: Record<string, unknown> = {
            resource: 'postings',
            person_id: personId,
            mode: proposalMode ? 'proposal' : 'direct',
            target: { pk: mode === 'create' ? {} : (row ? row.pk : {}) },
            changes: buildChanges(),
        };

        if (mode === 'create') {
            // c_office_id 僅在 create 送出（PK，建立時必填）；地址副表 create 時送空陣列。
            (body.changes as Record<string, unknown>).c_office_id = form.c_office_id === '' ? null : Number(form.c_office_id);
            (body.changes as Record<string, unknown>).c_addr = [];
        } else {
            // 編輯模式：c_office_id 為 PK、不可變，不送入 changes（避免地址副表孤兒）。
            body.operation = 'update';
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
            title={mode === 'create' ? t('posting_editor_create_title') : t('posting_editor_edit_title')}
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

                {mode === 'create' ? (
                    <FormField label={t('office_name')} required error={errors.c_office_id}>
                        <CodeAutocomplete
                            mode="search"
                            endpoint="/api/select/search/office"
                            value={form.c_office_id}
                            initialLabel={form.c_office_label}
                            placeholder={t('office_search_placeholder')}
                            onChange={(v, label) => {
                                // searchOffice 以 -999 代表「未詳」(0)；後端 handler 會再轉回 0。
                                setField('c_office_id', v === '-999' ? '0' : v);
                                setField('c_office_label', label);
                            }}
                        />
                    </FormField>
                ) : (
                    // 編輯模式：c_office_id 屬 PK、不可變（改 office 會使地址副表孤兒），唯讀顯示。
                    <FormField label={t('office_name')}>
                        <div style={{ padding: '6px 0', color: '#374151' }}>
                            {form.c_office_label || form.c_office_id || '—'}
                        </div>
                    </FormField>
                )}

                <FormField label={t('seq_no')} error={errors.c_sequence}>
                    <Input
                        type="number"
                        value={form.c_sequence}
                        onChange={(e) => setField('c_sequence', e.target.value)}
                    />
                </FormField>

                <FormField label={t('start_year_label')} error={errors.c_firstyear}>
                    <Input
                        type="number"
                        value={form.c_firstyear}
                        onChange={(e) => setField('c_firstyear', e.target.value)}
                    />
                </FormField>

                <FormField label={t('end_year_label')} error={errors.c_lastyear}>
                    <Input
                        type="number"
                        value={form.c_lastyear}
                        onChange={(e) => setField('c_lastyear', e.target.value)}
                    />
                </FormField>

                <FormField label={t('appt_type_label')} error={errors.c_appt_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="appttype"
                        idKey="c_appt_code"
                        labelKeys={['c_appt_code', 'c_appt_desc_chn', 'c_appt_desc']}
                        value={form.c_appt_code}
                        initialLabel={form.c_appt_label}
                        placeholder={t('appt_type_placeholder')}
                        onChange={(v, label) => {
                            setField('c_appt_code', v);
                            setField('c_appt_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('posting_assume_office_label')} error={errors.c_assume_office_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="assumeoffice"
                        idKey="c_assume_office_code"
                        labelKeys={['c_assume_office_code', 'c_assume_office_desc_chn', 'c_assume_office_desc']}
                        value={form.c_assume_office_code}
                        initialLabel={form.c_assume_office_label}
                        placeholder={t('posting_assume_office_placeholder')}
                        onChange={(v, label) => {
                            setField('c_assume_office_code', v);
                            setField('c_assume_office_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('posting_office_category_label')} error={errors.c_office_category_id}>
                    <CodeAutocomplete
                        mode="list"
                        model="officecate"
                        idKey="c_office_category_id"
                        labelKeys={['c_office_category_id', 'c_category_desc_chn', 'c_category_desc']}
                        value={form.c_office_category_id}
                        initialLabel={form.c_office_category_label}
                        placeholder={t('posting_office_category_placeholder')}
                        onChange={(v, label) => {
                            setField('c_office_category_id', v);
                            setField('c_office_category_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('posting_dynasty_label')} error={errors.c_dy}>
                    <CodeAutocomplete
                        mode="list"
                        model="dynasty"
                        idKey="c_dy"
                        labelKeys={['c_dy', 'c_dynasty_chn', 'c_dynasty']}
                        value={form.c_dy}
                        initialLabel={form.c_dy_label}
                        placeholder={t('posting_dynasty_placeholder')}
                        onChange={(v, label) => {
                            setField('c_dy', v);
                            setField('c_dy_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('posting_inst_label')} error={errors.c_inst_code}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/socialinstcode"
                        value={form.c_inst_code}
                        initialLabel={form.c_inst_code_label}
                        placeholder={t('posting_inst_placeholder')}
                        onChange={(v, label) => {
                            setField('c_inst_code', v);
                            setField('c_inst_code_label', label);
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
                            setField('c_source', v);
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
