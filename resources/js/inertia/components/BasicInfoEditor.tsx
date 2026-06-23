import React, { useEffect, useMemo, useRef, useState } from 'react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import { getCsrfToken } from './PersonBrowser/shared/csrf';

/**
 * 基本資料編輯器（對齊 legacy /basicinformation/{id}/edit，非 person-browser）。
 * 忠實復刻 legacy edit.blade：全 BIOG_MAIN 可編輯欄位 + 年號轉換（EraTimeField）+ 生成拼音 +
 * indexYear 聯動 + 三態授權提交。提交走既有 /api/v2/mutate（resource=basicinformation）。
 *
 * 註：本元件不依賴 app/person-browser 的編輯/分頁 UI；僅複用通用表單控件 EraTimeField / CodeAutocomplete。
 */

type Fields = Record<string, string>;

interface Props {
    personId: number;
    personLabel: string;
    initialFields: Fields;       // 所有 c_* 欄位的初始字串值
    initialLabels?: Fields;      // 代碼欄位的顯示標籤（年號等），key 同 c_* 名
    canEdit: boolean;            // 可直接寫入
    canPropose: boolean;         // 可提案
    mutateEndpoint: string;      // /api/v2/mutate
    pinyinEndpoint?: string;     // /api/select/search/pinyin
    t?: (k: string) => string;   // person/biogmains 翻譯
}

const READONLY_DERIVED = ['c_name_chn', 'c_name', 'c_name_proper', 'c_name_rm'];

// 一組日期欄位（生年/卒年/活動年）↔ EraTimeField 的子欄位映射。
interface DateGroup {
    year: string; nhCode: string; nhYear: string;
    range?: string; intercalary?: string; month?: string; day?: string; dayGz?: string; notes?: string;
}

