<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>中國歷代行政區地圖</title>
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
        aria-label="切換控制面板"
    >
        <span class="panel-toggle-bars" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
        </span>
    </button>
    <div class="layout">
        <aside id="control-panel" class="panel">
            <h1>中國歷代行政區地圖</h1>
            <p class="lead">
                使用 Sinica CCTS 歷史地圖服務，支援切換歷代行政區圖層，並可在指定座標加上 marker。
            </p>

            <form id="marker-form" class="form">
                <label>
                    <span>歷史地圖圖層</span>
                    <select id="historical-layer-select" name="historical-layer"></select>
                </label>

                <label>
                    <span>緯度 Latitude</span>
                    <input id="lat-input" name="lat" type="number" step="any" value="34.3416" required>
                </label>

                <label>
                    <span>經度 Longitude</span>
                    <input id="lng-input" name="lng" type="number" step="any" value="108.9398" required>
                </label>

                <label>
                    <span>標題</span>
                    <input id="label-input" name="label" type="text" value="長安" maxlength="100">
                </label>

                <div class="actions">
                    <button type="submit">加入 Marker</button>
                    <button type="button" id="reset-view">重設視角</button>
                    <button type="button" id="clear-markers">清除 Marker</button>
                </div>
            </form>

            <section class="layer-controls">
                <h2>現代底圖透明度</h2>
                <label>
                    <span>目前選取的現代底圖</span>
                    <input id="osm-opacity" type="range" min="0" max="100" step="5" value="50">
                </label>
                <p id="osm-opacity-value" class="slider-value">50%</p>
            </section>

            <div class="tips">
                <h2>說明</h2>
                <ul>
                    <li>底圖來源：Sinica CCTS 歷史地圖 WMTS / tile 服務。</li>
                    <li>輸入 WGS84 經緯度即可加點。</li>
                    <li>可用 <code>map</code> 指定圖層，例如 <code>?map=ad0741</code>。</li>
                    <li>也可用 <code>year</code> 推定圖層，例如 <code>?year=741</code>。</li>
                    <li>marker 可由 query param 指定：<code>?lat=34.3416&amp;lng=108.9398&amp;label=長安</code>。</li>
                </ul>
            </div>

            <p id="status" class="status" aria-live="polite"></p>
        </aside>

        <main class="map-shell">
            <div id="map" aria-label="歷史行政區地圖"></div>
        </main>
    </div>

    <script
        src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin="anonymous"
    ></script>
</body>
</html>
