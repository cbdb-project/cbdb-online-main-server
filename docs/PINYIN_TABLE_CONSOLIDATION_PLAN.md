# 拼音資料整併 Work Plan：`pinyin` 表吸收 `Pinyin.php`

## 背景與目標

目前拼音轉換由兩個資料來源組合而成：

1. **`pinyin` 資料表**（`database/migrations/2025_01_01_000000_import_cbdb_schema.php:2342-2347`）：524 筆姓氏（含複姓）中文→拼音對照，欄位 `id`, `lastname_chn`, `lastname_pinyin`，**無索引**（僅主鍵）。供 `BiogMainRepository::auto_pinyin()` 以「最長前綴優先」拆姓氏使用。
2. **`app/Models/Pinyin.php`**：靜態陣列 `private static $dic`，約 6910 筆去重後的單字→拼音字典（人名的名字部分、書名、職官名、機構名等逐字轉換都用它），查無此字回傳原字元。

目標：把兩者合併進同一張 `pinyin` 表，欄位改為：

```
id        bigint PK auto_increment
chn       varchar(10) not null   -- 原 lastname_chn
pinyin    varchar(30)   -- 原 lastname_pinyin
lastname  tinyint(1) not null default 0   -- 1=姓氏讀音，0=一般讀音；合併後多數列是一般字典（default 0 更符合實際分布），既有 524 筆姓氏資料在 migration 內顯式 UPDATE 回填為 1
唯一鍵 (chn, lastname)
索引 chn
```

`chn` 收斂為 `NOT NULL`：舊欄位 `lastname_chn` 雖宣告 `DEFAULT NULL`，但唯一鍵若允許 `NULL` 會被資料庫的「NULL 不參與唯一性比較」語意稀釋（多筆 NULL 可以共存，等於唯一鍵形同虛設）。同上，建立此約束前需先確認既有 524 筆資料沒有 NULL/空字串 `chn`，若有需先中止 migration 讓維運者處理。

**唯一鍵設計理由**：同一個字作姓氏與作一般字可能讀音不同（如「單」姓 shàn vs 一般 dān；「秘」姓 bì vs 一般 mì）。用複合唯一鍵 `(chn, lastname)` 讓兩種讀音並存，查詢時依情境（是否在拆姓氏）指定 `lastname` 篩選，不會互相覆蓋。

合併後 `Pinyin.php` **整個刪除**，改由新的 `App\Services\PinyinDictionary` 服務類提供查詢介面，內部整表快取進記憶體（資料量約 7400 筆，可負擔），避免每次查詢都打 DB（尤其書名/機構名逐字轉換若不快取會有 N+1 問題）。

## 不在本次範圍內

- 異體字（`VariantCharNormalizer`）與繁簡轉換（`CBDB__TRAD_SIMP_MAP`）機制**不動**，本計畫只處理拼音資料合併。這三者已在另一份調查中記錄，留待後續評估。
- `PinyinUmlaut`（v→ü 後處理）、`PinyinSearchNormalizer`（搜尋展開）、`PinyinMigrationPlanner`（歷史資料修復工具）維持現狀，不受影響——它們是後處理層，與資料來源無關。

## 現況盤點（供實作對照，避免遺漏呼叫點）

**`Pinyin::getPinyin()` 呼叫點（7 處，5 個檔案）**：
- `app/Repositories/BiogMainRepository.php:4033,4044`（`auto_pinyin()` 內，名字部分逐字轉換，可能是多字字串）
- `app/Http/Controllers/ApiController.php:637`（`buildPinyinWord()`）
- `app/Http/Controllers/AdminBatchLoadBookTitlesController.php:494,546`（書名逐字轉換、`collectUnpinyinableHan()` 檢查未對應字）
- `app/Http/Controllers/AdminBatchLoadOfficesController.php:329`
- `app/Http/Controllers/AdminBatchLoadSocialInstitutesController.php:464`

**`DB::table('pinyin')` 呼叫點（1 處）**：
- `app/Repositories/BiogMainRepository.php:4009-4013`（`auto_pinyin()`，`whereIn('lastname_chn', $prefixes)` 批次查詢，非逐字，本身沒有 N+1 問題）

