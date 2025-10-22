# CODES 表格前端實作比較

## 總覽
- 目前存在兩套前端呈現「代碼表」的做法：一套是泛用型的 `/codes/{table}` 介面（`resources/views/codes/*.blade.php`），另一套是為特定資料表量身打造的頁面（如 `/altnamecodes`、`/addresscodes` 等，搭配 Vue 元件）。
- 兩者在資料流、權限控制、搜尋體驗與維護成本上有明顯差異。以下整理程式碼現況與既往討論中提出的推測，供後續重構或調整參考。

## 泛用 `/codes/*` 介面
- **資料來源與流程**：`CodesController` 直接根據網址中的 `table_name` 使用 `DB::table($table_name)` 查詢（`app/Http/Controllers/CodesController.php`），前端採 Blade server-render，列表與 CRUD 表單都在同一套模板內（例如 `resources/views/codes/show.blade.php`）。
- **欄位推斷**：頁面首次載入時會從第一列資料中挑出包含 `name`、`desc`、`code` 等字串的欄位當作表頭；若推斷不到則退回 `Schema::getColumnListing()`。
- **分頁機制**：使用 Laravel 內建分頁器，預設每頁 20 筆，可於 `config('codes.per_page')` 調整（若未定義則 fallback 20）。
- **操作流程**：新增、編輯、刪除均由同一控制器完成，路徑為 `/codes/{table}/{id}/...`。主鍵拆解使用 `_._` 連結前兩個欄位的方式猜測複合鍵。
- **優點**：開發維護成本低、可以快速覆蓋多張 *_CODES 表，不需為每張表寫一個 controller/view。
- **搜尋功能**：2025 年新增 `?search=` query 支援，可在頁面頂部篩選表格列，底層會對推斷出的欄位下 `LIKE` 條件並保留原本的分頁。搜尋框值會帶入 `appends()`，翻頁時不會遺失條件。
- **操作紀錄**：`store`、`update`、`destroy` 現已呼叫 `OperationRepository`，將 CRUD 操作寫入 `operations` 表，內容含原始列資料與合併後值，與 `/altnamecodes` 等專用頁面保持一致。
- **既知問題**（依先前分析與實測）：
  - 欄位自動推斷不一定準確，表頭順序可能與實際需求不符。
  - `create`/`store` 尚缺少針對各表的欄位驗證與提示訊息，易出現不合規輸入。

## 專用 `/altnamecodes` 等介面
- **資料來源與流程**：各表有對應的 Controller（例如 `AltnameCodesController`、`AddressCodesController`），後端多半使用 Repository 讀取資料（如 `App\Repositories\AltCodeRepository`）。部分控制器在 `update`/`store` 時會寫入 `OperationRepository` 產生操作紀錄，流程較嚴謹。
- **前端結構**：頁面載入 Vue 元件（例如 `resources/assets/js/components/AltnameCodeList.vue`），透過 `/api/*` 路由進行 AJAX 查詢，自帶搜尋、分頁（一次載入 7 個頁碼索引）、條件延遲查詢等互動功能。
- **欄位呈現**：列表欄位、表單輸入欄位由各自模板硬編碼，能依資料表自訂顯示順序、欄位名稱與額外說明。
- **分頁與搜尋體驗**：Vue 元件會在使用者輸入時透過 debounce 呼叫 API；頁碼切換由前端控制 `current_page` 再觸發重查。這類頁面提供即時搜尋與編輯捷徑。
- **既知問題**：
  - `/altnamecodes` 前端無法直接跳至「最後一頁」的需求仍在，推測與 Vue 元件的 `showLast` 判斷或 API 回傳資訊受限有關（僅能逐頁遞增頁碼）。
  - 頁面分散於多個 Controller/Repository，若要更新共通功能（例如權限、欄位說明），需逐一修改，維護成本高。
  - 前後端耦合度高：Vue 元件假設 API 回傳含 `names.total`、`names.data` 等欄位，若後端格式調整需同步更新前端。

## 差異摘要
- **介面型態**：`/codes/*` 是 Blade server-render 表格；`/altnamecodes` 系列為 Vue + API 單頁互動列表。
- **表格範圍**：`/codes/*` 以 URL 動態選表，可快速覆蓋多張 *_CODES 表；專用介面則固定對應單一資料表。
- **搜尋／分頁能力**：泛用介面已提供單欄位關鍵字搜尋並保留翻頁；專用介面另有 debounce、複合條件與自訂頁碼等互動功能。
- **安全與權限**：專用介面經由 Laravel `Route::resource` 定義，僅暴露核准的操作；`/codes/*` 的授權則由共用的 controller 處理，並於操作失敗時提供明確訊息。
- **維護成本**：泛用介面一處改，多處受益；專用介面則能加入針對性驗證（例如地址代碼 ID 需唯一）與操作紀錄，但相對分散。

## 為何同時存在兩套？（推測）
- `/codes/*` 是較早期實作，快速提供所有 *_CODES 表的 CRUD 能力，但缺少搜尋與互動式篩選。
- 後續新加入的開發團隊為幾個使用頻率高、需求明確的表格（如別名、地址、官職）補上 Vue + API 的專用頁面，主要目標是補齊即時搜尋與較好的頁面體驗。
- 因為泛用介面仍能覆蓋大量低頻表格，而專用頁面只服務特定表，目前兩套實作並存；近期討論重點在於如何統整欄位呈現與搜尋體驗，評估是否把專用功能帶回泛用介面或讓專用頁面共用同一後端。

## 建議方向（供後續討論）
- 下一步評估在 `/codes/*` 介面提供搜尋功能；若體驗達標，考慮統一保留這套實作並逐步淘汰專用頁面。
- 若要統一介面，可考慮將 Vue 搜尋元件改寫為可配置版本，讓 `/codes/*` 也能載入；或將 `/codes/*` 的 Blade 表格提供 API，讓現有專用頁面共用資料來源。
- 在決定重構前，清點哪些專用頁面仍仰賴特殊驗證／操作紀錄，避免新架構缺少關鍵檢查。
