import React from 'react';
import { formatBilingualLabel } from './formatters';

interface Props {
    labelChn: string | null;
    labelEng: string | null;
    latitude?: number | null;
    longitude?: number | null;
    /** 當前人物 c_personid（chgis 浮出地圖以此抓該人物所有點位）。 */
    personId?: number | null;
    /** chgis 點位 key（addr:{id}:{type}:{seq} / office:{...}）；用於在地圖上高亮當前點，缺省則不高亮。 */
    mapKey?: string | null;
    // adminCatCode/adminCatLabel/year/mapId 已不需要：浮出地圖由 chgis-map app 自行抓點位渲染。
    adminCatCode?: number | null;
    adminCatLabel?: string | null;
    year?: number | null;
    mapId?: string | null;
}

/**
 * 地址呈現（對齊 legacy biogmains/_place_link.blade.php）：有座標且有 personId →
 * 地名本身為 .chgis-place-link 虛線下劃連結，點擊由 chgis-map app（已於 inertia 根模板載入）
 * 委派處理，浮出以 chgis_map.mbtiles 為底圖的無邊框地圖；否則為純文字。
 */
export default function AddressDisplayWithMap({
    labelChn,
    labelEng,
    latitude = null,
    longitude = null,
    personId = null,
    mapKey = null,
}: Props) {
    const label = formatBilingualLabel(labelChn, labelEng);

    if (!label) {
        return null;
    }

    if (latitude !== null && longitude !== null && personId != null) {
        return (
            <a
                className="chgis-place-link cbdb-historical-text"
                role="button"
                tabIndex={0}
                data-person-id={personId}
                data-key={mapKey ?? ''}
                data-lon={longitude}
                data-lat={latitude}
                title="點擊在地圖上查看"
            >
                {label}
            </a>
        );
    }

    return <span className="cbdb-historical-text">{label}</span>;
}
