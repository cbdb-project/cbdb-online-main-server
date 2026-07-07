# Code 表受審計寫入 API 建設計畫

> 狀態：**決策定案（可執行；本文 §D 為權威，與其下較早敘述衝突時以 §D 為準）**
> 分支：`feature/pinyin-v-to-umlaut-migration`（實作各小環節各起新分支、各自 PR）
> 關聯計畫：[拼音 v → ü 全庫正規化遷移計畫](./PINYIN_V_TO_UMLAUT_MIGRATION.md)（本計畫為其**階段 B 的前置依賴**）
> English version: [CODE_TABLE_MUTATION_API_PLAN.en.md](./CODE_TABLE_MUTATION_API_PLAN.en.md)

## §D. 決策定案（LLM 照此執行，勿再確認）

- **D-1 無主鍵表 `SOCIAL_INSTITUTION_ALTNAME_DATA`：直接 SKIP。** 該表無編輯入口，**排除於本 API 與遷移之外、不處理**（不採「合成識別鍵」方案）。下文所有關於此表的「合成 key／特例處理」段落一律視為**不執行、僅跳過**。
- **D-2 `/codes` 管理介面審計缺口：本計畫範圍內補齊。** 於 `CodesController` 直寫路徑（`store`/`update`/`destroy`）加 `AuditLogService::write()`，使 UI 與新 API 審計一致（從「可選後續」升為**必做**）。
- **D-3 `person_id` 契約：實作者自選、二擇一，皆以測試兜住、非阻塞。**
  - (a) 改 `MutationController`：code resource（`person_id_column === null`）時 `person_id` 可選——**須以回歸測試確保既有人物子資源行為不變**（缺 person_id 仍 422、交叉校驗仍觸發）；或
  - (b) **零控制器改動**：外部腳本對 code 表照送 `person_id: 0`（沿用 `NianHaoMutationHandler` 既有做法）。
