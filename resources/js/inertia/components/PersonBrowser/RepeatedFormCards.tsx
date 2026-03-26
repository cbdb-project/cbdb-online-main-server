import React from 'react';

interface Props {
    columns: Record<string, string>;
    rows: Record<string, unknown>[];
}

/**
 * 以卡片式列表顯示重複表單資料（如別名、地址、出處等）。
 * 設計上為未來 inline edit / delete 預留空間。
 */
export default function RepeatedFormCards({ columns, rows }: Props) {
    if (!rows || rows.length === 0) {
        return <div style={emptyStyle}>無資料</div>;
    }

    const colEntries = Object.entries(columns);

    return (
        <div style={containerStyle}>
            {rows.map((row, i) => (
                <div key={i} style={cardStyle}>
                    {colEntries.map(([key, label]) => {
                        const val = row[key];
                        if (val == null || val === '') return null;
                        return (
                            <div key={key} style={fieldStyle}>
                                <span style={labelStyle}>{label}</span>
                                <span style={valueStyle}>{String(val)}</span>
                            </div>
                        );
                    })}
                </div>
            ))}
        </div>
    );
}

const containerStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 8,
};

const cardStyle: React.CSSProperties = {
    border: '1px solid #dee2e6',
    borderRadius: 6,
    padding: '10px 14px',
    backgroundColor: '#fff',
};

const fieldStyle: React.CSSProperties = {
    display: 'flex',
    gap: 8,
    padding: '3px 0',
    fontSize: '0.8125rem',
    borderBottom: '1px solid #f5f5f5',
};

const labelStyle: React.CSSProperties = {
    color: '#6c757d',
    minWidth: 100,
    flexShrink: 0,
};

const valueStyle: React.CSSProperties = {
    color: '#212529',
    wordBreak: 'break-word',
};

const emptyStyle: React.CSSProperties = {
    padding: 24,
    textAlign: 'center',
    color: '#6c757d',
    fontSize: '0.875rem',
};
