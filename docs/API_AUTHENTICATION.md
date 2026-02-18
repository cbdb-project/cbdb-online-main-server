# API 認證架構說明

本文檔說明 CBDB 系統的 API 認證架構。系統使用 **Laravel Sanctum** 提供簡潔、安全的 API 認證。

## 認證方式概覽

CBDB API 支持兩種認證方式：

| 認證方式 | 使用場景 | 認證頭 | 適用對象 |
|---------|---------|--------|---------|
| **Session Cookie** | Web 前端 SPA | 自動（Cookie） | 瀏覽器用戶 |
| **Personal Access Token** | 外部應用/腳本 | `Authorization: Bearer {token}` | 開發者、研究人員 |

## 1. Session Cookie 認證（推薦用於 Web 前端）

### 工作原理

當用戶通過 Web 界面登錄後，Laravel 會建立一個 Session。Sanctum 利用這個 Session 來認證 API 請求。

### 優點

- ✅ 無需手動管理 Token
- ✅ 自動處理 CSRF 保護
- ✅ 與 Laravel 原生認證完美集成
- ✅ 適合單頁應用（SPA）

### 使用方式

1. **用戶登錄**
   ```javascript
   // 使用標準的 Laravel Auth 登錄表單
   await axios.post('/login', {
     email: 'user@example.com',
     password: 'password'
   });
   ```

2. **調用 API**
   ```javascript
   // 配置 Axios 以自動發送 Cookie
   window.axios.defaults.withCredentials = true;

   // 直接調用 API，Session 會自動處理認證
   const response = await axios.get('/api/select/search/addr');
   ```

3. **CSRF 保護**

   確保所有 POST/PUT/DELETE 請求包含 CSRF Token：
   ```html
   <meta name="csrf-token" content="{{ csrf_token() }}">
   ```

   ```javascript
   const token = document.head.querySelector('meta[name="csrf-token"]');
   axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
   ```

### 前端配置

在 `resources/js/app.js` 中已配置：

```javascript
// 啟用 Cookie 認證
window.axios.defaults.withCredentials = true;

// 自動附加 CSRF Token
const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}
```

## 2. Personal Access Token 認證（用於外部應用）

### 使用場景

- Python/R 數據分析腳本
- 外部應用集成
- 自動化工具
- 移動應用

### 獲取 API Token

1. **登錄 CBDB 系統**
2. **進入個人資料頁面**：導航至 `/profile`
3. **創建新 Token**：
   - 點擊「創建新 Token」
   - 輸入 Token 名稱（例如：「我的 Python 腳本」）
   - 選擇有效期限（可選）
   - 點擊創建
4. **複製 Token**：Token 只會顯示一次，請妥善保存

### 使用 Token 調用 API

#### Python 範例

```python
import requests

# 配置 API Token
API_BASE_URL = 'https://cbdb.example.com/api'
API_TOKEN = 'your-personal-access-token-here'

headers = {
    'Authorization': f'Bearer {API_TOKEN}',
    'Accept': 'application/json'
}

# 調用 API
response = requests.get(
    f'{API_BASE_URL}/select/search/addr',
    headers=headers,
    params={'keyword': '北京'}
)

data = response.json()
print(data)
```

#### cURL 範例

```bash
curl -X GET \
  'https://cbdb.example.com/api/select/search/addr?keyword=北京' \
  -H 'Authorization: Bearer your-personal-access-token-here' \
  -H 'Accept: application/json'
```

#### JavaScript 範例

```javascript
const axios = require('axios');

const API_BASE_URL = 'https://cbdb.example.com/api';
const API_TOKEN = 'your-personal-access-token-here';

axios.get(`${API_BASE_URL}/select/search/addr`, {
  headers: {
    'Authorization': `Bearer ${API_TOKEN}`,
    'Accept': 'application/json'
  },
  params: {
    keyword: '北京'
  }
})
.then(response => {
  console.log(response.data);
})
.catch(error => {
  console.error('API Error:', error.response?.data || error.message);
});
```

### Token 管理

用戶可以在 `/profile` 頁面管理自己的 API Token：

- **查看所有 Token**：查看 Token 名稱、創建時間、最後使用時間、到期時間
- **撤銷單個 Token**：不再需要的 Token 可以隨時撤銷
- **撤銷所有 Token**：一次性撤銷所有 Token（安全考慮）

### Token 安全最佳實踐

1. **不要在代碼中硬編碼 Token**
   ```python
   # ❌ 不推薦
   API_TOKEN = 'your-token-here'

   # ✅ 推薦：使用環境變量
   import os
   API_TOKEN = os.getenv('CBDB_API_TOKEN')
   ```

2. **定期輪換 Token**
   - 定期創建新 Token 並撤銷舊 Token
   - 特別是在懷疑 Token 洩漏時立即撤銷

