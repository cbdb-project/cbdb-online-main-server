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

    const secondaryNames = [summary.c_name, summary.c_name_rm]
        .filter((value, index, array) => value && array.indexOf(value) === index) as string[];

    return (
        <div style={boxStyle}>
            <div style={headerStyle}>
                <div style={nameRowStyle}>
                    <div style={mainNameStyle}>{summary.c_name_chn || '—'}</div>
                    {secondaryNames.length > 0 && (
                        <div style={secondaryNameBlockStyle}>
                            {secondaryNames.map((name) => (
                                <div key={name} style={secondaryNameStyle}>{name}</div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
            <div style={tagRowStyle}>
                <Tag label="ID" value={`#${summary.c_personid}`} />
                <Tag label="性別" value={summary.gender} />
                <Tag label="朝代" value={summary.dynasty_chn} />
                <Tag label="籍貫" value={summary.index_addr_chn} />
                <Tag label="生卒" value={lifespan} />
                <Tag label="Index Year" value={summary.c_index_year != null ? String(summary.c_index_year) : null} />
                {summary.alt_name_zi ? <Tag label="字" value={summary.alt_name_zi} /> : null}
                {summary.alt_name_hao ? <Tag label="號" value={summary.alt_name_hao} /> : null}
            </div>
        </div>
    );
}

function Tag({ label, value }: { label: string; value: string | null | undefined }) {
    if (!value) return null;

    return (
        <span style={tagStyle}>
            <span style={tagLabelStyle}>{label}</span>
            <span style={tagValueStyle}>{value}</span>
        </span>
    );
}

const boxStyle: React.CSSProperties = {
    borderBottom: '1px solid #dee2e6',
    padding: '16px 18px 14px',
    minHeight: 72,
    backgroundColor: '#fcfdff',
};

const headerStyle: React.CSSProperties = {
    marginBottom: 12,
};

const nameRowStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'baseline',
    flexWrap: 'wrap',
    gap: '4px 14px',
};

const mainNameStyle: React.CSSProperties = {
    fontSize: '1.65rem',
    fontWeight: 700,
    color: '#17293b',
    lineHeight: 1.2,
};

const secondaryNameBlockStyle: React.CSSProperties = {
    display: 'flex',
    flexWrap: 'wrap',
    gap: '4px 10px',
    alignItems: 'baseline',
};

const secondaryNameStyle: React.CSSProperties = {
    fontSize: '1.65rem',
    color: '#667788',
    lineHeight: 1.2,
};

const tagRowStyle: React.CSSProperties = {
    display: 'flex',
    flexWrap: 'wrap',
    gap: 8,
};

const tagStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    borderRadius: 6,
    overflow: 'hidden',
    fontSize: '0.9rem',
    lineHeight: 1,
    border: '1px solid #e0d5d7',
};

const tagLabelStyle: React.CSSProperties = {
    padding: '6px 10px',
    backgroundColor: '#f9eced',
    color: '#7a2030',
    fontWeight: 600,
    whiteSpace: 'nowrap',
};

const tagValueStyle: React.CSSProperties = {
    padding: '6px 11px',
    backgroundColor: '#fff',
    color: '#2a1015',
    fontWeight: 700,
    whiteSpace: 'nowrap',
};

const msgStyle: React.CSSProperties = {
    padding: 12,
    color: '#6c757d',
    fontSize: '0.875rem',
    textAlign: 'center',
};
