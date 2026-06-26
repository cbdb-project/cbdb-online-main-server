import React, { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { APP_THEME } from '../../theme';
import { useTranslation } from '../../hooks/useTranslation';

interface Props {
    sections: Section[];
    form?: BasicInfoForm | null;
    personId: number;
    mutateEndpoint: string;
    pinyinEndpoint: string;
    canEdit?: boolean;
    /**
     * 進場即直接進入編輯狀態（編輯主界面 PersonEditor 用，對齊舊頁「打開即可錄入」）。
     * 預設 false：PersonBrowser 維持「先檢視、點『編輯基本資料』才進編輯」的原行為。
     * 僅在 canEdit 為真時生效。
     */
    startEditing?: boolean;
    /**
     * 隱藏元件內建的（legacy 表單）刪除按鈕。供獨立 Edit 頁改用頁面層 /api/v2/delete
     * 時關閉，避免出現兩個刪除入口。預設 false（PersonBrowser 維持原行為）。
     */
    hideDelete?: boolean;
    onSaved?: () => void;
    onEditorStateChange?: (state: { editing: boolean; dirty: boolean }) => void;
    onRegisterSaveHandler?: ((handler: (() => Promise<boolean>) | null) => void) | undefined;
    /**
     * 覆寫「編輯基本資料」按鈕行為：提供時點擊不進入內聯編輯，改執行此回呼
     * （#34 詳情中樞用於導向獨立 BasicInfoEditor edit-v2，含年號轉換）。
     */
    onEditClick?: () => void;
}

interface Section {
    title: string;
    fields: Field[];
}

interface Field {
    label: string;
    value: string | number | null;
}

interface BasicInfoForm {
    person_id: number;
    fields: Record<string, FormField>;
}

interface FormField {
    key: string;
    label: string;
    value: string | number | null;
    input: 'text' | 'number' | 'textarea' | 'enum' | 'checkbox';
    editable: boolean;
    send_on_save?: boolean;
    derived?: boolean;
    enum_model?: string;
    id_key?: string;
    display_value?: string | number | null;
    options?: EnumOption[];
}

interface EnumOption {
    value: string;
    label: string;
}

type FieldValue = Field['value'];
type FormState = Record<string, string>;
type FieldErrors = Record<string, string[]>;

const enumOptionCache = new Map<string, Promise<EnumOption[]>>();
const hiddenFields: Record<string, string[]> = {
    nianhao: ['c_firstyear', 'c_lastyear'],
};

// 逐欄位說明文字（help/hint），對齊舊 basicinformation/edit.blade.php 的 __('biogmains.*')：
//   - 合併生成的顯示用姓名欄（name_auto_hint / pinyin_auto_hint / foreign_full_auto_hint / rm_auto_hint）
//   - 算法定期計算的指數欄（auto_calc_hint）
//   - 生卒年農曆月/日輸入範圍（month_range_hint / day_range_hint）
// 字串照抄 resources/lang/zh-TW/biogmains.php；與本元件既有硬編碼 zh-TW 一致（i18n 待後續統一）。
const FIELD_HINTS: Record<string, string> = {
    c_name_chn: '此欄位由「姓」和「名」自動合併生成，無需手動填寫',
    c_name: '此欄位由「Xing」和「Ming」自動合併生成，無需手動填寫',
    c_name_proper: '此欄位由「外文名」和「外文姓」自動合併生成（名+姓順序），無需手動填寫',
    c_name_rm: '此欄位由「外文羅馬字轉寫姓」和「外文羅馬字轉寫名」自動合併生成，無需手動填寫',
    c_index_year: '此欄位由算法定期自動計算生成，無需手動填寫',
    c_index_year_type_code: '此欄位由算法定期自動計算生成，無需手動填寫',
    c_index_year_source_id: '此欄位由算法定期自動計算生成，無需手動填寫',
    c_index_addr_id: '此欄位由算法定期自動計算生成，無需手動填寫',
    c_index_addr_type_code: '此欄位由算法定期自動計算生成，無需手動填寫',
    c_by_month: '請輸入 1-12 或留空',
    c_by_day: '請輸入 1-30 或留空',
    c_dy_month: '請輸入 1-12 或留空',
    c_dy_day: '請輸入 1-30 或留空',
};

export default function BasicInfoView({
    sections,
    form,
    personId,
    mutateEndpoint,
    pinyinEndpoint,
    canEdit = false,
    startEditing = false,
    hideDelete = false,
    onSaved,
    onEditorStateChange,
    onRegisterSaveHandler,
    onEditClick,
}: Props) {
    const t = useTranslation('person');
    const tCommon = useTranslation('common');
    const panelRef = useRef<HTMLDivElement | null>(null);
    const [editing, setEditing] = useState<boolean>(Boolean(startEditing && canEdit));
    const [formState, setFormState] = useState<FormState>({});
    const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [generatingPinyin, setGeneratingPinyin] = useState(false);

    const initialState = useMemo(() => buildInitialState(form), [form]);
    const dirty = useMemo(
        () => hasFormChanges(form, initialState, formState),
        [form, initialState, formState],
    );
    const dirtyFields = useMemo(
        () => buildDirtyFieldSet(form, initialState, formState),
        [form, initialState, formState],
    );
    const editableKeys = useMemo(() => {
        if (!form?.fields) return new Set<string>();
        return new Set(Object.entries(form.fields).filter(([, f]) => f.editable).map(([k]) => k));
    }, [form]);

    useEffect(() => {
        setEditing(Boolean(startEditing && canEdit));
        setFormState(initialState);
        setFieldErrors({});
        setMessage(null);
        setError(null);
    }, [initialState, personId, startEditing, canEdit]);

    useEffect(() => {
        if (!editing || !form) {
            return;
        }

        const models = new Set<string>();
        Object.values(form.fields).forEach((field) => {
            if (field.input === 'enum' && field.enum_model) {
                models.add(field.enum_model);
            }
        });

        models.forEach((model) => {
            void fetchEnumOptions(model);
        });
    }, [editing, form]);

    useEffect(() => {
        onEditorStateChange?.({
            editing,
            dirty: editing && dirty,
        });
    }, [dirty, editing, onEditorStateChange]);

    useEffect(() => () => {
        onRegisterSaveHandler?.(null);
        onEditorStateChange?.({ editing: false, dirty: false });
    }, [onEditorStateChange, onRegisterSaveHandler]);

    if ((!sections || sections.length === 0) && !form) {
        return <div style={emptyStyle}>{t('no_basic_data')}</div>;
    }

    const beginEdit = () => {
        if (!canEdit) {
            return;
        }

        setEditing(true);
        setFormState(initialState);
        setFieldErrors({});
        setMessage(null);
        setError(null);
    };

    const deleteFormRef = useRef<HTMLFormElement | null>(null);

    const handleDelete = useCallback(() => {
        if (!canEdit || !personId) return;
        if (!window.confirm(t('delete_confirm'))) return;
        deleteFormRef.current?.submit();
    }, [canEdit, personId]);

    const cancelEdit = useCallback(() => {
        setEditing(false);
        setFormState(initialState);
        setFieldErrors({});
        setMessage(null);
        setError(null);
    }, [initialState]);


    const updateField = (key: string, value: string) => {
        setFormState((prev) => applyDerivedFields({
            ...prev,
            [key]: value,
        }));
        setFieldErrors((prev) => {
            const next = { ...prev };
            delete next[key];

            return next;
        });
        setMessage(null);
        setError(null);
    };

    const generatePinyin = async () => {
        setGeneratingPinyin(true);
        setMessage(null);
        setError(null);

        try {
            const [surname, mingzi] = await Promise.all([
                fetchPinyinValue(pinyinEndpoint, formState.c_surname_chn ?? ''),
                fetchPinyinValue(pinyinEndpoint, formState.c_mingzi_chn ?? '', false),
            ]);

            setFormState((prev) => applyGeneratedPinyin(prev, initialState, surname, mingzi));
            setMessage(t('pinyin_generated'));
        } catch (err) {
            setError(err instanceof Error ? err.message : t('pinyin_failed'));
        } finally {
            setGeneratingPinyin(false);
        }
    };

    const save = async (): Promise<boolean> => {
        if (!form) {
            return false;
        }

        setSaving(true);
        setFieldErrors({});
        setMessage(null);
        setError(null);

        try {
            const response = await fetch(mutateEndpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    resource: 'basicinformation',
                    person_id: personId,
                    mode: 'direct',
                    operation: 'update',
                    target: {
                        pk: {
                            c_personid: personId,
                        },
                    },
                    changes: buildSaveChanges(form, formState),
                }),
            });

            const json = await response.json().catch(() => ({}));

            if (!response.ok || !json?.ok) {
                if (json?.errors && typeof json.errors === 'object') {
                    setFieldErrors(json.errors as FieldErrors);
                }
                throw new Error(firstErrorMessage(json) || `${t('save_failed')}（HTTP ${response.status}）`);
            }

            setEditing(false);
            setMessage(t('saved_success'));
            onSaved?.();
            return true;
        } catch (err) {
            setError(err instanceof Error ? err.message : t('save_failed'));
            scrollPanelToTop(panelRef);
            return false;
        } finally {
            setSaving(false);
        }
    };

    useEffect(() => {
        onRegisterSaveHandler?.(editing ? save : null);
    }, [editing, onRegisterSaveHandler, save]);

    return (
        <div ref={panelRef} style={{
            ...panelStyle,
            ...(editing ? editingPanelStyle : {}),
        }}
        >
            {form ? (
                <div style={toolbarStyle}>
                    <div>
                        <div style={toolbarTitleStyle}>{t('basic_info')}</div>
                        {editing ? <div style={editingHintStyle}>{t('editing_hint')}</div> : null}
                    </div>
                    <div style={toolbarButtonGroupStyle}>
                        {!editing && canEdit ? (
                            <>
                                <button type="button" style={primaryButtonStyle} onClick={onEditClick ?? beginEdit}>
                                    {t('edit_basic_info')}
                                </button>
                                <form ref={deleteFormRef} method="POST" action={`/basicinformation/${personId}`} style={{ display: 'none' }}>
                                    <input type="hidden" name="_method" value="DELETE" />
                                    <input type="hidden" name="_token" value={getCsrfToken()} />
                                </form>
                            </>
                        ) : editing ? (
                            <>
                                <button
                                    type="button"
                                    style={neutralButtonStyle}
                                    onClick={cancelEdit}
                                    disabled={saving}
                                >
                                    {tCommon('cancel')}
                                </button>
                                <button
                                    type="button"
                                    style={primaryButtonStyle}
                                    onClick={save}
                                    disabled={saving}
                                >
                                    {saving ? t('saving') : t('save_and_continue')}
                                </button>
                            </>
                        ) : null}
                    </div>
                </div>
            ) : null}

            {message ? <div style={successMessageStyle}>{message}</div> : null}
            {error ? <div style={errorMessageStyle}>{error}</div> : null}

            {editing && form ? renderEditor(
                form.fields,
                formState,
                dirtyFields,
                fieldErrors,
                updateField,
                generatePinyin,
                generatingPinyin,
                save,
                saving,
                cancelEdit,
                t,
                tCommon,
            ) : (
                <div style={{ paddingBottom: 16 }}>
                    {sections.map((section, index) => (
                        <div key={section.title} style={index === 0 ? sectionStyle : sectionWithDividerStyle}>
                            {renderReadOnlySection(section, undefined, editableKeys)}
                        </div>
                    ))}
                    {!editing && canEdit && !hideDelete ? (
                        <div style={dangerActionWrapStyle}>
                            <button type="button" style={dangerButtonStyle} onClick={handleDelete}>
                                {t('delete_person')}
                            </button>
                        </div>
                    ) : null}
                </div>
            )}
        </div>
    );
}

