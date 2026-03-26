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
                    <div style={sectionHeaderStyle}>
                        <h4 style={sectionTitleStyle}>{section.title}</h4>
                    </div>
                    <div style={section.title === '備註' ? fullWidthGridStyle : fieldsGridStyle}>
                        {section.fields.map((f, j) => (
                            <div key={j} style={fieldCardStyle(f.label)}>
                                <div style={labelStyle}>{f.label}</div>
                                <div style={valueStyle}>
                                    {f.value != null && f.value !== '' ? String(f.value) : '—'}
                                </div>
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
    border: '1px solid #d8dde3',
    borderRadius: 10,
    boxShadow: '0 1px 2px rgba(15, 23, 42, 0.04)',
    overflow: 'hidden',
};

const sectionHeaderStyle: React.CSSProperties = {
    padding: '12px 16px 0',
};

const sectionTitleStyle: React.CSSProperties = {
    margin: 0,
    fontSize: '1rem',
    fontWeight: 700,
    color: '#22313f',
};

const fieldsGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
    gap: 12,
    padding: 16,
};

const fullWidthGridStyle: React.CSSProperties = {
    ...fieldsGridStyle,
    gridTemplateColumns: '1fr',
};

const fieldCardBaseStyle: React.CSSProperties = {
    border: '1px solid #e5e9ef',
    borderRadius: 8,
    backgroundColor: '#fafbfd',
    padding: '12px 14px',
    minHeight: 74,
};

const labelStyle: React.CSSProperties = {
    color: '#6b7280',
    fontSize: '0.75rem',
    fontWeight: 600,
    letterSpacing: '0.02em',
    marginBottom: 6,
};

const valueStyle: React.CSSProperties = {
    color: '#1f2937',
    fontSize: '0.95rem',
    lineHeight: 1.5,
    wordBreak: 'break-word',
};

function fieldCardStyle(label: string): React.CSSProperties {
    if (label.includes('註') || label.includes('備註')) {
        return {
            ...fieldCardBaseStyle,
            gridColumn: '1 / -1',
            backgroundColor: '#fffdf7',
        };
    }

    return fieldCardBaseStyle;
}

const emptyStyle: React.CSSProperties = {
    padding: 24,
    textAlign: 'center',
    color: '#6c757d',
};
