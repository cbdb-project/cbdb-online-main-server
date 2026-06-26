import React, { useState } from 'react';
import { useTranslation } from '../../hooks/useTranslation';

/**
 * 共享的 AI「使用須知」隱私告知（可收合）：標題切換 + 同意條款 3 條 + 當前使用模型。
 * 由所有 AI 面板（AiCodeLookupPanel：assoc/statuses；PostingAiAutofill：offices）共用，
 * 確保告知內容與樣式單一來源、不再各自實作而分歧（曾發生 assoc 整段缺失）。
 */
// #84：收合狀態以 localStorage 跨頁/跨 session 記憶。使用者一旦收起，之後任何顯示此須知的頁面預設即收起；
// 首次（未設定）仍預設展開，確保同意須知對未讀者可見（§0.2 parity）。無 localStorage（隱私模式）時靜默回退展開。
const NOTICE_COLLAPSED_KEY = 'cbdb:ai-privacy-notice:collapsed';

function readCollapsed(): boolean {
    try {
        if (typeof window === 'undefined') {
            return false; // SSR 安全（目前 Inertia SSR 關閉，仍前瞻性防護）
        }
        return window.localStorage.getItem(NOTICE_COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

export default function AiPrivacyNotice({ aiModel }: { aiModel?: string }) {
    const t = useTranslation('biogmains');
    // 預設展開（首次/未設定）；曾收起過則讀 localStorage 回收合狀態。仍可隨時切換，切換即寫回 localStorage。
    const [show, setShow] = useState<boolean>(() => !readCollapsed());
    const toggle = () => {
        setShow((v) => {
            const next = !v;
            try {
                window.localStorage.setItem(NOTICE_COLLAPSED_KEY, next ? '0' : '1');
            } catch {
                /* localStorage 不可用（隱私模式等）：僅本次有效，不持久化 */
            }
            return next;
        });
    };
    return (
        <div style={wrapStyle}>
            <button type="button" style={toggleStyle} onClick={toggle} aria-expanded={show}>
                <i className="fas fa-circle-info" aria-hidden="true" style={{ marginRight: 4 }} />
                {t('ai_notice_title')}
                <i className={`fas fa-chevron-${show ? 'up' : 'down'}`} aria-hidden="true" style={{ marginLeft: 6, fontSize: '0.7rem' }} />
            </button>
            {show ? (
                <div style={boxStyle}>
                    <p style={{ margin: '0 0 6px', fontWeight: 600 }}>{t('ai_consent_intro')}</p>
                    <ul style={{ margin: 0, paddingLeft: 18 }}>
                        <li>{t('ai_consent_record')}</li>
                        {/* #27：用具名第三方服務版本（Google Gemini／OpenAI 等），對齊 legacy 官名自動填的隱私揭露、不遺漏舊頁內容。 */}
                        <li>{t('ai_consent_fill_third_party')}</li>
                        <li>{t('ai_consent_verify')}</li>
                    </ul>
                    {aiModel ? <p style={{ margin: '6px 0 0' }}>{t('ai_current_model')}<code>{aiModel}</code></p> : null}
                </div>
            ) : null}
        </div>
    );
}

const wrapStyle: React.CSSProperties = { marginBottom: 8 };
const toggleStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', border: '1px solid #93c5fd', background: '#fff', color: '#1d4ed8', borderRadius: 6, padding: '3px 10px', fontSize: '0.78rem', cursor: 'pointer' };
const boxStyle: React.CSSProperties = { marginTop: 6, padding: '10px 12px', background: '#f8fbff', border: '1px solid #dbeafe', borderRadius: 6, fontSize: '0.8rem', color: '#475569' };
