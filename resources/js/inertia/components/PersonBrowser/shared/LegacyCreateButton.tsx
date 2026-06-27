import React from 'react';
import { buildLegacyCreateUrl } from './legacyEditUrl';
import { APP_THEME } from '../../../theme';

interface Props {
    tabKey: string;
    canEdit: boolean;
    fallbackPersonId?: number | null;
}

export default function LegacyCreateButton({ tabKey, canEdit, fallbackPersonId }: Props) {
    if (!canEdit) {
        return null;
    }

    const href = buildLegacyCreateUrl(tabKey, fallbackPersonId);
    if (!href) {
        return null;
    }

    return (
        <div style={containerStyle}>
            <a href={href} style={linkStyle}>
                新增
            </a>
        </div>
    );
}

const containerStyle: React.CSSProperties = {
    display: 'flex',
    justifyContent: 'flex-end',
    marginBottom: 8,
};

const linkStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 30,
    padding: '0 12px',
    borderRadius: 4,
    border: `1px solid ${APP_THEME.brandBorder}`,
    color: APP_THEME.brandText,
    backgroundColor: 'var(--card)',
    textDecoration: 'none',
    fontSize: '0.875rem',
    fontWeight: 600,
};
