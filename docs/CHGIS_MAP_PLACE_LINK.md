# CHGIS 地圖：Place Name 可點擊連結與浮出地圖（可行性設計）

> 狀態：**已實作**（分支 `feature/chgis-map-place-link`，分階段 P1–P7）。本文件為設計與實作對照說明。
> 範圍：`/basicinformation/{id}/addresses` 與 `/basicinformation/{id}/offices` 兩個列表頁的 **Place Name** 欄位
> 目標：為「有有效經緯度」的地點加上可點擊連結，點擊後浮出以 `chgis_map.mbtiles` 為底圖的地圖，標出該人物所有有效地點，並突顯當前點。

> **部署注意（lazy 下載）**：`/chgis-map/status` 缺檔時以 `FetchChgisMapJob::dispatchAfterResponse()` 在回應後背景下載。當 `QUEUE_CONNECTION=sync`（預設）時，下載在 web 程序內執行，受 PHP `max_execution_time` 限制——大型底圖可能在下載完成前被中止。**正式環境應以 `php artisan cbdb:fetch-chgis-map`（部署步驟，見 §4.5）預先抓好底圖**，lazy 下載僅為後備；若要倚賴 lazy 下載，請改用常駐 queue worker（database/redis）或確保該路徑的 `max_execution_time` ≥ `chgis_map.source.timeout`。

> **實作備忘（與初版設計的差異）**：
> - 官職分組鍵改用 `(c_office_id, c_posting_id)` 複合鍵（`c_posting_id` 在 `POSTED_TO_OFFICE_DATA` 非全域唯一），點位 key 為 `office:{office_id}:{posting_id}:{addr_id}`；避免官名張冠李戴與 key 碰撞。
> - `ChgisMapManager` 下載額外驗證 SQLite 魔術位元組、原子替換含備份還原；lock TTL 嚴格大於下載逾時，狀態含 `started_at` 以 stale 自癒避免永久 `downloading`。
> - 前端 Leaflet 改為 npm 依賴（非 CDN），當前點用 `divIcon` 脈動、一般點用 `circleMarker`，避開 Vite 下預設 marker 圖檔路徑問題。

---

## 1. 目標與範圍

### 1.1 功能目標
1. 在兩個列表頁的 Place Name 欄位，對「有效座標」的地點渲染為可點擊連結；無效座標維持純文字。
2. 點擊後浮出 modal 地圖（無邊框、背景變暗、支援手機互動）。
3. 地圖底圖來自 `chgis_map.mbtiles`；圖面標出該人物**所有**有效 addresses + offices 地點，**當前點**置中並以特殊標記突顯。
4. `chgis_map.mbtiles` 不存放於 repo；部署與「首次存取地圖」時，若檔案不存在則自 HuggingFace 下載，並向使用者顯示提示。

### 1.2 非目標（本期不做）
- 不在 React/Inertia 版重做（這兩頁是 Blade 舊頁；本功能屬既有 Blade 頁的增強，按 AGENTS.md「相容期」原則僅做必要增強，不引入新框架）。
- 不做歷史分期切換（沿用 `chgis_map.mbtiles` 單一底圖）。
- 不做座標編輯／校正；僅做「能不能連結」的判定與展示。

---

## 2. 現況分析（依據程式碼）

### 2.1 資料來源

**Addresses** — `BasicInformationAddressesController::index()`（`byIdWithAddr` → `biog_addresses` hasMany `BiogAddr`）
視圖 `resources/views/biogmains/addresses/index.blade.php:46`：
```blade
<td>{{ $basicinformation->biog_addresses[$i]->addr->c_name_chn }}</td>
```
- `BiogAddr->addr` 關聯指向 `AddrCode`，可取 `->addr->x_coord`、`->addr->y_coord`、`->addr->c_addr_id`、`->addr->c_name`。

**Offices** — `BasicInformationOfficesController::index()`（`byIdWithOff` → `offices_addr` belongsToMany `AddrCode`，pivot `c_posting_id`，`BiogMain.php:102`）
視圖 `resources/views/biogmains/offices/index.blade.php:46`：
```blade
<td>{{ $post2addr[$value->pivot->c_posting_id] ?? '' }}</td>
```
- `post2addr` 來自 `serialAddr()`（`BasicInformationOfficesController.php:425`），目前只把 `c_name_chn` 以 `;` 串接，**丟棄了座標與 addr_id**。

