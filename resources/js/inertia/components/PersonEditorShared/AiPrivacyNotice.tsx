import React, { useState } from 'react';
import { useTranslation } from '../../hooks/useTranslation';

/**
 * 共享的 AI「使用須知」隱私告知（可收合）：標題切換 + 同意條款 3 條 + 當前使用模型。
 * 由所有 AI 面板（AiCodeLookupPanel：assoc/statuses；PostingAiAutofill：offices）共用，
 * 確保告知內容與樣式單一來源、不再各自實作而分歧（曾發生 assoc 整段缺失）。
 */
export default function AiPrivacyNotice({ aiModel }: { aiModel?: string }) {
    const t = useTranslation('biogmains');
    // 預設展開：legacy 永遠顯示完整數據收集/第三方服務同意須知，新版若預設收合等於把同意聲明藏在點擊後，
    // 屬 §0.2 parity 退化（且使用者多次強調此須知須可見）。故預設展開、仍可收合。
    const [show, setShow] = useState(true);
    return (
        <div style={wrapStyle}>
            <button type="button" style={toggleStyle} onClick={() => setShow((v) => !v)} aria-expanded={show}>
                <i className="fas fa-circle-info" aria-hidden="true" style={{ marginRight: 4 }} />
                {t('ai_notice_title')}
                <i className={`fas fa-chevron-${show ? 'up' : 'down'}`} aria-hidden="true" style={{ marginLeft: 6, fontSize: '0.7rem' }} />
            </button>
            {show ? (
                <div style={boxStyle}>
                    <p style={{ margin: '0 0 6px', fontWeight: 600 }}>{t('ai_consent_intro')}</p>
                    <ul style={{ margin: 0, paddingLeft: 18 }}>
                        <li>{t('ai_consent_record')}</li>
                        <li>{t('ai_consent_third_party')}</li>
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
