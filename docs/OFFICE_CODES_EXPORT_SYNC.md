# OFFICE_CODES 即時導出與下游同步計畫

> 狀態：**M0 已通過兩道關卡（review agent + codex 均判 go）**，可進 M1。
> 範圍：本輪只做 `OFFICE_CODES`；`ADDR_CODES` / `ADDRESSES` 押後。
> 涉及 repo：`cbdb-online-main-server`（本 repo）、`cbdb-project/code-office`。

---

## 0. Schema 現況與契約決策（最重要，先讀）

審查過程發現一個會推翻舊假設的事實，已釐清並定案：

- **live `OFFICE_CODES` 現在是 11 欄**，不是 16 欄。baseline migration（`2025_01_01_000000_import_cbdb_schema.php:1367`）原本 16 欄，但後續兩支 migration 已刪除其中 5 欄：
  - `2025_12_29_172644_drop_unused_old_id_columns.php` → 刪 `c_office_id_old`
  - `2026_04_24_000000_drop_category_columns_from_office_codes.php` → 刪 `c_category_1`~`c_category_4`
- **這 5 欄（`c_category_1~4`、`c_office_id_old`）下游不需要**（維護者確認）。
- **下游 `code_office.py` 只讀 index 0、1、3、5**（`c_office_id` / `c_dy` / `c_office_chn` / `c_office_chn_alt`），且**對整列不做欄數檢查**——全部落在現有 11 欄內。
- **決策：新契約 = live 的 11 欄**。`code_office.py` 完全相容。下游 `OFFICE_CODES.txt` 會在首次 `update.py` 同步後由 16 欄縮成 11 欄，**此為預期的格式改版，非錯誤**（見 §7 M3）。
- **M0 / M1 邊界**：M0 只在**本文件**鎖定這組 11 欄契約與順序；真正把它落地成 `config('codes.export_columns.OFFICE_CODES')` 是 **M1**，不是 M0。

> 教訓：判斷 schema 不能只看 baseline migration，要看「baseline + 後續所有 migration」的疊加結果。本文以下所有「11 欄」即指現況 live schema。

---

## 1. 背景與緣由

使用者（Yan）在 `input.cbdb.fas.harvard.edu/codes/OFFICE_CODES` 頁面上希望**下載最新的整張 `OFFICE_CODES` 表**，拿去餵給她使用的 `cbdb-project/code-office` repo（該 repo 由本 org 維護，Yan 是使用者），但頁面上找不到下載按鈕——因為目前 `/codes/{table}` 只是分頁檢視（`paginate` + 搜尋 + 排序），**從未提供任何 export / download 功能**。

`code-office` 的資料消費鏈現況：

- `OFFICE_CODES.txt`（repo 內 commit 的純文字檔）是 `code_office.py` 的**直接輸入**。
- `code_office.py` 以自訂的 `read_office()` **按欄位位置**解析該檔（不靠表頭），底層用 `csv.reader(delimiter='\t')`（**quote-aware**）——欄內 tab 靠雙引號保護，見 §5.2。
- 因此這個檔案的**欄序與格式就是契約**，差一欄即解析錯位。

---

## 2. 需求分析

- 需求形狀：**「改了就要、平時不用」的脈衝型即時需求**。例：週中為某同事新增兩個 office code，他隔天就要用。
- 任何固定週期都滿足不了脈衝：CBDB 周更 SQLite（已存在、已是權威基線）對週中新增的 code 必然落後。
- 新增 code **直接寫入 live `OFFICE_CODES` 表**，不經 proposal/approval 流程（已與維護者確認）。因此「讀 live = 拿到最新」成立，不需處理審批延遲。
- 長期/歷史軌跡需求由**現有的周更 SQLite** 覆蓋，本計畫**不需要 cron / 排程**。

結論：真正要的是「**能取得 DB 當下狀態**」的能力，觸發完全交給人在需要時手動執行。

---

## 3. 設計決策（含取捨）

### 採用
- **單一基礎件 = 一支「即時反映 live DB」的全量導出 endpoint**（在 main server）。它永遠最新，因為直接讀庫。
- **下游用 `update.py` 當客戶端**：消費者自行決定「直接拿本地檔跑」或「先 `update.py` 刷新再跑」。刷新是顯式 opt-in，正好對上脈衝需求。