> 結論：座標在兩頁都拿得到（皆經 `AddrCode`），但 offices 的 `serialAddr()` 需要改成保留 `x_coord/y_coord/c_addr_id`，而非只回傳字串。

### 2.2 座標欄位
- `ADDR_CODES.x_coord`（double，經度 longitude）、`ADDR_CODES.y_coord`（double，緯度 latitude）。
- 全專案慣例一致（見 `ApiController2/4_2/5/6`、`ViewTableQueries`）：**x=經度、y=緯度**。

### 2.3 既有地圖能力
- `resources/js/historical-maps/app.js` 已用 **Leaflet**（透過 `window.L`），但所有 tile 來源是外部 CDN（OSM / CartoDB / Esri / `tiles.digitalhumanities.dev`）。
- 全專案**沒有** mbtiles 讀取、沒有 tile server 路由（`routes/` 內 grep `tile/mbtiles` 無結果）。
- `package.json` 未直接依賴 leaflet（以全域 `window.L` 提供）。

### 2.4 mbtiles 檔案特性（實測 metadata）
| 項目 | 值 |
|------|----|
| format | png |
| type | overlay |
| minzoom / maxzoom | 3 / 8 |
| bounds (WGS84, W,S,E,N) | `58.5372, -62.6348, 152.24, 82.7288` |
| tiles 數量 | 15,715 |

- MBTiles 規格採 **TMS** 排列（y 軸與 XYZ/Leaflet 相反）：`tms_y = 2^z - 1 - y`。
- 投影 EPSG:3857（Web Mercator），緯度有效範圍 ±85.0511°。

---

## 3. 「有效座標」判定規則（核心邏輯）

集中為單一服務 `App\Support\CoordinateValidator`（純函式、無副作用、易測）。對任一 `(x_coord, y_coord)`：

設 `lon = x_coord`、`lat = y_coord`，依序判定，任一不過即「無效（不加連結）」：

1. **存在性**：`lon`、`lat` 皆非 `null`、可轉為數值。
2. **非零**：`abs(lon) >= EPS` 且 `abs(lat) >= EPS`（`EPS = 1e-7`）。
   - 同時排除 `0,0` 及「經度或緯度單軸為 0」的等價情形。
3. **落在底圖範圍內**（EPSG:3857 / mbtiles bounds）：
   - `WEST <= lon <= EAST` 且 `SOUTH <= lat <= NORTH`
   - 預設取 mbtiles bounds：`WEST=58.5372, EAST=152.24, SOUTH=-62.6348, NORTH=82.7288`。
   - 另設一組可選的「合理東亞收斂框」`SANE`（預設 `lon∈[70,140], lat∈[15,55]`，可由 config 關閉），用以過濾掉 mbtiles bounds 過寬（南界 −62 明顯異常）所放行的雜訊點。最終有效 = 落在 `mbtiles bounds` **且**（若啟用）落在 `SANE` 內。
4. **Web Mercator 可投影**：`abs(lat) <= 85.0511`（理論保險；`SANE`/bounds 已涵蓋，但保留以防設定放寬）。

### 3.1 關於「經緯度反掉」
- 反掉的座標（把緯度值放進 `x_coord`）多半會被規則 3 自然濾掉：例如 `lon=110` 被當成 `lat` 時 `110 > 82.7288`、超出緯度上界即判無效。
- **刻意不自動 swap**：CBDB 不該在展示層默默「猜測並修正」資料；若同時落在重疊區間（如兩值都在 70–82）而無法可靠判別，採「保守接受原值」並在文件標註此限制。資料層的反掉問題應另以資料清理處理，不在本功能範圍。

### 3.2 設定集中化
於 `config/chgis_map.php` 提供：
```php
return [
    'bounds' => ['west' => 58.5372, 'south' => -62.6348, 'east' => 152.24, 'north' => 82.7288],
    'sane_bounds' => ['enabled' => true, 'west' => 70, 'south' => 15, 'east' => 140, 'north' => 55],
    'epsilon' => 1e-7,
    'min_zoom' => 3,
    'max_zoom' => 8,
    // 下載相關見 §4
];
```
> bounds 與 mbtiles metadata 應一致；若日後更換 mbtiles，僅需改 config（亦可寫一個 artisan 指令從 mbtiles metadata 讀回更新）。

