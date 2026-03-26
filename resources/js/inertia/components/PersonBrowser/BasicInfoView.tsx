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
    const groups = [
        {
            title: '中文',
            items: [
                ['中文姓 (c_surname_chn)', fields['中文姓']],
                ['中文名 (c_mingzi_chn)', fields['中文名']],
                ['中文姓名 (c_name_chn)', fields['姓名']],
            ] as Array<[string, FieldValue]>,
        },
        {
            title: '拼音',
            items: [
                ['拼音姓 (c_surname)', fields['Xing']],
                ['拼音名 (c_mingzi)', fields['Ming']],
                ['拼音姓名 (c_name)', fields['姓名拼音']],
            ] as Array<[string, FieldValue]>,
        },
        {
            title: '外文',
            items: [
                ['外文姓 (c_surname_proper)', fields['外文姓']],
                ['外文名 (c_mingzi_proper)', fields['外文名']],
                ['外文姓名 (c_name_proper)', fields['外文全名']],
            ] as Array<[string, FieldValue]>,
        },
        {
            title: '外文羅馬字轉寫',
            items: [
                ['外文羅馬字轉寫姓 (c_surname_rm)', fields['外文羅馬字轉寫姓']],
                ['外文羅馬字轉寫名 (c_mingzi_rm)', fields['外文羅馬字轉寫名']],
                ['外文羅馬字轉寫姓名 (c_name_rm)', fields['外文羅馬字轉寫姓名']],
            ] as Array<[string, FieldValue]>,
        },
    ];

    return (
        <>
            <SectionHeading title={section.title} badge={badgeLabel('Person ID', fields['Person ID'])} />
            <div style={nameGroupGridStyle}>
                {groups.map((group) => (
                    <div key={group.title} style={nameGroupCardStyle}>
                        <div style={nameGroupTitleStyle}>{group.title}</div>
                        <div style={stackedFieldGroupStyle}>
                            {group.items.map(([label, value], index) => (
                                <ReadOnlyField
                                    key={label}
                                    label={label}
                                    value={value}
                                    derived={index === group.items.length - 1}
                                />
                            ))}
                        </div>
                    </div>
                ))}
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
                        ['年份 (c_birthyear)', fields['出生年']],
                        ['年號 (c_by_nh_code)', fields['出生年號']],
                        ['年號年 (c_by_nh_year)', fields['出生年號年']],
                        ['範圍 (c_by_range)', fields['出生年範圍']],
                        ['閏月 (c_by_intercalary)', fields['出生閏月']],
                        ['月份 (c_by_month)', fields['出生月']],
                        ['日期 (c_by_day)', fields['出生日']],
                        ['日干支 (c_by_day_gz)', fields['出生日時干支']],
                    ]}
                />
                <TimelineCard
                    title="卒年"
                    items={[
                        ['年份 (c_deathyear)', fields['死亡年']],
                        ['年號 (c_dy_nh_code)', fields['死亡年號']],
                        ['年號年 (c_dy_nh_year)', fields['死亡年號年']],
                        ['範圍 (c_dy_range)', fields['死亡年範圍']],
                        ['閏月 (c_dy_intercalary)', fields['死亡閏月']],
                        ['月份 (c_dy_month)', fields['死亡月']],
                        ['日期 (c_dy_day)', fields['死亡日']],
                        ['日干支 (c_dy_day_gz)', fields['死亡日時干支']],
                    ]}
                />
            </div>
            <div style={compactGridStyle}>
                <ReadOnlyField label="享年 (c_death_age)" value={fields['享年']} />
                <ReadOnlyField label="享年範圍 (c_death_age_range)" value={fields['享年範圍']} />
            </div>
        </>
    );
}

