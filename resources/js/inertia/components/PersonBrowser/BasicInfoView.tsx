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

type FieldValue = Field['value'];

export default function BasicInfoView({ sections }: Props) {
    if (!sections || sections.length === 0) {
        return <div style={emptyStyle}>無基本資料</div>;
    }

    return (
        <div style={panelStyle}>
            {sections.map((section, index) => (
                <div key={section.title} style={index === 0 ? sectionStyle : sectionWithDividerStyle}>
                    {renderSection(section)}
                </div>
            ))}
        </div>
    );
}

function renderSection(section: Section) {
    switch (section.title) {
        case '姓名資料':
            return renderNameSection(section);
        case '生卒年':
            return renderLifeSection(section);
        case '基本屬性':
            return renderPropertySection(section);
        case '指數資料':
            return renderIndexSection(section);
        case '活動年份':
            return renderActiveYearsSection(section);
        case '備註':
            return renderNotesSection(section);
        default:
            return renderFallbackSection(section);
    }
}

function renderNameSection(section: Section) {
    const fields = fieldMap(section);

    return (
        <>
            <SectionHeading title={section.title} badge={badgeLabel('Person ID', fields['Person ID'])} />
            <div style={nameIntroGridStyle}>
                <div style={pairedCardStyle}>
                    <div style={pairRowStyle}>
                        <ReadOnlyField label="姓" value={fields['中文姓']} />
                        <ReadOnlyField label="名" value={fields['中文名']} />
                    </div>
                    <div style={pairRowStyle}>
                        <ReadOnlyField label="Xing" value={fields['Xing']} />
                        <ReadOnlyField label="Ming" value={fields['Ming']} />
                    </div>
                </div>
                <div style={pairedCardStyle}>
                    <div style={pairRowStyle}>
                        <ReadOnlyField label="外文姓" value={fields['外文姓']} />
                        <ReadOnlyField label="外文名" value={fields['外文名']} />
                    </div>
                    <div style={pairRowStyle}>
                        <ReadOnlyField label="外文羅馬字轉寫姓" value={fields['外文羅馬字轉寫姓']} />
                        <ReadOnlyField label="外文羅馬字轉寫名" value={fields['外文羅馬字轉寫名']} />
                    </div>
                </div>
            </div>
            <div style={derivedGridStyle}>
                <ReadOnlyField label="姓名" value={fields['姓名']} muted />
                <ReadOnlyField label="姓名拼音" value={fields['姓名拼音']} muted />
                <ReadOnlyField label="外文全名" value={fields['外文全名']} muted />
                <ReadOnlyField label="外文羅馬字轉寫姓名" value={fields['外文羅馬字轉寫姓名']} muted />
            </div>
        </>
    );
}

function renderLifeSection(section: Section) {
    const fields = fieldMap(section);

    return (
        <>
            <SectionHeading title={section.title} />
            <div style={timelineGridStyle}>
                <TimelineCard
                    title="生年"
                    items={[
                        ['年份', fields['出生年']],
                        ['年號', fields['出生年號']],
                        ['年號年', fields['出生年號年']],
                        ['範圍', fields['出生年範圍']],
                        ['閏月', fields['出生閏月']],
                        ['月份', fields['出生月']],
                        ['日期', fields['出生日']],
                        ['日干支', fields['出生日時干支']],
                    ]}
                />
                <TimelineCard
                    title="卒年"
                    items={[
                        ['年份', fields['死亡年']],
                        ['年號', fields['死亡年號']],
                        ['年號年', fields['死亡年號年']],
                        ['範圍', fields['死亡年範圍']],
                        ['閏月', fields['死亡閏月']],
                        ['月份', fields['死亡月']],
                        ['日期', fields['死亡日']],
                        ['日干支', fields['死亡日時干支']],
                    ]}
                />
            </div>
            <div style={compactGridStyle}>
                <ReadOnlyField label="享年" value={fields['享年']} />
                <ReadOnlyField label="享年範圍" value={fields['享年範圍']} />
            </div>
        </>
    );
}

