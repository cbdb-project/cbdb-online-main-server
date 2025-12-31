# 使用者權限概述

本文件整理 CBDB 後台目前對帳號啟用狀態與角色（權限等級）的分類，並列出各層級可執行的操作。請在新增功能或調整授權時保持同步更新。

---

## 一、帳號啟用狀態（`users.is_active`）
| 值 | 名稱 | 說明 | 實際行為 |
|----|------|------|-----------|
| `0` | 未啟用 | 預設值、待審 / 停用狀態 | 可登入，但所有寫入動作會被 Controller 拒絕。 |
| `1` | 已啟用 | 正式啟用 | 能照角色權限執行對資料庫的新增 / 修改。 |
| `2` | 保留 | Migration 註解為「寄送激活郵件」 | 目前程式視同未啟用；不具任何寫入權限。 |

> 多數 Controller 皆以 `Auth::user()->is_active != 1` 作為拒絕條件；因此 `1` 是唯一真正的「可操作」狀態。

---

## 二、帳號角色（`users.is_admin`）
| 值 | 名稱 / 定位 | 常量定義 | 主要行為 |
|----|-------------|---------|-----------|
| `0` | 一般用戶 | `User::ROLE_REGULAR` | 只要 `is_active = 1` 即可直接對 `/basicinformation/*`、`/codes/*` 等模組進行新增 / 修改，Controller 會直接呼叫對應的 Repository → Model，寫入資料表並留下操作紀錄。 |
| `1` | 專家用戶 | `User::ROLE_EXPERT` | 擁有一般用戶權限；另外可：<br>• 進入 `/manage` 調整帳號啟用 / 角色。<br>• 在 `/operations`、`/modified` 審核提案（核准 / 退修）、執行操作復原。<br>• 存取 `/merge-preview` 等管理工具。 |
| `2` | 眾包用戶 | `User::ROLE_CROWDSOURCING` | 前台頁面可編輯，但 Controller 會偵測 `is_admin == 2`，把提交內容轉成 `operations`（`crowdsourcing_status = 2`），等待專家審核後套用，並不直接寫主資料表。 |
| `3` | 系統管理員 | `User::ROLE_SUPER_ADMIN` | **已在模型層面完全實現**。擁有與專家相同的基礎權限（透過 `isAdmin()` 方法識別），可用於區分更高階的管理功能。可透過 `cbdb:manage-user` 命令創建。 |

> **常量使用**：`User` 模型已定義角色和狀態常量（參見 `app/User.php`），建議在新代碼中使用常量而非魔術數字。

---

## 三、權限差異總覽

| 動作 | 一般用戶<br>(`is_admin=0`) | 專家用戶<br>(`is_admin=1`) | 眾包用戶<br>(`is_admin=2`) | 系統管理員<br>(`is_admin=3`) |
|------|--------------------------|--------------------------|--------------------------|----------------------------|
| `/basicinformation` 新增 / 修改 | 直接寫入 `BIOG_MAIN` | 同一般用戶 | 建立眾包提案，`crowdsourcing_status = 2`，不直接寫表 | 同專家用戶 |
| `/codes` 新增 / 修改 | 直接寫入各代碼表 | 同一般用戶，可額外審核提案 | 建立提案，交由專家審核 | 同專家用戶 |
| `/operations` 審核提案、操作復原 | 無 | ✅（透過 `isAdmin()` 檢查） | 無 | ✅（透過 `isAdmin()` 檢查） |
| `/modified` 審核提案 | 無 | ✅ | 無 | ✅ |
| `/crowdsourcing` 審核（Confirm） | 無 | ✅ | 只能提交提案 | ✅ |
| `/manage` 列表管理帳號 | 無 | ✅（透過 `canManageUsers()` 檢查） | 無 | ✅（透過 `canManageUsers()` 檢查） |
| `/merge-preview` 等管理工具 | 無 | ✅ | 無 | ✅ |

> **註**：系統管理員目前與專家用戶擁有相同的基礎權限（透過 `isAdmin()` 方法識別）。如需為系統管理員添加專屬權限，可使用 `isSuperAdmin()` 方法進行更細緻的權限控制。

