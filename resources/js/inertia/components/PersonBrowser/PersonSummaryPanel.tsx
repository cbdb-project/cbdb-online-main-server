import React from 'react';
import AddressDisplayWithMap from './shared/AddressDisplayWithMap';
import { formatBilingualLabel } from './shared/formatters';
import { APP_THEME } from '../../theme';

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
    dynasty_start: number | null;
    index_addr_chn: string | null;
    index_addr: string | null;
    index_addr_admin_cat_code: number | null;
    index_addr_admin_cat_label: string | null;
    index_addr_longitude: number | null;
    index_addr_latitude: number | null;
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
        return <div style={boxStyle}><div style={{ ...msgStyle, color: 'var(--destructive)' }}>{error}</div></div>;
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
                <Tag label="朝代" value={formatBilingualLabel(summary.dynasty_chn, summary.dynasty)} />
                <Tag
                    label="籍貫"
                    value={summary.index_addr_chn || summary.index_addr ? (
                        <AddressDisplayWithMap
                            labelChn={summary.index_addr_chn}
                            labelEng={summary.index_addr}
                            latitude={summary.index_addr_latitude}
                            longitude={summary.index_addr_longitude}
                            personId={summary.c_personid}
                        />
                    ) : '(未詳)'}
                />
                <Tag label="生卒" value={lifespan} />
                <Tag label="Index Year" value={summary.c_index_year != null ? String(summary.c_index_year) : null} />
                {summary.alt_name_zi ? <Tag label="字" value={summary.alt_name_zi} /> : null}
                {summary.alt_name_hao ? <Tag label="號" value={summary.alt_name_hao} /> : null}
            </div>
        </div>
    );
}

function Tag({ label, value }: { label: string; value: React.ReactNode }) {
    if (!value) return null;

    return (
        <span style={tagStyle}>
            <span style={tagLabelStyle}>{label}</span>
            <span style={tagValueStyle}>{value}</span>
        </span>
    );
}

const boxStyle: React.CSSProperties = {
    borderBottom: '1px solid var(--border)',
    padding: '16px 18px 14px',
    minHeight: 72,
    backgroundColor: 'var(--card)',
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
    color: 'var(--foreground)',
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
    color: 'var(--muted-foreground)',
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
    border: `1px solid ${APP_THEME.brandBorder}`,
};

const tagLabelStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    padding: '6px 10px',
    backgroundColor: APP_THEME.brandSurfaceStrong,
    color: APP_THEME.brandTextStrong,
    fontWeight: 600,
    whiteSpace: 'nowrap',
};

const tagValueStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    padding: '6px 11px',
    backgroundColor: 'var(--card)',
    color: APP_THEME.brandTextStrong,
    fontWeight: 700,
    whiteSpace: 'nowrap',
};

const msgStyle: React.CSSProperties = {
    padding: 12,
    color: 'var(--muted-foreground)',
    fontSize: '0.875rem',
    textAlign: 'center',
};
