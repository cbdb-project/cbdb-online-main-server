# AGENTS 指南

本文件提供 AI 代理在此專案工作的最小必要背景。目標是快速上手、降低踩坑，不再保留已失效的歷史操作細節。

## 專案現況
- 技術棧：Laravel 12、PHP 8.2+、MariaDB 10.11（prod 實測 10.11.14；相容性下限仍按 10.3 撰寫）、SQLite（測試）、Vite、Vue 3、Inertia/React。
- 全站主要互動頁面已遷移至 **React/Inertia 並翻 flag 上線**（`config/migration_flags.php` 頁面 flag 多為 `new`）：人物列表/檢視/詳情中樞、13 個 React 編輯器（basic-info + 12 個複合主鍵子資源）、Codes CRUD、operations/manage/crowdsourcing、admin 工具、認證頁、Query Playground（`/app/query-playground`）等。
- **舊版 Blade 視圖與 AdminLTE 3 + Bootstrap 4 仍實體保留，但已全面封路**（見 [docs/BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md](./docs/BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md)）：
  - **人物編輯全套（`basicinformation.*`）已於環節 2 實體刪除**——視圖、12 組子資源路由與 controller、
    `LegacyBladeFormGate`、以及那 15 個 `MIGRATION_FLAG_BASICINFO_*` flag 全部不存在了。
    舊 URI 只剩 302 導向（顯示頁）／410（寫入端）。**把 flag 塞回 config 不會復活它們**（有護欄測試鎖住）。
    唯三例外：`saveas`、`Duplicate_Collateral_Info`（React 編輯器正在呼叫）與 `destroy`。
  - **其餘頁面（`codes`／`view`／`operations`／`manage`／`crowdsourcing`／`admin.*`／`merge-preview`／
    `profile`／`dashboard`／`nl-query-logs`）已於環節 3 封路但未刪碼**：Blade 視圖與 controller 都還在，
    只是請求不再抵達 controller（顯示頁 302、legacy 寫入端 410）。逐條清單見
    [docs/BLADE_RETIREMENT_STAGE3_ROUTE_MANIFEST.md](./docs/BLADE_RETIREMENT_STAGE3_ROUTE_MANIFEST.md)。
  - 🔴 **回退鍵已經不是 migration flag**：這批頁面翻 `MIGRATION_FLAG_*=old` **沒有任何效果**
    （封路 middleware 不讀 flag）。要回退請設 **`LEGACY_PAGE_RETIREMENT=false`** 並
    `php artisan config:clear && php artisan config:cache`——不需重新部署、不需 git revert。
    對**已被 `legacy.page` 封路的那批頁面**而言，migration flag 現在只影響**連結指向**（側邊欄、payload 裡的 URL），不影響舊頁能否開啟；**`auth.*`／`welcome` 未封路**（flag 分支在 controller 內部），它們的 flag 仍然決定渲染 Blade 或 React（見 `tests/Feature/AuthPagesInertiaTest.php`）。
  - 少數頁面本就無 flag：Query Playground 主頁 `/query-playground` 硬導向 `/app/query-playground`；
    外部資料庫引用瀏覽器 `/external-db-link` 硬導向 `/app/external-db-link`（Blade 版已刪）。
  - AdminLTE 實體下架（環節 5）尚未執行。
  - **新功能一律只做在 React/Inertia 路徑（`resources/js/inertia/**`），不要再改舊 Blade。**
- 前端資源由 Vite 載入；React/Inertia 元件在 `resources/js/inertia/`。
- 使用者介面支援繁體中文／英文切換（預設 zh-TW），文件與 commit message 一律使用繁體中文。

## 關鍵原則

### 1. 資料庫相容性
- 所有 migration 必須同時兼容 MariaDB/MySQL 與 SQLite。
- 使用 `is_mysql()` / `is_sqlite()`，不要直接用 `DB::getDriverName()` 判斷。
- 優先使用 Laravel Schema Builder。
- 若必須寫原始 SQL，請移除 SQLite 不支援的語法，例如 `COMMENT`、`ENGINE`、`USING BTREE`。