function renderEditor(
    fields: Record<string, FormField>,
    values: FormState,
    dirtyFields: Set<string>,
    errors: FieldErrors,
    onChange: (key: string, value: string) => void,
    onGeneratePinyin?: () => void,
    generatingPinyin?: boolean,
    onSave?: () => void,
    saving?: boolean,
    onCancel?: () => void,
    t?: (key: string) => string,
    tCommon?: (key: string) => string,
) {
    return (
        <>
            <div style={sectionStyle}>
                <SectionHeading title="姓名資料" />
                <div style={editorNameGroupGridStyle}>
                    <EditorGroup
                        title="中文"
                        fields={[
                            'c_surname_chn',
                            'c_mingzi_chn',
                            'c_name_chn',
                        ]}
                        allFields={fields}
                        values={values}
                        dirtyFields={dirtyFields}
                        errors={errors}
                        onChange={onChange}
                        footer={onGeneratePinyin ? (
                            <div style={inlineActionWrapStyle}>
                                <button
                                    type="button"
                                    style={secondaryButtonStyle}
                                    onClick={onGeneratePinyin}
                                    disabled={generatingPinyin || saving}
                                >
                                    {generatingPinyin ? (t ? t('generating_pinyin') : '生成中…') : (t ? t('generate_pinyin') : '生成人名拼音')}
                                </button>
                            </div>
                        ) : null}
                    />
                    <EditorGroup
                        title="拼音"
                        fields={[
                            'c_surname',
                            'c_mingzi',
                            'c_name',
                        ]}
                        allFields={fields}
                        values={values}
                        dirtyFields={dirtyFields}
                        errors={errors}
                        onChange={onChange}
                    />
                    <EditorGroup
                        title="外文"
                        fields={[
                            'c_surname_proper',
                            'c_mingzi_proper',
                            'c_name_proper',
                        ]}
                        allFields={fields}
                        values={values}
                        dirtyFields={dirtyFields}
                        errors={errors}
                        onChange={onChange}
                    />
                    <EditorGroup
                        title="外文羅馬字轉寫"
                        fields={[
                            'c_surname_rm',
                            'c_mingzi_rm',
                            'c_name_rm',
                        ]}
                        allFields={fields}
                        values={values}
                        dirtyFields={dirtyFields}
                        errors={errors}
                        onChange={onChange}
                    />
                </div>
            </div>

            <div style={sectionWithDividerStyle}>
                <SectionHeading title="基本屬性" />
                <div style={editorCompactGridStyle}>
                    {[
                        'c_female',
                        'c_dy',
                        'c_ethnicity_code',
                        'c_choronym_code',
                        'c_household_status_code',
                    ].map((key) => (
                        <EditorField
                            key={key}
                            field={fields[key]}
                            value={values[key] ?? ''}
                            dirty={dirtyFields.has(key)}
                            error={errors[key]}
                            onChange={onChange}
                        />
                    ))}
                </div>
            </div>

            <div style={sectionWithDividerStyle}>
                <SectionHeading title="生卒年" />
                <div style={timelineGridStyle}>
                    <EditorTimelineCard
                        title="生年"
                        fieldKeys={[
                            'c_birthyear',
                            'c_by_nh_code',
                            'c_by_nh_year',
                            'c_by_range',
                            'c_by_intercalary',
                            'c_by_month',
                            'c_by_day',
                            'c_by_day_gz',
                        ]}
                        fields={fields}
                        values={values}
                        dirtyFields={dirtyFields}
                        errors={errors}
                        onChange={onChange}
                    />
                    <EditorTimelineCard
                        title="卒年"
                        fieldKeys={[
                            'c_deathyear',
                            'c_dy_nh_code',
                            'c_dy_nh_year',
                            'c_dy_range',
                            'c_dy_intercalary',
                            'c_dy_month',
                            'c_dy_day',
                            'c_dy_day_gz',
                        ]}
                        fields={fields}
                        values={values}
                        dirtyFields={dirtyFields}
                        errors={errors}
                        onChange={onChange}
                    />
                </div>
                <div style={editorCompactGridStyle}>
                    <EditorField field={fields.c_death_age} value={values.c_death_age ?? ''} dirty={dirtyFields.has('c_death_age')} error={errors.c_death_age} onChange={onChange} />
                    <EditorField field={fields.c_death_age_range} value={values.c_death_age_range ?? ''} dirty={dirtyFields.has('c_death_age_range')} error={errors.c_death_age_range} onChange={onChange} />
                </div>
            </div>

            <div style={sectionWithDividerStyle}>
                <SectionHeading title="活動年份" />
                <div style={timelineGridStyle}>
                    <EditorTimelineCard
                        title="在世始年 (c_fl_earliest_year)"
                        fieldKeys={[
                            'c_fl_earliest_year',
                            'c_fl_ey_nh_code',
                            'c_fl_ey_nh_year',
                            'c_fl_ey_notes',
                        ]}
                        fields={fields}
                        values={values}
                        dirtyFields={dirtyFields}
                        errors={errors}
                        onChange={onChange}
                    />
                    <EditorTimelineCard
                        title="在世終年 (c_fl_latest_year)"
                        fieldKeys={[
                            'c_fl_latest_year',
                            'c_fl_ly_nh_code',
                            'c_fl_ly_nh_year',
                            'c_fl_ly_notes',
                        ]}
                        fields={fields}
                        values={values}
                        dirtyFields={dirtyFields}
                        errors={errors}
                        onChange={onChange}
                    />
                </div>
            </div>

            <div style={sectionWithDividerStyle}>
                <SectionHeading title="備註" />
                <EditorField
                    field={fields.c_notes}
                    value={values.c_notes ?? ''}
                    dirty={dirtyFields.has('c_notes')}
                    error={errors.c_notes}
                    onChange={onChange}
                    fullWidth
                />
            </div>

            <div style={sectionWithDividerStyle}>
                <SectionHeading title="指數資料" />
                <div style={indexSectionStackStyle}>
                    <div style={indexYearRowStyle}>
                        {[
                            'c_index_year',
                            'c_index_year_type_code',
                            'c_index_year_source_id',
                        ].map((key) => (
                            <EditorField
                                key={key}
                                field={fields[key]}
                                value={values[key] ?? ''}
                                dirty={dirtyFields.has(key)}
                                error={errors[key]}
                                onChange={onChange}
                            />
                        ))}
                    </div>
                    <div style={indexAddressRowStyle}>
                        {[
                            'c_index_addr_id',
                            'c_index_addr_type_code',
                        ].map((key) => (
                            <EditorField
                                key={key}
                                field={fields[key]}
                                value={values[key] ?? ''}
                                dirty={dirtyFields.has(key)}
                                error={errors[key]}
                                onChange={onChange}
                            />
                        ))}
                    </div>
                </div>
            </div>

            <div style={sectionWithDividerStyle}>
                <SectionHeading title={t ? t('create_or_modify') : '建立 / 修改資訊'} />
                {/* 對齊舊頁 audit_display_hint：此區塊為顯示用、不提交（§0.2 不可遺漏舊頁說明文字）。 */}
                <small style={fieldHintStyle} className="text-muted">此為顯示用資訊，不會包含在表單提交中</small>
                <div style={editorCompactGridStyle}>
                    {[
                        'c_created_by',
                        'c_created_date',
                        'c_modified_by',
                        'c_modified_date',
                    ].map((key) => (
                        <EditorField
                            key={key}
                            field={fields[key]}
                            value={values[key] ?? ''}
                            dirty={dirtyFields.has(key)}
                            error={errors[key]}
                            onChange={onChange}
                        />
                    ))}
                </div>
            </div>

            <div style={editorBottomBarStyle}>
                <div style={editorBottomBarHintStyle}>{t ? t('save_hint') : '確認內容後再整頁儲存，儲存將調用 /api/v2/mutate 更新 BIOG_MAIN。'}</div>
                <div style={editorBottomBarButtonsStyle}>
                    <button
                        type="button"
                        style={neutralButtonStyle}
                        onClick={onCancel}
                        disabled={saving}
                    >
                        {tCommon ? tCommon('cancel') : '取消'}
                    </button>
                    <button
                        type="button"
                        style={primaryButtonStyle}
                        onClick={onSave}
                        disabled={saving}
                    >
                        {saving ? (t ? t('saving') : '儲存中…') : (t ? t('save_and_continue') : '整頁儲存')}
                    </button>
                </div>
            </div>
        </>
    );
}

