# React/Inertia 遷移 — 真實互動模擬測試計畫

> 本計畫取代先前「靜態 parity 對比」版本。核心轉變：**不再只比靜態渲染的資料/結構**，而是由 AI 代理以 Playwright 驅動真實瀏覽器，在舊 Blade 頁與新 React/Inertia 頁上**執行相同的真實使用者操作**（打字檢索、填表送出、點按鈕、新增、修改、查詢、刪除），再**對比操作後渲染出來的結果**。

## 一、方法論

### 1.1 真實互動，而非靜態快照
每個測試單元都是一段「使用者劇本」：定位輸入框 → 打字 → 點按鈕 → 等待結果重渲染 → 擷取結果 → 新舊對比。不接受「只開頁讀 props/DOM 就判 PASS」。

### 1.2 對比基準：語義內容對比（非原始 HTML）
舊版是 AdminLTE/Blade、新版是 React/Tailwind，外殼 HTML 標記本來就不同，逐字比對無意義。因此：

- **判定 PASS/FAIL 的依據**：從兩邊渲染後的 DOM 抽出「有意義的結果」並逐項相等比對——
  - 檢索結果列的**儲存格值序列**（**動態**排除動作欄：動作欄隨 `can_edit` = 登入且非唯讀才出現，故兩側須在**相同登入狀態**下擷取，且以「該欄是否含連結/按鈕」動態判斷並剔除，不可寫死「最後一欄」）；
  - 詳情/編輯表單回填的**欄位值**：**以欄名為鍵**逐欄比對，**不可用位置序列**——`appEdit`(回填) 用 `orderAuditFieldsForDisplay`、`appCreate`(新增) 用 `orderColumnsForCreate`(PK 優先)，兩者欄序不同；
  - 操作後的 **flash 訊息語義**：**逐流程定義規則**，先**剝除尾端 `@ <timestamp>`**（後端 flash 如 `'Store success @ '.now()`）再比；且注意**codes flash 為英文**（Store/Update/Delete success）、**manage flash 為中文**——跨流程文案不同，只比「成功/失敗 + 影響語義」，不可跨流程逐字；
  - 列表**總筆數 / 末頁**；
  - 刪除後**該列是否消失**、新增後**該列是否出現且值正確**。
- **同時存證**：每個對比點把兩邊結果區塊的 `innerText`（及必要時 `innerHTML`）一併寫入報告，供人工目視覆核。語義對比是閘門，HTML 存證是佐證。

### 1.3 合成資料生命週期（自建即用即刪）
真實的「新增/修改/刪除」**只動測試自建的合成資料，永不碰真實 CBDB 資料**：

1. **建**：測試開始時，於目標表插入一筆帶有明顯測試標記（如 `c_...code = 9_999_xxx`、名稱含 `E2E-TEST-<run>` 前綴）的合成列；或使用既有的拋棄式測試使用者。
2. **用**：對這筆合成列做「改 / 查 / 刪」並雙邊對比。
3. **刪/復原**：測試結束（含失敗路徑）一律於 `finally` 清理，確保可重複執行、不殘留：
   - codes 目標表合成列：用主鍵精準刪除。
   - **連動稽核列**：codes `performStore/performUpdate/performDestroy` 每次都呼叫 `recordOperation` 寫一筆 `operations` 列；CRUD 生命週期會殘留數筆。清理時**須一併刪除對應 `operations` 列**（用 resource/操作標記精準定位），否則違反「不殘留」。
   - **manage 軟刪不可逆**：`performUserUpdate` 軟刪會覆寫 `email→email-<ts>`、`password='-'`、`confirmation_token='-'`、`remember_token='-'`，**原雜湊無法還原**。故 manage 軟刪**只能對拋棄式帳號**做，且軟刪後該帳號報廢、不可復原、不可重跑（重跑需先 reseed 新拋棄帳號）。改 is_active/is_admin（流程 F）是可逆的（測畢改回原值即可）；軟刪（流程 G）是不可逆的終結動作。
4. 合成列的主鍵值固定可預測，清理時用主鍵精準刪除，不誤刪真實資料。

