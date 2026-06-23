/**
 * CHGIS 地圖 modal（addresses/offices 列表頁 Place Name 連結）
 *
 * 點擊 .chgis-place-link → 浮出以 chgis_map.mbtiles 為底圖的 Leaflet 地圖，
 * 標出該人物所有有效地點，當前點置中並以脈動標記突顯。
 * 設定與字串由後端注入 window.chgisMapConfig（見 _chgis_map_assets.blade.php）。
 *
 * 設計見 docs/CHGIS_MAP_PLACE_LINK.md §6。
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './style.css';

const cfg = () => window.chgisMapConfig || {};
const t = (key) => (cfg().i18n && cfg().i18n[key]) || key;
const reducedMotion = () =>
    window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let overlayEl = null;
let mapInstance = null;
let legendControl = null;
let pollTimer = null;
let lastActiveElement = null;
let savedOverflow = '';
let pointerdownOnOverlay = false;
let lastTabDirectionBackward = false;
let basemapRequestController = null;
let pointsRequestController = null;
// 開啟世代：每次開啟/關閉遞增，非同步 callback 以此辨識自己是否已過期
let openToken = 0;

/** 建立 modal DOM（僅一次）。 */
function buildOverlay() {
    const overlay = document.createElement('div');
    overlay.className = 'chgis-modal-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', t('modal_title'));
    overlay.innerHTML = `
        <div class="chgis-modal" tabindex="-1">
            <button type="button" class="chgis-modal__close" aria-label="${escapeHtml(t('close'))}">&times;</button>
            <div class="chgis-modal__map"></div>
            <div class="chgis-modal__status" role="status" aria-live="polite" aria-atomic="true" hidden>
                <div class="chgis-spinner" aria-hidden="true"></div>
                <div class="chgis-status__message"></div>
                <button type="button" class="chgis-status__retry" hidden></button>
            </div>
        </div>`;

    // 僅當 pointer 起點與終點都落在遮罩本體（非地圖）才關閉，避免拖曳鬆手誤關
    overlay.addEventListener('pointerdown', (e) => {
        pointerdownOnOverlay = e.target === overlay;
    });
    overlay.addEventListener('pointerup', (e) => {
        if (e.target === overlay && pointerdownOnOverlay) {
            closeModal();
        }
        pointerdownOnOverlay = false;
    });
    overlay.querySelector('.chgis-modal__close').addEventListener('click', closeModal);
    // modal 內 Tab focus trap
    overlay.addEventListener('keydown', onTrapKeydown);
    document.addEventListener('focusin', onDocumentFocusIn, true);

    document.body.appendChild(overlay);
    return overlay;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

function sanitizePopupUrl(url) {
    const raw = url == null ? '' : String(url).trim();
    if (raw === '') {
        return '';
    }

    try {
        const parsed = new URL(raw, window.location.origin);
        const isSafeProtocol = parsed.protocol === 'http:' || parsed.protocol === 'https:';
        if (!isSafeProtocol) {
            return '';
        }

        return parsed.origin === window.location.origin
            ? `${parsed.pathname}${parsed.search}${parsed.hash}`
            : parsed.toString();
    } catch (_error) {
        return '';
    }
}

function focusableEls() {
    if (!overlayEl) {
        return [];
    }
    return Array.from(
        overlayEl.querySelectorAll(
            'button:not([hidden]), a[href], [tabindex]:not([tabindex="-1"])'
        )
    ).filter((el) => el.offsetParent !== null && !el.disabled);
}

function onTrapKeydown(e) {
    lastTabDirectionBackward = e.shiftKey;
    if (e.key !== 'Tab') {
        return;
    }
    const els = focusableEls();
    if (!els.length) {
        e.preventDefault();
        return;
    }
    if (!overlayEl.contains(document.activeElement)) {
        e.preventDefault();
        (e.shiftKey ? els[els.length - 1] : els[0]).focus();
        return;
    }
    const first = els[0];
    const last = els[els.length - 1];
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}

