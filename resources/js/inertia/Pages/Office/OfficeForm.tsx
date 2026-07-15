import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import CodeAutocomplete from '../../components/PersonBrowser/shared/CodeAutocomplete';
import { getCsrfToken } from '../../components/PersonBrowser/shared/csrf';
import { Button } from '../../components/ui/Button';
import { FormField } from '../../components/ui/FormField';
import { useTranslation } from '../../hooks/useTranslation';

export interface OfficeUrls {
    index: string;
    edit_template: string;
    api_create: string;
    api_mutate: string;
    api_delete: string;
    search_type: string;
    search_source: string;
}

export interface OfficeInitial {
    name: string;
    name_alt: string | null;
    translation: string | null;
    translation_alt: string | null;
    pinyin: string | null;
    pinyin_alt: string | null;
    dynasty_code: number | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
    type_ids: string[];
}

export interface OfficeInitialLabels {
    dynasty: string | null;
    source: string | null;
    types: Record<string, string>;
}

interface TypeChip {
    id: string;
    label: string;
}

interface Props {
    mode: 'create' | 'edit';
    officeId?: number;
    initial: OfficeInitial;
    initialLabels: OfficeInitialLabels;
    urls: OfficeUrls;
}

/** 官職實體聚合表單（新增／編輯共用）；寫入走 mutation API（/api/v2）。 */
export default function OfficeForm({ mode, officeId, initial, initialLabels, urls }: Props) {
    const t = useTranslation('office');

    const [name, setName] = useState(initial.name);
    const [nameAlt, setNameAlt] = useState(initial.name_alt ?? '');
    const [translation, setTranslation] = useState(initial.translation ?? '');
    const [translationAlt, setTranslationAlt] = useState(initial.translation_alt ?? '');
    const [pinyin, setPinyin] = useState(initial.pinyin ?? '');
    const [pinyinAlt, setPinyinAlt] = useState(initial.pinyin_alt ?? '');
    const [pages, setPages] = useState(initial.pages ?? '');
    const [notes, setNotes] = useState(initial.notes ?? '');
    const [dynasty, setDynasty] = useState(initial.dynasty_code != null ? String(initial.dynasty_code) : '');
    const [source, setSource] = useState(initial.source_id != null ? String(initial.source_id) : '');
    const [sourceLabel, setSourceLabel] = useState<string | null>(initialLabels.source);
    const [types, setTypes] = useState<TypeChip[]>(
        initial.type_ids.map((id) => ({ id, label: initialLabels.types[id] ?? id })),
    );
    const [typeKey, setTypeKey] = useState(0); // 加入後 remount picker 以清空
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [serverError, setServerError] = useState<string | null>(null);

    const addType = (value: string, label: string) => {
        if (!value) return;
        setTypes((prev) => (prev.some((x) => x.id === value) ? prev : [...prev, { id: value, label }]));
        setTypeKey((k) => k + 1);
    };
    const removeType = (id: string) => setTypes((prev) => prev.filter((x) => x.id !== id));

    const mapError = (field: string, codes: string[]): string => {
        const code = codes[0] ?? '';
        if (field === 'name') return t('err_name_required');
        if (field === 'dynasty' || field === 'dynasty_label') return t('err_dynasty_invalid');
        if (field === 'type_ids') return code.includes('not_found') ? t('err_type_not_found') : t('err_types_required');
        if (field === 'source_id') return t('err_source_required');
        return code;
    };

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setServerError(null);

        const nn = (s: string) => (s.trim() === '' ? null : s);
        const changes = {
            name,
            name_alt: nn(nameAlt),
            translation: nn(translation),
            translation_alt: nn(translationAlt),
            pinyin, // 留空由後端依名稱自動派生
            pinyin_alt: pinyinAlt,
            dynasty_code: dynasty ? Number(dynasty) : null,
            type_ids: types.map((x) => x.id),
            source_id: source ? Number(source) : null,
            pages: nn(pages),
            notes: nn(notes),
        };
        const body =
            mode === 'create'
                ? { resource: 'office', person_id: 0, target: { pk: [] }, changes }
                : { resource: 'office', operation: 'update', person_id: 0, target: { pk: { c_office_id: officeId } }, changes };
        const endpoint = mode === 'create' ? urls.api_create : urls.api_mutate;

        try {
            const res = await fetch(endpoint, {
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
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                if (res.status === 422 && json?.errors && typeof json.errors === 'object') {
                    const mapped: Record<string, string> = {};
                    for (const [field, codes] of Object.entries(json.errors)) {
                        mapped[field] = mapError(field, Array.isArray(codes) ? (codes as string[]) : []);
                    }
                    setErrors(mapped);
                } else {
                    setServerError(json?.message ?? t('save_failed'));
                }
                setBusy(false);
                return;
            }
            const newId = json?.result?.pk?.c_office_id ?? officeId;
            router.visit(urls.edit_template.replace('__ID__', String(newId)));
        } catch (err) {
            setServerError(String(err));
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            {serverError && (
                <div className="rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">{serverError}</div>
            )}

            <FormField label={t('field_name')} htmlFor="office-name" error={errors.name}>
                <input
                    id="office-name"
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                />
            </FormField>

            <FormField label={t('field_name_alt')} htmlFor="office-name-alt">
                <input
                    id="office-name-alt"
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    value={nameAlt}
                    onChange={(e) => setNameAlt(e.target.value)}
                />
            </FormField>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField label={t('field_pinyin')} htmlFor="office-pinyin">
                    <input
                        id="office-pinyin"
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        placeholder={t('pinyin_auto_hint')}
                        value={pinyin}
                        onChange={(e) => setPinyin(e.target.value)}
                    />
                </FormField>
                <FormField label={t('field_pinyin_alt')} htmlFor="office-pinyin-alt">
                    <input
                        id="office-pinyin-alt"
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        placeholder={t('pinyin_auto_hint')}
                        value={pinyinAlt}
                        onChange={(e) => setPinyinAlt(e.target.value)}
                    />
                </FormField>
            </div>

            <FormField label={t('field_translation')} htmlFor="office-trans" error={errors.translation}>
                <input
                    id="office-trans"
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    value={translation}
                    onChange={(e) => setTranslation(e.target.value)}
                />
            </FormField>

            <FormField label={t('field_translation_alt')} htmlFor="office-trans-alt">
                <input
                    id="office-trans-alt"
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    value={translationAlt}
                    onChange={(e) => setTranslationAlt(e.target.value)}
                />
            </FormField>

            <FormField label={t('field_dynasty')} htmlFor="office-dynasty" error={errors.dynasty}>
                <CodeAutocomplete
                    id="office-dynasty"
                    mode="list"
                    model="dynasty"
                    idKey="c_dy"
                    labelKeys={['c_dynasty_chn', 'c_dynasty']}
                    value={dynasty}
                    initialLabel={initialLabels.dynasty}
                    placeholder={t('dynasty_placeholder')}
                    onChange={(v) => setDynasty(v)}
                />
            </FormField>

            <FormField label={t('field_source')} htmlFor="office-source" error={errors.source_id}>
                <CodeAutocomplete
                    id="office-source"
                    mode="search"
                    endpoint={urls.search_source}
                    value={source}
                    initialLabel={sourceLabel}
                    placeholder={t('source_placeholder')}
                    onChange={(v, label) => {
                        setSource(v);
                        setSourceLabel(label);
                    }}
                />
            </FormField>

            <FormField label={t('field_pages')} htmlFor="office-pages">
                <input
                    id="office-pages"
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    value={pages}
                    onChange={(e) => setPages(e.target.value)}
                />
            </FormField>

            <FormField label={t('field_notes')} htmlFor="office-notes">
                <textarea
                    id="office-notes"
                    rows={3}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                />
            </FormField>

            <FormField label={t('field_types')} htmlFor="office-type-add" error={errors.type_ids}>
                <div className="space-y-2">
                    <CodeAutocomplete
                        key={typeKey}
                        id="office-type-add"
                        mode="search"
                        endpoint={urls.search_type}
                        value=""
                        placeholder={t('type_add_placeholder')}
                        onChange={(v, label) => addType(v, label)}
                    />
                    {types.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('no_types')}</p>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {types.map((chip) => (
                                <span
                                    key={chip.id}
                                    className="inline-flex items-center gap-1 rounded-full border border-input bg-muted px-3 py-1 text-sm"
                                >
                                    {chip.label}
                                    <button
                                        type="button"
                                        className="ml-1 text-muted-foreground hover:text-red-600"
                                        onClick={() => removeType(chip.id)}
                                        aria-label="remove"
                                    >
                                        ×
                                    </button>
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            </FormField>

            <div className="flex gap-2 pt-2">
                <Button type="submit" disabled={busy}>
                    {t('btn_save')}
                </Button>
                <a
                    href={urls.index}
                    className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted"
                >
                    {t('btn_cancel')}
                </a>
            </div>
        </form>
    );
}