### 被否決 / 不採用
- ❌ **agent 爬 `/codes` HTML 分頁**：脆弱、不可重現、不可稽核。
- ❌ **人肉手動導出寄檔**：維護者變瓶頸、資料漂移、每次喬格式。
- ❌ **下載按鈕當主機制**：仍需人點＋人貼，不解決「下游檔案如何更新」。可作為次要 self-service（見 §5.1 末），但非核心。
- ❌ **cron / GitHub Action 定期 commit**：周更 SQLite 已是基線，無需再加。
- ❌ **格式選擇器（csv/tsv/xlsx/各種分隔符）**：假問題。下游早已統一為 **tab 分隔 UTF-8 `.txt`**，對齊現狀即可。
- ❌ **server 端持 GitHub token 主動 push 到 repo**：活動零件最多、需保管寫入憑證；脈衝需求用 `update.py` 已足夠。

### 關鍵原則
> **endpoint（伺服端）與 `update.py`（客戶端）是互補，不是二選一。**
> endpoint 是脈衝即時性無法繞過的 live 來源；`update.py` 只是按需去拉它的客戶端。

---

## 4. 架構總覽（跨 repo）

| Repo | 改動 | 角色 |
|---|---|---|
| `cbdb-online-main-server`（本 repo） | 新增全量導出 API endpoint | 伺服端 / live 來源 |
| `cbdb-project/code-office` | 新增 `update.py` | 客戶端 / 按需刷新 |
| `cbdb-project/code-addr` | **本輪不動** | 維持周更 HF（見 §8） |

資料流：

```
新增 office code → 直接落 live OFFICE_CODES 表（11 欄）
                      │
   同事執行 update.py │  GET /codes/OFFICE_CODES/export
                      ▼
   main server 串流導出（tab-CSV / quote-aware, 無表頭, UTF-8, LF, 11 欄）
                      │
                      ▼
   覆寫 code-office/OFFICE_CODES.txt（驗證後原子替換；首次同步 16→11 欄）
                      │
                      ▼
   同事照常跑 code_office.py（read_office 只讀 index 0/1/3/5，不變）
```

---

## 5. 詳細規格

### 5.1 main server — 導出 endpoint

- **路由**（`routes/web.php`）：
  `Route::get('codes/{table_name}/export', 'CodesController@export')->name('codes.export');`
  - 不衝突：`{table_name}` 不跨 `/`，雙段 `codes/OFFICE_CODES/export` 不會被單段 show（`web.php:186`）吃掉；既有帶 `where('id','.*')` 的路由皆為 POST/PATCH/DELETE 或需 `/edit` 後綴，**無 bare GET `codes/{table}/{id}`**。順序非必要，鄰近 `create` 註冊即可。
- **控制器** `CodesController@export(Request $request, string $table_name)`：
  - 先過 `guardTable($table_name)`，**再檢查該表是否存在於 `config('codes.export_columns')`**；不存在則 `abort(404)`。
  - **export 範圍（API 邊界，明文鎖定）**：**僅支援在 `config('codes.export_columns')` 明確配置匯出欄位的表；本輪只有 `OFFICE_CODES`**。其他 `/codes/{table}/export`（即使通過 `guardTable`，如 `ADDR_CODES`）一律 **404**。route 維持泛用 `codes/{table_name}/export`，但行為由 config 白名單收斂——未來要開放新表，只需在 `export_columns` 加一筆，無需改 route/controller。
  - **欄位來源**：讀 `config('codes.export_columns.OFFICE_CODES')` 的明確有序清單（11 欄，見附錄；**該清單在 M0 只存在於本文件，M1 才新增對應 config 鍵**），**不重用** `getTableColumns()`（後者回整表、欄序綁 DB schema、且可能含 audit 欄）。
  - **欄位漂移 fail-fast**：以 `Schema::getColumnListing('OFFICE_CODES')` 驗證 config 的 11 欄**全部存在**（**僅驗存在性**，不驗順序——輸出順序由下一點的 `select(config 欄序)` 保證）；缺欄/改名即回 **500 + 明確訊息**，寧可壞掉也不可輸出錯位資料。（11 欄目前與 live schema 一致。）
  - **輸出語意 = tab 分隔的 CSV（quote-aware），非裸 TSV**：用 CSV 寫出（delimiter=`\t`、quotechar=`"`、QUOTE_MINIMAL、內嵌引號加倍），與下游 `csv.reader(delimiter='\t')` 對齊。**禁止裸 `implode("\t")`**（現檔已有含內嵌 tab 的引號欄，裸 join 會破壞邊界）。詳見 §5.2。
  - **串流**：`StreamedResponse` + 對 `getKeyColumns($table)` 回傳的主鍵欄 `orderBy`（OFFICE_CODES = 單一主鍵 `c_office_id`，見附錄 migration）後 `chunk(2000)`，**禁止 `->get()`**。chunk 需穩定排序；`c_office_id` 升冪亦滿足下游 `update.py` 的嚴格遞增要求。
  - **限流**：加 `throttle:6,1`；可選 60s 短期回應快取——人工觸發頻率遠低於此，對「即時」無損卻能擋爬蟲爆量。此 endpoint **直連 live 生產庫**，限流為必要保護。
  - Header：`Content-Type: text/plain; charset=UTF-8`；`Content-Disposition: attachment; filename="OFFICE_CODES.txt"`。
  - **不提供格式查詢參數**：輸出固定為唯一契約格式（無表頭、tab-delimited、quote-aware CSV）。與 §3「不做格式選擇器」一致；未來若真需多格式再議，**不納入本輪 API 契約**。