function onDocumentFocusIn(e) {
    if (!overlayEl || !overlayEl.classList.contains('is-open')) {
        return;
    }
    if (overlayEl.contains(e.target)) {
        return;
    }
    const els = focusableEls();
    const fallback = els.length
        ? (lastTabDirectionBackward ? els[els.length - 1] : els[0])
        : overlayEl.querySelector('.chgis-modal');
    if (fallback) {
        fallback.focus();
    }
}

function setStatus({ message, spinner = true, retry = false }) {
    if (!overlayEl) {
        return;
    }
    const statusEl = overlayEl.querySelector('.chgis-modal__status');
    statusEl.querySelector('.chgis-status__message').textContent = message;
    statusEl.querySelector('.chgis-spinner').style.display = spinner ? '' : 'none';
    const retryBtn = statusEl.querySelector('.chgis-status__retry');
    retryBtn.hidden = !retry;
    statusEl.setAttribute('aria-busy', spinner ? 'true' : 'false');
    statusEl.hidden = false;
}

function hideStatus() {
    if (overlayEl) {
        overlayEl.querySelector('.chgis-modal__status').hidden = true;
    }
}

function resetRetry() {
    if (!overlayEl) {
        return;
    }
    const retryBtn = overlayEl.querySelector('.chgis-status__retry');
    retryBtn.onclick = null;
    retryBtn.hidden = true;
}

function cleanupMap() {
    if (mapInstance) {
        mapInstance.remove();
        mapInstance = null;
    }
    legendControl = null;
    if (overlayEl) {
        overlayEl.querySelector('.chgis-modal__map').replaceChildren();
    }
}

function abortPendingRequests() {
    if (basemapRequestController) {
        basemapRequestController.abort();
        basemapRequestController = null;
    }
    if (pointsRequestController) {
        pointsRequestController.abort();
        pointsRequestController = null;
    }
}

function isAbortError(error) {
    return error && (error.name === 'AbortError' || error.code === 20);
}

function parseJsonResponse(response) {
    if (!response.ok) {
        return response
            .json()
            .catch(() => null)
            .then((body) => {
                const error = new Error('HTTP ' + response.status);
                error.status = response.status;
                error.body = body;
                throw error;
            });
    }

    return response.json();
}

function showRetryableStatus(message, onRetry) {
    setStatus({ message, spinner: false, retry: true });
    const retryBtn = overlayEl.querySelector('.chgis-status__retry');
    retryBtn.textContent = t('retry');
    retryBtn.onclick = () => {
        setStatus({ message: t('loading') });
        onRetry();
    };
}

function openModal(trigger) {
    const personId = trigger.dataset.personId;
    const currentKey = trigger.dataset.key;
    const lon = parseFloat(trigger.dataset.lon);
    const lat = parseFloat(trigger.dataset.lat);
    if (!personId) {
        return;
    }

    const token = ++openToken;
    lastActiveElement = trigger;
    if (!overlayEl) {
        overlayEl = buildOverlay();
    }

    // 重置狀態（避免沿用上一次的殘留）
    stopPolling();
    abortPendingRequests();
    cleanupMap();
    resetRetry();
    hideStatus();

    savedOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        overlayEl.classList.add('is-open');
        overlayEl.querySelector('.chgis-modal__close').focus();
    });

    const start = () => startMap(token, personId, currentKey, lon, lat);

    ensureBasemapReady(token)
        .then(() => {
            if (token === openToken) {
                start();
            }
        })
        .catch((err) => showFailure(err, start, token));
}

function showFailure(err, start, token) {
    if (token !== openToken) {
        return;
    }
    showRetryableStatus(err && err.userMessage ? err.userMessage : t('download_failed'), () => {
        if (token !== openToken) {
            return;
        }
        setStatus({ message: t('downloading_basemap') });
        ensureBasemapReady(token, true)
            .then(() => token === openToken && start())
            .catch((e) => showFailure(e, start, token));
    });
}

