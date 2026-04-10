import React from 'react';
import { formatBilingualLabel } from './formatters';
import MapPreviewTrigger from './MapPreviewTrigger';

interface Props {
    labelChn: string | null;
    labelEng: string | null;
    adminCatCode?: number | null;
    adminCatLabel?: string | null;
    latitude?: number | null;
    longitude?: number | null;
    year?: number | null;
    mapId?: string | null;
}

export default function AddressDisplayWithMap({
    labelChn,
    labelEng,
    adminCatCode = null,
    adminCatLabel = null,
    latitude = null,
    longitude = null,
    year = null,
    mapId = null,
}: Props) {
    const label = formatBilingualLabel(labelChn, labelEng);

    if (!label) {
        return null;
    }

    return (
        <span style={wrapStyle}>
            <span>{label}</span>
            {latitude !== null && longitude !== null ? (
                <MapPreviewTrigger
                    latitude={latitude}
                    longitude={longitude}
                    label={labelChn || labelEng || '地點'}
                    adminCatCode={adminCatCode}
                    adminCatLabel={adminCatLabel}
                    year={year}
                    mapId={mapId}
                />
            ) : null}
        </span>
    );
}

const wrapStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    flexWrap: 'nowrap',
    gap: 4,
    lineHeight: 1,
};