- **D-4 `ADDRESSES` 派生表：** 改完 `ADDR_CODES` 後以 `cbdb:regenerate-addresses-table`（生產、MySQL-only）**重建**。
- **D-5 Phase B 的「改哪些」以掃描規則為準、不需人工 Sheet：** code 表基本純拼音（實測：`ETHNICITY.c_name` 498 列 11 條含 v 全為真拼音、0 西文；`CHORONYM` 173 列 1 條 `Vietnam` 不含 `lv/nv` 音節、規則天然排除）。故**專用拼音欄與 romanized-name 欄一律以確定性 `lv/lve/nv/nve` 音節規則直接替換**；掃描命令另出 `[OTHER-v]` 小清單供人類瞄一眼（安全網）。`ADDR_CODES` 於 Phase B 起步以只讀掃描實錘後再寫。
- **D-6 保存時拼音 v→ü 歸一化（止血 2.0，比照階段 A §D-12）：本計畫一併納入。** 階段 A 已對人名**保存路徑**補上手動輸入止血（`PinyinUmlaut::normalizeFields()`，見 [PINYIN_SAVE_NORMALIZE_DESIGN.md](./PINYIN_SAVE_NORMALIZE_DESIGN.md)）；code 表批次清乾淨後，其**手動輸入面**與**外部 API** 同樣會重新累積 `v`，故一併加掛，否則批次修正的成果會被日後錄入重新污染。
  - **掛點（三處）**：
    1. 新建的 **`AbstractCodeTableMutationHandler`**（一次覆蓋所有 code 表 API 寫入，最省事）；
    2. **`CodesController`** 寫入路徑（`store`/`update` 與各 proposal 方法；`destroy` 為刪除、無需歸一化）——其中 `store`/`update` **與 §D-2 補 `audit_log` 的掛點重疊**、可順手一起加，proposal 方法則為 §D-6 專屬（§D-2 未涵蓋、但會持久化欄位值故需歸一化）；
    3. **`AdminBatchLoadBookTitlesController::updatePinyin()`**（書名內聯編輯，現僅做大小寫／空白；其批次 `buildPinyin()` 已用 `PinyinUmlaut`——此為修正 inline 與 batch 的不一致）。
  - **每表拼音欄登錄（registry）＝與 §D-5 共用同一份名單**：v→ü 只能套在**確定是漢語拼音的欄**，須有「表→拼音欄」清單以排除英文譯名（如 `OFFICE_CODES.c_office_trans`）、中文欄（`c_*_chn`）、與語義存疑的另種羅馬化欄。此清單**即 §D-5 批次遷移所用的「專用拼音欄／romanized-name 欄」名單**——批次修正與保存止血**共用一個 registry**，避免兩份清單漂移。泛型直寫（`CodesController` 對任意表寫任意欄）**必須**先查 registry、只歸一化命中欄，嚴禁盲套（否則誤傷 Wade-Giles／譯名欄）。
  - **Tier 分流（2026-07 以本機 CBDB 全量副本實測 + Hongsu 定案）**：多數 code 拼音欄為純拼音、走 **Tier 1 後端靜默轉**；另有**具名的「混合欄」**（拼音與西文/英文夾雜）走 **Tier 2 altname 式彈窗**——**復用 §D-12 已建的前端偵測+彈窗機制**（`resources/js/inertia/utils/pinyinUmlaut.ts` + 對話框），差別只在通用 `/codes` 編輯 UI 需依下方「(表,欄)→Tier」登錄表決定是否彈窗，且後端對 Tier 2 欄**不 silent 轉**（尊重使用者於彈窗的選擇）。此登錄表即上一條所述「與 §D-5 共用的拼音欄名單」的具體實現（多加一欄 Tier）。

    | 表.欄 | Tier | 依據 |
    |---|---|---|
    | `OFFICE_CODES.c_office_pinyin`、`c_office_pinyin_alt` | Tier 1 | Hongsu：明確拼音（`_alt`＝別名拼音，非另種羅馬化） |
    | `NIAN_HAO.c_nianhao_pin`、`GANZHI_CODES.c_ganzhi_py`、`TEXT_BIBLCAT_CODES.c_text_cat_pinyin`、`SOCIAL_INSTITUTION_NAME_CODES.c_inst_name_py`、`SOCIAL_INSTITUTION_TYPES.c_inst_type_py`、`ADMIN_CAT_CODES.c_admin_cat_py` | Tier 1 | 純拼音欄（`_py`／`_pinyin`） |
    | `ETHNICITY_TRIBE_CODES.c_name` | Tier 1 | Hongsu：是拼音；§D-5 實測 498 列、11 個 v 全為真拼音、0 西文 |
    | `TEXT_CODES.c_title`、`TEXT_INSTANCE_DATA.c_instance_title` | Tier 1 | 羅馬化書名（批載器 `buildPinyin` 已用 `PinyinUmlaut`）；`c_instance_title` 比照 `c_title`（同性質，未逐一實測、Phase B 起步時抽驗） |
    | **`ADDR_CODES.c_name`** | **Tier 2** | 混英文地名+拼音（實測：`Lvchuan→Lüchuan` 等命中正確、`Soviet Far East`／`Vietnam` 規則不動）；Hongsu：手動錄入用 altname 提示 |
    | **`ETHNICITY_TRIBE_CODES.c_romanized`** | **Tier 2** | 混拼音+西文（實測 206 列、含 v 3：`Kitan-Yelv`／`Kitan-Shulv`→ü 正確、`Bavard` 規則略過）；Hongsu：用 altname 提示 |
    | **`ETHNICITY_TRIBE_CODES.c_surname`** | **Tier 2** | 實測 52 列、0 v；未來錄入用 altname 提示 |
    | **`DYNASTIES.c_dynasty`** | **Tier 2** | 含英文朝代名（如 `Five Dynasties`）；保持原狀、未來錄入用 altname 提示 |
    | **`CHORONYM_CODES.c_choronym_desc`** | **Tier 2** | 含外文（如 `Vietnam`）；保持原狀、未來錄入用 altname 提示 |
    | `ADDR_CODES.c_alt_names` | **排除** | 實測 1516 非空、含拉丁字母 0＝純中文、無拼音，永不轉 |

    > 批次遷移：Tier 1 直接套規則；Tier 2 亦套**同一規則**但**只轉命中且經人眼確認的列**（西文保持原狀），存量命中極少（`ADDR.c_name` 數十筆、`c_romanized` 2 筆〔含 v 共 3、其中 `Bavard` 為西文不轉〕、`c_surname`／`c_dynasty`／`c_choronym_desc` 皆 0）。故 Tier 2 對批次幾乎無額外負擔，主要價值在**擋未來手動錄入**。
  - **測試**：每個掛點回歸（Tier 1 欄手打 `lv` 讀回 `lü`；Tier 2 欄手打 `lv` 觸發彈窗、選轉/保留生效、選保留後端不覆寫；英文譯名／中文欄不受影響；registry 未列的欄不轉；書名 inline 與 batch 行為一致）。
