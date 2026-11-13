# CHGIS 底圖圖磚：外部網站使用說明

> 對象：想在自己的網頁地圖上疊用本站 CHGIS 歷史底圖的開發者（第一個使用者是 cbdb-qa-lab 的對話內地圖）。
> 本站內部的設計與護欄見 [CHGIS_MAP_PLACE_LINK.md](./CHGIS_MAP_PLACE_LINK.md) §5.1.1；端點列表見 [API.md](../API.md) 14.9。

## 1. 端點

```
GET https://<主站網域>/chgis-map/tiles/{z}/{x}/{y}
```

- **XYZ（Google／OSM）慣例**，Web Mercator（EPSG:3857）。`z` 是 1–2 位、`x`／`y` 是 1–10 位的非負整數，其他路徑是 404。
- **公開、不需登入、不使用 session**：回應不帶 `Set-Cookie`，也不帶 `Cross-Origin-Resource-Policy`，可由任何網站以 `<img>`（Leaflet、OpenLayers 的瓦片層）跨站載入。這個端點不讀取、也不依賴本站的 cookie（一般的跨站 `<img>` 本來就不會帶本站 cookie；就算帶了也會被忽略）。
- 另有 `GET /chgis-map/status`（JSON，底圖是否就緒），**外部網站不要呼叫**：它屬於本站頁面，底圖缺檔時會觸發伺服器下載，而且會建立 session。

## 2. 回應

| 情況 | 狀態 | 內容 |
| --- | --- | --- |
| 命中 | 200 | `image/png` 圖磚 |
| 該格沒有圖磚、`z` 超出原生縮放範圍、`x`／`y` 超出 `[0, 2^z − 1]` | 200 | 1×1 透明 PNG（不是 404，瓦片層不會報錯） |
| 帶 `If-None-Match` 且底圖未變 | 304 | 無內容 |
| 本站底圖檔尚未就緒（新部署、下載中） | 503 | 錯誤頁；短暫狀態，瓦片層會顯示為空白 |

快取：`Cache-Control: public, no-cache` 加 `ETag`／`Last-Modified`。瀏覽器會快取圖磚，但每次都會帶 `ETag` 回來驗證；底圖更換後 `ETag` 改變，自動拉新磚，**不需要**自己加版本參數。（本站自己的地圖網址帶 `?v=<底圖時間戳>`，那是為了淘汰改成 `no-cache` 之前留下的長期快取，新接入的網站用不到；伺服器也不讀這個參數。）

跨來源相關：
- 全站都帶 `Access-Control-Allow-Origin: *`（`Access-Control-Allow-Credentials: false`），所以需要時也可以用 `crossOrigin`（anonymous）載入，例如要把地圖畫進 canvas 匯出。
- 回應刻意**不帶** `Cross-Origin-Resource-Policy`。若你的網站設了 `Cross-Origin-Embedder-Policy: require-corp`，一般的 `<img>` 會被瀏覽器擋下——改用 `crossOrigin` 載入（Leaflet 的 `crossOrigin: true`），或改用 COEP `credentialless`。

## 3. 縮放與範圍（照本站的設定）

本站自己的地圖是這樣設定的（[resources/js/chgis-map/app.js](../resources/js/chgis-map/app.js)、[config/chgis_map.php](../config/chgis_map.php)）：

| 項目 | 程式預設 | 可由 prod 環境變數改？ | 說明 |
| --- | --- | --- | --- |
| 原生縮放 | 3–8 | 可以（`CHGIS_MAP_MIN_ZOOM`／`CHGIS_MAP_MAX_ZOOM`） | 範圍外伺服器回透明磚 |
| 顯示範圍 | W 60.5、S 3.0、E 150.5、N 59.5 | 可以（`CHGIS_MAP_DISPLAY_*`） | 底圖實際有內容的外接框，超出就是純黑／純白 |
| 地圖最大縮放 | 10 | 不行（寫在前端） | 以 `maxNativeZoom: 8` 放大第 8 級的磚（overzoom）；這是本站的選擇，你可以自訂 |
| `noWrap` | `true` | 不行（寫在前端） | 底圖不跨日界線重複 |

