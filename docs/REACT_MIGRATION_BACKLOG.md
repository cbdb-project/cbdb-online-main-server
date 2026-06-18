# React + Inertia 遷移待辦帳本（backlog ledger）

> 本檔是遷移執行的**單一真實來源**：列舉全部待遷移頁面與狀態。策略與規則見 [REACT_INERTIA_MIGRATION_PLAN.md](./REACT_INERTIA_MIGRATION_PLAN.md)（recipe、保真度原則、自主執行協定）。
> **本檔可頻繁更新**（每完成一頁就改狀態）；設計文件保持穩定。

## 狀態圖例
- `todo` 未開始　·　`in-progress` 進行中　·　`in-review` 已實作、走 gate 中　·　`done` 已合併（flag 仍指舊頁，待人切換）　·　`live` 已切換上線　·　`blocked` 卡住待人決定　·　`retired` 舊頁已退役刪除

**認領慣例（並發安全）**：標 `in-progress` 時寫成 `in-progress (iter-id, 起 ISO8601)`。同一時間只有一個 executor、序列執行。開機先 **`git fetch --prune origin`**（失敗即 infra 故障停機）再對帳（見設計文件附錄 C 步驟 0）：孤兒 `in-progress`（無對應 PR 且逾時）標回 `todo`/`blocked`；**帳本與（已刷新的）git/PR 不一致時以 git 為準**。
**挑選順序**：依賴就緒 → phase 編號 → **同 phase 內由上而下嚴格表序**。無可挑（全 `blocked`/`done`）→ 停機回報。
**心得/決策**：踩坑與計畫變更記錄在設計文件附錄 D（活頁），不寫在本表。

## 全域前置（gate 0，必須先完成）
| # | 項目 | 狀態 | 備註 |
|---|---|---|---|
| F1 | Phase 0 工具鏈：Tailwind + shadcn/ui + AdminLTE 視覺 token | done | 附錄 A.1；commit cb51518 |
| F2 | `AppShell` 正式化（新建 DashboardLayout，不動既有精簡 AppShell） | done | 附錄 A.2；commit 710c4f6 |
| F3 | 共用基元：`DataTable`(TanStack)/表單/`Modal`/`ConfirmDialog`/分頁 | done | 附錄 A.3；commit f70ec08 |
| F4 | 後端 `share()` 增補 `auth.user.roles`/`can` + `flash` 橋接 | done | 附錄 A.4；commit 8bc5194 |
| F5 | feature-flag 機制（§五之二）+ 導覽單一來源 | done | 附錄 A.5；commit ddfed0c |
| F6 | 測試範式 `assertInertia`（沿用既有 + share() 契約測試）；E2E 延後至 Phase 3/4 | done | 附錄 A.6 |
| F7 | **複合主鍵 write-path 收斂** + query-path store/update/destroy Feature 測試齊備 | todo | **Phase 4 硬前置**；見 COMPOSITE_PRIMARY_KEY_URL_DESIGN.md；此 gate 只由人翻 `done` |

> **Phase 1+ 任一頁開工前，F1–F5 必須 `done`。Phase 4 任一頁開工前，F7 必須 `done`（否則整個 Phase 4 `blocked`）。**

