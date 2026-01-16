# Batch Uploaders

本文件整理目前後台提供的批次匯入工具。這些工具皆僅限「活躍管理員」使用，入口位於側欄 **Management** 區塊。匯入成功後會寫入對應資料表，並透過 `operations` 留下操作紀錄以利稽核與還原。

## 共通注意事項

- 只有登入且 `is_active = 1`、`is_admin = 1` 的使用者可以存取 `/admin/batch-load-*`。
- 所有欄位皆以 **Tab** (`\t`) 分隔，匯入時會逐行解析；空行會被忽略。
- 匯入期間會使用資料庫交易，單行失敗會終止整批匯入並回傳錯誤訊息。
- 系統不會自動建立參照資料（如朝代、官職類型、來源文本等），請事先確認資料庫已有對應紀錄。

---

## 批次匯入書稿 `/admin/batch-load-book-titles`

### 功能概述

一次性建立多筆 `TEXT_CODES`，並記錄操作紀錄。適用於快速導入著作書目。

### 輸入格式

每行需包含三欄：

1. **作者 CBDB ID**（整數）
2. **書名（中文）**
3. **來源 `TEXT_ID`**（整數）

範例：

```
12345<TAB>某某書稿<TAB>54321
```

### 系統處理

- 自動分配 `c_textid`（目前為 `max(c_textid) + 1`）。
- 替書名清理多餘空白與標點（冒號前後留空格，冒號之後的卷次資訊僅保留於原始欄位）。
- 使用 `VariantCharNormalizer` 標準化異體字後，透過 `App\Models\Pinyin` 生成 `c_title`（拼音）；異體字（如「菴」→「庵」）會在拼音轉換時自動標準化，但原始書名保持不變；非漢字會保留原字詞。
- 透過 `BIOG_MAIN` 取得作者朝代 (`c_dy`)，若查無資料則保留空值。
- `c_text_type_id` 固定為 `01`（可視需求後續調整）。
- 所有新增紀錄的 `c_notes` 會帶上批次編號（格式：`[YYYYMMDDHHMMSS]`）。
- `ToolsRepository::timestamp` 會填入 `c_created_by`、`c_created_date`；`c_modified_*` 保持 `NULL`。

### 驗證

- 作者 ID、來源 TEXT_ID 必須為整數；來源必須存在於 `TEXT_CODES`。
- 書名不得為空。
- 若整批無有效資料會回傳錯誤提示。

### 影響資料表

- `TEXT_CODES` 新增多筆。
- `operations` 以 `op_type = 1`（新增）記錄每筆建立。

---

## 批次匯入社會機構 `/admin/batch-load-social-institutes`

### 功能概述

同時建立 `SOCIAL_INSTITUTION_NAME_CODES`、`SOCIAL_INSTITUTION_CODES` 與 `SOCIAL_INSTITUTION_ADDR`。若機構名稱已存在，會重用原始 `c_inst_name_code`。

### 輸入格式

每行需包含六欄：

1. **機構名稱（中文）**
2. **類型（中文或拼音）** — 需能對應 `SOCIAL_INSTITUTION_TYPES` 的 `c_inst_type_hz` 或 `c_inst_type_py`
3. **朝代（中文）** — 需能對應 `DYNASTIES.c_dynasty_chn`
4. **地址名稱**（僅提示用途，可留白）
5. **地址 ID**（整數，必須存在於 `ADDR_CODES`）
6. **來源 `TEXT_ID`**（整數，需存在於 `TEXT_CODES`）

範例：

```
南浦書院<TAB>書院<TAB>清<TAB>浦城<TAB>7793<TAB>4763
```

### 系統處理

- 先檢查名稱是否已存在：
  - 若已存在，沿用既有 `c_inst_name_code` 與拼音。
  - 若不存在，分配新 `c_inst_name_code`、使用 `VariantCharNormalizer` 標準化異體字後以 `App\Models\Pinyin` 生成拼音並建立名稱紀錄。
- 逐筆分配 `c_inst_code`（`max(c_inst_code) + 1`），寫入 `SOCIAL_INSTITUTION_CODES`。
- 為每筆建立對應的 `SOCIAL_INSTITUTION_ADDR`，`c_inst_addr_type_code` 固定使用 `1`，座標預設為 `0`。
- 每個步驟皆寫入 `operations`，包含名稱（視需要）、機構本體、地址變動。

### 驗證

- 檢查類型、朝代、地址 ID、來源 TEXT_ID 是否存在。
- 地址與來源欄位必須為整數；名稱、類型、朝代不可為空。
- 任一行出錯會終止整批匯入並顯示錯誤訊息。

### 影響資料表

- `SOCIAL_INSTITUTION_NAME_CODES`
- `SOCIAL_INSTITUTION_CODES`
- `SOCIAL_INSTITUTION_ADDR`
- `operations`

---

## 批次匯入官職 `/admin/batch-load-offices`

### 功能概述

匯入多筆 `OFFICE_CODES`，並同步建立 `OFFICE_CODE_TYPE_REL` 關聯。適合批量導入職名／職系資料。

### 輸入格式

每行需包含六欄：

1. **中文職名**
2. **英文職名**（可空白，但建議提供）
3. **朝代（中文）** — 對應 `DYNASTIES.c_dynasty_chn`
4. **官職類型 ID** — 對應 `OFFICE_TYPE_TREE.c_office_type_node_id`
5. **所屬單位**（備註，僅於結果表格展示）
6. **來源 `TEXT_ID`**（整數，需存在於 `TEXT_CODES`）

範例：

```
宗人府供事<TAB>Clerk in the Imperial Clan Court<TAB>清<TAB>200501<TAB>宗人府<TAB>4763
```

### 系統處理

- 逐筆檢查朝代、類型 ID、來源是否存在。
- 自動分配 `c_office_id`（`max(c_office_id) + 1`）。
- 使用 `VariantCharNormalizer` 標準化異體字後，以 `App\Models\Pinyin` 轉換中文職名為 `c_office_pinyin`。
- `OFFICE_CODES` 新增紀錄後，立即建立 `OFFICE_CODE_TYPE_REL`。
- 在 `operations` 中分別記錄兩張表的新增行為。

### 驗證

- 朝代、類型、來源皆需存在；來源必須為整數。
- 職名不可為空；類型 ID 不可為空。
- 若整批無有效資料則回傳提示。

### 影響資料表

- `OFFICE_CODES`
- `OFFICE_CODE_TYPE_REL`
- `operations`

---

## 測試與維護

- 新增的批次匯入器皆搭配 Feature 測試：
  - `AdminBatchLoadBookTitlesTest`
  - `AdminBatchLoadSocialInstitutesTest`
  - `AdminBatchLoadOfficesTest`
- `/operations`、`/modified` 也補充了回歸測試（`OperationsIndexDiffTest`、`ModifiedIndexDiffTest`），確保在缺少關聯資料或列表為空時頁面仍能正常渲染。
- 若日後新增其它批次工具，建議同步更新此文件並補測試。