function EditorGroup({
    title,
    fields,
    allFields,
    values,
    dirtyFields,
    errors,
    onChange,
    footer,
}: {
    title: string;
    fields: string[];
    allFields: Record<string, FormField>;
    values: FormState;
    dirtyFields: Set<string>;
    errors: FieldErrors;
    onChange: (key: string, value: string) => void;
    footer?: React.ReactNode;
}) {
    return (
        <div style={editorNameGroupStyle}>
            <div style={nameGroupTitleStyle}>{title}</div>
            <div style={stackedFieldGroupStyle}>
                {fields.map((key) => (
                    <EditorField
                        key={key}
                        field={allFields[key]}
                        value={values[key] ?? ''}
                        dirty={dirtyFields.has(key)}
                        error={errors[key]}
                        onChange={onChange}
                    />
                ))}
                {footer}
            </div>
        </div>
    );
}

function EditorTimelineCard({
    title,
    fieldKeys,
    fields,
    values,
    dirtyFields,
    errors,
    onChange,
}: {
    title: string;
    fieldKeys: string[];
    fields: Record<string, FormField>;
    values: FormState;
    dirtyFields: Set<string>;
    errors: FieldErrors;
    onChange: (key: string, value: string) => void;
}) {
    return (
        <div style={timelineCardStyle}>
            <div style={timelineTitleStyle}>{title}</div>
            <div style={editorTimelineItemsGridStyle}>
                {fieldKeys.map((key) => (
                    <EditorField
                        key={key}
                        field={fields[key]}
                        value={values[key] ?? ''}
                        dirty={dirtyFields.has(key)}
                        error={errors[key]}
                        onChange={onChange}
                        fullWidth={key.endsWith('_notes')}
                    />
                ))}
            </div>
        </div>
    );
}

function EditorField({
    field,
    value,
    dirty = false,
    error,
    onChange,
    fullWidth = false,
}: {
    field?: FormField;
    value: string;
    dirty?: boolean;
    error?: string[];
    onChange: (key: string, value: string) => void;
    fullWidth?: boolean;
}) {
    if (!field) {
        return null;
    }

    const hint = FIELD_HINTS[field.key];

    if (!field.editable) {
        return (
            <ReadOnlyField
                label={field.label}
                value={field.derived ? value : (field.display_value ?? value)}
                fullWidth={fullWidth}
                derived
                dirty={dirty}
                hint={hint}
            />
        );
    }

    return (
        <div
            style={{
                ...fieldWrapStyle,
                ...(fullWidth ? fullWidthStyle : {}),
            }}
        >
            <div style={{ ...fieldLabelStyle, ...(dirty ? dirtyFieldLabelStyle : {}) }}>{field.label}</div>
            {renderInputControl(field, value, onChange, dirty)}
            {hint ? <small style={fieldHintStyle} className="text-muted">{hint}</small> : null}
            {error && error.length > 0 ? <div style={fieldErrorStyle}>{error[0]}</div> : null}
        </div>
    );
}