## Phase 1 — 唯讀葉節點（依賴：F1–F5 必須 `done`；F6 建議）
| # | 頁面 | 路由（舊） | 控制器 | 狀態 | 備註 |
|---|---|---|---|---|---|
| P1-1 | admin/audit_logs/index | `admin.audit-logs` | AdminAuditLogController | done | **試點/參考頁**，附錄 B；新路由 `app.admin.audit-logs`；fidelity spec `docs/migration-specs/admin-audit-logs.md`；flag 預設 old |
| P1-2 | admin/ai_fill_logs/index | `admin.ai-fill-logs` | AiFillLogController | done | 唯讀日誌（卡片列表）；新路由 `app.admin.ai-fill-logs`；spec `docs/migration-specs/admin-ai-fill-logs.md`；flag old |
| P1-3 | admin/explain_sql | `admin.explainsql` | AdminExplainSqlController | done | 新路由 `app.admin.explainsql(.explain)`；唯讀白名單共用 runExplain()；spec `docs/migration-specs/admin-explain-sql.md`；flag old |
| P1-4 | dashboard/index | `dashboard` | DashboardController | done | 新路由 `app.dashboard`；buildStats() 共用；spec `docs/migration-specs/dashboard.md`；flag old |
| P1-5 | profile/edit | `profile.edit`/`profile.update` | UserProfileController | done | 新路由 `app.profile.edit(.update)`；含 TokenManager（api-tokens.*）；share() flash 擴充 session success/error；spec `docs/migration-specs/profile-edit.md`；flag old |
| P1-6 | query_playground/nl_query_logs | `query-playground.nl-query-logs` | QueryPlaygroundController@nlQueryLogs | done | 新路由 `app.query-playground.nl-query-logs`；spec `docs/migration-specs/nl-query-logs.md`；flag old |

## Phase 2 — Codes 代碼表 CRUD（依賴：F*，建議 P1 完成後）
| # | 頁面 | 路由（舊） | 狀態 | 備註 |
|---|---|---|---|---|
| P2-1 | codes/index | `codes.index` | done | 新路由 `app.codes.index`；客戶端搜尋/排序；spec `docs/migration-specs/codes-index.md`；flag old |
| P2-2 | codes/show | `codes.show` | done | 新路由 `app.codes.show`；buildShowPayload/buildCursorPayload 共用（Blade byte-equiv）；spec `docs/migration-specs/codes-show.md`；flag old |
| P2-3 | codes/create | `codes.create`/`codes.store` | done | 新路由 `app.codes.create/store/propose.store`；write-path 共用 performStore/performProposalStore（byte-equiv）；spec `docs/migration-specs/codes-create.md`；flag old |
| P2-4 | codes/edit | `codes.edit`/`codes.update`/`codes.destroy` | done | 新路由 `app.codes.edit/update/destroy/propose.update`；write-path 共用 perform*（byte-equiv）；spec `docs/migration-specs/codes-edit.md`；flag old |
| P2-5 | codes/proposal-edit | `codes.propose.*`/`codes.proposals.*` | done | 新路由 `app.codes.proposals.edit/update/cancel`（update/cancel 重用既有方法）；spec `docs/migration-specs/codes-proposal-edit.md`；flag old |

## Phase 3 — 人物列表與檢視（高流量；依賴：F*）
| # | 頁面 | 路由（舊） | 狀態 | 備註 |
|---|---|---|---|---|
| P3-1 | biogmains/basicinformation/index | `basicinformation.index` | done | 新路由 `app.basicinformation.index`（public）；搜尋+朝代facets+分頁；spec `docs/migration-specs/basicinformation-index.md`；flag old |
| P3-2 | biogmains/basicinformation/show | （legacy `layouts.app`） | blocked | **需人類決策**：show() 實際 render 的是 editor 視圖（edit.blade.php，533 行）的 readonly 模式，非獨立 show 視圖；show.blade.php（唯一用 layouts.app 者）實為孤兒（無人 render）。忠實復刻 = Phase 4 編輯器唯讀版（F7-blocked）。是否改以 PersonBrowser 風格的解耦唯讀視圖取代＝⚠️ 設計決策。見附錄 D.1。 |

## Phase 4 — 人物編輯器 + 12 複合主鍵子資源（XL；最高風險）
> **硬前置 = 全域 gate `F7` 必須 `done`**（複合主鍵 write-path 收斂 + query-path store/update/destroy Feature 測試齊備；見 COMPOSITE_PRIMARY_KEY_URL_DESIGN.md）。F7 未 `done` 則整個 Phase 4 `blocked`。
> ⚠️ 提醒：PersonBrowser tab 為**唯讀**，編輯表單為**全新開發**。每個子資源 4 視圖（index/create/edit/_form）。

