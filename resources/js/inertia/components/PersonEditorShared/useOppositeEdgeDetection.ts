import { useEffect, useState } from 'react';
import { getCsrfToken } from '../PersonBrowser/shared/csrf';

// #79（§4-A / §5-B）：編輯社會關係／親屬列時，偵測「對面互逆鏡像」現況。
// 後端 /api/v2/relationship/opposite-edges 回 {detection, count, status, edges}；僅 canWriteDirectly() 者得 detection=true。
// status: 'missing'(缺邊,問題A) / 'single'(正常) / 'multiple'(一對多,問題B)。

export type OppositeEdge = Record<string, string | number | null>;

export interface OppositeEdgeResult {
    detection: boolean;
    resource?: string;
    count?: number;
    status?: 'missing' | 'single' | 'multiple';
    edges?: OppositeEdge[];
}

export interface OppositeForward {
    opposite_id: number | string; // 對方人物 id（kin: c_kin_id / assoc: c_assoc_id）
    forward_code: number | string; // 正向關係碼（kin: c_kin_code / assoc: c_assoc_code）
    autogen_notes?: string | null; // kin 定位用
    text_title?: string; // assoc 定位用
    first_year?: number | string; // assoc 定位用
}

/**
 * 偵測對面互逆鏡像現況。enabled=false（如 create 模式）時不偵測。reloadKey 變更時重抓（存檔後刷新）。
 */
export function useOppositeEdgeDetection(opts: {
    enabled: boolean;
    resource: 'kinship' | 'associations';
    personId: number;
    forward: OppositeForward;
    reloadKey?: unknown;
}): { result: OppositeEdgeResult | null; loading: boolean } {
    const { enabled, resource, personId, forward, reloadKey } = opts;
    const [result, setResult] = useState<OppositeEdgeResult | null>(null);
    const [loading, setLoading] = useState(false);

    const oppositeId = Number(forward.opposite_id);
    const forwardCode = Number(forward.forward_code);
    const fwdKey = JSON.stringify(forward);

    useEffect(() => {
        // 對面 id / 正向碼為 0/空 時無意義（如「未详」對象），不偵測。
        if (!enabled || !oppositeId || !forwardCode) {
            setResult(null);
            setLoading(false);
            return;
        }
        let cancelled = false;
        setLoading(true);
        fetch('/api/v2/relationship/opposite-edges', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ resource, person_id: personId, forward }),
        })
            .then((r) => r.json().catch(() => ({})))
            .then((j) => { if (!cancelled) setResult(j && j.ok ? (j as OppositeEdgeResult) : null); })
            .catch(() => { if (!cancelled) setResult(null); })
            .finally(() => { if (!cancelled) setLoading(false); });
        return () => { cancelled = true; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, resource, personId, oppositeId, forwardCode, fwdKey, reloadKey]);

    return { result, loading };
}
