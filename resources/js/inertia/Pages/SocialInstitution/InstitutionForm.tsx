import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import CodeAutocomplete from '../../components/PersonBrowser/shared/CodeAutocomplete';
import { getCsrfToken } from '../../components/PersonBrowser/shared/csrf';
import { Button } from '../../components/ui/Button';
import { FormField } from '../../components/ui/FormField';
import { useTranslation } from '../../hooks/useTranslation';

export interface InstitutionUrls {
    index: string;
    create: string;
    edit_template: string;
    api_create: string;
    api_mutate: string;
    api_delete: string;
    search_addr: string;
    search_source: string;
}

export interface TypeOption {
    code: number;
    label: string;
}

export interface AddressRow {
    addr_id: number | null;
    addr_type_code: number;
    begin_year: number | null;
    end_year: number | null;
    xcoord: number;
    ycoord: number;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

export interface InstitutionAggregate {
    inst_code: number;
    name_code: number;
    name: string;
    name_pinyin: string;
    type_code: number | null;
    begin_year: number | null;
    by_nianhao_code: number | null;
    by_nianhao_year: number | null;
    by_year_range: number | null;
    begin_dy: number | null;
    floruit_dy: number | null;
    first_known_year: number | null;
    end_year: number | null;
    ey_nianhao_code: number | null;
    ey_nianhao_year: number | null;
    ey_year_range: number | null;
    end_dy: number | null;
    last_known_year: number | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
    addresses: AddressRow[];
}

export interface InstitutionInitialLabels {
    dynasties: Record<string, string>;
    source: string | null;
    addresses: Record<string, string>;
}

interface Props {
    mode: 'create' | 'edit';
    instCode?: number;
    initial: InstitutionAggregate | null;
    initialLabels: InstitutionInitialLabels;
    typeOptions: TypeOption[];
    /** edit 模式：被人物資料引用的筆數；>0 時後端會擋改名，前端預先鎖名稱欄。 */
    referenceCount?: number;
    urls: InstitutionUrls;
}

const inputCls =
    'w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
const smallInputCls =
    'w-full rounded-md border border-input bg-background px-2 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

/**
 * 社會機構實體聚合表單。create 模式對齊 create handler 的最小輸入（名稱／類型／朝代／地址／
 * 來源，成功後導向編輯頁補其餘欄位）；edit 模式為全欄位超集（SOCIAL_INSTITUTION_CODES 全部
 * 欄位＋多地址列對賬），寫入走 mutation API（resource=social-institution）。
 */
export default function InstitutionForm({ mode, instCode, initial, initialLabels, typeOptions, referenceCount = 0, urls }: Props) {
    const t = useTranslation('social_institution');

    const [name, setName] = useState(initial?.name ?? '');
    const [typeCode, setTypeCode] = useState(initial?.type_code != null ? String(initial.type_code) : '');
    const [beginDy, setBeginDy] = useState(initial?.begin_dy != null ? String(initial.begin_dy) : '');
    const [floruitDy, setFloruitDy] = useState(initial?.floruit_dy != null ? String(initial.floruit_dy) : '');
    const [endDy, setEndDy] = useState(initial?.end_dy != null ? String(initial.end_dy) : '');
    const [source, setSource] = useState(initial?.source_id != null ? String(initial.source_id) : '');
    const [sourceLabel, setSourceLabel] = useState<string | null>(initialLabels.source);
    const [pages, setPages] = useState(initial?.pages ?? '');
    const [notes, setNotes] = useState(initial?.notes ?? '');
    // 固定次序呼叫 useState 的小工具（僅頂層依序使用，符合 hooks 規則）。
    const numField = (key: keyof InstitutionAggregate) =>
        useState(initial?.[key] != null ? String(initial[key]) : '');
    const [beginYear, setBeginYear] = numField('begin_year');
    const [byNhCode, setByNhCode] = numField('by_nianhao_code');
    const [byNhYear, setByNhYear] = numField('by_nianhao_year');
    const [byRange, setByRange] = numField('by_year_range');
    const [firstKnown, setFirstKnown] = numField('first_known_year');
    const [endYear, setEndYear] = numField('end_year');
    const [eyNhCode, setEyNhCode] = numField('ey_nianhao_code');
    const [eyNhYear, setEyNhYear] = numField('ey_nianhao_year');
    const [eyRange, setEyRange] = numField('ey_year_range');
    const [lastKnown, setLastKnown] = numField('last_known_year');

    // create 模式：單一地址（create handler 語義）；edit 模式：多地址列對賬。
    const [createAddr, setCreateAddr] = useState('');
    const [addresses, setAddresses] = useState<AddressRow[]>(initial?.addresses ?? []);
    const [addrLabels, setAddrLabels] = useState<Record<string, string>>(initialLabels.addresses);

    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [serverError, setServerError] = useState<string | null>(null);

    const renameLocked = mode === 'edit' && referenceCount > 0;

    const setAddr = (i: number, patch: Partial<AddressRow>) =>
        setAddresses((prev) => prev.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
    const removeAddr = (i: number) => setAddresses((prev) => prev.filter((_, idx) => idx !== i));
    const addAddr = () =>
        setAddresses((prev) => [
            ...prev,
            { addr_id: null, addr_type_code: 1, begin_year: null, end_year: null, xcoord: 0, ycoord: 0, source_id: null, pages: null, notes: null },
        ]);

    const mapError = (field: string, codes: unknown[]): string => {
        const code = String(codes[0] ?? '');
        if (field === 'name') return code === 'rename_blocked_while_referenced' ? t('err_rename_blocked') : t('err_name_required');
        if (field === 'type') return t('err_type_invalid');
        if (field === 'dynasty') return t('err_dynasty_invalid');
        if (field === 'source_id') return t('err_source_required');
        if (field.startsWith('addresses')) return t('err_addresses_invalid');
        return code;
    };

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setServerError(null);

        const nn = (s: string) => (s.trim() === '' ? null : s);
        const ni = (s: string) => (s.trim() === '' ? null : Number(s));

        let body: Record<string, unknown>;
        if (mode === 'create') {
            body = {
                resource: 'social-institution',
                person_id: 0,
                target: { pk: [] },
                changes: {
                    name,
                    type_code: typeCode ? Number(typeCode) : null,
                    dynasty_code: beginDy ? Number(beginDy) : null,
                    addr_id: createAddr ? Number(createAddr) : null,
                    source_id: source ? Number(source) : null,
                },
            };
        } else {
            body = {
                resource: 'social-institution',
                operation: 'update',
                person_id: 0,
                target: { pk: { c_inst_code: instCode } },
                changes: {
                    name,
                    type_code: typeCode ? Number(typeCode) : null,
                    dynasty_code: beginDy ? Number(beginDy) : null,
                    floruit_dy: ni(floruitDy),
                    end_dy: ni(endDy),
                    begin_year: ni(beginYear),
                    by_nianhao_code: ni(byNhCode),
                    by_nianhao_year: ni(byNhYear),
                    by_year_range: ni(byRange),
                    first_known_year: ni(firstKnown),
                    end_year: ni(endYear),
                    ey_nianhao_code: ni(eyNhCode),
                    ey_nianhao_year: ni(eyNhYear),
                    ey_year_range: ni(eyRange),
                    last_known_year: ni(lastKnown),
                    source_id: source ? Number(source) : null,
                    pages: nn(pages),
                    notes: nn(notes),
                    addresses: addresses.map((r) => ({
                        addr_id: r.addr_id,
                        addr_type_code: r.addr_type_code,
                        begin_year: r.begin_year,
                        end_year: r.end_year,
                        xcoord: r.xcoord,
                        ycoord: r.ycoord,
                        source_id: r.source_id,
                        pages: r.pages,
                        notes: r.notes,
                    })),
                },
            };
        }

        try {
            const res = await fetch(mode === 'create' ? urls.api_create : urls.api_mutate, {
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
                        mapped[field.startsWith('addresses') ? 'addresses' : field] = mapError(field, Array.isArray(codes) ? codes : []);
                    }
                    setErrors(mapped);
                } else if (res.status === 409 && json?.errors?.name) {
                    setErrors({ name: t('err_rename_blocked') });
                } else {
                    setServerError(json?.message ?? t('save_failed'));
                }
                setBusy(false);
                return;
            }
            const newId = json?.result?.pk?.c_inst_code ?? instCode;
            router.visit(urls.edit_template.replace('__ID__', String(newId)));
        } catch (err) {
            setServerError(String(err));
            setBusy(false);
        }
    };

