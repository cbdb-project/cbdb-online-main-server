# Laravel MCP Web Interface Proposal（Official Package）

## 1. 目標與背景

本提案目標是為 CBDB Laravel server 新增一個可透過 **Web Request** 訪問的 MCP 介面，並使用既有的使用者 API key（Laravel Sanctum Personal Access Token）做身分驗證。

需求重點：

- 採用官方 MCP 實作，降低協議維護成本
- 僅允許 **read-only** 查詢 allowlist 表格
- 驗證使用者 API key（Bearer Token）
- 支援與既有 Python 範例一致的核心查詢能力

## 2. 可行性結論

可行，且建議以官方套件 `laravel/mcp` 作為第一選擇。

原因：

- 專案已使用 Laravel 12 與 Sanctum，可直接整合 MCP 路由中介層。
- 官方套件已處理 MCP over HTTP 基礎協議，避免自行維護 JSON-RPC 細節。
- 我們可專注在業務工具本身（allowlist、查詢限制、唯讀安全邊界）。

## 3. 方案比較

### 3.1 主方案（推薦）：`laravel/mcp`

- 套件：`composer require laravel/mcp`
- 傳輸：HTTP（由官方 MCP 路由機制提供）
- 驗證：路由掛 `auth:sanctum`
- 優點：
  - 協議相容性較好
  - 維護成本低
  - 與 Laravel 生態整合自然

### 3.2 備選方案：自研 MCP/JSON-RPC endpoint

- 優點：可完全自訂
- 缺點：需自行維護協議、錯誤碼與 client 相容性
- 結論：僅在官方套件無法滿足需求時採用

## 4. 認證與授權設計

### 4.1 API key 驗證

重用 Sanctum PAT：

- 中介層：`auth:sanctum`
- 只接受 Bearer Token，不接受 query string token
- token 無效/過期：回 `401`

### 4.2 建議 token abilities

建議將 MCP 權限最小化：

- `mcp:read`（必要）
- `mcp:schema`（可選）

於 MCP 工具入口檢查能力：

- 無 `mcp:read` -> `403`

## 5. 功能範圍（v1）

對齊既有 Python 服務能力，先提供 5 個工具：

1. `list_allowed_tables`
2. `query_table_schema(table_name)`
3. `get_table_row_by_id(table_name, id_column, id_value)`
4. `get_sample_data(table_name, limit=10, offset=0)`
5. `query_table(table_name, filters?, columns?, limit=10, offset=0)`

## 6. 安全模型

### 6.1 allowlist

- 新增 `config/mcp.php`
- `allowed_tables` 必填；空清單時拒絕所有查詢
- 內部表（`CBDB__*`）採明確白名單，不做通配預設開放

### 6.2 識別符驗證

- table/column 名稱必須經 identifier 驗證（例：`^[A-Za-z0-9_]+$`）
- 禁止未驗證識別符拼接 SQL

### 6.3 查詢限制

- `limit` 範圍：1~100
- `offset >= 0`
- `filters` 僅接受 key-value JSON，key 必須是合法欄位名
- values 一律參數化綁定

### 6.4 僅讀保證

- 不提供任意 SQL 執行工具
- 查詢服務僅封裝 SELECT 流程
- 部署層建議使用 DB read-only 帳號

### 6.5 限流與審計

- MCP 路由掛 `throttle`
- 記錄 MCP 操作：user_id、tool、table、耗時、狀態碼
- 對可能敏感參數進行遮罩

## 7. Laravel 實作藍圖（基於官方包）

### 7.1 依賴安裝

- `composer require laravel/mcp`

### 7.2 路由與 server 註冊

- 依官方文件建立 MCP 路由（建議放 `routes/ai.php`）
- 以官方 `Mcp::web(...)` 註冊 web MCP endpoint
- MCP 路由加上：
  - `auth:sanctum`
  - `throttle:mcp`

### 7.3 工具實作

- `app/McpTools/` 下建立 5 個工具類別或 handler
- 查詢邏輯集中在：
  - `app/Services/Mcp/ReadOnlyTableQueryService.php`
- 工具層只做參數驗證與回傳格式，資料查詢統一交給 service

### 7.4 設定檔

- `config/mcp.php`
  - `enabled`
  - `allowed_tables`
  - `max_limit`
  - `rate_limit_per_minute`
  - `require_token_abilities`

### 7.5 錯誤處理

- 優先沿用 `laravel/mcp` 官方錯誤封裝
- 服務層拋出業務錯誤時，保持穩定錯誤碼語意：
  - 未授權
  - 權限不足
  - 非 allowlist 表
  - 參數格式錯誤

## 8. 測試策略

### 8.1 Feature tests

- 未帶 token -> `401`
- 無 `mcp:read` -> `403`
- 非 allowlist 表 -> 錯誤
- `limit` 超界 -> 錯誤
- 合法 `query_table` -> 成功

### 8.2 Unit tests

- identifier 驗證
- filters 解析（LIKE / 等值）
- allowlist 行為

### 8.3 SQLite 相容性

- 測試環境使用 SQLite 時，schema 查詢需走 SQLite 相容分支（例如 `PRAGMA table_info`）
- 不使用 MySQL 專屬語法硬編碼

## 9. 分階段落地

### Phase 1（MVP）

- 安裝 `laravel/mcp`
- 建 MCP endpoint（web）
- 串接 `auth:sanctum` + `throttle`
- 完成 5 個 read-only tools
- 補齊最小測試集

### Phase 2

- 加入 token abilities 更細分控制（如 `mcp:schema`）
- 提升 observability（metrics + structured log）

### Phase 3

- 欄位級別授權（table + column policy）
- 多資料源或多租戶（若未來有需求）

## 10. 風險與對策

- 風險：套件仍為 0.x，升版可能有破壞性變動
  - 對策：鎖定版本、建立回歸測試
- 風險：查詢面過大造成資料外洩
  - 對策：嚴格 allowlist、最小權限 token、必要時加 column allowlist
- 風險：查詢濫用影響效能
  - 對策：`limit` 上限、throttle、慢查詢監控

## 11. 建議決策

建議採用「`laravel/mcp` + Sanctum PAT」作為第一版。

此路徑可在較低維護成本下滿足你的關鍵需求：

- 透過 web request 存取 MCP
- 驗證使用者 API key
- 提供與現有 Python 範例一致的查詢工具
- 保持 read-only 與可控安全邊界

## 12. 預估工期（單人）

- Proposal 定稿：0.5 天
- MVP 開發（含測試）：1.5~2.5 天
- 聯調與修正：1~2 天
- 合計：約 3~5 個工作天
