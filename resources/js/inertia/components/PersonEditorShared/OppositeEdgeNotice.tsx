import React from 'react';
import { OppositeEdgeResult } from './useOppositeEdgeDetection';

// #79（§4-A）：對面互逆鏡像現況提示（非阻斷）。
// - missing：對面尚無對應反向關係 → 提示「儲存時將自動為對方建立」（補建由 v2 update 的鏡像同步 allowBackfill 處理）。
// - multiple：對面有多筆對應 → 基本提示（問題 B / #80 將擴充「逐列連結 + 動作裁決」）。
// single → 不顯示（正常雙向）。

const baseBox: React.CSSProperties = {
    margin: '8px 0', padding: '10px 12px', borderRadius: 8, fontSize: '0.86rem', lineHeight: 1.5,
};
const missingBox: React.CSSProperties = { ...baseBox, border: '1px solid #f0c674', background: '#fffaf0', color: '#8a5a00' };
const multipleBox: React.CSSProperties = { ...baseBox, border: '1px solid #93c5fd', background: '#eff6ff', color: '#1e40af' };

export default function OppositeEdgeNotice({ result, reverseCodeLabel, tr }: {
    result: OppositeEdgeResult | null;
    reverseCodeLabel?: string;
    tr: (k: string, fb: string) => string;
}) {
    if (!result || !result.detection) {
        return null;
    }

    if (result.status === 'missing') {
        return (
            <div role="status" style={missingBox}>
                <strong>{tr('opposite_edge_missing_title', '對面尚無對應的反向關係')}</strong>
                <div style={{ marginTop: 3 }}>
                    {tr('opposite_edge_missing_desc', '對面人物目前沒有對應的反向關係。儲存此關係時，將自動為對方建立反向關係列（雙向同步）。')}
                    {reverseCodeLabel
                        ? ' ' + tr('opposite_edge_reverse_code', '反向關係碼：') + reverseCodeLabel
                        : ''}
                </div>
            </div>
        );
    }

    if (result.status === 'multiple') {
        const n = String(result.count ?? 0);
        const edges = result.edges ?? [];
        const codeKey = result.resource === 'kinship' ? 'c_kin_code' : 'c_assoc_code';
        const fmt = (e: Record<string, string | number | null>): string => {
            const parts = [`${tr('opposite_edge_code_label', '碼')} ${e[codeKey] ?? ''}`];
            if (e.c_text_title) parts.push(`${tr('text_title_field', '出處標題')} ${e.c_text_title}`);
            parts.push(`${tr('source_field', '出處')} ${e.c_source ?? 0}`);
            if (e.c_created_by) parts.push(`${tr('audit_created', '建檔')} ${e.c_created_by}`);
            return parts.join(' · ');
        };
        return (
            <div role="status" style={multipleBox}>
                <strong>{tr('opposite_edge_multiple_title', '對面已有多筆對應關係')}</strong>
                <div style={{ marginTop: 3 }}>
                    {tr('opposite_edge_multiple_desc', '對面人物已有多筆對應的反向關係（共 {n} 筆）。請確認下列記錄是否正確，建議前往對面人物逐一檢視整理。').replace('{n}', n)}
                </div>
                {edges.length ? (
                    <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
                        {edges.map((e, i) => (
                            <li key={`${e[codeKey] ?? ''}-${i}`} style={{ marginTop: 2 }}>{fmt(e)}</li>
                        ))}
                    </ul>
                ) : null}
            </div>
        );
    }

    return null;
}