    const numInput = (label: string, value: string, set: React.Dispatch<React.SetStateAction<string>>, id: string) => (
        <FormField label={label} htmlFor={id}>
            <input id={id} type="number" className={inputCls} value={value} onChange={(e) => set(e.target.value)} />
        </FormField>
    );

    return (
        <form onSubmit={submit} className="space-y-4">
            {serverError && (
                <div className="rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">{serverError}</div>
            )}

            <FormField label={t('field_name')} htmlFor="inst-name" error={errors.name}>
                <input
                    id="inst-name"
                    className={inputCls}
                    value={name}
                    disabled={renameLocked}
                    onChange={(e) => setName(e.target.value)}
                />
                {renameLocked && (
                    <p className="mt-1 text-xs text-muted-foreground">{t('rename_locked_hint', { n: String(referenceCount) })}</p>
                )}
            </FormField>

            <FormField label={t('field_type')} htmlFor="inst-type" error={errors.type}>
                <select id="inst-type" className={inputCls} value={typeCode} onChange={(e) => setTypeCode(e.target.value)}>
                    <option value="">{t('type_placeholder')}</option>
                    {typeOptions.map((o) => (
                        <option key={o.code} value={o.code}>
                            {o.code} {o.label}
                        </option>
                    ))}
                </select>
            </FormField>

            <FormField label={t('field_begin_dy')} htmlFor="inst-begin-dy" error={errors.dynasty}>
                <CodeAutocomplete
                    id="inst-begin-dy"
                    mode="list"
                    model="dynasty"
                    idKey="c_dy"
                    labelKeys={['c_dynasty_chn', 'c_dynasty']}
                    value={beginDy}
                    initialLabel={initial?.begin_dy != null ? (initialLabels.dynasties[String(initial.begin_dy)] ?? null) : null}
                    placeholder={t('dynasty_placeholder')}
                    onChange={(v) => setBeginDy(v)}
                />
            </FormField>

            <FormField label={t('field_source')} htmlFor="inst-source" error={errors.source_id}>
                <CodeAutocomplete
                    id="inst-source"
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

            {mode === 'create' ? (
                <FormField label={t('field_address')} htmlFor="inst-addr" error={errors.addr_id ?? errors.addresses}>
                    <CodeAutocomplete
                        id="inst-addr"
                        mode="search"
                        endpoint={urls.search_addr}
                        value={createAddr}
                        placeholder={t('addr_placeholder')}
                        onChange={(v) => setCreateAddr(v)}
                    />
                    <p className="mt-1 text-xs text-muted-foreground">{t('create_more_hint')}</p>
                </FormField>
            ) : (
                <>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label={t('field_floruit_dy')} htmlFor="inst-floruit-dy" error={errors.floruit_dy}>
                            <CodeAutocomplete
                                id="inst-floruit-dy"
                                mode="list"
                                model="dynasty"
                                idKey="c_dy"
                                labelKeys={['c_dynasty_chn', 'c_dynasty']}
                                value={floruitDy}
                                initialLabel={initial?.floruit_dy != null ? (initialLabels.dynasties[String(initial.floruit_dy)] ?? null) : null}
                                placeholder={t('dynasty_placeholder')}
                                onChange={(v) => setFloruitDy(v)}
                            />
                        </FormField>
                        <FormField label={t('field_end_dy')} htmlFor="inst-end-dy" error={errors.end_dy}>
                            <CodeAutocomplete
                                id="inst-end-dy"
                                mode="list"
                                model="dynasty"
                                idKey="c_dy"
                                labelKeys={['c_dynasty_chn', 'c_dynasty']}
                                value={endDy}
                                initialLabel={initial?.end_dy != null ? (initialLabels.dynasties[String(initial.end_dy)] ?? null) : null}
                                placeholder={t('dynasty_placeholder')}
                                onChange={(v) => setEndDy(v)}
                            />
                        </FormField>
                    </div>

                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
                        {numInput(t('field_begin_year'), beginYear, setBeginYear, 'inst-begin-year')}
                        {numInput(t('field_by_nianhao_code'), byNhCode, setByNhCode, 'inst-by-nh-code')}
                        {numInput(t('field_by_nianhao_year'), byNhYear, setByNhYear, 'inst-by-nh-year')}
                        {numInput(t('field_by_year_range'), byRange, setByRange, 'inst-by-range')}
                        {numInput(t('field_first_known_year'), firstKnown, setFirstKnown, 'inst-first-known')}
                        {numInput(t('field_end_year'), endYear, setEndYear, 'inst-end-year')}
                        {numInput(t('field_ey_nianhao_code'), eyNhCode, setEyNhCode, 'inst-ey-nh-code')}
                        {numInput(t('field_ey_nianhao_year'), eyNhYear, setEyNhYear, 'inst-ey-nh-year')}
                        {numInput(t('field_ey_year_range'), eyRange, setEyRange, 'inst-ey-range')}
                        {numInput(t('field_last_known_year'), lastKnown, setLastKnown, 'inst-last-known')}
                    </div>

                    <FormField label={t('field_pages')} htmlFor="inst-pages">
                        <input id="inst-pages" className={inputCls} value={pages} onChange={(e) => setPages(e.target.value)} />
                    </FormField>

                    <FormField label={t('field_notes')} htmlFor="inst-notes">
                        <textarea id="inst-notes" rows={3} className={inputCls} value={notes} onChange={(e) => setNotes(e.target.value)} />
                    </FormField>

                    <FormField label={t('field_addresses')} htmlFor="inst-addr-add" error={errors.addresses}>
                        <div className="space-y-3">
                            {addresses.map((r, i) => (
                                <div key={i} className="rounded-md border border-border p-3">
                                    <div className="mb-2 flex items-center justify-between">
                                        <span className="text-xs font-medium text-muted-foreground">#{i + 1}</span>
                                        <button type="button" className="text-sm text-red-600 hover:underline" onClick={() => removeAddr(i)}>
                                            {t('btn_remove_address')}
                                        </button>
                                    </div>
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <div className="sm:col-span-2">
                                            <CodeAutocomplete
                                                id={`inst-addr-${i}`}
                                                mode="search"
                                                endpoint={urls.search_addr}
                                                value={r.addr_id != null ? String(r.addr_id) : ''}
                                                initialLabel={r.addr_id != null ? (addrLabels[String(r.addr_id)] ?? null) : null}
                                                placeholder={t('addr_placeholder')}
                                                onChange={(v, label) => {
                                                    setAddr(i, { addr_id: v ? Number(v) : null });
                                                    if (v) setAddrLabels((prev) => ({ ...prev, [v]: label }));
                                                }}
                                            />
                                        </div>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_addr_type')}
                                            <input type="number" className={smallInputCls} value={r.addr_type_code}
                                                onChange={(e) => setAddr(i, { addr_type_code: Number(e.target.value || 1) })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_addr_pages')}
                                            <input className={smallInputCls} value={r.pages ?? ''}
                                                onChange={(e) => setAddr(i, { pages: e.target.value || null })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_addr_begin_year')}
                                            <input type="number" className={smallInputCls} value={r.begin_year ?? ''}
                                                onChange={(e) => setAddr(i, { begin_year: e.target.value === '' ? null : Number(e.target.value) })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_addr_end_year')}
                                            <input type="number" className={smallInputCls} value={r.end_year ?? ''}
                                                onChange={(e) => setAddr(i, { end_year: e.target.value === '' ? null : Number(e.target.value) })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_addr_xcoord')}
                                            <input type="number" step="any" className={smallInputCls} value={r.xcoord}
                                                onChange={(e) => setAddr(i, { xcoord: Number(e.target.value || 0) })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_addr_ycoord')}
                                            <input type="number" step="any" className={smallInputCls} value={r.ycoord}
                                                onChange={(e) => setAddr(i, { ycoord: Number(e.target.value || 0) })} />
                                        </label>
                                    </div>
                                </div>
                            ))}
                            <Button type="button" variant="outline" size="sm" onClick={addAddr}>
                                {t('btn_add_address')}
                            </Button>
                        </div>
                    </FormField>
                </>
            )}

            <div className="flex gap-2 pt-2">
                <Button type="submit" disabled={busy}>
                    {t('btn_save')}
                </Button>
                <a href={urls.index} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                    {t('btn_cancel')}
                </a>
            </div>
        </form>
    );
}