function closeModal() {
    if (!overlayEl) {
        return;
    }
    if (!overlayEl.classList.contains('is-open')) {
        return;
    }
    openToken++; // 使所有 pending 非同步 callback 失效
    stopPolling();
    abortPendingRequests();
    overlayEl.classList.remove('is-open');
    document.body.style.overflow = savedOverflow;
    pointerdownOnOverlay = false;

    cleanupMap();
    hideStatus();
    resetRetry();

    if (lastActiveElement && typeof lastActiveElement.focus === 'function') {
        lastActiveElement.focus();
    }
}

function stopPolling() {
    if (pollTimer) {
        window.clearTimeout(pollTimer);
        pollTimer = null;
    }
}

/**
 * 確認底圖就緒；缺檔時顯示下載提示並輪詢 status。
 * @param {number} token 開啟世代
 * @param {boolean} retry 失敗後重試（帶 ?retry=1）
 */
function ensureBasemapReady(token, retry = false) {
    stopPolling();
    if (basemapRequestController) {
        basemapRequestController.abort();
    }
    const statusUrl = cfg().statusUrl;
    const deadline = Date.now() + 60000; // 最多輪詢約 60 秒

    return new Promise((resolve, reject) => {
        const poll = (withRetry) => {
            if (token !== openToken) {
                return; // 已關閉或重新開啟，放棄
            }
            const url = withRetry ? `${statusUrl}?retry=1` : statusUrl;
            basemapRequestController = new AbortController();
            fetch(url, {
                headers: { Accept: 'application/json' },
                signal: basemapRequestController.signal,
            })
                .then(parseJsonResponse)
                .then((data) => {
                    basemapRequestController = null;
                    if (token !== openToken) {
                        return;
                    }
                    if (data.ready) {
                        resolve();
                        return;
                    }
                    if (data.state === 'failed') {
                        reject({ userMessage: data.message || t('download_failed') });
                        return;
                    }
                    setStatus({ message: t('downloading_basemap') });
                    if (Date.now() > deadline) {
                        reject({ userMessage: t('download_failed') });
                        return;
                    }
                    pollTimer = window.setTimeout(() => poll(false), 2500);
                })
                .catch((error) => {
                    basemapRequestController = null;
                    if (isAbortError(error) || token !== openToken) {
                        return;
                    }
                    const userMessage =
                        error && error.body && typeof error.body.message === 'string'
                            ? error.body.message
                            : t('load_error');
                    if (token === openToken) {
                        reject({ userMessage });
                    }
                });
        };
        poll(retry);
    });
}