export default function BasicInfoEditor({
    personId, personLabel, initialFields, initialLabels = {},
    canEdit, canPropose, mutateEndpoint, pinyinEndpoint = '/api/select/search/pinyin', t,
}: Props) {
    // useTranslation 在缺 key 時回傳 key 本身；故須在 t(k)===k（未翻譯）時退回中文 fallback，
    // 否則按鈕/標籤會顯示原始 key（如 save_directly）而非中文。
    const tr = (k: string, fallback: string) => {
        const v = t ? t(k) : k;
        return v && v !== k ? v : fallback;
    };
    const [fields, setFields] = useState<Fields>(initialFields);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const initialSnapshot = useRef<string>(JSON.stringify(initialFields));
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [pinyinDone, setPinyinDone] = useState(false);
    const [comment, setComment] = useState('');

    const dirty = useMemo(() => JSON.stringify(fields) !== initialSnapshot.current, [fields]);
    const dynastyCode = useMemo(() => {
        const v = parseInt(fields.c_dy ?? '', 10);
        return Number.isFinite(v) && v > 0 ? v : null;
    }, [fields.c_dy]);

    const set = (key: string, value: string) => setFields((p) => ({ ...p, [key]: value }));
    const setLabel = (key: string, value: string) => setLabels((p) => ({ ...p, [key]: value }));

    // 離頁守衛：有未存變更時警告（dirty guard）。TODO（基本資料收尾）：補 legacy 的
    // 「名中/拼音任一空」專屬提示（#check_info）。
    useEffect(() => {
        const handler = (e: BeforeUnloadEvent) => {
            if (!dirty) return;
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', handler);
        return () => window.removeEventListener('beforeunload', handler);
    }, [dirty]);

    // indexYear 聯動：享年 = 卒-生+1；index_year = 享年>60 ? 生+60 : 卒（對齊 legacy indexYear()）。
    const recomputeIndexYear = (next: Fields): Fields => {
        const by = parseInt(next.c_birthyear ?? '', 10);
        const dy = parseInt(next.c_deathyear ?? '', 10);
        const out = { ...next };
        if (Number.isFinite(by) && Number.isFinite(dy) && dy >= by) {
            const age = dy - by + 1;
            out.c_death_age = String(age);
            out.c_index_year = String(age > 60 ? by + 60 : dy);
        }
        return out;
    };

    const buildEra = (g: DateGroup): EraTimeFieldValues => ({
        year: fields[g.year] ?? '',
        nhCode: fields[g.nhCode] ?? '',
        nhCodeLabel: labels[g.nhCode] ?? '',
        nhYear: fields[g.nhYear] ?? '',
        range: g.range ? fields[g.range] ?? '' : '',
        intercalary: g.intercalary ? fields[g.intercalary] ?? '0' : '0',
        month: g.month ? fields[g.month] ?? '' : '',
        day: g.day ? fields[g.day] ?? '' : '',
        dayGz: g.dayGz ? fields[g.dayGz] ?? '' : '',
        notes: g.notes ? fields[g.notes] ?? '' : '',
    });

    const applyEra = (g: DateGroup, patch: Partial<EraTimeFieldValues>) => {
        setFields((prev) => {
            let next = { ...prev };
            if (patch.year !== undefined) next[g.year] = patch.year;
            if (patch.nhCode !== undefined) next[g.nhCode] = patch.nhCode;
            if (patch.nhYear !== undefined) next[g.nhYear] = patch.nhYear;
            if (g.range && patch.range !== undefined) next[g.range] = patch.range;
            if (g.intercalary && patch.intercalary !== undefined) next[g.intercalary] = patch.intercalary;
            if (g.month && patch.month !== undefined) next[g.month] = patch.month;
            if (g.day && patch.day !== undefined) next[g.day] = patch.day;
            if (g.dayGz && patch.dayGz !== undefined) next[g.dayGz] = patch.dayGz;
            if (g.notes && patch.notes !== undefined) next[g.notes] = patch.notes;
            // 生/卒年變動時重算 indexYear。
            if ((g.year === 'c_birthyear' || g.year === 'c_deathyear') && patch.year !== undefined) {
                next = recomputeIndexYear(next);
            }
            return next;
        });
        if (patch.nhCodeLabel !== undefined) setLabel(g.nhCode, patch.nhCodeLabel);
    };

    // 生成拼音：用中文姓名查 /api/select/search/pinyin，回填拼音姓/名。
    const generatePinyin = async () => {
        try {
            const surnameRes = await fetch(`${pinyinEndpoint}?q=${encodeURIComponent(fields.c_surname_chn ?? '')}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
            }).then((r) => r.json()).catch(() => null);
            const mingziRes = await fetch(`${pinyinEndpoint}?q=${encodeURIComponent(fields.c_mingzi_chn ?? '')}&split=0`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
            }).then((r) => r.json()).catch(() => null);
            const pick = (res: unknown): string => {
                const rows = Array.isArray((res as { data?: unknown[] })?.data) ? (res as { data: Array<Record<string, unknown>> }).data : [];
                const first = rows[0];
                return first ? String(first.text ?? first.c_name ?? first.value ?? '') : '';
            };
            const sp = pick(surnameRes); const mp = pick(mingziRes);
            setFields((p) => ({ ...p, c_surname: sp || p.c_surname, c_mingzi: mp || p.c_mingzi }));
            setPinyinDone(true);
            window.setTimeout(() => setPinyinDone(false), 4000);
        } catch {
            setError(tr('save_failed', '生成拼音失敗'));
        }
    };

    const save = async (mode: 'direct' | 'proposal') => {
        setSaving(true); setError(null); setMessage(null);
        // 只送與初始不同、且非唯讀派生的欄位。
        const initial: Fields = JSON.parse(initialSnapshot.current);
        const changes: Record<string, string | null> = {};
        for (const [k, v] of Object.entries(fields)) {
            if (READONLY_DERIVED.includes(k)) continue;
            if ((initial[k] ?? '') !== (v ?? '')) changes[k] = v === '' ? null : v;
        }
        if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        try {
            const res = await fetch(mutateEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    resource: 'basicinformation', person_id: personId, mode, operation: 'update',
                    target: { pk: { c_personid: personId } }, changes,
                    ...(mode === 'proposal' ? { comment } : {}),
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setMessage(mode === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            initialSnapshot.current = JSON.stringify(fields);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const textRow = (key: string, label: string, readonly = false, hint?: string) => (
        <div style={rowStyle}>
            <label style={labelStyle}>{label}</label>
            <div style={fieldStyle}>
                <input type="text" value={fields[key] ?? ''} readOnly={readonly} disabled={readonly}
                    onChange={(e) => set(key, e.target.value)}
                    style={{ ...inputStyle, ...(readonly ? readonlyStyle : {}) }} />
                {hint ? <small style={hintStyle} className="text-muted">{hint}</small> : null}
            </div>
        </div>
    );

    const codeRow = (key: string, label: string, model: string, idKey: string, labelKeys: string[]) => (
        <div style={rowStyle}>
            <label style={labelStyle}>{label}</label>
            <div style={fieldStyle}>
                <CodeAutocomplete mode="list" model={model} idKey={idKey} labelKeys={labelKeys}
                    value={fields[key] ?? ''} initialLabel={labels[key] ?? ''}
                    onChange={(v, lbl) => { set(key, v); setLabel(key, lbl); }} disabled={!canEdit && !canPropose} />
            </div>
        </div>
    );

    const birth: DateGroup = { year: 'c_birthyear', nhCode: 'c_by_nh_code', nhYear: 'c_by_nh_year', range: 'c_by_range', intercalary: 'c_by_intercalary', month: 'c_by_month', day: 'c_by_day', dayGz: 'c_by_day_gz' };
    const death: DateGroup = { year: 'c_deathyear', nhCode: 'c_dy_nh_code', nhYear: 'c_dy_nh_year', range: 'c_dy_range', intercalary: 'c_dy_intercalary', month: 'c_dy_month', day: 'c_dy_day', dayGz: 'c_dy_day_gz' };
    const flEarly: DateGroup = { year: 'c_fl_earliest_year', nhCode: 'c_fl_ey_nh_code', nhYear: 'c_fl_ey_nh_year', notes: 'c_fl_ey_notes' };
    const flLate: DateGroup = { year: 'c_fl_latest_year', nhCode: 'c_fl_ly_nh_code', nhYear: 'c_fl_ly_nh_year', notes: 'c_fl_ly_notes' };

    return (
        <div style={cardStyle}>
            <h3 style={titleStyle}>{tr('basic_info_title', '人物基本資料')} — {personLabel}</h3>
            {message ? <div style={okStyle}>{message}</div> : null}
            {error ? <div style={errStyle}>{error}</div> : null}
            {pinyinDone ? <div style={okStyle}>{tr('basicinfo_pinyin_alert', '「生成拼音」已完成')}</div> : null}

            {textRow('c_surname_chn', tr('surname_chn', '姓（中）'))}
            {textRow('c_mingzi_chn', tr('mingzi_chn', '名（中）'))}
            {textRow('c_surname', 'Xing')}
            {textRow('c_mingzi', 'Ming')}
            {canEdit ? <button type="button" style={infoBtn} onClick={() => void generatePinyin()}>{tr('generate_pinyin_btn', '生成拼音')}</button> : null}
            {textRow('c_surname_proper', tr('foreign_surname', '外文姓'))}
            {textRow('c_mingzi_proper', tr('foreign_mingzi', '外文名'))}
            {textRow('c_surname_rm', tr('foreign_rm_surname', '羅馬字姓'))}
            {textRow('c_mingzi_rm', tr('foreign_rm_mingzi', '羅馬字名'))}
            {/* 4 唯讀自動派生 */}
            {textRow('c_name_chn', '姓名（中） (c_name_chn)', true, tr('name_auto_hint', '由姓和名自動合併，無需手動填寫'))}
            {textRow('c_name', '拼音 (c_name)', true, tr('pinyin_auto_hint', '由 Xing/Ming 自動合併，無需手動填寫'))}
            {textRow('c_name_proper', '外文全名 (c_name_proper)', true, tr('foreign_full_auto_hint', '自動合併，無需手動填寫'))}
            {textRow('c_name_rm', '羅馬字全名 (c_name_rm)', true, tr('rm_auto_hint', '自動合併，無需手動填寫'))}

            {/* 性別（NULL/0/1）以 select */}
            <div style={rowStyle}>
                <label style={labelStyle}>{tr('gender', '性別')} (c_female)</label>
                <div style={fieldStyle}>
                    <select value={fields.c_female ?? 'NULL'} disabled={!canEdit && !canPropose}
                        onChange={(e) => set('c_female', e.target.value)} style={inputStyle}>
                        <option value="NULL">NULL</option>
                        <option value="0">0-{tr('male', '男')}</option>
                        <option value="1">1-{tr('female', '女')}</option>
                    </select>
                </div>
            </div>
            {codeRow('c_ethnicity_code', tr('tribe', '族群/部族') + ' (c_ethnicity_code)', 'ethnicity', 'c_ethnicity_code', ['c_ethnicity_code', 'c_name_chn', 'c_name'])}
            {codeRow('c_dy', tr('dynasty', '朝代') + ' (c_dy)', 'dynasty', 'c_dy', ['c_dy', 'c_dynasty_chn', 'c_dynasty'])}

            <div style={sectionLabel}>{tr('birth_year', '生年')} (c_birthyear)</div>
            <EraTimeField values={buildEra(birth)} onChange={(p) => applyEra(birth, p)} dynastyCode={dynastyCode} showRange showLunar />
            <div style={sectionLabel}>{tr('death_year', '卒年')} (c_deathyear)</div>
            <EraTimeField values={buildEra(death)} onChange={(p) => applyEra(death, p)} dynastyCode={dynastyCode} showRange showLunar />

            {textRow('c_index_year', tr('index_year', '指數年') + ' (c_index_year)', true, tr('auto_calc_hint', '由算法自動計算'))}
            {textRow('c_death_age', tr('age_at_death', '享年') + ' (c_death_age)')}

            <div style={sectionLabel}>{tr('active_from', '在世始年')} (c_fl_earliest_year)</div>
            <EraTimeField values={buildEra(flEarly)} onChange={(p) => applyEra(flEarly, p)} dynastyCode={dynastyCode} showNotes />
            <div style={sectionLabel}>{tr('active_until', '在世終年')} (c_fl_latest_year)</div>
            <EraTimeField values={buildEra(flLate)} onChange={(p) => applyEra(flLate, p)} dynastyCode={dynastyCode} showNotes />

            {codeRow('c_choronym_code', tr('choronym', '郡望') + ' (c_choronym_code)', 'choronym', 'c_choronym_code', ['c_choronym_code', 'c_choronym_chn', 'c_choronym'])}
            {codeRow('c_household_status_code', tr('household_field', '戶籍') + ' (c_household_status_code)', 'household', 'c_household_status_code', ['c_household_status_code', 'c_household_status_desc_chn', 'c_household_status_desc'])}

            <div style={rowStyle}>
                <label style={labelStyle}>{tr('notes_field', '備註')} (c_notes)</label>
                <div style={fieldStyle}>
                    <textarea value={fields.c_notes ?? ''} disabled={!canEdit && !canPropose} rows={5}
                        onChange={(e) => set('c_notes', e.target.value)} style={{ ...inputStyle, height: 'auto' }} />
                </div>
            </div>

            {canPropose && !canEdit ? (
                <div style={rowStyle}>
                    <label style={labelStyle}>{tr('modification_note_label', '修改說明')}</label>
                    <div style={fieldStyle}>
                        <textarea value={comment} rows={3} onChange={(e) => setComment(e.target.value)}
                            placeholder={tr('modification_note_placeholder', '請說明修改原因')} style={{ ...inputStyle, height: 'auto' }} />
                    </div>
                </div>
            ) : null}

            <div style={submitRow}>
                {canEdit ? <button type="button" disabled={saving || !dirty} style={primaryBtn} onClick={() => void save('direct')}>{tr('save_directly', '直接保存')}</button> : null}
                {(canEdit || canPropose) ? <button type="button" disabled={saving || !dirty} style={infoBtn} onClick={() => void save('proposal')}>{tr('submit_proposal', '提交建議')}</button> : null}
            </div>
        </div>
    );
}

const cardStyle: React.CSSProperties = { background: '#fff', border: '1px solid #dee2e6', borderRadius: 8, padding: 20 };
const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
const rowStyle: React.CSSProperties = { display: 'flex', gap: 12, alignItems: 'flex-start', marginBottom: 10 };
const labelStyle: React.CSSProperties = { width: 220, flexShrink: 0, fontSize: '0.875rem', paddingTop: 8, color: '#374151' };
const fieldStyle: React.CSSProperties = { flex: 1 };
const inputStyle: React.CSSProperties = { width: '100%', height: 36, padding: '0 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: '0.875rem', boxSizing: 'border-box' };
const readonlyStyle: React.CSSProperties = { background: '#f5f5f5', cursor: 'not-allowed' };
const hintStyle: React.CSSProperties = { display: 'block', marginTop: 2, fontSize: '0.78rem', color: '#6b7280' };
const sectionLabel: React.CSSProperties = { fontSize: '0.875rem', fontWeight: 600, color: '#374151', margin: '8px 0 4px' };
const submitRow: React.CSSProperties = { display: 'flex', gap: 8, marginTop: 16 };
const primaryBtn: React.CSSProperties = { padding: '8px 16px', borderRadius: 6, border: '1px solid #255f93', background: '#255f93', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const infoBtn: React.CSSProperties = { padding: '8px 16px', borderRadius: 6, border: '1px solid #17a2b8', background: '#17a2b8', color: '#fff', fontWeight: 700, cursor: 'pointer', marginBottom: 10 };
const okStyle: React.CSSProperties = { background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#065f46', padding: '8px 12px', borderRadius: 6, marginBottom: 10, fontSize: '0.875rem' };
const errStyle: React.CSSProperties = { background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', padding: '8px 12px', borderRadius: 6, marginBottom: 10, fontSize: '0.875rem' };