- **授權**：唯讀公開（CC BY-NC-SA）。**已確認**：`/codes` 不在 auth group（`web.php` auth group 始於 L218）、`show`（`CodesController.php:298`）內無 `Auth` 守衛（對比 `update/store/destroy` 內皆有 `Auth::check()`）→ show 公開，export 對齊即公開。
  - **關於 AGENTS.md §5「新路由須有後端授權檢查」**：本 endpoint 為**有意識的正當例外**（維護者已拍板公開唯讀）——(a) 僅唯讀、無 mutation；(b) 暴露的 `OFFICE_CODES` 為 CC BY-NC-SA 公開參考資料，且同一資料經已公開的 show 頁本即可瀏覽（export 只是改成批量下載）；(c) 本案核心需求是 `update.py` 以 `urllib` **無憑證自助拉取**，加授權會破壞此前提；(d) 以 `throttle:6,1` 限流防爬蟲爆量。
- **順手滿足原始痛點**：在 `/codes/OFFICE_CODES` show 頁加一個指向 export 的下載連結，近乎零成本直接回應 Yan「找不到下載鈕」的最初訴求。

### 5.2 格式保真度規格（**最高風險、必須逐項對齊**）

**目標格式 = live 11 欄、quote-aware tab-CSV、無表頭、UTF-8 無 BOM、LF。** 現有 repo 檔為 16 欄歷史格式（含已刪的 5 欄），其前 11 欄與目標一致；首次同步後檔案縮為 11 欄。

由現場**實測**，現有 `OFFICE_CODES.txt`（16 欄歷史檔）的格式特性（除欄數外，目標 11 欄沿用同樣規則）：

- **無表頭**（第一行即資料）
- **tab 分隔 + CSV quote 語意**（quotechar=`"`）：全檔 2 列含引號，其中第 30385 列欄值**引號內含字面 tab**，靠引號保護欄位邊界。**故這不是裸 TSV**（純 `split('\t')` 會把該列判為多 1 欄）。內嵌 tab 出現在 index 3（`c_office_chn`）等前段欄位，11 欄格式同樣會遇到，**必須 quote**。
- **UTF-8，無 BOM**（首 byte 為資料 `30`，非 `EF BB BF`）
- **LF（`\n`）換行，無 CR；檔尾無 trailing newline**（最後一 byte 為 tab）
- **33,957 筆**（`csv.reader` 解析；`wc -l`=33,956 因無末尾換行少算 1），**已按 `c_office_id` 升冪排序**

實作約束：

