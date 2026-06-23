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
 * 與 app.js 的 era-convert 邏輯：西元年 + 轉換鈕（→年號／←西元）+ 年號 + 年號年 + (時限) +
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

    const fillFromEra = async (era: EraResult) => {
        const year = parseInt(values.year, 10);
        let id = await findNianhaoIdByNameAndYear(era.reign_title, year, Number(era.year));
        if (id == null) {
            const fb = await findNianhaoIdByNameFallback(era.reign_title, year);
            if (fb.found) {
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
            const candidates = gregorianToReignCandidates(year, dynastyCode ?? null);
            if (!candidates.length) {
                window.alert(`無法找到西元 ${year} 年對應的年號`);
                return;
            }
            if (candidates.length === 1) {
                await fillFromEra(candidates[0]);
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
                    <button type="button" style={convBtnStyle} disabled={busy} onClick={() => void handleToReign()} title="轉為年號">→</button>
                    <button type="button" style={convBtnStyle} disabled={busy} onClick={() => void handleToAd()} title="轉為西元">←</button>
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
                        onChange={(e) => onChange({ month: e.target.value })} style={{ ...inputStyle, width: '7ch' }} aria-label="月" />
                    <span style={unitStyle}>月</span>
                    <input type="number" min={1} max={30} value={values.day ?? ''} disabled={disabled}
                        onChange={(e) => onChange({ day: e.target.value })} style={{ ...inputStyle, width: '7ch' }} aria-label="日" />
                    <span style={unitStyle}>日</span>
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
                        {eraOptions.map((opt, i) => (
                            <button
                                key={`${opt.reign_title}-${i}`}
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
const inputStyle: React.CSSProperties = { height: 34, padding: '0 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: '0.875rem', boxSizing: 'border-box' };
const btnGroupStyle: React.CSSProperties = { display: 'flex', gap: 4 };
const convBtnStyle: React.CSSProperties = { height: 34, minWidth: 32, borderRadius: 6, border: '1px solid #cbd5e1', background: '#fff', cursor: 'pointer', fontSize: '1rem' };
const fieldGroupStyle: React.CSSProperties = { display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 6 };
const labelStyle: React.CSSProperties = { fontSize: '0.85rem', color: '#374151', whiteSpace: 'nowrap' };
const unitStyle: React.CSSProperties = { fontSize: '0.85rem', color: '#374151' };
const checkboxLabelStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: '0.85rem', color: '#374151' };
const dialogBackdrop: React.CSSProperties = { position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.3)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center' };
const dialogBox: React.CSSProperties = { background: '#fff', borderRadius: 10, padding: 16, minWidth: 320, maxHeight: '70vh', overflowY: 'auto', boxShadow: '0 12px 32px rgba(0,0,0,0.2)' };
const dialogTitle: React.CSSProperties = { fontWeight: 700, marginBottom: 10 };
const dialogOption: React.CSSProperties = { display: 'block', width: '100%', textAlign: 'left', padding: '8px 10px', border: '1px solid #e5e7eb', borderRadius: 6, background: '#fff', cursor: 'pointer', marginBottom: 6 };
const dialogCancel: React.CSSProperties = { display: 'block', width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', cursor: 'pointer', marginTop: 4 };
