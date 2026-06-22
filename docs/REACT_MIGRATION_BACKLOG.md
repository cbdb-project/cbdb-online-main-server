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
| F7 | **複合主鍵 write-path 收斂** + query-path store/update/destroy Feature 測試齊備 | **done**（2026-06-22，使用者經 /goal 授權翻） | **Phase 4 硬前置已解除**；見 COMPOSITE_PRIMARY_KEY_URL_DESIGN.md。**W1–W4 完成、過 review+codex gate、全套 1688 綠（證據見下方 F7 備註）；W5（舊 Blade 4 表單下線）為 Phase 4 後續清理、不阻 F7。** |

> **Phase 1+ 任一頁開工前，F1–F5 必須 `done`。Phase 4 任一頁開工前，F7 必須 `done`（否則整個 Phase 4 `blocked`）。**

### F7 現況盤點（2026-06-22 調查）

> 結論：**update 主流程已收斂、路由層 100% 到位；但 create 整體未進 query 模式，create/delete + NULL 邊界測試只覆蓋 4/12 子資源。距離可翻 `done` 尚遠。**

**兩條 mutation 通道並存（盤點時兩邊一起看）：**
1. Web query 路由（`*Query` 方法 + `routes/web.php` 的 `.query` 路由，底層 `BiogMainRepository`）。
2. API v2 mutate（`POST /api/v2/mutate`、`app/Services/Mutations/*` handlers，PK 走結構化 `target.pk`）。`tests/Feature/ApiV2*Test.php` 全部打這條，**非** web `.query` 路由。

**已 100% 到位：** 12 子資源的 web query **edit/update/delete 路由**、控制器 `editQuery/updateQuery/destroyQuery`、API v2 **update handler** 全齊。

**測試覆蓋矩陣（C=create / U=update / D=delete，指 Feature 測試）：**

| 狀態 | 子資源 | 缺口 |
|---|---|---|
| ✅ C+U+D | altname、addresses、entries、statuses | create/delete handler 由 #942 補；仍缺 **NULL-PK-段** 邊界測試 |
| ⚠️ U+C，缺 D | sources | 無 delete handler 與 delete 測試 |
| ⚠️ 只有 U | texts、kinship、events、possession、socialinst、offices(posting) | 缺 create/delete handler + 測試 |
| ⚠️ U + 假 D | assoc | delete 測試測的是舊 path-based `assocDeleteById` 字串解析（`AssocDataDeleteTest`），非 query-path；另缺 create |

**翻 `done` 前必補（四項）：**
1. **create 從未走 query 路徑** — 12 子資源 create/store 全走舊 `Route::resource`（`routes/web.php:174-185`），無 `storeQuery`/API v2 create（除 #942 的 4 個 + sources inline）。違反設計收斂標準第 1 條。
2. **API v2 create/delete 只覆蓋 4/12**；texts/assoc/kinship/events/possession/socialinst/offices 在 mutate 層**連 handler 都沒有**。
3. **NULL-PK-段邊界**只 sources 一例（`ApiV2MutateTest.php:665`），其餘 11 個無。違反收斂標準第 4 條。
4. **前端 4 表單**（texts/addresses/altname/entries `_form.blade.php`）仍提交舊 path-based 寫路徑（設計文件 v1.3 自述）。

**非阻礙（資料完整性地雷已於先前 commit 解決，設計文件附錄 C/D 未同步，勿被誤導）：**
- ALTNAME 3-key/4-key 不匹配 → 已修（#834 Phase 0–4：`56126df`/`d2cad62`）。
- EVENTS 無主鍵 → 已修（#760 `cbe6d5c`、#773）。
- c_pages 及各種含 `-` 解析 → 已改用查詢參數模式（#745 `d04a546`、#777 `da3e2f2`、#775）。
- ASSOC 9-key / BIOG_INST 4-key → 設計文件自述 2026-01-26 已修。

### F7 收斂工作分解（2026-06-22 人類定方向，待 `/goal` 執行）

> **F7 範圍重新定義**：以 **API v2 mutate（`/api/v2/create|mutate|delete`）為新寫入層的單一通道**，12 子資源 **C/U/D + NULL-PK-段邊界測試齊備**即可翻 `done`；不再要求改造舊 Blade 表單去走 web `storeQuery`（舊表單於對應 React 頁完成後直接下線，見 W5）。決策見設計文件附錄 D.1（2026-06-22）。