function renderPropertySection(section: Section) {
    const fields = fieldMap(section);
    const mergedFields = [
        { label: '性別 (c_female)', value: fields['性別'] },
        { label: '朝代 (c_dynasty_chn / c_dynasty)', value: joinDisplayValues(fields['朝代（中文）'], fields['朝代（英文）']) },
        { label: '族裔 (c_ethnicity_chn / c_ethnicity)', value: joinDisplayValues(fields['族裔（中文）'], fields['族裔（英文）']) },
        { label: '郡望 (c_choronym_chn / c_choronym)', value: joinDisplayValues(fields['郡望（中文）'], fields['郡望（英文）']) },
        { label: '戶籍 (c_household_status_code)', value: joinDisplayValues(fields['戶籍（中文）'], fields['戶籍（英文）']) },
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
            <div style={indexSectionStackStyle}>
                <div style={indexYearRowStyle}>
                    <ReadOnlyField label="Index Year (c_index_year)" value={fields['Index Year']} derived />
                    <ReadOnlyField label="Index Year Type (c_index_year_type_code)" value={indexYearType} derived />
                    <ReadOnlyField label="Index Year Source (c_index_year_source_id)" value={fields['Index Year Source']} derived />
                </div>
                <div style={indexAddressRowStyle}>
                    <ReadOnlyField label="Index Address (c_index_addr_id)" value={indexAddress} derived />
                    <ReadOnlyField label="Index Address Type (c_index_addr_type_code)" value={fields['Index Address Type']} derived />
                </div>
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
                        ['公元年份 (c_fl_earliest_year)', fields['在世始年']],
                        ['年號 (c_fl_ey_nh_code)', fields['在世始年號']],
                        ['年號年 (c_fl_ey_nh_year)', fields['在世始年號年']],
                    ]}
                    note={fields['在世始年註']}
                />
                <TimelineCard
                    title="在世終年 (c_fl_latest_year)"
                    items={[
                        ['公元年份 (c_fl_latest_year)', fields['在世終年']],
                        ['年號 (c_fl_ly_nh_code)', fields['在世終年號']],
                        ['年號年 (c_fl_ly_nh_year)', fields['在世終年號年']],
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
            <div style={notesLabelStyle}>備註 (c_notes)</div>
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
                {note !== undefined ? <ReadOnlyField label={`${title.includes('始') ? '備註 (c_fl_ey_notes)' : '備註 (c_fl_ly_notes)'}`} value={note} fullWidth subtle /> : null}
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
    derived = false,
}: {
    label: string;
    value: FieldValue;
    fullWidth?: boolean;
    muted?: boolean;
    subtle?: boolean;
    emphasis?: boolean;
    derived?: boolean;
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
                    ...(derived ? derivedValueBoxStyle : {}),
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

const nameGroupGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
    gap: 16,
};

const nameGroupCardStyle: React.CSSProperties = {
    padding: 0,
};

const nameGroupTitleStyle: React.CSSProperties = {
    fontSize: '0.8rem',
    fontWeight: 700,
    color: '#48627d',
    marginBottom: 12,
    textAlign: 'center',
};

const stackedFieldGroupStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 12,
};

const compactGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
    gap: 14,
};

const indexSectionStackStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 14,
};

const indexYearRowStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(3, minmax(0, 1fr))',
    gap: 14,
};

const indexAddressRowStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(2, minmax(0, 1fr))',
    gap: 14,
};

const timelineGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))',
    gap: 16,
    marginBottom: 14,
};

const timelineCardStyle: React.CSSProperties = {
    padding: 0,
};

const timelineTitleStyle: React.CSSProperties = {
    fontSize: '0.9rem',
    fontWeight: 700,
    color: '#35516b',
    marginBottom: 12,
    textAlign: 'center',
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
    textAlign: 'left',
};

const fieldValueBoxStyle: React.CSSProperties = {
    minHeight: 42,
    padding: '0 12px',
    borderRadius: 8,
    border: '1px solid #cfd7e2',
    backgroundColor: '#fff',
    color: '#1f2d3d',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    textAlign: 'center',
    lineHeight: 1.35,
    wordBreak: 'break-word',
    boxShadow: 'inset 0 1px 2px rgba(15, 23, 42, 0.04)',
};

const mutedValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#f8fafc',
    borderColor: '#d5dee8',
};

const subtleValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#fffdf7',
    borderColor: '#ddd3aa',
};

const emphasisValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#f1f6fb',
    borderColor: '#c7d8ea',
    fontSize: '1rem',
    fontWeight: 700,
};

const derivedValueBoxStyle: React.CSSProperties = {
    backgroundColor: '#f4f8fb',
    borderColor: '#bfcfdf',
    color: '#18344d',
    fontWeight: 700,
};

const notesBoxStyle: React.CSSProperties = {
    padding: '14px 16px',
    borderRadius: 8,
    border: '1px solid #cfd7e2',
    backgroundColor: '#fff',
    color: '#2a3642',
    lineHeight: 1.7,
    whiteSpace: 'pre-wrap',
    wordBreak: 'break-word',
    boxShadow: 'inset 0 1px 2px rgba(15, 23, 42, 0.04)',
};

const notesLabelStyle: React.CSSProperties = {
    fontSize: '0.77rem',
    fontWeight: 700,
    color: '#667788',
    marginBottom: 6,
    textAlign: 'left',
};

const emptyStyle: React.CSSProperties = {
    padding: 24,
    textAlign: 'center',
    color: '#6c757d',
};
