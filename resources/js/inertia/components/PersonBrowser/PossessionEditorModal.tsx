import React, { useEffect, useState } from 'react';
import { Modal } from '../ui/Modal';
import { Input } from '../ui/Input';
import { Button } from '../ui/Button';
import { FormField } from '../ui/FormField';
import CodeAutocomplete from './shared/CodeAutocomplete';
import { getCsrfToken } from './shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';

export interface PossessionEditorRow {
    pk: {
        c_possession_record_id: number | null;
    };
    act_code?: number | null;
    act_chn?: string | null;
    act?: string | null;
    desc_chn?: string | null;
    desc?: string | null;
    quantity?: string | null;
    measure_code?: number | null;
    year?: number | null;
    nh_code?: number | null;
    nh_yr?: number | null;
    yr_range?: number | null;
    source_id?: number | null;
    pages?: string | null;
    notes?: string | null;
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
    row?: PossessionEditorRow | null;
    /** 行為（c_possession_act_code）顯示文字，編輯模式用於初始顯示。 */
    actInitialLabel?: string | null;
    /** 出處（c_source）顯示文字，編輯模式用於初始顯示。 */
    sourceInitialLabel?: string | null;
    onClose: () => void;
    onSaved: () => void;
}

type FieldErrors = Record<string, string[]>;

interface FormState {
    c_possession_act_code: string;
    c_act_label: string;
    c_possession_desc_chn: string;
    c_possession_desc: string;
    c_quantity: string;
    c_measure_code: string;
    c_measure_label: string;
    c_possession_yr: string;
    c_possession_nh_code: string;
    c_possession_nh_code_label: string;
    c_possession_nh_yr: string;
    c_possession_yr_range: string;
    c_possession_yr_range_label: string;
    c_sequence: string;
    c_source: string;
    c_source_label: string;
    c_pages: string;
    c_notes: string;
}

function emptyState(): FormState {
    return {
        c_possession_act_code: '',
        c_act_label: '',
        c_possession_desc_chn: '',
        c_possession_desc: '',
        c_quantity: '',
        c_measure_code: '',
        c_measure_label: '',
        c_possession_yr: '',
        c_possession_nh_code: '',
        c_possession_nh_code_label: '',
        c_possession_nh_yr: '',
        c_possession_yr_range: '',
        c_possession_yr_range_label: '',
        c_sequence: '0',
        c_source: '',
        c_source_label: '',
        c_pages: '',
        c_notes: '',
    };
}

function sentinelToBlank(value: number | null | undefined): string {
    if (value == null || value === 0 || value === -999) {
        return '';
    }
    return String(value);
}

function stateFromRow(row: PossessionEditorRow, actLabel?: string | null, sourceLabel?: string | null): FormState {
    return {
        c_possession_act_code: row.act_code != null ? String(row.act_code) : '',
        c_act_label: actLabel ?? row.act_chn ?? row.act ?? (row.act_code != null ? String(row.act_code) : ''),
        c_possession_desc_chn: row.desc_chn ?? '',
        c_possession_desc: row.desc ?? '',
        c_quantity: row.quantity ?? '',
        // c_measure_code（單位）由唯讀 tab 帶回（additive 輸出）；沿用 sentinel 慣例 0/-999→空白。
        // 確保「編輯只改備註」時 c_measure_code 帶回原值，不被 buildChanges 清成 null。
        c_measure_code: sentinelToBlank(row.measure_code),
        c_measure_label: sentinelToBlank(row.measure_code),
        c_possession_yr: row.year != null ? String(row.year) : '',
        // 年號/時限（Task 27 補回）；沿用 sentinel 慣例（0/-999→空白），編輯只改備註時帶回原值不被清空。
        c_possession_nh_code: sentinelToBlank(row.nh_code),
        c_possession_nh_code_label: sentinelToBlank(row.nh_code),
        c_possession_nh_yr: row.nh_yr != null ? String(row.nh_yr) : '',
        c_possession_yr_range: sentinelToBlank(row.yr_range),
        c_possession_yr_range_label: sentinelToBlank(row.yr_range),
        c_sequence: '0',
        c_source: row.source_id != null ? String(row.source_id) : '',
        c_source_label: sourceLabel ?? (row.source_id != null ? String(row.source_id) : ''),
        c_pages: row.pages ?? '',
        c_notes: row.notes ?? '',
    };
}