### 1.1 外鍵一律 RESTRICT（去級聯已完成）
- 全庫 `ON DELETE CASCADE` 已於 2026-08 全數翻為 `RESTRICT`（唯一例外是本來就正確的
  `fk_merged_person_source`＝`SET NULL`）。**新 migration 建立外鍵一律用 `ON DELETE RESTRICT`
  ／`restrictOnDelete()`，禁止 `CASCADE`／`cascadeOnDelete()`**；`ON UPDATE CASCADE` 維持現狀。
- 連帶刪除改由應用層顯式執行（先子後父、父列僅在無剩餘引用時才刪、逐列寫 operations／audit，
  見 `App\Services\ExplicitCascadeLogger`）。刪除路徑撞到 errno 1451 要轉友好報錯，不可吞掉。
- 背景與執行紀錄：[docs/ON_DELETE_CASCADE_RISK.md](./docs/ON_DELETE_CASCADE_RISK.md)、
  [docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md](./docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md)；
  刪除功能重建設計見 [docs/DELETE_IMPACT_PREVIEW_PLAN.md](./docs/DELETE_IMPACT_PREVIEW_PLAN.md)。

### 1.2 稽核欄（c_created_*／c_modified_*）由系統蓋章
- 語義（2026-08-05 定案）：`c_modified_*`＝「最後一次實際寫入」——核准提案、還原記錄都是寫入，
  一律蓋當下，不從提案 payload／歷史快照沿用舊值；`c_created_*` 只在 create 蓋、之後永遠沿用。
- 署名一律經 [app/Support/AuditActor.php](./app/Support/AuditActor.php) 取得（核准期間為雙人名
  「審核人 (Proposed by: 提案人)」），**不要直接寫 `Auth::user()->name`**。
- 提案 payload／operation 快照裡的稽核欄是「審計事實」可保留；但任何寫入路徑（handler 重放、
  通用核准、restore）落庫前必須剔除或覆蓋，不可原樣回寫。

### 1.3 文本寫入一律經異體字落地替換
- 任何**會把文本寫進資料庫**的新路徑，落庫前必須經過
  [CharVariantMapService](./app/Services/CharVariantMapService.php) 的
  `replaceRow($data, $table)`（整列）或 `replaceFor($table, $column, $value)`（單值）。
- **範圍由欄位型別決定**（見 [VariantReplaceScope](./app/Support/VariantReplaceScope.php)）：
  呼叫端**不需**自己判斷「這欄有沒有中文」，也**不要**自己維護欄位清單。未知表 fail-closed
  （一律不替換），所以 `$table` 要傳**目標資料表**、永遠不要傳 `operations`。
- **模式不由呼叫端選，預設是寬鬆（全量規則）**。strict 是逐欄位的例外（人名／別名欄），
  **不要假設「人物相關的表就該用 strict」**——同一列裡姓名欄 strict、`c_notes` lenient 是刻意的。
- **掛鉤位置是硬性要求**：必須早於 **PK 計算、查重／去重鍵查詢、拼音派生、以及
  `operations.resource_id`／`audit_log.row_pk` 的組裝**。否則會出現「查重用替換前的值、
  落庫用替換後的值」，或稽核鏈指向一個不存在的鍵。
  已知的文本型 PK 成員：`ALTNAME_DATA.c_alt_name_chn`、`ASSOC_DATA.c_text_title`、
  `BIOG_SOURCE_DATA.c_pages`；已知去重鍵：`SOCIAL_INSTITUTION_NAME_CODES.c_inst_name_hz`；
  已知標籤→代碼鍵：`DYNASTIES.c_dynasty_chn`、`SOCIAL_INSTITUTION_TYPES.c_inst_type_hz`／`_py`、
  `NIAN_HAO.c_nianhao_chn`；已知由中文派生：`OFFICE_CODES`／`TEXT_CODES`／機構名的拼音欄。
