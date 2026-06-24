import React, { useState } from 'react';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import {
    gregorianToReignCandidates,
    findNianhaoIdByNameAndYear,
    findNianhaoIdByNameFallback,
    convertNianhaoIdToYear,
    type EraResult,
} from './eraConversion';

/**
 * React 版「年號/時間欄位 + 西元↔年號轉換」，對齊 legacy components/inline-time-fields.blade.php
 * 與 app.js 的 era-convert 邏輯：西元年 + 轉換鈕（西元→年號／年號→西元，雙向）+ 年號 + 年號年 + (時限) +
 * (閏月/月/日/干支) + (備註)。受控：值由 props 傳入、變更經 onChange 回傳。
 *
 * 年號/時限/干支用 accepted CodeAutocomplete（list 模式）；轉換鈕複用移植自 app.js 的轉換邏輯，
 * 朝代由 dynastyCode prop 注入（人物朝代）。多個年號結果時彈出選擇。
 */
export interface EraTimeFieldValues {
    year: string;
    nhCode: string;
    nhCodeLabel?: string;
    nhYear: string;
    range?: string;
    rangeLabel?: string;
    intercalary?: string; // '0' | '1'
    month?: string;
    day?: string;
    dayGz?: string;
    dayGzLabel?: string;
    notes?: string;
}

interface Props {
    values: EraTimeFieldValues;
    /** 合併式變更：parent 把 patch 併入自身 FormState。 */
    onChange: (patch: Partial<EraTimeFieldValues>) => void;
    /** 人物朝代代碼（用於年號轉換的朝代匹配）。 */
    dynastyCode?: number | null;
    showRange?: boolean;
    showLunar?: boolean;
    showNotes?: boolean;
    nhLabel?: string;
    rangeLabel?: string;
    intercalaryLabel?: string;
    dayGzLabel?: string;
    notesLabel?: string;
    disabled?: boolean;
}

