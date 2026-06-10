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

    cleanupMap();
    mapInstance = L.map(mapEl, {
        zoomControl: true,
        attributionControl: false,
        minZoom: c.minZoom || 3,
        maxZoom: 10,
    });

    L.tileLayer(c.tileUrlTemplate, {
        minZoom: c.minZoom || 3,
        maxZoom: 10,
        maxNativeZoom: c.maxZoom || 8,
        noWrap: true,
        bounds: boundsToLatLng(bounds),
    }).addTo(mapInstance);

    const fallbackCenter = isFinite(lon) && isFinite(lat) ? [lat, lon] : [35, 110];
    mapInstance.setView(fallbackCenter, 6, { animate: false });

    // modal 進場動畫結束後再重算尺寸，避免量到中間態
    invalidateAfterTransition();

    loadPoints(personId)
        .then((points) => {
            if (token === openToken) {
                renderPoints(points, currentKey, fallbackCenter);
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
    if (b.south == null) {
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

function renderPoints(points, currentKey, fallbackCenter) {
    if (!points.length) {
        setStatus({ message: t('no_points'), spinner: false, retry: false });
        return;
    }

    const animate = !reducedMotion();
    const markers = [];
    let currentLatLng = null;

    points.forEach((p) => {
        if (!isFinite(p.lon) || !isFinite(p.lat)) {
            return;
        }
        const latlng = [p.lat, p.lon];
        const isCurrent = currentKey && p.key === currentKey;
        const marker = isCurrent ? currentMarker(latlng) : normalMarker(latlng, p.source);
        marker.addTo(mapInstance).bindPopup(popupHtml(p));
        markers.push(marker);
        if (isCurrent) {
            currentLatLng = latlng;
        }
    });

    addLegend();

    if (currentLatLng) {
        mapInstance.setView(currentLatLng, 7, { animate });
    } else if (markers.length === 1) {
        mapInstance.setView(markers[0].getLatLng(), 7, { animate });
    } else if (markers.length > 1) {
        mapInstance.fitBounds(L.featureGroup(markers).getBounds().pad(0.2), { animate });
    } else {
        mapInstance.setView(fallbackCenter, 6, { animate });
    }
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

function popupHtml(p) {
    const typeLabel = p.source === 'office' ? t('type_office') : t('type_address');
    const name = p.label || p.name_chn || p.name || '';
    let html = `<strong>${escapeHtml(name)}</strong><br><span>${escapeHtml(typeLabel)}</span>`;
    if (p.first_year || p.last_year) {
        const years = `${p.first_year || '?'}–${p.last_year || '?'}`;
        html += `<br><span>${escapeHtml(t('year_range'))}: ${escapeHtml(years)}</span>`;
    }
    return html;
}

function addLegend() {
    if (legendControl) {
        legendControl.remove();
    }
    const legend = L.control({ position: 'bottomleft' });
    legend.onAdd = function () {
        const div = L.DomUtil.create('div', 'chgis-legend');
        div.innerHTML = `
            <div class="chgis-legend__row"><span class="chgis-legend__dot" style="background:#ef4444"></span>${escapeHtml(t('current_location'))}</div>
            <div class="chgis-legend__row"><span class="chgis-legend__dot" style="background:#2563eb"></span>${escapeHtml(t('other_addresses'))}</div>
            <div class="chgis-legend__row"><span class="chgis-legend__dot" style="background:#16a34a"></span>${escapeHtml(t('office_locations'))}</div>`;
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