- **精確比對必須處理「兩形並存」**：既有列在 D6 之下保留變體形、新列是參考形，所以查重／
  去重／重用查詢要**兩形都探**（或走
  [VariantEquivalentLookup](./app/Support/VariantEquivalentLookup.php)）。少了這道，替換會
  **製造**新的重複列，而唯一鍵擋不住（不同字形＝不同鍵值）——**那比完全不替換更糟**。
  改鍵時要排除「正在編輯的那一列自己」，而且**只在真的改鍵時檢查**（否則歷史上就已兩形並存的
  資料會變成任何更新都做不了）。
- **繼承既有基底類別就自動生效**（`AbstractPersonSubresourceCreateHandler`／
  `AbstractPersonSubresourceMutationHandler`／`AbstractCodeTableMutationHandler` 都掛了
  `Concerns\AppliesVariantReplacement`，且在 `handle()` 裡實際呼叫）；只有**不繼承**的新寫入
  路徑才需要自己掛。兩個常被忽略的邊界：
  - 繼承了基底、但**額外**自己寫副表／鏡像／聚合列的 handler，那些額外寫入**不在**基底掛鉤的
    覆蓋範圍內，要自己再掛一次（傳該副表的表名）。
  - `applyVariantReplacement($data)` 省略表名時取 `$this->tableName()`；**沒有那個方法的
    handler 必須顯式傳表名**，否則是 runtime fatal 而非靜態錯誤。
  機械化把關見 `tests/Unit/VariantReplaceHookCoverageTest.php`：繞過基底又沒登記例外時它會紅；
  清冊分成三本，**已經掛上 trait 的類別不可列進 EXEMPT／EXEMPT_DELEGATES**：
  「確實不需要掛」（`EXEMPT`，每筆要寫理由）、「寫入委派給下層」（`EXEMPT_DELEGATES`，要指名
  下層檔案與掛鉤數、會被機械檢查）、「繼承基底但自己另有寫入」（`SUBCLASS_EXTRA_WRITES`，
  只認**寫在自己檔案裡**的額外寫入；委派給下層方法的鏡像同步偵測不到，要人工判斷）。
  該測試同時逐檔記數鎖住 handler 體系之外的掛鉤點（controller／repository／import service），
  掛鉤變少也會紅。**但它不是全庫掃描**：在 `app/Services/Mutations` 以外新開一個檔案直接落庫，
  只有 code review 擋得住。
- **替換發生了就要讓使用者知道**：handler 內用 `withVariantNotices()` 把通知掛上回應，
  **成功、409、422 都要掛**——被擋下來時使用者更需要知道「我輸入的字被正規化了」。
  累積器在每次 `handle()` 開頭重置；基底一律用 `mergeReplaced()` 而非 assign（直接 assign 會把
  上游收集到的通知靜默吃掉）。
- **不該替換的地方一律不掛**（見 `VariantReplaceScope` 的三組排除清單）：
  - 本身做文本替換／字形對照的表（`char_variant_map` 自己替換自己等於自我吞噬）；
  - 語義上必須保留原字的欄位：稽核署名、URL、跨表 join／查表鍵、拉丁人名與拼音欄、
    唯讀派生索引（`CBDB__NAME_FTS`）、以及**紀錄類**表（`operations`／`audit_log`：
    快照的語義是「當時實際發生什麼」，改寫等於偽造紀錄）。
  - **新增任何對照／映射性質的表，或任何需保留原始字形的欄位時，必須同步加進排除清單。**
  - 同理：`restore`（還原歷史快照）與「只刪不寫」的分支**不做內容替換**；
    鏡像的**定位鍵**不可單邊歸一（會讓偵測永遠找不到、修復工具失去幂等）。