**直接建立/操作 `pinyin` 表 schema 的測試（需同步改欄位名，詳細影響見步驟 4）**：
- `tests/Feature/ApiSearchPinyinTest.php`（`Schema::create('pinyin', …)` 多處 + 多筆 insert；含 `split=0` 一般字典轉換案例）
- `tests/Feature/ApiV2CreateBiogMainTest.php:136-145`
- `tests/Feature/BiogMainProposalTest.php:88-99,349-351`
- `tests/Unit/AutoPinyinTest.php:34-45`

**目前完全沒有建 `pinyin` 表、但改用 DB 後需要新增建表的測試**（現在靠 `Pinyin::$dic` 靜態陣列撐住，不需要 DB；詳細影響見步驟 4）：
- `tests/Feature/AdminBatchLoadBookTitlesTest.php`
- `tests/Feature/AdminBatchLoadOfficesTest.php`
- `tests/Feature/AdminBatchLoadSocialInstitutesTest.php`

**非 controller 的間接消費者**：
- `app/Console/Commands/MigratePinyinV.php:78` 透過 `BiogMainRepository::auto_pinyin()` 重新生成人名拼音（不是新的呼叫點，走既有的 `auto_pinyin()` 路徑），但現有 `tests/Feature/MigratePinyinVCommandTest.php` 只涵蓋 altname 分支，沒有測試覆蓋這條「重生 BIOG_MAIN 拼音」路徑。步驟 4 全量跑測試不會涵蓋到這支指令的實際行為，建議合併前額外手動跑一次 `php artisan cbdb:migrate-pinyin-v`（dry-run，不加 `--execute`）針對一小批既有資料抽樣驗證輸出沒有異常，作為自動化測試之外的補充驗證。

**與 `pinyin` 同名但無關、不要誤觸的地方**：
- `tests/Feature/AdminBatchLoadBookTitlesTest.php` 裡的 `'pinyin' => '...'` 是**表單欄位名稱**（使用者手動輸入書名拼音的 request key），跟 `pinyin` 資料表無關，不需改動。
- `config/codes.php:91` 的 `'pinyin' => '姓氏拼音對照表'` 是 Codes 後台清單的表格中文說明——`CodesController` 對一般表格是 schema-driven（`Schema::getColumnListing($table)`），改欄位名不需要改程式碼，但這行說明文字建議同步更新措辭（見步驟 5），因為表格性質已從「純姓氏對照」變成「姓氏＋一般字混合字典」。
- `pinyin` 未列在 `CompositePrimaryKey::SCHEMAS`、`config/codes.php` 的 `export_columns` 也沒有此表的白名單設定，因此 Codes CRUD／匯出邏輯不受影響。

**`getShortPinyin()` 方法**：全專案沒有任何呼叫點使用它，重寫時可以不用保留。

## 實作步驟（每步完成後跑 review 機制才能進下一步）

> Review 機制：每個步驟完成後，先派一組會讀程式碼與讀改動的 review agent 檢查，直到沒有嚴重 issue；再用 `codex exec --dangerously-bypass-approvals-and-sandbox`（PowerShell + `Write-Output "..." |` 管道傳 prompt + 代理環境變數）做第二輪檢查，直到沒有嚴重 issue，才進下一步。

### 步驟 0：本 work plan 文件本身

先過 review 機制（review agent + codex）確認計畫本身沒有遺漏呼叫點、schema 設計沒有明顯問題，再開始動代碼。

### 步驟 1：Migration — 調整 `pinyin` 表結構

新增一個 migration（class-based，跟隨 `2026_04_22_000000_set_posted_to_office_data_c_appt_code_not_null.php` 的風格：`is_mysql()` 判斷、有意義的 `up()`/`down()` 註解）：

1. **建立索引/約束前，先做兩項資料完整性檢查，任一項不通過就中止 migration 並印出明確錯誤訊息**（現有表只有主鍵 `id`，`lastname_chn` 上完全沒有任何約束，production 實際資料狀況在 repo 內看不到，不能假設乾淨）：
   - 檢查 NULL/空字串：`SELECT COUNT(*) FROM pinyin WHERE lastname_chn IS NULL OR lastname_chn = ''`，>0 則中止。
   - 檢查重複：`SELECT lastname_chn, COUNT(*) FROM pinyin GROUP BY lastname_chn HAVING COUNT(*) > 1`（此時所有既有列都還沒加 `lastname` 欄位，是單欄查重即可），>0 則列出重複值並中止。
