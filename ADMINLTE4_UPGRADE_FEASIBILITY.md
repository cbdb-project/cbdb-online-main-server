# AdminLTE 4 升級可行性分析報告

## 執行摘要

本報告評估 CBDB Online 項目從 **AdminLTE 3.2 (Bootstrap 4)** 升級到 **AdminLTE 4 (Bootstrap 5)** 的可行性。

**結論**：**可行但需謹慎規劃**。升級工作量中等，建議分階段執行，預計需要全面測試所有頁面功能。

---

## 一、當前技術棧現狀

### 1.1 前端框架
- **AdminLTE**: v3.2.0 (Bootstrap 4)
- **Bootstrap**: 4.x (透過 AdminLTE 引入)
- **Font Awesome**: 5.15.4
- **jQuery**: 3.5.0
- **Vue**: 3.x
- **構建工具**: Vite 7.2.4

### 1.2 關鍵依賴套件
```json
{
  "admin-lte": "^3.2.0",
  "datatables.net-bs4": "^2.3.5",
  "@ttskch/select2-bootstrap4-theme": "^1.5.2"
}
```

### 1.3 項目規模
- **Blade 模板數量**: 約 90 個
- **使用 Bootstrap 4 data attributes**: 18 處 (`data-toggle`, `data-widget` 等)
- **使用 Bootstrap 4 工具類**: 28 處 (`float-*`, `hidden-xs`, `visible-*` 等)
- **前端入口文件**: 3 個 (app.js, datatables.js, passport.js)

---

## 二、AdminLTE 4 主要變更

### 2.1 版本狀態
- **最新版本**: `4.0.0-rc6` (Release Candidate 6)
- **穩定性**: ⚠️ **尚未正式發布**，仍在候選發布階段
- **風險評估**: 中等 - RC 版本通常接近穩定，但可能仍有 API 變更

### 2.2 核心升級內容

#### A. Bootstrap 4 → Bootstrap 5
| 項目 | Bootstrap 4 | Bootstrap 5 |
|------|-------------|-------------|
| jQuery 依賴 | ✅ 必須 | ❌ 移除 |
| Data Attributes | `data-toggle` | `data-bs-toggle` |
| 工具類 | `float-*`, `ml-*`, `mr-*` | 保留 `float-*`，改用 `ms-*`, `me-*` |
| 響應式隱藏 | `hidden-xs`, `visible-*` | `d-none d-sm-block` 等 |
| 表單 | `custom-select`, `custom-checkbox` | 原生樣式，移除 `custom-*` |
| 模態框 | `$.fn.modal()` | `new bootstrap.Modal()` |

#### B. Font Awesome 5 → 6
- 大部分圖標向後兼容
- 部分圖標重命名或移除
- 需要更新 CDN 引用

#### C. JavaScript API 變更
```javascript
// Bootstrap 4
$('#myModal').modal('show');
$('[data-toggle="tooltip"]').tooltip();

// Bootstrap 5
const modal = new bootstrap.Modal('#myModal');
modal.show();
const tooltip = new bootstrap.Tooltip('[data-bs-toggle="tooltip"]');
```

---

## 三、項目影響範圍評估

### 3.1 必須修改的文件

#### A. 依賴套件 (`package.json`)
```diff
{
  "dependencies": {
-   "admin-lte": "^3.2.0",
+   "admin-lte": "^4.0.0",
-   "datatables.net-bs4": "^2.3.5",
+   "datatables.net-bs5": "^2.x.x",
-   "@ttskch/select2-bootstrap4-theme": "^1.5.2",
+   "select2-bootstrap-5-theme": "^1.x.x"
  }
}
```

#### B. Blade 模板 (約 90 個文件)
1. **Data Attributes 更新** (18 處)
   - `data-toggle="modal"` → `data-bs-toggle="modal"`
   - `data-toggle="collapse"` → `data-bs-toggle="collapse"`
   - `data-toggle="dropdown"` → `data-bs-toggle="dropdown"`
   - `data-widget="collapse"` → `data-card-widget="collapse"` (AdminLTE 專屬)

2. **工具類更新** (28 處)
   - `float-right` → `float-end` (Bootstrap 5 改名)
   - `float-left` → `float-start`
   - `ml-*` → `ms-*` (margin-left → margin-start)
   - `mr-*` → `me-*` (margin-right → margin-end)
   - `hidden-xs` → `d-none d-sm-block`
   - `visible-xs` → `d-block d-sm-none`

#### C. JavaScript 文件
1. **`resources/js/app.js`** (206 行)
   - 移除 Bootstrap 4 bundle 引入
   - 添加 Bootstrap 5 引入
   - 更新 Select2 主題配置
   - 更新模態框事件監聽器：
     ```javascript
     // 舊版 (Bootstrap 4)
     $(document).on('show.bs.modal', '.modal', ...);

     // 新版 (Bootstrap 5) - 事件名稱相同，但可能需要調整處理方式
     ```

