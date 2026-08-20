import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import CodeAutocomplete from '../../components/PersonBrowser/shared/CodeAutocomplete';
import { getCsrfToken } from '../../components/PersonBrowser/shared/csrf';
import { Button } from '../../components/ui/Button';
import { FormField } from '../../components/ui/FormField';
import { useTranslation } from '../../hooks/useTranslation';

export interface TextUrls {
    index: string;
    create: string;
    edit_template: string;
    api_create: string;
    api_mutate: string;
    api_delete: string;
    search_source: string;
}

export interface ExtantOption {
    code: number;
    label: string;
}

export interface InstanceRow {
    edition_id: number | null;
    instance_id: number | null;
    title_chn: string | null;
    title_pinyin: string | null;
    publisher: string | null;
    pub_loc: string | null;
    pub_year: number | null;
    pub_dy: number | null;
    pub_nh_code: number | null;
    pub_nh_year: number | null;
    source_id: number | null;
    pages: string | null;
    extant: number | null;
    notes: string | null;
}

/**
 * 版本列的前端狀態：在 wire 形狀之外掛一個**純前端**的穩定 id。
 *
 * 不用陣列索引當 React key（AGENTS.md §4）：刪掉一列會讓 React 把該 DOM 重用給另一個
 * (edition_id, instance_id)，焦點與輸入中的值會跟到錯的版本上。也不用複合鍵本身當 key
 * ——那兩個欄位是**使用者可編輯的**，改一個數字就會整列重新掛載而丟失焦點。
 */
interface InstanceRowState extends InstanceRow {
    _uid: string;
}

let instanceUidSeq = 0;
const nextInstanceUid = () => `inst-${++instanceUidSeq}`;

export interface TextAggregate {
    textid: number;
    title: string;
    title_pinyin: string;
    title_trans: string | null;
    title_alt_chn: string | null;
    type_id: string | null;
    year: number | null;
    nh_code: number | null;
    nh_year: number | null;
    range_code: number | null;
    bibl_cat_code: number | null;
    extant: number | null;
    country: number | null;
    dynasty_code: number | null;
    source_id: number | null;
    pages: string | null;
    url_api: string | null;
    url_api_coda: string | null;
    url_homepage: string | null;
    notes: string | null;
    instances: InstanceRow[];
}

export interface TextInitialLabels {
    dynasties: Record<string, string>;
    source: string | null;
}

interface Props {
    mode: 'create' | 'edit';
    textId?: number;
    initial: TextAggregate | null;
    initialLabels: TextInitialLabels;
    extantOptions: ExtantOption[];
    urls: TextUrls;
}

const inputCls =
    'w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
const smallInputCls =
    'w-full rounded-md border border-input bg-background px-2 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

/**
 * 文獻實體聚合表單。create 模式為最小輸入（書名／類型／朝代／來源，成功後導向編輯頁補其餘
 * 欄位與版本列）；edit 模式為全欄位（TEXT_CODES）＋版本列（TEXT_INSTANCE_DATA，以
 * (edition_id, instance_id) 定位、集合對賬），寫入走 mutation API（resource=text-entity）。
 * 拼音留空由後端自動派生（去卷冊註記＋異體字歸一化）；書名落庫前經字形標準化。
 */
