<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('nav.maps_page_title') }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="192x192" href="/icon-192.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin="anonymous"
    >
    @vite(['resources/js/historical-maps/app.js'])
</head>
<body>
    <button
        type="button"
        id="panel-toggle"
        class="panel-toggle"
        aria-expanded="false"
        aria-controls="control-panel"
        aria-label="{{ __('nav.maps_toggle_panel') }}"
    >
        <span class="panel-toggle-bars" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
        </span>
    </button>
    <div class="layout">
        <aside id="control-panel" class="panel">
            <h1>{{ __('nav.maps_page_title') }}</h1>
            <p class="lead">
                {{ __('nav.maps_page_desc') }}
            </p>

            <form id="marker-form" class="form">
                <label>
                    <span>{{ __('nav.maps_layer_label') }}</span>
                    <select id="historical-layer-select" name="historical-layer"></select>
                </label>

                <label>
                    <span>{{ __('nav.maps_lat_label') }}</span>
                    <input id="lat-input" name="lat" type="number" step="any" value="34.3416" required>
                </label>

                <label>
                    <span>{{ __('nav.maps_lng_label') }}</span>
                    <input id="lng-input" name="lng" type="number" step="any" value="108.9398" required>
                </label>

                <label>
                    <span>{{ __('nav.maps_marker_label') }}</span>
                    <input id="label-input" name="label" type="text" value="{{ __('nav.maps_marker_default') }}" maxlength="100">
                </label>

                <div class="actions">
                    <button type="submit">{{ __('nav.maps_add_marker') }}</button>
                    <button type="button" id="reset-view">{{ __('nav.maps_reset_view') }}</button>
                    <button type="button" id="clear-markers">{{ __('nav.maps_clear_markers') }}</button>
                </div>
            </form>

            <section class="layer-controls">
                <h2>{{ __('nav.maps_basemap_opacity') }}</h2>
                <label>
                    <span>{{ __('nav.maps_current_basemap') }}</span>
                    <input id="osm-opacity" type="range" min="0" max="100" step="5" value="50">
                </label>
                <p id="osm-opacity-value" class="slider-value">50%</p>
            </section>

            <div class="tips">
                <h2>{{ __('nav.maps_tips_title') }}</h2>
                <ul>
                    <li>{{ __('nav.maps_tip_source') }}</li>
                    <li>{{ __('nav.maps_tip_coords') }}</li>
                    <li>{!! __('nav.maps_tip_map_param') !!}</li>
                    <li>{!! __('nav.maps_tip_year_param') !!}</li>
                    <li>{!! __('nav.maps_tip_marker_param') !!}</li>
                </ul>
            </div>

            <p id="status" class="status" aria-live="polite"></p>
        </aside>

        <main class="map-shell">
            <div id="map" aria-label="{{ __('nav.maps_aria_map') }}"></div>
        </main>
    </div>

    <script
        src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin="anonymous"
    ></script>
</body>
</html>
