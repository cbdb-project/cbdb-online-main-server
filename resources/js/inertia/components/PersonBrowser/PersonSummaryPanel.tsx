import React from 'react';

export interface PersonSummary {
    c_personid: number;
    c_name_chn: string | null;
    c_name: string | null;
    c_name_proper: string | null;
    c_name_rm: string | null;
    c_surname_chn: string | null;
    c_mingzi_chn: string | null;
    gender: string;
    c_birthyear: number | null;
    c_deathyear: number | null;
    c_index_year: number | null;
    index_year_type: string | null;
    dynasty_chn: string | null;
    dynasty: string | null;
    index_addr_chn: string | null;
    index_addr: string | null;
    c_notes: string | null;
    alt_name_zi: string;
    alt_name_hao: string;
    tab_counts: Record<string, number>;
}

interface Props {
    summary: PersonSummary | null;
    loading: boolean;
    error: string | null;
}

export default function PersonSummaryPanel({ summary, loading, error }: Props) {
    if (loading) {
        return <div style={boxStyle}><div style={msgStyle}>載入摘要中…</div></div>;
    }
    if (error) {
        return <div style={boxStyle}><div style={{ ...msgStyle, color: '#dc3545' }}>{error}</div></div>;
    }
    if (!summary) {
        return (
            <div style={boxStyle}>
                <div style={msgStyle}>請在左側選擇一位人物</div>
            </div>
        );
    }

    const lifespan =
        summary.c_birthyear || summary.c_deathyear
            ? `${summary.c_birthyear ?? '?'} – ${summary.c_deathyear ?? '?'}`
            : '';

    return (
        <div style={boxStyle}>
            <div style={headerStyle}>
                <div style={mainNameStyle}>
                    {summary.c_name_chn || '—'}
                    <span style={idBadgeStyle}>#{summary.c_personid}</span>
                </div>
                {summary.c_name && <div style={engNameStyle}>{summary.c_name}</div>}
                {summary.c_name_rm && summary.c_name_rm !== summary.c_name && (
                    <div style={engNameStyle}>{summary.c_name_rm}</div>
                )}
            </div>
            <div style={gridStyle}>
                <Field label="性別" value={summary.gender} />
                <Field label="朝代" value={summary.dynasty_chn} />
                <Field label="生卒年" value={lifespan} />
                <Field label="Index Year" value={summary.c_index_year != null ? String(summary.c_index_year) : ''} />
                <Field label="Index Year Type" value={summary.index_year_type} />
                <Field label="Index Address" value={summary.index_addr_chn} />
                {summary.alt_name_zi && <Field label="字" value={summary.alt_name_zi} />}
                {summary.alt_name_hao && <Field label="號" value={summary.alt_name_hao} />}
            </div>
        </div>
    );
}

function Field({ label, value }: { label: string; value: string | null | undefined }) {
    if (!value) return null;
    return (
        <div style={fieldStyle}>
            <span style={fieldLabelStyle}>{label}</span>
            <span style={fieldValueStyle}>{value}</span>
        </div>
    );
}

const boxStyle: React.CSSProperties = {
    borderBottom: '1px solid #dee2e6',
    padding: '12px 16px',
    minHeight: 60,
};

const headerStyle: React.CSSProperties = {
    marginBottom: 8,
};

const mainNameStyle: React.CSSProperties = {
    fontSize: '1.25rem',
    fontWeight: 700,
    display: 'flex',
    alignItems: 'baseline',
    gap: 8,
};

const idBadgeStyle: React.CSSProperties = {
    fontSize: '0.75rem',
    color: '#6c757d',
    fontWeight: 400,
};

const engNameStyle: React.CSSProperties = {
    fontSize: '0.875rem',
    color: '#495057',
};

const gridStyle: React.CSSProperties = {
    display: 'flex',
    flexWrap: 'wrap',
    gap: '4px 16px',
};

const fieldStyle: React.CSSProperties = {
    display: 'flex',
    gap: 4,
    fontSize: '0.8125rem',
};

const fieldLabelStyle: React.CSSProperties = {
    color: '#6c757d',
    whiteSpace: 'nowrap',
};

const fieldValueStyle: React.CSSProperties = {
    color: '#212529',
};

const msgStyle: React.CSSProperties = {
    padding: 12,
    color: '#6c757d',
    fontSize: '0.875rem',
    textAlign: 'center',
};
