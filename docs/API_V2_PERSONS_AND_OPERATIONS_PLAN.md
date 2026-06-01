# API v2：人物清單與操作記錄端口設計計畫

## 背景

需新增兩個分頁 API 端口：

1. **人物清單**：輸出 `BIOG_MAIN` 中所有人物的 `c_personid`，分頁輸出。
2. **操作記錄**：將 `/operations` 頁面的操作記錄清單做成 API，分頁輸出。

---

## 第一步：建立新分支

```bash
git checkout develop
git pull
git checkout -b feature/api-v2-persons-and-operations
```

---

## 現行 API 架構摘要

閱讀 `routes/api.php`、`app/Http/Controllers/Api/TextLookupController.php`
及 `app/Http/Controllers/OperationsController.php` 後，整理現行慣例：

| 面向 | 現行做法 |
|------|---------|
| 路由前綴 | 現代端口用 `v2`，舊端口直接放根層 |
| 中間件 | `auth.optional`（允許未登入，不強制） |
| 控制器位置 | `app/Http/Controllers/Api/` |
| JSON 回應格式 | `{'ok': true, 'data': [...], 'meta': {...}}` |
| 分頁 | Laravel `paginate()`；分頁資訊另外組成 `pagination` 物件 |
| 錯誤格式 | `{'ok': false, 'message': '...', 'errors': {...}}` + HTTP 4xx |

---

## API 1：人物清單

### 路由

```
GET /api/v2/persons
```

### Query 參數

| 參數 | 預設值 | 說明 |
|------|-------|------|
| `per_page` | 100 | 每頁筆數，上限 1000 |
| `page` | 1 | 頁碼（Laravel 自動處理） |

### 實作位置

- **路由**：`routes/api.php`，加入 `v2` prefix group 下
- **控制器**：新建 `app/Http/Controllers/Api/PersonListController.php`

### 查詢邏輯

```php
BiogMain::select(['c_personid'])
    ->orderBy('c_personid', 'asc')
    ->paginate($perPage);
```

只取 `c_personid`，按主鍵升序，確保結果穩定可分頁。

### 回應格式

```json
{
  "ok": true,
  "data": [
    {"c_personid": 1},
    {"c_personid": 2},
    ...
  ],
  "pagination": {
    "total": 680000,
    "per_page": 100,
    "current_page": 1,
    "last_page": 6800,
    "from": 1,
    "to": 100
  }
}
```

---

## API 2：操作記錄

### 路由

```
GET /api/v2/operations
```

### 設計決策

現行 `OperationsController::index()` 包含大量 Blade 視圖邏輯：
- 即時 diff 比對（從資料庫讀取現行資料列並比較）
- audit log 載入
- HTML 渲染用的輔助屬性

**API 版本不複製這些邏輯**，原因：
1. Diff 計算每筆需額外 DB query，分頁 20 筆就需 20+ 次，成本高
2. `resource_data` / `resource_original` 已是完整 JSON，呼叫端可自行比對
3. API 消費者需要的是乾淨的資料，不是渲染用的計算結果

API 版只提供基本操作記錄欄位，`resource_data` / `resource_original` 解碼為 JSON 物件（不再是字串）。

### Query 參數

| 參數 | 預設值 | 說明 |
|------|-------|------|
| `per_page` | 20 | 每頁筆數，上限 100 |
| `page` | 1 | 頁碼 |
| `proposals_only` | false | `true` 只回傳提案（op_type 8/9） |
| `editor` | — | 依修改者篩選（純數字=user_id，否則模糊匹配 name） |
| `op_type` | — | 修改類型，多值陣列，例如 `op_type[]=1&op_type[]=3` |
| `status` | — | 提案審核狀態，多值，`proposals_only=true` 時有效 |

篩選邏輯與 `OperationsController::index()` 保持一致，直接重用，不重複實作。

### 實作位置

- **路由**：`routes/api.php`，加入 `v2` prefix group 下
- **控制器**：新建 `app/Http/Controllers/Api/OperationListController.php`
- **共用篩選邏輯**：直接在控制器內實作（邏輯簡單，不必抽 Service）

### 回應格式

```json
{
  "ok": true,
  "data": [
    {
      "id": 12345,
      "user_id": 7,
      "c_personid": 10001,
      "op_type": 3,
      "resource": "BIOG_MAIN",
      "resource_id": "c_personid=10001",
      "resource_data": { ... },
      "resource_original": { ... },
      "crowdsourcing_status": 0,
      "created_at": "2026-05-30T08:00:00.000000Z",
      "updated_at": "2026-05-30T08:00:00.000000Z"
    }
  ],
  "pagination": {
    "total": 4200,
    "per_page": 20,
    "current_page": 1,
    "last_page": 210,
    "from": 1,
    "to": 20
  }
}
```

---

## 授權與安全

兩個端口均使用 `auth.optional` 中間件（與現行 v2 端口一致）：
- 未登入使用者可讀取
- 不暴露任何寫入能力

Operations API 只讀，且 `crowdsourcing_status = 0` 的記錄才是正式操作記錄，無隱私疑慮。

---

## 實作步驟

1. **建分支**：`git checkout -b feature/api-v2-persons-and-operations`
2. **新建** `app/Http/Controllers/Api/PersonListController.php`
3. **新建** `app/Http/Controllers/Api/OperationListController.php`
4. **修改** `routes/api.php`：在 `v2` + `auth.optional` group 下加入兩條路由
5. **跑 CS Fixer**：`./vendor/bin/php-cs-fixer fix`
6. **補測試**（Feature test，驗證分頁結構、篩選參數）
7. **跑測試**：`./vendor/bin/phpunit`
8. **更新** `API.md`：在文件最前面新增 `## API v2` heading，按現有文檔格式補上兩個端口的說明

---

## 不在本次範圍內

- Operations API 的 diff 計算（視需求另行評估）
- 寫入端口
- 認證強制化（視後續政策決定）