**已批准方向（使用者 2026-06-22）：**
- **create 走 API v2 create handler（選項 A）**，沿用 #942（altname/address/entry/status）為樣板；不新增 web `storeQuery`。
  - ⚠️ **命名語意**：此「create handler」對應 REST 的 `store`（**僅使用者按下儲存時才 INSERT**），不是「進入即建立」。「打開新增表單」為**純前端動作**（React 渲染空表單），**不觸發任何後端寫入**；使用者不存就離開＝零後端呼叫、零記錄。**勿做成一進表單就建草稿記錄。**（現行兩段式：web `create()` 只 `return view`、`store()` 才寫入，API v2 維持相同語意。）
- 補齊 8 個子資源 create/delete handler + 測試。
- NULL 邊界測試由 executor 選最佳實踐（建議：以 `CompositePrimaryKey::SCHEMAS` 驅動的**參數化測試**，一次覆蓋各表可為 NULL 的 PK 片段，避免逐檔重複）。
- assoc delete 補 query-path/API v2 回歸（取代僅有的舊 path `assocDeleteById` 測試）。
- 舊前端 4 表單（texts/addresses/altname/entries）於對應 React 頁完成後下線舊寫路徑。

**工作項（executor 逐項勾；handler 樣板＝#942）：**
| # | 項目 | 對象 | 完成定義 | 狀態 |
|---|---|---|---|---|
| W1 | 補 API v2 **create** handler + 註冊 + 測試 | texts、assoc、kinship、events、possession、socialinst、offices（sources 已有 create） | `MutationHandlerRegistry` 註冊；`ApiV2Create*Test` 綠 | **done**（2026-06-22） |
| W2 | 補 API v2 **delete** handler + 註冊 + 測試 | sources、texts、assoc、kinship、events、possession、socialinst、offices | `ApiV2Delete*Test` 綠；assoc 改打 query-path（W4 合併） | **done**（2026-06-22） |
| W3 | **NULL-PK-段** 邊界測試 | PK 片段缺失/空值/哨兵的可達邊界 | 守衛與可空段覆蓋 | **done**（2026-06-22） |
| W4 | assoc delete 改 query-path 回歸 | assoc | 不再依賴 `assocDeleteById` 字串解析測試 | **done**（2026-06-22，併入 W2 `ApiV2DeleteAssociationTest`） |
| W5 | 舊 Blade 4 表單下線舊寫路徑 | texts/addresses/altname/entries `_form.blade.php` | **依賴對應 React 頁（Phase 4）完成**；F7 翻 done 不以此為前提，列為後續清理 | todo（Phase 4 後續，不阻 F7） |

> **W1–W4 完成且測試綠（2026-06-22）。F7 技術前提已就緒，待人翻 `done`（W5 為 Phase 4 後續清理，不阻 F7）。翻 `done` 仍只由人執行。**

**W1–W4 完成證據（2026-06-22）：**
- **W1 create handlers（7）**：簡單 5 個（`TextCreateHandler`/`AssociationCreateHandler`/`KinshipCreateHandler`/`EventCreateHandler`/`SocialInstitutionCreateHandler`，繼承 `AbstractPersonSubresourceCreateHandler`）；困難 2 個（`PossessionCreateHandler`/`PostingCreateHandler`，因 surrogate id 配發 + 地址副表，委派 `possessionStoreById`/`officeStoreById`）。proposal 模式對 possession/offices 回 501（與既有 v2 proposal delete 一致）。
- **W2 delete handlers（8）**：簡單 6 個（`TextDeleteHandler`/`KinshipDeleteHandler`/`EventDeleteHandler`/`SocialInstitutionDeleteHandler`/`AssociationDeleteHandler`/`SourceDeleteHandler`）；委派 2 個（`PossessionDeleteHandler`/`PostingDeleteHandler`，刪主表+副表）。基底新增 `optionalKeyFields()`（sources c_pages）與 `normalizeTargetPk()`（sources c_pages null→'' 對齊 create canonical，確保 round-trip）。
- **W3**：`ApiV2PkSegmentBoundaryTest`（必填 PK 片段缺失被拒、不誤寫；texts 3-key + assoc 9-key）。可空 c_pages、assoc 哨兵由既有 `ApiV2MutateTest`/`ApiV2DeleteSourceTest`/`ApiV2CreateAssociationTest` 覆蓋。
- **修正（codex 發現）**：`possessionStoreById` 的 surrogate id 配發移入交易內 `lockForUpdate`（原在交易外 max+1，併發會重複 PK）；`AssociationCreateHandler` 補 legacy PK 哨兵正規化（emptyToSentinel）。
- **測試**：新增測試檔 `ApiV2Create{Text,Kinship,Event,SocialInstitution,Association,Possession,Posting}Test`、`ApiV2Delete{Text,Kinship,Event,SocialInstitution,Association,Source,Possession,Posting}Test`、`ApiV2PkSegmentBoundaryTest`。全套 `./vendor/bin/phpunit` **1688 綠**。每個小環節經 review agent + codex gate 至無嚴重 issue。

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
| P3-2 | biogmains/basicinformation/show | （legacy `layouts.app`） | **done**（2026-06-22，flag old）：P4-0-B 建獨立 `BasicInformation/Show` React 唯讀頁（app.basicinformation.show）取代 editor-readonly，使用者選「另做獨立編輯頁」方向。 | **需人類決策**：show() 實際 render 的是 editor 視圖（edit.blade.php，533 行）的 readonly 模式，非獨立 show 視圖；show.blade.php（唯一用 layouts.app 者）實為孤兒（無人 render）。忠實復刻 = Phase 4 編輯器唯讀版（F7-blocked）。是否改以 PersonBrowser 風格的解耦唯讀視圖取代＝⚠️ 設計決策。見附錄 D.1。 |