⚠️ 前兩列 prod 可以覆寫。**正式接入前請向本站確認 prod 的實際值**（見 §6），不要只抄上表。

## 4. Leaflet 範例

```js
const TILE_ORIGIN = 'https://<主站網域>'; // 只放 origin，路徑寫死在程式裡
const display = L.latLngBounds([3.0, 60.5], [59.5, 150.5]);

const map = L.map('map', { minZoom: 3, maxZoom: 10, maxBounds: display, maxBoundsViscosity: 1.0 });

// Leaflet 要先有視角才會載入任何圖磚：沒有 setView／fitBounds 的地圖是一片空白。
map.fitBounds(display);

// 最小縮放改成「顯示範圍剛好蓋滿視窗」的那一級（本站的做法）：寬螢幕在第 3 級時
// 視窗會比顯示範圍寬，搭配 maxBoundsViscosity: 1.0 會來回彈跳，也會露出範圍外的空白。
// 容器尺寸改變（例如放在 modal 裡、進場動畫結束）後要先 invalidateSize() 再算一次。
const fitZoom = map.getBoundsZoom(display, true);
map.setMinZoom(Math.min(map.getMaxZoom(), Math.max(3, fitZoom)));

L.tileLayer(`${TILE_ORIGIN}/chgis-map/tiles/{z}/{x}/{y}`, {
  minZoom: 3,
  maxZoom: 10,
  maxNativeZoom: 8,
  noWrap: true,
  bounds: display,
  attribution:
    '<a href="https://chgis.fas.harvard.edu/data/chgis/v6/" target="_blank" rel="noopener noreferrer">CHGIS v6</a>' +
    ' © Harvard &amp; Fudan | Rivers &amp; coastlines: 1820 data |' +
    ' <a href="https://huggingface.co/datasets/cbdb/chgis-map" target="_blank" rel="noopener noreferrer">Map data</a>',
}).addTo(map);
```

- **請保留出處標示**：至少要有「CHGIS v6」與「© Harvard & Fudan」，建議直接用上面本站的文字（含 1820 年河流海岸線資料的說明與資料集連結）。
- 網站有 CSP 時，`img-src` 要加上主站的 origin（例如 `img-src 'self' https://<主站網域>`）；`<img>` 載入圖磚不需要 `connect-src`。
- 底圖還會向使用者的瀏覽器透露「他正在看哪一塊地圖」給本站（一般的跨站資源請求，含 `Referer`，依你網站的 `Referrer-Policy` 而定）。若有隱私告知的需要，請在頁面上說明。

## 5. 接入前的檢查

```bash
curl -sI https://<主站網域>/chgis-map/tiles/5/25/12
```

- 應為 `200` 與 `Content-Type: image/png`（若是 `503`，表示本站底圖還沒就緒，稍後再試）；
- **不應有** `Set-Cookie` 與 `Cross-Origin-Resource-Policy`：
  ```bash
  curl -sI https://<主站網域>/chgis-map/tiles/5/25/12 | grep -i -E 'set-cookie|cross-origin-resource-policy'
  # 沒有輸出才正確
  ```
- 本站的應用層測試看不到 web server（nginx／Caddy）額外加的標頭，以這個 `curl` 為準。

## 6. 穩定性與聯絡

- 路徑、公開性、無狀態性是本站對已知下游的承諾：本站 [AGENTS.md](../AGENTS.md) 與程式註解都註明了這條依賴，改動前會先通知下游。
- 本端點**沒有**服務等級保證，也沒有專門的限流額度；請用瀏覽器端的瓦片層按需載入，**不要批次抓取整個底圖**——需要整份底圖請直接下載 HuggingFace 上的 [chgis-map dataset](https://huggingface.co/datasets/cbdb/chgis-map)（`chgis_map.mbtiles`）。
- **接入前請先告訴我們**：到 [cbdb-online-main-server 的 GitHub issues](https://github.com/cbdb-project/cbdb-online-main-server/issues) 開一則 issue，說明網站、用途與依賴的設定（縮放、範圍）。我們會把它記進本站的已知下游清單（AGENTS.md「CHGIS 地圖」一節），之後改動這個端點或相關設定時會先通知；也可以在同一處詢問 prod 的實際設定值。
