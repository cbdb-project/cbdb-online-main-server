<?php

/*
|--------------------------------------------------------------------------
| CHGIS 地圖設定
|--------------------------------------------------------------------------
|
| 控制 Place Name 連結的有效座標判定、底圖（chgis_map.mbtiles）來源與
| 下載行為。詳見 docs/CHGIS_MAP_PLACE_LINK.md。
|
*/

return [
    // mbtiles 底圖的 WGS84 範圍（取自 mbtiles metadata 的 bounds: W,S,E,N）。
    // 座標需落在此範圍內才視為可連結。
    'bounds' => [
        'west' => (float) env('CHGIS_MAP_WEST', 58.5372),
        'south' => (float) env('CHGIS_MAP_SOUTH', -62.6348),
        'east' => (float) env('CHGIS_MAP_EAST', 152.24),
        'north' => (float) env('CHGIS_MAP_NORTH', 82.7288),
    ],

    // 合理東亞收斂框：mbtiles bounds 南界（-62）明顯異常，啟用此框可過濾雜訊點。
    // 有效座標需同時落在 bounds 與（啟用時）sane_bounds 內。
    'sane_bounds' => [
        'enabled' => (bool) env('CHGIS_MAP_SANE_ENABLED', true),
        'west' => (float) env('CHGIS_MAP_SANE_WEST', 70.0),
        'south' => (float) env('CHGIS_MAP_SANE_SOUTH', 15.0),
        'east' => (float) env('CHGIS_MAP_SANE_EAST', 140.0),
        'north' => (float) env('CHGIS_MAP_SANE_NORTH', 55.0),
    ],

    // 視為「等於 0」的容差，用於排除 0,0 與單軸為 0 的座標。
    'epsilon' => (float) env('CHGIS_MAP_EPSILON', 1e-7),

    // Web Mercator（EPSG:3857）可投影的緯度上限。
    'mercator_lat_limit' => 85.0511,

    'min_zoom' => (int) env('CHGIS_MAP_MIN_ZOOM', 3),
    'max_zoom' => (int) env('CHGIS_MAP_MAX_ZOOM', 8),

    // 底圖檔案來源與本地存放位置。
    'source' => [
        // HuggingFace dataset 的 resolve raw URL（公開 dataset，無需 token）。
        'url' => env('CHGIS_MAP_URL', 'https://huggingface.co/datasets/cbdb/chgis-map/resolve/main/chgis_map.mbtiles'),
        // 本地存放路徑（private disk，非 public）。
        'path' => env('CHGIS_MAP_PATH', storage_path('app/chgis/chgis_map.mbtiles')),
        // 體積下限（位元組），低於此值視為半截/失敗檔。
        'expected_min_bytes' => (int) env('CHGIS_MAP_MIN_BYTES', 5_000_000),
    ],
];