- **範圍調整**：因 D-1，本 API 的目標表**移除 `SOCIAL_INSTITUTION_ALTNAME_DATA`**；因 D-2，**加入 `CodesController` 補審計**一項；因 D-6，**加入保存時 v→ü 歸一化**一項（掛於 `AbstractCodeTableMutationHandler` + `CodesController` + 書名內聯，依共用 registry）。

## 0. 背景與動機

拼音遷移計畫的**階段 B**需要對 code／lookup 表（地名、官名、年號、書名、社會機構、行政類別等）做**受審計的批次資料修正**。修正必須走受審計流程（寫 `audit_log`）、可由外部腳本以 token 呼叫，且不得用繞過 audit 的集中式 SQL。經評估現況，此能力**大致缺失**，需新建。

## 1. 現況評估

### 1.1 `/codes` 管理介面（`CodesController`）
- **通用**：直接寫入路徑（`store`/`update`/`destroy`）由 `config/codes.php` 的 `tables` 驅動，可對任意 code 表的任意欄位寫入；複合主鍵以 URL 編碼（`col1_._col2_._col3`）處理。
- **但只寫 `operations`，不寫 `audit_log`**：三個直寫方法都呼叫 `recordOperation()` → `OperationRepository::store()`（寫 `operations` 表），**全程未呼叫 `AuditLogService::write()`**。
- **不適合外部批次**：為 `web` 路由，需 session + CSRF token；非 token API。
- 結論：適合**人工 UI 修改**（記於 `operations` 供審核），但**不滿足**「外部腳本 + 完整 audit_log」需求。

### 1.2 v2 mutation API（`/api/v2/*`）
- 具備完整審計管線：交易、`AuditLogService::write()`（寫 `audit_log`）、`OperationRepository::store()`（寫 `operations`）、`CompositePrimaryKey` 主鍵驗證、欄位白名單、變更偵測、proposal 模式。
- handler 經 `MutationHandlerRegistry` 註冊分派。
- **但 code 表幾乎沒有 handler**：唯一例外是 **`NianHaoMutationHandler`（NIAN_HAO）**——它直接繼承 `AbstractMutationHandler`、將 `person_id` 設為 `0`、照常寫 `audit_log` + `operations`。其餘 code 表（`ADDR_CODES`、`OFFICE_CODES`、`DYNASTIES`、`CHORONYM_CODES`、`ETHNICITY_TRIBE_CODES`、`TEXT_CODES`、`TEXT_INSTANCE_DATA`、`TEXT_BIBLCAT_CODES`、`GANZHI_CODES`、`SOCIAL_INSTITUTION_*`、`ADMIN_CAT_CODES` 等）**皆無 handler**。

### 1.3 結論
- **審計與主鍵基礎設施已就緒**（`NianHaoMutationHandler` 已證明 code 表可走 mutation API 並完整審計）。
- **缺的是各 code 表的 handler 與註冊**，以及一個讓擴充最小化的共用基底。
- 規模估計：**中等**（約 300–500 LOC，視表數與是否抽共用基底）。

## 2. 目標與範圍

- **目標**：讓外部腳本能以 **Bearer token**、經 **audit_log** 審計、可複核地修改 code 表欄位（首要為拼音欄位，但設計為通用欄位寫入）。
- **範圍**（對齊拼音遷移階段 B；依 §D-1 已**移除** `SOCIAL_INSTITUTION_ALTNAME_DATA`）：`ADDR_CODES`、`OFFICE_CODES`、`DYNASTIES`、`NIAN_HAO`（已具備，作為樣板）、`CHORONYM_CODES`、`ETHNICITY_TRIBE_CODES`、`TEXT_CODES`、`TEXT_INSTANCE_DATA`、`TEXT_BIBLCAT_CODES`、`GANZHI_CODES`、`SOCIAL_INSTITUTION_NAME_CODES`、`SOCIAL_INSTITUTION_TYPES`、`ADMIN_CAT_CODES`。
- **非目標**：不改既有 person sub-resource handler 行為。（註：`/codes` UI 補 audit **已改為必做**，見 §D-2，不再是「可選後續」。）

## 3. 可重用的現有基礎

