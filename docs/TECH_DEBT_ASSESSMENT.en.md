# Tech-Stack Assessment & Modernization Playbook

> Audit date: 2026-06-29 ｜ Branch: `docs-tech-debt-assessment`
> 中文版（authoritative source）: [`TECH_DEBT_ASSESSMENT.md`](./TECH_DEBT_ASSESSMENT.md)
>
> **What this is**: a **playbook for the team to discuss and act on** — not just a report.
> Structure = **Assessment (Parts 1–3) + Decisions to settle (Part 3.5) + Per-item action plans (Part 4)**.
> Every conclusion is backed by evidence (file:line / command output); plans are written to be
> "pick-up-and-do, no re-scanning needed." Each action lists: goal, prerequisites, step-by-step ops
> (with commands / config file contents), blast radius, risk, rollback, **Definition of Done (DoD)**,
> **open decisions**, effort, dependencies.
>
> **How to use this (for readers/reviewers)**:
> 1. Read Part 1 to align (the core stack is actually modern);
> 2. Skim the Part 2 evidence tables and decide what (if anything) to do;
> 3. **In the meeting, walk through the Part 3.5 decision list and settle each** (each is tagged with who decides);
> 4. Once decided, each action = one independent PR, executed per Part 4;
> 5. This doc only says "what & how"; fill in Owner/schedule in the tracker during discussion.

## Methodology (how this was verified)
- Read authoritative sources: `package.json`, `composer.json`, `composer.lock`, `package-lock.json`,
  `vite.config.js`, `tsconfig.json`, `.github/workflows/*`, `routes/*`, `README.md`, `AGENTS.md`.
- Used `grep`/`find` to count real references — distinguishing "installed" from "actually used."
- Ran `npx tsc --noEmit` to quantify type errors. Appendix A lists re-runnable commands.

---

# Part 1: Bottom line — the core stack is actually modern

Given it is now 2026-06, the core frameworks are current, even leading-edge. **"Old" is not in the core versions:**

| Component | Installed version (from lockfiles) | Verdict |
|---|---|---|
| Laravel Framework | v12.62.0 (`php ^8.2`; CI runs PHP 8.4) | Current |
| React / React-DOM | 19.2.4 | Current |
| Inertia (`@inertiajs/react`) | 2.3.18 | Current |
| Vite | 8.0.16 | Leading-edge |
| TypeScript | 6.0.2 | Leading-edge |
| Tailwind CSS | 4.3.1 (`@tailwindcss/vite`) | Leading-edge |
| lucide-react | 1.21.0 | Works (unusual version, see E3) |

`tsconfig.json` is itself modern and strict (`strict:true`, `noEmit`, `moduleResolution:"bundler"`, `target/module: ESNext`).
**The real debt is in three places: ① frontend engineering gaps, ② the legacy frontend stack (intentionally retained, pending Phase 7), ③ a few backend structural issues and dead dependencies.**

---

# Part 2: Findings (with evidence)

Severity = risk to quality/maintainability; Effort = rough estimate; "By design" = deliberately kept, not an oversight. Each row maps to a Part 4 plan.

### A. Frontend engineering gaps (incremental, low-risk, highest ROI)
| ID | Status | Evidence | Severity | Effort | Plan |
|---|---|---|---|---|---|
| A1 | No frontend tests (no vitest/jest/playwright) | No JS test config; scripts only `dev`/`build`/`prod`; core React editors have no unit/component tests | High | Med | §P-A1 |
| A2 | No JS/TS lint/format (no ESLint/Prettier) | `ls .eslintrc* eslint.config.* .prettierrc*` → none; only PHP-side php-cs-fixer | Med | Low | §P-A2 |
| A3 | TS strict configured but not enforced | `npx tsc --noEmit` reports **21 errors**; CI only runs `npm run prod` (esbuild does no type-checking) | High | Med | §P-A3 |
| A4 | husky installed but unused | `husky@9` in devDeps, but **no `.husky/`** dir and no `prepare` script | Low | Low | §P-A4 |
| A5 | No frontend gate in CI | workflows are only `phpunit/php-cs-fixer/codeql`; `phpunit.yml` just builds (`npm run prod`) | Med | Low | §P-A5 |