### 1.4 唯讀防護與刻意寫入面
- 預設 context 攔截所有非 GET/HEAD 請求（abort + 記錄），`serviceWorkers:'block'` 防繞過。
- **刻意寫入的單元**才在該操作前後暫時放行對應的寫入端點（白名單到具體 method+URL），操作完立即收回；其餘請求仍攔截。任何非預期寫入 → 該單元 FAIL。

## 二、可雙邊對比的流程矩陣

| # | 流程 | 操作類型 | 舊路由 | 新路由 | 對比點 |
|---|---|---|---|---|---|
| A | **codes 單表 搜尋** | 檢索（讀） | `GET /codes/{t}` `name=search` 送出 | `GET /app/codes/{t}` 受控搜尋送出 | 結果列儲存格值序列 + 總筆數 |
| B | **codes 單表 欄位過濾** | 檢索（讀） | `filters[{col}]` + 套用鈕 | 受控 filters + apply | 過濾後列值序列 + 筆數 |
| C | **codes 單表 新增** | 新增（寫） | `GET /create`→`POST /codes/{t}` 直接存 | `appCreate`→`appStore` | 送出後列表/詳情出現該合成列且欄值正確 + flash |
| D | **codes 單表 修改** | 修改（寫） | `GET /{id}/edit`→`PUT/PATCH` | `appEdit`→`appUpdate` | 改後回填值 + 列表該列新值 + flash |
| E | **codes 單表 刪除** | 刪除（寫） | 列上 `btn-danger`→`DELETE` | ConfirmDialog→`router.delete` | 刪後該列消失 + flash + 筆數 −1 |
| F | **manage 使用者 改啟用/角色** | 修改（寫，**可逆**） | `GET /manage/{id}/edit`→**PUT** | `appEdit`→`appUpdate`(**PATCH**) | 列表該列狀態/角色 badge 變化 + flash；測畢改回原值 |
| G | **manage 使用者 軟刪除** | 刪除（寫，**不可逆**） | 勾 `delete_user=1`→**PUT** | destructive→ConfirmDialog→**PATCH**`{delete_user:1}` | 軟刪後該列消失 + flash；**僅對拋棄帳號、不可復原** |
| H | **ExplainSQL 送出** | 查詢（讀寫表單） | `GET/POST /admin/explainsql` | `app.admin.explainsql(.explain)` | 同一 SQL 的 EXPLAIN 結果列語義一致 |

> A–E 在同一張可寫 codes 目標表上串成一條完整 CRUD 生命週期（建→查→改→刪），合成列即用即刪。
> **直接存 vs 提案是兩個獨立按鈕/端點，不是角色分支**：`performStore/Update/Destroy` 只檢查 `Auth::check() && isActive()`，**任何 active 使用者點「直接儲存」(save_direct) 都直接寫目標表**；「提案」(submit_proposal) 是另一條路由（`app.codes.propose.*`），寫的是 `operations` 表**而非目標表**。本計畫 C–E 一律**點 save_direct**，**絕不可點 submit_proposal**（否則沒寫到目標表、卻污染 operations，是假測）。`can_propose`/`can_edit` 只決定按鈕是否出現，與 superadmin 身分無關。
> manage 需 `canManageUsers()`。

### 目標表選定（流程 A–E）
- 選一張**結構單純、主鍵明確、可安全插入合成列**的 codes 表（候選由實作前以 `CompositePrimaryKey::SCHEMAS` 與欄位數最少者中挑選並記錄於報告）。
- 合成列主鍵採高位保留值（如 code 欄 `9999xxx`），名稱欄含 `E2E-TEST-<runtag>`，確保不與真實資料碰撞、清理可精準定位。

## 三、不可雙邊對比者（明確標註，不混入 parity 結論）

| 流程 | 狀況 | 處置 |
|---|---|---|
| Person Browser | 新獨有，無舊 Blade | 僅新版**功能煙測**（搜尋→出結果→無 JS 例外），不宣稱 parity |
| Query Playground | 舊頁已 302 轉新頁 | 僅新版功能煙測（打 SQL→執行→結果表/錯誤訊息） |
| search-by/entry | 新獨有 | 僅新版功能煙測 |
| BasicInformation 子資源 新增/改/刪 | 舊有完整 Blade UI、新只有唯讀 index（mutation 走 API 非 Inertia 表單頁） | 不做 UI 雙邊對比；如需驗新側 mutation，另以 API 層測試（非本計畫 UI 範圍） |
| codes 提案（propose store/update）+ proposal-edit 審核 | 舊新皆有完整對應（`app.codes.propose.*`、`proposalEdit`/`appProposalEdit`） | **本計畫暫不對比**（提案寫 `operations` 流程，與直接存 CRUD 正交；列為後續可擴充，避免與流程 C–E 的 save_direct 路徑混淆） |