---

## 4. mbtiles 資料來源與部署策略

### 4.1 不放進 repo
- `chgis_map.mbtiles` 為大型二進位（數十～數百 MB），**不入版控**。
- `.gitignore` 增加：`/storage/app/chgis/*.mbtiles`。

### 4.2 存放位置（決策）
**放在 `storage/app/chgis/chgis_map.mbtiles`（private disk）。**

理由：
- mbtiles 是 SQLite 容器，**無法**像靜態檔目錄被 web server 直接當 `/{z}/{x}/{y}.png` 提供；必須由後端讀取 SQLite 再吐出 tile。故不需放 `public/`。
- `storage/app` 已在 Docker volume 內持久化（`docker-compose.yml` 既有掛載），重啟不丟。
- 走 `Storage::disk('local')` 存取，路徑統一、權限受控。

> 不選 `public/`：會把整顆 SQLite 暴露為可下載檔，無意義且佔頻寬。
> 不選 `db-data/`：該目錄語意是 SQLite 資料庫匯出，混放底圖會混淆。

### 4.3 HuggingFace 來源
- Dataset：`cbdb/chgis-map`
- 檔案：`chgis_map.mbtiles`
- 下載 URL（resolve raw）：
  `https://huggingface.co/datasets/cbdb/chgis-map/resolve/main/chgis_map.mbtiles`
- 與既有 `scripts/weekly-sqlite-sync.sh` 同生態系（HuggingFace datasets）。本功能為**只讀下載**，公開 dataset 無需 token。

`config/chgis_map.php` 追加：
```php
'source' => [
    'url' => env('CHGIS_MAP_URL', 'https://huggingface.co/datasets/cbdb/chgis-map/resolve/main/chgis_map.mbtiles'),
    'path' => storage_path('app/chgis/chgis_map.mbtiles'),
    'expected_min_bytes' => 5_000_000, // 體積下限，防半截檔
    'timeout' => 1800,                  // 下載逾時（秒）；lock TTL = timeout + 600
],
```

### 4.4 取得方式一：artisan 指令（部署時）
新增 `php artisan cbdb:fetch-chgis-map`：
1. 若目標檔存在且大小 `>= expected_min_bytes` → 跳過（冪等）。
2. 否則串流下載到 `*.mbtiles.part`，完成後驗證大小（可選 checksum）→ 原子改名為正式檔。
3. 失敗不丟例外中止部署，僅 `warn` 並回非零旗標供腳本判斷（地圖功能會在執行期再嘗試，見 §4.6）。
4. `--force` 可強制重新下載。

### 4.5 取得方式二：接到 `deploy.sh`
在 `deploy.sh` 快取重建後追加（**非致命**）：
```bash
# 5. 確認 CHGIS 底圖（缺檔則自 HuggingFace 下載；失敗不中斷部署）
echo "檢查 CHGIS 底圖..."
php artisan cbdb:fetch-chgis-map || echo "警告: CHGIS 底圖下載失敗，地圖功能將於首次存取時重試"
```
> 放在最後且容錯：底圖缺失不應讓整個部署 `set -e` 失敗。

### 4.6 取得方式三：執行期 lazy 下載（存取地圖時）
依使用者要求「访问地图时若数据不在就去下载并给提示」：

- 前端開 modal 時先呼叫 `GET /chgis-map/status`。
- 後端 `status` 行為：
  - 檔案就緒 → `{ ready: true }`。
  - 檔案不存在且**未在下載** → 觸發背景下載（dispatch queued job `FetchChgisMapJob`，或在無 queue 環境下以 `cache lock + register_shutdown` 背景化），回 `{ ready: false, state: "downloading" }`。
  - 下載中 → `{ ready: false, state: "downloading", progress: <0-100|null> }`。
  - 下載失敗 → `{ ready: false, state: "failed", message: ... }`。
- 前端在 `ready=false` 時顯示提示（見 §6.5），並輪詢 `status`（間隔 2–3s、上限約 60s）直到 `ready` 或 `failed`。
- 以 cache lock（如 `Cache::lock('chgis_map_download', 600)`）避免併發重複下載。

---

## 5. 後端設計

