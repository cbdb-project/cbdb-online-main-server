(function () {
    const historicalMaps = [
        { id: 'Tang_Admin', title: '唐代各道、州界', maxNativeZoom: 12 },
        { id: 'bc0210', title: '秦代歷史地圖 (Qin)', yearRange: [-221, -206], maxNativeZoom: 12 },
        { id: 'bc0007', title: '西漢歷史地圖 (W. Han)', yearRange: [-206, 8], maxNativeZoom: 12 },
        { id: 'ad0140', title: '東漢歷史地圖 (E. Han)', yearRange: [25, 220], maxNativeZoom: 12 },
        { id: 'ad0262', title: '三國歷史地圖 (Sanguo)', yearRange: [220, 280], maxNativeZoom: 12 },
        { id: 'ad0281', title: '西晉歷史地圖 (W. Jing)', yearRange: [265, 316], maxNativeZoom: 12 },
        { id: 'ad0382', title: '東晉歷史地圖 (E. Jing)', yearRange: [317, 420], maxNativeZoom: 12 },
        { id: 'ad0497', title: '南北朝歷史地圖 (South and North)', yearRange: [420, 589], maxNativeZoom: 12 },
        { id: 'ad0612', title: '隋代歷史地圖 (Sui)', yearRange: [581, 618], maxNativeZoom: 12 },
        { id: 'ad0741', title: '唐代歷史地圖 (Tang)', yearRange: [618, 907], maxNativeZoom: 12 },
        { id: 'ad1111', title: '北宋歷史地圖 (N. Song)', yearRange: [960, 1127], maxNativeZoom: 12 },
        { id: 'ad1208', title: '南宋歷史地圖 (S. Song)', yearRange: [1127, 1279], maxNativeZoom: 12 },
        { id: 'ad1330', title: '元代歷史地圖 (Yuan)', yearRange: [1271, 1368], maxNativeZoom: 12 },
        { id: 'ad1582', title: '明代歷史地圖 (Ming)', yearRange: [1368, 1644], maxNativeZoom: 12 },
        { id: 'ad1582_10_2s', title: '讀史方輿紀要地名', yearRange: [1500, 1700], maxNativeZoom: 12 },
        { id: 'ad1820', title: '清代歷史地圖 (Qing)', yearRange: [1644, 1912], maxNativeZoom: 12 }
    ];

    const historicalMapsById = new Map(
        historicalMaps.map(function (item) {
            return [item.id, item];
        })
    );

    const tangBounds = L.latLngBounds(
        [17.13, 66.85],
        [61.4, 146.7]
    );

    const map = L.map('map', {
        zoomControl: true,
        attributionControl: true
    });

    map.attributionControl.setPrefix(false);

    const openStreetMapLayer = L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }
    );

    const cartoPositronLayer = L.tileLayer(
        'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
        {
            maxZoom: 20,
            attribution: '&copy; OpenStreetMap contributors &copy; CARTO'
        }
    );

    const esriGrayLayer = L.tileLayer(
        'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}',
        {
            maxZoom: 16,
            attribution: 'Tiles &copy; Esri'
        }
    );

    let activeBaseLayer = cartoPositronLayer.addTo(map);
    let activeHistoricalLayer = null;
    let activeHistoricalConfig = null;

    const baseLayers = {
        'CartoDB Positron': cartoPositronLayer,
        'Esri Gray Canvas': esriGrayLayer,
        'OSM Standard': openStreetMapLayer
    };

    L.control.layers(
        baseLayers,
        {},
        {
            collapsed: false
        }
    ).addTo(map);

    const markers = L.layerGroup().addTo(map);

    const form = document.getElementById('marker-form');
    const latInput = document.getElementById('lat-input');
    const lngInput = document.getElementById('lng-input');
    const labelInput = document.getElementById('label-input');
    const historicalLayerSelect = document.getElementById('historical-layer-select');
    const status = document.getElementById('status');
    const osmOpacityInput = document.getElementById('osm-opacity');
    const osmOpacityValue = document.getElementById('osm-opacity-value');
    const panel = document.getElementById('control-panel');
    const panelToggle = document.getElementById('panel-toggle');
    const resetViewButton = document.getElementById('reset-view');
    const clearMarkersButton = document.getElementById('clear-markers');
    const query = new URLSearchParams(window.location.search);

    function setStatus(message) {
        status.textContent = message;
    }

    function syncPanelToggle() {
        const isOpen = panel.classList.contains('is-open');
        panelToggle.setAttribute('aria-expanded', String(isOpen));
        panelToggle.setAttribute('aria-label', isOpen ? '收起控制面板' : '展開控制面板');
    }

    function resetView() {
        map.fitBounds(tangBounds, {
            padding: [20, 20]
        });
    }

    function setOsmOpacity(value) {
        const opacity = Math.min(1, Math.max(0, value / 100));
        activeBaseLayer.setOpacity(opacity);
        osmOpacityValue.textContent = Math.round(opacity * 100) + '%';
    }

    function buildHistoricalLayer(mapConfig) {
        const options = {
            minZoom: 0,
            maxZoom: 20,
            attribution: '底圖來源：中央研究院人社中心 GIS 專題中心'
        };

        if (typeof mapConfig.maxNativeZoom === 'number') {
            options.maxNativeZoom = mapConfig.maxNativeZoom;
        }

        return L.tileLayer(
            'https://gis.sinica.edu.tw/ccts/file-exists.php?img=' + mapConfig.id + '-png-{z}-{x}-{y}',
            options
        );
    }

    function setHistoricalLayer(mapId, reason) {
        const nextConfig = historicalMapsById.get(mapId) || historicalMaps[0];

        if (activeHistoricalConfig && activeHistoricalConfig.id === nextConfig.id) {
            historicalLayerSelect.value = nextConfig.id;
            return;
        }

        if (activeHistoricalLayer) {
            map.removeLayer(activeHistoricalLayer);
        }

        activeHistoricalConfig = nextConfig;
        activeHistoricalLayer = buildHistoricalLayer(nextConfig).addTo(map);
        historicalLayerSelect.value = nextConfig.id;

        activeHistoricalLayer.on('tileerror', function () {
            setStatus('歷史地圖圖磚載入失敗：' + nextConfig.title);
        });

        if (reason) {
            setStatus(reason + '：' + nextConfig.title);
        }
    }

    function findHistoricalMapByYear(year) {
        const matched = historicalMaps.find(function (item) {
            if (!item.yearRange) {
                return false;
            }

            return year >= item.yearRange[0] && year <= item.yearRange[1];
        });

        if (matched) {
            return matched;
        }

        let closest = historicalMaps[0];
        let closestDistance = Number.POSITIVE_INFINITY;

        historicalMaps.forEach(function (item) {
            if (!item.yearRange) {
                return;
            }

            const distance = Math.min(
                Math.abs(year - item.yearRange[0]),
                Math.abs(year - item.yearRange[1])
            );

            if (distance < closestDistance) {
                closestDistance = distance;
                closest = item;
            }
        });

        return closest;
    }

    function populateHistoricalLayerSelect() {
        historicalMaps.forEach(function (item) {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.title + ' [' + item.id + ']';
            historicalLayerSelect.appendChild(option);
        });
    }

    function addMarker(lat, lng, label) {
        const marker = L.marker([lat, lng]).addTo(markers);
        const popupLabel = label && label.trim() ? label.trim() : '未命名地點';

        marker.bindPopup(
            '<strong>' + escapeHtml(popupLabel) + '</strong><br>' +
            '緯度：' + lat.toFixed(6) + '<br>' +
            '經度：' + lng.toFixed(6)
        );

        marker.openPopup();
        map.setView([lat, lng], Math.max(map.getZoom(), 6), {
            animate: true
        });

        setStatus('已加入 marker：' + popupLabel + ' (' + lat.toFixed(4) + ', ' + lng.toFixed(4) + ')');
    }

    function parseQueryMarker() {
        const lat = Number.parseFloat(query.get('lat') || '');
        const lng = Number.parseFloat(query.get('lng') || '');
        const label = query.get('label') || '';

        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return null;
        }

        return {
            lat: lat,
            lng: lng,
            label: label
        };
    }

    function parseHistoricalSelectionFromQuery() {
        const mapId = query.get('map') || query.get('layer');

        if (mapId && historicalMapsById.has(mapId)) {
            return {
                mapId: mapId,
                reason: '已依 map 參數切換歷史地圖'
            };
        }

        const yearValue = query.get('year');
        const year = Number.parseInt(yearValue || '', 10);

        if (Number.isFinite(year)) {
            const inferred = findHistoricalMapByYear(year);

            return {
                mapId: inferred.id,
                reason: '已依 year=' + year + ' 推定歷史地圖'
            };
        }

        return {
            mapId: historicalMaps[0].id,
            reason: ''
        };
    }

    function escapeHtml(value) {
        return value
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        const lat = Number.parseFloat(latInput.value);
        const lng = Number.parseFloat(lngInput.value);
        const label = labelInput.value;

        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            setStatus('請輸入有效的經緯度。');
            return;
        }

        addMarker(lat, lng, label);
    });

    historicalLayerSelect.addEventListener('change', function () {
        setHistoricalLayer(historicalLayerSelect.value, '已切換歷史地圖');
    });

    resetViewButton.addEventListener('click', function () {
        resetView();
        setStatus('已重設到歷史地圖圖層範圍。');
    });

    clearMarkersButton.addEventListener('click', function () {
        markers.clearLayers();
        setStatus('已清除所有 marker。');
    });

    osmOpacityInput.addEventListener('input', function () {
        setOsmOpacity(Number.parseInt(osmOpacityInput.value, 10));
    });

    panelToggle.addEventListener('click', function () {
        panel.classList.toggle('is-open');
        syncPanelToggle();
    });

    map.on('baselayerchange', function (event) {
        activeBaseLayer = event.layer;
        setOsmOpacity(Number.parseInt(osmOpacityInput.value, 10));
    });

    populateHistoricalLayerSelect();
    syncPanelToggle();
    setOsmOpacity(Number.parseInt(osmOpacityInput.value, 10));
    const initialHistoricalSelection = parseHistoricalSelectionFromQuery();
    setHistoricalLayer(initialHistoricalSelection.mapId, initialHistoricalSelection.reason);
    resetView();

    const queryMarker = parseQueryMarker();

    if (queryMarker) {
        latInput.value = String(queryMarker.lat);
        lngInput.value = String(queryMarker.lng);
        labelInput.value = queryMarker.label;
        addMarker(queryMarker.lat, queryMarker.lng, queryMarker.label);
    } else {
        addMarker(34.3416, 108.9398, '長安');
    }
})();