2. **`resources/js/datatables.js`** (13 行)
   - 更新 DataTables 套件引入：
     ```javascript
     - import 'datatables.net-bs4';
     - import 'datatables.net-bs4/css/dataTables.bootstrap4.min.css';
     + import 'datatables.net-bs5';
     + import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
     ```

3. **`resources/views/layouts/dashboard-v3.blade.php`**
   - 更新 Font Awesome CDN 為 v6
   - 檢查自定義樣式與 Bootstrap 5 的兼容性

### 3.2 需要測試驗證的功能

#### A. UI 組件 (高優先級)
- ✅ **模態框 (Modal)**: 操作復原確認、查詢詳細等
- ✅ **下拉菜單 (Dropdown)**: 導航欄、操作按鈕
- ✅ **摺疊 (Collapse)**: 側邊欄、卡片工具
- ✅ **工具提示 (Tooltip)**: 懸停提示
- ✅ **Select2**: 人物選擇、代碼表選擇
- ✅ **DataTables**: 所有列表頁面

#### B. 響應式佈局 (中優先級)
- ✅ 桌面版 (≥992px): 固定側邊欄、獨立滾動
- ✅ 平板版 (768-991px): 響應式調整
- ✅ 手機版 (<768px): 隱藏/顯示元素

#### C. Dark Mode (中優先級)
- ✅ 自定義樣式覆蓋
- ✅ Select2 下拉菜單樣式
- ✅ 鏈接顏色調整

#### D. 自定義功能 (高優先級)
- ✅ 模態框焦點管理 (app.js:121-161)
- ✅ Person Select 初始化 (app.js:66-103)
- ✅ Vite 就緒回調機制 (dashboard-v3.blade.php:19-27)

---

## 四、風險評估

### 4.1 高風險項目 ⚠️

1. **AdminLTE 4 尚未正式發布**
   - **影響**: 可能存在未知 bug 或 API 變更
   - **緩解**: 等待正式版發布，或在測試環境充分驗證 RC 版本

2. **Bootstrap 5 移除 jQuery 依賴**
   - **影響**: 項目仍依賴 jQuery (Select2、DataTables、自定義代碼)
   - **緩解**: Bootstrap 5 可與 jQuery 共存，但需確保正確引入順序

3. **第三方套件兼容性**
   - **DataTables**: 需要從 `datatables.net-bs4` 遷移到 `datatables.net-bs5`
   - **Select2**: 需要更換 Bootstrap 5 主題套件
   - **緩解**: 兩者均有 Bootstrap 5 版本可用

### 4.2 中風險項目 ⚙️

1. **自定義樣式衝突**
   - **影響**: `dashboard-v3.blade.php` 中 266 行自定義 CSS 可能與 Bootstrap 5 衝突
   - **緩解**: 逐一測試並調整

2. **模態框事件處理**
   - **影響**: `app.js` 中的模態框焦點修復可能需要調整
   - **緩解**: Bootstrap 5 的事件系統大致相同，但需驗證

### 4.3 低風險項目 ✅

1. **Vue 3 兼容性**: 不受 AdminLTE 升級影響
2. **Vite 構建系統**: 僅需更新套件引入路徑
3. **後端 Laravel 代碼**: 完全不受影響

---

## 五、升級策略建議

### 5.1 分階段執行計劃

#### **階段 1: 準備與驗證** (預估 1-2 天)
1. ✅ 建立獨立的測試分支 `feat/adminlte4-upgrade`
2. ✅ 在本地環境安裝 AdminLTE 4-rc6
3. ✅ 確認第三方套件的 Bootstrap 5 版本可用性
4. ✅ 閱讀 AdminLTE 4 和 Bootstrap 5 的遷移指南

#### **階段 2: 核心升級** (預估 2-3 天)
1. ✅ 更新 `package.json` 依賴
2. ✅ 更新 `resources/js/app.js` 和 `datatables.js`
3. ✅ 更新 `dashboard-v3.blade.php` 主佈局
4. ✅ 批量替換 Blade 模板中的 data attributes
5. ✅ 批量替換工具類 (可使用正則表達式)

#### **階段 3: 全面測試** (預估 3-5 天)
1. ✅ 手動測試所有主要頁面 (約 20-30 個核心頁面)
2. ✅ 驗證響應式佈局 (桌面/平板/手機)
3. ✅ 驗證 Dark Mode 功能
4. ✅ 執行 PHPUnit 測試套件
5. ✅ 瀏覽器兼容性測試 (Chrome, Firefox, Safari, Edge)

#### **階段 4: 修復與優化** (預估 2-3 天)
1. ✅ 修復發現的樣式問題
2. ✅ 調整自定義 CSS
3. ✅ 優化性能 (如有必要)
4. ✅ 更新文檔 (`AGENTS.md`, `ADMINLTE.md`)

#### **階段 5: 上線準備** (預估 1 天)
1. ✅ 代碼審查
2. ✅ 最終回歸測試
3. ✅ 準備回滾計劃
4. ✅ 部署到 staging 環境
5. ✅ 生產環境部署