3. **使用適當的有效期限**
   - 短期任務使用 30-90 天
   - 長期服務可使用 1 年，但建議定期更新

4. **不要分享 Token**
   - 每個用戶/應用應有獨立的 Token
   - 不要通過不安全的渠道（如電子郵件、即時消息）傳輸 Token

## 需認證或可選認證的 API 端點

以下 API 端點需要認證：

| 端點 | 方法 | 描述 |
|------|------|------|
| `/api/user` | GET | 獲取當前用戶信息（需認證） |
| `/api/select/search/*` | GET | 所有選擇和搜索 API（可選認證） |

### 速率限制

為了保護系統穩定性，API 請求仍保留全局速率限制：

- **總體限制**：600 請求/分鐘（全局）

超過全局限制時，服務器會返回 `429 Too Many Requests` 錯誤。

## 錯誤處理

### 常見錯誤碼

| 狀態碼 | 錯誤 | 原因 | 解決方案 |
|-------|------|------|---------|
| 401 | Unauthenticated | Token 無效或過期 | 檢查 Token 是否正確，是否已過期 |
| 403 | Forbidden | 用戶無權限訪問 | 檢查用戶權限 |
| 419 | CSRF Token Mismatch | CSRF Token 無效（Session 認證） | 刷新頁面或重新獲取 CSRF Token |
| 429 | Too Many Requests | 超過速率限制 | 減少請求頻率，稍後重試 |

### 錯誤響應範例

```json
{
  "message": "Unauthenticated."
}
```

## 技術實現細節

### 後端配置

#### Middleware (`app/Http/Kernel.php`)

```php
'api' => [
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'throttle:600,1',
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
],
```

#### API 路由 (`routes/api.php`)

```php
Route::middleware('auth:sanctum')->get('/user', 'Api\UserController@show');

Route::group([
    'prefix' => 'select',
    'middleware' => ['auth.optional']
], function () {
    // 所有 select 和 search API 端點
});
```

#### Sanctum 配置 (`config/sanctum.php`)

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
    '%s%s',
    'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
    env('APP_URL') ? ','.parse_url(env('APP_URL'), PHP_URL_HOST) : ''
))),
```

### 數據庫表

Sanctum 使用 `personal_access_tokens` 表來存儲 API Token：

```sql
CREATE TABLE personal_access_tokens (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    tokenable_type VARCHAR(255),
    tokenable_id BIGINT,
    name VARCHAR(255),
    token VARCHAR(64) UNIQUE,
    abilities TEXT,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## 遷移說明

### 從 Laravel Passport 遷移

本系統已從 Laravel Passport 遷移到 Laravel Sanctum。如果您之前使用 Passport 的 Personal Access Token：

1. **舊 Token 已失效**：Passport Token 不再有效
2. **創建新 Token**：請在 `/profile` 頁面創建新的 Sanctum Token
3. **更新應用配置**：更新外部應用以使用新 Token

### 為什麼選擇 Sanctum？

- ✅ **更簡單**：Sanctum 專注於 SPA 和 Token 認證，無 OAuth 複雜性
- ✅ **更輕量**：不需要 OAuth2 服務器的開銷
- ✅ **更適合我們的需求**：CBDB 不需要 OAuth2 的授權流程
- ✅ **更好的 SPA 支持**：Session Cookie 認證與 Laravel 原生認證無縫集成

## 常見問題

### Q: 我可以同時有多個 API Token 嗎？

A: 可以！您可以為不同的應用或腳本創建多個 Token，方便管理和撤銷。

### Q: Token 會過期嗎？

A: 創建 Token 時可以設置有效期限（30 天、90 天、180 天、1 年），也可以創建永久有效的 Token。建議為安全考慮設置合理的有效期限。

### Q: 如何撤銷洩漏的 Token？

A: 立即登錄系統，前往 `/profile` 頁面，找到對應的 Token 並點擊「撤銷」按鈕。如果不確定哪個 Token 洩漏，可以點擊「撤銷所有 Token」。

### Q: Web 前端需要手動管理 Token 嗎？

A: 不需要！Web 前端使用 Session Cookie 認證，完全自動化。只需確保用戶已登錄即可。

### Q: API 調用失敗，返回 401 錯誤？

A: 檢查以下幾點：
1. Token 是否正確複製（沒有多餘的空格）
2. Token 是否已過期
3. Authorization 頭格式是否正確：`Bearer {token}`
4. 對於 Session 認證，檢查用戶是否已登錄

## 相關資源

- [Laravel Sanctum 官方文檔](https://laravel.com/docs/10.x/sanctum)
- [CBDB API 端點文檔](../API.md)
- [用戶管理頁面](/profile)
