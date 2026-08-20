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

## 釋出範圍契約：只匯出 allowlist 內的 CBDB 資料表

**釋出檔的內容由 `scripts/export-daily-sqlite.sh` 的 `TABLES=(...)` allowlist 決定**（目前 78 張 CBDB 資料表與代碼表），腳本逐表呼叫 `php artisan db:export-to-sqlite --tables="$TABLE"`（第二張起帶 `--append`）。這是釋出範圍的**第一道也是最重要的防線**：

- **allowlist 內不得出現任何帳號／憑證表**（`users`、`personal_access_tokens`、`password_resets`、`sessions`、`oauth_*` 等，完整清單見 `App\Console\Commands\ExportMysqlToSqlite::CREDENTIAL_TABLES`）。
- 也不含 Laravel／應用自己的營運表（`migrations`、`operations`、`audit_log`、`nl_query_logs`、`ai_fill_logs`、`person_change_index`、`char_variant_map`、`pinyin` 等）。其中 `audit_log` 含 email 與登入 IP／User-Agent、`operations`／`nl_query_logs`／`ai_fill_logs` 含使用者輸入與 user_id，屬個人資料；其餘（如 `migrations`）只是與公開資料集無關的營運資料。因此下游若要用釋出檔跑本機開發，必須自己補 Laravel 需要的表：見 [scripts/patch_sqlite_db_for_dev.sh](../scripts/patch_sqlite_db_for_dev.sh) 與 [README-Docker.md](../README-Docker.md)。

上述兩條由**三道防線**守住：

1. **產物自檢（繞不過去的那道）**：`scripts/export-daily-sqlite.sh` 在匯出完成後、產生 metadata 之前，對輸出檔跑 `php artisan cbdb:assert-sqlite-release-scope "$OUTPUT_FILE" --min-tables="${#TABLES[@]}"`（實作於 [app/Console/Commands/AssertSqliteReleaseScope.php](../app/Console/Commands/AssertSqliteReleaseScope.php)），`scripts/weekly-sqlite-sync.sh` 在打包上傳前再跑一次。它直接讀產物的 `sqlite_master`（表與檢視都算）並檢查三件事：
   - 不含 `ExportMysqlToSqlite::CREDENTIAL_TABLES` 內的帳號／憑證表（單獨列出只為了給出明確的錯誤訊息，它們本來也不在下面那份 allowlist 裡）；
   - 每個名稱都必須出現在 [`App\Support\SqliteReleaseTables::PUBLIC_TABLES`](../app/Support/SqliteReleaseTables.php) 這份**精確集合**裡——**這一條才是守住上面第二點的東西**，`audit_log`、`operations`、`nl_query_logs` 都不在憑證名單裡，只比對憑證名會整批漏掉。刻意不用「全大寫就算公開 CBDB 表」的形狀判準：那會放行任何日後新增的大寫表，而 `AUDIT_LOG_ARCHIVE`、`USER_LOGIN_EVENTS` 這種名字一樣全大寫、卻是個資；
   - 不含檢視（view）。檢視也會被下面的表數算進去，若不擋掉，78 個「與 allowlist 同名的空檢視」就能冒充一份完整產物；
   - 表數不得少於 `--min-tables`（**預設就是 allowlist 的長度**；釋出腳本另外顯式傳 `${#TABLES[@]}`，把產物表數與那份 shell 清單綁在一起）。空檔與被截斷的產物都是合法的 SQLite，只看「有沒有壞表名」會直接放行。子集 ＋ 拒絕檢視 ＋ 下界 ＋ 表名不重複，合起來等於「產物的表集合恰好是那 78 張」。

   一律 fail-closed：檔案不存在／不是普通檔案／開不起來／`--min-tables` 不是數字都算失敗。特別是「不存在」——`new PDO("sqlite:missing")` 會建出空白資料庫並回「0 張表、沒問題」，那是最危險的假通過。行為由 [tests/Feature/AssertSqliteReleaseScopeTest.php](../tests/Feature/AssertSqliteReleaseScopeTest.php) 實際執行驗證。
2. **釋出腳本的閘門測試（會真的跑 bash）**：[tests/Feature/ReleaseScriptSelfCheckGateTest.php](../tests/Feature/ReleaseScriptSelfCheckGateTest.php) 把假的 `php` 放到 `PATH` 最前面驅動整份 `export-daily-sqlite.sh`，斷言自檢失敗時腳本以非零結束碼收尾、且沒有產生 metadata；並斷言自檢拿到的路徑就是實際產出的那個檔案。這條測試不能用文字比對取代：`| tee`、`&& false`、漏掉的 `exit 1`、甚至一句 `set +e`，都能讓「自檢呼叫還在」而結束碼被吞掉。自檢因此寫成**裸呼叫 + `set -e`**，沒有可以吞結束碼的位置。
3. **靜態契約測試**：[tests/Feature/SqliteReleaseAllowlistTest.php](../tests/Feature/SqliteReleaseAllowlistTest.php) 檢查腳本文字（allowlist 只能有一處字面宣告、必須是字面清單、**逐項等於 `SqliteReleaseTables::PUBLIC_TABLES`**、匯出迴圈必須真的迭代 `"${TABLES[@]}"`、每個 `--tables` 恰好是 `"$TABLE"`、只有兩處匯出呼叫、自檢是裸呼叫且排在 metadata 之前）。

腳本的 `TABLES=(...)` 與 `SqliteReleaseTables::PUBLIC_TABLES` 是**兩份必須一致的清單**：前者驅動逐表匯出（意圖），後者是驗收產物的 oracle（事實），由上面第 3 點逐項比對。**新增 CBDB 表時兩邊都要加**；只改一邊會在測試就紅，不會拖到每週釋出的凌晨。之所以不讓腳本直接讀那份常數（那樣只有一份），是因為 `mapfile -t TABLES < <(php artisan …)` 在命令失敗時會靜默得到空陣列（`mapfile` 的結束碼不反映 process substitution 內的失敗），釋出範圍就變成由執行環境決定。

為什麼需要疊這幾道：靜態檢查看的是「意圖」，任何一層間接（`TABLES+=`、換個變數名餵 `for` 迴圈、`source` 外部檔、`eval`）都可能讓實際匯出範圍與清單不一致，追不完；產物自檢看的是**真正要上傳的那個檔案**；閘門測試證明的是「自檢失敗真的會擋下流程」。反過來，靜態測試的價值在於「有人動了釋出範圍的形狀時會被看到」。

真正擋下上傳的是**結束碼**：`weekly-sqlite-sync.sh` 有 `set -e` 且以裸 `bash scripts/export-daily-sqlite.sh` 呼叫匯出（沒有 `|| true`、不在 pipeline 或條件式裡），所以匯出腳本一非零就整條中止。不要以為「沒產生 metadata」本身能擋住：`weekly-sqlite-sync.sh` 在 metadata 不存在時只會印警告，然後照樣上傳 zip。

新增 CBDB 表時要顯式加進那兩份 allowlist（不會自動被收錄）；**不要**把腳本改成「動態取全部表」——那會讓釋出範圍變成由來源資料庫決定，等於把上述契約交給環境。

命令本身另有一層防禦：`db:export-to-sqlite` 預設會匯出憑證表的**結構**但跳過其**資料列**（見 `CREDENTIAL_TABLES` 的 docblock 與 issue #1251）。那一層是為了保護「開發者裸跑這個命令」，不是釋出範圍的依據——釋出範圍以 allowlist 為準。

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