| # | 子資源（view dir） | 控制器 | DB 表 / 複合鍵 | 狀態 |
|---|---|---|---|---|
| P4-0 | basicinformation/{create,edit} | BasicInformationController | BIOG_MAIN (c_personid) | todo |
| P4-1 | altname | BasicInformationAltnamesController | ALTNAME_DATA（3-key，無 c_sequence） | todo |
| P4-2 | addresses | BasicInformationAddressesController | BIOG_ADDR_DATA | todo |
| P4-3 | texts | BasicInformationTextsController | BIOG_TEXT_DATA/TEXT_DATA | todo |
| P4-4 | sources | BasicInformationSourcesController | BIOG_SOURCE_DATA | todo |
| P4-5 | offices | BasicInformationOfficesController | POSTED_TO_OFFICE_DATA(+ADDR) | todo |
| P4-6 | assoc | BasicInformationAssocController | ASSOC_DATA（9-col key） | todo |
| P4-7 | kinship | BasicInformationKinshipController | KIN_DATA | todo |
| P4-8 | events | BasicInformationEventsController | EVENTS_DATA | todo |
| P4-9 | statuses | BasicInformationStatusesController | STATUS_DATA | todo |
| P4-10 | entries | BasicInformationEntriesController | ENTRY_DATA（9-col key） | todo |
| P4-11 | possession | BasicInformationPossessionController | POSSESSION_DATA | todo |
| P4-12 | socialinst | BasicInformationSocialInstController | BIOG_INST_DATA | todo |
| P4-P | basicinformation 提案流程 | BasicInformationProposalController | — | todo |

## Phase 5 — 管理與營運工具（依賴：F*，部分依賴 P4 樣板）
| # | 頁面 | 路由（舊） | 狀態 | 備註 |
|---|---|---|---|---|
| P5-1 | operations/index（+restore/提案核可） | `operations.index`/`operations.restore` | done | 新路由 `app.operations.index`（inertia）；buildOperationsListing+serializeOperationRow 共用；寫入端點未改（router.post/delete）；unionPKDef* 移至 app/helpers.php；spec `docs/migration-specs/operations.md`；flag old |
| P5-2 | manage/index | `manage.index` | done | 新路由 `app.manage.index`；buildUserListing 共用（byte-equiv）；spec `docs/migration-specs/manage-index.md`；flag old |
| P5-3 | manage/edit | `manage.edit`/`manage.update` | done | 新路由 `app.manage.edit/update`；performUserUpdate 共用（byte-equiv，含軟刪除）；spec `docs/migration-specs/manage-edit.md`；flag old |
| P5-4 | manage/merge-preview | `merge-preview.index` | done | 新路由 `app.merge-preview.index`（inertia）；buildMergePreview 共用；純唯讀預覽（無寫入）；isAdmin 權限保留；spec `docs/migration-specs/merge-preview.md`；flag old |
| P5-5 | crowdsourcing/index | `crowdsourcing.index` | done | 新路由 `app.crowdsourcing.index`（superadmin+inertia）；buildCrowdsourcingLists 共用；confirm/reject 寫入未改（整頁導覽連 Blade GET）；has_diff 對齊 Blade；spec `docs/migration-specs/crowdsourcing.md`；flag old |
| P5-6 | admin/batch_load_book_titles | `admin.batch-load-book-titles` | done | 新路由（store/undo 重用，listRouteName 依路徑重導）；spec `docs/migration-specs/batch-load-book-titles.md`；flag old |
| P5-7 | admin/batch_load_offices | `admin.batch-load-offices` | done | 新路由（store 重用，listRouteName 重導；backWithErrors 收 Request）；spec `docs/migration-specs/batch-load-offices.md`；flag old |
| P5-8 | admin/batch_load_social_institutes | `admin.batch-load-social-institutes` | done | 新路由（store 重用，listRouteName 重導；backWithErrors 收 Request×3）；spec `docs/migration-specs/batch-load-social-institutes.md`；flag old |
| P5-9 | admin/wiki-maintenance | `admin.wiki-maintenance` | done | 新路由 `app.admin.wiki-maintenance`（inertia）；buildWikiListing 共用；非同步導入 fetch+2s 輪詢+取消；寫入/async 端點未改；spec `docs/migration-specs/wiki-maintenance.md`；flag old |
| P5-10 | admin/cbdb-table-maintenance | `admin.cbdb-table-maintenance` | todo | **輪詢/非同步**（rebuild/progress） |
| P5-11 | admin/unidirectional-relationship-repair | `admin.unidirectional-relationship-repair` | done | 新路由 `app.admin.unidirectional-relationship-repair`（inertia）；純表單頁；repairKinship/Assoc 寫入端點未改（fetch + X-XSRF-TOKEN）；spec `docs/migration-specs/unidirectional-relationship-repair.md`；flag old |
| P5-12 | maps/index | `app.maps.index` | retired/排除 | 獨立全螢幕 Leaflet 地圖應用（自有 historical-maps/app.js），非 AdminLTE dashboard 頁；已在 /app/maps、superadmin。包進 DashboardLayout 反而破壞全幅 UX → 列為 shell 遷移範圍外（例外，見附錄 D.1）。 |

