import React, { useRef } from 'react';
import { buildLegacyDeleteUrl, LegacyPk } from './legacyEditUrl';

interface Props {
    tabKey: string;
    pk: LegacyPk;
    canEdit: boolean;
    fallbackPersonId?: number | null;
}

export default function LegacyDeleteButton({ tabKey, pk, canEdit, fallbackPersonId }: Props) {
    if (!canEdit) {
        return null;
    }

    const formRef = useRef<HTMLFormElement | null>(null);

    const action = buildLegacyDeleteUrl(tabKey, pk, fallbackPersonId);
    if (!action) {
        return null;
    }

    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

    const handleClick = (event: React.MouseEvent) => {
        event.preventDefault();
        event.stopPropagation();

        if (!window.confirm('您真的確定要刪除嗎？\n\n請確認！')) {
            return;
        }

        formRef.current?.submit();
    };

    return (
        <>
            <form ref={formRef} method="POST" action={action} style={hiddenFormStyle}>
                <input type="hidden" name="_method" value="DELETE" />
                <input type="hidden" name="_token" value={csrfToken} />
            </form>
            <button type="button" style={buttonStyle} title="刪除" onClick={handleClick}>
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                    <line x1="2" y1="2" x2="12" y2="12" />
                    <line x1="12" y1="2" x2="2" y2="12" />
                </svg>
            </button>
        </>
    );
}

const hiddenFormStyle: React.CSSProperties = {
    display: 'none',
};

const buttonStyle: React.CSSProperties = {
    all: 'unset',
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    width: 30,
    height: 30,
    borderRadius: 6,
    border: '1px solid #dc3545',
    color: '#dc3545',
    backgroundColor: '#fff',
    fontSize: '0.85rem',
    cursor: 'pointer',
};