- **Unicode NFC 是另一回事，且已自動生效**：`CharVariantMapService` 的四個替換入口都會先做
  NFC 正規化（`App\Support\UnicodeNfc`），把**相容表意文字**折疊成統一表意文字
  （慎 U+FA87 → 慎 U+614E）。這與異體字替換性質不同——NFC 折疊的兩個碼位在 Unicode
  定義上**就是同一個字**（canonical equivalence），不是編輯判斷，故**不記進 `replaced`、
  不產生 notices**（字形一模一樣，列出來只是雜訊）。反過來 Unicode 從不折疊統一表意文字，
  所以 NFC **不會**碰 愼／峯／靑 那類異體字，兩套機制作用域不重疊。作用域沿用
  `VariantReplaceScope`（排除欄不做 NFC——代碼鍵單邊正規化會打斷關聯，理由同 D3）。
  **不要在呼叫端自己做 NFC**，也不要改用 NFKC（會抹掉全形／羅馬數字等有意義的區別）。
- **新增對照時要評估搜尋端**：該字的新舊資料會互相搜不到，需評估姓名搜尋與
  `CBDB__NAME_FTS` 的建索引。**不要去改 `VariantCharNormalizer`**——它做的是拼音派生，
  與落地替換是兩件事。
- 「不要再改舊 Blade」的**例外**：資料完整性規則（§1.1／§1.2／§1.3）適用於所有**仍在服役**的
  寫入路徑，不分新舊（例如 `saveas()`／`Duplicate_Collateral_Info()`／v1 token API／
  眾包回填都還活著）。只有已被閘門下架**且**有替代品的路徑才豁免。
- 背景與逐步執行紀錄：[docs/CHAR_VARIANT_MAP_TEXT_COLUMN_ROLLOUT_PLAN.md](./docs/CHAR_VARIANT_MAP_TEXT_COLUMN_ROLLOUT_PLAN.md)。

### 1.4 經緯度寫入一律經「空白／零值 → NULL」歸零
- `0,0` 不是東亞的任何地點，而是「沒有座標」被寫成了一個看起來合法的數字。讀取端早就這麼
  判（[CoordinateValidator](./app/Support/CoordinateValidator.php) 判 `zero_axis`／
  `non_numeric` 為不可連結），而且零值會**主動製造錯誤答案**：舊版 v1 的鄰近地點查詢是
  `ADDR_CODES.x_coord BETWEEN other.x_coord ± 0.03` 的自連接，所有 `0,0` 列因此互為
  「鄰居」（實測 316 列產生 99,856 對）。`ADDR_CODES` 那 316 列已於 2026-09-14 清理／回填。
- 任何**會把經緯度寫進資料庫**的新路徑，落庫前必須經過
  [CoordinatePairNormalizer](./app/Support/CoordinatePairNormalizer.php) 的
  `normalizeRow($data, $table)`。範圍由 `PAIRS` 登記決定，未登記的表 fail-closed（不處理），
  所以 `$table` 要傳**目標資料表**。**新增任何帶經緯度的資料表時，必須同步加進 `PAIRS`**
  （`tests/Unit/CoordinatePairRegistryGuardTest.php` 會在漏登記時紅）。
- **座標是一對，不是兩個獨立的欄**：一對之中任一軸空白或為零，整對都寫 `NULL`——**包含
  呼叫端沒送的那一軸**。只有一軸的座標對每個消費端都不可用。這件事會讓
  `result.updated_fields` 出現呼叫端沒送的欄位，是對外契約的一部分（見 API.md §13.1）。
- **掛鉤位置的硬約束與 §1.3 不同**：座標不是主鍵、不是查重鍵、不是拼音來源，所以「必須早於
  PK 計算」那一組在這裡是空的。取而代之的是**必須早於變更偵測**與 `CodeTableFieldValidator
  ::normalize()`。少了前者，`{x_coord: 0}` 打在座標已是 NULL 的列上會寫一次 `NULL` 覆蓋
  `NULL`——什麼都沒變，卻蓋掉 `c_modified_*`（違反 §1.2）並寫出 before／after 相同的
  `operations`／`audit_log`。