export default function TextForm({ mode, textId, initial, initialLabels, extantOptions, urls }: Props) {
    const t = useTranslation('text_entity');

    const [title, setTitle] = useState(initial?.title ?? '');
    const [titlePinyin, setTitlePinyin] = useState(initial?.title_pinyin ?? '');
    const [titleTrans, setTitleTrans] = useState(initial?.title_trans ?? '');
    const [titleAltChn, setTitleAltChn] = useState(initial?.title_alt_chn ?? '');
    const [typeId, setTypeId] = useState(initial?.type_id ?? '01');
    const [dynasty, setDynasty] = useState(initial?.dynasty_code != null ? String(initial.dynasty_code) : '');
    const [source, setSource] = useState(initial?.source_id != null ? String(initial.source_id) : '');
    const [sourceLabel, setSourceLabel] = useState<string | null>(initialLabels.source);
    const [extant, setExtant] = useState(initial?.extant != null ? String(initial.extant) : '');
    const [pages, setPages] = useState(initial?.pages ?? '');
    const [notes, setNotes] = useState(initial?.notes ?? '');
    // 固定次序呼叫 useState 的小工具（僅頂層依序使用，符合 hooks 規則）。
    const numField = (key: keyof TextAggregate) =>
        useState(initial?.[key] != null ? String(initial[key]) : '');
    const [year, setYear] = numField('year');
    const [nhCode, setNhCode] = numField('nh_code');
    const [nhYear, setNhYear] = numField('nh_year');
    const [rangeCode, setRangeCode] = numField('range_code');
    const [biblCat, setBiblCat] = numField('bibl_cat_code');
    const [country, setCountry] = numField('country');
    const [urlApi, setUrlApi] = useState(initial?.url_api ?? '');
    const [urlApiCoda, setUrlApiCoda] = useState(initial?.url_api_coda ?? '');
    const [urlHomepage, setUrlHomepage] = useState(initial?.url_homepage ?? '');

    const [instances, setInstances] = useState<InstanceRowState[]>(
        () => (initial?.instances ?? []).map((r) => ({ ...r, _uid: nextInstanceUid() }))
    );

    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [serverError, setServerError] = useState<string | null>(null);

    const setInst = (uid: string, patch: Partial<InstanceRow>) =>
        setInstances((prev) => prev.map((r) => (r._uid === uid ? { ...r, ...patch } : r)));
    const removeInst = (uid: string) => setInstances((prev) => prev.filter((r) => r._uid !== uid));
    const addInst = () =>
        setInstances((prev) => {
            const maxEdition = prev.reduce((m, r) => Math.max(m, r.edition_id ?? 0), 0);
            return [
                ...prev,
                {
                    _uid: nextInstanceUid(),
                    edition_id: maxEdition + 1, instance_id: 1,
                    title_chn: null, title_pinyin: null, publisher: null, pub_loc: null,
                    pub_year: null, pub_dy: null, pub_nh_code: null, pub_nh_year: null,
                    source_id: null, pages: null, extant: null, notes: null,
                },
            ];
        });

    const mapError = (field: string, codes: unknown[]): string => {
        const code = String(codes[0] ?? '');
        if (field === 'title') return t('err_title_required');
        if (field === 'source_id') return code === 'source_cycle' ? t('err_source_cycle') : t('err_source_invalid');
        if (field.startsWith('instances')) return t('err_instances_invalid');
        return `${field}: ${code}`;
    };

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        setServerError(null);

        const nn = (s: string) => (s.trim() === '' ? null : s);
        const ni = (s: string) => (s.trim() === '' ? null : Number(s));

        const changes: Record<string, unknown> = {
            title,
            title_pinyin: nn(titlePinyin),
            type_id: nn(typeId),
            dynasty_code: ni(dynasty),
            source_id: ni(source),
        };
        if (mode === 'edit') {
            Object.assign(changes, {
                title_trans: nn(titleTrans),
                title_alt_chn: nn(titleAltChn),
                year: ni(year),
                nh_code: ni(nhCode),
                nh_year: ni(nhYear),
                range_code: ni(rangeCode),
                bibl_cat_code: ni(biblCat),
                extant: ni(extant),
                country: ni(country),
                pages: nn(pages),
                url_api: nn(urlApi),
                url_api_coda: nn(urlApiCoda),
                url_homepage: nn(urlHomepage),
                notes: nn(notes),
                instances: instances.map((r) => ({
                    edition_id: r.edition_id,
                    instance_id: r.instance_id,
                    title_chn: r.title_chn,
                    title_pinyin: r.title_pinyin,
                    publisher: r.publisher,
                    pub_loc: r.pub_loc,
                    pub_year: r.pub_year,
                    pub_dy: r.pub_dy,
                    pub_nh_code: r.pub_nh_code,
                    pub_nh_year: r.pub_nh_year,
                    source_id: r.source_id,
                    pages: r.pages,
                    extant: r.extant,
                    notes: r.notes,
                })),
            });
        }

        const body: Record<string, unknown> =
            mode === 'create'
                ? { resource: 'text-entity', person_id: 0, target: { pk: [] }, changes }
                : { resource: 'text-entity', operation: 'update', person_id: 0, target: { pk: { c_textid: textId } }, changes };

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
                        mapped[field.startsWith('instances') ? 'instances' : field] = mapError(field, Array.isArray(codes) ? codes : []);
                    }
                    setErrors(mapped);
                } else {
                    setServerError(json?.message ?? t('save_failed'));
                }
                setBusy(false);
                return;
            }
            const newId = json?.result?.pk?.c_textid ?? textId;
            router.visit(urls.edit_template.replace('__ID__', String(newId)));
        } catch (err) {
            setServerError(String(err));
            setBusy(false);
        }
    };

    const numInput = (label: string, value: string, set: React.Dispatch<React.SetStateAction<string>>, id: string, error?: string) => (
        <FormField label={label} htmlFor={id} error={error}>
            <input id={id} type="number" className={inputCls} value={value} onChange={(e) => set(e.target.value)} />
        </FormField>
    );

    return (
        <form onSubmit={submit} className="space-y-4">
            {serverError && (
                <div className="rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">{serverError}</div>
            )}

            <FormField label={t('field_title')} htmlFor="text-title" error={errors.title}>
                <input id="text-title" className={inputCls} value={title} onChange={(e) => setTitle(e.target.value)} />
            </FormField>

            <FormField label={t('field_title_pinyin')} htmlFor="text-title-pinyin">
                <input
                    id="text-title-pinyin"
                    className={inputCls}
                    value={titlePinyin}
                    placeholder={t('pinyin_placeholder')}
                    onChange={(e) => setTitlePinyin(e.target.value)}
                />
            </FormField>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField label={t('field_type_id')} htmlFor="text-type-id" error={errors.type_id}>
                    <input id="text-type-id" className={inputCls} value={typeId} onChange={(e) => setTypeId(e.target.value)} />
                    <p className="mt-1 text-xs text-muted-foreground">{t('type_id_hint')}</p>
                </FormField>
                <FormField label={t('field_dynasty')} htmlFor="text-dynasty" error={errors.dynasty_code}>
                    <CodeAutocomplete
                        id="text-dynasty"
                        mode="list"
                        model="dynasty"
                        idKey="c_dy"
                        labelKeys={['c_dynasty_chn', 'c_dynasty']}
                        value={dynasty}
                        initialLabel={initial?.dynasty_code != null ? (initialLabels.dynasties[String(initial.dynasty_code)] ?? null) : null}
                        placeholder={t('dynasty_placeholder')}
                        onChange={(v) => setDynasty(v)}
                    />
                </FormField>
            </div>

            <FormField label={t('field_source')} htmlFor="text-source" error={errors.source_id}>
                <CodeAutocomplete
                    id="text-source"
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
                <p className="mt-1 text-xs text-muted-foreground">{t('source_hint')}</p>
            </FormField>

            {mode === 'edit' && (
                <>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label={t('field_title_trans')} htmlFor="text-title-trans">
                            <input id="text-title-trans" className={inputCls} value={titleTrans} onChange={(e) => setTitleTrans(e.target.value)} />
                        </FormField>
                        <FormField label={t('field_title_alt_chn')} htmlFor="text-title-alt">
                            <input id="text-title-alt" className={inputCls} value={titleAltChn} onChange={(e) => setTitleAltChn(e.target.value)} />
                        </FormField>
                    </div>

                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        {numInput(t('field_year'), year, setYear, 'text-year')}
                        {numInput(t('field_nh_code'), nhCode, setNhCode, 'text-nh-code', errors.nh_code)}
                        {numInput(t('field_nh_year'), nhYear, setNhYear, 'text-nh-year')}
                        {numInput(t('field_range_code'), rangeCode, setRangeCode, 'text-range-code', errors.range_code)}
                        {numInput(t('field_bibl_cat_code'), biblCat, setBiblCat, 'text-bibl-cat', errors.bibl_cat_code)}
                        {numInput(t('field_country'), country, setCountry, 'text-country', errors.country)}
                        <FormField label={t('field_extant')} htmlFor="text-extant" error={errors.extant}>
                            <select id="text-extant" className={inputCls} value={extant} onChange={(e) => setExtant(e.target.value)}>
                                <option value="">{t('extant_placeholder')}</option>
                                {extantOptions.map((o) => (
                                    <option key={o.code} value={o.code}>
                                        {o.code} {o.label}
                                    </option>
                                ))}
                            </select>
                        </FormField>
                        <FormField label={t('field_pages')} htmlFor="text-pages">
                            <input id="text-pages" className={inputCls} value={pages} onChange={(e) => setPages(e.target.value)} />
                        </FormField>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <FormField label={t('field_url_api')} htmlFor="text-url-api">
                            <input id="text-url-api" className={inputCls} value={urlApi} onChange={(e) => setUrlApi(e.target.value)} />
                        </FormField>
                        <FormField label={t('field_url_api_coda')} htmlFor="text-url-api-coda">
                            <input id="text-url-api-coda" className={inputCls} value={urlApiCoda} onChange={(e) => setUrlApiCoda(e.target.value)} />
                        </FormField>
                        <FormField label={t('field_url_homepage')} htmlFor="text-url-homepage">
                            <input id="text-url-homepage" className={inputCls} value={urlHomepage} onChange={(e) => setUrlHomepage(e.target.value)} />
                        </FormField>
                    </div>

                    <FormField label={t('field_notes')} htmlFor="text-notes">
                        <textarea id="text-notes" rows={3} className={inputCls} value={notes} onChange={(e) => setNotes(e.target.value)} />
                    </FormField>

                    <FormField label={t('field_instances')} htmlFor="text-inst-add" error={errors.instances}>
                        <div className="space-y-3">
                            {instances.map((r, i) => (
                                <div key={r._uid} className="rounded-md border border-border p-3">
                                    <div className="mb-2 flex items-center justify-between">
                                        <span className="text-xs font-medium text-muted-foreground">#{i + 1}</span>
                                        <button type="button" className="text-sm text-red-600 hover:underline" onClick={() => removeInst(r._uid)}>
                                            {t('btn_remove_instance')}
                                        </button>
                                    </div>
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_inst_edition_id')}
                                            <input type="number" className={smallInputCls} value={r.edition_id ?? ''}
                                                onChange={(e) => setInst(r._uid, { edition_id: e.target.value === '' ? null : Number(e.target.value) })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_inst_instance_id')}
                                            <input type="number" className={smallInputCls} value={r.instance_id ?? ''}
                                                onChange={(e) => setInst(r._uid, { instance_id: e.target.value === '' ? null : Number(e.target.value) })} />
                                        </label>
                                        <label className="col-span-2 text-xs text-muted-foreground">
                                            {t('field_inst_title_chn')}
                                            <input className={smallInputCls} value={r.title_chn ?? ''}
                                                onChange={(e) => setInst(r._uid, { title_chn: e.target.value || null })} />
                                        </label>
                                        <label className="col-span-2 text-xs text-muted-foreground">
                                            {t('field_inst_title_pinyin')}
                                            <input className={smallInputCls} value={r.title_pinyin ?? ''} placeholder={t('pinyin_placeholder')}
                                                onChange={(e) => setInst(r._uid, { title_pinyin: e.target.value || null })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_inst_publisher')}
                                            <input className={smallInputCls} value={r.publisher ?? ''}
                                                onChange={(e) => setInst(r._uid, { publisher: e.target.value || null })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_inst_pub_loc')}
                                            <input className={smallInputCls} value={r.pub_loc ?? ''}
                                                onChange={(e) => setInst(r._uid, { pub_loc: e.target.value || null })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_inst_pub_year')}
                                            <input type="number" className={smallInputCls} value={r.pub_year ?? ''}
                                                onChange={(e) => setInst(r._uid, { pub_year: e.target.value === '' ? null : Number(e.target.value) })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_inst_pub_dy')}
                                            <input type="number" className={smallInputCls} value={r.pub_dy ?? ''}
                                                onChange={(e) => setInst(r._uid, { pub_dy: e.target.value === '' ? null : Number(e.target.value) })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_inst_pub_nh_code')}
                                            <input type="number" className={smallInputCls} value={r.pub_nh_code ?? ''}
                                                onChange={(e) => setInst(r._uid, { pub_nh_code: e.target.value === '' ? null : Number(e.target.value) })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_inst_pub_nh_year')}
                                            <input type="number" className={smallInputCls} value={r.pub_nh_year ?? ''}
                                                onChange={(e) => setInst(r._uid, { pub_nh_year: e.target.value === '' ? null : Number(e.target.value) })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_inst_source')}
                                            <input type="number" className={smallInputCls} value={r.source_id ?? ''}
                                                onChange={(e) => setInst(r._uid, { source_id: e.target.value === '' ? null : Number(e.target.value) })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_inst_pages')}
                                            <input className={smallInputCls} value={r.pages ?? ''}
                                                onChange={(e) => setInst(r._uid, { pages: e.target.value || null })} />
                                        </label>
                                        <label className="text-xs text-muted-foreground">
                                            {t('field_inst_extant')}
                                            <input type="number" className={smallInputCls} value={r.extant ?? ''}
                                                onChange={(e) => setInst(r._uid, { extant: e.target.value === '' ? null : Number(e.target.value) })} />
                                        </label>
                                        <label className="col-span-2 text-xs text-muted-foreground sm:col-span-4">
                                            {t('field_inst_notes')}
                                            <input className={smallInputCls} value={r.notes ?? ''}
                                                onChange={(e) => setInst(r._uid, { notes: e.target.value || null })} />
                                        </label>
                                    </div>
                                </div>
                            ))}
                            <Button type="button" variant="outline" size="sm" onClick={addInst}>
                                {t('btn_add_instance')}
                            </Button>
                        </div>
                    </FormField>
                </>
            )}
            {mode === 'create' && <p className="text-xs text-muted-foreground">{t('create_more_hint')}</p>}

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
