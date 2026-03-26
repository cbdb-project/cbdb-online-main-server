import React from 'react';

interface Props {
    sections: Section[];
}

interface Section {
    title: string;
    fields: Field[];
}

interface Field {
    label: string;
    value: string | number | null;
}

export default function BasicInfoView({ sections }: Props) {
    if (!sections || sections.length === 0) {
        return <div style={emptyStyle}>無基本資料</div>;
    }

    return (
        <div style={containerStyle}>
            {sections.map((section, i) => (
                <div key={i} style={sectionStyle}>
                    <h4 style={sectionTitleStyle}>{section.title}</h4>
                    <div style={fieldsGridStyle}>
                        {section.fields.map((f, j) => (
                            <div key={j} style={fieldRowStyle}>
                                <span style={labelStyle}>{f.label}</span>
                                <span style={valueStyle}>
                                    {f.value != null && f.value !== '' ? String(f.value) : '—'}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}

const containerStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 16,
};

const sectionStyle: React.CSSProperties = {
    backgroundColor: '#fff',
    border: '1px solid #dee2e6',
    borderRadius: 6,
    overflow: 'hidden',
};

const sectionTitleStyle: React.CSSProperties = {
    margin: 0,
    padding: '8px 14px',
    fontSize: '0.875rem',
    fontWeight: 600,
    backgroundColor: '#f8f9fa',
    borderBottom: '1px solid #dee2e6',
};

const fieldsGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))',
    gap: 0,
};

const fieldRowStyle: React.CSSProperties = {
    display: 'flex',
    padding: '6px 14px',
    borderBottom: '1px solid #f0f0f0',
    fontSize: '0.8125rem',
    gap: 8,
};

const labelStyle: React.CSSProperties = {
    color: '#6c757d',
    minWidth: 120,
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
};