2. `renameColumn('lastname_chn', 'chn')`、`renameColumn('lastname_pinyin', 'pinyin')`，並把 `chn` 欄位改為 `NOT NULL`（通過第 1 項檢查後才安全）。
3. 新增欄位 `lastname` tinyint(1) not null default `0`（**預設值設為 0，不是 1**：合併後的表裡絕大多數列會是一般字典資料〔6910 筆〕而非姓氏〔524 筆〕，default 0 更貼近實際資料分布、語意更自然）。
4. 顯式執行 `UPDATE pinyin SET lastname = 1`（此時表內只有既有的 524 筆姓氏資料，尚未匯入一般字典，所以直接整表更新為 1 是安全且準確的，不需要 WHERE 條件）。
5. 建立複合唯一索引 `(chn, lastname)`（第 1 項檢查已確保此刻不會有重複衝突）、及 `chn` 一般索引（供前綴查詢）。
6. `down()` 反向：移除索引、移除 `lastname` 欄位、`chn` 欄位改回 nullable、改回舊欄位名。
7. 需同時兼容 MySQL 與 SQLite（`is_mysql()`/`is_sqlite()`），比照現有 migration 慣例。

此步驟只動 schema，不影響任何現有查詢（呼叫點尚未切換，仍用舊欄位名——**因此這一步完成後、下一步開始前，`auto_pinyin()` 會因欄位名不存在而報錯**，所以步驟 1 與步驟 4（呼叫點切換）需要在同一個 review 循環內原子完成，不能單獨合併到 develop。實務上步驟 1-4 會在同一個 PR 內依序完成、依序過 review，最後一起合併，避免中間狀態被誤合併上線。

**部署順序風險**：這個專案是單機部署（見 `deploy.sh`：composer/npm/快取重建為主，沒有藍綠或滾動部署機制；`deploy.sh` 本身不含 `php artisan migrate`，migration 依目前流程是人工在部署前後另外執行），migration 與程式碼是同一輪人工部署流程內一起套用，不存在「舊代碼對新 schema」或「新代碼對舊 schema」互相跨越多台機器同時存在的情境。這跟專案裡既有的欄位改名先例（如 `2026_01_22_192118_rename_entry_data_columns.php`）風險等級一致——都是採「migration 與呼叫點改動同批次上線」，沒有做 expand/contract（先雙寫雙讀、再切換、最後清舊欄位）的多階段設計。若之後這個專案改成多機/滾動部署，才需要重新評估是否要把這裡也改成 expand/contract；以目前的部署模型，本計畫的做法與既有慣例一致，風險可接受。）

### 步驟 2：抽出字典資料 + 一次性資料 migration

1. 在 `app/Models/Pinyin.php` 暫時新增一個 `public static function dictionaryForMigration(): array { return self::$dic; }`（僅供本步驟的資料匯出使用，步驟 5 會連同整個檔案一起刪除）。
2. 寫一個小工具腳本（或臨時 artisan command）呼叫這個方法，把結果 dump 成 `database/data/pinyin_dictionary.php`（`return [...]` 純資料檔，約 6910 筆，`chn => pinyin`）。這樣可以避免用正則表達式解析原始碼造成的重複鍵誤差（已驗證原始碼字面上有 31 個重複鍵，但 PHP 陣列語意下最終只有 6910 個唯一鍵——必須用「執行 PHP 拿到運行時陣列」的方式匯出，不能用文字解析）。
3. 新增一個 migration，`require` 這個資料檔，把 6910 筆以 `lastname=0` **分批**（如每批 500 筆）`insert` 進 `pinyin` 表（SQLite 對單一語句的參數數量有限制，MySQL 也建議分批以控制單一交易大小）。
4. `down()` 對應刪除 `where('lastname', 0)` 的資料列（保留 `lastname=1` 的既有姓氏資料）。
5. `database/data/pinyin_dictionary.php` 這個資料檔**永久保留**（不像暫時的 accessor 方法），因為未來任何全新環境跑 `php artisan migrate` 都要能重建完整字典資料，不能只依賴一次性手動指令。

### 步驟 3：新增 `PinyinDictionary` 服務類

新增 `app/Services/PinyinDictionary.php`，靜態介面比照舊 `Pinyin::getPinyin()`：