- **`create` 與 `update` 對「只送一軸」刻意不同**：`create` 走整列模式（payload 就是要
  insert 的完整列，缺席的那一軸必然是 NULL，所以半截對一併清空）；逐欄 `update` 不動
  （那一層看不到資料庫，另一軸可能本來就有值，擅自補寫會刪掉呼叫端沒提到的資料）。
- **繼承 `AbstractCodeTableMutationHandler`／`CodeTableCreateHandler` 就自動生效**
  （trait `Concerns\NormalizesCoordinatePairs`）。**人物子資源的基底沒有掛**——那些表沒有
  座標欄；哪天有了要自己掛。
- **非數值的值不歸零、交給驗證層回 422**（`"east"`、`"0e0"`、`"+40.5"`）。把它們靜默清成
  `NULL` 會把一個該報錯的請求變成「存成沒有座標」。**但這個前提只在 v2 API 上成立**：
  `CodesController` 與提案核准重放**都沒有**欄位驗證層，所以那兩條路自己呼叫
  `CoordinatePairNormalizer::invalidColumns()`——表單路徑回欄位錯誤、核准／還原路徑中止。
  新開的寫入路徑若沒有驗證層，必須比照辦理，否則 MariaDB 在非 strict sql_mode 下會把
  `"0e0"` 靜默轉成 `0`（實測）。
- **歸零發生了就要讓使用者知道**：v2 走 `withWriteNotices()`（成功／409／422 都要掛），
  表單與還原走 `flashCoordinateNotices*()`。`cleared_with_partner` 是唯一「系統丟掉了使用者
  真的填進去的值」的情形，絕不可以靜默。看得到原始列的呼叫端要用
  `dropNoOpCoordinateNotices()` 濾掉「沒送來、原值本來就是 NULL」的假損失。
- **`restore` 要歸零，這與 §1.3 對 restore 的豁免刻意不同。** §1.3 豁免 restore 是因為
  「歷史字形本身就是事實」，改寫還原內容是資訊的損失；`0,0` 不承載任何資訊，還原它是
  **重新武裝一個 bug**。而 restore 本來就不是位元級忠實重放——它已經會蓋掉 `c_modified_*`。
- 「零」的判定用**精確比較**（`(float) $v === 0.0`），刻意**不讀** `config('chgis_map.epsilon')`：
  那是 `CHGIS_MAP_EPSILON` 可調的讀取端容差，讓它驅動破壞性寫入等於把地圖調參變成刪資料的
  開關。兩邊的關係是**包含**（本類清空的 ⊂ validator 判無效的），由測試斷言而非共用旋鈕保證。
- 寫入端守衛只管新的寫入。上游重灌與新部署的地板靠
  `php artisan cbdb:normalize-addr-coords`（幂等，**每次上游匯入後都該跑**）與共用同一份
  邏輯的一次性 data migration（`2026_09_14_000000_normalize_zero_coordinates_in_addr_codes`）。
  那支清理刻意不寫 `operations`／`audit_log`、不蓋 `c_modified_*`——它是資料清理而非編輯行為。

### 2. 複合主鍵
- Laravel Eloquent 不支援複合主鍵。
- 複合主鍵表請使用 Query Builder，不要建立仰賴 Eloquent 主鍵行為的模型。
- 主鍵定義集中在 [app/Support/CompositePrimaryKey.php](./app/Support/CompositePrimaryKey.php)。
- 若修改子資源 API、URL 或 Person Browser `pk`，要同步檢查 `CompositePrimaryKey::SCHEMAS`、控制器與測試。

