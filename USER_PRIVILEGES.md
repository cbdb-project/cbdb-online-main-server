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
| `3` | 系統管理員（預留） | `User::ROLE_SUPER_ADMIN` | 預留角色，擁有比專家更高階的權限，需配合程式實作後啟用。 |

> **角色切換邏輯**：在 `ManagementController@edit(type=2)` 中實現循環切換：`1 → 2 → 0 → 1`。若要包含系統管理員，需調整為 `1 → 2 → 0 → 3 → 1`。
>
> **常量使用**：`User` 模型已定義角色和狀態常量（參見 `app/User.php`），建議在新代碼中使用常量而非魔術數字。

---

## 三、權限差異總覽

| 動作 | 一般用戶 (`is_admin=0`) | 專家用戶 (`is_admin=1`) | 眾包用戶 (`is_admin=2`) |
|------|-----------------------|------------------------|------------------------|
| `/basicinformation` 新增 / 修改 | 直接寫入 `BIOG_MAIN` | 同一般用戶 | 建立眾包提案，`crowdsourcing_status = 2`，不直接寫表 |
| `/codes` 新增 / 修改 | 直接寫入各代碼表 | 同一般用戶，可額外審核提案 | 建立提案，交由專家審核 |
| `/operations` 審核提案、操作復原 | 無 | ✅（僅活躍專家可用） | 無 |
| `/modified` 審核提案 | 無 | ✅ | 無 |
| `/crowdsourcing` 審核（Confirm） | 無 | ✅ | 只能提交提案 |
| `/manage` 列表管理帳號 | 無 | ✅ | 無 |
| `/merge-preview` 等管理工具 | 無 | ✅ | 無 |

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

## 五、新增「系統管理員」的實施指南

系統已預留系統管理員角色（`User::ROLE_SUPER_ADMIN = 3`），若要啟用需完成以下步驟：

### 1. 調整角色切換邏輯
修改 `ManagementController@edit(type=2)` 的角色切換邏輯：

```php
// 當前：1 → 2 → 0 → 1
// 修改為：1 → 2 → 0 → 3 → 1
if($user->is_admin == 1) { $user->is_admin = 2; }
elseif($user->is_admin == 2) { $user->is_admin = 0; }
elseif($user->is_admin == 0) { $user->is_admin = 3; }
elseif($user->is_admin == 3) { $user->is_admin = 1; }
```

### 2. 更新 UI 顯示
修改 `resources/views/manage/index.blade.php` 的角色顯示：

```php
{{
    $user->is_admin == 3 ? '系統管理員' :
    ($user->is_admin == 2 ? '眾包' :
    ($user->is_admin == 1 ? '專家' : '一般'))
}}

// 或使用輔助方法（推薦）
{{ $user->getRoleName() }}
```

### 3. 定義系統管理員專屬權限（可選）
若系統管理員需要比專家更高的權限，需在相關 Controller 中添加判斷：

```php
// 僅限系統管理員
if (!Auth::user()->isSuperAdmin()) {
    flash('該功能僅限系統管理員使用。', 'error');
    return redirect()->back();
}
```

### 4. 測試覆蓋
為新角色補充測試用例，確保權限檢查正確。

**注意**：目前多數功能使用 `is_admin == 1` 檢查，若要讓系統管理員也擁有相同權限，應改用 `isAdmin()` 方法（已包含專家和系統管理員）。

---

如有新增功能或調整權限，請同步更新本文件，確保後續開發者能快速了解各層級的能力與限制。
