import React from 'react';

// #70 鏡像「疑似匹配」提示（assoc / kinship 共用）。
// 後端嚴格定位（碼∈合法反向集）落空、但放寬查到對面有「碼漂移的疑似列」時回 409 + errors.mirror_suspected。
// 本元件呈現警告、列出疑似列（可點連結逐一跳對面 edit-v2 核對）、以及「強制收斂」（帶 meta.force 重送，將漂移碼修為
// 權威反向碼 authoritative_code；多條時修出一條正確列、其餘留人工刪）與「取消」。

export interface MirrorSuspected {
    table: string;
    candidates: Array<Record<string, string | number>>;
    authoritative_code: number;
    count: number;
}

interface Props {
    suspected: MirrorSuspected;
    urlFor: (pk: Record<string, string | number>) => string; // 依疑似列 pk 組對面 edit-v2 連結
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

const pkLabel = (pk: Record<string, string | number>): string =>
    Object.entries(pk).map(([k, v]) => `${k}=${v}`).join('，');

export default function MirrorSuspectedNotice({ suspected, urlFor, onForce, onDismiss, forcing, tr }: Props) {
    const multi = suspected.count > 1;
    return (
        <div style={boxStyle} role="alert">
            <div style={titleStyle}>
                ⚠ {tr('mirror_suspected_title', '對面找不到匹配的反向碼，但偵測到疑似同一關係的記錄')}
            </div>
            <div>
                {multi
                    ? tr('mirror_suspected_desc_multi', '對面有多條疑似記錄（關係碼可能已漂移，或屬另一段關係）。請逐一前往對面核對整理；強制收斂會修出一條權威反向列，其餘疑似列請自行確認後刪除。')
                    : tr('mirror_suspected_desc_one', '對面有一條疑似同一關係的記錄，其關係碼可能已漂移。請前往對面核對；或確認後強制收斂為權威反向碼。')}
            </div>
            <ul style={listStyle}>
                {suspected.candidates.map((pk) => (
                    <li key={pkLabel(pk)}>
                        <a href={urlFor(pk)} target="_blank" rel="noopener noreferrer" style={linkStyle}>
                            {tr('mirror_suspected_goto', '前往核對（另開分頁）')}
                        </a>
                        <span style={{ marginLeft: 8, color: '#78350f' }}>（{pkLabel(pk)}）</span>
                    </li>
                ))}
            </ul>
            <div style={{ color: '#78350f' }}>
                {tr('mirror_suspected_authoritative', '強制收斂目標反向碼')}：<code>{suspected.authoritative_code}</code>
            </div>
            <div style={btnRow}>
                <button type="button" style={forceBtn} disabled={forcing} onClick={onForce}>
                    {forcing ? tr('saving', '儲存中…') : tr('mirror_suspected_force', '強制收斂')}
                </button>
                <button type="button" style={dismissBtn} disabled={forcing} onClick={onDismiss}>
                    {tr('cancel', '取消')}
                </button>
            </div>
        </div>
    );
}