function renderInputControl(
    field: FormField,
    value: string,
    onChange: (key: string, value: string) => void,
    dirty = false,
) {
    if (field.input === 'textarea') {
        return (
            <textarea
                value={value}
                style={{
                    ...textareaStyle,
                    ...(dirty ? dirtyInputStyle : {}),
                }}
                rows={field.key === 'c_notes' ? 5 : 3}
                onChange={(event) => onChange(field.key, event.target.value)}
            />
        );
    }

    if (field.input === 'enum') {
        return (
            <EnumAutocompleteField
                field={field}
                value={value}
                dirty={dirty}
                onChange={(next) => onChange(field.key, next)}
            />
        );
    }

    if (field.input === 'checkbox') {
        return (
            <label style={{
                ...checkboxWrapStyle,
                ...(dirty ? dirtyInputStyle : {}),
            }}>
                <input
                    type="checkbox"
                    checked={value === '1'}
                    style={checkboxInputStyle}
                    onChange={(event) => onChange(field.key, event.target.checked ? '1' : '0')}
                />
                <span style={checkboxLabelStyle}>{value === '1' ? '閏月' : '平月'}</span>
            </label>
        );
    }

    return (
        <input
            type={field.input === 'number' ? 'number' : 'text'}
            value={value}
            style={{
                ...inputStyle,
                ...(dirty ? dirtyInputStyle : {}),
            }}
            onChange={(event) => onChange(field.key, event.target.value)}
        />
    );
}

