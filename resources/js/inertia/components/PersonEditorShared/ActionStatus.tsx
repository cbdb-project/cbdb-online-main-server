import React from 'react';

/**
 * 動作按鈕旁的即時回饋（Q3）：緊鄰按鈕顯示「儲存中…（轉圈）」「✓ 已儲存」「✗ 失敗」，
 * 解決「按鈕無動畫、頂部 flash 太遠導致不確定是否已儲存」。全 13 編輯器共用，跨頁一致。
 * 動畫用 FontAwesome fa-spin（全站已載入），不需自訂 keyframe。
 */
export default function ActionStatus({ saving, deleting, message, error, t }: {
    saving?: boolean;
    deleting?: boolean;
    message?: string | null;
    error?: string | null;
    t?: (k: string) => string;
}) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    if (saving || deleting) {
        return (
            <span style={busyStyle} role="status" aria-live="polite">
                <i className="fas fa-spinner fa-spin" aria-hidden="true" style={{ marginRight: 6 }} />
                {saving ? tr('saving', '儲存中…') : tr('deleting', '刪除中…')}
            </span>
        );
    }
    if (error) return <span style={errStyle} role="status" aria-live="polite">✗ {error}</span>;
    if (message) return <span style={okStyle} role="status" aria-live="polite">✓ {message}</span>;
    return null;
}

/** 按鈕內的轉圈（儲存中），供各編輯器主要動作按鈕在 saving 時顯示，使按鈕本身有動畫回饋。 */
export function BtnSpinner() {
    return <i className="fas fa-spinner fa-spin" aria-hidden="true" style={{ marginRight: 6 }} />;
}

const busyStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', color: '#255f93', fontSize: '0.9rem', fontWeight: 600 };
const okStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', color: '#065f46', fontSize: '0.9rem', fontWeight: 600 };
const errStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', color: '#991b1b', fontSize: '0.9rem', fontWeight: 600 };
