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
                <div style={mainNameStyle}>{summary.c_name_chn || '—'}</div>
                {secondaryNames.length > 0 && (
                    <div style={secondaryNameBlockStyle}>
                        {secondaryNames.map((name) => (
                            <div key={name} style={secondaryNameStyle}>{name}</div>
                        ))}
                    </div>
                )}
                <div style={metaRowStyle}>
                    <MutedMeta label="CBDB ID (c_personid)" value={`#${summary.c_personid}`} />
                    <MutedMeta label="性別 (c_female)" value={summary.gender} />
                    <MutedMeta label="朝代 (c_dy)" value={summary.dynasty_chn} />
                    <MutedMeta label="Index Address (c_index_addr_id)" value={summary.index_addr_chn} />
                </div>
            </div>
            <div style={gridStyle}>
                <Field label="生卒年 (c_birthyear / c_deathyear)" value={lifespan} />
                <Field label="Index Year (c_index_year)" value={summary.c_index_year != null ? String(summary.c_index_year) : ''} />
                {summary.alt_name_zi && <Field label="字 (ALTNAME_DATA.c_alt_name_chn)" value={summary.alt_name_zi} />}
                {summary.alt_name_hao && <Field label="號 (ALTNAME_DATA.c_alt_name_chn)" value={summary.alt_name_hao} />}
            </div>
        </div>
    );
}

function MutedMeta({ label, value }: { label: string; value: string | null | undefined }) {
    if (!value) return null;

    return (
        <div style={mutedMetaStyle}>
            <span style={mutedMetaLabelStyle}>{label}</span>
            <span style={mutedMetaValueStyle}>{value}</span>
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
    padding: '16px 18px 14px',
    minHeight: 72,
    backgroundColor: '#fcfdff',
};

const headerStyle: React.CSSProperties = {
    marginBottom: 12,
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
    gap: '4px 12px',
    marginTop: 4,
};

const secondaryNameStyle: React.CSSProperties = {
    fontSize: '0.98rem',
    color: '#667788',
    lineHeight: 1.35,
};

const metaRowStyle: React.CSSProperties = {
    display: 'flex',
    flexWrap: 'wrap',
    gap: '6px 16px',
    marginTop: 10,
};

const gridStyle: React.CSSProperties = {
    display: 'flex',
    flexWrap: 'wrap',
    gap: '6px 18px',
};

const fieldStyle: React.CSSProperties = {
    display: 'flex',
    gap: 5,
    fontSize: '0.92rem',
    alignItems: 'baseline',
};

const fieldLabelStyle: React.CSSProperties = {
    color: '#677786',
    whiteSpace: 'nowrap',
};

const fieldValueStyle: React.CSSProperties = {
    color: '#203142',
    fontWeight: 600,
};

const mutedMetaStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'baseline',
    gap: 5,
    fontSize: '0.9rem',
};

const mutedMetaLabelStyle: React.CSSProperties = {
    color: '#8090a0',
    whiteSpace: 'nowrap',
};

const mutedMetaValueStyle: React.CSSProperties = {
    color: '#566678',
};

const msgStyle: React.CSSProperties = {
    padding: 12,
    color: '#6c757d',
    fontSize: '0.875rem',
    textAlign: 'center',
};
