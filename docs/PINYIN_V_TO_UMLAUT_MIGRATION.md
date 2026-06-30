# 拼音 v → ü 全庫正規化遷移計畫

> 狀態：計畫草案（提交討論）
> 分支：`feature/pinyin-v-to-umlaut-migration`
> 相關 PR：[#1086](https://github.com/cbdb-project/cbdb-online-main-server/pull/1086)（生成字典修正，已併入 #1087）
> English version: [PINYIN_V_TO_UMLAUT_MIGRATION.en.md](./PINYIN_V_TO_UMLAUT_MIGRATION.en.md)

## 0. 背景與決議

CBDB 拼音規範長期以 `v` 代替 `ü`（如 `呂 = Lv`、`閭丘 = Lvqiu`、`耶律 = Yelv`）。經 Frank Lin、Song Chen（陳松）、Hongsu、Michael Fuller、Peter Bol 的郵件討論，達成以下共識：

1. **`ü` 為唯一正字（canonical）**，全庫拼音欄位不再以 `v` 代替（依《漢語拼音方案》）。
2. **輸入時接受 `v`**：手動錄入與搜尋時 `v` 自動轉 `ü`，方便鍵盤輸入。
3. **舊 `v` 形式可保留為「另一種羅馬化」別名**以兼容搜尋（Michael 提出的 `ALTNAME_DATA` 代碼 **22 alternative romanization**）；此為可選、非必須項。
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
- **唯一兼容缺口**：習慣打 **`v`** 的使用者（如 `Lv`、`Yelv`）。對此有兩個可選方案（皆可延後、非阻塞）：
  1. 將舊 `v` 形式以 `ALTNAME_DATA` 代碼 22 作別名（見 §5）；
  2. 在查詢層把 `v → ü` normalize。
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
> **無主鍵特例**：`SOCIAL_INSTITUTION_ALTNAME_DATA` 沒有 PRIMARY KEY（僅兩個普通索引、欄位皆 nullable）。因審計需要逐列定位鍵，此表不可走逐列審計修改；須另定合成識別或人工處理。
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
- 建議批量節奏：一次數百筆，或**一個姓氏一批**，先 dry-run / 取樣複核再正式送出。

### 5.3 別名兼容（可選、延後）
- 若決定保留舊 `v` 形式供搜尋兼容：以 `POST /api/v2/create`、`resource: "altnames"`、`c_alt_name_type_code: 22`、`c_alt_name: <v 形式>`、並附 `c_alt_name_chn`（PK 需要中文名）建立別名列。
- **前置條件**：`ALTNAME_CODES` 目前無 seed，須先確認/建立代碼 22「alternative romanization」（含中英說明），否則 FK 會拒絕未知代碼。
- 依共識，此步在**資料清理完成後**再加；是否必須無強烈定論。

### 5.4 盤點工具
- 仍建議提供唯讀 artisan 指令 `php artisan cbdb:scan-pinyin-v`：掃描 §4 候選欄位、依音節規則分類（疑似拼音 / 疑似西文名）、輸出 CSV 供人工複核並對齊 Frank 的 Google Sheet。此指令**只讀不寫**，可安全在生產執行。

## 6. 分階段執行計畫（採納 Frank 建議）

1. **止血（Stop the bleed）**：以 `ü` 為新資料正字；做必要的前端／生成端修改，防止再寫入新的 `v`。
   - 合併 PR #1086（`Pinyin::$dic`：`lv/lve/nv → lü/lüe/nü`）+ 更新 DB `pinyin` 表姓氏。
   - 在「漢字→拼音」生成入口加 `v → ü` 正規化（已核實入口）：`BiogMainRepository::auto_pinyin()`、`ApiController::buildPinyinWord()` / `searchPinyin()`、三個批次匯入各自的 `buildPinyin()`（`AdminBatchLoadOfficesController`、`AdminBatchLoadBookTitlesController`、`AdminBatchLoadSocialInstitutesController`）；這些路徑生成前已呼叫 `VariantCharNormalizer::normalize()`，是自然掛點。需新建共用 `v→ü` helper（目前 repo 無此 helper）。
   - 註：`nve` 在生成字典中本就不存在；`nve → nüe` 僅在輸入正規化端有意義。
2. **修正高可見人名拼音**：透過審計 mutation API 批次修正（§5.2），分批（數百筆／一姓氏一批）。
3. **（可選）保留舊 `v` 形式供搜尋兼容**：以代碼 22 別名（§5.3），於資料清理完成後再加；非必須。
4. **建議下游系統與 Access 版**適時加上 `v → ü` 查詢兼容（溝通、非阻塞）。
5. **持續掃描、修正其他非人名拼音欄位**（階段 B，§4）。

## 7. 風險與注意事項

- **西文名誤傷**（Silva/Calvin…）：靠音節規則 + 人工複核；`c_*_proper`、`c_*_rm`、譯名欄位不動。
- **搜尋現況**：`u` 已能命中 `ü`（collation 折疊）；僅 `v` 需兼容，且可延後（§3）。SQLite 測試不折疊，撰寫測試須注意。
- **代碼 22 FK 前置**：寫代碼 22 前須先於 `ALTNAME_CODES` 建立該代碼（§5.3）。
- **階段 B 審計路徑**：code 表目前僅 `NIAN_HAO` 有 mutation handler，需先建受審計寫入 API（見 [Code 表受審計寫入 API 建設計畫](./CODE_TABLE_MUTATION_API_PLAN.md)）才動工（§4）。
- **無主鍵表**：`SOCIAL_INSTITUTION_ALTNAME_DATA` 須特例處理（§4）。
- **派生表一致性**：`ADDRESSES` 只改源頭 `ADDR_CODES` 後以 `cbdb:regenerate-addresses-table` 重建（該指令為 MySQL 限定，SQLite 不可跑）。
- **禁止繞過 audit 的集中式 SQL**：所有資料修改走 mutation API 或受審計流程。

## 8. 執行順序與待辦帳本

- [ ] 階段一（止血）：合併 PR #1086 + 更新 DB `pinyin` 表姓氏為 `ü`
- [ ] 階段一（止血）：新建共用 `v→ü` helper，掛入生成入口（`auto_pinyin` + 三批次 `buildPinyin` + `ApiController`）防止新 `v`
- [ ] 盤點：`cbdb:scan-pinyin-v` 唯讀掃描 + 報告，對齊 Frank 的 Google Sheet 與西文名排除清單
- [ ] 階段二（人名）：外部腳本走 `/api/v2/mutate` 批次修正 `c_surname`/`c_mingzi`（`c_name` 由系統自動重算），分批、先 dry-run 複核
- [ ] （可選）確認／建立 `ALTNAME_CODES` 代碼 22，再以 `/api/v2/create` 補別名（資料清理完成後）
- [ ] 溝通下游系統與 Access 版，建議適時加 `v→ü` 查詢兼容
- [ ] 階段 B 前置：依 [Code 表受審計寫入 API 建設計畫](./CODE_TABLE_MUTATION_API_PLAN.md) 建立 code 表受審計寫入 API
- [ ] 階段 B：API 就緒後，分批掃描修正其他拼音欄位（含 `TEXT_INSTANCE_DATA` 複合主鍵、`SOCIAL_INSTITUTION_ALTNAME_DATA` 無主鍵特例、`ADDRESSES` 重建）
- [ ] 回歸測試（生成 / 正規化 / 人名修正 / audit / 西文名排除；注意 SQLite collation 差異）
- [ ] 文件同步：`CHANGELOG.md`、必要時 `DATABASE.md` / `README.md`
- [ ] **最後環節**：自 `config/codes.php` 的 `ui_hidden` 移除 `'pinyin'`，於 codes 介面重新顯示姓氏拼音對照表
