# CBDB 公共 API v1 說明

## 基本資訊
- 端點：`GET /cbdbapi/person.php`
- 參數：`id` (整數，必填)
- 內容格式：JSON
- `null` 欄位會輸出為空字串，符合 legacy API 的行為。

## 範例請求
```
GET /cbdbapi/person.php?id=1488
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
- `BasicInfo`：包含人物 ID、姓名、指數年份、朝代、出生/卒年、資料來源等欄位。
- `PersonSources.Source`：來源列表，欄位包含 `Source`、`SourceId`、`Pages`、`Notes`。
- `PersonSourcesAs.SourceAs`：若無資料為空字串。
- `PersonAliases.Alias`：別名列表，每筆含 `AliasType`、`AliasTypeId`、`AliasName`。
- `PersonAddresses.Address`：地址資訊，欄位含 `AddrTypeId`、`AddrType`、`AddrId`、`AddrName`、`belongs1_name`/`belongs1_id` 等。
- `PersonEntryInfo.Entry`：若無資料為空字串。
- `PersonPostings.Posting`：任官資訊，含職官、地址、起訖年份、出處等。欄位 `FirstYearNiaohaoYear`（`o` 應為 `n`）保留了舊版 API 的拼字錯誤以維持相容性。
- `PersonSocialStatus`、`PersonKinshipInfo`、`PersonSocialAssociation`、`PersonTexts`：若無資料則輸出空字串。

## 相容性備註
- 資料庫現行欄位為 `POSTED_TO_OFFICE_DATA.c_appt_code`（舊版為 `c_appt_type_code`）。早期 API 未同步更新欄位名稱，導致應為「1（正授）」等的除授類別經常回傳舊版預設值「0（未詳）」。本文件保留此歷史差異以利追蹤。
- 舊版 `/person.php` 透過 `xmlToJson.xsl` 將 XML 轉成 JSON；XSL 會在遍歷 `<Posting>` 節點時重複輸出同一筆資料，使得 `PersonPostings.Posting` 中的 `FirstYear` 等欄位被複製多次。新版控制器直接以 PHP 陣列輸出 JSON，不再出現此重複紀錄。

## 錯誤回應
- `422 Unprocessable Entity`：缺少 `id` 或 `id` 非正整數。
- `404 Not Found`：找不到對應人物。
