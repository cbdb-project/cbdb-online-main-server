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
    position: 'absolute',
    top: 10,
    right: 14,
    zIndex: 1,
};

const linkStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 26,
    padding: '0 10px',
    borderRadius: 4,
    border: '1px solid #c4737e',
    color: '#7a2030',
    backgroundColor: '#fff',
    textDecoration: 'none',
    fontSize: '0.8125rem',
    fontWeight: 600,
};