---

## 四、User 模型輔助方法

`User` 模型（`app/User.php`）提供以下輔助方法簡化權限檢查：

### 角色檢查方法
- `isActive(): bool` - 檢查用戶是否為活跃狀態（`is_active == 1`）
- `isAdmin(): bool` - 檢查用戶是否為專家或系統管理員（`is_admin` 為 1 或 3）
- `isExpert(): bool` - 檢查用戶是否為專家用戶（`is_admin == 1`）
- `isSuperAdmin(): bool` - 檢查用戶是否為系統管理員（`is_admin == 3`）
- `isCrowdsourcingUser(): bool` - 檢查用戶是否為眾包用戶（`is_admin == 2`）
- `isRegularUser(): bool` - 檢查用戶是否為一般用戶（`is_admin == 0`）

### 權限檢查方法
- `canManageUsers(): bool` - 檢查是否可管理用戶（活躍的專家或系統管理員）
- `canRestoreOperations(): bool` - 檢查是否可執行操作復原（活躍的專家或系統管理員）
- `canWriteDirectly(): bool` - 檢查是否可直接寫入數據（活躍且非眾包用戶）
- `canRunBatchImport(): bool` - 檢查是否可執行批量導入操作（活躍的專家或系統管理員）

### 其他輔助方法
- `getRoleName(): string` - 獲取用戶角色中文名稱（「一般」、「專家」、「眾包」、「系統管理員」）

### 使用示例

```php
// 舊寫法（魔術數字）
if (Auth::user()->is_active == 1 && Auth::user()->is_admin == 1) {
    // ...
}

// 新寫法（使用常量）
if (Auth::user()->is_active == User::STATUS_ACTIVE && Auth::user()->is_admin == User::ROLE_EXPERT) {
    // ...
}

// 推薦寫法（使用輔助方法）
if (Auth::user()->canManageUsers()) {
    // ...
}
```

---

## 五、用戶管理工具

### 5.1 命令行用戶管理（cbdb:manage-user）

項目提供 `cbdb:manage-user` artisan 命令，用於環境初始設置和日常用戶管理。此命令直接使用 `User` 模型創建新用戶，無需依賴開發環境的 Factory 組件，確保在生產環境中也能正常運行。

#### 交互式模式

直接運行命令，系統會逐步詢問所需信息：

```bash
php artisan cbdb:manage-user
```

系統會依次詢問：
1. Email（必填）
2. 用戶名稱
3. 密碼（至少 6 個字符）
4. 激活狀態（未激活 / 激活 / 預留）
5. 用戶角色（一般 / 專家 / 眾包 / 系統管理員）

#### 命令行選項模式

使用選項直接指定參數，適合腳本自動化：

```bash
# 創建系統管理員
php artisan cbdb:manage-user \
  --email=admin@example.com \
  --name="系統管理員" \
  --password=secret123 \
  --active=1 \
  --role=super-admin

# 創建專家用戶
php artisan cbdb:manage-user \
  --email=expert@example.com \
  --name="專家用戶" \
  --password=password123 \
  --active=1 \
  --role=expert

# 更新現有用戶（提升為專家）
php artisan cbdb:manage-user --email=user@example.com --role=expert

# 更新用戶名稱和密碼
php artisan cbdb:manage-user \
  --email=user@example.com \
  --name="新名稱" \
  --password=newpassword

# 列出所有用戶
php artisan cbdb:manage-user --list
```

#### 可用選項

| 選項 | 說明 | 可選值 |
|------|------|--------|
| `--email` | 用戶 Email（必填，用於查找或創建用戶） | 任何有效的 Email 地址 |
| `--name` | 用戶名稱 | 任何字符串 |
| `--password` | 用戶密碼（至少 6 個字符） | 至少 6 個字符的字符串 |
| `--active` | 激活狀態 | `0`（未激活）、`1`（激活）、`2`（預留） |
| `--role` | 用戶角色 | `regular`、`expert`、`crowdsourcing`、`super-admin` |
| `--list` | 列出所有用戶 | - |

