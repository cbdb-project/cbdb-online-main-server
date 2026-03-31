import React from 'react';
import { buildLegacyEditUrl, LegacyPk } from './legacyEditUrl';

interface Props {
    tabKey: string;
    pk: LegacyPk;
    canEdit: boolean;
    fallbackPersonId?: number | null;
}

export default function LegacyEditButton({ tabKey, pk, canEdit, fallbackPersonId }: Props) {
    if (!canEdit) {
        return null;
    }

    const href = buildLegacyEditUrl(tabKey, pk, fallbackPersonId);
    if (!href) {
        return null;
    }

    return (
        <div style={containerStyle}>
            <a href={href} style={linkStyle}>
                修改
            </a>
        </div>
    );
}

const containerStyle: React.CSSProperties = {
    marginTop: 10,
    display: 'flex',
    justifyContent: 'flex-end',
};

const linkStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 30,
    padding: '0 12px',
    borderRadius: 4,
    border: '1px solid #17a2b8',
    color: '#17a2b8',
    backgroundColor: '#fff',
    textDecoration: 'none',
    fontSize: '0.875rem',
    fontWeight: 600,
};