## Phase 6 — 認證頁與入口（建議最後；依賴：F*）
| # | 頁面 | 路由（舊） | 狀態 | 備註 |
|---|---|---|---|---|
| P6-1 | auth/login | `Auth::routes()` | todo | laravel/ui 後端不變 |
| P6-2 | auth/register | `Auth::routes()` | todo | |
| P6-3 | auth/passwords/email | `Auth::routes()` | todo | 忘記密碼 |
| P6-4 | auth/passwords/reset | `Auth::routes()` | todo | 重設密碼 |
| P6-5 | welcome | `/`（WelcomeController） | todo | landing |
| P6-C1 | 刪除死碼 home.blade.php | — | todo | **僅刪 view**；`/home` route/redirect 仍活躍，不可動 |
| P6-C2 | 刪除死碼 auth/register2.blade.php | — | todo | 已確認無引用 |

## Phase 7 — 下架 AdminLTE（全部頁 `live`/`retired` 後）
| # | 項目 | 狀態 |
|---|---|---|
| P7-1 | 移除 admin-lte/jquery/bootstrap/datatables.net-bs4/Select2 主題 | todo |
| P7-2 | 移除 layouts/dashboard-v3 全套 + resources/js/app.js 的 Vue 掛載 | todo |
| P7-3 | 更新 AGENTS.md / ADMINLTE.md / README.md / CHANGELOG.md；標註 ADMINLTE4_UPGRADE_FEASIBILITY.md 已被取代 | todo |

## 已具 React 版、僅待切換 + 退役（非重寫）
| 頁面 | React 路由 | 狀態 | 備註 |
|---|---|---|---|
| view/index、view/list | `app.view.index`/`app.view.show` | todo | React 版已上線；待 flip flag + 退役 Blade `view.index`/`view.show` |
| Query Playground UI | `/app/query-playground` | live | 已是 React（僅 nl_query_logs 日誌頁待遷，見 P1-6） |
| Person Browser | `/app/*` | live | 已是 React（唯讀） |
| Search-by-Entry | `/app/*` | live | 已是 React |

## 明確排除（不在「頁面重寫」範圍）
| 項目 | 原因 |
|---|---|
| cbdbapi/person.blade.php | XML/資料回應樣板（`response()->view()`），非互動頁 |

---

**統計**：待遷移互動頁面組約 30+（展開到視圖 ~105）；Phase 4 為工作量主體。狀態以本表為準，每次迭代更新。
