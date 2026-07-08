# SQLite Data Release

## 目的

此文件說明 CBDB SQLite 資料釋出流程，涵蓋本地產物、HuggingFace 上傳路徑與對外下載入口。

## 產物與命名

每次釋出會產生以下檔案：

1. SQLite 資料庫檔案（本地）
   - `db-data/cbdb_YYYYMMDD.sqlite3`
2. Metadata（本地）
   - `db-data/cbdb_YYYYMMDD.json`
3. Zip 產物（本地）
   - `db-data/cbdb_YYYYMMDD.zip`
4. Public 下載指向（本地）
   - `public/latest.zip`

Metadata 內容包含：
- `sha256`
- `generated_at_utc`
- `format`
- `huggingface_path`
- `huggingface_url`

## HuggingFace 釋出路徑

上傳時會放置在以下位置：

1. 歷史版本
   - `history/cbdb_YYYYMM/cbdb_YYYYMMDD.zip`
2. 最新版本捷徑
   - `latest.zip`
3. Metadata
   - `metadata/YYYY-MM/YYYY-MM-DD.json`
4. 最新 metadata
   - `latest.json`

### 最新下載入口

- `https://huggingface.co/datasets/cbdb/cbdb-sqlite/resolve/main/latest.zip`
- `https://input.cbdb.fas.harvard.edu/latest.zip`

## 釋出步驟

1. 執行每週同步腳本

```bash
./scripts/weekly-sqlite-sync.sh
```

2. 腳本流程包含：

- 先執行 `scripts/export-daily-sqlite.sh` 匯出資料與 metadata。
- 產生 `cbdb_YYYYMMDD.zip`（內含 `cbdb_YYYYMMDD.sqlite3` 與當日 metadata，且 zip 內不包含多層目錄）。
- 上傳至 HuggingFace 指定路徑。
- 在 HuggingFace 根目錄更新 `latest.zip` 與 `latest.json`。
- 將當日 zip 複製為 `public/latest.zip`。

## 已刪除人物過濾

匯出（`db:export-to-sqlite`，實作於 [app/Console/Commands/ExportMysqlToSqlite.php](../app/Console/Commands/ExportMysqlToSqlite.php)）會自動排除已被軟刪除的人物，避免其外流到公開釋出檔：

- 人物「刪除」是軟刪除：只把 `BIOG_MAIN.c_name_chn` 設為標記字串 `<待删除>`，列本身與 `c_personid` 不會被移除（見 [app/Services/Mutations/BiogMainDeleteHandler.php](../app/Services/Mutations/BiogMainDeleteHandler.php)）。
- 匯出 `BIOG_MAIN` 時排除 `c_name_chn = '<待删除>'` 的列（`c_name_chn` 為 `NULL` 視為正常，保留）。
- 匯出其餘表時，排除所有指向 `BIOG_MAIN.c_personid` 的欄位（含 `c_personid` 本身，以及透過正式 FK 宣告、由 `information_schema.KEY_COLUMN_USAGE` 動態偵測到的關係欄位，如 `KIN_DATA.c_kin_id`、`ASSOC_DATA.c_assoc_id` 等）屬於已刪除人物的列；一列只要有任一人物 ID 欄位命中已刪除人物即整列排除。
- 少數未宣告正式 FK、但語意上仍指向人物的欄位（目前僅 `MERGED_PERSON_DATA.c_merged_from_personid`）列在程式碼常數 `EXTRA_PERSON_ID_COLUMNS` 中，需手動維護。
- 正式匯出（來源為 MySQL）時，若 `information_schema` 查詢失敗，該表會被標記為匯出失敗（不會悄悄降級為「只過濾 `c_personid`」）；`export-daily-sqlite.sh` 仍會繼續處理其餘表，但只要有任何表失敗就以非零結束碼收尾，`weekly-sqlite-sync.sh`（`set -e`）因此不會繼續執行後面的壓縮與 `hf upload`，避免關係欄位過濾被無聲跳過又外流到公開釋出檔。

## 注意事項

- 若 `db-data/cbdb_YYYYMMDD.json` 不存在，zip 內只會包含 SQLite 檔案。
- `public/latest.zip` 會被覆寫為當日釋出檔案。
- 若需調整上傳路徑或命名，請同步更新：
  - `scripts/export-daily-sqlite.sh`
  - `scripts/weekly-sqlite-sync.sh`