## Phase 4 — 人物編輯器 + 12 複合主鍵子資源（XL；最高風險）
> **硬前置 `F7` 已於 2026-06-22 `done`** → Phase 4 解除 blocked，agent 可開工（flag 預設 old、不自動上線）。後端寫入層＝API v2 mutate（create/update/delete handler 12 子資源齊備、含測試）。
> ⚠️ 提醒：PersonBrowser tab 為**唯讀**，編輯表單為**全新開發**。

### Phase 4 實作盤點與增量計畫（2026-06-22，依 Explore 盤點）
**後端現況**：12 子資源的 API v2 create/update/delete handler **全部就緒**（W1–W4）。`BiogMainMutationHandler` 僅 `update`（**無** person create/delete handler）。
**前端缺口（需新建）**：① 子資源 React 編輯表單（目前 PersonBrowser tab 內為 `Legacy{Create,Edit,Delete}Button` 導舊 Blade）；② 共用 async-autocomplete / Select2 替代元件（目前僅 `BasicInfoView.fetchEnumOptions` 私有邏輯，未抽共用）；③ 前端年號/曆法轉換 util/元件（不存在）；④ `BasicInformationController` 的 `appEdit/appCreate/appShow` 與 `app.basicinformation.edit` 路由（不存在）。
**參考樣板**：CRUD 編輯器＝`CodesController` app* 方法 + `resources/js/inertia/Pages/Codes/{Edit,Create}.tsx`（useForm）；API v2 前端呼叫＝`PersonBrowser/BasicInfoView.tsx` `save()`（`fetch('/api/v2/mutate')` + `X-CSRF-TOKEN` meta）；唯讀展示＝`PersonBrowser/tabs/*Tab.tsx`；測試＝`CodesEditInertiaTest` + `ApiV2*<Resource>Test`。

**建議增量順序**：
- **F4-infra（前置）**：抽出共用 `<CodeAutocomplete>`（複用 `fetchEnumOptions` 打 `/api/select/{model}`）+ 子資源編輯 Modal 樣板。其餘子資源編輯器複用之。
- **P4-1 altname（第一個子資源樣板）**：AltNamesTab 內 Legacy 按鈕 → React Modal 表單，create/update/delete 走 `/api/v2/{create,mutate,delete}`（payload 見 COMPOSITE_PRIMARY_KEY；後端已測）。flag `basicinformation.altname`（或共用 editor flag）。
- **P4-2…P4-12**：照 P4-1 樣板逐一複製（addresses/texts/sources/offices/assoc/kinship/events/statuses/entries/possession/socialinst）。offices/possession 注意 create 走 `/api/v2/create`（surrogate id 由後端配發、proposal 暫 501）。
- **P4-0 人物主檔編輯頁**：⚠️ **與 P3-2 同屬「show=editor readonly」架構決策**，page 架構（獨立 Edit 頁 vs 沿用 PersonBrowser BasicInfoView inline 編輯）**需人拍板**後再做；BIOG_MAIN update 後端與 `BasicInfoView.save()` 前端核心已存在。person **新增/刪除**需先補 `BiogMainCreateHandler/DeleteHandler`（v2 目前無），或維持舊 Blade store/destroy。
- **P4-P 提案流程**：子資源 proposal create 走 `/api/v2/create` mode=proposal（多數已支援；possession/offices proposal 暫 501，需後端補）。