### 3. Query Playground
- 新功能只做在 React/Inertia 版。
- `/query-playground/*` API 仍為共用後端接口，修改時請同時檢查：
  - [app/Http/Controllers/QueryPlaygroundController.php](./app/Http/Controllers/QueryPlaygroundController.php)
  - [app/Services/QueryPlaygroundService.php](./app/Services/QueryPlaygroundService.php)
  - [app/Services/NaturalLanguageQueryService.php](./app/Services/NaturalLanguageQueryService.php)
  - [app/Services/SqlTableNameExtractor.php](./app/Services/SqlTableNameExtractor.php)
- 與 SQL allowlist、CTE、SSE、NL 工具調用有關的修改，必須補回歸測試。

### 4. 前端
- 所有頁面透過 `@vite` 載入資源。
- 不要重新引入外部 CDN 的 jQuery、Bootstrap、DataTables。
- 修改 `resources/js/**` 後，提交前需重新編譯前端。
- React 列表不要使用 index 當 key；若資料有複合主鍵，請使用穩定 `pk`。
- **必填欄位 create／update 一致**：若某欄位在新增（create）為必填，編輯（update）也必須維持必填——驗證邏輯與 UI 必填標記兩邊都要有，不可只擋 create。否則使用者在 update 時清空該欄仍能儲存，導致原本填好的資料被靜默清空。（驗證放在 `save()` 進入 create／update 分支之前，即可同時涵蓋兩者與 direct／proposal 四條路徑。）
- 頁面級內聯腳本若要對 `#app` 內的伺服器渲染節點綁定事件（`addEventListener`），必須包在 `onViteReady(function(){ ... })` 內。`app.js` 會在 DOM ready 時 `createApp(...).mount('#app')`，把整個 `#app`（layout 的 `<div class="wrapper" id="app">`）重新編譯並重建所有節點，掛載前直接綁定的監聽器會隨舊節點被丟棄而失效。`onViteReady` 的回呼在 mount 之後才執行，可確保綁在最終節點上。委託到 `document`/`window` 的監聽器與內聯 `onclick` 屬性不受影響。

### 6. i18n（繁體中文 / 英文切換）
- 系統預設語言為繁體中文（`zh-TW`），使用者可透過 navbar 切換至英文（`en`）。
- Blade 字串一律使用 `__('group.key')` 翻譯 helper；禁止在 Blade 中硬編碼中文字串。
- 翻譯檔：`resources/lang/zh-TW/*.php` 與 `resources/lang/en/*.php`；兩者必須同步。
- JS 字串透過 `{!! Js::from(__('group.key')) !!}` 注入；React/Inertia 元件從 `usePage().props.locale` 讀取 locale。
- Locale 切換由 `SetLocaleMiddleware`（`web` middleware group）處理，優先順序：session → cookie → Accept-Language。
- 測試環境中 Symfony 預設 `Accept-Language: en-us,en;q=0.5`；`TestCase::setUp()` 已覆蓋為 `zh-TW`，保持測試一致性。若個別測試需要英文語境，請用 `withSession(['locale' => 'en'])`。

### 5. 授權與安全
- 新路由必須有後端授權檢查，不能只靠前端隱藏。
- Query Playground 僅允許只讀 SQL，任何放寬都要先檢查白名單與測試。
- Blade 中若要產生前端 AJAX URL，優先使用相對路徑：`route('name', [], false)`，避免 HTTPS mixed content。

## 目前最常接觸的模組
- Query Playground / Historical QA：
  - [app/Http/Controllers/QueryPlaygroundController.php](./app/Http/Controllers/QueryPlaygroundController.php)
  - [app/Services/NaturalLanguageQueryService.php](./app/Services/NaturalLanguageQueryService.php)
  - [app/Services/Mcp/ReadOnlyTableQueryService.php](./app/Services/Mcp/ReadOnlyTableQueryService.php)
  - [tests/Feature/QueryPlaygroundTest.php](./tests/Feature/QueryPlaygroundTest.php)
  - [tests/Feature/HistoricalQaTest.php](./tests/Feature/HistoricalQaTest.php)
