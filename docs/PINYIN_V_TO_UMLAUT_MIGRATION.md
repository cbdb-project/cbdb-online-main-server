# 拼音 v → ü 全庫正規化遷移計畫

> 狀態：計畫草案（待 review）
> 分支：`feature/pinyin-v-to-umlaut-migration`
> 相關 PR：[#1086](https://github.com/cbdb-project/cbdb-online-main-server/pull/1086)（僅修正 `app/Models/Pinyin.php` 生成字典）

## 0. 背景與決議

CBDB 拼音規範長期以 `v` 代替 `ü`（如 `呂 = Lv`、`閭丘 = Lvqiu`、`耶律 = Yelv`）。經 Frank Lin、Song Chen（陳松）、Hongsu、Michael Fuller、Peter Bol 的郵件討論，達成以下共識：

1. **`ü` 為唯一正字（canonical）**，全庫拼音欄位不再以 `v` 代替（依《漢語拼音方案》）。
2. **輸入時接受 `v`**：手動錄入與搜尋時 `v` 自動轉 `ü`，方便鍵盤輸入。
3. **舊 `v` 形式保留為「另一種羅馬化」別名**以兼容搜尋；使用 Michael 提出的 `ALTNAME_DATA` 別名代碼 **22（alternative romanization）**，避免把 `v` 形式當成正式名字，且不寫入 `c_notes`。
4. **所有資料修改必須有 audit 記錄**，透過受審計的批次流程（Repository / Service + `AuditLogService`），**不得使用裸 SQL `UPDATE`**。

> PR #1086 只解決「生成字典」，屬於「防止今後新增 `v`」；**存量資料清理是本計畫的主體**，且必須涵蓋人名以外的所有拼音欄位（地名、官名、朝代、年號、族群等）。

## 1. 拼音判定規則（核心）

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

## 2. 受影響範圍盤點（不可只看人名）

> 這一節正面回答「為什麼大家只談人名」：拼音欄位散布在多張表，**地名、官名、朝代、年號、族群等 code 表同樣含 `v`**，必須一併納入。

> 下列欄位清單已對照 `docs/DATABASE_SCHEMA.md`（MySQL/SQLite 兩半一致）逐一核實。掃描階段仍應以指令重新確認資料實況，但欄位名稱本身已確定。

### A. 漢語拼音欄位（需轉換）
| 表 | 欄位 | 說明 |
|----|------|------|
| `BIOG_MAIN` | `c_name`、`c_surname`、`c_mingzi` | 漢語拼音；**由拼音來源自動生成**（見 §5：`c_surname` 來自 DB `pinyin` 表，`c_mingzi` 來自 `Pinyin::$dic`，`c_name` = 兩者串接）。**不宜直接 UPDATE**，宜改源頭後重生（見 §4.3）。 |
| `ALTNAME_DATA` | `c_alt_name` | 人物別名羅馬化（`c_alt_name_chn` 為中文，屬複合主鍵） |
| `ADDR_CODES` | `c_name` | 地名羅馬化（`c_name_chn` 為中文） |
| `ADDRESSES`（派生） | `c_name`、`belongs1_Name`…`belongs5_Name` | 由 `ADDR_CODES.c_name` 派生，**改源頭後重建，不直接改**（見 §4.4） |
| `OFFICE_CODES` | `c_office_pinyin`、`c_office_pinyin_alt` | 官名拼音 |
| `ETHNICITY_TRIBE_CODES` | `c_name`、`c_romanized`、`c_surname` | 族群羅馬化 |
| `DYNASTIES` | `c_dynasty` | 朝代羅馬化（實務上幾無 `lv/nv`，仍納入掃描） |
| `NIAN_HAO` | `c_nianhao_pin` | 年號拼音（`c_nianhao_chn` 為中文） |
| `CHORONYM_CODES` | `c_choronym_desc` | 郡望羅馬化（`c_choronym_chn` 為中文） |
| `TEXT_CODES` | `c_title` | 書名羅馬化（`c_title_chn` 中文、`c_title_trans` 為譯名不轉） |
| `TEXT_INSTANCE_DATA` | `c_instance_title` | 版本書名羅馬化 |
| `TEXT_BIBLCAT_CODES` | `c_text_cat_pinyin` | 書目分類拼音 |
| `GANZHI_CODES` | `c_ganzhi_py` | 干支拼音（60 干支不含 `lü/nü`，預期 0 筆，仍掃描確認） |
| `SOCIAL_INSTITUTION_NAME_CODES` | `c_inst_name_py` | 社會機構名拼音 |
| `SOCIAL_INSTITUTION_TYPES` | `c_inst_type_py` | 社會機構類型拼音 |
| `SOCIAL_INSTITUTION_ALTNAME_DATA` | `c_inst_altname_py` | 社會機構別名拼音 |
| `ADMIN_CAT_CODES` | `c_admin_cat_py` | 行政類別拼音（`c_admin_cat_trans` 為譯名不轉） |

> 掃描階段以「凡欄位名以 `_py` / `_pinyin` 結尾，或經 schema 標註為 romanized」為準則，自動列舉，避免上表日後遺漏新欄位。

### B. 非漢語拼音 romanization 欄位（預設不動，需單獨決議）
| 表 | 欄位 | 原因 |
|----|------|------|
| `BIOG_MAIN` | `c_name_rm`、`c_surname_rm`、`c_mingzi_rm` | **Wade-Giles / McCune-Reischauer 等非拼音羅馬化**、使用者可編輯。`ü` 用法與《漢語拼音方案》不同，**不納入本次自動轉換**。 |
| `BIOG_MAIN` | `c_name_proper`、`c_surname_proper`、`c_mingzi_proper` | 人物母語（非漢語）原名，拉丁字母，可能含真正的 `v`，**非拼音，不轉**。 |

### C. 絕不轉換
- 確認為西文名 / 外文的 `v`（見第 1 節排除規則）。
- 譯名欄位（非羅馬化）：`OFFICE_CODES.c_office_trans` / `c_office_trans_alt`、`TEXT_CODES.c_title_trans`、`ADMIN_CAT_CODES.c_admin_cat_trans` 等英文翻譯欄位。

## 3. 階段一：盤點掃描（read-only，不改資料）

目標：在動任何資料前，先把「全庫哪些欄位、哪些記錄含 `v`、其中哪些是拼音、哪些是西文名」盤清楚。

- 新增唯讀 artisan 指令：`php artisan cbdb:scan-pinyin-v`
  - 掃描第 2-A 節所有候選表/欄位（清單以指令內設定檔維護，便於擴充）。
  - 對每個 `table.column` 統計：含 `v` 記錄數、依音節規則自動分類（疑似拼音 / 疑似西文名）、抽樣樣本。
  - 輸出 CSV 報告（放 `storage/app/pinyin-v-scan/`），供人工複核並與 Frank 的 Google Sheet 對齊。
- 此階段**不寫任何資料**，可在正式庫安全執行。
- 驗收：報告涵蓋所有候選欄位；西文名誤判率經人工抽查可接受。

## 4. 階段二：以 audit 記錄進行資料修改

> 設計原則：**每筆業務資料變更都要在同一交易內寫一筆 `audit_log`**，不得用裸 SQL 直接改業務資料而略過審計。（唯一例外：派生表 `ADDRESSES` 以既有重建指令整表重生，屬技術重建、不另記 audit，見 §4.4。）

### 4.1 既有審計基礎設施（用法與限制）
- `app/Services/AuditLogService.php` 的 `write()` / `logChange()`：
  - 參數：`table`、`operation`、`rowPk`（**required，非 nullable**）、`oldData`、`newData`、`actorType`、`actorId`、`operationId`、`occurredAt`、`createdAt`。
  - 自動以 `Str::ulid()` 產生 `operation_id`（批次可傳入**同一個** `operationId` 標識整批，便於日後整批回溯/回滾）。
  - 透過 `DB::afterCommit` 自動更新 `person_change_index`，人物相關表變更會被偵測，無需另外處理。
- **重要：`AuditLogService` 只負責「寫 audit」，不執行資料 UPDATE 本身。** 因此轉換指令必須自己完成「資料寫入 + 呼叫 `write()`」兩步，包在同一交易內。
- **code 表沒有現成的「UPDATE + audit」路徑**：現有 35 個 `AuditLogService` 呼叫端都在人物/子資源寫入流程（BiogMain、altname、address…）與 NianHao，`ADDR_CODES`、`OFFICE_CODES`、`DYNASTIES`、`ETHNICITY_TRIBE_CODES` 等只有 `$guarded=[]` 的裸 Eloquent model，**沒有**配對審計的 repository。故本指令需自行用 `Model::update()`／`save()` 改值，再逐列呼叫 `AuditLogService::write()`——這是**要新建的流程**，非沿用既有基礎設施。
- `audit_log` 為 **append-only**；回滾以「反向 new→old」再寫一筆 `UPDATE` 達成，不刪 audit。

### 4.2 轉換指令設計
- 新增 artisan 指令：`php artisan cbdb:convert-pinyin-v [--dry-run] [--table=] [--limit=]`（命名/簽章對齊既有 `cbdb:*` 慣例）。
  - 預設 `--dry-run`：只輸出將變更的 diff，不寫庫。
  - 每筆變更流程（同一 DB 交易內）：
    1. 依音節規則計算 `new` 值；若與 `old` 相同或判定為西文名則跳過。
    2. 解析 `rowPk`：指令需內建「每表主鍵對照」，依各表實際主鍵組 `rowPk`。受影響表的主鍵已逐一核實如下：
       - **複合主鍵（2 表）**：
         - `ALTNAME_DATA`（3 鍵 `c_alt_name_chn`、`c_alt_name_type_code`、`c_personid`）— **已登錄** `CompositePrimaryKey::SCHEMAS`，可用 `App\Support\CompositePrimaryKey`。
         - `TEXT_INSTANCE_DATA`（3 鍵 `c_textid`、`c_text_edition_id`、`c_text_instance_id`）— **未登錄** SCHEMAS，需手動組 `rowPk`。
       - **單一主鍵**：`ADDR_CODES.c_addr_id`、`OFFICE_CODES.c_office_id`、`DYNASTIES.c_dy`、`ETHNICITY_TRIBE_CODES.c_ethnicity_code`、`NIAN_HAO.c_nianhao_id`、`CHORONYM_CODES.c_choronym_code`、`TEXT_CODES.c_textid`、`TEXT_BIBLCAT_CODES.c_text_cat_code`、`GANZHI_CODES.c_ganzhi_code`、`SOCIAL_INSTITUTION_NAME_CODES.c_inst_name_code`、`SOCIAL_INSTITUTION_TYPES.c_inst_type_code`、`ADMIN_CAT_CODES.c_admin_cat_code`（均未登錄 SCHEMAS，手動組 `rowPk`）。
       - **無主鍵表（特例，必須單獨處理）**：`SOCIAL_INSTITUTION_ALTNAME_DATA` **沒有 PRIMARY KEY**（僅 `c_inst_code` / `c_inst_name_code` 兩個普通索引，所有欄位皆 nullable）。因 `AuditLogService::write()` 的 `rowPk` 為 required，**此表不可走逐列審計轉換**。處理方案二擇一：(a) 以可唯一定位的欄位組合作合成 `rowPk`（需先確認 `c_inst_code`+`c_inst_name_code`+`c_inst_altname_type` 等是否唯一），或 (b) 排除自動轉換、改人工處理並另記錄。掃描階段若再發現其他無主鍵表，同此原則。
    3. 以 Eloquent `Model::update()` 改該列拼音欄位（**非裸 SQL**）。
    4. `AuditLogService::write()` 記錄 `operation='UPDATE'`、`actorType='system'`、`actorId='pinyin-v-migration'`、整批共用一個 `operationId`、`oldData`/`newData` 只含受影響欄位。
  - 分批、可續跑（記錄已處理水位）、可中止。
- 驗收：`--dry-run` diff 經人工確認；正式跑後 `audit_log` 可完整還原每筆變更；抽樣比對轉換正確、無西文名誤傷。

### 4.3 `BIOG_MAIN` 拼音欄位以「重生」而非「直改」
- `c_name`/`c_surname`/`c_mingzi` 是由 DB `pinyin` 表（姓）與 `Pinyin::$dic`（名）自動生成的。若直接 UPDATE，日後任何觸發 `BiogMainRepository::auto_pinyin()` 的編輯會把它再覆寫回（取決於來源是否已修正）。
- 正確順序：**先**完成 §5 的來源修正（合併 PR #1086 + DB `pinyin` 表姓氏改 `ü`），**再**對受影響人物重新生成拼音並經審計寫回；避免「改了又被生成覆蓋」。`ALTNAME_DATA` 與各 code 表非自動生成，照 §4.2 直接轉。

### 4.4 派生表 `ADDRESSES` 重建
- 不直接改 `ADDRESSES`；改 `ADDR_CODES.c_name` 後，以既有 `php artisan cbdb:regenerate-addresses-table`（`app/Console/Commands/RegenerateAddresses.php`，支援 `--dry-run`）重建。
- **注意：該指令目前以原始 `TEMPORARY TABLE` + `INSERT…SELECT` 實作，為 MySQL/MariaDB 限定**，SQLite 不可跑。這與 §6 的可攜性原則相衝突；本計畫沿用其現狀（生產為 MariaDB），但須在 doc 與指令說明標明此限制，勿在 SQLite 環境執行此步。

### 4.5 別名兼容（僅人名）與代碼 22 的前置條件
- 依決議，對受影響人物將原 `v` 形式以 `ALTNAME_DATA` 代碼 **22（alternative romanization）** 寫入供搜尋兼容；同樣經 `AuditLogService` 記錄。code 表（地名/官名等）**不寫別名**，其搜尋兼容靠程式層 normalize（見 §5）。
- **前置條件（必須先做）**：`ALTNAME_CODES`（PK `c_name_type_code`）目前 repo 內**無任何 seed**，無法確認代碼 22 已存在且語意為「alternative romanization」。`ALTNAME_DATA.c_alt_name_type_code` 有 FK 指向 `ALTNAME_CODES`，**寫入未知代碼會被 FK 拒絕**。故須先確認／建立代碼 22（含中英說明）。
- **PK 限制**：`ALTNAME_DATA` 主鍵為 `(c_alt_name_chn, c_alt_name_type_code, c_personid)`，**不含** `c_alt_name`。因此寫一筆代碼 22 別名時必須同時提供中文名 `c_alt_name_chn`（非僅羅馬化字串），且每組 (中文名, 人物) 只能有一筆代碼 22。實作須決定 `c_alt_name_chn` 取值（通常取該人物對應的中文名）。

## 5. 階段三：程式碼修改（最後一步）

1. **合併生成字典**：合入 PR #1086（`app/Models/Pinyin.php` 的 `$dic`：`lv/lve/nv → lü/lüe/nü`），並更新 DB `pinyin` 表相關姓氏。
   - 已核實：`$dic` 內含 `v` 的值**只有** `lv`、`lve`、`nv` 三種，無其他雜散 `v`；**`nve` 在字典中本就不存在**（無漢字映射到 `nve`），故生成字典端「補 `nve`」無事可做。`nve → nüe` 只在「輸入／搜尋正規化」端有意義（使用者可能手打 `nve`）。
   - PR #1086 尚未併入本分支（工作樹仍為 `v`），合併時一併確認。
2. **輸入正規化**：在「漢字→拼音」生成的統一入口加上 `v → ü` 規則。已核實的生成入口：
   - 主路徑 `BiogMainRepository::auto_pinyin()`（人物建立/更新；`c_surname` 查 DB `pinyin` 表、`c_mingzi` 走 `Pinyin::getPinyin()`）。
   - `ApiController::buildPinyinWord()` / `searchPinyin()`（「產生拼音」按鈕、`GET api/search/pinyin`）。
   - **批次匯入另有獨立的 `buildPinyin()`**：`AdminBatchLoadOfficesController`、`AdminBatchLoadBookTitlesController`、`AdminBatchLoadSocialInstitutesController` 各自一份，需各別套用，避免「散落未覆蓋」。
   - 上述路徑生成前已呼叫 `VariantCharNormalizer::normalize()`，是加掛 `v → ü` 正規化的自然位置。
   - 目前 repo 內**無任何 `v→ü` 正規化 helper**（全庫搜尋 `ü`/`lü`/`nü` 為 0 命中），須新建共用 helper 供生成端與搜尋端共用。
3. **搜尋兼容**：把查詢字串 normalize（Michael 方案②），使用者打 `v` 仍命中 `ü` 資料；人名另有代碼 22 別名雙保險。已核實範圍：
   - 拼音 LIKE 比對在 `app/Services/PersonBrowserService.php`（`c_name`/`c_surname`/`c_mingzi` 等欄位的 LIKE 回退查詢），是需要 query 端正規化之處。
   - **FTS 全文索引（`NameSearchIndexService` / `CBDB__NAME_FTS`）以中文 `c_name_chn` 建立、非拼音**，不需 `v→ü`；搜尋兼容只針對拼音 LIKE 路徑，勿誤動 FTS。
4. **回歸測試**：拼音生成（含三批次 `buildPinyin`）、輸入正規化、拼音 LIKE 搜尋兼容、audit 記錄、複合主鍵 rowPk 解析（`ALTNAME_DATA` / `TEXT_INSTANCE_DATA`）、無主鍵表特例處理、西文名排除，皆補測試。
5. **相容性**：確認 `ü`（U+00FC）在 MariaDB（utf8mb4）與 SQLite 皆正常；Michael 已確認 `ü` 為合法非 ASCII（0xFC），不在先前清理的非法字元之列。
6. **重新顯示 `pinyin` 表於 codes 介面（本計畫最後一個環節）**：`pinyin`（姓氏拼音對照表）目前被 `config/codes.php` 的 `ui_hidden` 陣列（含 `'pinyin'`）從 `/codes` 首頁清單隱藏。待前述各環節完成、拼音資料已正規化為 `ü` 後，從 `ui_hidden` 移除 `'pinyin'`，讓它重新出現在 codes 表清單中供檢視／維護。
   - `pinyin` 已在 `config/codes.php` 的 `tables` 白名單（標籤「姓氏拼音對照表」），故只需移除 `ui_hidden` 一筆，無需新增其他設定。
   - 此為最後一步的原因：先讓資料與生成字典都改為 `ü`、確認顯示正常後再公開，避免使用者看到仍含 `v` 的過渡狀態。
   - 設計背景見 `docs/CODES_BOOLEAN_FILTER_DESIGN.md` §9.1（`ui_hidden` 僅影響首頁清單，不影響直連 `/codes/{table}` 與 Query Playground / NL / MCP 白名單）。

## 6. 風險與注意事項

- **西文名誤傷**（Silva/Calvin…）：靠音節規則 + 人工複核雙重把關；另注意 `c_*_proper`（母語原名）亦可能含真 `v`，不轉。
- **Wade-Giles 等非拼音欄位**（`c_*_rm`）：本次不動，避免破壞另一套羅馬化系統。
- **`BIOG_MAIN` 生成覆蓋**：名拼音為自動生成，務必先修來源再重生，否則直改值會被後續編輯覆蓋（§4.3）。
- **代碼 22 FK 前置**：`ALTNAME_DATA` 寫代碼 22 前必須先在 `ALTNAME_CODES` 建好該代碼，否則 FK 拒絕（§4.5）。
- **`ADDRESSES` 重建為 MySQL 限定**：`cbdb:regenerate-addresses-table` 用原始 SQL/暫存表，SQLite 不可跑（§4.4）。
- **派生表/視圖一致性**：只改源頭表，派生資料重建。
- **資料庫相容**：新寫的掃描/轉換指令遵守 `is_mysql()`/`is_sqlite()`；勿在原始 SQL 用 SQLite 不支援語法（既有 `ADDRESSES` 重建指令為已知例外）。
- **OneDrive 同步競態**：本 repo 受 OneDrive 同步，自動編輯可能被回寫；每完成一步盡快 commit（見 [[project_onedrive_edit_race]]）。

## 7. 執行順序與待辦帳本

> 順序要點：**先修拼音來源（生成字典 + DB pinyin 表），再重生 `BIOG_MAIN`**，否則直改會被生成覆蓋（§4.3）。

- [ ] 階段一：`cbdb:scan-pinyin-v` 唯讀掃描指令 + 報告，確認受影響表/欄位完整清單（含 §2-A 全部欄位）
- [ ] 與 Frank 的 Google Sheet 對齊欄位清單與西文名排除清單
- [ ] 來源修正：合併 PR #1086（`Pinyin::$dic`）+ 更新 DB `pinyin` 表姓氏為 `ü`
- [ ] 前置：確認／建立 `ALTNAME_CODES` 代碼 22「alternative romanization」（FK 需要，§4.5）
- [ ] 階段二：`cbdb:convert-pinyin-v --dry-run` 轉換指令（`Model::update()` + `AuditLogService`，禁裸 SQL）——處理 §2-A 所有資料表，含複合主鍵表（`ALTNAME_DATA`、`TEXT_INSTANCE_DATA`）與各單鍵 code 表
- [ ] 階段二：特例 `SOCIAL_INSTITUTION_ALTNAME_DATA`（無主鍵）另定合成識別或人工處理（§4.2）
- [ ] 階段二：`BIOG_MAIN` 受影響人物**重生**拼音（來源修正後）並經審計寫回（§4.3）
- [ ] 階段二：改 `ADDR_CODES` 後以 `cbdb:regenerate-addresses-table` 重建 `ADDRESSES`（MySQL only，§4.4）
- [ ] 階段二：人名代碼 22 別名寫入（搜尋兼容，附中文名 `c_alt_name_chn`）
- [ ] 階段二：dry-run 複核 → 正式執行 → audit 還原驗證
- [ ] 階段三：新建共用 `v→ü` normalize helper；掛入生成端（`auto_pinyin` + 三批次 `buildPinyin`）與搜尋端（`PersonBrowserService` 拼音 LIKE）
- [ ] 階段三：回歸測試（生成 / 正規化 / 搜尋 / audit / rowPk / 西文名排除）
- [ ] 文件同步：`CHANGELOG.md`、必要時 `DATABASE.md` / `README.md`
- [ ] **最後環節**：自 `config/codes.php` 的 `ui_hidden` 移除 `'pinyin'`，於 codes 介面重新顯示姓氏拼音對照表
