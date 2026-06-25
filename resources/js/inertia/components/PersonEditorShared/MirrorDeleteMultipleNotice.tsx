import React from 'react';

// #81 §6：刪除一筆親屬正向列時，後端以 legitReverses 廣集定位到「對面有多筆」對應反向列 → 回 409 + errors.mirror_delete_multiple。
// 本元件列出將被一併刪除的反向列（可點連結逐一前往對面 edit-v2 核對），並提供「確認一併刪除」（帶 meta.force 重送）與「取消」。
// 後端 update-all 對「合法多反向列」語義正確；此處僅把原本的靜默刪除改為偵測即停、由使用者確認，避免誤刪他段關係的反向列。

export interface MirrorDeleteMultiple {
    table: string;
    candidates: Array<Record<string, string | number | null>>;
    count: number;
}

interface Props {
    info: MirrorDeleteMultiple;
    urlFor: (row: Record<string, string | number | null>) => string; // 依候選列組對面 edit-v2 連結
    onConfirm: () => void;
    onDismiss: () => void;
    deleting?: boolean;
    tr: (k: string, fallback: string) => string;
}

const boxStyle: React.CSSProperties = {
    border: '1px solid #dc2626', background: '#fef2f2', borderRadius: 8,
    padding: '12px 14px', margin: '8px 0', fontSize: '0.9rem', color: '#991b1b',
};
const titleStyle: React.CSSProperties = { fontWeight: 600, marginBottom: 6 };
const listStyle: React.CSSProperties = { margin: '6px 0', paddingLeft: 18 };
const linkStyle: React.CSSProperties = { color: '#1d4ed8', textDecoration: 'underline', fontWeight: 600 };
const btnRow: React.CSSProperties = { display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' };
const confirmBtn: React.CSSProperties = {
    padding: '4px 12px', border: '1px solid #b91c1c', background: '#b91c1c', color: '#fff',
    borderRadius: 6, cursor: 'pointer', fontSize: '0.85rem',
};
const dismissBtn: React.CSSProperties = {
    padding: '4px 12px', border: '1px solid #d1d5db', background: '#fff', color: '#374151',
    borderRadius: 6, cursor: 'pointer', fontSize: '0.85rem',
};

const fmt = (row: Record<string, string | number | null>, tr: (k: string, fb: string) => string): string => {
    const parts = [`${tr('opposite_edge_code_label', '碼')} ${row.c_kin_code ?? ''}`];
    parts.push(`${tr('source_field', '出處')} ${row.c_source ?? 0}`);
    if (row.c_created_by) parts.push(`${tr('audit_created', '建檔')} ${row.c_created_by}`);
    return parts.join(' · ');
};

export default function MirrorDeleteMultipleNotice({ info, urlFor, onConfirm, onDismiss, deleting, tr }: Props) {
    const n = String(info.count ?? info.candidates.length);
    return (
        <div style={boxStyle} role="alert">
            <div style={titleStyle}>
                ⚠ {tr('mirror_delete_multiple_title', '對面有多筆對應的反向關係列')}
            </div>
            <div>
                {tr('mirror_delete_multiple_desc', '刪除此關係將一併刪除對面下列反向關係列（共 {n} 筆）。請先確認這些列確屬本段關係，再確認刪除；建議先前往對面人物逐一核對。').replace('{n}', n)}
            </div>
            <ul style={listStyle}>
                {info.candidates.map((row) => (
                    <li key={`${row.c_personid ?? ''}-${row.c_kin_id ?? ''}-${row.c_kin_code ?? ''}-${row.c_source ?? ''}`}>
                        <a href={urlFor(row)} target="_blank" rel="noopener noreferrer" style={linkStyle}>
                            {tr('mirror_suspected_goto', '前往核對（另開分頁）')}
                        </a>
                        <span style={{ marginLeft: 8, color: '#7f1d1d' }}>（{fmt(row, tr)}）</span>
                    </li>
                ))}
            </ul>
            <div style={btnRow}>
                <button type="button" style={confirmBtn} disabled={deleting} onClick={onConfirm}>
                    {deleting ? tr('deleting', '刪除中…') : tr('mirror_delete_multiple_confirm', '確認一併刪除全部')}
                </button>
                <button type="button" style={dismissBtn} disabled={deleting} onClick={onDismiss}>
                    {tr('cancel', '取消')}
                </button>
            </div>
        </div>
    );
}
