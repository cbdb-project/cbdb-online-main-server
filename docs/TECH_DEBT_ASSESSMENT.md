# 技術棧現況盤點與現代化行動手冊

> 盤點日期：2026-06-29 ｜ 分支：`docs-tech-debt-assessment`
> English version: [`TECH_DEBT_ASSESSMENT.en.md`](./TECH_DEBT_ASSESSMENT.en.md)
>
> **本文定位**：一份**供同事討論、可直接據以行動的行動手冊（playbook）**——不是純報告。
> 結構＝**評估（Part 1–3）＋ 討論前決策清單（Part 3.5）＋ 逐項行動計畫（Part 4）**。
> 所有結論已附證據（檔案行號／指令結果），計畫力求「拿著就能做、不需再掃描」。
> 每個行動含：目標、前置、逐步操作（含指令／設定檔內容）、影響面、風險、回退、**驗收標準(DoD)**、
> **待討論決策點**、工作量、相依。
>
> **怎麼用這份手冊（給讀者/評審）**：
> 1. 先看 Part 1 對齊認知（核心棧其實偏新）；
> 2. 看 Part 2 證據表，挑要不要做、做哪些；
> 3. **開會時逐條過 Part 3.5 的決策清單並拍板**（每條都標了需要誰決定）；
> 4. 拍板後，每個行動 = 一個獨立 PR，照 Part 4 的步驟執行；
> 5. 本手冊只描述「做什麼、怎麼做」，實際排期與負責人請於討論時填入下表的 Owner/排期欄。

## 方法論（如何驗證）
- 讀權威來源：`package.json`、`composer.json`、`composer.lock`、`package-lock.json`、`vite.config.js`、
  `tsconfig.json`、`.github/workflows/*`、`routes/*`、`README.md`、`AGENTS.md`。
- 以 `grep`/`find` 統計實際引用點，區分「安裝了」與「真的有用」。
- 跑 `npx tsc --noEmit` 量化型別錯誤。附錄 A 列出可重跑指令。

---

# Part 1：先說結論——核心棧其實偏新

考量現在是 2026-06，核心框架屬當代甚至前沿，**「古老」不在核心版本**：

| 元件 | 已安裝版本（lock 實測） | 評價 |
|---|---|---|
| Laravel Framework | v12.62.0（`php ^8.2`，CI 跑 PHP 8.4） | 當代 |
| React / React-DOM | 19.2.4 | 當代 |
| Inertia (`@inertiajs/react`) | 2.3.18 | 當代 |
| Vite | 8.0.16 | 前沿 |
| TypeScript | 6.0.2 | 前沿 |
| Tailwind CSS | 4.3.1（`@tailwindcss/vite`） | 前沿 |
| lucide-react | 1.21.0 | 可用（版號異常，見 E3） |

`tsconfig.json` 現代且嚴格（`strict:true`、`noEmit`、`moduleResolution:"bundler"`、`target/module: ESNext`）。
**真正的債在三處：① 前端工程化缺口、② 遺留前端棧（設計保留待 Phase 7）、③ 少量後端結構債與死依賴。**

---

# Part 2：發現清單（含證據）

嚴重度＝對品質/維護的風險；工作量＝粗估；「設計保留」＝專案刻意保留、非疏失。各項對應 Part 4 的詳細計畫編號。

### A. 前端工程化缺口（增量補強、低風險、性價比最高）
| 編號 | 現狀 | 證據 | 嚴重度 | 工作量 | 計畫 |
|---|---|---|---|---|---|
| A1 | 前端零測試（無 vitest/jest/playwright） | 無任何 JS 測試設定檔；scripts 僅 `dev`/`build`/`prod`；React 核心編輯器無單元/元件測試 | 高 | 中 | §P-A1 |
| A2 | 無 JS/TS lint/format（無 ESLint/Prettier） | `ls .eslintrc* eslint.config.* .prettierrc*`→無；只有 PHP 端 php-cs-fixer | 中 | 低 | §P-A2 |
| A3 | TS strict 配了但未強制 | `npx tsc --noEmit` 回報 **21 個錯誤**；CI 前端只 `npm run prod`（esbuild 不做型別檢查） | 高 | 中 | §P-A3 |
| A4 | husky 裝了卻沒用 | `husky@9` 在 devDeps，但**無 `.husky/`**、無 `prepare` script | 低 | 低 | §P-A4 |
| A5 | CI 無前端關卡 | workflows 僅 `phpunit/php-cs-fixer/codeql`；`phpunit.yml` 只 `npm run prod` | 中 | 低 | §P-A5 |