#### 工作流程

1. **創建新用戶**：如果提供的 Email 不存在，命令會創建新用戶
2. **更新現有用戶**：如果 Email 已存在，命令會更新該用戶的信息
3. **自動生成字段**：新用戶會自動生成 `confirmation_token` 和其他必需字段

### 5.2 測試和內部工具（User Factory）

項目已為 `User` 模型添加 `HasFactory` trait，測試和內部工具可使用 Factory 創建測試用戶。

#### 基本用法

```php
use App\Models\User;

// 創建普通用戶（默認為激活狀態、一般角色）
$user = User::factory()->create();

// 創建但不保存到數據庫
$user = User::factory()->make();

// 批量創建用戶
$users = User::factory()->count(5)->create();

// 使用自定義屬性
$user = User::factory()->create([
    'name' => '張三',
    'email' => 'zhangsan@example.com',
    'password' => Hash::make('password123'),
]);
```

#### 使用狀態方法

Factory 提供多種狀態方法，方便創建特定類型的用戶：

```php
// 創建活躍用戶
$activeUser = User::factory()->active()->create();

// 創建未激活用戶
$inactiveUser = User::factory()->inactive()->create();

// 創建系統管理員
$superAdmin = User::factory()->superAdmin()->create();

// 創建專家用戶
$expert = User::factory()->expert()->create();

// 創建眾包用戶
$crowdsourcing = User::factory()->crowdsourcing()->create();

// 創建一般用戶
$regular = User::factory()->regular()->create();

// 組合多個狀態
$activeAdmin = User::factory()->active()->superAdmin()->create();

// 創建活躍的管理員（隨機為專家或系統管理員）
$activeAdmin = User::factory()->activeAdmin()->create();
```

#### 在測試中使用

```php
use Tests\TestCase;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class MyFeatureTest extends TestCase
{
    #[Test]
    public function only_active_admins_can_restore_operations()
    {
        // 創建活躍的專家用戶
        $admin = User::factory()->active()->expert()->create();

        // 執行需要管理員權限的操作
        $response = $this->actingAs($admin)
            ->post('/operations/restore/123');

        $response->assertSuccessful();
    }

    #[Test]
    public function crowdsourcing_users_cannot_write_directly()
    {
        // 創建眾包用戶
        $crowdsourcingUser = User::factory()
            ->active()
            ->crowdsourcing()
            ->create();

        $this->assertFalse($crowdsourcingUser->canWriteDirectly());
    }
}
```

#### 向後兼容

舊的 `factory()` 語法仍然支持，無需修改現有代碼：

```php
// 舊語法（仍然可用）
$user = factory(User::class)->create();
$users = factory(User::class, 5)->create();
```

---

## 六、系統管理員角色實施狀態

系統管理員角色（`User::ROLE_SUPER_ADMIN = 3`）已在**模型層面完全實現**，可以正常創建和使用。

### ✅ 已實現的功能

#### 1. User 模型支持
- ✅ 定義了 `User::ROLE_SUPER_ADMIN = 3` 常量
- ✅ 提供完整的輔助方法：
  - `isSuperAdmin(): bool` - 檢查是否為系統管理員
  - `isAdmin(): bool` - 包含專家和系統管理員
  - `canManageUsers(): bool` - 活躍的專家或系統管理員可管理用戶
  - `canRestoreOperations(): bool` - 活躍的專家或系統管理員可復原操作
  - `getRoleName(): string` - 返回「系統管理員」中文名稱

#### 2. 用戶創建和管理
- ✅ `cbdb:manage-user` 命令支持創建系統管理員：
  ```bash
  php artisan cbdb:manage-user \
    --email=admin@example.com \
    --name="系統管理員" \
    --password=secret123 \
    --active=1 \
    --role=super-admin
  ```

- ✅ `UserFactory` 支持創建系統管理員測試用戶：
  ```php
  $superAdmin = User::factory()->superAdmin()->create();
  $activeAdmin = User::factory()->active()->superAdmin()->create();
  ```