export default function PossessionEditorModal({
    open,
    mode,
    proposalMode = false,
    personId,
    createEndpoint,
    mutateEndpoint,
    row,
    actInitialLabel,
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
            setForm(stateFromRow(row, actInitialLabel, sourceInitialLabel));
        } else {
            setForm(emptyState());
        }
    }, [open, mode, row, actInitialLabel, sourceInitialLabel]);

    const setField = (key: keyof FormState, value: string) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    // c_possession_record_id 為 surrogate PK，由後端配發；編輯器不顯示、不送入 changes（由 target.pk 定位）。
    // 地址副表 c_addr_id：比照 offices 取捨，React 編輯器先不做地址多選——create 送空 []，
    // update 不送 c_addr_id（PossessionMutationHandler 不碰 POSSESSION_ADDR），故編輯不會清空既有地址。
    // 哨兵：數值碼欄（act/measure/source）未詳時以 0 送出，後端 normalizeSentinelValues 處理。
    const buildChanges = () => {
        const changes: Record<string, unknown> = {
            c_sequence: form.c_sequence === '' ? 0 : Number(form.c_sequence),
            c_possession_act_code: form.c_possession_act_code === '' ? null : Number(form.c_possession_act_code),
            c_possession_desc_chn: form.c_possession_desc_chn || null,
            c_possession_desc: form.c_possession_desc || null,
            c_quantity: form.c_quantity || null,
            c_measure_code: form.c_measure_code === '' ? null : Number(form.c_measure_code),
            c_possession_yr: form.c_possession_yr === '' ? null : Number(form.c_possession_yr),
            c_possession_nh_code: form.c_possession_nh_code === '' ? null : Number(form.c_possession_nh_code),
            c_possession_nh_yr: form.c_possession_nh_yr === '' ? null : Number(form.c_possession_nh_yr),
            c_possession_yr_range: form.c_possession_yr_range === '' ? null : Number(form.c_possession_yr_range),
            c_source: form.c_source === '' ? null : Number(form.c_source),
            c_pages: form.c_pages || null,
            c_notes: form.c_notes || null,
        };
        return changes;
    };

    const handleSubmit = async () => {
        setErrors({});
        setGlobalError(null);
        setSaving(true);

        // create：target.pk = {}（c_possession_record_id 由後端配發），欄位走 changes。
        // update：target.pk = row.pk（c_possession_record_id）。
        const endpoint = mode === 'create' ? createEndpoint : mutateEndpoint;
        const body: Record<string, unknown> = {
            resource: 'possessions',
            person_id: personId,
            mode: proposalMode ? 'proposal' : 'direct',
            target: { pk: mode === 'create' ? {} : (row ? row.pk : {}) },
            changes: buildChanges(),
        };

        if (mode === 'create') {
            // 地址副表 create 時送空陣列（React 編輯器暫不做地址多選）。
            (body.changes as Record<string, unknown>).c_addr_id = [];
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
            title={mode === 'create' ? t('possession_editor_create_title') : t('possession_editor_edit_title')}
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

                <FormField label={t('action_label')} error={errors.c_possession_act_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="possact"
                        idKey="c_possession_act_code"
                        labelKeys={['c_possession_act_code', 'c_possession_act_desc_chn', 'c_possession_act_desc']}
                        value={form.c_possession_act_code}
                        initialLabel={form.c_act_label}
                        placeholder={t('possession_act_placeholder')}
                        onChange={(v, label) => {
                            setField('c_possession_act_code', v);
                            setField('c_act_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('possession_desc_chn_label')} error={errors.c_possession_desc_chn}>
                    <Input
                        value={form.c_possession_desc_chn}
                        onChange={(e) => setField('c_possession_desc_chn', e.target.value)}
                    />
                </FormField>

                <FormField label={t('possession_desc_label')} error={errors.c_possession_desc}>
                    <Input
                        value={form.c_possession_desc}
                        onChange={(e) => setField('c_possession_desc', e.target.value)}
                    />
                </FormField>

                <FormField label={t('quantity_label')} error={errors.c_quantity}>
                    <Input value={form.c_quantity} onChange={(e) => setField('c_quantity', e.target.value)} />
                </FormField>

                <FormField label={t('unit_label')} error={errors.c_measure_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="measure"
                        idKey="c_measure_code"
                        labelKeys={['c_measure_code', 'c_measure_desc_chn', 'c_measure_desc']}
                        value={form.c_measure_code}
                        initialLabel={form.c_measure_label}
                        placeholder={t('unit_placeholder')}
                        onChange={(v, label) => {
                            setField('c_measure_code', v);
                            setField('c_measure_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('year_label')} error={errors.c_possession_yr}>
                    <Input
                        type="number"
                        value={form.c_possession_yr}
                        onChange={(e) => setField('c_possession_yr', e.target.value)}
                    />
                </FormField>

                <FormField label={t('nianhao_label')} error={errors.c_possession_nh_code}>
                    <CodeAutocomplete
                        mode="list"
                        model="nianhao"
                        idKey="c_nianhao_id"
                        labelKeys={['c_nianhao_chn']}
                        value={form.c_possession_nh_code}
                        initialLabel={form.c_possession_nh_code_label}
                        placeholder={t('nianhao_placeholder')}
                        onChange={(v, label) => {
                            setField('c_possession_nh_code', v === '-999' ? '0' : v);
                            setField('c_possession_nh_code_label', label);
                        }}
                    />
                </FormField>

                <FormField label={t('nianhao_year_label')} error={errors.c_possession_nh_yr}>
                    <Input
                        type="number"
                        value={form.c_possession_nh_yr}
                        onChange={(e) => setField('c_possession_nh_yr', e.target.value)}
                    />
                </FormField>

                <FormField label={t('year_range_label')} error={errors.c_possession_yr_range}>
                    <CodeAutocomplete
                        mode="list"
                        model="range"
                        idKey="c_range_code"
                        labelKeys={['c_range_chn']}
                        value={form.c_possession_yr_range}
                        initialLabel={form.c_possession_yr_range_label}
                        placeholder={t('year_range_placeholder')}
                        onChange={(v, label) => {
                            setField('c_possession_yr_range', v);
                            setField('c_possession_yr_range_label', label);
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
