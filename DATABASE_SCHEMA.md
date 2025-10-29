# 資料庫結構說明

## 基線遷移
- 2025 年 10 月已透過 `database/migrations/2025_01_01_000000_import_cbdb_schema.php` 追溯導入完整的 CBDB 結構。  
- Migration 內嵌 `cbdb_schema.sql` 原始內容，只會對尚未存在的資料表執行 `CREATE TABLE`，不包含 `DROP TABLE` 或其他破壞性語句，確保在既有資料上安全地補齊 schema。  
- 由於這是一份歷史基線，`down()` 無對應刪除邏輯。

## 既有資料注意事項
- 若資料庫已手動同步至最新 schema，只需執行 `php artisan migrate` 讓 Laravel 記錄遷移狀態；Migration 會跳過已存在的資料表。  
- 若在其他環境新建資料庫，可先初始化空 schema，再執行 `php artisan migrate` 取得完整表結構。  
- 任何附加的資料表調整請另外建立遷移檔，避免直接修改基線檔案。

## 測試與維護
- 在 CI 或開發端使用 SQLite 測試時，請自行建立必要的表結構（Feature/Unit 測試內已提供部分建表腳本）。  
- 如需更新基線 schema，建議先匯出新的 SQL，再評估是否以遷移增量方式處理，而非覆寫既有檔案。
