import React, { useState } from 'react';
import { getCsrfToken } from '../PersonBrowser/shared/csrf';

/**
 * AI 任官自動填面板（對齊 legacy offices/_form 的 AI 智能填充區塊）。
 * 僅新增模式顯示。POST 原文到 /api/ai/posting/extract，回傳 matched_fields/suggested_fields/statistics；
 * 由父層 onApply(data) 將欄位值套進 React 編輯器狀態（React CodeAutocomplete 直接吃 value+label，
 * 不需 legacy 那套 select2 重查 options 的邏輯）。onClear 還原 AI 套用前的狀態。
 */
export interface AiFieldEntry { value: unknown; text?: unknown }
export interface AiAutofillData {
    matched_fields?: Record<string, AiFieldEntry>;
    suggested_fields?: Record<string, AiFieldEntry>;
    statistics?: { matched_count?: number; suggested_count?: number; not_found_count?: number; empty_count?: number };
}

interface Props {
    personId: number;
    extractEndpoint: string;
    disabled?: boolean;
    t?: (k: string) => string;
    onApply: (data: AiAutofillData) => void;
    onClear: () => void;
}

export default function PostingAiAutofill({ personId, extractEndpoint, disabled = false, t, onApply, onClear }: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [status, setStatus] = useState<{ kind: 'info' | 'error' | 'ok'; msg: string } | null>(null);
    const [stats, setStats] = useState<AiAutofillData['statistics'] | null>(null);
    const [applied, setApplied] = useState(false);

    const run = async () => {
        const src = text.trim();
        if (!src) { setStatus({ kind: 'error', msg: tr('ai_enter_text_first', '請先輸入原文') }); return; }
        setBusy(true); setStatus({ kind: 'info', msg: tr('ai_fill_processing', 'AI 處理中…') });
        try {
            const res = await fetch(extractEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ source_text: src, person_id: personId, route_url: window.location.pathname }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.success) {
                throw new Error(json?.error?.message || json?.error || `HTTP ${res.status}`);
            }
            const data: AiAutofillData = json.data ?? {};
            onApply(data);
            setStats(data.statistics ?? null);
            setApplied(true);
            setStatus({ kind: 'ok', msg: tr('ai_fill_done_status', 'AI 填充完成，請核對') });
        } catch (e) {
            setStatus({ kind: 'error', msg: e instanceof Error ? e.message : tr('ai_fill_failed', 'AI 填充失敗') });
        } finally { setBusy(false); }
    };

    const clear = () => {
        if (!window.confirm(tr('ai_clear_confirm', '確定清除 AI 填入的內容？'))) return;
        onClear();
        setApplied(false); setStats(null); setStatus(null);
    };

    return (
        <div style={cardStyle}>
            <div style={headerStyle}>
                <strong>✨ {tr('ai_offices_autofill', 'AI 智能填充（任官）')}</strong>
                <span style={noticeStyle}>{tr('ai_fill_consent_intro', '原文將送交第三方 AI 服務處理，請確認後使用，並務必人工核對結果。')}</span>
            </div>
            <textarea value={text} disabled={disabled || busy} onChange={(e) => setText(e.target.value)} rows={4}
                placeholder={tr('ai_input_placeholder_offices', '貼上含任官資訊的原文，AI 會嘗試擷取官名／時間／地點／出處等欄位')}
                style={textareaStyle} />
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginTop: 6 }}>
                <button type="button" style={aiBtn} disabled={disabled || busy} onClick={() => void run()}>
                    ⚡ {tr('ai_fill_btn', 'AI 自動填')}
                </button>
                {applied ? <button type="button" style={clearBtn} disabled={busy} onClick={clear}>🧹 {tr('ai_clear_btn', '清除 AI 填入')}</button> : null}
                {status ? <span style={status.kind === 'error' ? errTextStyle : status.kind === 'ok' ? okTextStyle : infoTextStyle}>{status.msg}</span> : null}
            </div>
            {stats ? (
                <ul style={summaryStyle}>
                    <li>✅ {stats.matched_count ?? 0} {tr('ai_fields_matched_suffix', '欄位已匹配填入')}</li>
                    {(stats.suggested_count ?? 0) > 0 ? <li>⚠️ {stats.suggested_count} {tr('ai_fields_confirm_suffix', '欄位建議值（請確認）')}</li> : null}
                    {(stats.not_found_count ?? 0) > 0 ? <li>🔍 {stats.not_found_count} {tr('ai_fields_manual_suffix', '欄位查無代碼（請手動）')}</li> : null}
                </ul>
            ) : null}
        </div>
    );
}

const cardStyle: React.CSSProperties = { background: '#f0f9ff', border: '1px solid #bae6fd', borderRadius: 8, padding: 12, marginBottom: 14 };
const headerStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, marginBottom: 8 };
const noticeStyle: React.CSSProperties = { fontSize: '0.75rem', color: '#0369a1' };
const textareaStyle: React.CSSProperties = { width: '100%', padding: 8, borderRadius: 6, border: '1px solid #cbd5e1', fontSize: '0.85rem', boxSizing: 'border-box' };
const aiBtn: React.CSSProperties = { borderRadius: 8, padding: '7px 14px', border: '1px solid #0369a1', background: '#0ea5e9', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const clearBtn: React.CSSProperties = { borderRadius: 8, padding: '7px 12px', border: '1px solid #cbd5e1', background: '#fff', color: '#475569', fontWeight: 600, cursor: 'pointer' };
const infoTextStyle: React.CSSProperties = { fontSize: '0.82rem', color: '#0369a1' };
const okTextStyle: React.CSSProperties = { fontSize: '0.82rem', color: '#065f46' };
const errTextStyle: React.CSSProperties = { fontSize: '0.82rem', color: '#991b1b' };
const summaryStyle: React.CSSProperties = { margin: '8px 0 0', paddingLeft: 18, fontSize: '0.82rem', color: '#334155' };