### 5.1 新增路由（`routes/web.php`）
```php
// 底圖 tile（讀 mbtiles 吐 PNG）
Route::get('/chgis-map/tiles/{z}/{x}/{y}', [ChgisMapController::class, 'tile'])
    ->where(['z' => '[0-9]+', 'x' => '[0-9]+', 'y' => '[0-9]+'])
    ->name('chgis-map.tile');

// 底圖狀態 / 觸發下載
Route::get('/chgis-map/status', [ChgisMapController::class, 'status'])->name('chgis-map.status');

// 某人物所有有效地點（給 modal 畫點）
Route::get('/basicinformation/{id}/map-points', [ChgisMapController::class, 'personPoints'])
    ->name('basicinformation.map-points');
```
> 路由置於 `web` middleware group，沿用既有授權上下文（與兩頁列表同等可見性）。

### 5.2 `ChgisMapController`

**`tile($z,$x,$y)`** — 從 mbtiles 取磚：
- 開啟 `storage/app/chgis/chgis_map.mbtiles`（唯讀 SQLite，PDO/`new \PDO('sqlite:...')`，獨立連線，**不**走預設 DB 連線）。
- MBTiles 為 TMS：`tms_y = (2 ** $z) - 1 - $y`。
- `SELECT tile_data FROM tiles WHERE zoom_level=? AND tile_column=? AND tile_row=?`。
- 命中 → `Response(png, 200)`，帶 `Content-Type: image/png`、`Cache-Control: public, no-cache`、`ETag`（含底圖 mtime）；隨即 `isNotModified()` 支援 `304`。
- 未命中／超出範圍／讀取例外 → 回 1×1 透明 PNG（`204` 會讓 Leaflet 報錯，回透明 PNG 較穩）。透明磚同樣帶 `no-cache` + ETag（ETag 以 `transparent/` 前綴，避免與實磚在同 z/x/y、同 mtime 下誤撞 `304`），確保某格由「透明」轉為「底圖已覆蓋」後不會續用快取空白磚。
- 檔案不存在 → `503`（前端已先查 status，理論上不會走到）。
- 連線快取：以 singleton/靜態持有 PDO，避免每磚重開檔。

#### 5.2.1 底圖更新後的快取失效策略（兩段式）

底圖（`chgis_map.mbtiles`）更換後，必須讓使用者立即看到新磚而非舊磚。採**兩段式互補**設計，缺一不可：

| 機制 | 位置 | 解決情境 |
|------|------|----------|
| **URL 版本號 `?v=<mbtiles mtime>`** | `_chgis_map_assets.blade.php`（注入 `tileUrlTemplate`） | **新開頁面／重新整理**：底圖更新→mtime 變→tile URL 變→全新快取鍵，瀏覽器既有舊磚快取自動失效。這是淘汰「已用舊 `max-age` 快取之舊磚」的主力。 |
| **`Cache-Control: public, no-cache` + ETag** | `ChgisMapController::tile()` / `transparentTile()` | **已開著未關的分頁**：Leaflet 已用舊 `?v` 初始化，使用者不重整、僅平移/縮放時，no-cache 令這些請求帶 ETag 回源驗證→ETag 因 mtime 改變而失效→自動換新磚，免重整。 |

> ⚠️ **不可改回長 `max-age`**：歷史上 tile 曾用 `max-age=2592000`（30 天），導致換底圖後瀏覽器於 fresh 期內不驗證、持續顯示舊磚（且因各 zoom 的磚被快取時間不同，呈現「只有某些 zoom 是舊色」的詭異現象）。`?v=` 雖能讓**新頁面**繞過，但長 `max-age` 會讓**已開分頁**把舊 `?v` 的磚硬快取到過期，故仍保留 `no-cache` 負責分頁自癒。兩者同源（皆用 mbtiles mtime），正常「換檔覆蓋」更新即同時生效。

**`status()`** — 見 §4.6。

