# /codes 列表白名單說明

此文件用來記錄 `/codes` 後台功能允許瀏覽或編輯的代碼表清單，需同步維護 `config/codes.php`（或環境變數 `CODES_TABLES`）。未列於白名單的資料表，即便透過 URL 嘗試訪問，也會被系統回傳 404。

## 管理方式

1. **主要設定**：`config/codes.php` 的 `tables` 陣列。
2. **環境覆蓋**：部署環境可透過 `.env` 的 `CODES_TABLES` 指定，格式為以逗號分隔的表名，例如：
   ```
   CODES_TABLES=ALTNAME_CODES,TEXT_CODES,ADDR_CODES
   ```
3. **未配置情況**：若程式未讀到上述設定，`CodesRepository` 會回傳空陣列，此時所有 `/codes/*` 路徑將拒絕存取。

## 目前白名單

| # | 資料表名稱 |
|---|------------|
| 1 | ADDR_BELONGS_DATA |
| 2 | ADDR_CODES |
| 3 | ADDRESSES |
| 4 | ALTNAME_CODES |
| 5 | APPOINTMENT_CODES |
| 6 | ASSOC_CODES |
| 7 | ASSUME_OFFICE_CODES |
| 8 | BIOG_ADDR_CODES |
| 9 | BIOG_INST_CODES |
|10 | CHORONYM_CODES |
|11 | COUNTRY_CODES |
|12 | DYNASTIES |
|13 | ENTRY_CODES |
|14 | ETHNICITY_TRIBE_CODES |
|15 | EVENT_CODES |
|16 | EXTANT_CODES |
|17 | GANZHI_CODES |
|18 | HOUSEHOLD_STATUS_CODES |
|19 | INDEXYEAR_TYPE_CODES |
|20 | KINSHIP_CODES |
|21 | LITERARYGENRE_CODES |
|22 | MEASURE_CODES |
|23 | MERGED_PERSON_DATA |
|24 | OCCASION_CODES |
|25 | OFFICE_CODES |
|26 | PARENTAL_STATUS_CODES |
|27 | PLACE_CODES |
|28 | POSSESSION_ACT_CODES |
|29 | SCHOLARLYTOPIC_CODES |
|30 | SOCIAL_INSTITUTION_ALTNAME_CODES |
|31 | SOCIAL_INSTITUTION_CODES |
|32 | SOCIAL_INSTITUTION_NAME_CODES |
|33 | STATUS_CODES |
|34 | TEXT_BIBLCAT_CODES |
|35 | TEXT_CODES |
|36 | TEXT_INSTANCE_DATA |
|37 | TEXT_ROLE_CODES |
|38 | YEAR_RANGE_CODES |

> 建議：若新增或移除代碼表，請同步更新本文件與 `config/codes.php`，並在部署環境重新執行 `php artisan config:cache` 以確保新設定生效。

> 備註：`DYNASTIES` 與 `GANZHI_CODES` 目前在泛用 `/codes` 介面中為只讀表，僅允許瀏覽與搜尋。