function startMap(token, personId, currentKey, lon, lat) {
    if (token !== openToken) {
        return;
    }
    hideStatus();
    const mapEl = overlayEl.querySelector('.chgis-modal__map');
    const c = cfg();
    const bounds = c.bounds || {};
    // 顯示範圍（限制平移／縮放，不露出底圖內容外的純黑／純白）；缺設定時退回 tile bounds。
    const displayLatLng = boundsToLatLng(c.displayBounds || bounds);

    cleanupMap();
    mapInstance = L.map(mapEl, {
        zoomControl: true,
        attributionControl: true,
        minZoom: c.minZoom || 3,
        maxZoom: 10,
        // 硬邊界：平移到界即回彈（maxBounds 延到 invalidateSize 後再設，
        // 避免在 modal 進場、容器尺寸仍為 0 時夾擠出壞狀態導致底圖不顯示）。
        maxBoundsViscosity: 1.0,
    });

    mapInstance.attributionControl.setPrefix(
        '<a href="https://leafletjs.com" title="A JS library for interactive maps">Leaflet</a>'
    );

    L.tileLayer(c.tileUrlTemplate, {
        minZoom: c.minZoom || 3,
        maxZoom: 10,
        maxNativeZoom: c.maxZoom || 8,
        noWrap: true,
        bounds: displayLatLng || boundsToLatLng(bounds),
        attribution:
            '<a href="https://chgis.fas.harvard.edu/data/chgis/v6/" target="_blank" rel="noopener noreferrer">CHGIS v6</a>' +
            ' © Harvard &amp; Fudan | Rivers &amp; coastlines: 1820 data |' +
            ' <a href="https://huggingface.co/datasets/cbdb/chgis-map" target="_blank" rel="noopener noreferrer">Map data</a>',
    }).addTo(mapInstance);

    // clickedCenter：使用者點擊的 place-link 所附座標（有效時）。即使點擊點未對上任何
    // 已抓取的 point key（例如任官地址的 office key 與 point key 不完全一致），仍以此座標
    // 居中，取代「多點時 fitBounds 全部」造成的「點開的地址不居中」。
    const clickedCenter = isFinite(lon) && isFinite(lat) ? [lat, lon] : null;
    const fallbackCenter = clickedCenter || [35, 110];
    mapInstance.setView(fallbackCenter, 6, { animate: false });

    // modal 進場動畫結束後再重算尺寸，避免量到中間態
    invalidateAfterTransition();

    loadPoints(personId)
        .then((points) => {
            if (token === openToken) {
                renderPoints(points, currentKey, fallbackCenter, clickedCenter);
            }
        })
        .catch((error) => {
            if (isAbortError(error) || token !== openToken) {
                return;
            }
            if (token === openToken) {
                showRetryableStatus(t('load_error'), () => {
                    if (token !== openToken) {
                        return;
                    }
                    hideStatus();
                    startMap(token, personId, currentKey, lon, lat);
                });
            }
        });
}

function invalidateAfterTransition() {
    const modal = overlayEl.querySelector('.chgis-modal');
    let done = false;
    const run = () => {
        if (done || !mapInstance) {
            return;
        }
        done = true;
        mapInstance.invalidateSize({ animate: false });
        // 容器有真實尺寸後才設 maxBounds 與最小 zoom（在 0×0 時設會夾擠出壞狀態、底圖不顯示）。
        const disp = boundsToLatLng(cfg().displayBounds);
        if (disp) {
            const dispBounds = L.latLngBounds(disp);
            // 最小 zoom = 「內容框剛好蓋滿視窗」的 zoom（inside=true：視窗需完全落在 bounds 內），
            // 杜絕縮太遠露出內容外的純黑／純白。
            const fitZoom = mapInstance.getBoundsZoom(dispBounds, true);
            if (isFinite(fitZoom)) {
                // 夾在 [設定 minZoom, maxZoom]：避免極小的 display_bounds 把 minZoom 推到超過 maxZoom 而鎖死地圖。
                const floor = Math.max(cfg().minZoom || 3, fitZoom);
                mapInstance.setMinZoom(Math.min(mapInstance.getMaxZoom(), floor));
            }
            mapInstance.setMaxBounds(dispBounds);
        }
        modal.removeEventListener('transitionend', onEnd);
    };
    const onEnd = (e) => {
        if (e.propertyName === 'transform') {
            run();
        }
    };
    modal.addEventListener('transitionend', onEnd);
    // fallback：動畫被略過（reduced-motion）或 transitionend 未觸發時
    window.setTimeout(run, reducedMotion() ? 30 : 240);
}

function boundsToLatLng(b) {
    if (!b || b.south == null) {
        return undefined;
    }
    return [
        [b.south, b.west],
        [b.north, b.east],
    ];
}

function loadPoints(personId) {
    const base = cfg().pointsUrlBase || '/basicinformation';
    const url = `${base}/${encodeURIComponent(personId)}/map-points`;
    if (pointsRequestController) {
        pointsRequestController.abort();
    }
    pointsRequestController = new AbortController();

    return fetch(url, {
        headers: { Accept: 'application/json' },
        signal: pointsRequestController.signal,
    })
        .then(parseJsonResponse)
        .finally(() => {
            pointsRequestController = null;
        })
        .then((data) => (data && Array.isArray(data.points) ? data.points : []));
}

