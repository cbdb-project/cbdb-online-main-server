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
        <a href={href} style={linkStyle} title="修改">
            ✎
        </a>
    );
}

const linkStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    width: 30,
    height: 30,
    borderRadius: 6,
    border: '1px solid #c4737e',
    color: '#7a2030',
    backgroundColor: '#fff',
    textDecoration: 'none',
    fontSize: '0.95rem',
    cursor: 'pointer',
};
