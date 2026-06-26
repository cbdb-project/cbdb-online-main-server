import React, { useEffect, useState } from 'react';
import { Modal } from '../ui/Modal';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import { FormField } from '../ui/FormField';
import CodeAutocomplete from './shared/CodeAutocomplete';
import { getCsrfToken } from './shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';

export interface AddressEditorRow {
    pk: {
        c_personid: number;
        c_addr_id: number | null;
        c_addr_type: number | null;
        c_sequence: number | null;
    };
    sequence: number | null;
    addr_id: number | null;
    addr_chn: string | null;
    addr: string | null;
    type_code: number | null;
    type_label_chn: string | null;
    type_label: string | null;
    first_year: number | null;
    last_year: number | null;
    source_id?: number | null;
    source_label?: string | null;
    pages?: string | null;
    notes: string | null;
    natal?: number | null;
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
    row?: AddressEditorRow | null;
    /** 出處（c_source）顯示文字，編輯模式用於初始顯示。 */
    sourceInitialLabel?: string | null;
    /** 地名（c_addr_id）顯示文字，編輯模式用於初始顯示。 */
    addrInitialLabel?: string | null;
    onClose: () => void;
    onSaved: () => void;
}

type FieldErrors = Record<string, string[]>;

interface FormState {
    c_sequence: string;
    c_addr_id: string;
    c_addr_label: string;
    c_addr_type: string;
    c_addr_type_label: string;
    c_firstyear: string;
    c_lastyear: string;
    c_source: string;
    c_source_label: string;
    c_pages: string;
    c_notes: string;
    c_natal: string;
}

function emptyState(): FormState {
    return {
        c_sequence: '0',
        c_addr_id: '',
        c_addr_label: '',
        c_addr_type: '',
        c_addr_type_label: '',
        c_firstyear: '',
        c_lastyear: '',
        c_source: '',
        c_source_label: '',
        c_pages: '',
        c_notes: '',
        c_natal: '',
    };
}

function stateFromRow(row: AddressEditorRow, sourceLabel?: string | null, addrLabel?: string | null): FormState {
    return {
        c_sequence: row.sequence != null ? String(row.sequence) : '0',
        c_addr_id: row.addr_id != null ? String(row.addr_id) : '',
        c_addr_label: addrLabel ?? row.addr_chn ?? row.addr ?? (row.addr_id != null ? String(row.addr_id) : ''),
        c_addr_type: row.type_code != null ? String(row.type_code) : '',
        c_addr_type_label: row.type_label_chn ?? row.type_label ?? '',
        c_firstyear: row.first_year != null ? String(row.first_year) : '',
        c_lastyear: row.last_year != null ? String(row.last_year) : '',
        c_source: row.source_id != null ? String(row.source_id) : '',
        c_source_label: sourceLabel ?? row.source_label ?? (row.source_id != null ? String(row.source_id) : ''),
        c_pages: row.pages ?? '',
        c_notes: row.notes ?? '',
        c_natal: row.natal != null ? String(row.natal) : '',
    };
}