> 每個增量：flag 預設 old → review agent → codex → 下一個。**flag 切換上線、刪除舊 Blade 仍由人。**
> **教訓（多次 codex 抓出，務必套用於剩餘子資源）**：
> 1. **隱藏/未在表單顯示的 PK 欄，編輯模式一律不送入 changes**（由 target.pk=row.pk 經後端 buildNewPk 保留原值）。否則用哨兵 0 覆寫會使 PK 漂移／副表孤兒。已在 P4-5 offices（c_office_id）、P4-10 entries（c_inst_code/c_inst_name_code）各抓一次。
> 2. **副表以含某 PK 欄位為鍵時**（POSTED_TO_ADDR_DATA、POSSESSION_ADDR），該 PK 欄編輯須唯讀；泛型 update 只改主表不遷移副表。
> 3. **欄名對照真實 migration schema**：handler allowedFields 勿含不存在欄（已修 EVENTS c_event_type、ENTRY c_entry_nh_code/c_secondary_source_title/c_supplement）；測試自建表勿過度簡化或用假欄。
> 4. **哨兵對稱**：字串/年份特殊哨兵（assoc c_text_title '[n/a]'、c_assoc_first_year '-9999'）update handler 也須 emptyToSentinel；數值碼空值前端送 -999（非 ''）。