1. **欄位順序是契約**：M0 階段先由**本文件附錄**鎖定 11 欄的有序清單；M1 再把同一清單寫入 `config('codes.export_columns')`。endpoint 之後以 `select(config 欄序)` 輸出，故契約順序**最終由 M1 的 config 決定、與 live schema 的物理欄序無關**（`getColumnListing` fail-fast 只驗存在性，不驗順序）。`read_office` 按位置解析，欄名對它無意義，但位置/順序必須穩定。
2. **輸出用 CSV writer（tab delimiter + QUOTE_MINIMAL + quotechar `"`）**，與下游 `csv.reader` 對齊：欄值含 `\t`、`\n`、`"` 時才加引號、引號加倍。**不得 strip/replace 欄內字元**。
3. **不加 BOM、輸出 LF、無表頭**（`read_office` 按位置解析，加表頭會整體錯位/KeyError）。
4. **型別字串化**：所有欄一律 `(string)` 直出，**不做 locale/數值格式化**（不補零、不科學記號）；NULL → 空字串（與現檔一致：連續 tab 即空欄）。
5. **trailing newline 不納入比對**（見 §6）：endpoint 可每列補 `\n`（串流自然寫法），現檔無末尾換行——因驗收採「列集合等價」而非逐 byte，故不衝突。

### 5.3 code-office — `update.py`

- 僅用 Python **標準庫**（`urllib` + `csv` + `os` + `tempfile`），不強加外部依賴。
- 行為：
  1. `GET` endpoint（設 timeout，如 30s）→ 串流寫入**與 `OFFICE_CODES.txt` 同目錄**的暫存檔（確保 `os.replace` 為同卷原子操作）。
  2. **先驗 HTTP**：狀態碼 == 200、`Content-Type` 含 `text/plain`（擋登入轉址/錯誤頁回 HTML）。
  3. **再驗結構**：以 `csv.reader(delimiter='\t')` 解析；非空；**逐列**檢查欄數 == **11**（**非 `split('\t')`**，否則含引號內嵌 tab 的列會誤判）。此外，將第 0 欄視為 `c_office_id` 做**更強但仍屬防呆級**的檢查：每列都須能 `int()`、整檔須**嚴格遞增且不可重複**（對齊伺服端 `orderBy('c_office_id')`）。  
     - 這樣做的目的，是避免「欄數剛好 11、但欄序或內容已漂移，而第 0 欄碰巧還是整數」的壞檔被誤收。  
     - 但也要明講：**`update.py` 無法單靠客戶端檢查獨立證明欄序正確**；欄序正確性的主保證仍在 M1/M2 的 server 端契約（固定 config 欄序 + export 測試）。`update.py` 這裡做的是消費端防呆，不是唯一真相來源。  
     - 任一列失敗即報出**該列號**並中止、保留原檔。
  4. 驗證通過 → `os.replace` **原子替換** `OFFICE_CODES.txt`；任一步失敗 → 保留原檔、非零退出 + 明確訊息。
  5. 印出更新前後**列數（row count）**差異；另偵測並提示**欄數（column count）變化**——首次同步會是 16→11，屬預期改版。
- 寫出：`encoding='utf-8'`、`newline=''`、**不寫 BOM**（保 LF）。
- 設定：endpoint URL 以常數預設（指向正式站 `/codes/OFFICE_CODES/export`），可由 **環境變數 / 命令列參數** 覆寫。
- 可選 `--dry-run`：只報告差異不覆寫。
- **OneDrive 注意**：目標在 OneDrive 同步目錄，replace 時可能遇同步鎖/檔案佔用；失敗訊息需可辨識此情形。

---

## 6. 驗收標準

- **格式/欄序（嚴格、自動化）**：以固定 fixture 驗證「欄數(=11, csv 解析) / 欄序(= config 指定順序) / 分隔(tab+CSV quote) / 編碼(UTF-8 無 BOM) / 換行(LF) / 無表頭 / quote round-trip」。**不比對 trailing newline**。（見 M2）
- **live 對照（人工 spot check，非嚴格自動驗收）**：對正式 endpoint 匯出能成功、列數 == live `count()`；可抽樣比對現檔 index 0–10 的值，但**因 live 可能已更新，不要求逐欄全等**。
- **資料完整性**：導出列數 == live 表 `count()`。
- **記憶體安全**：走串流，峰值不隨表大小線性成長。
- **欄位漂移防護**：config 11 欄與 live 表欄位不符時 endpoint 回 500（§5.1 fail-fast）。
- **端到端（最關鍵）**：`update.py` 刷新（檔案變 11 欄）後，`code_office.py` 以樣本 `input.txt` 執行**不報錯**、輸出合理（確認它只讀 index 0/1/3/5、不受欄數縮減影響）。
- **scope 負向測試（M2）**：`/codes/<未配置匯出的表>/export`（如 `ADDR_CODES`，雖通過 `guardTable` 但不在 `export_columns`）須回 **404**，防止泛用 route 被誤用成全表公開匯出。
- **自動化測試（M2）**：因測試用 `FakeDatabaseManager`（無真實 OFFICE_CODES），須自備小型 fixture（含 **NULL 欄、中文、一列在 index 3 含引號+內嵌 tab 的邊界資料**），斷言：11 欄、無表頭、首 3 byte ≠ `EF BB BF`、LF、`Content-Type` charset=UTF-8、NULL→空、含 tab 列經 `csv.reader` round-trip 仍為 11 欄且值還原。