**總預估時間**: 9-14 工作日

### 5.2 技術實施要點

#### A. 批量替換腳本範例
```bash
# 1. 替換 data-toggle 為 data-bs-toggle
find resources/views -name "*.blade.php" -type f -exec sed -i 's/data-toggle="/data-bs-toggle="/g' {} +

# 2. 替換工具類
find resources/views -name "*.blade.php" -type f -exec sed -i 's/\bfloat-right\b/float-end/g' {} +
find resources/views -name "*.blade.php" -type f -exec sed -i 's/\bfloat-left\b/float-start/g' {} +
find resources/views -name "*.blade.php" -type f -exec sed -i 's/\bml-/ms-/g' {} +
find resources/views -name "*.blade.php" -type f -exec sed -i 's/\bmr-/me-/g' {} +
```

#### B. package.json 更新範例
```json
{
  "dependencies": {
    "admin-lte": "^4.0.0",
    "datatables.net": "^1.13.8",
    "datatables.net-bs5": "^2.1.0",
    "select2": "^4.1.0",
    "select2-bootstrap-5-theme": "^1.3.0"
  }
}
```

#### C. app.js 更新要點
```javascript
// 1. 移除 Bootstrap 4 bundle
- import 'admin-lte/plugins/bootstrap/js/bootstrap.bundle';

// 2. AdminLTE v4 應已包含 Bootstrap 5，確認引入方式
import 'admin-lte';

// 3. 更新 Select2 主題
- $.fn.select2.defaults.set('theme', 'bootstrap4');
+ $.fn.select2.defaults.set('theme', 'bootstrap-5');
```

### 5.3 回滾計劃

如遇到嚴重問題，可快速回滾：
1. ✅ 保留 Git 分支指向升級前的穩定版本
2. ✅ 保留舊版 `package-lock.json` 備份
3. ✅ 記錄所有自定義修改，便於重新應用

---

## 六、替代方案

### 6.1 繼續使用 AdminLTE 3
**優點**:
- ✅ 零風險，當前系統穩定運行
- ✅ Bootstrap 4 仍在維護中 (至 2023 年)
- ✅ 社群資源豐富

**缺點**:
- ⚠️ 技術債務累積
- ⚠️ 未來升級成本更高
- ⚠️ 新功能缺失

### 6.2 等待 AdminLTE 4 正式版
**建議**: ✅ **推薦此方案**

**理由**:
- RC6 版本仍可能有 API 變更
- 正式版預計包含更完善的文檔
- 社群會有更多遷移經驗分享
- 可利用等待期間準備遷移計劃和腳本

**行動**:
1. 監控 AdminLTE GitHub 倉庫的發布動態
2. 準備遷移腳本和測試清單
3. 在測試環境進行預先驗證

---

## 七、最終建議

### 7.1 核心建議
**等待 AdminLTE 4 正式發布後再升級**，但現在可以開始準備：

1. ✅ **立即行動**:
   - 整理所有使用 Bootstrap 4 專屬特性的代碼清單
   - 準備自動化替換腳本
   - 建立測試清單 (約 20-30 個核心頁面)

2. ✅ **AdminLTE 4 正式發布後**:
   - 在獨立分支執行升級
   - 完成階段 1-5 的實施計劃
   - 保留至少 1 週的測試時間

3. ✅ **長期規劃**:
   - 記錄遷移經驗到 `ADMINLTE.md`
   - 更新 `AGENTS.md` 的前端技術棧說明
   - 考慮建立前端樣式規範文檔

### 7.2 成功關鍵因素
1. ⭐ **充分測試**: 所有主要功能頁面必須手動驗證
2. ⭐ **回滾準備**: 確保可隨時回退到穩定版本
3. ⭐ **文檔更新**: 同步更新所有相關技術文檔
4. ⭐ **分階段部署**: 先 staging，後 production

### 7.3 時機建議
**最佳升級時機**: AdminLTE 4.0.0 正式版發布後 2-4 週
- 社群已有初步反饋
- 重大 bug 已修復
- 遷移文檔完善

---

## 八、參考資源

### 8.1 官方文檔
- [AdminLTE 4 文檔](https://adminlte.io/docs/4.0/)
- [Bootstrap 5 遷移指南](https://getbootstrap.com/docs/5.3/migration/)
- [Font Awesome 6 升級指南](https://fontawesome.com/docs/web/setup/upgrade/)

### 8.2 第三方套件
- [DataTables Bootstrap 5](https://datatables.net/examples/styling/bootstrap5.html)
- [Select2 Bootstrap 5 Theme](https://github.com/apalfrey/select2-bootstrap-5-theme)

### 8.3 項目文檔
- `AGENTS.md` - 項目技術棧總覽
- `ADMINLTE.md` - AdminLTE 3 遷移記錄
- `package.json` - 當前依賴清單

---

**文檔版本**: 1.0
**更新日期**: 2025-12-24
**作者**: Claude (AI Agent)
**審核狀態**: 待人工審核