**`personPoints($id)`** — 回該人物所有有效地點 GeoJSON/JSON：
```jsonc
{
  "points": [
    {
      "key": "addr:12345:0:1",          // 穩定鍵（見下）
      "source": "address",                // address | office
      "addr_id": 12345,
      "name_chn": "...", "name": "...",
      "lon": 118.3, "lat": 35.1,
      "first_year": 1023, "last_year": 1050,
      "label": "知州 · 密州"               // 顯示用（office 帶官名）
    }
  ]
}
```
- 重用 `CoordinateValidator`：只回有效點。
- address 與 office 都查（office 經 `offices_addr`）；同一頁觸發只需該頁資料，但為「顯示此人所有 addresses+offices」需**同時撈兩類**（不論從哪頁點進來）。
- `key` 設計：addresses 用 `addr:{c_addr_id}:{c_addr_type}:{c_sequence}`；offices **實作改為三鍵** `office:{c_office_id}:{c_posting_id}:{c_addr_id}`（因 `c_posting_id` 非全域唯一，見頂部「實作備忘」），供前端標記「當前點」。
- 授權：與列表頁一致（公開可讀則公開；若有登入限制沿用）。

### 5.3 `serialAddr()` 調整（offices）
`BasicInformationOfficesController::serialAddr()` 目前回 `posting_id => "名稱;名稱"`。
- 新增一個並行方法（或擴充回傳結構），讓 view 能對每個 posting 的每個地點，知道 `name_chn / x_coord / y_coord / c_addr_id`，以判定是否連結。
- 建議回傳：`posting_id => [ ['name'=>, 'lon'=>, 'lat'=>, 'addr_id'=>, 'linkable'=>bool], ... ]`，view 端逐一渲染（連結或純文字，多筆仍以 `;` 分隔）。
- 保留舊 `serialAddr()` 給其他呼叫點（若有）以降風險。

### 5.4 `CoordinateValidator`（§3）
- `isValid(?float $lon, ?float $lat): bool`
- `reason(?float $lon, ?float $lat): ?string`（debug/測試用，回不通過的原因）
- 全部讀 `config('chgis_map.*')`。

---

## 6. 前端設計（UI/UX）

### 6.1 連結渲染（兩頁 view）
- Place Name 由純文字改為：`linkable` 時輸出
  ```html
  <a href="#" class="chgis-place-link"
     data-person-id="{{ $id }}"
     data-key="addr:12345:0:1"
     data-lon="118.3" data-lat="35.1">地名</a>
  ```
  否則維持純文字（不可點）。
- 不在 view 寫互動邏輯；用 `data-*` + 一支委派監聽的 JS（事件委派，避免逐列綁定）。

### 6.2 資源載入（Vite，遵守 AGENTS.md：禁 CDN）
- 新增 Vite entry：`resources/js/chgis-map/app.js`（modal + Leaflet 初始化）。
- **Leaflet 改為 npm 依賴**（`npm i leaflet`）而非全域 CDN（符合「不要重新引入外部 CDN」原則）。`historical-maps` 既有的 `window.L` 用法可後續再收斂，本功能直接用 npm import。
- 僅在這兩頁 `@vite` 載入該 entry（或全站載入但 lazy 初始化）。
- 提交前需 `npm run build`。

### 6.3 Modal 結構（無邊框、背景變暗）
設計思路（依設計師慣例）：
- **遮罩**：全螢幕 `position: fixed; inset:0;` 半透明深色 `rgba(0,0,0,.6)` + `backdrop-filter: blur(2px)`，網頁變暗。
- **容器**：無邊框、大圓角、陰影；桌機約 `min(92vw, 1100px) × min(88vh, 760px)` 置中。
- **地圖填滿**容器；右上角浮一個半透明圓形關閉鈕（`×`），不佔地圖邊。
- **進出動畫**：遮罩 fade、容器 scale/translateY 輕微彈入（`prefers-reduced-motion` 時關閉）。
- **關閉**：點遮罩、按 `×`、按 `Esc` 皆可關。
- 開啟時鎖 `body` 捲動（`overflow:hidden`）。