export default function AddressEditorModal({
    open,
    mode,
    proposalMode = false,
    personId,
    createEndpoint,
    mutateEndpoint,
    row,
    sourceInitialLabel,
    addrInitialLabel,
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
            setForm(stateFromRow(row, sourceInitialLabel, addrInitialLabel));
        } else {
            setForm(emptyState());
        }
    }, [open, mode, row, sourceInitialLabel, addrInitialLabel]);

    const setField = (key: keyof FormState, value: string) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const buildChanges = () => {
        const changes: Record<string, unknown> = {
            c_firstyear: form.c_firstyear === '' ? null : Number(form.c_firstyear),
            c_lastyear: form.c_lastyear === '' ? null : Number(form.c_lastyear),
            c_source: form.c_source === '' ? null : Number(form.c_source),
            c_pages: form.c_pages || null,
            c_notes: form.c_notes || null,
            // c_natal（是否本貫）：0/1 旗標；空白以 null 計（舊表單為 0/1 select）。
            c_natal: form.c_natal === '' ? null : Number(form.c_natal),
        };
        return changes;
    };

    const handleSubmit = async () => {
        setErrors({});
        setGlobalError(null);

        // PK 必填欄位（c_addr_type / c_sequence）送出前先擋，避免後端回不友善的 400/409。
        const requiredErrors: FieldErrors = {};
        if (form.c_addr_type === '') {
            requiredErrors.c_addr_type = [t('required') ?? '必填'];
        }
        if (form.c_sequence === '') {
            requiredErrors.c_sequence = [t('required') ?? '必填'];
        }
        if (Object.keys(requiredErrors).length > 0) {
            setErrors(requiredErrors);
            return;
        }

        setSaving(true);

        // 4 鍵全由 client 提供；地名清空時以「未詳」(0) 計。
        const targetPk = {
            c_personid: personId,
            c_addr_id: form.c_addr_id === '' ? 0 : Number(form.c_addr_id),
            c_addr_type: form.c_addr_type === '' ? null : Number(form.c_addr_type),
            c_sequence: form.c_sequence === '' ? 0 : Number(form.c_sequence),
        };

        const endpoint = mode === 'create' ? createEndpoint : mutateEndpoint;
        const body: Record<string, unknown> = {
            resource: 'addresses',
            person_id: personId,
            mode: proposalMode ? 'proposal' : 'direct',
            target: { pk: mode === 'create' ? targetPk : (row ? row.pk : targetPk) },
            changes: buildChanges(),
        };

        if (mode === 'edit') {
            body.operation = 'update';
            // 編輯模式允許修改 PK 欄位（後端會檢查衝突）。
            (body.changes as Record<string, unknown>).c_addr_id = targetPk.c_addr_id;
            (body.changes as Record<string, unknown>).c_addr_type = targetPk.c_addr_type;
            (body.changes as Record<string, unknown>).c_sequence = targetPk.c_sequence;
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
            title={mode === 'create' ? t('address_editor_create_title') : t('address_editor_edit_title')}
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

                <FormField label={t('seq_no')} required error={errors.c_sequence}>
                    <Input
                        type="number"
                        value={form.c_sequence}
                        onChange={(e) => setField('c_sequence', e.target.value)}
                    />
                </FormField>

                <FormField label={t('type_label')} required error={errors.c_addr_type}>
                    <CodeAutocomplete
                        mode="list"
                        model="biogaddr"
                        idKey="c_addr_type"
                        labelKeys={['c_addr_type', 'c_addr_desc_chn', 'c_addr_desc']}
                        value={form.c_addr_type}
                        initialLabel={form.c_addr_type_label}
                        placeholder={t('address_type_placeholder')}
                        onChange={(v, label) => {
                            setField('c_addr_type', v);
                            setField('c_addr_type_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('address_label')} error={errors.c_addr_id}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/addr"
                        value={form.c_addr_id}
                        initialLabel={form.c_addr_label}
                        placeholder={t('address_search_placeholder')}
                        onChange={(v, label) => {
                            // searchAddr 以 -999 代表「未詳」(0)；後端 handler 會再轉回 0。
                            setField('c_addr_id', v === '-999' ? '0' : v);
                            setField('c_addr_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('natal_label')} error={errors.c_natal}>
                    <select
                        value={form.c_natal}
                        onChange={(e) => setField('c_natal', e.target.value)}
                        style={selectStyle}
                    >
                        <option value="">—</option>
                        <option value="0">0 - {t('natal_no')}</option>
                        <option value="1">1 - {t('natal_yes')}</option>
                    </select>
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

const selectStyle: React.CSSProperties = {
    width: '100%',
    height: 36,
    borderRadius: 6,
    border: '1px solid #cbd5e1',
    padding: '0 10px',
    fontSize: '0.875rem',
    boxSizing: 'border-box',
};