- Person Browser：
  - [app/Services/PersonBrowserService.php](./app/Services/PersonBrowserService.php)
  - [resources/js/inertia/components/PersonBrowser](./resources/js/inertia/components/PersonBrowser)
  - [tests/Feature/PersonBrowserTest.php](./tests/Feature/PersonBrowserTest.php)
- 複合主鍵子資源與 mutation：
  - `app/Http/Controllers/BasicInformation*Controller.php`
  - `app/Services/Mutations/*`
  - `tests/Feature/ApiV2Mutate*Test.php`
- Operations / Restore：
  - [app/Http/Controllers/OperationsController.php](./app/Http/Controllers/OperationsController.php)
  - [app/Repositories/OperationRepository.php](./app/Repositories/OperationRepository.php)
- CHGIS 地圖（Place Name 連結與浮出地圖）：
  - [app/Http/Controllers/ChgisMapController.php](./app/Http/Controllers/ChgisMapController.php)（tile/status/personPoints）
  - [app/Services/PersonMapPointsService.php](./app/Services/PersonMapPointsService.php)、[app/Services/ChgisMapManager.php](./app/Services/ChgisMapManager.php)、[app/Support/CoordinateValidator.php](./app/Support/CoordinateValidator.php)
  - [resources/js/chgis-map](./resources/js/chgis-map)、[config/chgis_map.php](./config/chgis_map.php)
  - 底圖 `storage/app/chgis/chgis_map.mbtiles`（不入版控，`php artisan cbdb:fetch-chgis-map` 下載）；設計見 [docs/CHGIS_MAP_PLACE_LINK.md](./docs/CHGIS_MAP_PLACE_LINK.md)

## 常用命令

```bash
composer install
npm install
./vendor/bin/php-cs-fixer fix
./vendor/bin/phpunit
./vendor/bin/phpunit --filter TestName
npm run build
php artisan cbdb:manage-user
php artisan cbdb:sync-opencc-trad-simp   # 更新 vendored third_party/opencc/TSCharacters.txt，需 git diff 審查後提交
php artisan cbdb:fetch-chgis-map        # 下載 CHGIS 底圖（缺檔才下載）
```

## 提交前最低檢查
- `git diff` 只包含預期改動。
- PHP 代碼已格式化：`./vendor/bin/php-cs-fixer fix`
- 跑過受影響測試；若改動面大，跑 `./vendor/bin/phpunit`
- 改了前端資源時，跑 `npm run build`
- **改了 API（路由／請求回應欄位／授權／錯誤碼／resource 別名或白名單）時，同步更新 `API.md`**（見「文檔維護原則」）
- **新增或修改「把文本寫進資料庫」的路徑時，確認已掛上異體字落地替換**（§1.3；繼承既有基底類別即自動生效，不繼承的要自己掛；只有「確實不需要掛」或「寫入委派給下層」才登記進 `tests/Unit/VariantReplaceHookCoverageTest.php` 的例外清冊，並跑該測試確認沒紅）
- **新增或修改「把經緯度寫進資料庫」的路徑時，確認已掛上座標歸零**（§1.4；繼承兩個代碼表 handler 即自動生效，其他路徑要自己掛；沒有欄位驗證層的路徑還要自己呼叫 `invalidColumns()`。新增帶經緯度的資料表要同步加進 `CoordinatePairNormalizer::PAIRS`，並跑 `tests/Unit/CoordinatePairRegistryGuardTest.php` 確認沒紅）
- commit message 使用繁體中文

