# CBDB 公共 API v1 說明

## 基本資訊
- 端點：`GET /cbdbapi/person.php`
- 預設回應為 HTML；若需 JSON，請於查詢參數加上 `o=json`（或 `mode=json` 亦相容）。
- 參數：
  - `id`：1-7 位數字（支持前置 0，如 `0001367`，兼容 Wikidata 格式）
  - `name`：字串
  - 至少需提供 `id` 或 `name` 其中之一；若同時提供則以 `id` 為主
- HTML 模式在 `name` 查詢時會於頁面上方列出最多 20 筆候選人物供點選；JSON 模式則回傳符合條件的第一筆結果。
- 內容格式：JSON
- `null` 欄位會輸出為空字串，符合 legacy API 的行為。

## 範例請求
```
GET /cbdbapi/person.php?id=1488&o=json
GET /cbdbapi/person.php?id=0001488&o=json      # Wikidata 格式（前置 0）
GET /cbdbapi/person.php?name=張三&o=json
GET /cbdbapi/person.php?id=1488                 # HTML 模式（無 o 參數）
```

## 回應結構
```json
{
  "Package": {
    "PersonAuthority": {
      "DataSource": "CBDB",
      "Version": "20131220",
      "PersonInfo": {
        "Person": {
          "BasicInfo": { ... },
          "PersonSources": {
            "Source": [ ... ]
          },
          "PersonSourcesAs": "",
          "PersonAliases": {
            "Alias": [ ... ]
          },
          "PersonAddresses": {
            "Address": [ ... ]
          },
          "PersonEntryInfo": "",
          "PersonPostings": {
            "Posting": [ ... ]
          },
          "PersonSocialStatus": "",
          "PersonKinshipInfo": "",
          "PersonSocialAssociation": "",
          "PersonTexts": ""
        }
      }
    }
  }
}
```

## 欄位說明

### 基本資訊
- `BasicInfo`：包含人物 ID、姓名、指數年份、朝代、出生/卒年、資料來源等欄位。

### 來源文獻
- `PersonSources.Source`：來源列表，欄位包含 `Source`、`SourceId`、`Pages`、`Notes`。
- `PersonSourcesAs.SourceAs`：保留原版 API 的欄位設計，通常僅用於中央研究院人名權威資料；其內容對應 `PersonSources.Source` 清單中的同一筆來源。

### 別名與地址
- `PersonAliases.Alias`：別名列表，每筆含 `AliasType`、`AliasTypeId`、`AliasName`。
- `PersonAddresses.Address`：地址資訊，欄位含 `AddrTypeId`、`AddrType`、`AddrId`、`AddrName`、`belongs1_name`/`belongs1_id` 等。

### 科舉與任官
- `PersonEntryInfo.Entry`：科舉資訊。若無資料為空字串。
- `PersonPostings.Posting`：任官資訊，含職官、地址、起訖年份、出處等。欄位 `FirstYearNiaohaoYear`（第一个 `o` 應為 `n`）保留了舊版 API 的拼字錯誤以維持相容性。

### 社會身份
- `PersonSocialStatus.SocialStatus`：社會身份資訊清單（來源：STATUS_DATA 表）。每筆包含：
  - `StatusId`：身份代碼
  - `StatusName`：身份名稱（中文）
  - `FirstYear`：起始年份
  - `LastYear`：終止年份

  若無資料則整個 `PersonSocialStatus` 欄位不會出現在回應中。

### 親屬關係
- `PersonKinshipInfo.Kinship`：親屬關係資訊清單（來源：KIN_DATA 表）。每筆包含：
  - `KinPersonId`：親屬人物 ID
  - `KinPersonName`：親屬人物姓名（中文）
  - `KinCode`：親屬關係代碼
  - `KinRel`：親屬關係（英文）
  - `KinRelName`：親屬關係名稱（中文）
  - `Source`：來源文獻標題
  - `Pages`：頁碼
  - `Notes`：註記

  若無資料則整個 `PersonKinshipInfo` 欄位不會出現在回應中。

### 社會關係
- `PersonSocialAssociation.Association`：社會關係資訊清單（來源：ASSOC_DATA 表）。每筆包含：
  - `AssocPersonId`：社會關係人物 ID
  - `AssocPersonName`：社會關係人物姓名（中文）
  - `AssocCode`：社會關係代碼
  - `AssocName`：社會關係名稱（中文）
  - `Year`：年份
  - `TextTitle`：文獻標題
  - `KinPersonId`：親屬人物 ID（若社會關係涉及親屬）
  - `KinPersonName`：親屬人物姓名
  - `KinRelName`：親屬關係名稱
  - `AssocKinPersonId`：關係人物的親屬 ID
  - `AssocKinPersonName`：關係人物的親屬姓名
  - `AssocKinRelName`：關係人物的親屬關係名稱
  - `Source`：來源文獻標題
  - `Pages`：頁碼
  - `Notes`：註記

  若無資料則整個 `PersonSocialAssociation` 欄位不會出現在回應中。

