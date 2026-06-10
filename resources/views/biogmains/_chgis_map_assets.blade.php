{{--
    CHGIS 地圖前端資源與設定注入
    由 addresses/offices 列表頁 @include。注入 window.chgisMapConfig（路由、範圍、i18n），
    並透過 @vite 載入 chgis-map 入口（含 Leaflet 與 modal 樣式）。
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
@endphp
@push('scripts')
    <script>
        window.chgisMapConfig = {
            statusUrl: {!! Js::from(route('chgis-map.status', [], false)) !!},
            tileUrlTemplate: {!! Js::from($chgisTileTemplate) !!},
            pointsUrlBase: {!! Js::from(route('basicinformation.index', [], false)) !!},
            minZoom: {{ (int) config('chgis_map.min_zoom', 3) }},
            maxZoom: {{ (int) config('chgis_map.max_zoom', 8) }},
            bounds: {!! Js::from(config('chgis_map.bounds')) !!},
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