function renderPropertySection(section: Section) {
    const fields = fieldMap(section);
    const mergedFields = [
        { label: '性別', value: fields['性別'] },
        { label: '朝代', value: joinDisplayValues(fields['朝代（中文）'], fields['朝代（英文）']) },
        { label: '族裔', value: joinDisplayValues(fields['族裔（中文）'], fields['族裔（英文）']) },
        { label: '郡望', value: joinDisplayValues(fields['郡望（中文）'], fields['郡望（英文）']) },
        { label: '戶籍', value: joinDisplayValues(fields['戶籍（中文）'], fields['戶籍（英文）']) },
    ];
    return (
        <>
            <SectionHeading title={section.title} />
            <div style={compactGridStyle}>
                {mergedFields.map((field) => (
                    <ReadOnlyField key={field.label} label={field.label} value={field.value} />
                ))}
            </div>
        </>
    );
}

function renderIndexSection(section: Section) {
    const fields = fieldMap(section);
    const indexYearType = joinDisplayValues(fields['Index Year Type（中文）'], fields['Index Year Type（英文）']);
    const indexAddress = joinDisplayValues(fields['Index Address（中文）'], fields['Index Address（英文）']);

    return (
        <>
            <SectionHeading title={section.title} />
            <div style={indexHeroGridStyle}>
                <ReadOnlyField label="Index Year" value={fields['Index Year']} emphasis />
                <ReadOnlyField label="Index Year Type (c_index_year_type_code)" value={indexYearType} />
            </div>
            <div style={compactGridStyle}>
                <ReadOnlyField label="Index Year Source" value={fields['Index Year Source']} fullWidth />
                <ReadOnlyField label="Index Address" value={indexAddress} />
                <ReadOnlyField label="Index Address Type" value={fields['Index Address Type']} />
            </div>
        </>
    );
}

function renderActiveYearsSection(section: Section) {
    const fields = fieldMap(section);

    return (
        <>
            <SectionHeading title={section.title} />
            <div style={timelineGridStyle}>
                <TimelineCard
                    title="在世始年 (c_fl_earliest_year)"
                    items={[
                        ['公元年份', fields['在世始年']],
                        ['年號', fields['在世始年號']],
                        ['年號年', fields['在世始年號年']],
                    ]}
                    note={fields['在世始年註']}
                />
                <TimelineCard
                    title="在世終年 (c_fl_latest_year)"
                    items={[
                        ['公元年份', fields['在世終年']],
                        ['年號', fields['在世終年號']],
                        ['年號年', fields['在世終年號年']],
                    ]}
                    note={fields['在世終年註']}
                />
            </div>
        </>
    );
}

function renderNotesSection(section: Section) {
    const fields = fieldMap(section);

    return (
        <>
            <SectionHeading title={section.title} />
            <div style={notesBoxStyle}>{displayValue(fields['備註'])}</div>
        </>
    );
}

function renderFallbackSection(section: Section) {
    return (
        <>
            <SectionHeading title={section.title} />
            <div style={compactGridStyle}>
                {section.fields.map((field) => (
                    <ReadOnlyField key={field.label} label={field.label} value={field.value} />
                ))}
            </div>
        </>
    );
}

function SectionHeading({ title, badge }: { title: string; badge?: string | null }) {
    return (
        <div style={sectionHeadingStyle}>
            <h4 style={sectionTitleStyle}>{title}</h4>
            {badge ? <span style={sectionBadgeStyle}>{badge}</span> : null}
        </div>
    );
}

function TimelineCard({
    title,
    items,
    note,
}: {
    title: string;
    items: Array<[string, FieldValue]>;
    note?: FieldValue;
}) {
    return (
        <div style={timelineCardStyle}>
            <div style={timelineTitleStyle}>{title}</div>
            <div style={timelineItemsGridStyle}>
                {items.map(([label, value]) => (
                    <ReadOnlyField key={label} label={label} value={value} />
                ))}
                {note !== undefined ? <ReadOnlyField label="備註" value={note} fullWidth subtle /> : null}
            </div>
        </div>
    );
}

function ReadOnlyField({
    label,
    value,
    fullWidth = false,
    muted = false,
    subtle = false,
    emphasis = false,
}: {
    label: string;
    value: FieldValue;
    fullWidth?: boolean;
    muted?: boolean;
    subtle?: boolean;
    emphasis?: boolean;
}) {
    return (
        <div
            style={{
                ...fieldWrapStyle,
                ...(fullWidth ? fullWidthStyle : {}),
            }}
        >
            <div style={fieldLabelStyle}>{label}</div>
            <div
                style={{
                    ...fieldValueBoxStyle,
                    ...(muted ? mutedValueBoxStyle : {}),
                    ...(subtle ? subtleValueBoxStyle : {}),
                    ...(emphasis ? emphasisValueBoxStyle : {}),
                }}
            >
                {displayValue(value)}
            </div>
        </div>
    );
}