- `public static function getPinyin(string $string): string`：逐字查表，串接每個字的拼音，查無此字回傳原字元（完全比照 `Pinyin::chineseToPinyin()` 現有行為），**只查 `lastname=0` 的一般字典**。
- 內部用靜態變數快取整表 `lastname=0` 的資料（`chn => pinyin`），同一個 PHP request 生命週期內只查一次 DB，之後全部是記憶體查表，效能等同原本的 `Pinyin::$dic`。
- 提供 `reset()` 方法供測試清除靜態快取（比照 `VariantCharNormalizer::reset()` 的模式）。
- 新增 `tests/Unit/PinyinDictionaryTest.php`，用 SQLite 記憶體表塞入幾筆已知資料，驗證：命中、未命中回傳原字、多字字串正確逐字串接、`lastname=1` 的資料不會被一般字典查詢誤用。

此步驟先讓新類與舊 `Pinyin.php` **並存**（互不影響），降低單一步驟風險。

### 步驟 4：切換呼叫點 + 更新測試

1. 把 7 個呼叫點的 `use App\Models\Pinyin;` 改成 `use App\Services\PinyinDictionary;`，呼叫改成 `PinyinDictionary::getPinyin(...)`（5 個檔案：`BiogMainRepository.php`、`ApiController.php`、`AdminBatchLoadBookTitlesController.php`、`AdminBatchLoadOfficesController.php`、`AdminBatchLoadSocialInstitutesController.php`）。
2. `BiogMainRepository::auto_pinyin()` 的姓氏查詢（`app/Repositories/BiogMainRepository.php:4009-4013`）：
   - 欄位名改為 `chn`/`pinyin`。
   - 加上 `->where('lastname', 1)` 篩選，避免誤撈到一般字典裡剛好前綴相符的資料列。

3. **測試影響面比初版評估的更廣，需要修正**：切換到 DB 查詢後，任何測試若實際觸發「一般字典」查詢路徑（不只是拆姓氏），就需要 `pinyin` 表裡有 `lastname=0` 的對應資料，否則查無此字會回傳原字元、跟現行用 `Pinyin::$dic` 的行為不一致，測試斷言會壞掉。逐一盤點：

   - `tests/Feature/ApiSearchPinyinTest.php`——**不是**單純姓氏查詢：`split=0`（見該檔案約 184-210 行）直接測「不拆姓氏，純轉換」路徑，會經過一般字典查詢，需要補上這些案例實際用到的字的 `lastname=0` 資料。另外第 24 行目前把 `lastname_chn` 宣告為單欄 `->primary()`，比新設計的複合唯一鍵更嚴格，需鬆綁（例如改成一般欄位，或改用 `unique(['chn','lastname'])`）。
   - `tests/Unit/AutoPinyinTest.php`、`tests/Feature/ApiV2CreateBiogMainTest.php`、`tests/Feature/BiogMainProposalTest.php`——這三個雖然主要測「拆姓氏」，但拆完姓氏後名字（mingzi）部分一樣會呼叫 `PinyinDictionary::getPinyin()` 查一般字典（現行是靠 `Pinyin::$dic` 撐住），如果只補姓氏表資料（`lastname=1`），名字部分的字查不到會回傳原字元，可能讓 `c_mingzi`/`c_name` 斷言跟現在的結果不一致。這幾個檔案除了改欄位名、補 `lastname=1` 姓氏資料外，也要補上測試資料裡「名字」用到的字的 `lastname=0` 資料。
   - `tests/Feature/AdminBatchLoadBookTitlesTest.php`、`tests/Feature/AdminBatchLoadOfficesTest.php`、`tests/Feature/AdminBatchLoadSocialInstitutesTest.php`——**初版計畫完全漏掉這三個**。這三組測試目前完全沒有建立 `pinyin` 表（因為現在全靠 `Pinyin::$dic` 靜態陣列，不需要 DB），但對應的 controller 會逐字呼叫 `getPinyin()` 生成書名/職官/機構名拼音並斷言結果。改用 DB 後，這三組測試也需要建立 `pinyin` 表並塞入測試資料實際用到的字。

   **這暴露一個規模問題**：如果每個測試都要自己列舉「這次案例用到哪些字」去手動塞 `lastname=0` 資料，工作量大且容易漏字（例如書名測試可能涵蓋幾十個不同的字）。與其逐一盤點每個測試用到哪些字，改採以下做法：
   - 新增一個共用測試輔助（例如 `tests/Concerns/SeedsPinyinDictionary.php` trait，或 `TestCase` 的 protected method），內部 `require database/data/pinyin_dictionary.php`（步驟 2 產生、永久保留的資料檔）並批次塞入整份 ~6910 筆一般字典資料（`lastname=0`）到當前測試的 SQLite 記憶體 `pinyin` 表。
   - 任何測試若需要「真實拼音轉換結果與現行行為一致」（上述 7 個檔案都屬此類），在其 `setUp()`／建表之後呼叫這個輔助方法即可獲得與現行 `Pinyin::$dic` 完全相同的覆蓋範圍，不需要逐字盤點，也不會因為漏補某個字而讓斷言意外改變行為。
   - 純粹測「姓氏拆分邏輯本身」而不關心名字部分實際拼出什麼的測試（如果有這種案例），可以只塞姓氏資料、不呼叫這個輔助，跑起來會更快。
   - 這個輔助方法本身也需要有一個小測試（或在 `PinyinDictionaryTest.php` 裡順便驗證）確認它塞入的筆數與 `database/data/pinyin_dictionary.php` 筆數一致，避免輔助方法本身跟資料檔不同步。