function renderPoints(points, currentKey, fallbackCenter, clickedCenter) {
    const valid = points.filter((p) => isFinite(p.lon) && isFinite(p.lat));
    if (!valid.length) {
        setStatus({ message: t('no_points'), spinner: false, retry: false });
        return;
    }

    // 依座標（四捨五入到 5 位小數）合併同地點的多個 node
    const groups = groupByCoord(valid);
    const animate = !reducedMotion();
    const markers = [];
    let currentLatLng = null;

    groups.forEach((entries) => {
        const first = entries[0];
        const latlng = [first.lat, first.lon];
        const hasCurrent = currentKey && entries.some((e) => e.key === currentKey);
        const marker = makeMarker(latlng, entries, hasCurrent);
        marker.addTo(mapInstance).bindPopup(buildPopup(entries, currentKey), {
            maxWidth: 300,
            maxHeight: 320,
        });
        markers.push(marker);
        if (hasCurrent) {
            currentLatLng = latlng;
        }
    });

    const groupList = Array.from(groups.values());
    const hasCluster = groupList.some((entries) => entries.length > 1);
    // 僅當地圖上真的出現「分割色」marker（mixed 且非 current，current 會被紅色覆蓋）才在圖例說明
    const hasMixed = groupList.some(
        (entries) => groupIsMixed(entries) && !(currentKey && entries.some((e) => e.key === currentKey))
    );
    addLegend(hasCluster, hasMixed);

    if (currentLatLng) {
        mapInstance.setView(currentLatLng, 7, { animate });
    } else if (clickedCenter) {
        // 點開特定 place-link：以該地址座標居中，即使其 key 未對上任何 point。
        mapInstance.setView(clickedCenter, 7, { animate });
    } else if (markers.length === 1) {
        mapInstance.setView(markers[0].getLatLng(), 7, { animate });
    } else if (markers.length > 1) {
        mapInstance.fitBounds(L.featureGroup(markers).getBounds().pad(0.2), { animate });
    } else {
        mapInstance.setView(fallbackCenter, 6, { animate });
    }
}

/** 依座標分組（同像素合併）。回傳 Map<coordKey, entries[]>。 */
function groupByCoord(points) {
    const groups = new Map();
    points.forEach((p) => {
        const lon = Number(p.lon);
        const lat = Number(p.lat);
        const key = lon.toFixed(5) + ',' + lat.toFixed(5);
        if (!groups.has(key)) {
            groups.set(key, []);
        }
        groups.get(key).push(p);
    });
    return groups;
}

const COLOR_ADDRESS = '#2563eb';
const COLOR_OFFICE = '#16a34a';
const FILL_MIXED = `linear-gradient(90deg, ${COLOR_ADDRESS} 0 50%, ${COLOR_OFFICE} 50% 100%)`;

/** 該組是否同時含地址與官職（同一地點既是傳記地址又是任官地）。 */
function groupIsMixed(entries) {
    return entries.some((e) => e.source === 'address') && entries.some((e) => e.source === 'office');
}

/** 標記填色：兼具兩類→左藍右綠分割；純地址→藍；純官職→綠。 */
function groupFill(entries) {
    if (groupIsMixed(entries)) {
        return FILL_MIXED;
    }
    return entries.some((e) => e.source === 'address') ? COLOR_ADDRESS : COLOR_OFFICE;
}

function makeMarker(latlng, entries, hasCurrent) {
    if (entries.length === 1) {
        return hasCurrent ? currentMarker(latlng) : normalMarker(latlng, entries[0].source);
    }
    return clusterMarker(latlng, entries.length, hasCurrent, groupFill(entries));
}