function fieldMap(section: Section): Record<string, FieldValue> {
    return Object.fromEntries(section.fields.map((field) => [field.label, field.value]));
}

function displayValue(value: FieldValue) {
    if (value == null || value === '') {
        return '—';
    }

    return String(value);
}

function badgeLabel(prefix: string, value: FieldValue) {
    if (value == null || value === '') {
        return null;
    }

    return `${prefix} ${value}`;
}

function joinDisplayValues(left: FieldValue, right: FieldValue) {
    const parts = [left, right]
        .map((value) => (value == null ? '' : String(value).trim()))
        .filter((value) => value !== '');

    if (parts.length === 0) {
        return '—';
    }

    return parts.join(' / ');
}

const panelStyle: React.CSSProperties = {
    backgroundColor: '#fff',
    border: '1px solid #d3dae3',
    borderRadius: 10,
    boxShadow: '0 1px 3px rgba(15, 23, 42, 0.05)',
    overflow: 'hidden',
};

const sectionStyle: React.CSSProperties = {
    padding: 20,
};

const sectionWithDividerStyle: React.CSSProperties = {
    ...sectionStyle,
    borderTop: '1px solid #e8edf3',
};

const sectionHeadingStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    marginBottom: 16,
    flexWrap: 'wrap',
};

const sectionTitleStyle: React.CSSProperties = {
    margin: 0,
    fontSize: '1rem',
    fontWeight: 700,
    color: '#233444',
    letterSpacing: '0.01em',
};

const sectionBadgeStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    padding: '5px 10px',
    borderRadius: 999,
    backgroundColor: '#eef4fb',
    color: '#30567a',
    fontSize: '0.78rem',
    fontWeight: 700,
};

const nameIntroGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))',
    gap: 16,
    marginBottom: 16,
};

const pairedCardStyle: React.CSSProperties = {
    padding: 16,
    border: '1px solid #e4eaf1',
    borderRadius: 10,
    backgroundColor: '#f9fbfd',
};

const pairRowStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
    gap: 14,
};

const derivedGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
    gap: 14,
};

const compactGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
    gap: 14,
};

const indexHeroGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
    gap: 14,
    marginBottom: 14,
};

const timelineGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))',
    gap: 16,
    marginBottom: 14,
};

const timelineCardStyle: React.CSSProperties = {
    border: '1px solid #e4eaf1',
    borderRadius: 10,
    backgroundColor: '#fbfcfe',
    padding: 16,
};

const timelineTitleStyle: React.CSSProperties = {
    fontSize: '0.9rem',
    fontWeight: 700,
    color: '#35516b',
    marginBottom: 12,
};

const timelineItemsGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
    gap: 12,
};

const fieldWrapStyle: React.CSSProperties = {
    minWidth: 0,
};

const fullWidthStyle: React.CSSProperties = {
    gridColumn: '1 / -1',
};

const fieldLabelStyle: React.CSSProperties = {
    fontSize: '0.77rem',
    fontWeight: 700,
    color: '#667788',
    marginBottom: 6,
};

const fieldValueBoxStyle: React.CSSProperties = {
    minHeight: 42,
    padding: '10px 12px',
    borderRadius: 8,
    border: '1px solid #d7dee7',
    backgroundColor: '#fff',
    color: '#1f2d3d',
    lineHeight: 1.5,
    wordBreak: 'break-word',
};

const mutedValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#f4f7fa',
    borderColor: '#dbe3ec',
};

const subtleValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#fffdf7',
    borderColor: '#eadfb4',
};

const emphasisValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#eef5fb',
    borderColor: '#c8daea',
    fontSize: '1.05rem',
    fontWeight: 700,
};

const notesBoxStyle: React.CSSProperties = {
    padding: '14px 16px',
    borderRadius: 10,
    border: '1px solid #dadfc7',
    backgroundColor: '#fffdf7',
    color: '#2a3642',
    lineHeight: 1.7,
    whiteSpace: 'pre-wrap',
    wordBreak: 'break-word',
};

const emptyStyle: React.CSSProperties = {
    padding: 24,
    textAlign: 'center',
    color: '#6c757d',
};