## 高風險區域備忘
- `ALTNAME_DATA` 現行主鍵是 3-key，不含 `c_sequence`。
- `POSTED_TO_ADDR_DATA` 的 `resource_id` 會沿用 `POSTED_TO_OFFICE_DATA` 格式，地址明細存於 `resource_data['rows']`。
- 與時間欄位有關的修改，請注意 `DB_TIMEZONE` 必須與 `APP_TIMEZONE` 對齊；資料庫時區使用數字偏移，例如 `+08:00`。
- 若測試需自行建表，請補齊必要主鍵、nullable、timestamps；很多回歸都來自測試表結構過度簡化。
- `app/codes/{table_name}`（`CodesController@appShow`）帶 `sort_by`／`filters[...]` 時需登入且 `Auth::user()->isActive()`（見 `guardSortFilterRequiresAuth()`）；**Blade 版 `codes/{table_name}`（`show()`）未同步處理**。
  🔴 **唯一能重新暴露它的是 `LEGACY_PAGE_RETIREMENT=false`**（全站 kill switch）：該 Blade 路由掛 `legacy.page:app.codes.show`、一律 302 導向 React 版，而封路 middleware 不讀 flag，所以翻 `MIGRATION_FLAG_CODES=old` **沒有任何效果**（最容易犯的錯，故明列）。**動用 kill switch 回退時必須把「無門檻深分頁排序查詢重新暴露」算進代價。**
  詳見 [docs/CODES_SORT_FILTER_AUTH_GATE.md](./docs/CODES_SORT_FILTER_AUTH_GATE.md)。

## 文檔維護原則
- `AGENTS.md` 只保留目前有效的規則與入口，不記錄已淘汰的歷史流程。
- `CHANGELOG.md` 只記錄近階段高價值變更與方向，不再維護超長流水帳。
- 重大 UI / 流程變更時，同步更新 `README.md`、`CHANGELOG.md`。
- **API 改動必須同步 [API.md](./API.md)**：`API.md` 是唯一對外的 API 文檔（外部協作者／眾包提交者照它實作）。
  只要動到下列任一項，就必須在同一個 commit／PR 內更新 `API.md`，不得延後：
  - 路由（新增／改名／下架端點，或改變 HTTP 方法、middleware、CSRF 豁免、限流）
  - 請求或回應的欄位、結構、預設值（含 `mode` / `operation` / `target.pk` / `changes` / `meta` 語義）
  - 授權與角色行為（`canWriteDirectly` / `canPropose` / 帳號啟用檢查、token abilities）
  - 錯誤碼、`errors` 鍵值、訊息語義
  - `resource` 別名、支援的 operation 組合、欄位白名單、必填與哨兵值規則
  - 提案（proposal）流程與審核狀態語義
  若同時影響機器可讀規格，`docs/openapi/openapi.yaml` 也要一起更新。

## 相關文檔
- [README.md](./README.md)
- [CHANGELOG.md](./CHANGELOG.md)
- [API.md](./API.md)（對外 API 文檔：v2 讀取／寫入端與舊版 v1；改 API 必須同步）
- [DATABASE.md](./DATABASE.md)
- [docs/APPROVAL_FLOWS.md](./docs/APPROVAL_FLOWS.md)
- [docs/PERSON_PROPOSAL_PATHS.md](./docs/PERSON_PROPOSAL_PATHS.md)（人物提案：三條核准路徑與逐資源現況；改動核准行為前必讀）
- [docs/ZHWIKI_SOURCE_SYNC.md](./docs/ZHWIKI_SOURCE_SYNC.md)（中文維基連結增量維護：走 mutation API，勿用 WikiMaintenanceController 全量重灌）
- [docs/VIEWS.md](./docs/VIEWS.md)
- [docs/POSTING_OFFICE.md](./docs/POSTING_OFFICE.md)
- [docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md](./docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md)

## AI / Claude skills
- `.claude/skills/database-schema.md`
- `.claude/skills/mutation-api-record-editing.md`
- `.claude/skills/migration-guide.md`
- `.claude/skills/pre-commit-checks.md`
- `.claude/skills/testing-guide.md`
- `.claude/skills/commit-messages/SKILL.md`
- `.claude/skills/frontend-event-handler-debugging.md`