## 四、各流程互動規格（selector 重點）

- **舊頁**：可用 `input[name=...]`、`button[type=submit]`、`form[action=...]`、固定 id（`#is_active`、`#is_admin`、`#delete_user`、`#__proposal_comment`、`#delete-form-{id}`）。原生 `alert/confirm` 需 `page.on('dialog', d=>d.accept())`。
- **新頁**：多為受控元件、**無 name 屬性**；改用 `id`（codes 欄位 `id={col}`、`#is_active`、`#is_admin`、`#__proposal_comment`）、按鈕可見文字（i18n：`save_changes`/`save_direct`/`search`/`apply_filters`/`delete`/`run_query`）、`placeholder`、`aria-label`。刪除走 `ConfirmDialog`（先點 destructive 鈕開 dialog，再點確認鈕），非原生 confirm。
- **提交方法**（唯讀防護白名單須按各側實際 method 放行，否則假 FAIL）：
  - manage update **route 兩側都接受 PUT|PATCH**，但 **UI 實際送出**：舊 Blade = **PUT**（`manage/edit.blade.php` `@method('PUT')`）、新 React = **PATCH**（`form.patch`）。白名單按「實際送出」對舊側放行 **PUT**、新側放行 **PATCH**。
  - codes update：舊 `@method('PATCH')`／新 `form.patch` → 兩側 **PATCH**；codes store = **POST**；codes destroy = **DELETE**（舊隱藏 form `@method('DELETE')`／新 `router.delete`）。
  - 白名單只放行「當前刻意操作的那一條 method+URL」，操作後立即收回。
- **結果擷取**：
  - codes 列表：舊 `div.table-responsive > table.table-bordered > tbody`；新 `div.overflow-x-auto > table > tbody`。逐列抽資料欄（排除動作欄），值序列比對。
  - manage 列表：舊 `table.table tbody`；新對應 `table tbody`。比狀態/角色 badge 文字。
  - flash：操作後讀渲染後 DOM 的 flash 容器（新版 `[role=status]`），不可讀 `#app[data-page]`（client-side 導航後陳舊）。

## 五、安全 / 共識
- 目標 DB：`cbdb_data`（開發 / 可丟棄資料）。
- 合成資料即用即刪；失敗路徑亦於 `finally` 清理。
- 拋棄式管理帳號（superadmin 登入用、regular 軟刪目標）測畢可刪。
- AI 功能不深測（控成本）：query-playground 煙測僅打一句安全 SELECT；不觸發 NL/SSE 生成。
- 所有 e2e 腳本、報告、截圖一律本地（`.git/info/exclude` 排除），不進版控。

## 六、執行與過閘規範
1. 逐流程實作互動腳本，每完成一個流程：
   - 先派 **review agent**（讀腳本 + 讀相關前後端碼）檢查方法論健全性、防假 PASS、清理是否確實，直到無嚴重 issue；
   - 再以 **codex**（`codex exec --dangerously-bypass-approvals-and-sandbox`，`Write-Output "<prompt>" | codex ...`，PowerShell 用 `powershell.exe`）覆核，直到無嚴重 issue；
   - 才推進下一個流程。
2. 全部流程跑完後輸出一份合併報告（每流程的對比點、PASS/FAIL、HTML 存證指標、清理結果）。
3. 文檔本身（本計畫）之新增/重寫亦走 review 規範後才提交。

## 七、重跑
```powershell
# 前提：dev server 已起；npm run build；Playwright 已裝
node tests/e2e/login.mjs superadmin        # 刷新登入 state（session 會過期）
# 之後逐流程腳本：interact-codes-crud.mjs / interact-codes-search.mjs /
#                 interact-manage.mjs / interact-explainsql.mjs / smoke-new-only.mjs
# 報告與截圖輸出於 storage/app/test-artifacts/
```