- `app/Services/AuditLogService.php`：`write()` 已支援任意表名。
- `OperationRepository::store()`：`personId` 可為 `0`（code 表適用）。
- `app/Support/CompositePrimaryKey.php`：`SCHEMAS` 需登錄 code 表主鍵；`validateOrFail()`、`buildStoredResourceId()` 可重用。
- `MutationHandlerRegistry` / `Api/MutationController` / `MutationReadService`：handler 註冊、分派與 resource 定義。
- **樣板**：`app/Services/Mutations/NianHaoMutationHandler.php`（code 表審計寫入的可行範例）。

## 4. 設計

- **共用基底 `AbstractCodeTableMutationHandler`**：整合交易、`audit_log`、`operations`、PK 驗證、欄位白名單、變更偵測等樣板邏輯（含防呆：`keyColumns()` 必須與 `CompositePrimaryKey::SCHEMAS` 一致，否則 500，杜絕部分鍵 UPDATE 命中多列）。
  - **實作定案：改用單一 config 驅動的 `ConfigCodeTableMutationHandler` + `config/code_table_mutations.php`**，而非每表寫子類——13 張表（NIAN_HAO ＋ 12 張新表）高度均質（只差表名/主鍵/白名單常數），config 驅動可免去 handler／registry 樣板膨脹，且此 config 之後可原地承載 §D-6 的「表→拼音欄 Tier」登錄。基底仍保留抽象方法設計，**需要客製驗證等特殊行為的表可另寫子類**。`NIAN_HAO` 已併入此 config（原 `NianHaoMutationHandler` 子類刪除，22 個 API 測試行為不變）。
- **`person_id` 契約（採 §D-3 選項 b：零控制器改動）**：code 表 handler 內部一律把 `operations.c_personid` 設為 0、忽略呼叫端傳入值；呼叫端仍依 `MutationController` 契約傳 `person_id`（通常 0）。**不改 `MutationController`**——避免動到既有 person 子資源的必填校驗與交叉檢查。
- **主鍵**：
  - 單鍵與複合鍵（如 `TEXT_INSTANCE_DATA` 3 鍵）登錄 `CompositePrimaryKey::SCHEMAS`。
  - **無主鍵表 `SOCIAL_INSTITUTION_ALTNAME_DATA`：直接 SKIP、不處理**（見 §D-1；不建合成識別鍵）。
- **認證／授權**：沿用 Sanctum **Bearer token**、`active` 且非 crowdsourcing（`canWriteDirectly()`）；保留 `direct` / `proposal` 兩模式。
- **端點**：沿用 `/api/v2/mutate`、`/api/v2/create`、`/api/v2/delete`，以 `resource` 字串路由到對應 code 表 handler。
- **resource_id 編碼一致性**：複合主鍵的 `resource_id` 須與 `CodesController` / `OperationsController` 既有格式對齊，避免 `operations` 連結解析失準。

## 5. 實作步驟

1. 在 `CompositePrimaryKey::SCHEMAS` 登錄各 code 表主鍵（單鍵與複合鍵）。
2. 在 `MutationReadService` 的 definitions 新增各 code 表 resource（`person_id_column: null` + aliases）。
3. 新建 `AbstractCodeTableMutationHandler` 共用基底。
4. 為各表新建 concrete handler（首要 `update`；必要時 `create` / `delete`），並把 `NianHaoMutationHandler` 重構至新基底。
5. 在 `MutationHandlerRegistry` 註冊各 handler。
6. 調整 `MutationController`：code resource 時 `person_id` 可選。
7. 無主鍵表 `SOCIAL_INSTITUTION_ALTNAME_DATA`：**SKIP、不處理**（§D-1）。另新增：於 `CodesController` 直寫路徑補 `AuditLogService::write()`（§D-2）。
8. 測試：每表 `update` + `audit_log` 斷言（old/new、operation_id）、複合主鍵解析、授權（active／非 crowdsourcing）、`direct`/`proposal` 模式、SQLite/MariaDB 相容。

## 6. 風險與注意事項

- **`person_id` 契約改動**：須回歸測試既有 person sub-resource handler 不受影響。
- **無主鍵表**：`SOCIAL_INSTITUTION_ALTNAME_DATA` **SKIP、不處理**（§D-1）。
- **複合主鍵 resource_id 一致性**：與既有 `CodesController` / `OperationsController` 編碼對齊。
- **UI 與 API 落差**：`/codes` UI 目前只寫 `operations`、不寫 `audit_log`；**本計畫必做**把 `CodesController` 直寫路徑補上 `AuditLogService::write()`（§D-2）。
- **資料庫相容**：遵守 `is_mysql()` / `is_sqlite()`。