---

## 7. 里程碑與每環節審查關卡

> **每個里程碑（M1 起）完成後的固定流程：**
> 1. 派出**一組 review agent**（需讀相關程式碼 + 讀本次 diff）做程式碼檢查與 review。
> 2. 反覆修正直到**無嚴重 issue**。
> 3. 再以 **`codex`（終端機指令，非本助手的 agent）** 檢查與 review，直到**無嚴重 issue**。
>    ```powershell
>    $env:HTTPS_PROXY = "http://127.0.0.1:7890"; $env:HTTP_PROXY = "http://127.0.0.1:7890"
>    Write-Output "<review prompt>" | codex exec --dangerously-bypass-approvals-and-sandbox
>    ```
> 4. 通過後才推進下一里程碑。

| 里程碑 | 內容 | 主要產物 |
|---|---|---|
| **M0** | 本計畫文件 + 鎖定 **11 欄**契約（已釐清 schema 演變、確認 5 欄不需要、確認 `code_office.py` 僅讀 0/1/3/5）。 | 本文件、**文件內（附錄）鎖定的有序 11 欄清單**。M0 **不改 `config/codes.php`**；寫入 `config('codes.export_columns')` 屬 M1。 |
| **M1** | main server 導出 endpoint（route + 控制器 + CSV-quote 串流 + fail-fast 11 欄斷言 + throttle + 格式保真 + show 頁下載連結） | `routes/web.php`、`CodesController@export`、`config/codes.php` |
| **M2** | main server Feature 測試（自備 fixture；11 欄/欄序/無 BOM/無表頭/quote round-trip/權限） | `tests/Feature/...` |
| **M3** | `code-office/update.py`（clone 到 `…/GitHub/code-office`，寫完直接 push，§9.1） | `update.py` |
| **M4** | 端到端驗證（`update.py` → `OFFICE_CODES.txt` 變 11 欄 → `code_office.py` 樣本跑通） | 驗證記錄 |

> 提交前最低檢查（每個碰到 PHP/前端的里程碑）：`./vendor/bin/php-cs-fixer fix`、跑受影響測試；commit message 用繁體中文。
>
> **M3 push 前關卡**：本機跑 `update.py` 對正式 endpoint 拉一次 → 確認 `OFFICE_CODES.txt` 變為 11 欄 → 跑 `code_office.py` 樣本確認不報錯 → 確認 `git diff` 僅 `OFFICE_CODES.txt` 內容變動（**16→11 欄為預期改版，非噪音**）、**無 CRLF/BOM 噪音**（注意 `.gitattributes` `* text=auto` 可能對 LF 做正規化，commit 前須確認檔案仍為 LF）。建議 M3 在 commit message 明記「OFFICE_CODES 改為 11 欄新格式，移除已於 main server 刪除的 c_category_1~4 / c_office_id_old」。

---

## 8. ADDR / code-addr 押後說明

- `code-addr` 的真實輸入是 **repo 內提交的** `ZZZ_ADDRESSES.xlsx`（上游源自 HF，但 `code_addr.py` 讀的是本地 repo 檔）；腳本會自行 `to_csv` 重生 `ADDRESSES.txt`（含表頭、tab、UTF-8）。
- repo 內 `ADDRESSES.txt` 是 **ADDRESSES + belongs1~5 階層攤平的衍生表**，**非** `ADDR_CODES` 原始單表。若要 server 端即時導出，須重現該 join，工作量明顯較重。
- 決策（與維護者確認）：**新建地址機率低 → 週中脈衝需求弱 → 維持周更 HF 即可**，本輪不做 addr 的 live 導出。
- 若日後 addr 也出現週中即時需求，再新增「`ADDRESSES` live 導出（含 join）」與 `code-addr/update.py`。

