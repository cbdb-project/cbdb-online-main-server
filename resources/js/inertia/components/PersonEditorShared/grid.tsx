import React from 'react';
import RequiredMark from './RequiredMark';

/**
 * 人物編輯器共用「網格設計語言」（#53 單一真相）：上標籤卡片 + 技術碼淡化 + 響應式雙欄網格。
 * 樣式值由 BasicInfoEditor 既有設計逐字抽出，所有 13 個編輯器共用以保跨頁一致。
 *
 * ⚠️ 焦點保留鐵則：欄位內容（input / autocomplete / 選擇器）必須由呼叫端直接建立並以 children 傳入；
 * 本檔只提供「模組層級的穩定函式 / 元件」（GridLabel / gridCell / gridInput），
 * 絕不可在編輯器 render 函式內部「定義新元件」再渲染欄位——否則每次 render 重新掛載 → input 失焦。
 */

// ─── 樣式常數（逐字對齊 BasicInfoEditor 既有值）──────────────────────────────
export const gridCardStyle: React.CSSProperties = { background: '#fff', border: '1px solid #dee2e6', borderRadius: 8, padding: 20 };
export const gridSectionStyle: React.CSSProperties = { marginBottom: 22 };
export const gridSectionHeadStyle: React.CSSProperties = { fontSize: '0.82rem', fontWeight: 700, color: '#255f93', letterSpacing: '0.06em', textTransform: 'uppercase', paddingBottom: 8, marginBottom: 14, borderBottom: '1px solid #e6eef6' };
// 響應式雙欄：寬螢幕 2 欄、窄螢幕自動降為 1 欄（min(100%,22rem) 使極窄時不溢出）。
export const gGrid: React.CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 22rem), 1fr))', gap: '14px 22px', marginBottom: 4 };
export const gFull: React.CSSProperties = { gridColumn: '1 / -1' };
export const gLabelStyle: React.CSSProperties = { display: 'block', fontSize: '0.9rem', fontWeight: 600, color: '#374151', marginBottom: 5 };
export const gCodeStyle: React.CSSProperties = { fontWeight: 400, color: '#9aa4b2', fontSize: '0.78rem', marginLeft: 6 };
export const gInputStyle: React.CSSProperties = { width: '100%', height: 40, padding: '0 11px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: '1rem', boxSizing: 'border-box', background: '#fff' };
export const gHintStyle: React.CSSProperties = { display: 'block', marginTop: 4, fontSize: '0.8rem', color: '#6b7280' };
export const gReadonlyStyle: React.CSSProperties = { background: '#f5f5f5', cursor: 'not-allowed' };
export const gAuditWrapStyle: React.CSSProperties = { marginTop: 16, paddingTop: 12, borderTop: '1px solid #e5e7eb' };

// 動作列：主要動作靠左、危險/另存靠右。
export const gSubmitRow: React.CSSProperties = { display: 'flex', gap: 8, marginTop: 16, flexWrap: 'wrap', alignItems: 'center' };
export const gBtnGroupRight: React.CSSProperties = { display: 'flex', gap: 8, flexWrap: 'wrap', marginLeft: 'auto' };
const actionBtnBase: React.CSSProperties = { padding: '8px 16px', borderRadius: 6, fontSize: '0.95rem', fontWeight: 700, cursor: 'pointer', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', border: '1px solid transparent' };
export const gPrimaryBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid #255f93', background: '#255f93', color: '#fff' };
export const gInfoBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid #0891b2', background: '#0891b2', color: '#fff' };
export const gDangerBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid #dc3545', background: '#dc3545', color: '#fff' };
export const gSuccessBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid #28a745', background: '#28a745', color: '#fff' };
export const gCancelBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid #cbd5e1', background: '#fff', color: '#374151' };

// 訊息橫幅。
export const gOkStyle: React.CSSProperties = { background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#065f46', padding: '8px 12px', borderRadius: 6, marginBottom: 10, fontSize: '1rem' };
export const gErrStyle: React.CSSProperties = { background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', padding: '8px 12px', borderRadius: 6, marginBottom: 10, fontSize: '1rem' };
export const gWarnStyle: React.CSSProperties = { background: '#fffbeb', border: '1px solid #fde68a', color: '#92400e', padding: '8px 12px', borderRadius: 6, marginBottom: 10, fontSize: '1rem' };

// ─── 標籤 / 欄位輔助（模組層級、穩定身分；安全不致 input 失焦）──────────────
/** 上標籤：label + 可選紅 *（必填）+ 可選淡化技術碼後綴。 */
export function GridLabel({ label, code, required }: { label: string; code?: string; required?: boolean }) {
    return (
        <label style={gLabelStyle}>
            {label}
            {required ? <RequiredMark /> : null}
            {code ? <span style={gCodeStyle}>{code}</span> : null}
        </label>
    );
}

export interface GridCellOpts {
    code?: string;
    required?: boolean;
    full?: boolean;
    hint?: string;
}

/**
 * 網格欄位：上標籤 + 任意欄位內容（children，由呼叫端建立）+ 可選 hint。
 * 以「函式呼叫」使用：gridCell('標籤', { full:true }, <CodeAutocomplete .../>)；children 為呼叫端既有元素，
 * React 依型別/位置 reconcile，不會重掛載、不失焦。
 */
export function gridCell(label: string, opts: GridCellOpts, children: React.ReactNode): React.ReactElement {
    return (
        <div style={opts.full ? gFull : undefined}>
            <GridLabel label={label} code={opts.code} required={opts.required} />
            {children}
            {opts.hint ? <span style={gHintStyle}>{opts.hint}</span> : null}
        </div>
    );
}

export interface GridInputOpts {
    value: string;
    onChange: (v: string) => void;
    type?: string;
    disabled?: boolean;
    readonly?: boolean;
    highlight?: boolean;
    placeholder?: string;
    maxLength?: number;
}

/** 純文字/數字輸入（取代各編輯器的 textRow/numRow 內層 input）。value/onChange 由呼叫端綁定其 fields/set。 */
export function gridInput(o: GridInputOpts): React.ReactElement {
    return (
        <input
            type={o.type ?? 'text'}
            value={o.value}
            disabled={o.disabled || o.readonly}
            readOnly={o.readonly}
            placeholder={o.placeholder}
            maxLength={o.maxLength}
            onChange={(e) => o.onChange(e.target.value)}
            style={{ ...gInputStyle, ...(o.readonly || o.disabled ? gReadonlyStyle : {}), ...(o.highlight ? { background: '#FFFFBB' } : {}) }}
        />
    );
}

/** 區塊分組：留白 + 藍系標題列；內部欄位走一致響應式網格。 */
export function GridSection({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div style={gridSectionStyle}>
            <div style={gridSectionHeadStyle}>{title}</div>
            {children}
        </div>
    );
}
