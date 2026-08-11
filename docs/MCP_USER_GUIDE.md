# CBDB MCP 使用說明（使用者版）

本文提供一般使用者如何連接並使用 CBDB MCP 服務。

## 1. 服務資訊

- MCP URL：`https://input.cbdb.fas.harvard.edu/api/mcp`
- Transport：Streamable HTTP
- 認證方式：`Authorization: Bearer <Sanctum Personal Access Token>`
- 權限要求：token 需包含 `mcp:read` ability

## 2. 取得 API Token

請在 CBDB 系統建立 Personal Access Token（個人資料頁）。**不需要手動設定能力**：介面沒有
能力選擇器，新建 token 一律自動取得目前唯一可簽發的能力：

- `mcp:read`

（自 P1-4 起，預設值不再是 Sanctum 的通配能力 `*`。若你手上的舊 token 是 `*`，它已被
migration 降級為 `mcp:read`，MCP 用法不受影響。）

若 token 洩漏，請立即撤銷並重新建立。

## 3. 在 Codex CLI 設定 MCP

### 3.1 設定 token 環境變數

```bash
export CBDB_MCP_BEARER_TOKEN="<YOUR_TOKEN_HERE>"
```

### 3.2 新增 MCP server

以 Codex 为例：

```bash
codex mcp add cbdb-http-mcp \
  --url https://input.cbdb.fas.harvard.edu/api/mcp \
  --bearer-token-env-var CBDB_MCP_BEARER_TOKEN
```

### 3.3 驗證設定

```bash
codex mcp get cbdb-http-mcp
```

預期會看到：

- `transport: streamable_http`
- `url: https://input.cbdb.fas.harvard.edu/api/mcp`
- `bearer_token_env_var: CBDB_MCP_BEARER_TOKEN`

## 4. 協議層 smoke test（cURL）

### 4.1 initialize

```bash
curl -sS https://input.cbdb.fas.harvard.edu/api/mcp \
  -H "Authorization: Bearer $CBDB_MCP_BEARER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc":"2.0",
    "id":1,
    "method":"initialize",
    "params":{
      "protocolVersion":"2025-06-18",
      "capabilities":{},
      "clientInfo":{"name":"curl","version":"1.0"}
    }
  }'
```

### 4.2 tools/list

```bash
curl -sS https://input.cbdb.fas.harvard.edu/api/mcp \
  -H "Authorization: Bearer $CBDB_MCP_BEARER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

### 4.3 tools/call（list_allowed_tables）

```bash
curl -sS https://input.cbdb.fas.harvard.edu/api/mcp \
  -H "Authorization: Bearer $CBDB_MCP_BEARER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc":"2.0",
    "id":3,
    "method":"tools/call",
    "params":{"name":"list_allowed_tables","arguments":{}}
  }'
```

## 5. 可用工具

目前提供 5 個 read-only 工具：

1. `list_allowed_tables`
2. `query_table_schema`
3. `get_table_row_by_id`
4. `get_sample_data`
5. `query_table`

## 6. 常見錯誤與排除

### 6.1 `Environment variable ... is not set`

原因：`--bearer-token-env-var` 設成了 token 值，而不是環境變數名稱。

正確示例：

```bash
# 錯誤：把 token 值直接放進參數
# --bearer-token-env-var 3|xxxx

# 正確：放「變數名稱」
--bearer-token-env-var CBDB_MCP_BEARER_TOKEN
```

### 6.2 `401 Unauthorized`

- token 無效、過期、已撤銷，或未帶 `Authorization` header。

### 6.3 `403 Missing required token ability`

- token 缺少 `mcp:read`（或環境中配置的 `MCP_REQUIRED_ABILITY`）。

### 6.4 `Table ... is not in allowlist`

- 該表不在 `MCP_ALLOWED_TABLES`。

### 6.5 `Could not resolve host`

- DNS / 網路連線問題，請先檢查本機網路與公司代理設定。

## 7. 使用建議

- 生產環境請使用最小權限 token（不要使用 `*`）。
- 定期輪換 token。
- 若懷疑 token 外洩，先撤銷再重建。
- 查詢時 `id_value` 請與欄位型別一致（數值欄位請傳數值）。
