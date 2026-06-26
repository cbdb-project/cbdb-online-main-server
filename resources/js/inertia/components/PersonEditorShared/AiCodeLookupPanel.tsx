import React, { useRef, useState } from 'react';
import { useTranslation } from '../../hooks/useTranslation';
import { getCsrfToken } from '../PersonBrowser/shared/csrf';
import AiPrivacyNotice from './AiPrivacyNotice';

/**
 * 共享的「AI 智能識別代碼」面板（assoc / statuses 等 code-lookup 編輯器共用），
 * 統一外觀（紫色 AI 強調色）、隱私使用須知（含同意條款 + 當前模型）與互動，
 * 避免各編輯器各自實作而樣式/內容分歧（曾發生 assoc 漏掉整段使用須知）。
 *
 * 後端：POST aiSuggestEndpoint { query, table, person_id, route_name, route_url }
 *  → { success, ai_fill_log_id, data: { matched_codes, not_found, summary } }
 */
export interface AiCandidate {
    code_id: number | string;
    desc_chn?: string;
    desc_en?: string;
    relevance?: string;
    reason?: string;
}

interface Props {
    table: string;                 // 'ASSOC_CODES' | 'STATUS_CODES' …
    personId: number;
    aiSuggestEndpoint?: string;
    aiModel?: string;
    routeName?: string;
    /** 面板標題（各編輯器不同，如「AI 智能識別社會關係代碼」）。 */
    title: string;
    placeholder?: string;
    hint?: string;
    /** 點擊候選代碼後回填到對應欄位。 */
    onApply: (c: AiCandidate) => void;
    disabled?: boolean;
}

export function relevanceColor(relevance?: string): string {
    const r = (relevance ?? '').toLowerCase();
    if (r.includes('high') || r.includes('高')) return '#16a34a';
    if (r.includes('medium') || r.includes('中')) return '#d97706';
    if (r.includes('low') || r.includes('低')) return '#94a3b8';
    return '#c7d2fe';
}

export default function AiCodeLookupPanel({
    table, personId, aiSuggestEndpoint, aiModel, routeName,
    title, placeholder, hint, onApply, disabled = false,
}: Props) {
    const t = useTranslation('biogmains');
    const [query, setQuery] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [candidates, setCandidates] = useState<AiCandidate[] | null>(null);
    const [notFound, setNotFound] = useState<string[]>([]);
    const [summary, setSummary] = useState('');
    const aiFillLogId = useRef<number | null>(null);

    const run = async () => {
        const q = query.trim();
        if (!q) { setError(t('ai_enter_description')); return; }
        if (!aiSuggestEndpoint) return;
        setBusy(true); setError(null); setCandidates(null); setNotFound([]); setSummary('');
        try {
            const res = await fetch(aiSuggestEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    query: q, table, person_id: personId,
                    route_name: routeName ?? '',
                    route_url: typeof window !== 'undefined' ? window.location.pathname : '',
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.success) throw new Error(json?.error || t('ai_recognition_failed'));
            if (typeof json.ai_fill_log_id === 'number') aiFillLogId.current = json.ai_fill_log_id;
            const data = json.data ?? {};
            setCandidates(Array.isArray(data.matched_codes) ? data.matched_codes : []);
            setNotFound(Array.isArray(data.not_found) ? data.not_found : []);
            setSummary(typeof data.summary === 'string' ? data.summary : '');
        } catch (e) {
            setError(e instanceof Error ? e.message : t('ai_recognition_failed'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div style={cardStyle}>
            <div style={headerStyle}>
                <strong style={titleStyle}><i className="fas fa-wand-magic-sparkles" aria-hidden="true" style={{ marginRight: 6 }} />{title}</strong>
            </div>
            <div style={{ padding: 14 }}>
                <AiPrivacyNotice aiModel={aiModel} />
                <textarea value={query} disabled={busy || disabled} rows={3} onChange={(e) => setQuery(e.target.value)}
                    placeholder={placeholder} style={textareaStyle} />
                {hint ? <div style={hintStyle}>{hint}</div> : null}
                <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 12 }}>
                    <button type="button" style={runBtnStyle} disabled={busy || disabled} onClick={() => void run()}>
                        {busy ? t('ai_processing') : t('ai_recognize_btn')}
                    </button>
                    {error ? <span style={{ color: '#b91c1c', fontSize: '0.82rem' }}>{error}</span> : null}
                </div>
                {candidates ? (
                    <div style={{ marginTop: 12 }}>
                        <div style={{ fontWeight: 700, marginBottom: 6, fontSize: '0.85rem' }}>{t('ai_candidate_codes')}</div>
                        {candidates.length ? (
                            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                                {candidates.map((c) => (
                                    <button key={String(c.code_id)} type="button" title={c.reason}
                                        style={{ ...candidateBtnStyle, borderColor: relevanceColor(c.relevance) }}
                                        onClick={() => onApply(c)}>
                                        <strong>{c.code_id}</strong> {c.desc_chn ?? ''} {c.desc_en ? <small style={{ color: '#64748b' }}>({c.desc_en})</small> : null}
                                    </button>
                                ))}
                            </div>
                        ) : <span style={{ color: '#64748b', fontSize: '0.82rem' }}>{t('ai_no_match')}</span>}
                        {notFound.length ? (
                            <div style={{ marginTop: 8 }}>
                                <div style={{ color: '#d97706', fontSize: '0.82rem' }}>{t('ai_no_match')}</div>
                                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, marginTop: 4 }}>
                                    {notFound.map((n, i) => <span key={`${n}-${i}`} style={badgeStyle}>{n}</span>)}
                                </div>
                            </div>
                        ) : null}
                        {summary ? <div style={summaryStyle}>{summary}</div> : null}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

// 統一 AI 面板樣式：留在系統藍色系（與整站一致），以較飽和的藍底 + 魔杖圖示標示「智能/AI」功能，
// 主按鈕用品牌藍 #255f93；「未找到」徽章維持琥珀（警示語意）。
const cardStyle: React.CSSProperties = { background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 10, marginBottom: 14, overflow: 'hidden' };
const headerStyle: React.CSSProperties = { display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, padding: '10px 14px', background: '#dbeafe', borderBottom: '1px solid #bfdbfe' };
const titleStyle: React.CSSProperties = { color: '#1e3a8a', fontSize: '0.95rem' };
const textareaStyle: React.CSSProperties = { width: '100%', minHeight: 64, padding: '8px 10px', borderRadius: 8, border: '1px solid #cbd5e1', fontSize: '1rem', boxSizing: 'border-box', resize: 'vertical' };
const hintStyle: React.CSSProperties = { fontSize: '0.78rem', color: '#64748b', marginTop: 4 };
const runBtnStyle: React.CSSProperties = { padding: '8px 16px', borderRadius: 8, border: '1px solid #1d4d77', background: '#255f93', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const candidateBtnStyle: React.CSSProperties = { border: '1px solid #bfdbfe', background: '#fff', borderRadius: 999, padding: '4px 12px', fontSize: '0.82rem', cursor: 'pointer' };
const badgeStyle: React.CSSProperties = { background: '#fef3c7', color: '#92400e', borderRadius: 4, padding: '1px 7px', fontSize: '0.75rem' };
const summaryStyle: React.CSSProperties = { marginTop: 8, padding: '8px 10px', background: '#fff', border: '1px solid #dbeafe', borderRadius: 6, fontSize: '0.82rem', color: '#334155' };
