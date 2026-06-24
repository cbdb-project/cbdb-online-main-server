import React from 'react';

// #66 雙向鏡像同步衝突提示（assoc / kinship 共用）。
// 後端偵測到「對面鏡像列的對應欄位已有不同內容」時回 409 + errors.mirror_conflict；
// 本元件呈現警告、逐欄列出衝突（對面現值 → 本次將寫入值）、提供可點連結跳對面 edit-v2 檢查、
// 以及「強制覆寫」（帶 meta.force 重送）與「取消」。

export interface MirrorConflictField {
    field: string;
    existing: unknown;
    incoming: unknown;
}

export interface MirrorConflict {
    table: string;
    pk: Record<string, string | number>;
    fields: MirrorConflictField[];
}

interface Props {
    conflict: MirrorConflict;
    mirrorUrl: string; // 對面鏡像列 edit-v2 連結（呼叫端依 pk 組好）
    onForce: () => void;
    onDismiss: () => void;
    forcing?: boolean;
    tr: (k: string, fallback: string) => string;
}

const boxStyle: React.CSSProperties = {
    border: '1px solid #f59e0b', background: '#fffbeb', borderRadius: 8,
    padding: '12px 14px', margin: '8px 0', fontSize: '0.9rem', color: '#92400e',
};
const titleStyle: React.CSSProperties = { fontWeight: 600, marginBottom: 6 };
const listStyle: React.CSSProperties = { margin: '6px 0', paddingLeft: 18 };
const linkStyle: React.CSSProperties = { color: '#1d4ed8', textDecoration: 'underline', fontWeight: 600 };
const btnRow: React.CSSProperties = { display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' };
const forceBtn: React.CSSProperties = {
    padding: '4px 12px', border: '1px solid #b45309', background: '#b45309', color: '#fff',
    borderRadius: 6, cursor: 'pointer', fontSize: '0.85rem',
};
const dismissBtn: React.CSSProperties = {
    padding: '4px 12px', border: '1px solid #d1d5db', background: '#fff', color: '#374151',
    borderRadius: 6, cursor: 'pointer', fontSize: '0.85rem',
};

const display = (v: unknown): string => {
    if (v === null || v === undefined || String(v).trim() === '') return trEmpty;
    return String(v);
};
const trEmpty = '（空）';

export default function MirrorConflictNotice({ conflict, mirrorUrl, onForce, onDismiss, forcing, tr }: Props) {
    return (
        <div style={boxStyle} role="alert">
            <div style={titleStyle}>
                ⚠ {tr('mirror_conflict_title', '對面記錄的對應欄位已有不同內容')}
            </div>
            <div>
                {tr('mirror_conflict_desc', '同步到對方記錄時會覆寫以下欄位，可能洗掉對方既有資料。建議先前往對面檢查，或確認後再強制覆寫。')}
            </div>
            <ul style={listStyle}>
                {conflict.fields.map((f) => (
                    <li key={f.field}>
                        <code>{f.field}</code>：{display(f.existing)} → {display(f.incoming)}
                    </li>
                ))}
            </ul>
            <div>
                <a href={mirrorUrl} target="_blank" rel="noopener noreferrer" style={linkStyle}>
                    {tr('mirror_conflict_goto', '前往對面記錄檢查（另開分頁）')}
                </a>
            </div>
            <div style={btnRow}>
                <button type="button" style={forceBtn} disabled={forcing} onClick={onForce}>
                    {forcing ? tr('saving', '儲存中…') : tr('mirror_conflict_force', '強制覆寫對面')}
                </button>
                <button type="button" style={dismissBtn} disabled={forcing} onClick={onDismiss}>
                    {tr('cancel', '取消')}
                </button>
            </div>
        </div>
    );
}
