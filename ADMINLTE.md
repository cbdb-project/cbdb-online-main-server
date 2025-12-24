# AdminLTE 在 CBDB Online 項目中的現況與指引

本文件記錄專案前端技術棧現況。專案已完成從 AdminLTE 2 / Bower / Laravel Mix 到 AdminLTE 3 / Vite 的完整遷移。

## 現況總覽（v3.2 + Vite）
- 全站已切換至 AdminLTE v3.2（Bootstrap 4、Font Awesome 5），所有使用 dashboard 佈局的頁面都走 `layouts/dashboard-v3.blade.php`，專案中不存在 `layouts/dashboard.blade.php`。
- 前端資產以 **Vite** 打包，輸出在 `public/build`。入口：`resources/js/app.js`（主要 UI + jQuery/Bootstrap/AdminLTE/Select2）、`resources/js/datatables.js`（DataTables）。
- `resources/js/jquery-global.js` 會先將 jQuery 掛到 `window`，再載入 Bootstrap 4 bundle（含 Popper）、AdminLTE 3、Select2（Bootstrap 4 主題）與共用的 modal 焦點修復、`initPersonSelect`。
- 版型套用 Font Awesome 5 CDN，其餘 AdminLTE/Bootstrap/Datatables/Select2 皆由 Vite bundle 提供，無外部 JS CDN 依賴。
- 佈局中的客製樣式集中於 `layouts/dashboard-v3.blade.php` 內的 `<style>`（scroll/表格寬度/Select2 高度修正等）；文檔先前列出的 pagination/brand-logo 類 CSS 已被移除，程式碼中無對應片段。

## 已覆蓋的頁面（全部走 v3）
- 模組：codes 全套、operations、modified、view、manage、crowdsourcing、profile、admin 工具全套、basicinformation（含地址/別名/文本/任官/社會關係/入仕/事件/親屬/身份/財產/社會機構/來源）、dashboard。
- 登入/註冊等 Auth 頁面也改用 Vite 入口與 Bootstrap 4 表單樣式，不再載入 AdminLTE 2 資產。
- `resources/views/layouts/app.blade.php`（Auth 用）與 `resources/views/layouts/dashboard-v3.blade.php`（主站）均透過 `@vite(['resources/js/app.js'])`。

## 遺留檔案清理狀態
Laravel Mix 時期的 `resources/assets/` 目錄及相關檔案**已完全移除**：
- ~~`resources/assets/js/bootstrap.js`~~、~~`resources/assets/sass/app.scss`~~ - 原 Mix 入口
- ~~`resources/assets/css/styles.css`~~ - 舊版樣式檔
- 相關的 Mix 配置檔案（`webpack.mix.js`）也已移除

所有前端資源現由 **Vite** 統一管理，入口位於 `resources/js/` 目錄。

## 類名對照（歷史備查）
升版時常用的 v2 → v3 對照，供查漏用：
- 容器：`box`/`panel` → `card`，`box-header`/`panel-heading` → `card-header`，`box-body`/`panel-body` → `card-body`，`box-footer`/`panel-footer` → `card-footer`，`box-tools` → `card-tools`
- 表格：`table-condensed` → `table-sm`
- 工具類：`pull-right` → `float-right`，`pull-left` → `float-left`，`hidden-xs` → `d-none d-sm-block`，`hidden-sm` → `d-none d-md-block`，`hidden-md` → `d-none d-lg-block`，`visible-xs` → `d-block d-sm-none`
- 導航：`sidebar-menu` → `nav nav-pills nav-sidebar flex-column`；`<li class="header">` → `<li class="nav-header">`；`treeview-menu` → `nav nav-treeview`
- Data attributes：`data-widget="collapse"` → `data-card-widget="collapse"`，`data-widget="remove"` → `data-card-widget="remove"`
- 圖標：Font Awesome 4 `fa fa-dashboard` → Font Awesome 5 `fas fa-tachometer-alt`，`fa fa-minus` → `fas fa-minus`，`fa fa-plus` → `fas fa-plus`

## 測試/驗證建議
- 必要頁面 smoke：`/codes`、`/operations`、`/modified`、`/view`、`/manage`、`/basicinformation/...`、`/crowdsourcing`、`/admin/*`、`/home`。
- 確認導航收合、模態框、Select2、DataTables、響應式（桌機/行動）與 Bootstrap 4 表單驗證樣式。
- 若有前端改動，使用 `npm run dev`（或 `npm run build`）重建 `public/build`。

## 後續工作方向
- **AdminLTE 4 升級準備**（Bootstrap 5）：
  - Data attributes：`data-toggle` → `data-bs-toggle`
  - 工具類：`float-*` → `d-flex` / Flexbox utilities
  - 圖標：Font Awesome 5 → Font Awesome 6
  - 測試重點：Vite 構建產物、響應式佈局、JavaScript 插件兼容性
