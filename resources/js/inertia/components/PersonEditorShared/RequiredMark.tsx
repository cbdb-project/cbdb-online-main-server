import React from 'react';
import { useTranslation } from '../../hooks/useTranslation';

/**
 * 必填欄位標記：欄位 label 後綴一顆紅色 *（#63 全站一致）。
 * hover/螢幕報讀顯示「必填」。沿用 BasicInfoEditor 既有紅 * 樣式（#dc2626, 粗體）。
 */
export default function RequiredMark() {
    const t = useTranslation('biogmains');
    const label = t('required_field') || '必填';

    return (
        <span style={reqStyle} title={label} aria-label={label}>
            {' *'}
        </span>
    );
}

const reqStyle: React.CSSProperties = { color: 'var(--destructive)', fontWeight: 700 };