function normalMarker(latlng, source) {
    const color = source === 'office' ? '#16a34a' : '#2563eb';
    return L.circleMarker(latlng, {
        radius: 7,
        color: '#fff',
        weight: 2,
        fillColor: color,
        fillOpacity: 0.9,
    });
}

function currentMarker(latlng) {
    const icon = L.divIcon({
        className: '',
        html: '<div class="chgis-current-marker"></div>',
        iconSize: [20, 20],
        iconAnchor: [10, 10],
        popupAnchor: [0, -10],
    });
    return L.marker(latlng, { icon, zIndexOffset: 1000 });
}

/** 多筆同座標：帶數字徽章的標記（含當前點脈動）。 */
function clusterMarker(latlng, count, isCurrent, fill) {
    const n = Number(count) || 0;
    const cls = 'chgis-cluster-marker' + (isCurrent ? ' chgis-cluster-marker--current' : '');
    // n 為整數、fill 為固定字面色／漸層，無使用者輸入，無 XSS
    const style = isCurrent ? '' : ` style="background:${fill}"`;
    const html = `<div class="${cls}"${style}><span class="chgis-cluster-badge">${n}</span></div>`;
    const icon = L.divIcon({
        className: '',
        html,
        iconSize: [24, 24],
        iconAnchor: [12, 12],
        popupAnchor: [0, -12],
    });
    return L.marker(latlng, { icon, zIndexOffset: isCurrent ? 1000 : 0 });
}

function firstNonEmpty(...values) {
    for (const value of values) {
        const text = value == null ? '' : String(value).trim();
        if (text !== '') {
            return text;
        }
    }

    return '';
}

/** 地點名稱（地名）：name_chn → name → #addr_id → 未知。 */
function placeNameOf(e) {
    const place = firstNonEmpty(e.name_chn, e.name);
    if (place !== '') {
        return place;
    }
    if (e.addr_id != null && e.addr_id !== '') {
        return '#' + e.addr_id;
    }

    return t('unknown_place');
}

/** 官名中／英並列的 HTML（英文以較淡較小字呈現，避免擁擠）。 */
function officeNameHtml(e) {
    const chn = firstNonEmpty(e.office_name);
    const en = firstNonEmpty(e.office_name_en);
    if (chn && en) {
        return escapeHtml(chn) + ` <span class="chgis-pop__en">${escapeHtml(en)}</span>`;
    }
    if (chn) {
        return escapeHtml(chn);
    }
    if (en) {
        return escapeHtml(en);
    }

    return '';
}

/** 一筆 entry 顯示的 HTML：官職為「官名(中 英) · 地名」，地址為「地名」。 */
function entryDisplayHtml(e) {
    const place = escapeHtml(placeNameOf(e));
    if (e.source === 'office') {
        const office = officeNameHtml(e);
        return office ? `${office} &middot; ${place}` : place;
    }

    return place;
}

/** 純文字版（供同名碰撞偵測，不含標籤）。 */
function entryDisplayText(e) {
    if (e.source === 'office') {
        const parts = [firstNonEmpty(e.office_name, e.office_name_en), placeNameOf(e)].filter(Boolean);
        return parts.join(' · ');
    }

    return placeNameOf(e);
}

function formatYears(e) {
    if (!e.first_year && !e.last_year) {
        return '';
    }

    return String(e.first_year || '?') + '-' + String(e.last_year || '?');
}

function formatEntryLine(e, currentKey, showAddrId) {
    const isCurrent = currentKey && e.key === currentKey;
    const safeUrl = sanitizePopupUrl(e.url);

    // 整列內容：地名/官名 + #id + 年代
    let content = `<span class="chgis-pop__name">${entryDisplayHtml(e)}</span>`;
    if (showAddrId && e.addr_id != null && e.addr_id !== '') {
        content += ` <span class="chgis-pop__meta">#${escapeHtml(e.addr_id)}</span>`;
    }
    const years = formatYears(e);
    if (years) {
        content += ` <span class="chgis-pop__yr">(${escapeHtml(years)})</span>`;
    }

    const currentCls = isCurrent ? ' chgis-pop__item--current' : '';

    // 有 url 時「整列」即為連結（block，整列可點），於新分頁開啟該筆記錄頁
    if (safeUrl) {
        return `<a class="chgis-pop__item chgis-pop__item--link${currentCls}" href="${escapeHtml(safeUrl)}" target="_blank" rel="noopener noreferrer" title="${escapeHtml(t('open_record'))}">${content}</a>`;
    }

    return `<div class="chgis-pop__item${currentCls}">${content}</div>`;
}

