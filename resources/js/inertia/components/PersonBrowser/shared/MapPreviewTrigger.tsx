import React, { useMemo, useState } from 'react';
import SelectionDialog from '../../../components/SelectionDialog';
import { APP_THEME } from '../../../theme';

interface Props {
    latitude: number;
    longitude: number;
    label: string;
    adminCatCode?: number | null;
    adminCatLabel?: string | null;
    year?: number | null;
    mapId?: string | null;
    buttonLabel?: string;
}

export default function MapPreviewTrigger({
    latitude,
    longitude,
    label,
    adminCatCode = null,
    adminCatLabel = null,
    year = null,
    mapId = null,
    buttonLabel = '地圖',
}: Props) {
    const [open, setOpen] = useState(false);
    const mapUrl = useMemo(
        () => buildMapUrl({ latitude, longitude, label, year, mapId }),
        [label, latitude, longitude, mapId, year],
    );
    const dialogTitle = buildDialogTitle(label, adminCatCode, adminCatLabel, year);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                style={buttonStyle}
                title="點這裡看地圖"
                aria-label={`查看 ${label} 的地圖`}
            >
                <MapIcon />
                <span>{buttonLabel}</span>
            </button>

            <SelectionDialog
                isOpen={open}
                title={dialogTitle}
                width={1180}
                onClose={() => setOpen(false)}
                footer={(
                    <>
                        <a href={mapUrl} target="_blank" rel="noreferrer" style={secondaryLinkStyle}>
                            在新視窗開啟
                        </a>
                        <button type="button" onClick={() => setOpen(false)} style={closeButtonStyle}>
                            關閉
                        </button>
                    </>
                )}
            >
                <div style={frameWrapStyle}>
                    <iframe
                        src={mapUrl}
                        title={`${dialogTitle} 地圖`}
                        style={iframeStyle}
                    />
                </div>
            </SelectionDialog>
        </>
    );
}

function buildDialogTitle(label: string, adminCatCode: number | null, adminCatLabel: string | null, year: number | null) {
    const parts = [label];

    parts.push(`行政類別: ${formatAdminCat(adminCatCode, adminCatLabel)}`);
    parts.push(`年份: ${year ?? '—'}`);

    return parts.join(' / ');
}

function formatAdminCat(adminCatCode: number | null, adminCatLabel: string | null) {
    if (adminCatLabel && adminCatCode !== null) {
        return extractPrimaryAdminCatLabel(adminCatLabel);
    }

    if (adminCatLabel) {
        return extractPrimaryAdminCatLabel(adminCatLabel);
    }

    if (adminCatCode !== null) {
        return String(adminCatCode);
    }

    return '—';
}

function extractPrimaryAdminCatLabel(adminCatLabel: string) {
    return adminCatLabel
        .split(' / ')
        .map((part) => part.trim())
        .find((part) => part !== '') || adminCatLabel;
}

function buildMapUrl({
    latitude,
    longitude,
    label,
    year,
    mapId,
}: {
    latitude: number;
    longitude: number;
    label: string;
    year?: number | null;
    mapId?: string | null;
}) {
    const query = new URLSearchParams({
        lat: String(latitude),
        lng: String(longitude),
        label,
    });

    if (typeof year === 'number' && Number.isFinite(year)) {
        query.set('year', String(year));
    }

    if (mapId) {
        query.set('map', mapId);
    }

    return `/app/maps?${query.toString()}`;
}

function MapIcon() {
    return (
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style={iconStyle}>
            <path
                d="M3 6.5 8.5 4l7 2.5L21 4v13.5L15.5 20l-7-2.5L3 20V6.5Zm6.5-.62v10.99l5 1.79V7.67l-5-1.79Zm-5 .62v10.42l3-.97V5.53l-3 .97Zm15-.97-3 .97v10.42l3-.97V5.53Z"
                fill="currentColor"
            />
        </svg>
    );
}

const buttonStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: 4,
    padding: 0,
    border: 'none',
    backgroundColor: 'transparent',
    color: APP_THEME.brandText,
    cursor: 'pointer',
    fontSize: 'inherit',
    fontFamily: 'inherit',
    fontWeight: 600,
    lineHeight: 1,
    whiteSpace: 'nowrap',
    appearance: 'none',
    WebkitAppearance: 'none',
};

const iconStyle: React.CSSProperties = {
    display: 'block',
    width: 13,
    height: 13,
};

const frameWrapStyle: React.CSSProperties = {
    backgroundColor: '#e2e8f0',
    borderRadius: 4,
    overflow: 'hidden',
};

const iframeStyle: React.CSSProperties = {
    width: '100%',
    height: 'min(72vh, 760px)',
    border: 'none',
    display: 'block',
    backgroundColor: '#fff',
};

const secondaryLinkStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    height: 36,
    padding: '0 14px',
    borderRadius: 4,
    border: '1px solid #d0d8e2',
    backgroundColor: '#fff',
    color: '#334155',
    textDecoration: 'none',
    fontSize: '0.9rem',
};

const closeButtonStyle: React.CSSProperties = {
    height: 36,
    padding: '0 14px',
    borderRadius: 4,
    border: `1px solid ${APP_THEME.brand}`,
    backgroundColor: APP_THEME.brand,
    color: '#fff',
    cursor: 'pointer',
    fontSize: '0.9rem',
};
