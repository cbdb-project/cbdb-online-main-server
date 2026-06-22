import React, { useEffect, useState } from 'react';
import { Modal } from '../ui/Modal';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import { FormField } from '../ui/FormField';
import CodeAutocomplete from './shared/CodeAutocomplete';
import { getCsrfToken } from './shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';

export interface TextEditorRow {
    pk: {
        c_personid: number;
        c_textid: number | null;
        c_role_id: number | null;
    };
    text_id: number | null;
    title_chn: string | null;
    title: string | null;
    role_id: number | null;
    role_chn: string | null;
    role: string | null;
    source_id?: number | null;
    pages?: string | null;
    notes: string | null;
}

interface Props {
    open: boolean;
    mode: 'create' | 'edit';
    /** true 時送出提案（mode: 'proposal'）而非直接寫入。 */
    proposalMode?: boolean;
    personId: number;
    createEndpoint: string;
    mutateEndpoint: string;
    /** 編輯模式下的既有資料。 */
    row?: TextEditorRow | null;
    /** 著作（c_textid）顯示文字，編輯模式用於初始顯示。 */
    textInitialLabel?: string | null;
    /** 角色（c_role_id）顯示文字，編輯模式用於初始顯示。 */
    roleInitialLabel?: string | null;
    /** 出處（c_source）顯示文字，編輯模式用於初始顯示。 */
    sourceInitialLabel?: string | null;
    onClose: () => void;
    onSaved: () => void;
}

type FieldErrors = Record<string, string[]>;

interface FormState {
    c_textid: string;
    c_textid_label: string;
    c_role_id: string;
    c_role_id_label: string;
    c_source: string;
    c_source_label: string;
    c_pages: string;
    c_notes: string;
}

function emptyState(): FormState {
    return {
        c_textid: '',
        c_textid_label: '',
        c_role_id: '',
        c_role_id_label: '',
        c_source: '',
        c_source_label: '',
        c_pages: '',
        c_notes: '',
    };
}

function stateFromRow(
    row: TextEditorRow,
    textLabel?: string | null,
    roleLabel?: string | null,
    sourceLabel?: string | null,
): FormState {
    return {
        c_textid: row.text_id != null ? String(row.text_id) : '',
        c_textid_label: textLabel ?? row.title_chn ?? row.title ?? (row.text_id != null ? String(row.text_id) : ''),
        c_role_id: row.role_id != null ? String(row.role_id) : '',
        c_role_id_label: roleLabel ?? row.role_chn ?? row.role ?? '',
        c_source: row.source_id != null ? String(row.source_id) : '',
        c_source_label: sourceLabel ?? (row.source_id != null ? String(row.source_id) : ''),
        c_pages: row.pages ?? '',
        c_notes: row.notes ?? '',
    };
}

export default function TextEditorModal({
    open,
    mode,
    proposalMode = false,
    personId,
    createEndpoint,
    mutateEndpoint,
    row,
    textInitialLabel,
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
            setForm(stateFromRow(row, textInitialLabel, roleInitialLabel, sourceInitialLabel));
        } else {
            setForm(emptyState());
        }
    }, [open, mode, row, textInitialLabel, roleInitialLabel, sourceInitialLabel]);

    const setField = (key: keyof FormState, value: string) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    // 非 PK 欄位。c_source/c_pages/c_notes 即使留空也會送出 null，使編輯時可清空。
    const buildChanges = () => {
        const changes: Record<string, unknown> = {
            c_source: form.c_source === '' ? null : Number(form.c_source),
            c_pages: form.c_pages || null,
            c_notes: form.c_notes || null,
        };
        return changes;
    };

    const handleSubmit = async () => {
        setErrors({});
        setGlobalError(null);

        // PK 必填欄位（c_textid / c_role_id）送出前先擋。
        const requiredErrors: FieldErrors = {};
        if (form.c_textid === '') {
            requiredErrors.c_textid = [t('required') ?? '必填'];
        }
        if (form.c_role_id === '') {
            requiredErrors.c_role_id = [t('required') ?? '必填'];
        }
        if (Object.keys(requiredErrors).length > 0) {
            setErrors(requiredErrors);
            return;
        }

        setSaving(true);

        // 3 鍵全由 client 提供。
        const targetPk = {
            c_personid: personId,
            c_textid: Number(form.c_textid),
            c_role_id: Number(form.c_role_id),
        };

        const endpoint = mode === 'create' ? createEndpoint : mutateEndpoint;
        const body: Record<string, unknown> = {
            resource: 'texts',
            person_id: personId,
            mode: proposalMode ? 'proposal' : 'direct',
            target: { pk: mode === 'create' ? targetPk : (row ? row.pk : targetPk) },
            changes: buildChanges(),
        };

        if (mode === 'edit') {
            body.operation = 'update';
            // 編輯模式允許修改 PK 欄位（後端會檢查衝突）。
            (body.changes as Record<string, unknown>).c_textid = targetPk.c_textid;
            (body.changes as Record<string, unknown>).c_role_id = targetPk.c_role_id;
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
            title={mode === 'create' ? t('text_editor_create_title') : t('text_editor_edit_title')}
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

                <FormField label={t('text_title')} required error={errors.c_textid}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/text"
                        value={form.c_textid}
                        initialLabel={form.c_textid_label}
                        placeholder={t('text_search_placeholder')}
                        onChange={(v, label) => {
                            // searchText 以 -999 代表「未詳」(0)；後端 handler 會再轉回 0。
                            setField('c_textid', v === '-999' ? '0' : v);
                            setField('c_textid_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('role_label')} required error={errors.c_role_id}>
                    <CodeAutocomplete
                        mode="list"
                        model="role"
                        idKey="c_role_id"
                        labelKeys={['c_role_id', 'c_role_desc_chn', 'c_role_desc']}
                        value={form.c_role_id}
                        initialLabel={form.c_role_id_label}
                        placeholder={t('role_placeholder')}
                        onChange={(v, label) => {
                            setField('c_role_id', v);
                            setField('c_role_id_label', label);
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
