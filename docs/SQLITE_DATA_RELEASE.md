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

- `https://huggingface.co/datasets/cbdb/cbdb-sqlite/blob/main/latest.zip`

## 釋出步驟

1. 執行每週同步腳本

```bash
./scripts/weekly-sqlite-sync.sh
```

2. 腳本流程包含：

- 先執行 `scripts/export-daily-sqlite.sh` 匯出資料與 metadata。
- 產生 `cbdb_YYYYMMDD.zip`（內含 `cbdb_YYYYMMDD.sqlite3` 與當日 metadata）。
- 上傳至 HuggingFace 指定路徑。
- 在 HuggingFace 根目錄更新 `latest.zip` 與 `latest.json`。
- 將當日 zip 複製為 `public/latest.zip`。

## 注意事項

- 若 `db-data/cbdb_YYYYMMDD.json` 不存在，zip 內只會包含 SQLite 檔案。
- `public/latest.zip` 會被覆寫為當日釋出檔案。
- 若需調整上傳路徑或命名，請同步更新：
  - `scripts/export-daily-sqlite.sh`
  - `scripts/weekly-sqlite-sync.sh`