### 6.4 地圖內容
- 底圖：`L.tileLayer('/chgis-map/tiles/{z}/{x}/{y}?v=<mbtiles mtime>', { minZoom:3, maxZoom:10, maxNativeZoom:8, bounds: <mbtiles bounds>, noWrap:true })`（允許 overzoom 到 10，原生最大 8）。URL 末端的 `?v=` 版本號由 blade 注入做 cache-busting，見 §5.2.1。
- 開啟後 `fetch(/basicinformation/{id}/map-points)` 取全部有效點。
- **一般點**：標準 marker / circleMarker。
- **當前點**（`data-key` 對應者）：特殊標記（較大、主題色、可加脈動光暈），`map.setView([lat,lon], zoom)` 置中。
- 各 marker `bindPopup` 顯示地名、類型（地址/官職）、年代區間。
- 多點時提供「縮放至全部」控制（`fitBounds`），但初始以當前點置中（符合需求）。
- 圖例：區分「當前地點 / 其他地址 / 官職地點」。
- **顯示範圍限制（maxBounds）**：地圖設 `maxBounds = config('chgis_map.display_bounds')` 且 `maxBoundsViscosity:1.0`，並在 `invalidateSize` 後以 `getBoundsZoom(displayBounds, true)` 把最小 zoom 設成「內容框蓋滿視窗」的值，避免平移／縮太遠露出底圖內容外的純黑／純白。`display_bounds` 取自實際彩色內容外接框（分析磚像素得約 `W60.8 S3.1 E150 N59.2`，加邊距後預設 `W60.5 S3 E150.5 N59.5`），與點有效性無關（後者仍由 `bounds`/`sane_bounds` 把關）。

### 6.5 底圖未就緒的提示（§4.6）
- 開 modal → 先查 status。
- 未就緒：modal 內顯示置中提示卡：「**地圖底圖首次載入中，正在從資料庫下載，請稍候…**」+ spinner + 進度（若有）。
- 輪詢 `ready` 後再初始化地圖；`failed` 顯示「底圖下載失敗，請稍後再試」+ 重試鈕。
- i18n：所有字串走翻譯鍵（§7）。

### 6.6 手機互動
- 容器在手機改為近全螢幕（`100vw × 100dvh`，用 `dvh` 處理瀏覽器列高度）。
- 觸控手勢：Leaflet `tap`/pinch zoom；`dragging` 啟用；避免與頁面捲動衝突（modal 開啟時 body 鎖捲動）。
- 關閉鈕加大命中區（≥44px）；支援下滑關閉（可選）。
- marker / popup 字級與點擊區放大。
- **安全區與排版**：關閉鈕、attribution（資訊欄）、圖例皆以 `env(safe-area-inset-*)` 內推，避開瀏海／圓角螢幕邊緣遮蓋。圖例設 `width:max-content`（避免被 Leaflet 0 寬容器壓成一字一行的直排），並抬到 attribution 之上（多讓約兩行高），避免資訊欄與圖例層疊。

---

## 7. i18n

- 新增翻譯鍵於 `resources/lang/zh-TW/*.php` 與 `resources/lang/en/*.php`（兩者同步，AGENTS.md 規定）。
- 建議群組 `chgis_map`（或併入 `biogmains`）：
  - `map_modal_title`、`current_location`、`other_addresses`、`office_locations`
  - `downloading_basemap`、`download_failed`、`retry`、`fit_all`、`close`
  - popup：`address_type`、`posting`、`year_range`
- Blade 中以 `__('chgis_map.key')`；JS 字串以 `{!! Js::from(__('chgis_map.key')) !!}` 注入（沿用專案慣例）。
- 連結本身是地名（資料值），不翻譯；周邊 UI 文字一律翻譯。

---

## 8. 安全與授權

- 三個新路由皆置於 `web` group，沿用兩列表頁相同的可見性（若列表公開可讀，地點與底圖亦同級公開）。
- `tile`：路由以 `where` 限定 `z/x/y` 為整數；只 `SELECT` mbtiles，無寫入、無使用者輸入拼 SQL（PDO prepared）。
- `personPoints`：只回該 `{id}` 的有效座標，無敏感欄位。
- mbtiles 放 `storage/app`（非 public），只能透過 tile 控制器逐磚取得，無法整檔下載。
- 下載來源 URL 由 config 固定（HuggingFace），不接受使用者參數，避免 SSRF。
- 遵守 AGENTS.md：AJAX URL 用 `route('name', [], false)` 相對路徑，避免 HTTPS mixed content。

---

## 9. 測試計畫

**Unit — `CoordinateValidator`**
- null/null、0/0、單軸 0、`1e-9` 近零 → 無效。
- 反掉（lat 值入 lon）落界外 → 無效。
- 界內正常點 → 有效；`SANE` 開關行為；邊界值（恰等於 bounds）。
- Web Mercator ±85.0511 邊界。

