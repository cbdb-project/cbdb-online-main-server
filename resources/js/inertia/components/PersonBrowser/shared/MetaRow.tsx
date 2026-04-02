import React from 'react';

interface Props {
    label: string;
    value: React.ReactNode;
}

/**
 * 單行 label + value 元件，用於 tab card 內的欄位呈現。
 */
export default function MetaRow({ label, value }: Props) {
    if (value == null || value === '') return null;

    return (
        <div style={rowStyle}>
            <span style={labelStyle}>{label}</span>
            <span style={valueStyle}>{value}</span>
        </div>
    );
}

const rowStyle: React.CSSProperties = {
    display: 'flex',
    gap: 8,
    padding: '4px 0',
    fontSize: '0.9rem',
    lineHeight: 1.4,
    borderBottom: '1px solid #f5f5f5',
};

const labelStyle: React.CSSProperties = {
    color: '#6c757d',
    minWidth: 80,
    flexShrink: 0,
    fontWeight: 500,
};

const valueStyle: React.CSSProperties = {
    color: '#212529',
    wordBreak: 'break-word',
};