#### 3. 權限檢查方法
系統管理員在使用 `isAdmin()` 方法檢查時會被識別為管理員，因此在大多數需要管理員權限的地方已經可以正常工作：

```php
// ✅ 系統管理員會通過這些檢查
if (Auth::user()->isAdmin()) {
    // 專家和系統管理員都可以執行
}

if (Auth::user()->canManageUsers()) {
    // 活躍的專家或系統管理員可以管理用戶
}

if (Auth::user()->canRestoreOperations()) {
    // 活躍的專家或系統管理員可以復原操作
}
```

### 🔧 需要進一步完善的部分

#### 1. UI 顯示更新
某些頁面的角色顯示可能仍使用硬編碼邏輯。**推薦統一使用** `$user->getRoleName()` 方法：

```php
// ❌ 舊寫法（可能不顯示系統管理員）
{{
    $user->is_admin == 2 ? '眾包' :
    ($user->is_admin == 1 ? '專家' : '一般')
}}

// ✅ 新寫法（自動支持所有角色）
{{ $user->getRoleName() }}
```

需要檢查的文件：
- `resources/views/manage/index.blade.php`
- 其他顯示用戶角色的視圖

#### 2. 定義系統管理員專屬權限（可選）
如果系統管理員需要比專家**更高**的權限，可在相關 Controller 中添加判斷：

```php
// 僅限系統管理員的功能
if (!Auth::user()->isSuperAdmin()) {
    flash('該功能僅限系統管理員使用。', 'error');
    return redirect()->back();
}
```

#### 3. Controller 權限檢查遷移
目前多數功能使用 `is_admin == 1` 檢查。若要讓系統管理員也擁有相同權限，**應改用 `isAdmin()` 方法**：

```php
// ❌ 舊寫法（只允許專家）
if (Auth::user()->is_admin == 1) {
    // ...
}

// ✅ 新寫法（允許專家和系統管理員）
if (Auth::user()->isAdmin()) {
    // ...
}
```

需要檢查和更新的地方：
- `app/Http/Controllers/OperationsController.php`
- `app/Http/Controllers/ManageController.php`
- 其他需要管理員權限的 Controller

#### 4. 測試覆蓋
為系統管理員角色補充測試用例：

```php
#[Test]
public function super_admin_can_manage_users()
{
    $superAdmin = User::factory()->active()->superAdmin()->create();

    $this->assertTrue($superAdmin->isSuperAdmin());
    $this->assertTrue($superAdmin->isAdmin());
    $this->assertTrue($superAdmin->canManageUsers());
}
```

### 📋 遷移檢查清單

如果需要在整個系統中完全啟用系統管理員角色，建議按以下順序進行：

- [ ] 1. 使用 `grep` 搜索所有 `is_admin == 1` 的硬編碼檢查
- [ ] 2. 評估每處檢查是否應該包含系統管理員
- [ ] 3. 將適當的檢查改為使用 `isAdmin()` 方法
- [ ] 4. 更新所有視圖中的角色顯示為 `getRoleName()`
- [ ] 5. 補充系統管理員相關的測試用例
- [ ] 6. 在開發/測試環境創建系統管理員用戶進行端到端測試

### 💡 最佳實踐

1. **優先使用輔助方法**：使用 `isAdmin()`、`isSuperAdmin()` 等方法而非直接比較 `is_admin` 值
2. **使用 `getRoleName()`**：在 UI 中顯示角色名稱時統一使用此方法
3. **明確權限需求**：如果某功能僅限專家，使用 `isExpert()`；如果包含所有管理員，使用 `isAdmin()`
4. **避免魔術數字**：使用 `User::ROLE_SUPER_ADMIN` 等常量而非數字 3

**總結**：系統管理員角色在模型和工具層面已完全可用，可以立即開始使用。UI 和某些 Controller 的權限檢查可以根據實際需求逐步遷移和完善。

---

如有新增功能或調整權限，請同步更新本文件，確保後續開發者能快速了解各層級的能力與限制。
