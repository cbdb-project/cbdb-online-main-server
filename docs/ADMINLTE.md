# AdminLTE 在 CBDB Online 的歷史與下架紀錄

🔴 **AdminLTE 已於 2026-09-15 完整下架，本文件自此為歷史文件，不是操作指引。**

專案曾經歷 AdminLTE 2 / Bower / Laravel Mix → AdminLTE 3 / Vite 的完整遷移，最後在 React/Inertia
遷移完成後整套移除。下架的執行紀錄見
[docs/BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md](./BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md) 環節 5。

## 現在長什麼樣

- 主站互動頁面都是 **React/Inertia**（`resources/js/inertia/**`），樣式走 **Tailwind v4**
  （唯一含 `@import 'tailwindcss'` 的檔是 `resources/css/inertia.css`）＋ shadcn/ui 慣例元件。
- `resources/views/` 只剩 4 個 Blade 檔，都不是 AdminLTE 頁：
  `inertia.blade.php`（React 根模板）、`maps/index.blade.php`（獨立全螢幕 Leaflet 地圖殼）、
  `cbdbapi/person.blade.php`（v1 API 回應樣板）、`partials/chgis-map-assets.blade.php`。
  ⚠️ `maps/index.blade.php` **是仍在服役的互動頁**（Leaflet 全螢幕地圖，掛在 `/app/maps`），
  不是 legacy 殘留——它只是沒有 React 化，且自帶 CDN Leaflet、不套任何殼。
- Vite 入口只剩 3 個：`resources/js/inertia/app.tsx`、`resources/js/historical-maps/app.js`、
  `resources/js/chgis-map/app.js`。
- 圖示仍用 Font Awesome（`@fortawesome/fontawesome-free`，由 `resources/css/inertia.css` 引入）——
  React 側邊欄與各頁的 `fas fa-*` class 名沿用自 AdminLTE 時期，**這是刻意保留的**。

## 已移除的東西（環節 5，2026-09-15）

**5a**：6 個 layout Blade 檔（`layouts/{app,dashboard-v3,header-v3,footer,sidebar-v3,partials/sidebar-node}`）
與 `AppServiceProvider` 的 `View::composer('layouts.dashboard-v3', …)`。

**5b**：
- JS／CSS：`resources/js/{app.js,jquery-global.js,datatables.js,components/Select.vue,utils/datetime.js}`、
  `resources/css/{select2-overrides,mobile-responsive,ai-autofill}.css`。
- `vite.config.js`：`app.js`／`datatables.js` 兩個入口、`vue()` plugin、`vue` 別名。
- `package.json`：`admin-lte`、`jquery`、`datatables.net`、`datatables.net-bs4`、
  `@ttskch/select2-bootstrap4-theme`、`vue`、`@vue/compiler-sfc`、`@vitejs/plugin-vue`、
  `axios`、`lodash`、`sass`。

⚠️ **刻意保留**（不要跟著刪）：`resources/js/utils/{disableNumberInputWheel,sqlFormatter}.js`
（被 `inertia/app.tsx` 與 `QueryPlayground/SqlEditorPanel.tsx` import）、`resources/js/chgis-map/`、
`resources/js/historical-maps/`、`leaflet`、`@fortawesome/fontawesome-free`。

## 對後續工作的意義

- **不要重新引入 jQuery、Bootstrap、DataTables、Select2 或 Vue**（AGENTS.md §4 已列為硬規則）。
  React 端的對應做法：modal 用 `components/ui/Modal.tsx`、表格用 `@tanstack/react-table`、
  可搜尋選單用既有的 autocomplete 元件。
- [docs/ADMINLTE4_UPGRADE_FEASIBILITY.md](./ADMINLTE4_UPGRADE_FEASIBILITY.md) 評估的是
  「AdminLTE 3 → 4（Bootstrap 5）」，**該路線已被 React/Inertia 遷移取代**，僅供歷史查閱。
- 舊版的 v2 → v3 class 名對照表已無用途，隨本次改寫移除；需要時從 git 歷史取回
  （本檔 2026-09-15 之前的版本）。
