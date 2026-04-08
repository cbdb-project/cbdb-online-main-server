import React from 'react';
import { formatBilingualLabel } from './formatters';
import MapPreviewTrigger from './MapPreviewTrigger';

interface Props {
    labelChn: string | null;
    labelEng: string | null;
    latitude?: number | null;
    longitude?: number | null;
    year?: number | null;
    mapId?: string | null;
}

export default function AddressDisplayWithMap({
    labelChn,
    labelEng,
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
    flexWrap: 'wrap',
    gap: 4,
};
