import React, { useEffect, useState } from 'react';
import { Modal } from '../ui/Modal';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import { FormField } from '../ui/FormField';
import CodeAutocomplete from './shared/CodeAutocomplete';
import { getCsrfToken } from './shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';

export interface EventEditorRow {
    pk: {
        c_personid: number;
        c_sequence: number | null;
        c_event_code: number | null;
    };
    sequence: number | null;
    event_code: number | null;
    event_chn: string | null;
    event: string | null;
    year: number | null;
    event_text: string | null;
    role: string | null;
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
    row?: EventEditorRow | null;
    /** 事件類型（c_event_code）顯示文字，編輯模式初始顯示。 */
    eventCodeInitialLabel?: string | null;
    /** 出處（c_source）顯示文字，編輯模式初始顯示。 */
    sourceInitialLabel?: string | null;
    onClose: () => void;
    onSaved: () => void;
}

type FieldErrors = Record<string, string[]>;

interface FormState {
    // PK 欄位（除 c_personid 外）
    c_event_code: string;
    c_event_code_label: string;
    c_sequence: string;
    // 非 PK 欄位
    c_year: string;
    c_source: string;
    c_source_label: string;
    c_pages: string;
    c_event: string;
    c_role: string;
    c_notes: string;
}

function emptyState(): FormState {
    return {
        c_event_code: '',
        c_event_code_label: '',
        c_sequence: '',
        c_year: '',
        c_source: '',
        c_source_label: '',
        c_pages: '',
        c_event: '',
        c_role: '',
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
    row: EventEditorRow,
    eventCodeLabel?: string | null,
    sourceLabel?: string | null,
): FormState {
    const eventCode = sentinelToBlank(row.pk.c_event_code);

    return {
        c_event_code: eventCode,
        c_event_code_label: eventCodeLabel ?? (eventCode || ''),
        // c_sequence 為 PK，預設 0 視為未詳顯示空白。
        c_sequence: sentinelToBlank(row.pk.c_sequence),
        c_year: sentinelToBlank(row.year),
        c_source: sentinelToBlank(row.source_id),
        c_source_label: sourceLabel ?? sentinelToBlank(row.source_id),
        c_pages: row.pages ?? '',
        c_event: row.event_text ?? '',
        c_role: row.role ?? '',
        c_notes: row.notes ?? '',
    };
}

export default function EventEditorModal({
    open,
    mode,
    proposalMode = false,
    personId,
    createEndpoint,
    mutateEndpoint,
    row,
    eventCodeInitialLabel,
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
            setForm(stateFromRow(row, eventCodeInitialLabel, sourceInitialLabel));
        } else {
            setForm(emptyState());
        }
    }, [open, mode, row, eventCodeInitialLabel, sourceInitialLabel]);

    const setField = (key: keyof FormState, value: string) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    // 數值 PK 欄位：留空＝未詳，送 -999 由後端 normalizeSentinelValues 轉成 '0'。
    // 注意（P4-6 教訓）：空 PK 數值欄務必送 -999，不可送 ''——'' 經 middleware 轉 null，
    // normalizeSentinelValues 不處理 null，可能寫成 0 或出錯。
    const numericKeyOrSentinel = (raw: string): number => (raw === '' ? -999 : Number(raw));

    // 3-key PK 對應欄位（create 直接放 target.pk；edit 放 changes 以允許改 PK）。
    // c_event_code 經後端 normalizeSentinelValues；c_sequence 無哨兵但仍以 -999→Number 防止 null。
    const buildKeyFields = () => ({
        c_event_code: numericKeyOrSentinel(form.c_event_code),
        c_sequence: form.c_sequence === '' ? 0 : Number(form.c_sequence),
    });

    // 非 PK 欄位。留空送 null 以允許清空（c_source 為數值欄，空送 null）。
    // 農曆/曆法欄位（c_nh_code/c_nh_year/c_month/c_day/c_yr_range/c_intercalary）與 c_addr_id
    // 暫不納入，update 時不送即不被清空（比照 P4-2/P4-5）。
    const buildNonKeyChanges = () => ({
        c_year: form.c_year === '' ? null : Number(form.c_year),
        c_source: form.c_source === '' ? null : Number(form.c_source),
        c_pages: form.c_pages || null,
        c_event: form.c_event || null,
        c_role: form.c_role || null,
        c_notes: form.c_notes || null,
    });

    const handleSubmit = async () => {
        setErrors({});
        setGlobalError(null);

        // 事件類型（c_event_code）必填。
        if (form.c_event_code === '') {
            setErrors({ c_event_code: [t('required') ?? '必填'] });
            return;
        }

        setSaving(true);

        const keyFields = buildKeyFields();
        const endpoint = mode === 'create' ? createEndpoint : mutateEndpoint;

        const body: Record<string, unknown> = {
            resource: 'events',
            person_id: personId,
            mode: proposalMode ? 'proposal' : 'direct',
        };
        if (proposalMode) {
            body.meta = { comment };
        }

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
            title={mode === 'create' ? t('event_editor_create_title') : t('event_editor_edit_title')}
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

                <FormField label={t('event_label')} required error={errors.c_event_code}>
                    <CodeAutocomplete
                        mode="search"
                        endpoint="/api/select/search/event"
                        value={form.c_event_code}
                        initialLabel={form.c_event_code_label}
                        placeholder={t('event_code_placeholder')}
                        onChange={(v, label) => {
                            // searchEvent 以 -999 代表「未詳」(0)；後端 handler 會再轉回 0。
                            setField('c_event_code', v === '-999' ? '0' : v);
                            setField('c_event_code_label', label);
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

                <FormField label={t('event_year_label')} error={errors.c_year}>
                    <Input
                        type="number"
                        value={form.c_year}
                        onChange={(e) => setField('c_year', e.target.value)}
                    />
                </FormField>

                <FormField label={t('event_detail_label')} error={errors.c_event}>
                    <textarea
                        value={form.c_event}
                        onChange={(e) => setField('c_event', e.target.value)}
                        rows={3}
                        style={textareaStyle}
                    />
                </FormField>

                <FormField label={t('event_role_label')} error={errors.c_role}>
                    <Input value={form.c_role} onChange={(e) => setField('c_role', e.target.value)} />
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
