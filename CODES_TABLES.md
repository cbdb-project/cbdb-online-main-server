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
| 1 | ADDR_CODES |
| 2 | ALTNAME_CODES |
| 3 | APPOINTMENT_CODES |
| 4 | ASSOC_CODES |
| 5 | ASSUME_OFFICE_CODES |
| 6 | BIOG_ADDR_CODES |
| 7 | BIOG_INST_CODES |
| 8 | CHORONYM_CODES |
| 9 | COUNTRY_CODES |
|10 | ENTRY_CODES |
|11 | ETHNICITY_TRIBE_CODES |
|12 | EVENT_CODES |
|13 | EXTANT_CODES |
|14 | GANZHI_CODES |
|15 | HOUSEHOLD_STATUS_CODES |
|16 | INDEXYEAR_TYPE_CODES |
|17 | KINSHIP_CODES |
|18 | LITERARYGENRE_CODES |
|19 | MEASURE_CODES |
|20 | OCCASION_CODES |
|21 | OFFICE_CODES |
|22 | PARENTAL_STATUS_CODES |
|23 | PLACE_CODES |
|24 | POSSESSION_ACT_CODES |
|25 | SCHOLARLYTOPIC_CODES |
|26 | SOCIAL_INSTITUTION_ALTNAME_CODES |
|27 | SOCIAL_INSTITUTION_CODES |
|28 | SOCIAL_INSTITUTION_NAME_CODES |
|29 | STATUS_CODES |
|30 | TEXT_BIBLCAT_CODES |
|31 | TEXT_CODES |
|32 | TEXT_ROLE_CODES |
|33 | YEAR_RANGE_CODES |

> 建議：若新增或移除代碼表，請同步更新本文件與 `config/codes.php`，並在部署環境重新執行 `php artisan config:cache` 以確保新設定生效。