/** 一組（同座標）的 popup：標頭 + 依類型分組的捲動清單，當前 node 標示。 */
function buildPopup(entries, currentKey) {
    const placeName = placeNameOf(entries[0]);
    const addresses = entries.filter((e) => e.source === 'address');
    const offices = entries.filter((e) => e.source === 'office');

    let head = `<strong>${escapeHtml(placeName)}</strong>`;
    if (entries.length > 1) {
        head += ` &middot; ${entries.length} ${escapeHtml(t('count_unit'))}`;
    }

    let html = `<div class="chgis-pop"><div class="chgis-pop__head">${head}</div>`;
    html += popupSection(t('type_address'), addresses, currentKey);
    html += popupSection(t('type_office'), offices, currentKey);
    html += '</div>';
    return html;
}

function popupSection(label, list, currentKey) {
    if (!list.length) {
        return '';
    }

    const showAddrId = new Set(list.map((e) => entryDisplayText(e))).size < list.length;
    let html = `<div class="chgis-pop__section"><div class="chgis-pop__sectit">${escapeHtml(label)}</div>`;
    list.forEach((e) => {
        html += formatEntryLine(e, currentKey, showAddrId);
    });
    html += '</div>';
    return html;
}

function addLegend(hasCluster, hasMixed) {
    if (legendControl) {
        legendControl.remove();
    }
    const legend = L.control({ position: 'bottomleft' });
    legend.onAdd = function () {
        const div = L.DomUtil.create('div', 'chgis-legend');
        let html = `
            <div class="chgis-legend__row"><span class="chgis-legend__dot" style="background:#ef4444"></span><span class="chgis-legend__label">${escapeHtml(t('current_location'))}</span></div>
            <div class="chgis-legend__row"><span class="chgis-legend__dot" style="background:${COLOR_ADDRESS}"></span><span class="chgis-legend__label">${escapeHtml(t('biographical_addresses'))}</span></div>
            <div class="chgis-legend__row"><span class="chgis-legend__dot" style="background:${COLOR_OFFICE}"></span><span class="chgis-legend__label">${escapeHtml(t('office_locations'))}</span></div>`;
        // 同一點兼具地址與官職時的分割標記說明
        if (hasMixed) {
            html += `<div class="chgis-legend__row"><span class="chgis-legend__dot" style="background:${FILL_MIXED}"></span><span class="chgis-legend__label">${escapeHtml(t('both_types'))}</span></div>`;
        }
        // 只有真的出現合併標記時才顯示徽章說明
        if (hasCluster) {
            html += `<div class="chgis-legend__row"><span class="chgis-legend__badge">N</span><span class="chgis-legend__label">${escapeHtml(t('legend_count_hint'))}</span></div>`;
        }
        div.innerHTML = html;
        return div;
    };
    legend.addTo(mapInstance);
    legendControl = legend;
}

/** 事件委派：點擊或鍵盤觸發 Place Name 連結。 */
function onActivate(e) {
    const trigger = e.target.closest('.chgis-place-link');
    if (!trigger) {
        return;
    }
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
        return;
    }
    e.preventDefault();
    openModal(trigger);
}

document.addEventListener('click', onActivate);
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && overlayEl && overlayEl.classList.contains('is-open')) {
        closeModal();
        return;
    }
    onActivate(e);
});
