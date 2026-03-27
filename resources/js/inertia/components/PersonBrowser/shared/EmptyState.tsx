import React from 'react';

interface Props {
    message?: string;
}

export default function EmptyState({ message }: Props) {
    return (
        <div style={style}>
            <span style={{ fontSize: '1.25rem' }}>📭</span>
            <span>{message || '無資料'}</span>
        </div>
    );
}

const style: React.CSSProperties = {
    padding: 32,
    textAlign: 'center',
    color: '#6c757d',
    fontSize: '0.875rem',
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    gap: 8,
};