## 7. 與拼音遷移計畫的關係

- 本計畫是 [拼音遷移計畫](./PINYIN_V_TO_UMLAUT_MIGRATION.md) **階段 B（其他非人名拼音欄位）的前置依賴**。
- 完成本 API 後，階段 B 的 code 表拼音修正即可比照階段 A 人名，以**外部腳本 + 受審計 mutation API** 進行。
- 階段 A（人名）**不依賴**本計畫——人名走既有的 `basicinformation` / `altnames` mutation handler 即可。
- **止血兩半在此對齊**：階段 A 的「保存時止血」由 [PINYIN_SAVE_NORMALIZE_DESIGN.md](./PINYIN_SAVE_NORMALIZE_DESIGN.md) 覆蓋人名保存路徑；code 表的對應一半（§D-6）內建於本計畫（新 handler + `CodesController` + 書名內聯），與批次修正共用 §D-5 的每表拼音欄 registry。兩者合起來，code 表在階段 B 之後也不再產生新的 `v`。

## 8. 待辦帳本

- [x] 登錄 code 表主鍵於 `CompositePrimaryKey::SCHEMAS`（＋同步 `OperationsController::resourceKeyColumns()`）
- [~] `MutationReadService` 新增 code 表 resource 定義（`person_id_column: null`）——**update 路徑不需**（`store()` 只用 registry）；待 GET/create/delete 才補
- [x] 新建 `AbstractCodeTableMutationHandler` 共用基底（M1）
- [x] code 表 update handler——改以 config 驅動 `ConfigCodeTableMutationHandler` + `config/code_table_mutations.php`（13 表＝ `NIAN_HAO` ＋ 12 新表）；原 `NianHaoMutationHandler` 子類刪除
- [x] `MutationHandlerRegistry` 註冊（以 `ConfigCodeTableMutationHandler` 取代 `NianHaoMutationHandler`）
- [x] `person_id` 契約：採 §D-3 選項 b（handler 內部固定 0、**`MutationController` 零改動**）
- [ ] 無主鍵表 `SOCIAL_INSTITUTION_ALTNAME_DATA`：**SKIP、不處理**（§D-1）
- [x] 測試（`ApiV2MutateCodeTablesTest`：單鍵/多欄/三欄/複合三鍵 update・audit・person_id=0・白名單拒絕・proposal・404・403；`ApiV2MutateNianHaoTest` 22 項不變）
- [ ] **（必做，§D-2）** `CodesController` 直寫路徑補 `AuditLogService::write()`，使 UI 與 API 審計一致
- [x] **（§D-6）** 「表→拼音欄 Tier」registry——落於 `config/code_table_mutations.php` 每表的 `tier1_fields`／`tier2_fields`（與 allowed_fields 一致；與 §D-5 名單同源）
- [x] **（§D-6）** 保存時 v→ü 歸一化**後端三掛點全數完成**：(1) `AbstractCodeTableMutationHandler`／`ConfigCodeTableMutationHandler`（v2 API）；(2) `CodesController`（`store`/`update`／proposal store/update/updateExisting，依 config tier1_fields 只轉 Tier 1、Tier 2 不動、非 Phase B 表不動）；(3) `AdminBatchLoadBookTitlesController::updatePinyin()`（書名 c_title Tier 1）。
- [x] **（§D-6）** **前端 Tier 2 彈窗完成**：通用 `/codes` 編輯器（`Codes/Create`、`Codes/Edit`）於保存前對本表 `tier2_fields`（controller 傳入之 prop）以規則偵測 v→ü，命中才彈窗（`PinyinUmlautConfirmDialog`，復用 `pinyinUmlaut.ts`）由使用者「轉換／保留」；`form.transform` 保證提交送出選定值。
- [x] **（§D-6）** 測試：後端 `ApiV2MutateCodeTablesTest`／`CodesControllerTest`／`AdminBatchLoadBookTitlesTest`；前端 `pinyinUmlaut.test.ts`（`collectUmlautConversions` 多欄偵測）＋ `CodesCreateInertiaTest`（`tier2_fields` prop：config 表為 `['c_name']`、非 config 表為 `[]`）。
- [ ] 文件同步：`AGENTS.md` 模組入口、必要時 `CHANGELOG.md`