### B. 遺留前端棧（**設計保留**，AGENTS.md 載明 Phase 7 才物理下架）
| 編號 | 現狀 | 證據 | 嚴重度 | 工作量 | 計畫 |
|---|---|---|---|---|---|
| B1 | AdminLTE 3 + Bootstrap 4 + jQuery 3.5 + DataTables + select2 仍接線於 legacy `app.js` | 引用數：`jquery`×22、`select2`×40、`bootstrap`×19、`datatables`×6、`admin-lte`×6；`resources/views` 仍有 **106** 個 `.blade.php`；`axios` 僅 legacy 用（`app.js:228`） | 中（已隔離） | 大 | §P-B1 |
| B2 | Vue 3 只為 1 個元件存在 | 僅 `resources/js/components/Select.vue`（`app.js:318` import、稍後於 app.js 內 `createApp` 掛載）；`vue`+`@vitejs/plugin-vue`+`@vue/compiler-sfc` 只服務它 | 中 | 中 | §P-B2 |

### C. 後端 / 基礎設施
| 編號 | 現狀 | 證據 | 嚴重度 | 工作量 | 計畫 |
|---|---|---|---|---|---|
| C1 | MariaDB 10.3.39（已 EOL） | `README.md:58`、`AGENTS.md:6`；MariaDB 10.3 官方支援 2023-05 結束 | 高 | 中 | §P-C1 |
| C2 | **同名雙 ApiController**（命名混淆，非死碼） | 兩個類別都叫 `ApiController`：根命名空間 `app/Http/Controllers/ApiController.php`（`api.php` 43 處 `'ApiController@'`，select/search/* 等）＋ `app/Http/Controllers/Api/ApiController.php`（`api.php` **9 處** `'Api\ApiController@'`：OFFICE_CODES、office/entry/place 清單、place_belongs_to 等，經 `RouteServiceProvider` 的 `App\Http\Controllers` 命名空間前綴解析）。**兩者皆 live**，僅命名重複易混淆 | 低 | 低 | §P-C2 |
| C3 | laravel/ui\@4.6.1（過時認證腳手架） | `web.php:19 Auth::routes();`、`web.php:26 HomeController@index`；認證頁已遷 React 但路由仍靠 `Auth::routes()` | 中 | 中 | §P-C3 |

### D. 死依賴 / 冗餘（已驗證可移除）
| 編號 | 依賴 | 證據 | 計畫 |
|---|---|---|---|
| D1 | `sass-loader@12` + `resolve-url-loader@5` | webpack 時代 loader；不在 `vite.config.js`，全庫 `.scss` **0** 個 | §P-D |
| D2 | `sass@1.79` | 無任何 `.scss` | §P-D |
| D3 | `lodash@4.17` | `resources/js` **0** 次使用 | §P-D |
| D4 | `husky@9` | 無 `.husky/`、無 `prepare` script | §P-D / §P-A4 |

> `axios` **不可**列死依賴——legacy `app.js` 仍用 `window.axios`；待 Phase 7 一併處理。

### E. 程式模式與其他
| 編號 | 現狀 | 證據 | 備註 |
|---|---|---|---|
| E1 | 內聯 `style` 寫死十六進制色 | 近期深色模式重構已修約 71 檔 | 新元件一律用 token class；存量漸進收斂 |
| E2 | 複合主鍵走 Query Builder | 受 CBDB schema 限制（`app/Support/CompositePrimaryKey.php`） | 「無法輕易改」類，僅記錄 |
| E3 | lucide-react 版號異常（1.21.0；上游主線 0.x） | 實測可正常打包 | 低優先；§P-D 附帶查證 |

---

# Part 3：建議優先序

1. **A 類工程化安全網**（A2/A3 → A1 → A5 → A4）：純增量、不動功能，讓後續所有改動更安全。
2. **D 類死依賴**：風險極低的快速勝利。
3. **C 類後端債**（C2 → C3 → C1）。
4. **B 類遺留棧**：最大但已隔離，綁定 Phase 7 整批規劃。

---

# Part 3.5：討論前需拍板的決策清單（開會逐條過）

> 這些是「做之前必須有人拍板」的岔路；其餘步驟一旦決策確定即為機械執行。

| # | 決策題 | 選項 | 建議 | 需誰拍板 |
|---|---|---|---|---|
| Q1 | 是否導入前端工程化全套（lint/typecheck/test/CI 關卡） | 全做 / 只做 lint+typecheck / 暫不做 | 全做（A 類性價比最高） | 前端負責人 + Tech Lead |
| Q2 | CI 前端關卡何時轉「阻擋合併」 | 立即阻擋 / 先 `continue-on-error` 觀察一週 | 先觀察一週再強制 | Tech Lead |
| Q3 | husky：正式啟用 pre-commit vs 直接移除 | 啟用（§P-A4 選項一）/ 移除（選項二） | 若採 A2/A3 則啟用，否則移除 | 前端負責人 |
| Q4 | `Auth::routes()` 替代方案 | 顯式路由 / 導入 Fortify | 顯式路由（改動小、無新依賴） | 後端負責人 |
| Q5 | MariaDB 升級目標版本與窗口 | 10.11 LTS / 11.x；維護窗口時間 | 10.11 LTS（LTS 穩定） | 維運 + Tech Lead |
| Q6 | `Select.vue` 處理路徑 | 隨 Phase 7 退場(A) / 先改寫成 React(B) | 路徑 A（除非該頁短期不會下架） | 前端負責人 |
| Q7 | Phase 7 legacy 下架是否啟動、批次切分 | 啟動/暫緩；如何分批 | 待所有頁驗收完成再啟動 | Tech Lead + PO |
| Q8 | `lucide-react@1.21.0` 版號異常處理 | 維持 / 改上游主線版號並鎖定 | 查證來源後再定 | 前端負責人 |

## 行動追蹤表（討論時填寫）
| 行動 | 計畫 | 工作量 | 相依 | Owner | 目標排期 | 狀態 |
|---|---|---|---|---|---|---|
| ESLint+Prettier | §P-A2 | 低 | — | | | 待討論 |
| typecheck 關卡 + 清 21 錯 | §P-A3 | 中 | — | | | 待討論 |
| Vitest + RTL | §P-A1 | 中 | A2 | | | 待討論 |
| husky 收尾 | §P-A4 | 低 | A2（選項一） | | | 待討論 |
| CI 前端關卡 | §P-A5 | 低 | A1/A2/A3 | | | 待討論 |
| 清死依賴 | §P-D | 低 | A4 協調 | | | 待討論 |
| ApiController 改名／消除同名混淆（**非刪除**） | §P-C2 | 低 | — | | | 待討論 |
| 取代 Auth::routes() | §P-C3 | 中 | Q4 | | | 待討論 |
| MariaDB 升級 | §P-C1 | 中 | Q5、維運 | | | 待討論 |
| Vue 退場 | §P-B2 | 中 | Q6、B1 | | | 待討論 |
| Phase 7 legacy 下架 | §P-B1 | 大 | Q7、全頁驗收 | | | 待討論 |

---

# Part 4：詳細實作計畫

> 通則：每個計畫獨立成一個 PR/分支；遵循專案流程（review agent → codex → 通過後 commit/PR/rebase/merge）。
> 凡新增 npm 依賴，版本以「與現有工具鏈相容的最新版」為準（本專案在 Vite 8 / TS 6 / ESLint 9 era），
> 安裝後以 `npm run build` + `npx tsc --noEmit` 驗證不破壞既有建置。

## §P-A2：導入 ESLint + Prettier（建議最先做，後續 A1/A3/A4 都依賴它）
**目標**：前端有自動 lint/format，先 `warn` 不阻擋、再逐步收緊。
**前置**：無。
**步驟**
1. 安裝（flat config，ESLint 9 era）：
   ```bash
   npm i -D eslint @eslint/js typescript-eslint eslint-plugin-react-hooks \
     eslint-plugin-react-refresh prettier eslint-config-prettier
   ```
2. 新增 `eslint.config.js`（flat config，只掃 `resources/js/inertia/**` 的 TS/React，避開 legacy `app.js`）：
   ```js
   import js from '@eslint/js';
   import tseslint from 'typescript-eslint';
   import reactHooks from 'eslint-plugin-react-hooks';
   import reactRefresh from 'eslint-plugin-react-refresh';
   import prettier from 'eslint-config-prettier';

   export default tseslint.config(
     { ignores: ['public/**', 'node_modules/**', 'resources/js/app.js', 'resources/js/datatables.js', 'resources/js/historical-maps/**', 'resources/js/chgis-map/**', 'resources/js/components/**'] },
     js.configs.recommended,
     ...tseslint.configs.recommended,
     {
       files: ['resources/js/inertia/**/*.{ts,tsx}'],
       plugins: { 'react-hooks': reactHooks, 'react-refresh': reactRefresh },
       rules: {
         ...reactHooks.configs.recommended.rules,
         // 起步階段先降級為 warn，避免一次塞爆；穩定後逐條轉 error
         '@typescript-eslint/no-explicit-any': 'warn',
         '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
       },
     },
     prettier, // 關掉與 Prettier 衝突的格式規則
   );
   ```
3. 新增 `.prettierrc.json`（對齊現有程式風格——4 空格縮排、單引號、結尾分號）：
   ```json
   { "tabWidth": 4, "singleQuote": true, "semi": true, "printWidth": 120, "trailingComma": "all" }
   ```
4. `package.json` scripts 增：
   ```json
   "lint": "eslint resources/js/inertia",
   "lint:fix": "eslint resources/js/inertia --fix",
   "format": "prettier --write \"resources/js/inertia/**/*.{ts,tsx,css}\""
   ```
5. 跑 `npm run lint`，記錄 warning 數作為基線；**先不**把既有 warning 清光（避免巨大 diff）。
**影響面**：僅新增設定檔與 scripts，不改執行程式。**風險**：低。
**回退**：移除設定檔與 scripts、`npm uninstall` 上述套件。
**驗證**：`npm run lint` 可執行且只報 warn；`npm run build` 不受影響。
**工作量**：低（~0.5 天）。**相依**：被 A4/A5 依賴。

## §P-A3：建立型別檢查關卡並清除現有 21 個錯誤
**目標**：`tsc --noEmit` 進 CI 並阻擋合併；先清掉現存 21 個錯誤。
**前置**：無（可與 A2 並行）。
**步驟**
1. `package.json` 增 script：`"typecheck": "tsc --noEmit -p tsconfig.json"`。
2. 先盤點現有錯誤並分類：
   ```bash
   npx tsc --noEmit -p tsconfig.json 2>&1 | grep "error TS" | sed -E 's/\(.*//' | sort | uniq -c | sort -rn
   ```
   依本會話觀察，主要集中在 `PersonBrowser/TabContentLoader.tsx`、`BasicInfoEditor.tsx:99`、
   `Pages/BasicInformation/{Edit,Show}.tsx`、`SearchByEntry/Index.tsx`、`ViewTables/Show.tsx`、
   以及 `utils/*.js`（隱式 any，缺 `.d.ts`）。
3. 逐檔修正（每修一檔即 `npm run typecheck` 收斂）：
   - `Section[]/Field[]` 的 `value: unknown` → 收斂型別或在邊界做型別守衛。
   - `SetStateAction<Fields>` 不相容（`BasicInfoEditor.tsx:99`）→ 修正 `Fields` 型別或 setState 回呼。
   - `PageProps` 不滿足 Inertia 約束（`SearchByEntry`、`ViewTables/Show`）→ 讓 interface `extends SharedProps` 或補索引簽章。
   - `../../../utils/*.js`（`disableNumberInputWheel`、`sqlFormatter`）隱式 any → 補同名 `.d.ts` 或改 `.ts`。
4. 全清為 0 後，CI 加關卡（見 §P-A5）。
**影響面**：多為型別標註修正，少數可能揭露真實缺陷（需小心）。**風險**：中（改到執行檔）。
**回退**：型別修正分小 commit，可逐一回退；CI 關卡可先設 `continue-on-error` 緩衝。
**驗證**：`npm run typecheck` 回 0 錯誤；`npm run build` 綠；跑受影響頁的既有 PHPUnit。
**工作量**：中（~1–2 天，視 21 個錯誤深淺）。**相依**：A5 的 typecheck 關卡依賴本項清零。

## §P-A1：導入 Vitest + React Testing Library
**目標**：前端關鍵邏輯/元件可測。
**前置**：建議 A2 先行（共用 TS 設定）。
**步驟**
1. 安裝：
   ```bash
   npm i -D vitest @testing-library/react @testing-library/jest-dom \
     @testing-library/user-event jsdom @vitejs/plugin-react
   ```
2. 新增 `vitest.config.ts`（重用既有 alias，jsdom 環境）：
   ```ts
   import { defineConfig } from 'vitest/config';
   import react from '@vitejs/plugin-react';
   import { fileURLToPath, URL } from 'node:url';

   export default defineConfig({
     plugins: [react()],
     test: { environment: 'jsdom', globals: true, setupFiles: ['./resources/js/inertia/test/setup.ts'],
       include: ['resources/js/inertia/**/*.{test,spec}.{ts,tsx}'] },
     resolve: { alias: { '@': fileURLToPath(new URL('./resources/js/inertia', import.meta.url)) } },
   });
   ```
3. `resources/js/inertia/test/setup.ts`：`import '@testing-library/jest-dom';`
4. scripts 增：`"test": "vitest run"`、`"test:watch": "vitest"`。
5. 先寫高價值、低耦合的測試（不需後端）：
   - `BasicInfoEditor` 的 `deriveNames`（純函式）與「生成拼音」回填邏輯（mock `fetch`）。
   - `logCardStyles`／token class 對映（純資料）。
   - 一個關鍵元件 render 冒煙測試（如 `SelectionDialog` 開關、`Pagination`）。
6. 目標起步覆蓋率：核心 `inertia/components` 純函式優先，不追全站數字。
**影響面**：純新增。**風險**：低。
**回退**：移除設定/測試、`npm uninstall`。
**驗證**：`npm run test` 綠；CI 串接（§P-A5）。
**工作量**：中（框架 0.5 天 + 持續補測）。**相依**：A5。

## §P-A4：husky 收尾（二擇一）
**選項一（建議，若採用 A2/A3）：正式啟用 pre-commit**
1. `npm i -D lint-staged`；`package.json` 增 `"prepare": "husky"`；跑 `npm run prepare`。
2. `npx husky init` 生成 `.husky/pre-commit`，內容：
   ```sh
   npx lint-staged
   ```
3. `package.json` 增：
   ```json
   "lint-staged": {
     "resources/js/inertia/**/*.{ts,tsx}": ["eslint --fix", "prettier --write"],
     "app/**/*.php": ["./vendor/bin/php-cs-fixer fix"]
   }
   ```
**選項二（若暫不導入鉤子）：移除依賴** —— `npm uninstall husky`（見 §P-D）。
**影響面**：開發流程。**風險**：低。**回退**：刪 `.husky/`、移除 prepare/lint-staged。
**驗證**：實際 commit 觸發 hook；CI 不受影響。**工作量**：低。**相依**：選項一依賴 A2。

## §P-A5：CI 加前端關卡
**目標**：PR 阻擋型別/lint/測試錯誤。
**步驟**：在 `.github/workflows/phpunit.yml`（已裝 Node 22 + `npm install`）的 build 步驟後、或獨立 job 增：
```yaml
      - name: Frontend checks
        run: |
          npm run typecheck
          npm run lint
          npm run test