---

## 9. 決策記錄（原開放問題，已定）

1. **契約欄數**：採 **live 11 欄**新格式（`c_category_1~4`、`c_office_id_old` 已於 main server 刪除且下游不需要）。下游 `OFFICE_CODES.txt` 首次同步後 16→11 欄，屬預期改版。
2. **`update.py` 落地方式**：在本機 `C:\Users\sudos\OneDrive\document\GitHub\code-office` **clone `cbdb-project/code-office`，寫完直接 push**（不走 PR）。
3. **endpoint URL 與範圍**：route 為泛用 `/codes/{table_name}/export`（RESTful，沿用 `/codes/{table}` 命名），但**範圍由 `config('codes.export_columns')` 白名單收斂**——本輪僅 `OFFICE_CODES` 可匯出，其餘表（即使通過 `guardTable`）一律 404（見 §5.1）。
4. **export 存取權限**：**公開唯讀**（維護者拍板）——`/codes` 不在 auth group、`show` 無 `Auth` 守衛。export 對齊 show（公開）+ `throttle:6,1` 限流。此為 AGENTS.md §5 授權規則的**有意識正當例外**，理由見 §5.1（唯讀／公開資料／update.py 無憑證前提／已限流）。

---

## 附錄：現場事實記錄（實測）

- **live `OFFICE_CODES` = 11 欄**（契約欄名與欄序；M0 先在本文件鎖定，M1 再以此順序寫入新的 `config('codes.export_columns')` 鍵，**目前 config 尚無此鍵**）：
  1. `c_office_id`　2. `c_dy`　3. `c_office_pinyin`　4. `c_office_chn`　5. `c_office_pinyin_alt`　6. `c_office_chn_alt`　7. `c_office_trans`　8. `c_office_trans_alt`　9. `c_source`　10. `c_pages`　11. `c_notes`
  - 來源：baseline `database/migrations/2025_01_01_000000_import_cbdb_schema.php:1367` 的 16 欄，**減去**後續 migration 刪除的 `c_category_1~4`（`2026_04_24`）與 `c_office_id_old`（`2025_12_29`）。
  - **主鍵**：`PRIMARY KEY (c_office_id)` —— **單一主鍵**（非複合）→ 串流 `orderBy('c_office_id')`。
- `code-office/OFFICE_CODES.txt`（**現況 = 16 欄歷史檔**）：無表頭、tab+CSV quote、UTF-8 無 BOM、LF、無 trailing newline、按 `c_office_id` 升冪、33,957 筆。多出的 5 欄（index 11–15 = `c_category_1~4`、`c_office_id_old`）為已刪欄位的舊資料；首次 `update.py` 同步後消失（→ 11 欄）。
- `code-office/code_office.py`：`read_office()` 用 `csv.reader(delimiter='\t')` 按位置解析，**僅用 index 0/1/3/5**（`office_id_index=0`、`office_dy_index=1`、`office_name_index=3`、`office_altname_index=5`），**無整列欄數檢查** → 11 欄完全相容。OFFICE_CODES 朝代固定在 index 1。
  - 注意：「朝代欄位置依層級而定」是 **code-addr** 特性，不適用 office。
- `code-office/.gitattributes`：僅 `* text=auto`（LF normalization），**非 Git LFS**。
- `code-addr`：`ZZZ_ADDRESSES.xlsx` 提交在 repo 內；`ADDRESSES.txt` 為 ADDRESSES + belongs1~5 攤平之衍生表（含表頭），非 ADDR_CODES 原始單表。
- `main server`：`routes/web.php` 既有 `codes` 系列路由（L185-196，不在 auth group）；`CodesController` 為分頁 CRUD，無 export 方法；`config/codes.php` 含 `connection/database/per_page/ui_hidden/tables`，無欄位資訊；`resolveTableColumns`→`Schema::getColumnListing` 取整表欄位。
