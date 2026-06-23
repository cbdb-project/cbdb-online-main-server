# React 人物編輯器「全面重做、對齊 legacy 編輯頁」規格 / Checklist

> 背景：先前 React 人物編輯器誤用 `app/person-browser`（唯讀瀏覽器）組件拼成，導致**大量頁面無法真正編輯**並丟失 legacy 功能（年號轉換、地圖、AI 工具…）。本系統是**編輯平台，編輯是第一位的功能**。對齊對象＝legacy 編輯頁 `/basicinformation/{id}/edit`、`/basicinformation/{id}/{子資源}`（`resources/views/biogmains/**`），**不是 person-browser**。
>
> 紀律：每頁重做須對照本 checklist 逐項補齊；過閘以「實機能真的編輯並保存、資料正確落庫」為準（DB 級實查），非「渲染出來」。慢、做對、不漏。人物編輯器目前全 `old`（legacy 完整在線），逐頁重做+過閘後才翻 `new`。

## 進度（更新於 2026-06-23）

過閘＝review agent 讀碼 → codex（`codex exec --dangerously-bypass-approvals-and-sandbox`）→ 無嚴重問題 → 提交。獨立測試路由 `…/edit-v2`，flag 全 `old`、未上線。

| 編輯器 | 狀態 | 備註 |
|---|---|---|
| basic-info / altname / addresses / texts | ✅ 已重做過閘 | EraTimeField/CodeAutocomplete/TextpersonPair 等共享機制已建 |
| socialinst / possession | ✅ 已重做過閘 | |
| events / entries / statuses(含 AI code-lookup) | ✅ 已重做過閘 | |
| sources | ✅ 已重做過閘 | 修正 c_textid=0 parity + delete round-trip |
| **offices ★AI** | ✅ 已重做過閘 | 31a 農曆白名單 / 31b 地址同步(抽 syncPostingAddresses+afterDirectUpdate 鉤子) / 31c UI(多地址+雙era+inst拆碼+saveas) / 31d AI 任官自動填 |
| **assoc ★AI + 雙向 mirror** | ✅ 全部完成過閘 | 後端互逆鏡像(create/update/delete+核准路徑)+era 白名單+編輯器UI+AI code-lookup 全部過閘提交。codex 多輪揪出 legacy 數據模型歧義全修。9段PK 編輯器、pair 由後端權威補齊、AI 識別 create/edit 皆顯示 |
| **kinship + 雙向 mirror** | ✅ 全部完成過閘(#33) | 後端互逆鏡像(create 33a6fb2 / update+delete+核准刪除 1811faf，抽 syncKinMirrorOnUpdate/OnDelete 共用法 legacy+v2 共享)+編輯器UI 6f38e88，皆 review→codex CLEAN。配對碼一律 KINSHIP_CODES.c_kin_pair1 權威推導不採信前端、c_autogen_notes 編輯不破壞配對；缺碼 fail-closed 回滾防孤兒(codex 揪出)。3段PK 編輯器、pair 後端補齊 |
| 統一版面對齊 + 人物詳情中樞 + 翻 flag | ⏳ 進行中(#34) | 見 D0。**已完成並過閘**：(a) i18n 中英切換修復（biogmains 納入共享 translations，ba1b08d）；(b) AI SSL 環境修復（C:\php\cacert.pem + php.ini curl.cainfo/openssl.cafile，非代碼）；(c) PersonBanner 人物頭+子資源導航（對齊 legacy banner）鋪到 13 個 editv2 頁（63f732b）；(d) 詳情中樞改用 PersonBanner、脫離 person-browser 的 BrowserTabs/PersonSummaryPanel（e86cc90）；(e) basic_info 接獨立 BasicInfoEditor（含年號轉換）；(f) 地址改 legacy place-link：地名虛線下劃連結開地圖（a47bb18）；(g) 編輯器卡片撐滿寬度 880→100%（5d0bec0）；(h) 12 子資源分頁卡片→legacy 表格樣式（SubresourceTable），補回 review/codex 揪出的 parity（StatusesTab 年份哨兵過濾、EventsTab 雙語名/i18n 欄頭、AltNames/Possessions 序號用 c_sequence、assoc/events/entries 次序欄、kinship/assoc 表頭，d15ed04）；(i) 親屬/社會關係人連結另開新分頁、地圖點地名以該座標居中、頁碼置中、身份標頭置中（tab 維持靠左）、person_records 誤譯修正「人物基本資料→人物記錄」、麵包屑末段隨分頁切換、表格密度對齊 table-sm（d15ed04 / 97db188）。**待續**：依 docs/REACT_MIGRATION_SIMULATION_TEST_PLAN.md 全面機器對比（含 basic_info 表單多欄版面 H1、各列卡片標題 M1 等以證據驅動補齊），通過後翻 flag |

可重用基礎設施（已建並驗證）：`afterDirectUpdate`/`afterDirectInsert`/`proposalAuxiliaryPayload` 鉤子（在 Abstract*Handler，空預設、對其他子資源 no-op）、legacy 邏輯抽取共用法（offices `syncPostingAddresses` 已示範，assoc/kinship 鏡像沿用）、`PostingAiAutofill` 面板、`CompositePrimaryKey::emptyToSentinel`。詳見 memory `v2-childtable-mirror-reuse-pattern`。

## 進度補充：#40 模擬測試結果（2026-06-23）

方法（依 docs/REACT_MIGRATION_SIMULATION_TEST_PLAN.md §6.0）：`compare-pages.mjs` 全量擷取 13 個編輯器頁的新舊證據
（基本資料 edit、12 子資源 create-mode：legacy `/basicinformation/1762/{sub}/create` vs 新 `/app/.../{sub}/edit-v2`），
再由 review agent 逐對判讀差異清單，分「真實缺口／可接受 delta／格式差異」三類。

**總結論：React 編輯器為忠實的欄位級移植**——各 spec 欄位皆存在、可直接編輯性與 legacy 一致；無欄位遺漏、無 editable→readonly 退化（下列邊界除外）。
大量 count 落差（mainText、option、labels−1）皆由**可接受 delta** 解釋：select2/select-vue→CodeAutocomplete（option 不再內嵌）、`人物基本資訊` in-form 塊→PersonBanner、
成對碼 select（assocship/kinship/assoc_kinship pair）改由後端權威同步而移除、農曆 1-12/1-30 提示→EraTimeField tooltip、0 計數徽章隱藏。AI 工具（offices 任官自動填、assoc/statuses code-lookup）、saveas、c_autogen_notes、Wiki 警告、checkbox 皆確認存在。

**已修並過閘（commit 9156451 + 本批）**：(a) 共享「查看本頁歷史記錄」連結（PersonBanner，依分頁，13 編輯器+中樞）；(b) 修改說明 textarea+提示對所有可提案者顯示；
(c) basic-info 3 個指數欄補 auto_calc 提示；(d) entries `arabic_numerals_hint`、assoc `assoc_count_hint`、offices `sequence_same_note` 補回可見提示文字。

**翻 flag 前待處理（gate-before-flip）**：
- ⛔ **副表編輯阻擋特定子資源上線**：events `c_addr_id`（EVENTS_ADDR）在新編輯器**唯讀（create+edit 皆是）**，legacy 可編輯；possession `c_addr_id[]` 多地址在 **edit 模式唯讀**（create 可）。→ 須先支援副表 mutation，否則 events/possession 維持 old。
- ⚠️ **edit 模式待覆核**（create 證據未涵蓋）：altnames `c_alt_name_chn/c_alt_name/c_notes` 的 unionPKDef 特殊字元 round-trip（須確認 v2 後端編解碼）；sources edit 模式 `c_textid/c_pages` 唯讀（PK 不可變、delete-and-recreate 設計）須人簽核為可接受。
- 可接受/cosmetic：label 的 `(c_*)` 後綴改名、possession 度量單位以 inline placeholder 取代獨立 label、offices AI 摘要少一行 empty_count。
- 尚未執行維度：I 字體載入、J 側欄/flag 導流、K 視覺 computed style、A–H 互動寫入（子資源 mutation 走 API，依 §三不做 UI 雙邊對比，必要時另以 API 測試）。

## A. 全局共享機制（先在 React 建一次，各頁複用——最易漏）

1. **`EraTimeField`（年號/時間複合控件）** ← legacy `components/inline-time-fields.blade.php`（已起 Phase 1，需完整覆蓋）：年份 input + **西元↔年號雙向轉換鈕**（`.era-convert-btn`/`.era-reverse-convert-btn`，邏輯見 `app.js` initEraConversion：cn-era、朝代過濾、多結果 dialog、按 c_str 年份範圍精確匹配 nianhao id、反向算西元、特殊映射）+ 年號 select + 年號年 + range select（可選）+ **農曆區塊（閏月 checkbox hidden0/checkbox1、月 1-12、日 1-30、日干支 select）**+ notes（可選）+ `initLunarValidation` 月日範圍校驗。各使用處子字段集不同（見各頁）。
2. **代碼表下拉** ← `<select-vue model=...>`：dynasty/ethnicity/choronym/household/nianhao/range/ganzhi/altcode/role/biogaddr/appttype/assumeoffice/officecate/topic/occasion/parentstatus/possact/measure/birole。用 accepted `CodeAutocomplete/EnumAutocompleteField`（聚焦展開全量、可選可搜）。
3. **遠程搜索下拉** ← `initAjaxSelect($el,'model')`：text(典籍)/addr(地名)/office/socialinstcode/biog(人物)/kincode/assoccode/event/entry/status/pinyin。`addr` 帶 `dy_start/dy_end`（朝代範圍過濾）、`office` 帶 `c_dy`。用 CodeAutocomplete search 模式。
4. **人物上下文（取代 `person-id-display` 隱藏字段）**：dynasty_code/start/end + person_id —— 驅動 addr 朝代過濾與 AI 朝代回填。重做以 props/context 傳入，**漏掉會斷 ajax 搜索/AI**。
5. **`audit-fields`**：建檔者/日期、更新者/日期（唯讀，僅編輯模式有值時顯示）。
6. **`textperson_pair` 出處候選聯動**（**12 子資源頁全有**）：進頁 `GET /api/select/search/textperson?q={person_id}` 預載；選項以 `&and&` 拆 textid/pages → `GET /api/select/search/text?q=textid` 取標題 → **自動回填 `c_source`+`c_pages`、黃底高亮、alert**。做成共享組件。
7. **三態授權提交**：`canWriteDirectly()`→「直接保存」(action=save)；active→「提交建議」(action=proposal)；非 active→只讀無提交鈕。+ `__proposal_comment` 修改說明 textarea（提案流程）。
8. **複合主鍵 query 模式 URL** + **unionPKDef 編解碼**（altname/sources/assoc 對含特殊字符欄位轉義/反轉義，見 `CompositePrimaryKey::buildUrl`）。

## B. 逐頁 checklist

### 基本資料 edit（`basicinformation/edit.blade.php`）
欄位：姓中 `c_surname_chn`/名中 `c_mingzi_chn`/Xing `c_surname`/Ming `c_mingzi`；異域姓名 `c_surname_proper`/`c_mingzi_proper`、羅馬化 `c_surname_rm`/`c_mingzi_rm`；**4 唯讀自動派生**（`c_name_chn`/`c_name`/`c_name_proper`/`c_name_rm`，附「自動生成」hint）；性別 `c_female`；族群 `c_ethnicity_code`；朝代 `c_dy`；**生年 `c_birthyear`**（EraTimeField 全套農曆 `c_by_*`，onchange indexYear）；**卒年 `c_deathyear`**（`c_dy_*`）；唯讀自動 `c_index_year/c_index_year_type_code/c_index_year_source_id/c_index_addr_id/c_index_addr_type_code`；享年 `c_death_age`+範圍 `c_death_age_range`；**始活動 `c_fl_earliest_year`**（EraTimeField 子集 `c_fl_ey_nh_code/nh_year`+notes `c_fl_ey_notes`，無農曆）；**末活動 `c_fl_latest_year`**（`c_fl_ly_*`+notes）；郡望 `c_choronym_code`；戶籍 `c_household_status_code`；備註 `c_notes`。
特殊（易漏）：**生成拼音鈕**（`/api/select/search/pinyin`，回填 c_surname/c_mingzi 高亮）；**indexYear 聯動**（享年=死-生+1、index_year）；**beforeunload 姓名校驗**（名中/拼音空則警告）；脏檢查禁用提交；刪除 confirm；Duplicate Collateral / Duplicate Basic(saveas)；提交 `PATCH /basicinformation/{id}`；history-button。create 為極簡獨立頁（`c_personid`+`c_name_chn`）。

### 異名 Altname：`c_sequence`(req)、`c_alt_name_chn`(req)、`c_alt_name`、類型 `c_alt_name_type_code`(altcode)、`c_source`(text)、`c_pages`、`c_notes`、textperson_pair。unionPKDef 編解碼；query 模式 URL。無年號/地圖/AI。
### 地址 Addresses：`c_sequence`(req)、類型 `c_addr_type`(biogaddr)、**地名 `c_addr_id`(addr，朝代過濾)**、**起年 `c_firstyear`**(EraTimeField 農曆 `c_fy_*`)、**末年 `c_lastyear`**(`c_ly_*`)、`c_source`、`c_pages`、`c_notes`、**祖籍 `c_natal`**(0否/1是)、textperson_pair。**地圖**：index 頁 `_chgis_map_assets` + `_place_link`（可點地名→浮出 modal）。path/query 模式 URL。
### 典籍 Texts：`c_textid`(text)、角色 `c_role_id`(role)、`c_source`、`c_pages`、`c_notes`、textperson_pair。無年號/地圖/AI。
### 出處 Sources：`c_textid`(text，req)、`c_pages`、**主要出處 `c_main_source`/自傳 `c_self_bio`**(checkbox hidden0/1)、`c_notes`。**Wiki 警告 alert**（textid∈{60795,68942,68943}）。unionPKDef。
### 官職 Offices ★AI：`c_sequence`、官職 `c_office_id`(office，帶 c_dy)、機構 `c_inst_code`(socialinstcode+隱藏 `c_inst_name_code`)、**地名 `c_addr[]`(multi-select+`c_addr_cleared`)**、`c_source`、`c_pages`、**起 `c_firstyear`**(農曆 `c_fy_*`)、**末 `c_lastyear`**(`c_ly_*`)、任命 `c_appt_code`、就任 `c_assume_office_code`、類別 `c_office_category_id`、`c_notes`、朝代 `c_dy`、textperson_pair。**AI 填充**（新增+Gemini+active）：原文→`POST ai.posting.extract`→matched/suggested 回填（select2/vue 區別、addr multi、not-found Search hint、結果摘要 card、`ai_fill_log_id`、隱私通知）。另存為。**地圖**：offices/index `_chgis_map_assets`+place_link。
### 社會關係 Assoc ★AI：（最多欄）`c_sequence`；親屬 `c_kin_code`(kincode)+`c_kin_id`(biog)；關聯人 `c_assoc_code`(assoccode)+`c_assoc_id`(biog)；關聯親屬 `c_assoc_kin_code`+`c_assoc_kin_id`；**始 `c_assoc_first_year`**(農曆 `c_assoc_fy_*`)、**末 `c_assoc_last_year`**(`c_assoc_ly_*`)；`c_notes`；主題 `c_topic_code`；場合 `c_occasion_code`；作品 `c_text_title`(默認[n/a])；數量 `c_assoc_count`(默認1)；中介 `c_tertiary_personid`(biog)+`c_tertiary_type_notes`；見證 `c_assoc_claimer_id`(biog)；地點 `c_addr_id`(addr)；機構 `c_inst_code`+`c_inst_name_code`；`c_source`/`c_pages`；textperson_pair。**三組成對聯動**：`c_assocship_pair`(/assocpair)、`c_kinship_pair`(/kinpair)、`c_assoc_kinship_pair`(/kinpair)。**AI 代碼識別**：`POST ai.code-lookup.suggest` table=ASSOC_CODES→候選按鈕(relevance 上色+成對提示)→applyAssocCode 回填+觸發 assocship_pair。unionPKDef。
### 親屬 Kinship：`c_kin_code`(kincode)、`c_kin_id`(biog)、`c_source`、`c_pages`、`c_notes`、**`c_autogen_notes`**、**成對 `c_kinship_pair`**(/kinpair 聯動)、textperson_pair。⚠️見 v2 互逆鏡像 bug（須移植鏡像事務才可上線）。
### 身份 Statuses ★AI：`c_sequence`、身份 `c_status_code`(status)、**`c_supplement`**、**始 `c_firstyear`**(EraTimeField 無農曆 `c_fy_nh_code/nh_year/range`)、**末 `c_lastyear`**(`c_ly_*`)、`c_source`/`c_pages`/`c_notes`、textperson_pair。**AI 代碼識別**：ai.code-lookup.suggest table=STATUS_CODES→applyStatusCode。
### 入仕 Entries：`c_sequence`(req)、方式 `c_entry_code`(entry)、**入仕年 `c_year`**(EraTimeField 無農曆 `c_entry_nh_id/nh_year/range`)、`c_exam_rank`、`c_attempt_count`、`c_exam_field`、父輩 `c_parental_status_code`(parentstatus)、地點 `c_entry_addr_id`(addr)、`c_age`、`c_posting_notes`、親屬 `c_kin_code`+`c_kin_id`、關係 `c_assoc_code`+`c_assoc_id`、機構 `c_inst_code`+`c_inst_name_code`、`c_source`/`c_pages`/`c_notes`、textperson_pair。10 段超長複合主鍵。
### 占有 Possession：`c_sequence`、行為 `c_possession_act_code`(possact)、`c_possession_desc`、`c_possession_desc_chn`、`c_quantity`+單位 `c_measure_code`(measure)、**年份 `c_possession_yr`**(EraTimeField 無農曆 `c_possession_nh_code/nh_yr/yr_range`)、**地名 `c_addr_id[]`(multi addr)**、`c_source`/`c_pages`/`c_notes`、textperson_pair。query 模式（pk=`c_possession_record_id`）。
### 社會機構 SocialInst：`c_inst_code`(socialinstcode+`c_inst_name_code`)、角色 `c_bi_role_code`(birole)、**始 `c_bi_begin_year`**(EraTimeField 無農曆 `c_bi_by_*`)、**末 `c_bi_end_year`**(`c_bi_ey_*`)、`c_source`/`c_pages`/`c_notes`、textperson_pair。

## C. 最易漏清單（每頁過閘前核對）
1. textperson_pair（全子資源）2. 年號雙向轉換鈕 + 農曆校驗 3. person 上下文隱藏字段（驅動 addr 過濾/AI）4. AI 兩端點（offices=posting.extract；assoc/statuses=code-lookup.suggest，table 不同）5. multi-select 地址（offices/possession，offices 有清空標記）6. 複合主鍵 query 模式 + unionPKDef 7. 三態授權提交 + __proposal_comment 8. CHGIS 地圖（addresses/offices index）9. 各頁 required/默認值差異 10. 零散特有（拼音生成/indexYear/beforeunload/Duplicate×2/Wiki 警告/另存為/c_autogen_notes/補充文字）。

## D0. 版面（layout）—— 使用者決定：功能全做完後再統一調版面（2026-06-22）
- 目前各編輯器是「功能正確、版面未對齊 legacy」狀態（使用者已知、可接受）。
- **不在每個編輯器逐頁調版面**；待 12 個編輯器功能/欄位/資料正確性全部做完並過閘後，做**一個統一的「版面對齊 legacy」pass**：建共享表單版面組件，一次性把所有頁的區塊劃分/欄位順序/分組/間距/label 位置/hints 呈現拉齊到 legacy 編輯頁。
- 為此，各編輯器重建時**結構保持一致**（欄位分組、區塊命名比照 legacy `版面分區`），便於最後統一套版面、減少返工。

## D. 重做計畫
1. **共享基礎組件**（A 節全部）建好並各自驗證。
2. **逐頁重建**（B 節），每頁對照 checklist + C 節核對。
3. AI 工具（offices/assoc/statuses）、地圖（addresses/offices）。
4. 每頁過閘＝對比 legacy 編輯頁無遺漏 + **實機編輯保存 DB 級驗證** + review→codex → 才翻 new。
5. kinship/assoc 另需先移植 v2 互逆鏡像事務（見 [memory] v2-mutation-no-mirror-bug）。