### B. Legacy frontend stack (**by design**; AGENTS.md states physical removal is Phase 7)
| ID | Status | Evidence | Severity | Effort | Plan |
|---|---|---|---|---|---|
| B1 | AdminLTE 3 + Bootstrap 4 + jQuery 3.5 + DataTables + select2 still wired into legacy `app.js` | Ref counts: `jquery`×22, `select2`×40, `bootstrap`×19, `datatables`×6, `admin-lte`×6; `resources/views` still has **106** `.blade.php`; `axios` is legacy-only (`app.js:228`) | Med (isolated) | Large | §P-B1 |
| B2 | Vue 3 exists for a single component | Only `resources/js/components/Select.vue` (imported at `app.js:318`, mounted later via `createApp` in app.js); `vue`+`@vitejs/plugin-vue`+`@vue/compiler-sfc` serve only it | Med | Med | §P-B2 |

### C. Backend / infrastructure
| ID | Status | Evidence | Severity | Effort | Plan |
|---|---|---|---|---|---|
| C1 | MariaDB 10.3.39 (EOL) | `README.md:58`, `AGENTS.md:6`; MariaDB 10.3 reached end-of-support 2023-05 | High | Med | §P-C1 |
| C2 | **Two same-named ApiControllers** (naming confusion, NOT dead code) | Both classes are named `ApiController`: root `app/Http/Controllers/ApiController.php` (43 `'ApiController@'` refs in `api.php`, the select/search/* endpoints) + `app/Http/Controllers/Api/ApiController.php` (**9** `'Api\ApiController@'` refs: OFFICE_CODES, office/entry/place lists, place_belongs_to, etc., resolved via `RouteServiceProvider`'s `App\Http\Controllers` namespace prefix). **Both are live**; only the duplicate name is confusing | Low | Low | §P-C2 |
| C3 | laravel/ui v4.6.1 (dated auth scaffolding) | `web.php:19 Auth::routes();`, `web.php:26 HomeController@index`; auth pages already migrated to React, but routes still rely on `Auth::routes()` | Med | Med | §P-C3 |

### D. Dead / redundant dependencies (verified removable)
| ID | Dependency | Evidence | Plan |
|---|---|---|---|
| D1 | `sass-loader@12` + `resolve-url-loader@5` | webpack-era loaders; not in `vite.config.js`, and there are **0** `.scss` files | §P-D |
| D2 | `sass@1.79` | no `.scss` anywhere | §P-D |
| D3 | `lodash@4.17` | **0** uses in `resources/js` | §P-D |
| D4 | `husky@9` | no `.husky/`, no `prepare` script | §P-D / §P-A4 |

> `axios` must **not** be listed as dead — legacy `app.js` still uses `window.axios`; handle it in Phase 7.

### E. Code patterns & misc
| ID | Status | Evidence | Note |
|---|---|---|---|
| E1 | Inline `style` with hardcoded hex colors | Recent dark-mode refactor already fixed ~71 files | New components must use token classes; existing ones converge gradually |
| E2 | Composite PKs via Query Builder (not Eloquent) | Constrained by CBDB schema (`app/Support/CompositePrimaryKey.php`) | "Can't easily change" — record only |
| E3 | lucide-react version anomaly (1.21.0; upstream mainline is 0.x) | Builds/imports fine | Low priority; verify provenance in §P-D |

---

# Part 3: Recommended priority

1. **A — engineering safety net** (A2/A3 → A1 → A5 → A4): pure addition, doesn't touch features, makes every later change safer.
2. **D — dead dependencies**: very low risk, quick wins.
3. **C — backend debt** (C2 → C3 → C1).
4. **B — legacy stack**: largest but isolated; plan as one batch tied to Phase 7.

---

# Part 3.5: Decisions to settle before starting (walk through in the meeting)

> These are the forks that need someone to decide first; everything else is mechanical once decided.

| # | Decision | Options | Recommendation | Who decides |
|---|---|---|---|---|
| Q1 | Adopt the full frontend toolchain (lint/typecheck/test/CI gate)? | All / only lint+typecheck / none | All (A items are highest ROI) | Frontend lead + Tech Lead |
| Q2 | When to make the CI frontend gate blocking | Block immediately / `continue-on-error` for a week first | Observe a week, then enforce | Tech Lead |
| Q3 | husky: enable pre-commit vs remove | Enable (§P-A4 option 1) / Remove (option 2) | Enable if adopting A2/A3, else remove | Frontend lead |
| Q4 | Replacement for `Auth::routes()` | Explicit routes / adopt Fortify | Explicit routes (smaller change, no new dep) | Backend lead |
| Q5 | MariaDB target version & window | 10.11 LTS / 11.x; maintenance window | 10.11 LTS (stable LTS) | Ops + Tech Lead |
| Q6 | How to handle `Select.vue` | Retire with Phase 7 (A) / rewrite to React first (B) | Path A (unless that page won't be retired soon) | Frontend lead |
| Q7 | Start Phase 7 legacy removal? batching? | Start/defer; how to split | Start only after all pages are accepted | Tech Lead + PO |
| Q8 | `lucide-react@1.21.0` version anomaly | Keep / switch to upstream mainline & pin | Verify provenance first | Frontend lead |

## Action tracker (fill in during discussion)
| Action | Plan | Effort | Depends on | Owner | Target date | Status |
|---|---|---|---|---|---|---|
| ESLint + Prettier | §P-A2 | Low | — | | | TBD |
| typecheck gate + clear 21 errors | §P-A3 | Med | — | | | TBD |
| Vitest + RTL | §P-A1 | Med | A2 | | | TBD |
| husky cleanup | §P-A4 | Low | A2 (option 1) | | | TBD |
| CI frontend gate | §P-A5 | Low | A1/A2/A3 | | | TBD |
| Remove dead deps | §P-D | Low | coordinate w/ A4 | | | TBD |
| Rename ApiController / de-dupe name (**not delete**) | §P-C2 | Low | — | | | TBD |
| Replace Auth::routes() | §P-C3 | Med | Q4 | | | TBD |
| MariaDB upgrade | §P-C1 | Med | Q5, Ops | | | TBD |
| Retire Vue | §P-B2 | Med | Q6, B1 | | | TBD |
| Phase 7 legacy removal | §P-B1 | Large | Q7, all pages accepted | | | TBD |

---

# Part 4: Detailed action plans

> General rule: each plan = one independent PR/branch; follow the project flow (review agent → codex → then commit/PR/rebase/merge).
> For any new npm dependency, use "the latest version compatible with the current toolchain" (this repo is in the Vite 8 / TS 6 / ESLint 9 era);
> after installing, verify with `npm run build` + `npx tsc --noEmit` that the existing build isn't broken.

## §P-A2: Add ESLint + Prettier (do this first; A1/A3/A4 build on it)
**Goal**: automatic lint/format on the frontend, starting at `warn` (non-blocking), tightening over time.
**Prereq**: none.
**Steps**
1. Install (flat config, ESLint 9 era):
   ```bash
   npm i -D eslint @eslint/js typescript-eslint eslint-plugin-react-hooks \
     eslint-plugin-react-refresh prettier eslint-config-prettier
   ```
2. Add `eslint.config.js` (flat config; lint only `resources/js/inertia/**` TS/React, skip legacy `app.js`):
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
         // start as warn to avoid a huge diff; promote to error once stable
         '@typescript-eslint/no-explicit-any': 'warn',
         '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
       },
     },
     prettier, // disable formatting rules that conflict with Prettier
   );
   ```
3. Add `.prettierrc.json` (match existing style — 4-space indent, single quotes, semicolons):
   ```json
   { "tabWidth": 4, "singleQuote": true, "semi": true, "printWidth": 120, "trailingComma": "all" }
   ```
4. Add scripts to `package.json`:
   ```json
   "lint": "eslint resources/js/inertia",
   "lint:fix": "eslint resources/js/inertia --fix",
   "format": "prettier --write \"resources/js/inertia/**/*.{ts,tsx,css}\""
   ```
5. Run `npm run lint`, record the warning count as a baseline; do **not** clear all existing warnings now (avoids a massive diff).
**Blast radius**: config + scripts only, no runtime code. **Risk**: low.
**Rollback**: remove config/scripts, `npm uninstall` the packages.
**DoD**: `npm run lint` runs and only reports warnings; `npm run build` unaffected.
**Effort**: low (~0.5 day). **Depends**: required by A4/A5.

## §P-A3: Add a type-check gate and clear the existing 21 errors
**Goal**: `tsc --noEmit` in CI and blocking merges; first clear the existing 21 errors.
**Prereq**: none (can run parallel to A2).
**Steps**
1. Add script: `"typecheck": "tsc --noEmit -p tsconfig.json"`.
2. Inventory and classify current errors:
   ```bash
   npx tsc --noEmit -p tsconfig.json 2>&1 | grep "error TS" | sed -E 's/\(.*//' | sort | uniq -c | sort -rn
   ```
   Per this session's observation, they cluster in `PersonBrowser/TabContentLoader.tsx`, `BasicInfoEditor.tsx:99`,
   `Pages/BasicInformation/{Edit,Show}.tsx`, `SearchByEntry/Index.tsx`, `ViewTables/Show.tsx`,
   and `utils/*.js` (implicit any, missing `.d.ts`).
3. Fix file by file (run `npm run typecheck` after each):
   - `Section[]/Field[]` `value: unknown` → narrow the type or add a type guard at the boundary.
   - `SetStateAction<Fields>` mismatch (`BasicInfoEditor.tsx:99`) → fix the `Fields` type or the setState callback.
   - `PageProps` not satisfying the Inertia constraint (`SearchByEntry`, `ViewTables/Show`) → make the interface `extends SharedProps` or add an index signature.
   - `../../../utils/*.js` (`disableNumberInputWheel`, `sqlFormatter`) implicit any → add matching `.d.ts` or convert to `.ts`.
4. Once at 0, add the CI gate (see §P-A5).
**Blast radius**: mostly type annotations; a few may reveal real defects (handle carefully). **Risk**: medium (touches runtime files).
**Rollback**: keep fixes as small commits, revert individually; the CI gate can start with `continue-on-error`.
**DoD**: `npm run typecheck` reports 0 errors; `npm run build` green; affected pages' existing PHPUnit pass.
**Effort**: medium (~1–2 days). **Depends**: A5's typecheck gate depends on this reaching zero.

## §P-A1: Add Vitest + React Testing Library
**Goal**: testable frontend logic/components.
**Prereq**: A2 recommended first (shared TS config).
**Steps**
1. Install:
   ```bash
   npm i -D vitest @testing-library/react @testing-library/jest-dom \
     @testing-library/user-event jsdom @vitejs/plugin-react
   ```
2. Add `vitest.config.ts` (reuse existing alias, jsdom env):
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
3. `resources/js/inertia/test/setup.ts`: `import '@testing-library/jest-dom';`
4. Add scripts: `"test": "vitest run"`, `"test:watch": "vitest"`.
5. Start with high-value, low-coupling tests (no backend needed):
   - `BasicInfoEditor`'s `deriveNames` (pure function) and the "generate pinyin" fill logic (mock `fetch`).
   - `logCardStyles` / token-class mapping (pure data).
   - One smoke render test for a key component (e.g. `SelectionDialog` open/close, `Pagination`).
6. Initial coverage target: prioritize pure functions in `inertia/components`; don't chase a site-wide number.
**Blast radius**: pure addition. **Risk**: low.
**Rollback**: remove config/tests, `npm uninstall`.
**DoD**: `npm run test` green; wired into CI (§P-A5).
**Effort**: medium (0.5 day setup + ongoing). **Depends**: A5.

## §P-A4: husky cleanup (choose one)
**Option 1 (recommended if adopting A2/A3): enable pre-commit**
1. `npm i -D lint-staged`; add `"prepare": "husky"` to `package.json`; run `npm run prepare`.
2. `npx husky init` generates `.husky/pre-commit` containing:
   ```sh
   npx lint-staged
   ```
3. Add to `package.json`:
   ```json
   "lint-staged": {
     "resources/js/inertia/**/*.{ts,tsx}": ["eslint --fix", "prettier --write"],
     "app/**/*.php": ["./vendor/bin/php-cs-fixer fix"]
   }
   ```
**Option 2 (if not adopting hooks now): remove the dependency** — `npm uninstall husky` (see §P-D).
**Blast radius**: dev workflow. **Risk**: low. **Rollback**: delete `.husky/`, remove prepare/lint-staged.
**DoD**: an actual commit triggers the hook; CI unaffected. **Effort**: low. **Depends**: option 1 depends on A2.

## §P-A5: Add the frontend CI gate
**Goal**: PRs block on type/lint/test errors.
**Steps**: in `.github/workflows/phpunit.yml` (already has Node 22 + `npm install`), after the build step or as a separate job:
```yaml
      - name: Frontend checks
        run: |
          npm run typecheck
          npm run lint
          npm run test