4. 跑 `./vendor/bin/phpunit`（全量，因為呼叫點橫跨多個 controller/repository，影響面廣，不只 filter 特定測試）。

### 步驟 5：刪除 `Pinyin.php`、收尾

1. 刪除 `app/Models/Pinyin.php`（含步驟 2 加的臨時 accessor，一併刪除）。
2. 全域搜尋確認沒有殘留的 `App\Models\Pinyin` 引用。
3. `config/codes.php:91` 的表格說明文字，由「姓氏拼音對照表」改為更準確的措辭（如「姓氏／單字拼音對照表」），反映表格現在同時承載姓氏與一般字典兩種資料。
4. 視需要更新 `AGENTS.md`/`DATABASE.md` 是否要記錄這張表的新用途（目前兩份文件都沒有提到 `pinyin` 表，可視情況補充一小段，非強制）。
5. `npm run build`（若有前端 Codes 頁面快取欄位標籤，確認無殘留）、`./vendor/bin/phpunit`（全量）、`./vendor/bin/php-cs-fixer fix`。

## 風險與注意事項

- **步驟 1-4 必須在同一個 PR 內完成才能合併**：欄位重新命名後，若呼叫點沒有同步更新會直接讓 `auto_pinyin()`、書名/職官/機構批次匯入全部報錯（`pinyin`/`chn` 欄位不存在）。不要把步驟 1 單獨合併上線。此風險等級與專案既有的欄位改名先例一致（見步驟 1 的「部署順序風險」說明），不需要額外的 expand/contract 設計。
- **資料 migration 的分批 insert**：SQLite 單一 prepared statement 的參數上限（預設 999）需要留意，`chn`+`pinyin`+`lastname` 三欄 × 每批筆數不要超過限制，建議每批 300-500 筆保守處理。
- **`(chn, lastname)` 唯一鍵衝突**：步驟 1 已在建索引前對既有 524 筆資料做 NULL/重複檢查並全部回填 `lastname=1`；步驟 2 匯入的 6910 筆一般字典全部是 `lastname=0`。兩批資料的 `lastname` 值不同，理論上不會撞唯一鍵（除非字典本身在去重後仍有重複，已在步驟 2 用「執行 PHP 取運行時陣列」的方式排除此可能）。**不要用 `insertOrIgnore`**——這份資料應是 deterministic 的，若真的意外撞鍵，靜默吞掉衝突會變成「字典少幾筆、特定字轉不出來」的隱性行為漂移，比直接失敗更難排查。萬一撞鍵，應該記錄衝突內容（哪個 `chn`/`lastname` 組合、哪一批）後直接讓 migration 失敗中止，讓維運者能明確定位問題，而不是悄悄跳過。
- **效能**：`PinyinDictionary` 的整表快取是「每個 PHP request 生命週期內快取一次」，不是跨 request 常駐（PHP-FPM/一般 web request 模型下沒有常駐記憶體），如果之後效能有疑慮，可以再評估要不要疊加 `Cache::rememberForever`（Redis/file cache）減少重複查表——但這超出本次範圍，先以「跟原本 `Pinyin.php` 一樣，每個 request 只需查一次」為目標即可，不用一開始就上外部快取層。
- **測試維護成本**：步驟 4 引入的共用測試輔助（塞入整份 ~6910 筆字典資料）雖然避免了逐字盤點的風險，但也代表相關測試的 `setUp()` 會多一次批次 insert（SQLite 記憶體 DB，預期仍是毫秒級，但如果之後這類測試數量變多，值得留意整體測試套件執行時間是否明顯變慢）。