**Feature — `ChgisMapController`**
- `personPoints`：建測試人物，混入有效/無效座標，斷言只回有效點、`key`/`source` 正確、addresses 與 offices 都涵蓋。
- `tile`：以小型測試 mbtiles fixture（少量磚）驗 TMS y-flip、命中回 PNG、未命中回透明 PNG、缺檔回 503。
- `status`：缺檔回 `downloading` 並觸發 job（mock/fake queue）；就緒回 `ready`。

**View / 整合**
- addresses/offices index：有效座標渲染 `<a class="chgis-place-link">`、無效維持純文字（HTML 斷言）。
- offices 多地點：部分可連結、部分不可，`;` 分隔仍正確。

**前端**
- 手動驗證 modal 開關、Esc/遮罩關閉、置中與突顯、手機尺寸、底圖下載提示流程。

> 測試 mbtiles fixture 放 `tests/fixtures/`，數 KB 即可（手造一兩個 zoom 的磚）。

---

## 10. 實作階段拆分（建議 PR 切分）

| 階段 | 內容 | 產出 |
|------|------|------|
| P1 | `config/chgis_map.php` + `CoordinateValidator` + 單元測試 | 判定邏輯可獨立合併 |
| P2 | `cbdb:fetch-chgis-map` 指令 + `.gitignore` + 接 `deploy.sh` | 部署時能取得底圖 |
| P3 | `ChgisMapController::tile` + `status` + 路由 + lazy 下載 job + 測試 | 底圖可服務 |
| P4 | `personPoints` 端點 + offices `serialAddr` 重構 + 測試 | 點位資料可取得 |
| P5 | 兩頁 view 連結渲染 + i18n | 後端連結出現 |
| P6 | `chgis-map` 前端 entry（Leaflet npm、modal、UX、手機、下載提示）+ `npm run build` | 完整體驗 |
| P7 | 文件（README/CHANGELOG）、回歸測試、收尾 | 上線 |

---

## 11. 風險與未決問題

1. **mbtiles bounds 南界 −62 異常**：metadata 涵蓋範圍過寬，故引入可選 `SANE` 東亞收斂框；上線前宜以實際資料抽樣校準 `SANE` 數值。
2. **體積與首次下載時間**：15,715 磚，檔案可能數十～數百 MB；lazy 下載期間使用者等待。建議部署階段（P2）就先抓好，lazy 下載僅作後備。
3. **TMS y-flip**：最易出錯處；務必以 fixture 測試左上/右下磚對位。
4. **Leaflet 來源**：本功能改用 npm import；`historical-maps` 仍用 `window.L`，短期並存、不強迫一次收斂（避免擴大改動面）。
5. **offices 多地點連結**：同一 posting 可能串多個地名，需逐地名判定 linkable，UI 上避免把整串都變連結。
6. **座標反掉無法 100% 偵測**（§3.1）：少數落在重疊區間者保守接受，屬已知限制。
7. **HuggingFace 可用性**：下載失敗時功能優雅降級（顯示提示、可重試），不影響其他頁面。
8. **快取**：tile 回應加 `ETag`/`Cache-Control`，降低 mbtiles 讀取壓力；PDO 連線重用。

---

## 12. 影響檔案一覽（預估）

**新增**
- `config/chgis_map.php`
- `app/Support/CoordinateValidator.php`
- `app/Http/Controllers/ChgisMapController.php`
- `app/Console/Commands/FetchChgisMap.php`
- `app/Jobs/FetchChgisMapJob.php`
- `resources/js/chgis-map/app.js`（+ 樣式）
- 翻譯鍵：`resources/lang/zh-TW/chgis_map.php`、`resources/lang/en/chgis_map.php`
- 測試：`tests/Unit/CoordinateValidatorTest.php`、`tests/Feature/ChgisMapTest.php` + fixture

**修改**
- `routes/web.php`（3 路由）
- `app/Http/Controllers/BasicInformationOfficesController.php`（`serialAddr` 重構 / 並行方法）
- `resources/views/biogmains/addresses/index.blade.php:46`
- `resources/views/biogmains/offices/index.blade.php:46`
- `deploy.sh`（追加抓底圖步驟）
- `.gitignore`（`/storage/app/chgis/*.mbtiles`）
- `vite.config.js`（新 entry）
- `README.md` / `CHANGELOG.md`（收尾）