```
（或新建 `frontend.yml`，與 PHPUnit 並行；`on:` 同 push/pull_request。）
**影響面**：CI。**風險**：低，但**必須**在 A3 清零、A1/A2 落地後才開啟阻擋，否則 PR 全紅。可先 `continue-on-error: true` 觀察一週再轉強制。
**回退**：移除步驟。**驗證**：開一個故意含型別錯誤的 PR，確認被擋。**工作量**：低。**相依**：A1/A2/A3。

## §P-D：清除死依賴
**目標**：移除 `sass-loader`、`resolve-url-loader`、`sass`、`lodash`（husky 視 §P-A4 決定）。
**前置**：再次跑驗證（附錄 A 的 D 區指令）確認 0 引用。
**步驟**
1. 最終確認：
   ```bash
   find resources -name '*.scss' | wc -l                 # 預期 0
   grep -rniE "lodash" resources/js | wc -l              # 預期 0
   grep -rniE "resolve-url-loader|sass-loader|\.scss" vite.config.js resources/js | wc -l   # 預期 0
   ```
2. 移除：`npm uninstall sass-loader resolve-url-loader sass lodash`（husky 若採選項二一併 `npm uninstall husky`）。
3. `npm install` 重建 lockfile；`npm run build` 與（若已有）`npm run test`/`typecheck` 全綠。
**影響面**：僅 devDeps。**風險**：低（已證 0 引用）。**回退**：`git checkout package.json package-lock.json && npm install`。
**驗證**：`npm run build` 綠；bundle 不缺檔。**工作量**：低（~0.5 天）。**相依**：D4 與 A4 二選一協調。

## §P-C2：消除「同名雙 ApiController」命名混淆（**改名，非刪除**）
> ⚠️ 注意：`Api/ApiController` 是 **live 程式碼**——`routes/api.php` 有 **9 個路由**指向它（見上），
> **絕對不可刪除**。本計畫只做「重新命名以消除同名混淆」，屬可選的低優先 readability 重構。
**目標**：兩個都叫 `ApiController` 的類別易混淆；把 `Api\ApiController` 改名為描述性名稱（建議
`CodeLookupApiController` 或 `OfficeEntryPlaceApiController`，依其職責），消除歧義。
**前置**：先盤點其全部引用點。
**步驟**
1. 盤點引用（預期 routes/api.php 9 筆 + 類別宣告）：
   ```bash
   # ⚠️ grep 對反斜線轉義敏感：務必用 -F 固定字串，否則 "Api\\ApiController@" 會誤回 0（本手冊作者曾踩此坑）。
   grep -Fc 'Api\ApiController@' routes/api.php                          # 預期 9（OFFICE_CODES…entry_list_by_name）
   php artisan route:list | grep -iE "OFFICE_CODES|post_list|entry_list|place_list|place_belongs_to|office_list_by_name|entry_list_by_name"   # 預期 9 條
   ```
2. 重新命名類別與檔案（`Api/ApiController.php` → `Api/CodeLookupApiController.php`，class 名同步），
   同步更新 `routes/api.php` 的 9 處 `'Api\ApiController@...'` → `'Api\CodeLookupApiController@...'`。
3. `composer dump-autoload`；`php artisan route:list` 比對改名前後路由數一致。
**影響面**：後端（純改名，行為不變）。**風險**：低（機械改名）。**回退**：`git revert`。
**驗收標準(DoD)**：`route:list` 與改名前完全一致（路由數、URI、method 不變）；`./vendor/bin/phpunit` 全綠；全庫無殘留 `Api\ApiController` 引用。
**待討論**：是否值得做（純美化、零功能收益）；若否，至少在兩個檔頭加註解標明彼此關係。**工作量**：低。

## §P-C3：以顯式路由/Fortify 取代 `laravel/ui` 的 `Auth::routes()`
**目標**：移除過時的 laravel/ui 腳手架；認證已是 React/Inertia。
**前置**：先 `php artisan route:list | grep -iE "login|register|password|logout"` 盤點 `Auth::routes()` 實際展開了哪些路由與其 controller。
**步驟**
1. 將 `Auth::routes()` 展開為**顯式**路由（指向現有 Inertia/Auth controllers），逐條對照 `route:list` 補齊：login/logout/register/password.request/password.email/password.reset/password.update（及 `verification.*` 若有用）。
2. 確認 `HomeController@index name('home')` 是否仍需要；若 dashboard 已接管，導向調整。
3. 移除 `composer remove laravel/ui`；`composer dump-autoload`。
4. 全站認證流程回歸：登入/登出/註冊/忘記密碼/重設密碼（已有對應 Feature 測試者優先跑）。
**影響面**：認證路由（高敏感）。**風險**：中（認證面）。**回退**：`git revert` + `composer require laravel/ui`。
**驗證**：`route:list` 與展開前一致；認證 Feature 測試綠；手動走一遍五條流程。**工作量**：中（~1 天）。

## §P-C1：MariaDB 10.3.39 → 受支援版本（10.11 LTS 或 11.x）
**目標**：脫離 EOL 資料庫。屬**環境面**，程式相容性已被 migration 規範保護。
**前置**：確認生產/測試環境部署方式（本 repo 無 docker-compose 版本鎖；版本資訊在 `README.md:58`）。
**步驟**
1. 環境盤點：列出生產 DB 實際版本、字元集（`utf8mb4`）、collation、timezone 設定（`DB_TIMEZONE` 需與 `APP_TIMEZONE` 對齊，見 AGENTS 高風險備忘）。
2. 在 staging 起一台目標版本（建議 10.11 LTS），以生產資料快照還原。
3. 相容性檢查：
   - 跑 `php artisan migrate:status` 與全套 PHPUnit（針對 MySQL 連線，非僅 SQLite）。
   - 檢查是否誤用 DB 專屬語法（README:73 已要求避免 ngram/專屬插件）。
   - 檢查 `sql_mode`（10.11 預設較嚴格，留意 `ONLY_FULL_GROUP_BY`、零日期）對既有查詢影響。
4. 灰度：先切 staging，觀察查詢日誌與效能，再切生產（維護窗口 + 備援）。
**影響面**：全站資料層。**風險**：高（環境）。**回退**：DB 快照還原 + 切回舊版本。
**驗證**：全套 PHPUnit（MySQL 連線）綠；關鍵頁查詢正確、效能無退化。**工作量**：中（環境協調為主）。**相依**：需維運。

## §P-B2：Vue `Select.vue` → 移除 Vue 依賴
**目標**：消滅「為 1 個元件背整套 Vue」。
**前置**：盤點 `Select.vue` 的使用情境（`app.js:318` import、稍後於 app.js 內 `createApp` 掛載；對應 Blade 頁）。
**步驟（二擇一）**
- **路徑 A（推薦，若該頁屬 legacy Blade）**：併入 §P-B1 Phase 7，隨 Blade 一起退場，不單獨改寫。
- **路徑 B（若該 Select 仍被需要）**：用原生 `<select>`（或既有 React `Select` 元件）取代，改 `app.js` 掛載邏輯；確認無其他 `.vue` 後，移除 `vue`、`@vitejs/plugin-vue`、`@vue/compiler-sfc`、`vite.config.js` 的 `vue()` plugin 與 `resolve.alias.vue`。
**影響面**：legacy 前端。**風險**：中。**回退**：`git revert`。
**驗證**：對應頁功能正常；`npm run build` 綠且 bundle 不再含 Vue runtime。**工作量**：中。**相依**：與 B1 協調。

## §P-B1：Phase 7——legacy 前端棧整批下架
**目標**：移除 AdminLTE/Bootstrap4/jQuery/DataTables/select2/Vue/axios 與 106 個 Blade 視圖。
**前置（硬條件）**：對應的每一個 React/Inertia 頁皆已「像人類測試員逐頁對比新舊」驗收通過（見專案 gate-before-flip 規範）；所有 migration flag 確認可永久 `new`、不再需要回退。
**步驟（分批，非一次性）**
1. **凍結回退**：確認 `config/migration_flags.php` 全部 `new` 且穩定一段時間；移除 flag 機制前先移除「指向舊路由」的分支。
2. **逐模組刪 Blade**：依模組（basicinformation/codes/operations/...）分批刪 `resources/views/**` 與對應舊 controller 動作、舊路由；每批跑全測 + 手動冒煙。
3. **拆 Vite 入口**：從 `vite.config.js` 的 `input` 移除 `app.js`、`datatables.js`（確認無頁面再引用）。
4. **移除依賴**：`npm uninstall admin-lte bootstrap jquery datatables.net datatables.net-bs4 @ttskch/select2-bootstrap4-theme select2 vue @vitejs/plugin-vue @vue/compiler-sfc axios`（逐一確認 0 引用後）。
5. **清 layout/資產**：移除 `layouts/dashboard-v3` 等 AdminLTE layout 與相關 CSS/JS。
**影響面**：全站（移除整個 legacy 世界）。**風險**：高（範圍大）。**回退**：分批進行，每批獨立可 revert；保留 tag。
**驗證**：每批後全套 PHPUnit + 手動逐頁；最終 `grep -rniE "jquery|admin-lte|select2"` 歸零。**工作量**：大（多個 PR、跨週）。**相依**：所有頁面驗收完成。

## §E 類（記錄性，無獨立 PR）
- **E1 內聯色**：新元件禁止再寫死 hex，一律 `var(--token)`/token class（規範已在 `docs`/近期 commit 體現）。
- **E2 複合主鍵**：維持 Query Builder 做法，勿改（schema 限制）。
- **E3 lucide-react 版號**：plan 階段查證 `1.21.0` 來源；若為非主線發佈，評估改用上游主線並鎖定。

---

## 附錄 A：可重跑的驗證指令
```bash
# 版本快照
grep -A1 '"laravel/framework"' composer.lock | grep version
node -e "const l=require('./package-lock.json'),p=l.packages||{};for(const k of ['node_modules/vite','node_modules/typescript','node_modules/react','node_modules/tailwindcss','node_modules/@inertiajs/react','node_modules/lucide-react'])p[k]&&console.log(k.replace('node_modules/','')+': '+p[k].version)"

# A3 型別錯誤數與分布
npx tsc --noEmit -p tsconfig.json 2>&1 | grep -cE "error TS"
npx tsc --noEmit -p tsconfig.json 2>&1 | grep "error TS" | sed -E 's/\(.*//' | sort | uniq -c | sort -rn

# A1/A2/A4 工具鏈缺口
ls .eslintrc* eslint.config.* .prettierrc* vitest.config.* jest.config.* playwright.config.* .husky/ 2>/dev/null

# B1 遺留前端引用量
for lib in jquery admin-lte bootstrap datatables select2; do echo "$lib: $(grep -rniE "$lib" resources/js/app.js resources/js/*.js | wc -l)"; done
find resources/views -name '*.blade.php' | wc -l   # Blade 視圖數

# B2 Vue 用量
find resources -name '*.vue'

# C2 同名雙 ApiController（兩者皆 live）— 注意 grep 反斜線轉義，第二條務必用 -F 固定字串
grep -c "'ApiController@" routes/api.php                             # 根 ApiController（預期 43）
grep -Fc 'Api\ApiController@' routes/api.php                         # Api\ApiController（預期 9，非 0！）

# D 死依賴
grep -rniE "lodash" resources/js | wc -l                            # 預期 0
find resources -name '*.scss' | wc -l                               # 預期 0
grep -rniE "resolve-url-loader|sass-loader" vite.config.js           # 預期 0
```
