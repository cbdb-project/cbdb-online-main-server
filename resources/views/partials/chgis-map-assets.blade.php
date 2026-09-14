{{--
    CHGIS 地圖前端資源與設定注入
    注入 window.chgisMapConfig（路由、範圍、i18n），並透過 @vite 載入 chgis-map 入口
    （含 Leaflet 與 modal 樣式）。

    ⚠️ 這個 partial 被 resources/views/inertia.blade.php @include，也就是**每一個 React 頁
    都會執行它**——刪除或改壞它會讓全站 Inertia 頁 500。它原本放在 biogmains/ 底下
    （_chgis_map_assets.blade.php），Blade 下架計畫環節 2 刪除該目錄前先搬到這裡，避免被
    當成 legacy 檔一併刪掉。

    必須在 inertia.blade.php 的 @stack('scripts') **之前** @include（本檔用 @push('scripts')）。

    AGENTS.md：AJAX URL 使用相對路徑 route(name, [], false)，避免 HTTPS mixed content。
--}}
@php
    // 以相對路徑（route(name, [], false)）產生 URL，避免 HTTPS mixed content（AGENTS.md §5），
    // 並相容子目錄部署。tile 模板的 {z}/{x}/{y} 佔位符在 route 產生後再注入，避免被 URL 編碼。
    $chgisTileTemplate = str_replace(
        ['__Z__', '__X__', '__Y__'],
        ['{z}', '{x}', '{y}'],
        route('chgis-map.tile', ['z' => '__Z__', 'x' => '__X__', 'y' => '__Y__'], false)
    );

    // 加底圖版本號做 cache-busting：以 mbtiles mtime 當版本（與 tile ETag 同源）。
    // 底圖更新後版本號改變 → tile URL 改變（全新快取鍵），繞過瀏覽器既有的舊磚快取，
    // 立即取得新磚；no-cache 僅能擋未來快取，無法淘汰使用者已存的舊磚，故另以 URL 版本根治。
    $chgisManager = app(\App\Services\ChgisMapManager::class);
    $chgisTileVersion = $chgisManager->isReady() ? (@filemtime($chgisManager->path()) ?: 0) : 0;
    $chgisTileTemplate .= '?v=' . $chgisTileVersion;

    // 人物地圖點 URL 模板。原本這裡傳的是 basicinformation.index 的 URL 當「base」，前端再自行
    // 接上 /{id}/map-points——那讓每一個 React 頁都相依於一條 **legacy 路由名**，該路由在環節 2
    // 會被改成導向，名字一旦動到就是全站 500。改為直接產生 map-points 自己的 URL 模板。
    $chgisPointsTemplate = str_replace(
        '__ID__',
        '{id}',
        route('basicinformation.map-points', ['id' => '__ID__'], false)
    );
@endphp
@push('scripts')
    <script>
        window.chgisMapConfig = {
            statusUrl: {!! Js::from(route('chgis-map.status', [], false)) !!},
            tileUrlTemplate: {!! Js::from($chgisTileTemplate) !!},
            pointsUrlTemplate: {!! Js::from($chgisPointsTemplate) !!},
            minZoom: {{ (int) config('chgis_map.min_zoom', 3) }},
            maxZoom: {{ (int) config('chgis_map.max_zoom', 8) }},
            bounds: {!! Js::from(config('chgis_map.bounds')) !!},
            displayBounds: {!! Js::from(config('chgis_map.display_bounds')) !!},
            i18n: {!! Js::from([
                'modal_title' => __('chgis_map.modal_title'),
                'current_location' => __('chgis_map.current_location'),
                'biographical_addresses' => __('chgis_map.biographical_addresses'),
                'office_locations' => __('chgis_map.office_locations'),
                'close' => __('chgis_map.close'),
                'loading' => __('chgis_map.loading'),
                'downloading_basemap' => __('chgis_map.downloading_basemap'),
                'download_failed' => __('chgis_map.download_failed'),
                'retry' => __('chgis_map.retry'),
                'no_points' => __('chgis_map.no_points'),
                'load_error' => __('chgis_map.load_error'),
                'unknown_place' => __('chgis_map.unknown_place'),
                'type_address' => __('chgis_map.type_address'),
                'type_office' => __('chgis_map.type_office'),
                'year_range' => __('chgis_map.year_range'),
                'count_unit' => __('chgis_map.count_unit'),
                'legend_count_hint' => __('chgis_map.legend_count_hint'),
                'open_record' => __('chgis_map.open_record'),
                'both_types' => __('chgis_map.both_types'),
            ]) !!},
        };
    </script>
    @vite(['resources/js/chgis-map/app.js'])
@endpush
