# 拼音 v → ü 全庫正規化遷移計畫

> 狀態：**決策定案（可直接執行；本文 §D「決策定案」為權威，與其下較早敘述衝突時以 §D 為準）**
> 分支：`feature/pinyin-v-to-umlaut-migration`（本分支＝「計畫 PR」#1087；**實作每個小環節各起描述性新分支、各自開 PR**）
> 相關 PR：[#1086](https://github.com/cbdb-project/cbdb-online-main-server/pull/1086)（生成字典修正，已併入 #1087）
> English version: [PINYIN_V_TO_UMLAUT_MIGRATION.en.md](./PINYIN_V_TO_UMLAUT_MIGRATION.en.md)

## §D. 決策定案（LLM 照此執行，勿再確認、勿再重議）

> 以下為團隊郵件討論後**已拍板**的決策，可直接執行；不需再向人類確認。**Token 等機密不寫入本文。**

### D-0 範圍與流程
- **拆成兩個獨立 goal**：本計畫的 **Phase A 先做**＝止血 + 只讀掃描 + 查詢展開 + 人名審計批量修正；**Phase B（其他 code 表拼音）為之後另一個 goal**，且以先建好 [Code 表受審計寫入 API](./CODE_TABLE_MUTATION_API_PLAN.md) 為前置。
- **工作流（每個小環節）**：先派一組「讀代碼＋讀修改」的 review agent 檢查，反覆到無嚴重 issue；再用 **codex（終端指令，非 agent）** 檢查到無嚴重 issue；才推進下一環節。**未經人類明確指示不得合併。**
- **每個小環節＝各自的描述性新分支＋各自 PR**（例：`feature/pinyin-stop-the-bleed`、`feature/pinyin-scan-command`、`feature/pinyin-query-expansion`、`feature/pinyin-names-migration`）。

### D-1 不做 code 22 別名
- **不建立 `ALTNAME_DATA` 代碼 22「alternative romanization」別名**（團隊確認：查詢展開已滿足搜尋，code 22 冗餘）。以下文中所有「code 22 / 別名」段落一律視為**不執行**。

### D-2 權威來源＝公開 Google Sheet（逐條清單），掃描命令僅交叉核對
- 兩個分頁（公開、可直接 CSV 匯出）：
  - **ALTNAME_DATA**：`gid=1425535916`，CSV：`https://docs.google.com/spreadsheets/d/19SOyBtA8cKE9aq_hIkxRiT-e2i6f5bFDIY_TcNAn57I/export?format=csv&gid=1425535916`；957 資料列；欄位 `table,field,id,wrong_pinyin,correct_pinyin,note_en,note_zh`。
  - **BIOG_MAIN**：`gid=248977087`，CSV：同上網址改 `gid=248977087`；11,407 資料列（`c_name` 5,783／`c_surname` 3,509／`c_mingzi` 2,115）；欄位 `table,field,id,wrong_pinyin,correct_pinyin`。
- `id` ＝ `c_personid`。**西文名已人工排除**。**Sheet 為權威核對基準**；掃描命令 `cbdb:scan-pinyin-v` 只做交叉核對與差異報告，**不得覆蓋 Sheet**。
- **掃描盤點的定位（採 Frank #1087@L83）**：主要盤點可直接在**上週的 SQLite data dump 上以一次性 SQL** 查得，不必依賴常設指令；止血（§D-7）修好後不再產生新 v 記錄，僅需對「最後一週新增資料」再過一遍，之後如有零星遺漏單獨修正即可。**M2 的 `cbdb:scan-pinyin-v` 已合併、於 Phase A 期間保留為只讀備用工具，但不列為每次必跑的常設流程；並將於整個 Phase A 收尾時移除（見 §D-10 最後一步）。**

### D-3 BIOG_MAIN 套用法（採 Frank #1087@L104：重生 + Sheet 當 oracle + 寫入前漂移檢查）
- **機制＝重新合成，而非直接套 Sheet 值**：拼音生成庫已由止血修好（§D-7），故對每個受影響 `c_personid` **直接呼叫 `BiogMainRepository::auto_pinyin()`**，以其中文名（`c_name_chn`）重新合成 `c_surname`／`c_mingzi`／`c_name`（自然產出正字 ü）。
- **寫入前漂移檢查（②a，必做）**：套用前先讀該人現值；若欄位現值**已不等於** Sheet 的 `wrong_pinyin`（表示已被改過／已遷移），**跳過並記錄**——避免覆寫他人變更，且天然冪等（重跑安全）。
- **oracle 閘（②b，必做）**：重新合成的結果**必須等於** Sheet 的 `correct_pinyin` 才寫入；**對不上的不寫**，收入例外清單交 Hongsu 裁決。
- **只送 `c_surname` 與／或 `c_mingzi`** 走 `/api/v2/mutate`；`c_name` 由 handler 自動重算（§5.2）。**忽略 Sheet 的 `c_name` 直寫列**（API 封鎖直寫 c_name）。

### D-4 BIOG_MAIN 的 204 條「孤兒 c_name」——由 §D-3 重生機制天然涵蓋（原「拆分量」approach A 廢除）
- 原問題：某 `c_personid` 僅有 `c_name` 改動、無對應 `c_surname`／`c_mingzi` 列——共 **204** 個（出現在 `c_name` 集合、卻不在 `c_surname ∪ c_mingzi` 集合的 id），需判定 v 落在哪個分量。
- **採 §D-3 重生機制後此問題消失**：`auto_pinyin()` 會一次產出全部分量（surname／mingzi／name），無需判定 v 落點。§D-3 的 oracle 閘（重生 `c_name` == Sheet `correct_pinyin`）仍為必做；對不上者入例外清單。

### D-5 ALTNAME 套用法（複合主鍵定位）
- Sheet 的 `id` ＝ `c_personid`，但 `ALTNAME_DATA` 為 **3-鍵複合主鍵**。以 **`c_personid = id` 且 `c_alt_name = wrong_pinyin`** 定位該列、解析完整 PK（依 `CompositePrimaryKey::SCHEMAS`／讀取），再 `/api/v2/mutate` 將 `c_alt_name` 改為 `correct_pinyin`。
- 若某 `(c_personid, c_alt_name)` 命中 **>1 列**（歧義）→ **跳過並列入例外清單**。

### D-6 執行方式與節奏
- 使用操作者的 Sanctum **Bearer token**（active、非 crowdsourcing、可 `canWriteDirectly()` 的使用者；**Token 不寫入任何檔案／commit／PR／log**）。走 `/api/v2/*`、`mode:"direct"`，audit 自動。
- 流程（每筆）：**(0) 讀現值 → 漂移檢查**（現值 ≠ Sheet `wrong_pinyin` 則跳過並記錄）；**(1)** BIOG_MAIN 走 `auto_pinyin()` 重生（§D-3）／ALTNAME 以 `wrong_pinyin` 定位完整 PK（§D-5）；**(2) oracle 閘**（結果 == Sheet `correct_pinyin` 才續，否則入例外清單）；**(3) dry-run** 產出「完整預定變更集 + 例外/跳過清單」並自檢「無 Sheet 以外項、無 `[OTHER-v]` 混入」；**(4) 分批跑完**（BIOG_MAIN 一姓氏一批、ALTNAME 分塊）；**(5) 輸出抽樣**供人類抽查。
- 目標＝**直接生產**（無 staging）。**回退**：每筆皆審計 mutation，抽查發現錯的可經同一審計路徑／operations restore 逐筆回退。

### D-7 止血現況
- 生成字典 `app/Models/Pinyin.php`（29 處）**已完成**（commit d4ad265）。
- **待建**：共用 `v→ü` helper，掛入生成入口（`BiogMainRepository::auto_pinyin()`、三個批次 `buildPinyin()`、`ApiController::buildPinyinWord()`/`searchPinyin()`）。
- DB `pinyin` 表 4 個姓氏 **Frank 已在生產改好** → **只讀校驗、不重寫**（如需，可自 `https://input.cbdb.fas.harvard.edu/app/basicinformation` 對照）。

### D-8 搜尋
- `u` 已由 collation 摺疊命中 `ü`（無需改）。**查詢展開**（輸入 `lv/lve/nv/nve` 時以 OR 同查 v 與 ü 形式）**列入 Phase A**。SQLite 測試不摺疊，撰測需注意。

### D-9 Phase B 前瞻（於之後的 Phase B goal 執行）
- **實測結論：code 表基本是純拼音，不需 Phase-A 那種人工 Sheet。** 佐證：`ETHNICITY_TRIBE_CODES.c_name` 498 列中 11 條含 v、**全為真拼音、0 西文**；`CHORONYM_CODES.c_choronym_desc` 173 列中 1 條 `Vietnam`——**不含 `lv/nv` 音節簇、規則天然不動它**。
- 作法：**專用拼音欄與 romanized-name 欄一律以確定性音節規則直接替換**；掃描命令另出一份 `[OTHER-v]`（含 v 但非 `lv/lve/nv/nve`，如 `Vietnam`）小清單供人類 30 秒瞄一眼（安全網、非逐條審）。`ADDR_CODES`（最大、無法公開全掃）於 Phase B 起步時以只讀掃描命令實錘後再寫。
- **D-9a 無主鍵表 `SOCIAL_INSTITUTION_ALTNAME_DATA`：直接 SKIP**（無編輯入口，排除於 API 與遷移之外）。
- **D-9b `/codes` 管理介面審計缺口：補上**（於 `CodesController` 直寫路徑加 `AuditLogService::write()`，與新 API 審計一致）——詳見 code 表 API 計畫。
- **D-9c `ADDRESSES` 派生表：** 改完 `ADDR_CODES` 後以 `cbdb:regenerate-addresses-table`（生產、MySQL-only）**重建**。

### D-10 最後一步（Phase A 收尾，資料清乾淨後執行）
- 自 `config/codes.php` 的 `ui_hidden` **移除 `'pinyin'`**（重新顯示姓氏拼音表）為**既定計畫的一環**，**非另需人工決定**。
- **移除 M2 掃描指令 `cbdb:scan-pinyin-v`**（採 Frank #1087@L83：盤點屬一次性工作、不需常設指令）：刪除 `app/Console/Commands/ScanPinyinV.php`、`tests/Feature/ScanPinyinVTest.php`、`app/Console/Kernel.php` 的註冊列，作為整個 Phase A 的**最後一個小環節**。（`app/Support/PinyinUmlaut.php` 為止血與查詢展開共用，**保留**。）

### D-11 執行實測結果與最終機制定案（全量生產 dry-run + execute 後回填）
> 以下取代 §D-3／§D-4 原「重生為主 + oracle」設計——生產全量 dry-run 顯示 `auto_pinyin` 對約 27%(1558) 人名無法復現 Sheet 人工校訂（多音字/生僻字/「之妻」英譯），故改為 **Sheet 權威優先**。

- **BIOG 最終機制（Sheet 權威優先）**：寫入值取自 Sheet `correct`——兩分量行→直接採用；一分量行＋完整名→由完整名扣除已知分量推導（強制 `trim(surname.' '.mingzi)==完整名`）。`auto_pinyin` 重生**僅作 high/low 信心標記、不 gate 寫入**；low 另出 `*-low-confidence.json` 供抽查。實作見 `app/Services/Pinyin/PinyinMigrationPlanner.php`。
- **孤兒（只有 c_name 行）＝Sheet 完整名第一空格拆**：現庫分量常髒（如 `c_surname='Lu9'`＝Lü），故不信任現庫拼寫，只用現庫 `c_surname` 是否為空判斷有無姓；姓取自 Sheet 完整名第一空格前（CBDB 姓恆單 token）。**多空格（≥2，描述性/親屬「之女·妻」如「次室女」）、含括號（消歧/之妻）、現庫姓含空格 → 一律交人工**（是否可拆需語義判斷：親屬女可拆、其餘不可）。
- **寫入契約**：`POST /api/v2/mutate` payload 需**頂層 `person_id`＝pk.c_personid**（缺則 422 校驗失敗），resource `basicinformation`／`altnames`，`mode:direct`，只送 c_surname/c_mingzi（c_name 由 handler 重算）。base-url 因 nginx 強制 https、本地憑證不符，改用 `php artisan serve` 內建 http server（`--base-url=http://127.0.0.1:PORT`）。
- **指令**：`cbdb:migrate-pinyin-v {--table=both|biog|altname} {--fetch} {--confidence=all|high|low} {--execute} {--base-url=}`；預設 dry-run；`--confidence` 分批（high 批＝BIOG high + ALTNAME、跳過 BIOG low；low 批＝BIOG low）。Token 只從環境變數 `CBDB_MIGRATE_TOKEN` 讀。
- **執行結果（2026-07-01）**：第一批 high+ALTNAME 成功 5146、第二批 low 成功 1506，**累計 6652 筆寫入生產、線上抽樣驗證正確**（含生僻字 `呂搢→Lü Jin`、複姓孤兒 `閭丘陞→Lüqiu Sheng`）。
- **待人工（尚未寫）**：孤兒多空格（~47，等「親屬女可拆」規則）、括號孤兒 9、ALTNAME 歧義 27（>1 命中）、**分量中文名 NULL 的無名記錄 24**（如 `鄭履正`，handler 校驗「名不能為空」拒絕、API 無法更新，需另途）。清單見人工複核 xlsx（不入版控）。

## 0. 背景與決議

CBDB 拼音規範長期以 `v` 代替 `ü`（如 `呂 = Lv`、`閭丘 = Lvqiu`、`耶律 = Yelv`）。經 Frank Lin、Song Chen（陳松）、Hongsu、Michael Fuller、Peter Bol 的郵件討論，達成以下共識：

1. **`ü` 為唯一正字（canonical）**，全庫拼音欄位不再以 `v` 代替（依《漢語拼音方案》）。
2. **輸入時接受 `v`**：手動錄入／拼音生成時將 `v` 正規化為 `ü`（儲存正字）；搜尋時則讓使用者輸入的 `v` 形式同時匹配 `v` 與 `ü`（查詢展開，見 §3），方便鍵盤輸入。
3. ~~舊 `v` 形式可選擇性保留為「另一種羅馬化」別名（`ALTNAME_DATA` 代碼 22）~~ **（作廢：不做，見 §D-1；查詢展開已滿足搜尋）**。
4. **所有資料修改必須有 audit 記錄**，**不得使用繞過 audit 的集中式 SQL**；以受審計的 mutation API（外部腳本）執行最為安全。

## 1. 規劃原則（依後續討論調整）

依 Frank 後續建議，本計畫採以下原則，避免把單純的資料品質修正與較大的兼容工程綁在一起：

- **資料修正 vs 搜尋兼容解耦**：把 `v → ü` 視為**資料品質修正**——效果等同編輯在 UI 逐筆改正，只是更系統化、更不易出錯，且全程有 audit 可回溯。搜尋兼容是**獨立、可延後**的工作，不應阻塞資料修正。
- **分階段、人名優先**：不需一次完成全庫遷移。先做**高可見、低風險的人名拼音**，其他拼音欄位（地名、官名、年號等）在掃描與複核後分批進行。
- **不阻塞於下游系統，亦不阻塞於 Access 版**：線上學術資料庫的正字修正不需等待下游系統同步實作，也不需等待 Access 版（CBDB 單機版，為平行發行版本，非下游）；我們會與下游系統及 Access 版溝通並建議採用兼容行為，但這些都不應阻擋資料品質修正。
- **以 mutation API 執行**：用既有、已測試的審計 mutation API（外部腳本），而非新寫繞過或自建寫入路徑。

## 2. 拼音判定規則（核心）

`ü` 在漢語拼音中只出現在聲母 `l` / `n` 之後，可窮舉的音節僅四種：

| v 形式 | 正確拼音 |
|--------|----------|
| `lv`   | `lü`     |
| `lve`  | `lüe`    |
| `nv`   | `nü`     |
| `nve`  | `nüe`    |

因此**需要轉換的子串只有上述四種**（大小寫各自處理：`Lv/Lü`、`Lve/Lüe`、`Nv/Nü`、`Nve/Nüe`）。

### 必須排除的情況（非拼音的 v）
- 西文名 / 外文羅馬化：`Silva`、`Calvin`、`Melvin`、`Sylvia`、`Vasco`、`Verbiest`… 這些 `v` 不是拼音聲母 `l/n` 後的 `ü`。
- 判定方法（二擇一或併用）：
  - **音節模式比對**：只在「以空白或字串邊界分隔的音節 token」**整體等於或開頭為** `lv` / `lve` / `nv` / `nve`（其後接母音/音節邊界）時才轉換，避免誤傷 `Silva`（`lv` 前有 `Si`，非音節開頭）。
  - **人工白名單複核**：盤點階段產出可疑清單供人工確認（對齊 Frank 的 [Google Sheet](https://docs.google.com/spreadsheets/d/19SOyBtA8cKE9aq_hIkxRiT-e2i6f5bFDIY_TcNAn57I)）。

> 注意：CBDB 拼音多以空白分隔音節（如 `Lü Mengzheng`、`Yelü`），但 `Yelv` 這類連寫情況存在，需同時處理「token 內部音節邊界」。實作時以正則鎖定 `l/n + v(e)` 且左側非字母、右側為母音或邊界。

## 3. 搜尋現況（決定兼容工作的範圍）

> 此節是「搜尋兼容為何可延後」的依據。

- **collation 事實**：生產環境 MariaDB 的 `utf8mb4_general_ci` 與 `utf8mb4_unicode_ci` **皆為 accent-insensitive，會把 `ü` 摺疊成 `u`**。受影響欄位 `c_surname`、`c_mingzi`、`pinyin.lastname_pinyin`、`c_alt_name` 為 general_ci，`c_name` 為 unicode_ci，兩者都折疊。
- **結論**：使用者**打 `u` 已能命中 `ü` 資料**，無需額外程式（Frank 線上實例：搜尋 `yelu` 命中 `Yelü`，符合預期）。因此把資料改成 `ü` **不會**讓既有以 `u` 搜尋的使用者失效。
- **唯一兼容缺口：習慣打 `v` 的使用者**（如 `Lv`、`Yelv`）——`v` 不會被 collation 折疊成 `ü`。**建議採「查詢展開（query expansion）」：當使用者輸入含 `v` 的音節形式（`lv`/`lve`/`nv`/`nve`）時，系統同時以 `v` 形式與對應 `ü` 形式查詢（OR），而非把查詢中的 `v` 取代為 `ü`。**
  - 理由：在使用者查詢中**無法可靠區分** `lv` 是 `lü` 的代打、還是西文名（如 `Calvin`）的一部分；直接取代會選定單一解讀、可能讓含 `v` 的西文名查詢落空。同時查兩者最穩健——既命中已正規化的 `ü` 資料，也命中過渡期殘留的 `v` 形式與西文名。
  - 此法可延後、非阻塞；且**使代碼 22 別名對搜尋並非必要**（別名若仍要做，純為搜尋以外的目的，見 §5.3）。
- **SQLite 例外**：測試環境（SQLite）為二進位/`NOCASE` 比較，**不折疊** `ü`/`u`。撰寫回歸測試時須注意此差異（必要時於測試端先正規化或自訂 collation），勿據 SQLite 行為推斷生產行為。

## 4. 受影響範圍盤點（分階段）

> 拼音欄位不僅存在於人名相關欄位；地名、官名、朝代、年號、族群等 code 表同樣含 `v`。但依「人名優先、其他分批」原則，下表標明階段。欄位名稱已逐一對照 `docs/DATABASE_SCHEMA.md` 核實。

### 階段 A — 人名拼音（優先處理）
| 表 | 欄位 | 說明 |
|----|------|------|
| `BIOG_MAIN` | `c_surname`、`c_mingzi` | 漢語拼音；mutation API **可直接更新**，且 update 路徑不會重跑 `auto_pinyin`（不會用中文重生覆蓋你給的值）。 |
| `BIOG_MAIN` | `c_name` | 全名（`c_surname + ' ' + c_mingzi`）；mutation API 雖將其列為 blocked（不可直接傳入），但 update 路徑會**自動由合併後的 `c_surname`+`c_mingzi` 重算 `c_name`**（handler 先把 changes 併入整筆原紀錄）。**故修正 `c_surname`/`c_mingzi` 後 `c_name` 自動跟著正確，無需另行處理。** |
| `ALTNAME_DATA` | `c_alt_name` | 人物別名羅馬化（`c_alt_name_chn` 為中文；複合主鍵 3 鍵）。 |

### 階段 B — 其他拼音欄位（後續分批，掃描複核後進行）
| 表 | 欄位 | 主鍵備註 |
|----|------|----------|
| `ADDR_CODES` | `c_name` | 單鍵 `c_addr_id`；派生表 `ADDRESSES`（`c_name`、`belongs1_Name`…`belongs5_Name`）改源頭後重建 |
| `OFFICE_CODES` | `c_office_pinyin`、`c_office_pinyin_alt` | 單鍵 `c_office_id` |
| `ETHNICITY_TRIBE_CODES` | `c_name`、`c_romanized`、`c_surname` | 單鍵 `c_ethnicity_code` |
| `DYNASTIES` | `c_dynasty` | 單鍵 `c_dy`（實務上幾無 `lv/nv`） |
| `NIAN_HAO` | `c_nianhao_pin` | 單鍵 `c_nianhao_id` |
| `CHORONYM_CODES` | `c_choronym_desc` | 單鍵 `c_choronym_code` |
| `TEXT_CODES` | `c_title` | 單鍵 `c_textid`（`c_title_trans` 為譯名不轉） |
| `TEXT_INSTANCE_DATA` | `c_instance_title` | **複合主鍵 3 鍵** `c_textid`、`c_text_edition_id`、`c_text_instance_id` |
| `TEXT_BIBLCAT_CODES` | `c_text_cat_pinyin` | 單鍵 `c_text_cat_code` |
| `GANZHI_CODES` | `c_ganzhi_py` | 單鍵；60 干支不含 `lü/nü`，預期 0 筆 |
| `SOCIAL_INSTITUTION_NAME_CODES` | `c_inst_name_py` | 單鍵 `c_inst_name_code` |
| `SOCIAL_INSTITUTION_TYPES` | `c_inst_type_py` | 單鍵 `c_inst_type_code` |
| `SOCIAL_INSTITUTION_ALTNAME_DATA` | `c_inst_altname_py` | **無主鍵**（特例，見下） |
| `ADMIN_CAT_CODES` | `c_admin_cat_py` | 單鍵 `c_admin_cat_code` |

> 掃描階段以「凡欄位名以 `_py` / `_pinyin` 結尾，或經 schema 標註為 romanized」為準則自動列舉，避免日後遺漏新欄位。
> **無主鍵特例**：`SOCIAL_INSTITUTION_ALTNAME_DATA` 沒有 PRIMARY KEY。**依 §D-9a 直接 SKIP、不處理**（無編輯入口，排除於 API 與遷移之外）。
> **階段 B 的審計路徑（已評估，需先建 API）**：mutation API（`/api/v2/*`）目前以人物及其子資源為主，code 表中**僅 `NIAN_HAO` 有 handler**；`/codes` UI 雖通用但只寫 `operations`、不寫 `audit_log`，且為 CSRF web 路由不適合外部腳本。因此階段 B 動工前需先建立 code 表的受審計寫入 API——詳見 [Code 表受審計寫入 API 建設計畫](./CODE_TABLE_MUTATION_API_PLAN.md)。不得用繞過 audit 的 SQL。

### 不轉換的欄位
- **非漢語拼音 romanization**：`BIOG_MAIN.c_name_rm`、`c_surname_rm`、`c_mingzi_rm`（Wade-Giles / McCune-Reischauer 等，使用者可編輯，`ü` 用法不同）。
- **母語原名**：`BIOG_MAIN.c_name_proper`、`c_surname_proper`、`c_mingzi_proper`（拉丁字母，可能含真 `v`）。
- **譯名欄位**：`OFFICE_CODES.c_office_trans` / `c_office_trans_alt`、`TEXT_CODES.c_title_trans`、`ADMIN_CAT_CODES.c_admin_cat_trans` 等英譯。
- 確認為西文名 / 外文的 `v`（見 §2 排除規則）。

## 5. 修正方式：外部腳本走既有審計 mutation API

> 原則：**重用已測試的審計 mutation API，禁止繞過 audit 的集中式 SQL。**

### 5.1 mutation API（已核實）
- 端點：`POST /api/v2/mutate`（更新）、`POST /api/v2/create`（新增）、`POST /api/v2/delete`（刪除）。
- 認證：Sanctum **Bearer token**（給一個 active、非 crowdsourcing 角色的使用者）；`/api/v2/*` **CSRF 豁免** → 適合外部批次腳本。`mode: "direct"` 需 `canWriteDirectly()`（非 crowdsourcing）。
- **audit 自動**：handler 內自動呼叫 `AuditLogService::write()`，並寫 `operations`，無需額外程式。

### 5.2 人名拼音修正
- `BIOG_MAIN.c_surname` / `c_mingzi`：`/api/v2/mutate` **允許直接更新**，且 update 路徑**不會**重跑 `auto_pinyin`（不會用中文重生覆蓋你給的值）。腳本直接帶入修正後拼音即可。
- **`c_name` 自動重算（無待解項）**：handler 的 `buildMergedPayload()` 會先把 `changes` 併入整筆原紀錄，`updateById()` 再由合併後的 `c_surname`+`c_mingzi` 重算 `c_name`（連同 `c_name_chn`/`c_name_proper`/`c_name_rm`）。因此腳本**只需送修正後的 `c_surname`/`c_mingzi`**，`c_name` 會自動跟著正確，無資料遺失風險、無需另行處理。
- **修正值的取得＝重生（§D-3，Frank #1087@L104）**：不直接照抄 Sheet 值，而是呼叫 `auto_pinyin()` 以中文名重新合成 `c_surname`/`c_mingzi`（止血後自然產出 ü），並以「寫入前漂移檢查 + Sheet `correct_pinyin` oracle 閘」把關；因 update 路徑不重跑 `auto_pinyin`，送出我方重生的值即穩定落庫。
- 建議批量節奏：一次數百筆，或**一個姓氏一批**，先 dry-run / 取樣複核再正式送出。

### 5.3 別名（**作廢，不執行 —— 見 §D-1**；以下僅存檔說明）
- 搜尋兼容已由查詢展開（§3）達成，**代碼 22 別名並非搜尋所需**。以下僅在「基於搜尋以外的理由」仍要保留舊 `v` 形式為別名時適用。
- 作法：以 `POST /api/v2/create`、`resource: "altnames"`、`c_alt_name_type_code: 22`、`c_alt_name: <v 形式>`、並附 `c_alt_name_chn`（PK 需要中文名）建立別名列。
- **前置條件**：`ALTNAME_CODES` 目前無 seed，須先確認/建立代碼 22「alternative romanization」（含中英說明），否則 FK 會拒絕未知代碼。
- 若要做，建議在**資料清理完成後**再加；是否必須無強烈定論。

### 5.4 盤點工具
- 只讀 artisan 指令 `php artisan cbdb:scan-pinyin-v`（M2 已合併）：掃描 §4 候選欄位、依音節規則分類（疑似拼音 / 疑似西文名）、輸出 CSV 供人工複核並對齊 Frank 的 Google Sheet。此指令**只讀不寫**，可安全在生產執行。**惟採 Frank #1087@L83：盤點屬一次性工作，主要以 dump 上的一次性 SQL 為主；本指令僅為 Phase A 期間備用，並於收尾時移除（§D-2／§D-10）。**

## 6. 分階段執行計畫（採納 Frank 建議）

1. **止血（Stop the bleed）**：以 `ü` 為新資料正字；做必要的前端／生成端修改，防止再寫入新的 `v`。
   - 合併 PR #1086（`Pinyin::$dic`：`lv/lve/nv → lü/lüe/nü`）+ 更新 DB `pinyin` 表姓氏。
   - 在「漢字→拼音」生成入口加 `v → ü` 正規化（已核實入口）：`BiogMainRepository::auto_pinyin()`、`ApiController::buildPinyinWord()` / `searchPinyin()`、三個批次匯入各自的 `buildPinyin()`（`AdminBatchLoadOfficesController`、`AdminBatchLoadBookTitlesController`、`AdminBatchLoadSocialInstitutesController`）；這些路徑生成前已呼叫 `VariantCharNormalizer::normalize()`，是自然掛點。需新建共用 `v→ü` helper（目前 repo 無此 helper）。
   - 註：`nve` 在生成字典中本就不存在；`nve → nüe` 僅在輸入正規化端有意義。
2. **修正高可見人名拼音**：透過審計 mutation API 批次修正（§5.2），分批（數百筆／一姓氏一批）。
3. **搜尋兼容（Phase A 執行，見 §D-8）**：以查詢展開讓使用者輸入的 `v` 形式同時匹配 `v` 與 `ü`（§3）。（code 22 別名不做，見 §D-1。）
4. **建議下游系統與 Access 版**適時加上查詢兼容——查詢時同時匹配 `v` 與 `ü` 形式（溝通、非阻塞）。
5. **持續掃描、修正其他非人名拼音欄位**（階段 B，§4）。

## 7. 風險與注意事項

- **西文名誤傷**（Silva/Calvin…）：靠音節規則 + 人工複核；`c_*_proper`、`c_*_rm`、譯名欄位不動。
- **搜尋現況**：`u` 已能命中 `ü`（collation 折疊）；僅 `v` 需兼容，採查詢展開（輸入 `v` 同時查 `v` 與 `ü`），且可延後（§3）。SQLite 測試不折疊，撰寫測試須注意。
- ~~代碼 22 FK 前置~~ **（不適用：不做 code 22，見 §D-1）**。
- **階段 B 審計路徑**：code 表目前僅 `NIAN_HAO` 有 mutation handler，需先建受審計寫入 API（見 [Code 表受審計寫入 API 建設計畫](./CODE_TABLE_MUTATION_API_PLAN.md)）才動工（§4）。
- **無主鍵表**：`SOCIAL_INSTITUTION_ALTNAME_DATA` **SKIP、不處理**（見 §D-9a）。
- **派生表一致性**：`ADDRESSES` 只改源頭 `ADDR_CODES` 後以 `cbdb:regenerate-addresses-table` 重建（該指令為 MySQL 限定，SQLite 不可跑）。
- **禁止繞過 audit 的集中式 SQL**：所有資料修改走 mutation API 或受審計流程。

## 8. 執行順序與待辦帳本

- [ ] 階段一（止血）：合併 PR #1086 + 更新 DB `pinyin` 表姓氏為 `ü`
- [ ] 階段一（止血）：新建共用 `v→ü` helper，掛入生成入口（`auto_pinyin` + 三批次 `buildPinyin` + `ApiController`）防止新 `v`
- [x] 盤點：`cbdb:scan-pinyin-v` 唯讀掃描 + 報告（M2 已合併）；**主要盤點以 dump 一次性 SQL 為主，指令為備用、Phase A 收尾時移除（§D-2／§D-10，Frank #1087@L83）**
- [ ] 階段二（人名）：外部腳本走 `/api/v2/mutate` 批次修正 `c_surname`/`c_mingzi`——**採「重生 + Sheet oracle + 寫入前漂移檢查」（§D-3，Frank #1087@L104）**，`c_name` 由系統自動重算，分批、先 dry-run 複核
- [x] **（Phase A，§D-8）** 搜尋兼容：拼音 LIKE 查詢端查詢展開（輸入 `v` 同查 `v` 與 `ü`）（§3）——M3 PR #1099
- [ ] ~~確認／建立 `ALTNAME_CODES` 代碼 22 並補別名~~ **（不做，見 §D-1）**
- [ ] 溝通下游系統與 Access 版，建議查詢時同時匹配 `v` 與 `ü` 形式
- [ ] 階段 B 前置：依 [Code 表受審計寫入 API 建設計畫](./CODE_TABLE_MUTATION_API_PLAN.md) 建立 code 表受審計寫入 API
- [ ] 階段 B：API 就緒後，分批掃描修正其他拼音欄位（含 `TEXT_INSTANCE_DATA` 複合主鍵、`ADDRESSES` 重建；`SOCIAL_INSTITUTION_ALTNAME_DATA` **SKIP，見 §D-9a**）
- [ ] 回歸測試（生成 / 正規化 / 人名修正 / audit / 西文名排除；注意 SQLite collation 差異）
- [ ] 文件同步：`CHANGELOG.md`、必要時 `DATABASE.md` / `README.md`
- [ ] **最後環節（§D-10）**：自 `config/codes.php` 的 `ui_hidden` 移除 `'pinyin'`，於 codes 介面重新顯示姓氏拼音對照表
- [ ] **Phase A 最末步（§D-10）**：移除 M2 掃描指令 `cbdb:scan-pinyin-v`（`ScanPinyinV.php` + 測試 + Kernel 註冊；保留 `PinyinUmlaut.php`）
