import React, { useEffect, useState } from 'react';
import { Modal } from '../ui/Modal';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import { FormField } from '../ui/FormField';
import CodeAutocomplete from './shared/CodeAutocomplete';
import { getCsrfToken } from './shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';

export interface SourceEditorRow {
    pk: {
        c_personid: number;
        c_textid: number | null;
        c_pages: string | null;
    };
    text_id: number | null;
    title_chn: string | null;
    title: string | null;
    pages: string | null;
    notes: string | null;
    is_main_source: boolean;
    is_self_bio: boolean;
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
    row?: SourceEditorRow | null;
    /** 出處（c_textid）顯示文字，編輯模式用於初始顯示。 */
    textInitialLabel?: string | null;
    onClose: () => void;
    onSaved: () => void;
}

type FieldErrors = Record<string, string[]>;

interface FormState {
    c_textid: string;
    c_textid_label: string;
    c_pages: string;
    c_notes: string;
    c_main_source: boolean;
    c_self_bio: boolean;
}

function emptyState(): FormState {
    return {
        c_textid: '',
        c_textid_label: '',
        c_pages: '',
        c_notes: '',
        c_main_source: false,
        c_self_bio: false,
    };
}

function stateFromRow(row: SourceEditorRow, textLabel?: string | null): FormState {
    return {
        c_textid: row.text_id != null ? String(row.text_id) : '',
        c_textid_label: textLabel ?? row.title_chn ?? row.title ?? (row.text_id != null ? String(row.text_id) : ''),
        c_pages: row.pages ?? '',
        c_notes: row.notes ?? '',
        c_main_source: !!row.is_main_source,
        c_self_bio: !!row.is_self_bio,
    };
}

export default function SourceEditorModal({
    open,
    mode,
    proposalMode = false,
    personId,
    createEndpoint,
    mutateEndpoint,
    row,
    textInitialLabel,
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
            setForm(stateFromRow(row, textInitialLabel));
        } else {
            setForm(emptyState());
        }
    }, [open, mode, row, textInitialLabel]);

    const setField = <K extends keyof FormState>(key: K, value: FormState[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    // 非 PK 欄位。布林欄位一律送 0/1；c_notes 留空送 null 以允許清空。
    const buildChanges = () => {
        const changes: Record<string, unknown> = {
            c_notes: form.c_notes || null,
            c_main_source: form.c_main_source ? 1 : 0,
            c_self_bio: form.c_self_bio ? 1 : 0,
        };
        return changes;
    };

    const handleSubmit = async () => {
        setErrors({});
        setGlobalError(null);

        // PK 必填欄位：僅 c_textid（c_pages 可空＝canonical ''，c_personid 自動帶）。
        if (form.c_textid === '') {
            setErrors({ c_textid: [t('required') ?? '必填'] });
            return;
        }

        setSaving(true);

        // 3 鍵全由 client 提供；c_pages 空字串即 canonical ''。
        const targetPk = {
            c_personid: personId,
            c_textid: Number(form.c_textid),
            c_pages: form.c_pages,
        };

        const endpoint = mode === 'create' ? createEndpoint : mutateEndpoint;
        const body: Record<string, unknown> = {
            resource: 'sources',
            person_id: personId,
            mode: proposalMode ? 'proposal' : 'direct',
            target: { pk: mode === 'create' ? targetPk : (row ? row.pk : targetPk) },
            changes: buildChanges(),
        };

        if (mode === 'create') {
            // create 需要 c_textid / c_pages 一併帶入 changes 以建立 PK。
            (body.changes as Record<string, unknown>).c_textid = targetPk.c_textid;
            (body.changes as Record<string, unknown>).c_pages = targetPk.c_pages;
        } else {
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
            title={mode === 'create' ? t('source_editor_create_title') : t('source_editor_edit_title')}
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

                <FormField label={t('source_label')} required error={errors.c_textid}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/text"
                        value={form.c_textid}
                        initialLabel={form.c_textid_label}
                        placeholder={t('source_search_placeholder')}
                        onChange={(v, label) => {
                            // searchText 以 -999 代表「未詳」(0)；後端 handler 會再轉回 0。
                            setField('c_textid', v === '-999' ? '0' : v);
                            setField('c_textid_label', label);
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

                <label style={checkboxRowStyle}>
                    <input
                        type="checkbox"
                        checked={form.c_main_source}
                        onChange={(e) => setField('c_main_source', e.target.checked)}
                    />
                    <span>{t('main_source')}</span>
                </label>

                <label style={checkboxRowStyle}>
                    <input
                        type="checkbox"
                        checked={form.c_self_bio}
                        onChange={(e) => setField('c_self_bio', e.target.checked)}
                    />
                    <span>{t('self_bio')}</span>
                </label>

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

const checkboxRowStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    gap: 8,
    fontSize: '0.875rem',
    cursor: 'pointer',
};