| # | 子資源（view dir） | 控制器 | DB 表 / 複合鍵 | 狀態 |
|---|---|---|---|---|
| P4-0 | basicinformation/{create,edit,show} | BasicInformationController | BIOG_MAIN (c_personid) | **done**（2026-06-22，flag old；使用者選「另做獨立編輯頁」）：P4-0-A 補 BiogMainCreateHandler（client 提供 c_personid + 驗證）/BiogMainDeleteHandler（軟刪除 c_name_chn='<待删除>'，非真刪）；P4-0-B 建 appEdit/appCreate/appShow 路由+控制器+React 頁（Edit 複用 BasicInfoView、Show 唯讀解 P3-2、Create 新增表單），走 /api/v2；review+codex 過、全套 1729 綠。人物層級 proposal（create/delete）回 501（走 legacy crowdsourcing_status，非本次）。 |
| P4-1 | altname | BasicInformationAltnamesController | ALTNAME_DATA（3-key，無 c_sequence） | **done**（2026-06-22，flag old；React 編輯器 + 共用 CodeAutocomplete/csrf；review+codex 過、build✓、Altname 103 綠） |
| P4-2 | addresses | BasicInformationAddressesController | BIOG_ADDR_DATA | **done**（2026-06-22，flag old；React 編輯器；review+codex 過、build✓、Address 83 綠；農曆/c_natal 欄位暫略，部分 PATCH 不清空既有值） |
| P4-3 | texts | BasicInformationTextsController | BIOG_TEXT_DATA/TEXT_DATA | **done**（2026-06-22，flag old；React 編輯器；review+codex 過、build✓、124 綠） |
| P4-4 | sources | BasicInformationSourcesController | BIOG_SOURCE_DATA | **done**（2026-06-22，flag old；React 編輯器；c_pages 可空 PK + 布林；review+codex 過、build✓、Source 105/PersonBrowser 42 綠） |
| P4-5 | offices | BasicInformationOfficesController | POSTED_TO_OFFICE_DATA(+ADDR) | **done**（2026-06-22，flag old；React 編輯器；create 後端配發 c_posting_id、proposal 不提供；**c_office_id 編輯唯讀**＝避免 POSTED_TO_ADDR_DATA 孤兒[codex 抓出]；review+codex 過、build✓、Posting 72 綠） |
| P4-6 | assoc | BasicInformationAssocController | ASSOC_DATA（9-col key） | **done**（2026-06-22，flag old；React 編輯器；9-key+人物選擇；**修 update 哨兵漂移**：c_text_title/c_assoc_first_year 補 emptyToSentinel[review 抓出]+回歸測試；review+codex 過、build✓、Association 42 綠） |
| P4-7 | kinship | BasicInformationKinshipController | KIN_DATA | **done**（2026-06-22，flag old；React 編輯器；3-key+人物選擇；空 PK 送 -999；review+codex 過、build✓、Kinship 72 綠） |
| P4-8 | events | BasicInformationEventsController | EVENTS_DATA | **done**（2026-06-22，flag old；React 編輯器；**修既有欄名 bug**：c_event_type→c_event、c_event_addr→c_addr_id、c_range→c_yr_range、移除 c_supplement[handler+前端+service+測試表對齊真實 schema]；review+codex 過、build✓、Event 51 綠） |
| P4-9 | statuses | BasicInformationStatusesController | STATUS_DATA | **done**（2026-06-22，flag old；React 編輯器；欄名驗證乾淨；review+codex 過、build✓、Status 65 綠） |
| P4-10 | entries | BasicInformationEntriesController | ENTRY_DATA（10-col key） | **done**（2026-06-22，flag old；React 編輯器；**修既有欄名 bug**(c_entry_nh_code→c_nianhao_id、移除 c_supplement/c_secondary_source_title)；**修隱藏 PK 漂移**(c_inst_code/c_inst_name_code 編輯不送)[codex 抓出]+回歸測試；review+codex 過、build✓、Entry 54 綠） |
| P4-11 | possession | BasicInformationPossessionController | POSSESSION_DATA | **done**（2026-06-22，flag old；React 編輯器；create 後端配發 PK、地址副表不清空、proposal 不提供；**修既有假欄**(c_supplement/c_measure_value/c_firstyear/c_lastyear→真實欄)+修單位 clobber；review+codex 過、build✓、Possession 37 綠） |
| P4-12 | socialinst | BasicInformationSocialInstController | BIOG_INST_DATA | **done**（2026-06-22，flag old；React 編輯器；**修既有 7 個假欄**(c_supplement/c_bi_firstyear/c_bi_lastyear/c_bi_fy_*/c_bi_ly_*→真實 c_bi_begin_year/c_bi_end_year/c_bi_by_*/c_bi_ey_*)；review+codex 過、build✓、SocialInstitution 34 綠） |
| P4-P | basicinformation 提案流程 | BasicInformationProposalController | — | **done**（2026-06-22，flag old）：A=後端 proposal DELETE 提交+審核（OperationsProposalController 加 delete 分支、offices/possession 副表）；B=offices/possession proposal CREATE 提交+審核（applyOfficeProposal/新增 applyPossessionCreateProposal）；C=12 編輯器加提案模式 UI（User::canPropose()=isActive、canProposeEdits prop、proposalMode=!canEdit&&canPropose、comment）。皆過 review+codex。眾包用戶可於新編輯器送 create/update/delete 提案，後端 authorizeDirect/authorizeProposal 強制無權限升級。 |

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
| P5-10 | admin/cbdb-table-maintenance | `admin.cbdb-table-maintenance` | done | 新路由 `app.admin.cbdb-table-maintenance`（inertia）；buildTableStats 共用；同步/非同步重建 fetch+5s 輪詢；寫入/async 端點未改；spec `docs/migration-specs/cbdb-table-maintenance.md`；flag old |
| P5-11 | admin/unidirectional-relationship-repair | `admin.unidirectional-relationship-repair` | done | 新路由 `app.admin.unidirectional-relationship-repair`（inertia）；純表單頁；repairKinship/Assoc 寫入端點未改（fetch + X-XSRF-TOKEN）；spec `docs/migration-specs/unidirectional-relationship-repair.md`；flag old |
| P5-12 | maps/index | `app.maps.index` | retired/排除 | 獨立全螢幕 Leaflet 地圖應用（自有 historical-maps/app.js），非 AdminLTE dashboard 頁；已在 /app/maps、superadmin。包進 DashboardLayout 反而破壞全幅 UX → 列為 shell 遷移範圍外（例外，見附錄 D.1）。 |

## Phase 6 — 認證頁與入口（建議最後；依賴：F*）
| # | 頁面 | 路由（舊） | 狀態 | 備註 |
|---|---|---|---|---|
| P6-1 | auth/login | `Auth::routes()` | **done**（2026-06-22，flag auth.login old）：show* flag→Inertia 否則 Blade；POST/節流/guest 未動；review+codex 過 |
| P6-2 | auth/register | `Auth::routes()` | **done**（2026-06-22，flag auth.register old） |
| P6-3 | auth/passwords/email | `Auth::routes()` | **done**（2026-06-22，flag auth.passwords old，忘記密碼） |
| P6-4 | auth/passwords/reset | `Auth::routes()` | **done**（2026-06-22，flag auth.passwords old，重設密碼，token/email 透傳） |
| P6-5 | welcome | `/`（WelcomeController） | **done**（2026-06-22，flag welcome old，landing） |
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
