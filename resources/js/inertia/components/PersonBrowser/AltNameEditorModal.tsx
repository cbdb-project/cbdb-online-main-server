import React, { useEffect, useState } from 'react';
import { Modal } from '../ui/Modal';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import { FormField } from '../ui/FormField';
import CodeAutocomplete from './shared/CodeAutocomplete';
import { getCsrfToken } from './shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';

export interface AltNameEditorRow {
    pk: {
        c_personid: number;
        c_alt_name_chn: string | null;
        c_alt_name_type_code: number | null;
    };
    sequence: number | null;
    name_chn: string | null;
    name: string | null;
    type_code: number | null;
    type_label_chn: string | null;
    type_label: string | null;
    source_id: number | null;
    source_label?: string | null;
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
    row?: AltNameEditorRow | null;
    /** 出處（c_source）顯示文字，編輯模式用於初始顯示。 */
    sourceInitialLabel?: string | null;
    onClose: () => void;
    onSaved: () => void;
}

type FieldErrors = Record<string, string[]>;

interface FormState {
    c_sequence: string;
    c_alt_name_chn: string;
    c_alt_name: string;
    c_alt_name_type_code: string;
    c_alt_name_type_label: string;
    c_source: string;
    c_source_label: string;
    c_pages: string;
    c_notes: string;
}

function emptyState(): FormState {
    return {
        c_sequence: '',
        c_alt_name_chn: '',
        c_alt_name: '',
        c_alt_name_type_code: '',
        c_alt_name_type_label: '',
        c_source: '',
        c_source_label: '',
        c_pages: '',
        c_notes: '',
    };
}

function stateFromRow(row: AltNameEditorRow, sourceLabel?: string | null): FormState {
    return {
        c_sequence: row.sequence != null ? String(row.sequence) : '',
        c_alt_name_chn: row.name_chn ?? '',
        c_alt_name: row.name ?? '',
        c_alt_name_type_code: row.type_code != null ? String(row.type_code) : '',
        c_alt_name_type_label: row.type_label_chn ?? row.type_label ?? '',
        c_source: row.source_id != null ? String(row.source_id) : '',
        c_source_label: sourceLabel ?? row.source_label ?? (row.source_id != null ? String(row.source_id) : ''),
        c_pages: row.pages ?? '',
        c_notes: row.notes ?? '',
    };
}

export default function AltNameEditorModal({
    open,
    mode,
    proposalMode = false,
    personId,
    createEndpoint,
    mutateEndpoint,
    row,
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
            setForm(stateFromRow(row, sourceInitialLabel));
        } else {
            setForm(emptyState());
        }
    }, [open, mode, row, sourceInitialLabel]);

    const setField = (key: keyof FormState, value: string) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const buildChanges = () => {
        const changes: Record<string, unknown> = {
            c_sequence: form.c_sequence === '' ? null : Number(form.c_sequence),
            c_alt_name_chn: form.c_alt_name_chn,
            c_alt_name: form.c_alt_name || null,
            c_alt_name_type_code: form.c_alt_name_type_code === '' ? null : Number(form.c_alt_name_type_code),
            c_source: form.c_source === '' ? null : Number(form.c_source),
            c_pages: form.c_pages || null,
            c_notes: form.c_notes || null,
        };
        return changes;
    };

    const handleSubmit = async () => {
        setSaving(true);
        setErrors({});
        setGlobalError(null);

        const targetPk = {
            c_personid: personId,
            c_alt_name_chn: form.c_alt_name_chn,
            c_alt_name_type_code: form.c_alt_name_type_code === '' ? null : Number(form.c_alt_name_type_code),
        };

        const endpoint = mode === 'create' ? createEndpoint : mutateEndpoint;
        const body: Record<string, unknown> = {
            resource: 'altnames',
            person_id: personId,
            mode: proposalMode ? 'proposal' : 'direct',
            target: { pk: mode === 'create' ? targetPk : (row ? row.pk : targetPk) },
            changes: buildChanges(),
        };
        if (mode === 'edit') {
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
            title={mode === 'create' ? t('altname_editor_create_title') : t('altname_editor_edit_title')}
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

                <FormField label={t('seq_no')} error={errors.c_sequence}>
                    <Input
                        type="number"
                        value={form.c_sequence}
                        onChange={(e) => setField('c_sequence', e.target.value)}
                    />
                </FormField>

                <FormField label={t('alt_name')} required error={errors.c_alt_name_chn}>
                    <Input
                        value={form.c_alt_name_chn}
                        onChange={(e) => setField('c_alt_name_chn', e.target.value)}
                    />
                </FormField>

                <FormField label={t('altname_pinyin_label')} error={errors.c_alt_name}>
                    <Input value={form.c_alt_name} onChange={(e) => setField('c_alt_name', e.target.value)} />
                </FormField>

                <FormField label={t('type_label')} required error={errors.c_alt_name_type_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="altcode"
                        idKey="c_name_type_code"
                        labelKeys={['c_name_type_code', 'c_name_type_desc_chn', 'c_name_type_desc']}
                        value={form.c_alt_name_type_code}
                        initialLabel={form.c_alt_name_type_label}
                        placeholder={t('altname_type_placeholder')}
                        onChange={(v, label) => {
                            setField('c_alt_name_type_code', v);
                            setField('c_alt_name_type_label', label);
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