export default function EraTimeField({
    values,
    onChange,
    dynastyCode,
    showRange = false,
    showLunar = false,
    showNotes = false,
    nhLabel = '年號',
    rangeLabel = '時限',
    intercalaryLabel = '閏月',
    dayGzLabel = '日(干支)',
    notesLabel = '備註',
    disabled = false,
}: Props) {
    const [eraOptions, setEraOptions] = useState<EraResult[] | null>(null);
    const [busy, setBusy] = useState(false);

    // 農曆月(1-12)/日(1-30) 範圍校驗（對齊 legacy initLunarValidation）。
    const inRange = (v: string | undefined, max: number) => {
        if (v == null || v === '') return true;
        const n = Number(v);
        return Number.isInteger(n) && n >= 1 && n <= max;
    };
    const monthInvalid = !inRange(values.month, 12);
    const dayInvalid = !inRange(values.day, 30);

    const fillFromEra = async (era: EraResult) => {
        const year = parseInt(values.year, 10);
        let id = await findNianhaoIdByNameAndYear(era.reign_title, year, Number(era.year));
        if (id == null) {
            const fb = await findNianhaoIdByNameFallback(era.reign_title, year);
            if (fb.found) {
                // 對齊 legacy app.js fillEraFields：精確匹配失敗時 fallback 只是「同名年號中最近一筆」，
                // 非已證實正確（同名跨朝代/cn-era 與 DB c_str 不一致時可能錯）。須先 confirm 再採用，
                // 不可靜默填入，否則有錯填年號 ID 的風險。
                const ok = window.confirm(
                    `年號「${era.reign_title}」的資料庫記錄與 cn-era 數據存在差異。\n\n` +
                    `cn-era：${era.reign_title} 第 ${era.year} 年（西元 ${year}）\n` +
                    `資料庫：${fb.dbInfo}\n\n` +
                    `是否使用資料庫中的記錄？（取消＝放棄轉換，請手動選擇）`,
                );
                if (!ok) {
                    return;
                }
                id = fb.id;
            } else {
                window.alert(`找到年號「${era.reign_title}」，但資料庫中無對應記錄，請手動選擇。`);
                return;
            }
        }
        onChange({ nhCode: String(id), nhCodeLabel: era.reign_title, nhYear: String(era.year) });
    };

    // 西元 → 年號
    const handleToReign = async () => {
        const year = parseInt(values.year, 10);
        if (Number.isNaN(year) || year === 0) {
            window.alert('請先輸入有效的西元年份');
            return;
        }
        setBusy(true);
        try {
            // 取「全部」候選（不過濾朝代），再自行依朝代過濾，以便偵測朝代不匹配並警告（對齊 app.js）。
            const all = gregorianToReignCandidates(year, null);
            if (!all.length) {
                window.alert(`無法找到西元 ${year} 年對應的年號`);
                return;
            }
            const dc = dynastyCode ?? null;
            // 對齊 legacy app.js：單一候選與多候選兩條分支分開處理，避免「朝代無對應 alert」與
            // 「單一結果 confirm」同時觸發（雙重彈窗）。
            if (all.length === 1) {
                const only = all[0];
                // 單一結果且與所選朝代不符 → 只 confirm（不另發朝代無對應 alert，對齊 app.js:529-549）。
                if (dc && dc > 0 && only.dynasty !== dc) {
                    const ok = window.confirm(
                        `所選朝代與查詢結果不符。\n\n查詢結果：${only.dynasty_name} ${only.reign_title} ${only.year_num}\n\n是否使用此結果？`,
                    );
                    if (!ok) {
                        return;
                    }
                }
                await fillFromEra(only);
                return;
            }
            // 多候選：依朝代過濾；過濾後為空 → 警告並用全部候選（對齊 legacy app.js:500-527）。
            let candidates = all;
            if (dc && dc > 0) {
                const filtered = all.filter((r) => r.dynasty === dc);
                if (filtered.length === 0) {
                    window.alert(`所選朝代在西元 ${year} 年沒有對應的年號。\n請檢查朝代選擇是否正確，或從以下全部候選中選擇。`);
                    candidates = all;
                } else {
                    candidates = filtered;
                }
            }
            if (candidates.length === 1) {
                await fillFromEra(candidates[0]); // 過濾後剩 1（朝代相符）→ 直接填
            } else {
                setEraOptions(candidates); // 多結果 → 彈出選擇
            }
        } finally {
            setBusy(false);
        }
    };

    // 年號 → 西元
    const handleToAd = async () => {
        if (!values.nhCode) {
            window.alert('請先選擇年號');
            return;
        }
        const nhYear = parseInt(values.nhYear, 10);
        if (Number.isNaN(nhYear) || nhYear <= 0) {
            window.alert('請輸入有效的年號年數');
            return;
        }
        setBusy(true);
        try {
            const res = await convertNianhaoIdToYear(values.nhCode, nhYear);
            if (res.success && res.year != null) {
                onChange({ year: String(res.year) });
            } else {
                window.alert(res.message || '轉換失敗');
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <div style={wrapStyle}>
            <input
                type="number"
                value={values.year}
                disabled={disabled}
                onChange={(e) => onChange({ year: e.target.value })}
                style={{ ...inputStyle, width: '12ch' }}
                aria-label="西元年份"
            />
            {!disabled && (
                <div style={btnGroupStyle}>
                    <button type="button" style={convBtnStyle} disabled={busy} onClick={() => void handleToReign()} title="西元 → 年號（由西元年換算年號）" aria-label="由西元換算年號">→</button>
                    <button type="button" style={convBtnStyle} disabled={busy} onClick={() => void handleToAd()} title="年號 → 西元（由年號換算西元年）" aria-label="由年號換算西元">←</button>
                </div>
            )}
            <div style={fieldGroupStyle}>
                <label style={labelStyle}>{nhLabel}</label>
                <div style={{ minWidth: '16ch', flex: '1 1 16ch' }}>
                    <CodeAutocomplete
                        mode="list"
                        model="nianhao"
                        idKey="c_nianhao_id"
                        labelKeys={['c_nianhao_chn']}
                        value={values.nhCode}
                        initialLabel={values.nhCodeLabel}
                        disabled={disabled}
                        onChange={(v, label) => onChange({ nhCode: v, nhCodeLabel: label })}
                    />
                </div>
                <input
                    type="number"
                    value={values.nhYear}
                    disabled={disabled}
                    onChange={(e) => onChange({ nhYear: e.target.value })}
                    style={{ ...inputStyle, width: '8ch' }}
                    aria-label="年號年"
                />
                <span style={unitStyle}>年</span>
            </div>
            {showRange && (
                <div style={fieldGroupStyle}>
                    <label style={labelStyle}>{rangeLabel}</label>
                    <div style={{ minWidth: '14ch', flex: '1 1 14ch' }}>
                        <CodeAutocomplete
                            mode="list"
                            model="range"
                            idKey="c_range_code"
                            labelKeys={['c_range_chn']}
                            value={values.range ?? ''}
                            initialLabel={values.rangeLabel}
                            disabled={disabled}
                            onChange={(v, label) => onChange({ range: v, rangeLabel: label })}
                        />
                    </div>
                </div>
            )}
            {showLunar && (
                <div style={fieldGroupStyle}>
                    <label style={checkboxLabelStyle}>
                        <input
                            type="checkbox"
                            checked={values.intercalary === '1'}
                            disabled={disabled}
                            onChange={(e) => onChange({ intercalary: e.target.checked ? '1' : '0' })}
                        />
                        {intercalaryLabel}
                    </label>
                    <input type="number" min={1} max={12} value={values.month ?? ''} disabled={disabled}
                        onChange={(e) => onChange({ month: e.target.value })}
                        style={{ ...inputStyle, width: '7ch', ...(monthInvalid ? invalidStyle : {}) }}
                        aria-invalid={monthInvalid} title={monthInvalid ? '請輸入 1-12 或留空' : undefined} aria-label="月" />
                    <span style={unitStyle}>月</span>
                    <span style={{ ...rangeHintStyle, ...(monthInvalid ? invalidHintStyle : {}) }}>(1-12)</span>
                    <input type="number" min={1} max={30} value={values.day ?? ''} disabled={disabled}
                        onChange={(e) => onChange({ day: e.target.value })}
                        style={{ ...inputStyle, width: '7ch', ...(dayInvalid ? invalidStyle : {}) }}
                        aria-invalid={dayInvalid} title={dayInvalid ? '請輸入 1-30 或留空' : undefined} aria-label="日" />
                    <span style={unitStyle}>日</span>
                    <span style={{ ...rangeHintStyle, ...(dayInvalid ? invalidHintStyle : {}) }}>(1-30)</span>
                    <label style={labelStyle}>{dayGzLabel}</label>
                    <div style={{ minWidth: '12ch', flex: '1 1 12ch' }}>
                        <CodeAutocomplete
                            mode="list"
                            model="ganzhi"
                            idKey="c_ganzhi_code"
                            labelKeys={['c_ganzhi_chn']}
                            value={values.dayGz ?? ''}
                            initialLabel={values.dayGzLabel}
                            disabled={disabled}
                            onChange={(v, label) => onChange({ dayGz: v, dayGzLabel: label })}
                        />
                    </div>
                </div>
            )}
            {showNotes && (
                <div style={{ ...fieldGroupStyle, width: '100%' }}>
                    <label style={labelStyle}>{notesLabel}</label>
                    <input type="text" value={values.notes ?? ''} disabled={disabled}
                        onChange={(e) => onChange({ notes: e.target.value })} style={{ ...inputStyle, flex: 1 }} />
                </div>
            )}

            {eraOptions && (
                <div style={dialogBackdrop} onClick={() => setEraOptions(null)}>
                    <div style={dialogBox} onClick={(e) => e.stopPropagation()}>
                        <div style={dialogTitle}>找到多個符合年號，請選擇：</div>
                        {eraOptions.map((opt) => (
                            <button
                                key={`${opt.dynasty ?? ''}-${opt.reign_title}-${opt.year}`}
                                type="button"
                                style={dialogOption}
                                onClick={() => { setEraOptions(null); void fillFromEra(opt); }}
                            >
                                <strong>{opt.dynasty_name}</strong> {opt.reign_title} {opt.year_num}
                            </button>
                        ))}
                        <button type="button" style={dialogCancel} onClick={() => setEraOptions(null)}>取消</button>
                    </div>
                </div>
            )}
        </div>
    );
}

const wrapStyle: React.CSSProperties = { display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 6, position: 'relative' };
const inputStyle: React.CSSProperties = { height: 34, padding: '0 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: '1rem', boxSizing: 'border-box' };
const invalidStyle: React.CSSProperties = { borderColor: '#dc3545', boxShadow: '0 0 0 1px #dc3545' };
const btnGroupStyle: React.CSSProperties = { display: 'flex', gap: 4 };
const convBtnStyle: React.CSSProperties = { height: 34, minWidth: 32, borderRadius: 6, border: '1px solid #cbd5e1', background: '#fff', cursor: 'pointer', fontSize: '1rem' };
const fieldGroupStyle: React.CSSProperties = { display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 6 };
const labelStyle: React.CSSProperties = { fontSize: '0.85rem', color: '#374151', whiteSpace: 'nowrap' };
const unitStyle: React.CSSProperties = { fontSize: '0.85rem', color: '#374151' };
// 農曆月/日合法範圍的常駐可見提示（對齊 legacy month_range_hint / day_range_hint，但常顯而非僅 invalid）。
const rangeHintStyle: React.CSSProperties = { fontSize: '0.78rem', color: '#94a3b8', marginLeft: -2 };
const invalidHintStyle: React.CSSProperties = { color: '#dc3545' };
const checkboxLabelStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: '0.85rem', color: '#374151' };
const dialogBackdrop: React.CSSProperties = { position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.3)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center' };
const dialogBox: React.CSSProperties = { background: '#fff', borderRadius: 10, padding: 16, minWidth: 320, maxHeight: '70vh', overflowY: 'auto', boxShadow: '0 12px 32px rgba(0,0,0,0.2)' };
const dialogTitle: React.CSSProperties = { fontWeight: 700, marginBottom: 10 };
const dialogOption: React.CSSProperties = { display: 'block', width: '100%', textAlign: 'left', padding: '8px 10px', border: '1px solid #e5e7eb', borderRadius: 6, background: '#fff', cursor: 'pointer', marginBottom: 6 };
const dialogCancel: React.CSSProperties = { display: 'block', width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', cursor: 'pointer', marginTop: 4 };