function EnumAutocompleteField({
    field,
    value,
    dirty = false,
    onChange,
}: {
    field: FormField;
    value: string;
    dirty?: boolean;
    onChange: (value: string) => void;
}) {
    const localOptions = useMemo(() => field.options ?? [], [field.options]);
    const [remoteOptions, setRemoteOptions] = useState<EnumOption[]>(localOptions);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    useEffect(() => {
        let cancelled = false;

        if (field.options && field.options.length > 0) {
            setRemoteOptions(field.options);
            return () => {
                cancelled = true;
            };
        }

        if (!field.enum_model) {
            setRemoteOptions([]);
            return () => {
                cancelled = true;
            };
        }

        fetchEnumOptions(field.enum_model, field.id_key)
            .then((options) => {
                if (!cancelled) {
                    setRemoteOptions(options);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setRemoteOptions([]);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [field.enum_model, field.id_key, field.options]);

    const selectedOption = remoteOptions.find((option) => option.value === value);

    useEffect(() => {
        setQuery(selectedOption?.label ?? '');
    }, [selectedOption?.label, field.key]);

    const effectiveQuery = open && query === (selectedOption?.label ?? '') ? '' : query;
    const filtered = filterEnumOptions(remoteOptions, effectiveQuery);

    return (
        <div style={enumWrapStyle}>
            <input
                type="text"
                value={query}
                style={{
                    ...inputStyle,
                    ...(dirty ? dirtyInputStyle : {}),
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => {
                    window.setTimeout(() => {
                        setOpen(false);
                        if (query.trim() === '') {
                            onChange('');
                            setQuery('');

                            return;
                        }

                        const exact = findMatchingOption(remoteOptions, query);
                        if (exact) {
                            onChange(exact.value);
                            setQuery(exact.label);

                            return;
                        }

                        setQuery(selectedOption?.label ?? '');
                    }, 120);
                }}
                onChange={(event) => {
                    const next = event.target.value;
                    setQuery(next);
                    setOpen(true);
                    if (next === '') {
                        onChange('');
                    }
                }}
            />
            {open && filtered.length > 0 ? (
                <div style={dropdownStyle}>
                    {filtered.map((option) => (
                        <button
                            key={`${field.key}-${option.value}`}
                            type="button"
                            style={dropdownItemStyle}
                            onMouseDown={(event) => {
                                event.preventDefault();
                                onChange(option.value);
                                setQuery(option.label);
                                setOpen(false);
                            }}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

function extractFieldKey(label: string): string | null {
    const match = label.match(/\(([^)]+)\)\s*$/);
    return match ? match[1] : null;
}

function editableClickHandler(label: string, editableKeys: Set<string>, onClickEdit?: () => void): (() => void) | undefined {
    if (!onClickEdit) return undefined;
    const key = extractFieldKey(label);
    if (key && editableKeys.has(key)) return onClickEdit;
    // 允許直接傳入 field key（無括號）
    if (editableKeys.has(label)) return onClickEdit;
    return undefined;
}

function renderReadOnlySection(section: Section, onClickEdit?: () => void, editableKeys?: Set<string>) {
    const ek = editableKeys ?? new Set<string>();
    switch (section.title) {
        case '姓名資料':
            return renderNameSection(section, onClickEdit, ek);
        case '生卒年':
            return renderLifeSection(section, onClickEdit, ek);
        case '基本屬性':
            return renderPropertySection(section, onClickEdit, ek);
        case '指數資料':
            return renderIndexSection(section, onClickEdit, ek);
        case '活動年份':
            return renderActiveYearsSection(section, onClickEdit, ek);
        case '備註':
            return renderNotesSection(section, onClickEdit, ek);
        case '建立 / 修改資訊':
            return renderAuditSection(section);
        default:
            return renderFallbackSection(section);
    }
}

function renderNameSection(section: Section, onClickEdit?: () => void, editableKeys?: Set<string>) {
    const fields = fieldMap(section);
    const groups = [
        {
            title: '中文',
            items: [
                ['中文姓 (c_surname_chn)', fields['中文姓']],
                ['中文名 (c_mingzi_chn)', fields['中文名']],
                ['中文姓名 (c_name_chn)', fields['姓名']],
            ] as Array<[string, FieldValue]>,
        },
        {
            title: '拼音',
            items: [
                ['拼音姓 (c_surname)', fields['Xing']],
                ['拼音名 (c_mingzi)', fields['Ming']],
                ['拼音姓名 (c_name)', fields['姓名拼音']],
            ] as Array<[string, FieldValue]>,
        },
        {
            title: '外文',
            items: [
                ['外文姓 (c_surname_proper)', fields['外文姓']],
                ['外文名 (c_mingzi_proper)', fields['外文名']],
                ['外文姓名 (c_name_proper)', fields['外文全名']],
            ] as Array<[string, FieldValue]>,
        },
        {
            title: '外文羅馬字轉寫',
            items: [
                ['外文羅馬字轉寫姓 (c_surname_rm)', fields['外文羅馬字轉寫姓']],
                ['外文羅馬字轉寫名 (c_mingzi_rm)', fields['外文羅馬字轉寫名']],
                ['外文羅馬字轉寫姓名 (c_name_rm)', fields['外文羅馬字轉寫姓名']],
            ] as Array<[string, FieldValue]>,
        },
    ];

    return (
        <>
            <SectionHeading title={section.title} />
            <div style={nameGroupGridStyle}>
                {groups.map((group) => (
                    <div key={group.title} style={nameGroupCardStyle}>
                        <div style={nameGroupTitleStyle}>{group.title}</div>
                        <div style={stackedFieldGroupStyle}>
                            {group.items.map(([label, sectionValue], index) => (
                                <ReadOnlyField
                                    key={label}
                                    label={label}
                                    value={sectionValue}
                                    derived={index === group.items.length - 1}
                                    onClickEdit={editableClickHandler(label, editableKeys ?? new Set(), onClickEdit)}
                                />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </>
    );
}

function renderLifeSection(section: Section, onClickEdit?: () => void, editableKeys?: Set<string>) {
    const fields = fieldMap(section);

    return (
        <>
            <SectionHeading title={section.title} />
            <div style={timelineGridStyle}>
                <TimelineCard
                    title="生年"
                    items={[
                        ['年份 (c_birthyear)', fields['出生年']],
                        ['年號 (c_by_nh_code)', prefixId(fields['出生年號 ID'], String(fields['出生年號'] ?? ''))],
                        ['年號年 (c_by_nh_year)', fields['出生年號年']],
                        ['範圍 (c_by_range)', prefixId(fields['出生年範圍 ID'], String(fields['出生年範圍'] ?? ''))],
                        ['閏月 (c_by_intercalary)', fields['出生閏月']],
                        ['月份 (c_by_month)', fields['出生月']],
                        ['日期 (c_by_day)', fields['出生日']],
                        ['日干支 (c_by_day_gz)', prefixId(fields['出生日時干支 ID'], String(fields['出生日時干支'] ?? ''))],
                    ]}
                    onClickEdit={onClickEdit}
                    editableKeys={editableKeys}
                />
                <TimelineCard
                    title="卒年"
                    items={[
                        ['年份 (c_deathyear)', fields['死亡年']],
                        ['年號 (c_dy_nh_code)', prefixId(fields['死亡年號 ID'], String(fields['死亡年號'] ?? ''))],
                        ['年號年 (c_dy_nh_year)', fields['死亡年號年']],
                        ['範圍 (c_dy_range)', prefixId(fields['死亡年範圍 ID'], String(fields['死亡年範圍'] ?? ''))],
                        ['閏月 (c_dy_intercalary)', fields['死亡閏月']],
                        ['月份 (c_dy_month)', fields['死亡月']],
                        ['日期 (c_dy_day)', fields['死亡日']],
                        ['日干支 (c_dy_day_gz)', prefixId(fields['死亡日時干支 ID'], String(fields['死亡日時干支'] ?? ''))],
                    ]}
                    onClickEdit={onClickEdit}
                    editableKeys={editableKeys}
                />
            </div>
            <div style={compactGridStyle}>
                <ReadOnlyField label="享年 (c_death_age)" value={fields['享年']} onClickEdit={editableClickHandler('c_death_age', editableKeys ?? new Set(), onClickEdit)} />
                <ReadOnlyField label="享年範圍 (c_death_age_range)" value={prefixId(fields['享年範圍 ID'], String(fields['享年範圍'] ?? ''))} onClickEdit={editableClickHandler('c_death_age_range', editableKeys ?? new Set(), onClickEdit)} />
            </div>
        </>
    );
}

function renderPropertySection(section: Section, onClickEdit?: () => void, editableKeys?: Set<string>) {
    const fields = fieldMap(section);
    const mergedFields = [
        { label: '性別 (c_female)', value: fields['性別'] },
        { label: '朝代 (c_dy)', value: joinDisplayValues(fields['朝代（中文）'], fields['朝代（英文）']) },
        { label: '族裔 (c_ethnicity_code)', value: joinDisplayValues(fields['族裔（中文）'], fields['族裔（英文）']) },
        { label: '郡望 (c_choronym_code)', value: joinDisplayValues(fields['郡望（中文）'], fields['郡望（英文）']) },
        { label: '戶籍 (c_household_status_code)', value: joinDisplayValues(fields['戶籍（中文）'], fields['戶籍（英文）']) },
    ];

    return (
        <>
            <SectionHeading title={section.title} />
            <div style={compactGridStyle}>
                {mergedFields.map((field) => (
                    <ReadOnlyField key={field.label} label={field.label} value={field.value} onClickEdit={editableClickHandler(field.label, editableKeys ?? new Set(), onClickEdit)} />
                ))}
            </div>
        </>
    );
}

function renderIndexSection(section: Section, _onClickEdit?: () => void, _editableKeys?: Set<string>) {
    const fields = fieldMap(section);
    const indexYearType = prefixId(fields['Index Year Type'], joinDisplayValues(fields['Index Year Type（中文）'], fields['Index Year Type（英文）']));
    const indexAddress = prefixId(fields['Index Address ID'], joinDisplayValues(fields['Index Address（中文）'], fields['Index Address（英文）']));
    const indexAddrType = prefixId(fields['Index Address Type'], joinDisplayValues(fields['Index Address Type（中文）'], fields['Index Address Type（英文）']));

    return (
        <>
            <SectionHeading title={section.title} />
            <div style={indexSectionStackStyle}>
                <div style={indexYearRowStyle}>
                    <ReadOnlyField label="Index Year (c_index_year)" value={fields['Index Year']} derived />
                    <ReadOnlyField label="Index Year Type (c_index_year_type_code)" value={indexYearType} derived />
                    <ReadOnlyField label="Index Year Source (c_index_year_source_id)" value={fields['Index Year Source']} derived />
                </div>
                <div style={indexAddressRowStyle}>
                    <ReadOnlyField label="Index Address (c_index_addr_id)" value={indexAddress} derived />
                    <ReadOnlyField label="Index Address Type (c_index_addr_type_code)" value={indexAddrType} derived />
                </div>
            </div>
        </>
    );
}

function renderActiveYearsSection(section: Section, onClickEdit?: () => void, editableKeys?: Set<string>) {
    const fields = fieldMap(section);

    return (
        <>
            <SectionHeading title={section.title} />
            <div style={timelineGridStyle}>
                <TimelineCard
                    title="在世始年 (c_fl_earliest_year)"
                    items={[
                        ['西元年份 (c_fl_earliest_year)', fields['在世始年']],
                        ['年號 (c_fl_ey_nh_code)', prefixId(fields['在世始年號 ID'], String(fields['在世始年號'] ?? ''))],
                        ['年號年 (c_fl_ey_nh_year)', fields['在世始年號年']],
                    ]}
                    note={fields['在世始年註']}
                    onClickEdit={onClickEdit}
                    editableKeys={editableKeys}
                />
                <TimelineCard
                    title="在世終年 (c_fl_latest_year)"
                    items={[
                        ['西元年份 (c_fl_latest_year)', fields['在世終年']],
                        ['年號 (c_fl_ly_nh_code)', prefixId(fields['在世終年號 ID'], String(fields['在世終年號'] ?? ''))],
                        ['年號年 (c_fl_ly_nh_year)', fields['在世終年號年']],
                    ]}
                    note={fields['在世終年註']}
                    onClickEdit={onClickEdit}
                    editableKeys={editableKeys}
                />
            </div>
        </>
    );
}

function renderNotesSection(section: Section, onClickEdit?: () => void, editableKeys?: Set<string>) {
    const fields = fieldMap(section);

    return (
        <>
            <SectionHeading title={section.title} />
            <div style={notesLabelStyle}>備註 (c_notes)</div>
            {(() => {
                const handler = editableClickHandler('c_notes', editableKeys ?? new Set(), onClickEdit);
                return (
                    <div
                        style={{
                            ...notesBoxStyle,
                            ...(handler ? clickableFieldStyle : {}),
                        }}
                        onClick={handler}
                        role={handler ? 'button' : undefined}
                        tabIndex={handler ? 0 : undefined}
                        onKeyDown={handler ? (e) => { if (e.key === 'Enter' || e.key === ' ') handler(); } : undefined}
                    >
                        {displayValue(fields['備註'])}
                    </div>
                );
            })()}
        </>
    );
}

function renderAuditSection(section: Section) {
    const fields = fieldMap(section);

    return (
        <>
            <SectionHeading title={section.title} />
            {/* 對齊舊頁 audit_display_hint：此區塊為顯示用、不提交（§0.2 不可遺漏舊頁說明文字）。 */}
            <small style={fieldHintStyle} className="text-muted">此為顯示用資訊，不會包含在表單提交中</small>
            <div style={compactGridStyle}>
                <ReadOnlyField label="Created By (c_created_by)" value={fields['Created By']} />
                <ReadOnlyField label="Created Date (c_created_date)" value={fields['Created Date']} />
                <ReadOnlyField label="Modified By (c_modified_by)" value={fields['Modified By']} />
                <ReadOnlyField label="Modified Date (c_modified_date)" value={fields['Modified Date']} />
            </div>
        </>
    );
}

function renderFallbackSection(section: Section) {
    return (
        <>
            <SectionHeading title={section.title} />
            <div style={compactGridStyle}>
                {section.fields.map((field) => (
                    <ReadOnlyField key={field.label} label={field.label} value={field.value} />
                ))}
            </div>
        </>
    );
}

function SectionHeading({ title, badge }: { title: string; badge?: string | null }) {
    return (
        <div style={sectionHeadingStyle}>
            <h4 style={sectionTitleStyle}>{title}</h4>
            {badge ? <span style={sectionBadgeStyle}>{badge}</span> : null}
        </div>
    );
}

function TimelineCard({
    title,
    items,
    note,
    onClickEdit,
    editableKeys,
}: {
    title: string;
    items: Array<[string, FieldValue]>;
    note?: FieldValue;
    onClickEdit?: () => void;
    editableKeys?: Set<string>;
}) {
    const ek = editableKeys ?? new Set<string>();
    return (
        <div style={timelineCardStyle}>
            <div style={timelineTitleStyle}>{title}</div>
            <div style={timelineItemsGridStyle}>
                {items.map(([label, sectionValue]) => (
                    <ReadOnlyField key={label} label={label} value={sectionValue} onClickEdit={editableClickHandler(label, ek, onClickEdit)} />
                ))}
                {note !== undefined ? (() => { const noteLabel = title.includes('始') ? '備註 (c_fl_ey_notes)' : '備註 (c_fl_ly_notes)'; return <ReadOnlyField label={noteLabel} value={note} fullWidth subtle onClickEdit={editableClickHandler(noteLabel, ek, onClickEdit)} />; })() : null}
            </div>
        </div>
    );
}

function ReadOnlyField({
    label,
    value,
    fullWidth = false,
    muted = false,
    subtle = false,
    emphasis = false,
    derived = false,
    dirty = false,
    hint,
    onClickEdit,
}: {
    label: string;
    value: FieldValue;
    fullWidth?: boolean;
    muted?: boolean;
    subtle?: boolean;
    emphasis?: boolean;
    derived?: boolean;
    dirty?: boolean;
    hint?: string;
    onClickEdit?: () => void;
}) {
    return (
        <div
            style={{
                ...fieldWrapStyle,
                ...(fullWidth ? fullWidthStyle : {}),
            }}
        >
            <div style={{ ...fieldLabelStyle, ...(dirty ? dirtyFieldLabelStyle : {}) }}>{label}</div>
            <div
                style={{
                    ...fieldValueBoxStyle,
                    ...(muted ? mutedValueBoxStyle : {}),
                    ...(subtle ? subtleValueBoxStyle : {}),
                    ...(emphasis ? emphasisValueBoxStyle : {}),
                    ...(derived ? derivedValueBoxStyle : {}),
                    ...(dirty ? dirtyValueBoxStyle : {}),
                    ...(onClickEdit ? clickableFieldStyle : {}),
                }}
                onClick={onClickEdit}
                role={onClickEdit ? 'button' : undefined}
                tabIndex={onClickEdit ? 0 : undefined}
                onKeyDown={onClickEdit ? (e) => { if (e.key === 'Enter' || e.key === ' ') onClickEdit(); } : undefined}
            >
                {displayValue(value)}
            </div>
            {hint ? <small style={fieldHintStyle} className="text-muted">{hint}</small> : null}
        </div>
    );
}

function buildInitialState(form?: BasicInfoForm | null): FormState {
    if (!form?.fields) {
        return {};
    }

    const next: FormState = {};
    Object.entries(form.fields).forEach(([key, field]) => {
        next[key] = field.value == null ? '' : String(field.value);
    });

    return applyDerivedFields(next);
}

function hasFormChanges(form: BasicInfoForm | null | undefined, initialState: FormState, currentState: FormState): boolean {
    if (!form?.fields) {
        return false;
    }

    return Object.keys(form.fields).some((key) => (initialState[key] ?? '') !== (currentState[key] ?? ''));
}

function buildDirtyFieldSet(form: BasicInfoForm | null | undefined, initialState: FormState, currentState: FormState): Set<string> {
    if (!form?.fields) {
        return new Set();
    }

    return new Set(
        Object.keys(form.fields).filter((key) => (initialState[key] ?? '') !== (currentState[key] ?? '')),
    );
}

function applyDerivedFields(next: FormState): FormState {
    const updated = { ...next };

    updated.c_name_chn = `${updated.c_surname_chn ?? ''}${updated.c_mingzi_chn ?? ''}`;
    updated.c_name = joinWithSpace(updated.c_surname, updated.c_mingzi);
    updated.c_name_proper = joinWithSpace(updated.c_mingzi_proper, updated.c_surname_proper);
    updated.c_name_rm = joinWithSpace(updated.c_mingzi_rm, updated.c_surname_rm);

    return updated;
}

function applyGeneratedPinyin(prev: FormState, initialState: FormState, surname: string, mingzi: string): FormState {
    const next = applyDerivedFields({
        ...prev,
        c_surname: surname,
        c_mingzi: mingzi,
    });

    ['c_surname', 'c_mingzi', 'c_name'].forEach((key) => {
        if ((next[key] ?? '') === (initialState[key] ?? '')) {
            next[key] = initialState[key] ?? '';
        }
    });

    return next;
}

function buildSaveChanges(form: BasicInfoForm, values: FormState): Record<string, string | null> {
    const changes: Record<string, string | null> = {};

    Object.entries(form.fields).forEach(([key, field]) => {
        if (field.send_on_save === false || key === 'c_personid') {
            return;
        }

        let value = values[key] ?? '';

        // enum（含 c_female）及 FK 欄位：空字串視為「未選擇」，送 null
        if (field.input === 'enum' && value === '') {
            changes[key] = null;

            return;
        }

        if ((key === 'c_by_intercalary' || key === 'c_dy_intercalary') && value === '') {
            value = '0';
        }

        changes[key] = value;
    });

    return changes;
}

function joinWithSpace(left?: string, right?: string): string {
    return [left ?? '', right ?? '']
        .map((part) => part.trim())
        .filter((part) => part !== '')
        .join(' ');
}

async function fetchPinyinValue(endpoint: string, query: string, split: boolean = true): Promise<string> {
    if (!query.trim()) {
        return '';
    }

    const splitParam = split ? '' : '&split=0';
    const response = await fetch(`${endpoint}?q=${encodeURIComponent(query)}${splitParam}`, {
        headers: {
            'Accept': 'text/plain',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`生成拼音失敗（HTTP ${response.status}）`);
    }

    return (await response.text()).trim();
}

function getCsrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function firstErrorMessage(json: unknown): string | null {
    if (!json || typeof json !== 'object') {
        return null;
    }

    const candidate = json as { message?: string; errors?: Record<string, string[]> };
    if (candidate.message) {
        return candidate.message;
    }

    if (candidate.errors) {
        const first = Object.values(candidate.errors)[0];
        if (Array.isArray(first) && first.length > 0) {
            return first[0];
        }
    }

    return null;
}

async function fetchEnumOptions(model: string, idKey?: string): Promise<EnumOption[]> {
    const cacheKey = `${model}:${idKey ?? ''}`;
    if (!enumOptionCache.has(cacheKey)) {
        enumOptionCache.set(cacheKey, (async () => {
            const response = await fetch(`/api/select/${model}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`載入選項失敗（${model}）`);
            }

            const data = await response.json();
            if (!Array.isArray(data)) {
                return [];
            }

            return data.map((item) => normalizeEnumOption(model, item, idKey)).filter(Boolean) as EnumOption[];
        })());
    }

    return enumOptionCache.get(cacheKey)!;
}

function normalizeEnumOption(model: string, item: Record<string, unknown>, idKey?: string): EnumOption | null {
    const preferredIdKey = idKey || modelIdKeyMap[model];
    const value = preferredIdKey && preferredIdKey in item
        ? item[preferredIdKey]
        : guessOptionValue(item);

    if (value == null) {
        return null;
    }

    return {
        value: String(value),
        label: normalizeOptionLabel(model, item),
    };
}

function normalizeOptionLabel(model: string, item: Record<string, unknown>): string {
    const hidden = hiddenFields[model] || [];

    return Object.entries(item)
        .filter(([key]) => !hidden.includes(key))
        .map(([, value]) => (value == null ? '' : String(value).trim()))
        .filter((value) => value !== '')
        .join(' ')
        .trim();
}

function guessOptionValue(item: Record<string, unknown>): unknown {
    const keys = Object.keys(item);
    const suffixPriority = ['_id', '_code'];

    for (const suffix of suffixPriority) {
        const match = keys.find((key) => key.endsWith(suffix));
        if (match) {
            return item[match];
        }
    }

    return keys.length > 0 ? item[keys[0]] : null;
}

function filterEnumOptions(options: EnumOption[], query: string): EnumOption[] {
    const normalized = query.trim().toLowerCase();
    if (!normalized) {
        return options;
    }

    return options.filter((option) => {
        const haystack = `${option.value} ${option.label}`.toLowerCase();

        return haystack.includes(normalized);
    });
}

function findMatchingOption(options: EnumOption[], query: string): EnumOption | undefined {
    const normalized = query.trim().toLowerCase();

    return options.find((option) => option.label.toLowerCase() === normalized || option.value.toLowerCase() === normalized);
}

function fieldMap(section: Section): Record<string, FieldValue> {
    return Object.fromEntries(section.fields.map((field) => [field.label, field.value]));
}

function displayValue(value: FieldValue) {
    if (value == null || value === '') {
        return '—';
    }

    return String(value);
}

function joinDisplayValues(left: FieldValue, right: FieldValue) {
    const parts = [left, right]
        .map((value) => (value == null ? '' : String(value).trim()))
        .filter((value) => value !== '');

    if (parts.length === 0) {
        return '—';
    }

    return parts.join(' / ');
}

function prefixId(id: FieldValue, display: string): string {
    if (id == null || id === '' || display === '—') {
        return display;
    }

    return `[${id}] ${display}`;
}

function scrollPanelToTop(panelRef: React.RefObject<HTMLDivElement | null>) {
    window.requestAnimationFrame(() => {
        panelRef.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });
    });
}

const modelIdKeyMap: Record<string, string> = {
    ethnicity: 'c_ethnicity_code',
    choronym: 'c_choronym_code',
    dynasty: 'c_dy',
    nianhao: 'c_nianhao_id',
    range: 'c_range_code',
    ganzhi: 'c_ganzhi_code',
    household: 'c_household_status_code',
};

const panelStyle: React.CSSProperties = {
    backgroundColor: '#fff',
    border: '1px solid #d3dae3',
    borderRadius: 10,
    boxShadow: '0 1px 3px rgba(15, 23, 42, 0.05)',
    overflow: 'hidden',
};

const editingPanelStyle: React.CSSProperties = {
    borderColor: '#7ea8cc',
    boxShadow: '0 0 0 3px rgba(53, 111, 161, 0.08), 0 4px 16px rgba(15, 23, 42, 0.08)',
};

const toolbarStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    flexWrap: 'wrap',
    padding: '16px 20px',
    borderBottom: '1px solid #e8edf3',
    backgroundColor: '#fbfcfe',
};

const toolbarTitleStyle: React.CSSProperties = {
    fontSize: '1rem',
    fontWeight: 700,
    color: '#213445',
};

const editingHintStyle: React.CSSProperties = {
    marginTop: 4,
    fontSize: '0.82rem',
    color: '#5f7891',
};

const toolbarButtonGroupStyle: React.CSSProperties = {
    display: 'flex',
    flexWrap: 'wrap',
    gap: 10,
};

const buttonBaseStyle: React.CSSProperties = {
    borderRadius: 8,
    padding: '8px 14px',
    fontSize: '0.9rem',
    fontWeight: 700,
    cursor: 'pointer',
    border: '1px solid transparent',
};

const primaryButtonStyle: React.CSSProperties = {
    ...buttonBaseStyle,
    backgroundColor: APP_THEME.brand,
    color: '#fff',
};

const secondaryButtonStyle: React.CSSProperties = {
    ...buttonBaseStyle,
    backgroundColor: '#f0f6fb',
    color: '#1f527c',
    borderColor: '#b9cfe2',
};

const neutralButtonStyle: React.CSSProperties = {
    ...buttonBaseStyle,
    backgroundColor: '#fff',
    color: '#4f6274',
    borderColor: '#cdd7e1',
};

const dangerActionWrapStyle: React.CSSProperties = {
    display: 'flex',
    justifyContent: 'flex-end',
    padding: '8px 16px 0',
};

const dangerButtonStyle: React.CSSProperties = {
    ...buttonBaseStyle,
    backgroundColor: '#fff',
    color: '#dc3545',
    borderColor: '#dc3545',
};

const successMessageStyle: React.CSSProperties = {
    margin: '16px 20px 0',
    padding: '10px 12px',
    borderRadius: 8,
    backgroundColor: '#ecf8ef',
    border: '1px solid #bedfca',
    color: '#25603a',
    fontSize: '0.88rem',
};

const errorMessageStyle: React.CSSProperties = {
    margin: '16px 20px 0',
    padding: '10px 12px',
    borderRadius: 8,
    backgroundColor: '#fff1f1',
    border: '1px solid #e3bcbc',
    color: '#a03131',
    fontSize: '0.88rem',
};

const sectionStyle: React.CSSProperties = {
    margin: '16px 16px 0',
    padding: '20px 20px 24px',
    backgroundColor: '#f8fafc',
    border: '1px solid #dfe6ee',
    borderRadius: 10,
};

const sectionWithDividerStyle: React.CSSProperties = {
    ...sectionStyle,
};

const sectionHeadingStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    gap: 12,
    marginBottom: 16,
    padding: '10px 14px',
    backgroundColor: APP_THEME.brandSurface,
    borderRadius: 6,
    borderLeft: `3px solid ${APP_THEME.brand}`,
    flexWrap: 'wrap',
};

const sectionTitleStyle: React.CSSProperties = {
    margin: 0,
    fontSize: '1.08rem',
    fontWeight: 700,
    color: APP_THEME.brandTextStrong,
    letterSpacing: '0.01em',
};

const sectionBadgeStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    padding: '5px 10px',
    borderRadius: 999,
    backgroundColor: APP_THEME.brandSurfaceStrong,
    color: APP_THEME.brandText,
    fontSize: '0.78rem',
    fontWeight: 700,
};

const nameGroupGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
    gap: 16,
};

const editorNameGroupGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(250px, 1fr))',
    gap: 16,
};

const nameGroupCardStyle: React.CSSProperties = {
    padding: '14px 14px 16px',
    border: '1px solid #dfe6ee',
    borderRadius: 8,
    backgroundColor: '#fdfdfe',
};

const editorNameGroupStyle: React.CSSProperties = {
    padding: '14px 14px 16px',
    border: '1px solid #c9d9e8',
    borderRadius: 10,
    backgroundColor: '#f7fbff',
};

const nameGroupTitleStyle: React.CSSProperties = {
    fontSize: '0.88rem',
    fontWeight: 700,
    color: '#6b2533',
    marginBottom: 12,
    textAlign: 'center',
};

const stackedFieldGroupStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 12,
};

const compactGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
    gap: 14,
};

const editorCompactGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))',
    gap: 16,
};

const indexSectionStackStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 14,
};

const indexYearRowStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(3, minmax(0, 1fr))',
    gap: 14,
};

const indexAddressRowStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(2, minmax(0, 1fr))',
    gap: 14,
};

const timelineGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))',
    gap: 16,
    marginBottom: 14,
};

const timelineCardStyle: React.CSSProperties = {
    padding: 16,
    border: '1px solid #dfe6ee',
    borderRadius: 8,
    backgroundColor: '#fdfdfe',
};

const timelineTitleStyle: React.CSSProperties = {
    fontSize: '0.94rem',
    fontWeight: 700,
    color: '#6b2533',
    marginBottom: 12,
    textAlign: 'center',
};

const timelineItemsGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
    gap: 12,
};

const editorTimelineItemsGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
    gap: 12,
};

const fieldWrapStyle: React.CSSProperties = {
    minWidth: 0,
};

const fullWidthStyle: React.CSSProperties = {
    gridColumn: '1 / -1',
};

const fieldLabelStyle: React.CSSProperties = {
    fontSize: '0.84rem',
    fontWeight: 600,
    color: '#556677',
    marginBottom: 6,
    textAlign: 'left',
    whiteSpace: 'nowrap',
    overflow: 'hidden',
    textOverflow: 'ellipsis',
};

const dirtyFieldLabelStyle: React.CSSProperties = {
    color: '#8a5a15',
};

const fieldValueBoxStyle: React.CSSProperties = {
    minHeight: 42,
    padding: '0 12px',
    boxSizing: 'border-box',
    borderRadius: 8,
    border: '1px solid #cfd7e2',
    backgroundColor: '#fff',
    color: '#1f2d3d',
    fontSize: '0.92rem',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    textAlign: 'center',
    lineHeight: 1.35,
    wordBreak: 'break-word',
    boxShadow: 'inset 0 1px 2px rgba(15, 23, 42, 0.04)',
};

const inputStyle: React.CSSProperties = {
    width: '100%',
    minHeight: 42,
    padding: '0 12px',
    boxSizing: 'border-box',
    borderRadius: 8,
    border: '1px solid #92b2d1',
    backgroundColor: '#fffdf4',
    color: '#163049',
    textAlign: 'center',
    boxShadow: 'inset 0 1px 2px rgba(15, 23, 42, 0.04), 0 0 0 1px rgba(146, 178, 209, 0.08)',
};

const textareaStyle: React.CSSProperties = {
    width: '100%',
    padding: '10px 12px',
    boxSizing: 'border-box',
    borderRadius: 8,
    border: '1px solid #92b2d1',
    backgroundColor: '#fffdf4',
    color: '#163049',
    textAlign: 'left',
    lineHeight: 1.6,
    boxShadow: 'inset 0 1px 2px rgba(15, 23, 42, 0.04), 0 0 0 1px rgba(146, 178, 209, 0.08)',
    resize: 'vertical',
};

const enumWrapStyle: React.CSSProperties = {
    position: 'relative',
    width: '100%',
};

const dropdownStyle: React.CSSProperties = {
    position: 'absolute',
    top: 'calc(100% + 4px)',
    left: 0,
    right: 0,
    zIndex: 20,
    maxHeight: 240,
    overflowY: 'auto',
    border: '1px solid #cfd7e2',
    borderRadius: 8,
    backgroundColor: '#fff',
    boxShadow: '0 8px 24px rgba(15, 23, 42, 0.12)',
};

const dropdownItemStyle: React.CSSProperties = {
    width: '100%',
    padding: '9px 12px',
    border: 'none',
    backgroundColor: '#fff',
    color: '#1f2d3d',
    textAlign: 'left',
    cursor: 'pointer',
};

const checkboxWrapStyle: React.CSSProperties = {
    minHeight: 42,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
    padding: '0 12px',
    boxSizing: 'border-box',
    borderRadius: 8,
    border: '1px solid #92b2d1',
    backgroundColor: '#fffdf4',
    boxShadow: 'inset 0 1px 2px rgba(15, 23, 42, 0.04), 0 0 0 1px rgba(146, 178, 209, 0.08)',
    cursor: 'pointer',
};

const checkboxInputStyle: React.CSSProperties = {
    width: 16,
    height: 16,
    margin: 0,
};

const checkboxLabelStyle: React.CSSProperties = {
    fontSize: '0.9rem',
    fontWeight: 600,
    color: '#163049',
};

const inlineActionWrapStyle: React.CSSProperties = {
    display: 'flex',
    justifyContent: 'center',
    marginTop: 2,
};

const editorBottomBarStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    flexWrap: 'wrap',
    padding: '16px 20px 20px',
    borderTop: '1px solid #dbe7f2',
    backgroundColor: '#f9fbfe',
};

const editorBottomBarButtonsStyle: React.CSSProperties = {
    display: 'flex',
    gap: 8,
};

const editorBottomBarHintStyle: React.CSSProperties = {
    fontSize: '0.82rem',
    color: '#62798f',
};

const fieldErrorStyle: React.CSSProperties = {
    marginTop: 6,
    fontSize: '0.77rem',
    color: '#b23a3a',
};

// 逐欄位說明文字（對齊舊頁 <small class="text-muted">）；用 <small> 標籤確保被擷取工具的 hints 選擇器涵蓋。
const fieldHintStyle: React.CSSProperties = {
    display: 'block',
    marginTop: 6,
    fontSize: '0.77rem',
    color: '#6c757d',
    lineHeight: 1.5,
    whiteSpace: 'normal',
};

const mutedValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#f8fafc',
    borderColor: '#d5dee8',
};

const subtleValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#fffdf7',
    borderColor: '#ddd3aa',
};

const emphasisValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#f1f6fb',
    borderColor: '#c7d8ea',
    fontSize: '1rem',
    fontWeight: 700,
};

const derivedValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#f4f8fb',
    borderColor: '#bfcfdf',
    color: '#18344d',
    fontWeight: 700,
};

const dirtyValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#fff5db',
    borderColor: '#d3a342',
    boxShadow: 'inset 0 1px 2px rgba(15, 23, 42, 0.04), 0 0 0 1px rgba(211, 163, 66, 0.16)',
};

const dirtyInputStyle: React.CSSProperties = {
    backgroundColor: '#fff7e4',
    borderColor: '#d3a342',
    boxShadow: 'inset 0 1px 2px rgba(15, 23, 42, 0.04), 0 0 0 1px rgba(211, 163, 66, 0.18)',
};

const notesBoxStyle: React.CSSProperties = {
    padding: '14px 16px',
    borderRadius: 8,
    border: '1px solid #cfd7e2',
    backgroundColor: '#fff',
    color: '#2a3642',
    lineHeight: 1.7,
    whiteSpace: 'pre-wrap',
    wordBreak: 'break-word',
    boxShadow: 'inset 0 1px 2px rgba(15, 23, 42, 0.04)',
};

const notesLabelStyle: React.CSSProperties = {
    fontSize: '0.84rem',
    fontWeight: 600,
    color: '#556677',
    marginBottom: 6,
    textAlign: 'left',
};

const clickableFieldStyle: React.CSSProperties = {
    cursor: 'pointer',
    transition: 'background-color 0.15s',
};

const emptyStyle: React.CSSProperties = {
    padding: 24,
    textAlign: 'center',
    color: '#6c757d',
};