```
(Or create `frontend.yml` running in parallel with PHPUnit; same `on:` push/pull_request.)
**Blast radius**: CI. **Risk**: low, but **only** enable blocking after A3 hits zero and A1/A2 land, otherwise every PR goes red. Consider `continue-on-error: true` for a week first.
**Rollback**: remove the step. **DoD**: open a PR with a deliberate type error and confirm it's blocked. **Effort**: low. **Depends**: A1/A2/A3.

## §P-D: Remove dead dependencies
**Goal**: remove `sass-loader`, `resolve-url-loader`, `sass`, `lodash` (husky depends on §P-A4).
**Prereq**: re-run the Appendix A "D" checks to confirm 0 references.
**Steps**
1. Final confirmation:
   ```bash
   find resources -name '*.scss' | wc -l                 # expect 0
   grep -rniE "lodash" resources/js | wc -l              # expect 0
   grep -rniE "resolve-url-loader|sass-loader|\.scss" vite.config.js resources/js | wc -l   # expect 0
   ```
2. Remove: `npm uninstall sass-loader resolve-url-loader sass lodash` (if husky takes option 2, also `npm uninstall husky`).
3. `npm install` to rebuild the lockfile; `npm run build` and (if present) `npm run test`/`typecheck` all green.
**Blast radius**: devDeps only. **Risk**: low (0 references proven). **Rollback**: `git checkout package.json package-lock.json && npm install`.
**DoD**: `npm run build` green; no missing modules in the bundle. **Effort**: low (~0.5 day). **Depends**: coordinate D4 with A4.

## §P-C2: Resolve the "two same-named ApiControllers" confusion (**RENAME, not delete**)
> ⚠️ Note: `Api/ApiController` is **live code** — `routes/api.php` has **9 routes** pointing to it (see above),
> so it **must NOT be deleted**. This plan is only "rename to remove the duplicate-name confusion," an optional low-priority readability refactor.
**Goal**: two classes both named `ApiController` are confusing; rename `Api\ApiController` to something descriptive
(suggest `CodeLookupApiController` or `OfficeEntryPlaceApiController` per its responsibility) to remove the ambiguity.
**Prereq**: inventory all references first.
**Steps**
1. Inventory references (expect 9 in routes/api.php + the class declaration):
   ```bash
   # ⚠️ grep is sensitive to backslash escaping: use -F (fixed string), or "Api\\ApiController@" wrongly returns 0 (the author hit this).
   grep -Fc 'Api\ApiController@' routes/api.php                          # expect 9 (OFFICE_CODES…entry_list_by_name)
   php artisan route:list | grep -iE "OFFICE_CODES|post_list|entry_list|place_list|place_belongs_to|office_list_by_name|entry_list_by_name"   # expect 9 rows
   ```
2. Rename the class and file (`Api/ApiController.php` → `Api/CodeLookupApiController.php`, class name in sync),
   and update the 9 `'Api\ApiController@...'` references in `routes/api.php` → `'Api\CodeLookupApiController@...'`.
3. `composer dump-autoload`; `php artisan route:list` to confirm route count matches before/after.
**Blast radius**: backend (pure rename, behavior unchanged). **Risk**: low (mechanical rename). **Rollback**: `git revert`.
**DoD**: `route:list` identical to before (count, URIs, methods unchanged); `./vendor/bin/phpunit` green; no remaining `Api\ApiController` references.
**Open question**: is it worth doing (pure cosmetics, zero functional gain)? If not, at least add header comments to both files noting their relationship. **Effort**: low.

## §P-C3: Replace laravel/ui's `Auth::routes()` with explicit routes / Fortify
**Goal**: remove the dated laravel/ui scaffolding; auth is already React/Inertia.
**Prereq**: first run `php artisan route:list | grep -iE "login|register|password|logout"` to see exactly which routes `Auth::routes()` expands to and their controllers.
**Steps**
1. Expand `Auth::routes()` into **explicit** routes (pointing at the existing Inertia/Auth controllers), matching `route:list` one by one: login/logout/register/password.request/password.email/password.reset/password.update (and `verification.*` if used).
2. Confirm whether `HomeController@index name('home')` is still needed; if the dashboard takes over, adjust the redirect.
3. Remove `composer remove laravel/ui`; `composer dump-autoload`.
4. Regression-test the whole auth flow: login/logout/register/forgot-password/reset-password (run existing Feature tests first if any).
**Blast radius**: auth routes (high-sensitivity). **Risk**: medium (auth). **Rollback**: `git revert` + `composer require laravel/ui`.
**DoD**: `route:list` identical to before expansion; auth Feature tests green; manually walk all five flows. **Effort**: medium (~1 day).

## §P-C1: MariaDB 10.3.39 → supported version (10.11 LTS or 11.x)
**Goal**: get off an EOL database. This is **environment-side**; code compatibility is already protected by the migration rules.
**Prereq**: confirm how prod/test environments are deployed (this repo has no docker-compose version pin; version info is in `README.md:58`).
**Steps**
1. Environment inventory: list prod DB actual version, charset (`utf8mb4`), collation, timezone (`DB_TIMEZONE` must align with `APP_TIMEZONE`, see AGENTS high-risk notes).
2. Stand up the target version in staging (recommend 10.11 LTS), restored from a prod snapshot.
3. Compatibility checks:
   - Run `php artisan migrate:status` and the full PHPUnit suite (against MySQL connection, not just SQLite).
   - Check for DB-specific syntax (README:73 already requires avoiding ngram / proprietary plugins).
   - Check `sql_mode` (10.11 defaults are stricter: `ONLY_FULL_GROUP_BY`, zero-dates) impact on existing queries.
4. Roll out gradually: switch staging first, watch query logs and performance, then prod (maintenance window + backup).
**Blast radius**: the whole data layer. **Risk**: high (environment). **Rollback**: restore DB snapshot + switch back.
**DoD**: full PHPUnit (MySQL connection) green; key-page queries correct, no performance regression. **Effort**: medium (mostly coordination). **Depends**: Ops.

## §P-B2: Vue `Select.vue` → remove the Vue dependency
**Goal**: eliminate "carrying a whole Vue runtime for one component."
**Prereq**: inventory how `Select.vue` is used (the `app.js:318` import / `createApp` mount point, the corresponding Blade pages).
**Steps (choose one)**
- **Path A (recommended if that page is legacy Blade)**: fold into §P-B1 Phase 7, retire it together with Blade; don't rewrite separately.
- **Path B (if that Select is still needed)**: replace with a native `<select>` (or the existing React `Select`), update the `app.js` mount logic; after confirming no other `.vue`, remove `vue`, `@vitejs/plugin-vue`, `@vue/compiler-sfc`, the `vue()` plugin in `vite.config.js`, and `resolve.alias.vue`.
**Blast radius**: legacy frontend. **Risk**: medium. **Rollback**: `git revert`.
**DoD**: the page works; `npm run build` green and the bundle no longer contains Vue runtime. **Effort**: medium. **Depends**: coordinate with B1.

## §P-B1: Phase 7 — retire the legacy frontend stack as one batch
**Goal**: remove AdminLTE/Bootstrap4/jQuery/DataTables/select2/Vue/axios and the 106 Blade views.
**Prereq (hard requirement)**: every corresponding React/Inertia page has been accepted "page-by-page, compared old vs new like a human tester" (see the project's gate-before-flip rule); all migration flags confirmed permanently `new`, no rollback needed.
**Steps (batched, not all at once)**
1. **Freeze rollback**: confirm `config/migration_flags.php` is all `new` and stable for a while; remove the "points to old route" branches before removing the flag mechanism.
2. **Delete Blade per module**: by module (basicinformation/codes/operations/...), delete `resources/views/**` and the corresponding old controller actions / old routes in batches; run full tests + manual smoke each batch.
3. **Trim Vite inputs**: remove `app.js`, `datatables.js` from `vite.config.js` `input` (after confirming no page still references them).
4. **Remove deps**: `npm uninstall admin-lte bootstrap jquery datatables.net datatables.net-bs4 @ttskch/select2-bootstrap4-theme select2 vue @vitejs/plugin-vue @vue/compiler-sfc axios` (each after confirming 0 references).
5. **Clean layouts/assets**: remove `layouts/dashboard-v3` and related AdminLTE layout CSS/JS.
**Blast radius**: site-wide (removes the entire legacy world). **Risk**: high (large scope). **Rollback**: batch-by-batch, each independently revertable; keep tags.
**DoD**: full PHPUnit + manual page-by-page each batch; finally `grep -rniE "jquery|admin-lte|select2"` returns zero. **Effort**: large (multiple PRs, multi-week). **Depends**: all pages accepted.

## §E items (informational, no standalone PR)
- **E1 inline colors**: new components must not hardcode hex; use `var(--token)` / token classes (the convention is reflected in `docs`/recent commits).
- **E2 composite PKs**: keep the Query Builder approach, don't change (schema constraint).
- **E3 lucide-react version**: verify the provenance of `1.21.0` at plan time; if it's not a mainline release, evaluate switching to upstream mainline and pinning.

---

## Appendix A: re-runnable verification commands
```bash
# Version snapshot
grep -A1 '"laravel/framework"' composer.lock | grep version
node -e "const l=require('./package-lock.json'),p=l.packages||{};for(const k of ['node_modules/vite','node_modules/typescript','node_modules/react','node_modules/tailwindcss','node_modules/@inertiajs/react','node_modules/lucide-react'])p[k]&&console.log(k.replace('node_modules/','')+': '+p[k].version)"

# A3 type-error count and distribution
npx tsc --noEmit -p tsconfig.json 2>&1 | grep -cE "error TS"
npx tsc --noEmit -p tsconfig.json 2>&1 | grep "error TS" | sed -E 's/\(.*//' | sort | uniq -c | sort -rn

# A1/A2/A4 toolchain gaps
ls .eslintrc* eslint.config.* .prettierrc* vitest.config.* jest.config.* playwright.config.* .husky/ 2>/dev/null

# B1 legacy frontend reference counts
for lib in jquery admin-lte bootstrap datatables select2; do echo "$lib: $(grep -rniE "$lib" resources/js/app.js resources/js/*.js | wc -l)"; done
find resources/views -name '*.blade.php' | wc -l   # Blade view count

# B2 Vue usage
find resources -name '*.vue'

# C2 two same-named ApiControllers (both live) — mind grep backslash escaping; use -F for the 2nd
grep -c "'ApiController@" routes/api.php                             # root ApiController (expect 43)
grep -Fc 'Api\ApiController@' routes/api.php                         # Api\ApiController (expect 9, not 0!)

# D dead deps
grep -rniE "lodash" resources/js | wc -l                            # expect 0
find resources -name '*.scss' | wc -l                               # expect 0
grep -rniE "resolve-url-loader|sass-loader" vite.config.js           # expect 0
```
