# CODES 介面整合備忘

## 現況
- `/codes/{table}` 為唯一介面：`CodesController` 依網址動態查詢資料表，提供列表、CRUD、提案／審核等完整流程。
- 2025-11 起移除 6 個既有的 Vue 單頁（`/altnamecodes`、`/addrcodes`、`/appointcodes`、`/officecodes`、`/socialinstitutioncodes`、`/textcodes` 與其對應 API），原功能已整合回 `/codes/*`。
- 2025-11 起將 `/addrbelongsdata` 和 `/textinstancedata` 整合至 `/codes/ADDR_BELONGS_DATA` 和 `/codes/TEXT_INSTANCE_DATA`。
  - 新增頁面提供欄位說明和連結，方便用戶查看相關代碼表。
  - `TEXT_INSTANCE_DATA` 提供 Load Data 功能，可從 `TEXT_CODES` 自動載入書籍資訊。
- 常用 *_CODES 表的欄位順序與 Vue 版本一致，並會：
  - 自動排序主鍵欄位並預估下一個編號（含複合鍵組合）。
  - 在新增／編輯時計入 `c_created_*`、`c_modified_*` 與 `operations` 日誌。
- **只讀表**：部分表格設為只讀模式，僅供查詢，禁止新增、修改或刪除：
  - `CBDB__NAME_FTS`：姓名搜尋倒排索引（內部輔助表，由系統自動維護）
  - `DYNASTIES`：朝代代碼
  - `GANZHI_CODES`：干支代碼
  - 繁簡字符映射（原 `CBDB__TRAD_SIMP_MAP`）已不是代碼表，改為 vendored 原始字典檔
    `third_party/opencc/TSCharacters.txt`（`php artisan cbdb:sync-opencc-trad-simp` 更新），見
    [NAME_SEARCH_COMMANDS.md](./NAME_SEARCH_COMMANDS.md)

## 剩餘差異
- **互動方式**： `/codes/*` 目前仍採伺服器端分頁＋單欄位搜尋，沒有即時過濾或頁碼捷徑；若未來需要可再評估加入前端元件。
- **API**：原 Vue 專用的 `/api/*code` 查詢已下線；其他 `select/*` 查詢 API 照常提供給表單自動完成使用。

## 後續建議
1. 觀察使用者是否需要即時搜尋，若有可在 `/codes/*` 上加入可配置的 Vue/Alpine 元件。
2. 持續擴充 `tests/Feature/CodesControllerTest.php` 覆蓋更多資料表與邊界情境，確保欄位覆寫與預設值行為穩定。