### 著作文獻
- `PersonTexts.Text`：人物相關文獻清單（來源：TEXT_CODES + BIOG_TEXT_DATA 表）。每筆包含：
  - `TextId`：文獻 ID
  - `TextName`：文獻標題（中文）
  - `Year`：年份
  - `Role`：該人物在文獻中的角色（如「作者」、「撰者」等）
  - `Source`：來源文獻標題
  - `Pages`：頁碼
  - `Notes`：註記

  若無資料則整個 `PersonTexts` 欄位不會出現在回應中。

## 相容性備註
- 資料庫現行欄位為 `POSTED_TO_OFFICE_DATA.c_appt_code`（原版為 `c_appt_type_code`）。原版 API 未同步更新欄位名稱，導致應為「1（正授）」等的除授類別經常回傳預設值「0（未詳）」。本次 API 實作將此問題修正；本文件保留此歷史差異以利追蹤。
- 原版 `/person.php` 透過 `xmlToJson.xsl` 將 XML 轉成 JSON；XSL 會在遍歷 `<Posting>` 節點時重複輸出同一筆資料，使得 `PersonPostings.Posting` 中的 `FirstYear` 等欄位被複製多次。新版控制器直接以 PHP 陣列輸出 JSON，不再出現此重複紀錄。
- 原版 API 期望 null 欄位輸出為空字串，現行 JSON API 亦採此方式，避免前端資料處理需要特殊判斷。
- `name` 查詢會優先以 BIOG_MAIN 的中文、英文與拼音欄位做全字比對，再依序比對 ALTNAME，若仍找不到才改用模糊查詢；命中後回傳第一筆結果。

## 版本更新記錄

### 2025-11-08：Wikidata 格式支持
- **ID 格式擴展**：支持 1-7 位數字，包含前置 0（如 `0001367`）
- **驗證改進**：從嚴格整數驗證改為正則表達式 `/^\d{1,7}$/`
- **錯誤處理優化**：
  - HTML 模式：顯示錯誤頁面而非重定向
  - JSON 模式：結構化錯誤響應
  - 統一使用 422 狀態碼表示驗證錯誤
- **兼容性**：完全向後兼容，原有的整數 ID 格式照常運作

### 測試用例
```bash
# ✅ 標準格式
curl "https://cbdb.example.com/cbdbapi/person.php?id=1367&o=json"

# ✅ Wikidata 格式（前置 0）
curl "https://cbdb.example.com/cbdbapi/person.php?id=0001367&o=json"

# ✅ 最大長度（7 位）
curl "https://cbdb.example.com/cbdbapi/person.php?id=1234567&o=json"

# ❌ 超過 7 位（返回 422）
curl "https://cbdb.example.com/cbdbapi/person.php?id=12345678&o=json"

# ❌ 非數字格式（返回 422）
curl "https://cbdb.example.com/cbdbapi/person.php?id=abc123&o=json"
```

## 錯誤回應

### 驗證錯誤 (422 Unprocessable Entity)

**觸發條件**：
- 未提供 `id` 或 `name` 參數
- `id` 格式不符（非 1-7 位數字）
- `id` 包含非數字字符
- `id` 超過 7 位數

**JSON 模式回應範例**：
```json
{
  "error": {
    "code": 422,
    "message": "Validation failed.",
    "details": [
      "The id format is invalid."
    ]
  }
}
```

**HTML 模式回應**：
- 返回帶有錯誤信息的頁面（紅色警告框）
- 列出所有驗證失敗的原因
- 不再重定向到首頁

### 資源不存在 (404 Not Found)

**觸發條件**：
- 找不到對應的人物記錄
- 查詢的 ID 已經被合併到其他人物

**JSON 模式回應範例**：
```json
{
  "error": {
    "code": 404,
    "message": "Person not found.",
    "merge_hint": {
      "merged_to_person_id": 12345,
      "reason": "Duplicate record merged"
    }
  }
}
```

**HTML 模式回應**：
- 對於 `id` 查詢：顯示「找不到該人物」的提示頁面；若該 ID 已被合併，會顯示藍色提醒框，列出新的 CBDB ID 並附上合併理由（若存在），可直接點擊前往該人物
- 對於 `name` 查詢：顯示「找不到符合條件的人物」提示
