# 異體字對照表 Work Plan（第三階段）：按欄位型別全面套用落地替換

> **前置文件**：[第一階段（schema）](./CHAR_VARIANT_MAP_CONSOLIDATION_PLAN.md)、[第二階段（3 個呼叫點）](./CHAR_VARIANT_MAP_CALL_SITE_WIRING_PLAN.md)。
> 本階段完成第二階段「不在本次範圍內」列出的待辦：把落地替換擴張成**所有文本型別欄位的通用機制**，並留下防止日後再漏的常規。
>
> **文件結構約定**：「決策」只寫**規則**；所有要做的事都在「實作步驟」。同一件事不重複兩處。

## 背景與現況

第一階段的目標宣告是把 `TITLE_VARIANT_MAP` 擴充成「所有表的錄入端都能查詢的通用對照」。第二階段採**逐點手掛**，因此目前全庫只有這些生產呼叫：

| 呼叫點 | 模式 | 位置 |
|---|---|---|
| BIOG_MAIN 姓名（v2 update／proposal） | strict | `BiogMainMutationHandler.php:218-224` |
| BIOG_MAIN 姓名（create／update，v2 與 legacy 共用同一 repository 方法） | strict | `BiogMainRepository.php:253-254`、`:379-386` |
| ALTNAME_DATA 別名（v2 create／update） | strict | `AltnameCreateHandler.php:87`、`AltnameMutationHandler.php:90` |
| 書名批次匯入 | lenient | `AdminBatchLoadBookTitlesController.php:616` |

（另有兩處掛在 legacy Blade 專屬路徑上，隨 legacy 頁面淘汰，本階段不維護、不擴充。）

### 缺口盤點

| # | 缺口 | 影響 |
|---|---|---|
| G1 | Codes UI 的 5 條寫入路徑零替換（`config/codes.php` 註冊 82 張表，扣掉唯讀與實體聚合封寫後約 76 張可由 UI 寫入；D2 的「已知表」聯集是 83 張，含 UI 未註冊但有 PK schema 的表） | `ADDR_CODES.c_name_chn`、`APPOINTMENT_CODES.c_appt_desc_chn`、`KINSHIP_CODES.c_kinrel_chn`、`EVENT_CODES.c_event_name_chn` 等數十個中文欄原樣入庫 |
| G2 | 21 個人物子資源 v2 handler 只有 Altname 的 2 個掛了 | `ASSOC_DATA.c_text_title`（PK 成員）、`BIOG_SOURCE_DATA.c_pages`（PK 成員）、`EVENTS_DATA.c_event`／`c_role`、`POSSESSION_DATA.c_possession_desc_chn`、`ENTRY_DATA.c_exam_rank`／`c_exam_field`、`STATUS_DATA.c_supplement`、全表通用的 `c_notes`／`c_pages` |
| G3 | 實體聚合（官職／社會機構）零替換 | `OFFICE_CODES.c_office_chn`；`SOCIAL_INSTITUTION_NAME_CODES.c_inst_name_hz` 更嚴重——它同時是**去重鍵** |
| G4 | token API 代碼表 create 零替換 | `TEXT_CODES.c_title_chn` 走 `CodeTableCreateHandler` 不替換、走書名批次匯入會替換——同欄位兩路徑行為不一致（既有 bug） |
| G5 | 提案核准的直接寫庫分支原樣回寫 payload | 代碼表走 `applyCreateProposal()`／`applyUpdateProposal()`；KIN_DATA／ASSOC_DATA 走 `applyKinshipProposal()`／`applyAssocProposal()` |
| G6 | 眾包核准回填不替換 | `CrowdsourcingController::confirm()` 繞過 repository 與 handler |
| G7 | v1 `api/operations/*` 提案端點零替換 | token 認證、仍在服役；v2 的人物 create 提案回 501，**沒有替代品** |
| G8 | 人物複製工具零替換，且未被任何閘門攔 | `BasicInformationController::saveas()`（`:1963`／`:1981`）、`Duplicate_Collateral_Info()`（`:2006`，寫 8 張表）；`routes/web.php:161-162` 未掛 `legacy.form` |
| G9 | 單向關係修復工具零替換 | `UnidirectionalRelationshipRepairController::executeRepair()`（insert `:190`） |

## 決策（規則）

### D1：以**欄位型別**判定範圍

文本型別（`varchar`／`char`／`tinytext`／`text`／`mediumtext`／`longtext`）一律過機制；其餘不過。**不預判該欄有沒有中文**——對照表 key 全是 CJK，套在拼音／羅馬字欄上必然 no-op。型別是 schema 的權威事實、自動跟上演進，不像命名規律（`_chn`／`_hz`）會漏掉 8 個無後綴中文欄（`EVENTS_DATA.c_event`／`c_role`、`ENTRY_DATA.c_exam_rank`／`c_exam_field`、`STATUS_DATA.c_supplement`、`ASSOC_DATA.c_text_title`、`TEXT_INSTANCE_DATA.c_publisher`／`c_pub_loc`）。

已用 `docs/DATABASE_SCHEMA.md` 驗證：全庫型別只有 `varchar`(369)／`smallint`(343)／`int`(106)／`longtext`(40)／`datetime`(40)／`timestamp`(16)／`double`(8)／`bigint`(8)／`text`(7)／`tinyint`(4)／`varbinary`(2)／`char`(1)，無 `enum`／`set`／`json`／`blob`。`type_name` 兩個 driver 都已歸一為小寫（`MySqlGrammar.php:146`、`SQLiteProcessor.php:35`）。

⚠️ **但不能只靠 `DATABASE_SCHEMA.md`**：該文件自己就漏了 `CHAR_VARIANT_MAP`／`KINREL_REDUCTION`／`TEXT_DATA` 三張表。上面的型別統計因此是**參考值**，真正兜底的是 S1 的漂移守衛。

### D2：範圍解析 fail-closed，比對大小寫不敏感

1. 表名／欄位名比對**一律大小寫不敏感**。
2. **未知表不替換（fail closed）**。「已知」= `config/codes.php['tables']` ∪ `CompositePrimaryKey::SCHEMAS` ∪ `config/code_table_writes.php['tables']` ∪ `config/code_table_mutations.php['tables']`。框架表、紀錄表、客戶端亂傳的字串自然落在未知而跳過。
3. **絕不用未驗證的外部輸入當 registry 鍵**（v1 端點的 `resource` 必須先過已知表判定）。

**已查證 fail-closed 不會靜默廢掉本計畫**：G1–G9 所有目標表都在聯集內（實測 83 張），包括容易漏想的 `OFFICE_CODE_TYPE_REL`／`OFFICE_TYPE_TREE`／`SOCIAL_INSTITUTION_*`／`EVENTS_ADDR`／`POSSESSION_ADDR`／`POSTED_TO_ADDR_DATA`／`MERGED_PERSON_DATA`，以及 G8 `Duplicate_Collateral_Info()` 寫的那 8 張。**但這是現況巧合**——新增 CBDB 表若忘了登記進四份 registry 任一，fail-closed 就會靜默跳過它，所以 S1 必須補漂移守衛。

理由：查表若寫成字面精確比對會 fail-open 且不對稱地壞——排除清單漏中 ⇒ 對照表自我吞噬；strict 清單漏中 ⇒ 靜默降級成 lenient ⇒ 人名裡的「峯」被改寫。這不是假想：`codes.php` 用小寫 `char_variant_map`／`pinyin`，CBDB 表用全大寫，而 `Api\OperationsController.php:68` 的 `resource` 是客戶端任意字串。

### D3：排除清單

**一般性原則**：**本身就是做文本替換／字形對照用途的地方，以及語義上必須保留原字的地方，一律不掛。** 判斷新表／新欄問兩題：(a) 內容「就是字形本身」嗎？(b) 存在目的是「忠實保留當時的原字」嗎？任一為是 ⇒ 排除。

| 排除對象 | 理由 |
|---|---|
| **一切對照／映射表**（`char_variant_map`、`pinyin.c_chn`） | 內容就是字形本身，替換等於自我吞噬。`pinyin.c_chn` 另有第一階段明文設計「異體字各自有讀音」。**寫成一般性規則**——已下架的 `CBDB__TRAD_SIMP_MAP` 當年不被誤動純粹因為型別是 `varbinary`；日後若有人建 `varchar` 的對照表就會落入範圍 |
| `CBDB__NAME_FTS.*` | 唯讀派生索引，**刻意同時保存繁簡兩形**供檢索命中 |
| **跨表 join／樹狀關聯的「代碼鍵」**（清單見下） | 判準是「這個值是用來跟別表對上的代碼」，**不是**「是不是 varchar PK 成員」。`ALTNAME_DATA.c_alt_name_chn`／`ASSOC_DATA.c_text_title`／`BIOG_SOURCE_DATA.c_pages` 同樣是 varchar PK 成員，但是**內容**、必須替換 |
| BIOG_MAIN 9 個拉丁人名欄＋`ALTNAME_DATA` 4 個（共 13 欄） | 見 D4 |
| 稽核欄 `c_created_by`／`c_created_date`／`c_modified_by`／`c_modified_date`／`created_at`／`updated_at` | 依 `AGENTS.md` §1.2 由系統蓋章。這是**署名**不是內容。（`OperationsProposalController::AUDIT_COLUMNS`（`:474`）只列前 4 個，本清單是其超集） |
| URL 欄 `c_url_api`／`c_url_api_coda`／`c_url_homepage` | 識別碼／位址而非文句 |
| 派生表 `ADDRESSES.*` 與 DB view（`View_BiogInstData`、`View_PossessionsData`） | 由源頭派生重建；改派生物只會與源頭不一致。（`Schema::getColumns()` 會回傳 view，故需明文排除） |
| `operations.resource_id`／`resource`／`resource_data`／`resource_original` | `resource_id` 是序列化複合主鍵、內含中文 PK 成員，改寫會讓提案與目標列脫鉤。**更根本的規則：掛鉤一律以「目標表」為 `$table`，永不以 `operations` 呼叫**；此列僅縱深防禦 |
| 紀錄／帳號表 `users`／`nl_query_logs`／`ai_fill_logs`／`audit_log` | 紀錄的語義是「當時實際發生了什麼」，改寫等於偽造紀錄。（D2 fail-closed 已涵蓋，明文列出以固定意圖） |

**已查證的代碼鍵清單（直接進排除常數，不必等 S0）**：
- `BIOG_MAIN.c_index_year_type_code`（varchar(255) → `INDEXYEAR_TYPE_CODES` varchar(191) PK）
- `ENTRY_CODE_TYPE_REL.c_entry_type` ↔ `ENTRY_TYPES.c_entry_type`（有真 FK，且被**前綴階層比對** `where('c_entry_type','like',$id.'%')`——`Api/ApiController.php:409`／`:413`、`EntryTypeTree.tsx`；替換會打斷 LIKE 樹走訪）
- `KINSHIP_CODES.c_kinrel`／`c_kinrel_simplified`（join **來源側**：`ApiController4:119`、`4_1:208`、`4_2:235`、`4_2:406-407`）
- `KIN_MOURNING.c_kinrel`、`KIN_MOURNING_STEPS.c_kinrel`（上者的**對面側**——只排一邊就是「只改 join 一邊會打斷關聯」）
- `KINREL_REDUCTION.c_kinrel_target`／`c_kinrel_replacement`（須與 `KINSHIP_CODES.c_kinrel` 對齊）、`c_sex`（varchar(1) PK 成員，值 M／F／B，`CompositePrimaryKey.php:195-196`）
- `OFFICE_TYPE_TREE.c_office_type_node_id`／`c_parent_id`（樹狀關聯）
- 各 `*_TYPE_REL`／`*_TYPES` 家族的 varchar 代碼 PK
- **varchar 自參照樹狀父鍵（非 PK、schema 未宣告 FK）**——已掃過全庫 migration，共 **5 個**：
  `ENTRY_TYPES.c_entry_type_parent_id`（`import_cbdb_schema.php:889`，指向同表 `c_entry_type`；`EntryTypeTree.tsx:32-38` 以精確 array-key 建樹）、
  `ASSOC_TYPES.c_assoc_type_parent_id`（`:367`）、
  `TEXT_BIBLCAT_TYPES.c_text_cat_type_parent_id`（`:2024`）、
  `TEXT_TYPE.c_text_type_parent_id`（`:2165`）、
  **`STATUS_TYPES.c_status_type_parent_code`（`:1936`，指向同表 PK `c_status_type_code` `:1933`）——注意它的後綴是 `_parent_code` 而非 `_parent_id`，只掃 `*_parent_id` 會漏掉**。
  **這一類是 S0 三個結構式條件都抓不到的**：不是 PK、schema 沒有宣告 FK，而比對關係散在前端（`ENTRY_TYPES` 在 `resources/js/`）與後端（`ASSOC_TYPES`／`TEXT_BIBLCAT_TYPES` 在 PHP）**兩邊都有**，不能只掃一邊。若其中出現可替換字，通用 Codes UI 會只改父鍵或節點鍵之一而**直接斷樹**

以上現值多為 ASCII、今天替換是 no-op，但那是**靠內容安全、不是靠設計安全**——沒有機制阻止有人填中文，而只改 join 一邊會直接打斷關聯／樹。S0 是**補充掃描**（找這份清單之外的），不是從零開始。

**⚠️ 不是排除項但需成對處理**：`DYNASTIES.c_dynasty_chn`、`SOCIAL_INSTITUTION_TYPES.c_inst_type_hz`／`c_inst_type_py`、`NIAN_HAO.c_nianhao_chn` 語義上是內容、**照常替換（不進排除常數）**，但同時是「標籤→代碼」的精確比對鍵，處理方式見 **S4**。誤加進排除常數會讓代碼表側永不正規化，等於把問題凍結。

**載體**：`app/Support/VariantReplaceScope.php` 常數 + 測試斷言清單與理由註解同步。不放 config——這些排除是**程式正確性前提**，不應可由部署設定關掉。

### D4：預設 lenient（全量），strict 是明文例外

| 欄位 | 模式 |
|---|---|
| **預設：所有文本欄** | **lenient（全量 7 筆）** |
| `BIOG_MAIN.c_name_chn`／`c_surname_chn`／`c_mingzi_chn` | strict（6 筆，排除「峯→峰」） |
| `ALTNAME_DATA.c_alt_name_chn` | strict |
| `BIOG_MAIN` 的 `c_surname`／`c_mingzi`／`c_name`／`c_surname_proper`／`c_mingzi_proper`／`c_name_proper`／`c_surname_rm`／`c_mingzi_rm`／`c_name_rm`，`ALTNAME_DATA` 的 `c_alt_name`／`c_alt_name_pinyin`／`_pinyin2`／`_pinyin3` | **排除**（共 13 欄，使用者確認） |

- `modeFor()` 的**預設回傳是 `'lenient'`**；只有命中 strict 清單才 `'strict'`，命中排除／未知表／非文本欄才 `null`。**不得**寫成「查不到就 strict」或「整張 BIOG_MAIN／ALTNAME_DATA 都 strict」。
- strict／lenient 是**逐欄位**：同一列 BIOG_MAIN 裡姓名欄 strict、`c_notes` lenient；ALTNAME_DATA 裡 `c_alt_name_chn` strict、`c_notes` lenient。
- **13 個拉丁人名欄「排除」而非 strict**，兩個依據：(1) strict 仍會套那 6 筆規則，真有人填漢字時 愼→慎／靑→青 照樣被改；只有排除能真正不碰。(2) 排除順帶消掉一個組合欄失步——`BiogMainRepository::updateById()` 的 `c_name`／`c_name_proper`／`c_name_rm` 是從 **`$request`** 重組的（`:260`／`:264`／`:265`），若分欄在 `$data` 被替換而組合欄從未替換的 `$request` 組出，就破壞 `:250-252` 註解保護的 invariant。排除 ⇒ 無替換 ⇒ 無失步。
- （語義補充）羅馬字轉寫的用途就是保留錄入者寫的拼法；中文源頭欄已歸一，轉寫欄不需要、也不該被字形正規化改寫。
- ⚠️ **不要引用 `docs/PINYIN_SAVE_NORMALIZE_DESIGN.md` 當本決定的依據**：那份文件管的是 ü/v 拼音歸一化、對異體字替換**沒有管轄權**，而且它的表格把 `c_surname`／`c_mingzi`（`:37`）與 `c_name`（`:38`、`:50`）明列為 **Tier 1（要轉）**。曾有一輪誤引它當「既有規則早就這樣定了」，此警示是為了避免再犯。
- **排除不造成中文／拼音失步，三條路徑理由不同**（`auto_pinyin()` 全庫**只在** `store():392` 被呼叫）：create 由 `auto_pinyin()` 在替換後從 `c_name_chn` 全權重算；update／proposal **從不重新派生拼音**（`:260` 由 `$request` 組、`:225-227` 由 `$payload` 組），拼音欄純屬使用者輸入，一致性責任本來就在使用者，排除＝維持現況。

### D5：備註／自由文字欄一律替換（使用者決定）

`c_notes`／`c_pages`／`c_supplement`／`c_tertiary_type_notes`／`c_posting_notes`／`c_autogen_notes` 全部 lenient。第二階段曾提「逐字抄錄史料原貌」的保留意見，使用者明確決定**全部替換，含備註欄**。（`c_self_bio` 不是文字欄——只存在於 `BIOG_SOURCE_DATA`、型別 `smallint(6)`，按型別本來就不在範圍。）

### D6：不做既有資料的批次回溯校正（使用者決定）

只處理「往後新增／修改時」。**這個決定有連鎖後果，見 D7**——既有列保留變體字形，而新寫入歸一到參考字，任何**精確比對**都會踩到。

### D7：既有資料的「兩形並存」必須在所有身分／去重比對上處理

D6 之下，同一個概念會同時以變體形（既有列）與參考形（新列）存在。任何拿文本欄做**精確比對**的地方都必須處理，否則替換會**製造**新的分裂：

- **去重／重用查詢**（`resolveNameCode()`、標籤→代碼 map）：必須**兩形都探**（或把 map 鍵在記憶體內正規化）。只替換傳入值會讓查詢**錯過**它本來會命中的既有列，於是鑄出第二個碼——**比不替換更糟**。
- **PK 改名**（`ALTNAME_DATA.c_alt_name_chn`／`ASSOC_DATA.c_text_title`／`BIOG_SOURCE_DATA.c_pages`）：編輯既有變體形列時替換等於**改名**，這是想要的「觸碰即歸一」。既有 PK 衝突偵測會處理撞號，但必須確認回**乾淨的 409 而非 500**。
- **鏡像對面列**：見 S3。
- **歷史快照／序列化 PK 當定位器（第四類，最容易寫出 bug）**：凡是拿**舊值**去定位既有列的地方，都**不可**替換那個定位器：
  - `applyUpdateProposal()` 用 `buildKeyConditions($keyColumns, $original)`（`:778`）定位既有列。**`$data` 要替換、`$original` 絕對不可替換**——既有列在 D6 下永遠是變體形，替換定位器會落空、拋「資料不存在或已被刪除」，而 `:792` 的改鍵碰撞偵測根本跑不到。
  - **PK 被歸一後，該列所有既有 `operations.resource_id`（序列化複合主鍵）會與目標列脫鉤**：`OperationRepository` 的 `where('resource_id', …)`（`:83`／`:104`／`:125`）、`restore()` 的 `buildKeyConditions`、提案列表的現況比對都會找不到列。D3 只涵蓋「不可改寫 `resource_id`」，**反方向（PK 被改寫導致 resource_id 陳舊）是本階段的已知後果**，需在 S3 測試涵蓋並在 PR 說明。
  - 客戶端／React 編輯器手上的 `target.pk` 在一次歸一化 update 之後即失效（PK 重同步是既有的已知陷阱），S3 要驗證前端有重同步。

### D8：幂等由「載入時傳遞閉包」保證

`c_variant_char` 有唯一鍵（migration `:18`）⇒ 圖是 out-degree ≤ 1 的 functional graph，不會分叉，閉包唯一確定；閉包後 key 集 ∩ value 集 = ∅ ⇒ 重複套用是不動點。三條配套規則：

1. **兩欄必須是單一 codepoint**（`mb_strlen() === 1`）。幂等論證只在單字元 key 下成立：`甲乙→丙丁` + `丁→戊`，閉包接不起來（exact match），第一次得 `丙丁`、第二次得 `丙戊`。非 BMP 不會誤擋（mbstring 算 1）。**此決定取代第一階段 `:49`／`:53` 那句「varchar(10) 為變體選擇符留餘裕」**——在實作出替代不變式（「value 集不得含任何 key 為子字串」）之前不收錄組合字符；欄位長度不變，只是多一道驗證。
2. **先按模式過濾、再各自算閉包與環**，且 `$lenientMap`／`$strictMap` 維持兩份獨立快取。**今天的實作已滿足**（`strictMap():153` 在 SQL 層 `where(...):156`），這條是**擋住「共用 loader 載入全表算一次閉包、strict 再按 flag 過濾」那個誘人重構**的護欄——別去找現存的 bug，沒有。若做了該重構：`X→峯`(0)+`峯→峰`(1) ⇒ 全表閉包得 `X→峰`，其 flag 為 0 於是留在 strict map ⇒ strict 透過傳遞把 strict-excluded 的邊套進人名欄，廢掉 `c_strict_excluded` 的唯一用途。
3. **執行順序必須是「按模式過濾 → 偵測並移除環上出邊 → 對剩餘無環圖算閉包」**。反過來（先算閉包再偵環）**不可實作**：對 `A→B`+`B→A` 或自環 `A→A`，閉包在環移除前沒有定義，一般的走鏈寫法會**無限迴圈**。
4. **環的處置：只丟棄構成該環的邊 + 記 error log，其餘照常**。兩個 map 方法是所有替換的唯一入口，在此 throw 會讓 Codes UI 80 表、所有 v2 mutate、三支批次匯入、眾包核准、提案核准**一起爆**（一個 `峰→峯` 或打錯字的 `A→A` 就夠）；回空 map 則等於全站靜默不替換。定位精確：functional graph 下「環上節點」＝「從自己出發能走回自己」，逐 key 走鏈 + visited set 即可，`A→A` 自然當長度 1 的環丟掉；改動侷限在兩個 map 方法，**不需動 `replaceUsing()`**。**「鏈進入環」（`A→B`、`B→C`、`C→B`）只丟環上節點（B、C）的出邊，`A→B` 要保留**。

### D9：搜尋路徑完全沒有異體字歸一化（必須明講）

D6 之下，新資料是「清」、舊資料是「淸」，而**搜尋路徑上沒有任何異體字歸一化** ⇒ **使用者用任一形都搜不到另一形**。落差本來就在，但本階段會顯著放大它。

**成因要指對**：`VariantCharNormalizer` 的 6 個呼叫點（`AdminBatchLoadBookTitlesController:586`／`:649`、`ApiController:651`、`BiogMainRepository:4101`／`:4132`／`:4144`）做的**全是拼音派生**，不是查詢端歸一化；`NameSearchService`／`NameSearchIndexService`／`PersonBrowserService`／`PinyinSearchNormalizer` **零引用**它；`CBDB__NAME_FTS.is_simplified` 只覆蓋繁簡（OpenCC），不覆蓋異體字。**真正的槓桿在姓名搜尋／FTS 建索引**。
（量化依據：`VariantCharNormalizer::$fallbackMap` 是硬編的 7 字 菴攷嶽愼註于槀，與 `char_variant_map` 的變體集**僅 2 筆交集**（愼、槀）——就算把它接上對照表也解決不了搜尋落差，因為搜尋根本不經過它。）

本階段**不動**搜尋路徑（牽動姓名搜尋與 FTS 重建，風險性質不同），但 S9 必須把後果寫進 PR／CHANGELOG，S8a 必須把「新增對照要評估搜尋端（**不是**改 `VariantCharNormalizer`）」寫進 AGENTS.md，並列為下一階段候選。

## 實作設計

### `app/Support/VariantReplaceScope.php`

```php
final class VariantReplaceScope {
    public const TEXT_TYPES = ['varchar','char','tinytext','text','mediumtext','longtext'];
    public const STRICT_COLUMNS = [
        'BIOG_MAIN' => ['c_name_chn','c_surname_chn','c_mingzi_chn'],
        'ALTNAME_DATA' => ['c_alt_name_chn'],
    ];
    // 排除清單見 D3（整表／逐欄／任何表都排除三組常數）

    /** @return 'strict'|'lenient'|null null = 不替換（排除／未知表／非文本欄） */
    public static function modeFor(string $table, string $column): ?string;
    public static function textColumns(string $table): array;   // 按表快取
    public static function isKnownDataTable(string $table): bool;
    public static function reset(): void;                        // 必須在 TestCase::setUp() 呼叫
}
```

**registry 抽取的形狀陷阱**：`codes.php['tables']` 與 `code_table_writes.php['tables']` 是**以表名為鍵的 map**（`array_keys()`）；`code_table_mutations.php['tables']` 是**list of maps、表名在 `'table'` 值**（`array_column($t,'table')`）。對第三份誤用 `array_keys()` 會得到 `"0".."13"` 並漏掉 14 張真表——今天恰好被掩蓋（那 14 張也都在 `codes.php`），**純屬巧合**。實測聯集 = **83 張表**（僅在大小寫不敏感下成立；敏感會算成 84，差的是 `CHAR_VARIANT_MAP` vs `char_variant_map`）。

**型別探測與快取**：`Schema::getColumns($table)` 的 `type_name`。注意 `getColumns()` 在本專案是**全新用法**（全庫只用過 `getColumnListing()`／`getColumnType()`），但 `type_name` 只有它才有，Laravel 12 兩個 driver 都支援。**必須按表快取**（批次匯入逐列迴圈，否則 N 次 metadata 查詢）。

### `CharVariantMapService` 新增方法

```php
/** 整列替換：逐欄查 modeFor()。非字串值（int/null/陣列）原樣跳過——刻意的淺層掃描，
 *  POSTED_TO_ADDR_DATA 的 resource_data['rows'] 與 __proposal_aux 這類嵌套/非欄位鍵不該被當欄位處理。 */
public static function replaceRow(array $data, string $table): array;   // {data, replaced}

/** 單值替換。掛鉤點手上常常不是「以欄位名為鍵的整列」——OfficeImportService 在 buildPinyin() 之前
 *  拿到的 $input 鍵是 name/name_alt/notes（欄位名只在 officeColumns() 的 return、:113-124 才出現），
 *  resolveNameCode(string $name) 更是裸字串。對這些位置呼叫 replaceRow() 會靜默 no-op。 */
public static function replaceFor(string $table, string $column, string $value): array;  // {text, replaced}

/** char_variant_map 專用結構驗證：單 codepoint、不成環。
 *  $excludeId：更新／還原既有列時要排除該列的舊邊，否則會誤報環——
 *  表有 id=5 `乙→甲`、id=9 `甲→丙`，把 id=5 改成 `丙→乙` 是合法的 `甲→丙→乙`，
 *  但把舊邊算進去會看到 `乙→甲→丙→乙`。
 *  $row 可能是部分 payload（restoreUpdate 用歷史快照），需與現有列 merge 後再驗。 */
public static function assertWritable(array $row, ?int $excludeId = null): void;
```

### 通知通道與 i18n

| 介面 | 通道 |
|---|---|
| Codes UI（Blade + React 共用 `perform*`） | `flash($msg,'info')` |
| v2 JSON API | 頂層可選 `notices`，用既有 `withNotices()` |
| 批次匯入 | 逐列 `variant_replacements`，比照書名匯入 |
| 眾包回填、提案核准、v1 端點、複製工具 | 無通知（無互動使用者在場，記錄在 operations／audit 可查） |

`buildNotices()` 目前硬編繁中，**S1 一併改走 `__()`** + 同步兩份翻譯檔（依 `AGENTS.md` §6）——它即將出現在 82 張代碼表與所有 v2 回應。

**422／409 的錯誤回應也必須帶 notices**：替換在 `hasEffectiveChanges()` 之前、且替換後的值才用於查重，所以使用者會遇到「只把『淸』改成庫裡已有的『清』→ `422 no_effective_changes`」與「輸入自認不同的標題、替換後撞既有列 → `409`」。不帶說明的話使用者無從得知系統改了字。

## 實作步驟

> 每步完成後：review agent 檢查到沒有嚴重 issue → `codex exec --dangerously-bypass-approvals-and-sandbox`（PowerShell + `Write-Output "..." |` 管道傳 prompt）檢查到沒有嚴重 issue → commit、PR、rebase merge 保持線性 git log → 才進下一步。

### S0：varchar 代碼鍵**補充**掃描（S2／S3 的前置條件）

D3 已內嵌一份已查證的代碼鍵清單，直接進排除常數。本步是**找那份清單之外的**：對聯集內 83 張表的每一個 varchar／char 欄，凡 (a) 是 PK 成員、(b) 任一側有宣告的 FK、或 (c) 在應用碼裡被 exact 或 prefix（`LIKE 'x%'`）比對到別表，就逐一判定「代碼鍵 vs 內容」。**命名式判準不足**（`c_index_year_type_code` 與 `c_entry_type` 都不符命名規律卻是代碼鍵），所以判準必須是結構式的。

⚠️ **但結構式判準有一個已知盲區**：varchar 自參照樹狀父鍵**不是 PK、schema 未宣告 FK**，且比對關係散在前端與後端兩邊（見 D3 清單最後一項），三個條件全部落空。**而且命名不統一**——有 `*_parent_id` 也有 `*_parent_code`（`STATUS_TYPES`），所以按後綴掃也會漏。
本步的額外掃描要用**語義判準而非命名**：**「這個 varchar 欄的值是否對應同表的文字型 code PK」**；並同時掃 `app/` 與 `resources/js/` 對代碼表欄位做 array-key／`===` 比對的地方。（D3 那 5 個已是全庫 migration 掃描的結果，本步是確認沒有第 6 個。）

注意 (a) 單獨不足以判定排除——`ALTNAME_DATA.c_alt_name_chn`／`ASSOC_DATA.c_text_title`／`BIOG_SOURCE_DATA.c_pages` 都是 PK 成員但屬內容、必須替換。三個條件只是**篩出候選**。

**另跑一次 prod schema 欄位比對**（不是 migrations）：型別導向的範圍會靜默納入 **prod-only 文本欄**，而 `TEXT_TYPES` 守衛與已知表守衛都看不到它們（D2 的 caveat 只涵蓋 prod-only 的**表**）。已知 prod-only：`ALTNAME_DATA.c_alt_name_pinyin`／`_pinyin2`／`_pinyin3`（已排除）與 `c_alt_name_role`（待歸類；只在合成測試表出現，`string(...,50)`，所以上面那個讀 live schema 的結構式掃描**碰不到它**）。

### S1：基礎設施

- `VariantReplaceScope`、`replaceRow()`、`replaceFor()`、`assertWritable()`（見實作設計）。
- 對照表載入改為**先按模式過濾 → 偵測並移除環上出邊（記 error log，不拋錯、不回空 map）→ 對剩餘無環圖算傳遞閉包**。順序不可顛倒（見 D8 第 3 點：先算閉包會在環上無限迴圈）。
- `buildNotices()` 改 `__()` + 兩份翻譯檔。
- **在 `OperationsController::restore()` 掛 `assertWritable()`**：`char_variant_map` 有 10 個寫入入口，restore 是**唯一不在 S2／S5／S6 編輯範圍內**的那條（`restoreUpdate():1196` `update($payload)`、`restoreDelete():1227` `updateOrInsert`／`:1229` `insert`；該表明文登記在 `resourceKeyColumns():1505`）。superadmin 還原一筆對照就能重新引入環／多字元 key、繞過其餘 9 個 guard。**這與「restore 不做內容替換」不衝突**：那是不對 restore 的內容做落地替換，這裡是對這張表的寫入做結構驗證。`restore()` 已有 try/catch → `flash(...,'error')`（`:1109-1140`），拋錯會乾淨降級。
- 在 `TestCase::setUp()` 加 `VariantReplaceScope::reset()`（與既有 `CharVariantMapService::reset()` 並列）。**必須做**：測試自建簡化合成表，同一表名在不同檔案有不同欄位集（`ApiV2CreateBiogMainTest.php:146-151` vs `BiogMainProposalTest.php:59-60`），PHPUnit 單一行程 ⇒ 前一檔案暖起來的型別快取對下一檔案是錯的，結果會依檔案順序漂移。
- **guard 與 reset 的入口數不同，別混用**：
  - `assertWritable()` 掛 **10 條**（所有能寫 `char_variant_map` 的應用層路徑，含 3 條只寫 `operations` 的提案路徑——在那裡擋是提早拒絕）。掛載分工：**本步只做 restore**，其餘由 S2（Codes UI 5）、S5（token API 2）、S6（提案核准 2）各自完成。
  - `CharVariantMapService::reset()` 只需 **7 條真正落庫點**：Codes UI 2（`performStore()` insert `:1772`／`performUpdate()` update `:1355`）+ token API 2（`CodeTableCreateHandler:101`、`ConfigCodeTableMutationHandler`）+ 提案核准 2 + restore 1。三條提案路徑不改對照表，reset 沒有意義。
  - migration 兩者都繞過，靠資料不變式測試兜底。
- **不能用 Eloquent observer**：`app/Models/` 下沒有 `char_variant_map` 的 model，每條寫入都是 `DB::table()`；observer 攔不到，要攔就得把刻意 table-agnostic 的泛用 CRUD 為這張表特例化。單 codepoint 那一半**可選配**用 DB CHECK 做成真落庫級（連 migration 都涵蓋），但 SQLite **不支援對既有表加約束**（需整表重建）且函式名不同（`CHAR_LENGTH` vs `length`，依 `AGENTS.md` §1 要 `is_mysql()`／`is_sqlite()` 分支）——列為選配硬化，非必須。
- 測試（`tests/Unit/VariantReplaceScopeTest.php`、`CharVariantMapServiceRowTest.php`）：
  - 型別判定：varchar／char／text／longtext 是；integer／smallint／datetime／varbinary 不是（部分型別全庫不存在，用合成表）。
  - **`TEXT_TYPES` 漂移守衛**——⚠️ 天真實作在 SQLite 上抓不到目標：Laravel 把 `->enum()` 編成 `varchar`+check、`->json()` 編成 `text`，守衛全綠而 MariaDB 端真的逃出範圍。**三者擇二**：(a) driver-aware 並在 CI 對 MariaDB 跑；(b) 掃 `database/migrations` 出現 `->enum(`／`->json(`／`->binary(` 即紅；(c) fail-closed——observed `type_name` 必須全在明文分類表內，未分類即紅。
  - **已知表 registry 漂移守衛**（與上者對稱）：live schema 每張表要嘛在聯集內、要嘛在明文的「非 CBDB 資料表」清單內。邊界要註明：(i) 測試 schema 來自 migrations，抓的是「新 migration 忘了登記」，**抓不到 prod-only 表**；(ii) `Schema::getTables()` 在 MySQL 某些版本含 view、SQLite 另有 `getViews()`，driver 不對稱要明確處置。
  - **預設方向**（擋「預設寫成 strict」）：未登記的文本欄（如 `EVENT_CODES.c_event_name_chn`）→ lenient，「峯」→「峰」。
  - `BIOG_MAIN.c_surname_chn` → strict（「峯」不變）；**同一列** `c_notes` → lenient（「峯」變）。`ALTNAME_DATA.c_alt_name_chn` → strict 但同表 `c_notes` → lenient（驗證逐欄位、非逐表）。
  - **登記完整性**：strict 4 欄與排除 13 欄**逐欄逐一**斷言（抽驗擋不住漏登）。
  - 大小寫不敏感：`biog_main.C_SURNAME_CHN` 仍 strict。fail-closed：未知表回 null。
  - 排除逐筆：`char_variant_map.c_variant_char`、`pinyin.c_chn`、`c_modified_by`、`c_url_homepage`、`operations.resource_id`。
  - 幂等：套兩次 == 套一次，**且在對照表被人為插入成鏈時仍成立**。
  - 環：`A→B`+`B→A` → 丟這兩條、其餘生效、記 log、不無限迴圈；`A→A` 同樣；**`A→B`+`B→C`+`C→B` 時 `A→B` 必須保留**。
  - **鏈跨越 excluded 邊界**：`X→峯`(0)+`峯→峰`(1) ⇒ lenient 對 `X` 得「峰」、strict 對 `X` 得「峯」、strict 對「峯」不動。
  - 單 codepoint 驗證擋下多字元；現有 7 筆種子與既有 fixture 全為單一 codepoint（已查證，不會弄紅既有測試）。
  - **資料不變式：現有 7 筆無鏈無環**——變體集 `愼槀峯靑頴淸厰` 與參考集 `慎稿峰青穎清廠` 無交集（這是 D8 幂等論證的資料側前提，也是 migration 繞過 guard 時的唯一兜底）。
  - **四份 registry 抽取形狀**：斷言各自抽出的表名數量與內容符合預期（擋「對 `code_table_mutations` 誤用 `array_keys()`」那個今天恰好被掩蓋的陷阱）。
  - `assertWritable()` 的 `$excludeId`：上面 `乙→甲`／`甲→丙`／改成 `丙→乙` 的合法案例不得誤報。
  - 哨兵值不變式：`[n/a]`／`-9999`／`<待删除>` 與 `c_variant_char` 集合無交集（今天成立是資料巧合）。
  - 淺層掃描：嵌套陣列值原樣保留。

> **S1 已完成的實作約定（S2–S7 必讀）**：`replaced` 的值在衝突時**會是 list** ——
> 同一個變體在 strict 欄與 lenient 欄的閉包終點可以不同（`龴→峯`(excluded=0) +
> `峯→峰`(excluded=1)：strict 得「峯」、lenient 得「峰」）。因此：
> - **只能經 `CharVariantMapService::buildNotices()`／`withNotices()`／`flattenReplaced()` 消費**，
>   **不可直接 `foreach` 取值或 `implode`**（會拋 "Array to string conversion"，或把陣列
>   JSON 化進前端 payload 而打壞契約）。
> - 合併兩份 `replaced` 一律用 `CharVariantMapService::mergeReplaced()`，
>   **不可用 `+=` 或 `array_merge`**（兩者都會靜默丟掉一個參考字，讓通知與實際落庫的字形不一致）。
> - 需要結構化 payload（例如批次匯入結果頁的 `variant_replacements`）時用 `flattenReplaced()`。

### S2：Codes UI 全表串接（G1）

5 條路徑在現有 `normalizeCodeTablePinyin()` 呼叫的**下一行**插入 `replaceRow($data, $table)`：`:1766`（`performStore`）、`:1349`（`performUpdate`）、`:1512`（`performProposalStore`）、`:1843`（`performProposalUpdate`）、`:1624`（`proposalUpdateExisting`）。

- direct 兩條：`replaced` 非空時把 `buildNotices()` 的每則訊息 `flash(...,'info')`（**不要自己組字**，見上方 S1 約定）；它們之後才呼叫 `applyColumnDefaultsForBlanks()`（`:1769`／`:1352`），**三條提案路徑不呼叫它**，只需在記 operation 之前。
- 提案三條：替換後的值進 `operations.resource_data`（`$table` 傳**目標表**，不是 `operations`）。
- 同時涵蓋 Blade 與 React（共用 `perform*`）。
- **`char_variant_map` 的 guard**：`performStore()`／`performUpdate()` 落庫前呼叫 `CharVariantMapService::assertWritable($data, $id)`（違反回 flash error 且不寫入），成功後呼叫 `CharVariantMapService::reset()`。三條提案路徑寫的是 `operations`、不改對照表，**不需要** reset，但仍建議在提案建立時先跑 `assertWritable()` 提早拒絕。
- 測試：`ADDR_CODES.c_name_chn` 含「淸」→ 落庫「清」+ flash；拼音欄拉丁字串 no-op；數字欄不變；`char_variant_map` 自身 `c_notes` 含「淸」**不**被替換；`pinyin` 表新增「峯」讀音 → `c_chn` 保持「峯」；提案 `resource_data` 是替換後值且 `resource_id` 未被改寫。

### S3：人物子資源 v2 handler 全面串接（G2）

在兩個抽象基底類別插入通用掛鉤，21 個子類自動生效：
- `AbstractPersonSubresourceCreateHandler`：`:121` 呼叫 `preprocessCreateData()` **之前**。`:124` 才 `extractPkFromRow()`、`:127` 才 `findExistingRow()`，所以 PK 成員替換後的值自然成為新 PK 且查重看到替換後值。（白名單過濾 `:112`、`validateFields` `:115` 都早於掛鉤點；已逐一確認 24 個 `preprocess*` 覆寫沒有任何一個從其他欄位反推中文欄或還原 PK 成員。）
- `AbstractPersonSubresourceMutationHandler`：`:137` 呼叫 `preprocessUpdateData()` **之前**（早於 `:141 hasEffectiveChanges()` 與 `:227 buildNewPk()`）。
- 三個體系外例外各自補掛、同樣在 PK 計算／查重之前：`PossessionCreateHandler`、`PostingCreateHandler`、`SourceMutationHandler`（後者寫的 `BIOG_SOURCE_DATA` PK 第三欄就是文本欄 `c_pages`）。
- **`replaced` 必須由通用掛鉤收集並放到基底類別的 protected 屬性**，子類改讀它，並**移除**子類重複的 `replaceStrict()` 呼叫（保留 `BracketNormalizer`／`PinyinUmlaut` 順序）。否則通用掛鉤先跑、值已正規化，`AltnameCreateHandler:82-89` 的 `replaced` 恆為 `[]`，**別名替換通知靜默消失**。既有屬性在 `AltnameCreateHandler:23`／`AltnameMutationHandler:24`，使用點 `:104`／`:111` 與 `:137`／`:144`；沒有其他子類自己呼叫 `CharVariantMapService`。**基底必須用 merge 而非 assign**，否則日後子類覆寫會靜默吃掉通知（正是本問題的失效模式）。補「別名通知不消失」回歸斷言。
- **BIOG_MAIN 也不在基底體系內，必須單獨處理**：`BiogMainCreateHandler:26`／`BiogMainMutationHandler:23` 都 `extends AbstractMutationHandler`；實寫在 `BiogMainRepository::store()`／`updateById()`，只手掛了三個姓名欄（`:253-254`、`:379-386`），`c_notes`／`c_tribe`／`c_fl_ey_notes`／`c_fl_ly_notes` 全漏；且 `BIOG_MAIN` 在 `HANDLER_ROUTED_RESOURCES`（`:57-68`，11 筆）、提案核准重放同一條，S6 也蓋不到。
  - 三處手掛改用 `replaceRow($data,'BIOG_MAIN')`：`store()`、`updateById()`、**以及 `BiogMainMutationHandler::prepareProposalPayload()`（`:214`，`replaceStrict` 在 `:218-219`）**——後者不經 repository，只改前兩處會讓非姓名文本欄在**提案 payload 裡不被替換**，使用者在審核畫面看到的字形與核准後落庫的不一致，S6 宣稱的雙保險對它不成立。
  - **`store():378-389` 的分支必須原樣保留**：只有在 `c_surname_chn`／`c_mingzi_chn` 之一**以 key 形式存在**時才由分欄重組 `c_name_chn`（`:378` 是 `array_key_exists(...) || array_key_exists(...)`），否則走 `:385-388` 直接替換 `c_name_chn` 本身。`:370-377` 有長註解、`ApiV2CreateBiogMainTest.php:374`（`testDirectBiogMainCreateWithOnlyNameChnDoesNotClearItWhenPartsAreAbsent`）就是只送 `c_name_chn` 的案例。天真地「先 `replaceRow()` 再無條件相加」會把 `c_name_chn` 抹成空字串——第二階段 review 已抓過一次的資料損毀 bug。組字要讀替換後的 `$data`／`$payload`，不可讀 `$surnameReplaced['text']` 或 `$request`。
  - **回傳 key 名不一致，四個消費者都要改**：`store():414` 回 `'replaced'`、`updateById():340` 回 `'variant_replaced'`；消費者 `BiogMainCreateHandler:158`、`BiogMainMutationHandler:134`、`BasicInformationController:1761`、`BasicInformationController:1938`。統一命名時漏一處就是靜默掉通知或 undefined key。
- **`ASSOC_DATA.c_text_title` 替換會改寫對面鏡像列的 PK 成員**：`AssociationMutationHandler::afterDirectUpdate():124` 把 `$targetPk['c_text_title']`（**替換前**的 URL pk）當定位器傳給 `BiogMainRepository::syncAssocMirrorOnUpdate():2650`，該查詢用舊值定位、而 `$dataMirror` 帶**替換後**的標題去 `update()`。**所以定位不會落空、#66／#70 不會誤觸發，鏡像會收斂**——但有兩個後果要處理：(a) 那個 update 改的是**對面那個人**的列的 PK 成員，**該側沒有 PK 衝突檢查**，若參考形的鏡像列已存在會撞唯一鍵而冒成 **500 而非乾淨的 409**；(b) 這是對既有資料的回溯改寫，與 D6 精神有張力，明文記錄為「觸碰即歸一」的刻意例外。（定位器見 `RelationshipMirrorService::locateOppositeEdges():135`／`reverseRelationExists():193`（`c_text_title` 條件在 `:212`）與 `BasicInformationProposalController:622`；**不存在 `mirrorExists()` 這個方法**。）補回歸測試涵蓋 (a)。
- v2 回應以 `withNotices()` 帶 `notices`；422／409 也要帶。
- 測試（`tests/Feature/ApiV2MutateVariantReplacementTest.php`）：`ASSOC_DATA.c_text_title`（PK 成員 + 鏡像撞號回 409）、**`BIOG_SOURCE_DATA.c_pages`**（PK 第三欄）、`EVENTS_DATA.c_event`、`POSSESSION_DATA.c_possession_desc_chn`、`ENTRY_DATA.c_exam_rank`、`STATUS_DATA.c_supplement`、各表 `c_notes`；BIOG_MAIN 姓名 strict 而同列 `c_notes`／`c_tribe` lenient（與上面 `replaceRow` 的決定是同一個原子變更）；**編輯既有變體形列時的 D7「PK 改名」行為**。

### S4：實體聚合（官職／社會機構）串接（G3）

**這一步的所有精確比對都要按 D7 處理兩形並存。**

- `OfficeImportService::officeColumns()`（`app/Services/Import/OfficeImportService.php:100`）：對 `c_office_chn`／`c_office_chn_alt`／`c_notes`／`c_pages` 用 **`replaceFor()`**（不是 `replaceRow()`——此處 `$input` 鍵不是欄位名），**必須在 `buildPinyin()`（`:106`、`:109`）之前**。已查證 `buildPinyin()` 從中文逐字派生（`Concerns/SharesImportHelpers.php:41-57`），且 `pinyin.c_chn` 被排除、異體字保有自己讀音 ⇒ 先替換才拿到參考字的讀音，正是想要的。
- **`SocialInstituteImportService::resolveNameCode()`（`:166`，`where('c_inst_name_hz',$name)` `:170`）必須兩形都探**。⚠️ **只替換傳入值會製造新的分裂**：既有列字面是「淸…」在 D6 之下永不改寫，把匯入值正規化成「清…」會讓精確比對**錯過它本來會命中的那一列**，於是鑄出**第二個** name code——比不替換更糟。做法：以替換前後兩個值查一次（`whereIn`），命中既有列就複用其碼；都沒有才新建（用參考形）。既有實作已是 `->orderBy('c_inst_name_code')->lockForUpdate()->first()`（`:170-173`），**沿用該排序即「最小碼優先」**，兩形都在時的選擇是確定的——不要自行換排序。呼叫端 `create():244`／`update():313`。
  **由此產生一個刻意的不對稱，要明文記錄**：兩形都在時複用既有變體形列，該列的 `c_inst_name_hz` 仍是「淸…」、**永不歸一**，與 D7 第二類「PK 改名＝觸碰即歸一」的語義相反。這是為了不製造重複碼而接受的取捨。
- **「標籤→代碼」精確比對（D3 標註的三張表）**，兩件事都要做：
  1. **建 map 時就把 map 的鍵在記憶體內正規化**（與 S2、與 D6 無關）。⚠️ 前一版計畫說「S2 會把代碼表那側正規化，所以只替換傳入標籤就夠」是**錯的**：D6 之下既有 `DYNASTIES` 列若字面是「淸」則永遠是「淸」，而使用者標籤「清」本來就是參考字、替換後不變 ⇒ 照樣落空。
  2. **查表前替換傳入標籤**。兩者合起來才讓「表格寫淸／代碼表寫清」與「表格寫清／代碼表寫淸」兩個方向都命中。
  - **鍵碰撞必須保留所有 value，且呼叫端契約要一起定**：這些 map 的**值同時是代碼白名單**（`ResolvesOfficeAggregateInput.php:77`、`SocialInstitutionAggregateDefinition.php:89`／`:92` 的 `in_array((int)$code, $map, true)`）。若正規化讓兩列的鍵塌成一個，被丟掉那個 `c_dy`／`c_inst_type_code` 會從 map 消失，**一個完全合法的代碼開始被判 invalid**。
    ⚠️ **但不能只改成 `label => code[]` 就了事**——那會打壞 6 個期待純量的呼叫端：`ResolvesOfficeAggregateInput:62`、`SocialInstitutionAggregateDefinition:69`／`:79`（`$map[$label]` 期待純量）、`:77`／`:89`／`:92`（`in_array` 期待扁平純量 value）、以及批次匯入 `AdminBatchLoadOfficesController:91`／`AdminBatchLoadSocialInstitutesController:96-97`（無檢查純量存取）。
    **明訂契約**：map 維持 `label => code`（單一純量），碰撞時**確定性取最小碼**並記 warning；另建 `allCodes(): int[]` 扁平集，把 `in_array(...)` 那三處改讀它。這樣呼叫端形狀不變、白名單不再遺漏合法碼。（注意今天 `mapWithKeys` + `orderBy` asc 是 last-wins ＝**最大**者，與「取最小」相反，所以要顯式處理、不能靠既有順序。）
  - **map builder 有五個、lookup site 有五個，全部都要改**（前一版只列三處、且宣稱「一步同時修好 React 編輯器」是**假的**）：
    - builder：`SharesImportHelpers.php:24`（trait `dynastyMap()`——`OfficeImportService:33` 與 `SocialInstituteImportService:43` **都 `use` 這個 trait**，所以改這一處同時覆蓋兩個 ImportService 與 office 聚合，是最有效的收斂點；該 service **沒有**自己的 `dynastyMap()`）、`AdminBatchLoadOfficesController:206`（`getDynastyMap()`）、`AdminBatchLoadSocialInstitutesController:250`（`getDynastyMap()`）／`:220-243`（`getTypeMap()`）、`SocialInstituteImportService::typeMap():52`
    - lookup（批次匯入）：parse 階段 `AdminBatchLoadSocialInstitutesController:201-202`／`AdminBatchLoadOfficesController:187` 是**正確的單一插入點**——標籤在此寫進 `$rows[]`，而 `validateLookups()`／`validateDynasties()`（`:272`／`:276`、`:226`）在交易前對同一批 row 值跑，所以在這裡正規化就能讓 `:96-97`／`:91` 的**無檢查陣列存取**安全；只在驗證階段正規化會讓那裡拋 "Undefined array key" 而非乾淨的逐列錯誤
    - lookup（**v2／React 路徑，前一版完全漏掉**）：`ResolvesOfficeAggregateInput.php:62`（`$dynastyMap[$label]`）、`SocialInstitutionAggregateDefinition.php:69`（`$typeMap[$label]`）／`:79`（`$dynastyMap[$label]`）。這三處在 parse 階段之外，S6 的 `approveEntityAggregateProposal` 重放也走它們
    - `PostingAutofillService.php:1454`／`:1463`（`where('c_nianhao_chn',$name)`）同類，一併處理
  - **搜尋範圍（供判斷是否窮舉）**：已掃過 `app/` 全部 `pluck('c_x','c_*chn|hz|py')` 與 `where('c_*chn|hz|_name|_title', $var)` 兩種形狀；office type／appointment type／addr 名稱都用**數字代碼**解析、不受影響。注意這兩種 grep **對陣列索引式查表是盲的**（v2 那三處就是這樣漏掉的），所以新增 lookup 時不能只靠 grep。
- 批次匯入結果頁補 `variant_replacements`，比照書名匯入。
- 測試：`AdminBatchLoadOfficesTest`／`AdminBatchLoadSocialInstitutesTest` 補——(a) 表格寫「淸」、代碼表「清」→ 成功；(b) 表格寫「清」、代碼表「淸」→ 成功（前一版會漏的方向）；(c) 代碼表同時有兩形 → 有 warning 且**兩個代碼都仍可用**；(d) **既有 name code 是「淸…」、匯入「淸…」→ 複用既有碼、不新建，且該列名稱文本保持「淸…」原形**（鎖住上面那個刻意的不對稱）；(e) 兩列分別寫「淸」「清」的同一機構名 → 收斂到同一個碼。

### S5：token API 代碼表 create／update（G4）

- `CodeTableCreateHandler`：目前無前處理掛鉤點，在 `:86 array_intersect_key` 之後、`:101` 落庫之前插入（抽出 protected 掛鉤方法）。`:99` 的 `ToolsRepository::timestamp()` 蓋稽核欄，掛在其前即可（稽核欄本來也在排除清單）。
- `ConfigCodeTableMutationHandler::preprocessUpdateData()`（`:97-101`）：在既有 `PinyinUmlaut::normalizeFields()` 之後加。
- **`char_variant_map` 的 guard**：兩個 handler 落庫前呼叫 `assertWritable($row, $id)`（違反回 422），成功後 `CharVariantMapService::reset()`。
- 修掉 G4 的不一致：`TEXT_CODES.c_title_chn` 兩路徑對齊。
- 測試：create 含「淸」的 `c_title_chn` → 落庫「清」，且與書名批次匯入同輸入同結果；`char_variant_map` 自身不被替換；單 codepoint 驗證回 422。

### S6：提案核准的直接寫庫分支（G5）

- **主分支先說清**：`applyProposal():405` 把 `HANDLER_ROUTED_RESOURCES` 內的資源導到 `applyViaMutationHandler():483` 以 v2 direct handler 重放，所以**大多數人物子資源提案由 S3 的基底掛鉤自動覆蓋**，走不到下面兩條。
- `applyCreateProposal():744`／`applyUpdateProposal():772`（服務代碼表與尚未遷移的表）：**掛鉤點在方法最上方、`buildKeyConditions()` 之前**，不是「insert／update 之前」。`applyCreateProposal()` 的重複檢查在 `:752`，早於 `:757` 的 insert；寫成「落庫前」會重演 §1.3 禁止的錯位（查重用替換前值、落庫用替換後值）。`applyUpdateProposal()` 的 `$conditions` 同理（update `:798`）。今天實際影響小（該分支的文本型 PK 成員都在排除清單），但**措辭會被複製**。
- `applyKinshipProposal():597`／`applyAssocProposal():637`：不重放 handler、直接呼叫 `BiogMainRepository` 的 kinship／assoc 寫入方法，S3 覆蓋不到，必須獨立補。部分 repository 方法只是薄轉發，真正寫入在 `OfficePostingRepository.php`／`EventStatusRepository.php`，掛鉤要放在真正落庫那層。
- 實體聚合提案核准（`:226 approveEntityAggregateProposal()` → `:273` 以 direct 重放，注入的正是 S4 那兩個 ImportService）由 S4 覆蓋，本步只需驗證——**含 S4 新增的三個 v2 lookup site**。
- 雙保險：提案建立端（S2／S3／S5）已替換，核准端再替換一次，依 D8 幂等。與 S2 不衝突：S2 讓存進 payload 的已是替換後值，本步是對歷史遺留 payload 補網。
- **`char_variant_map` 的 guard**：`applyCreateProposal()`／`applyUpdateProposal()` 落庫前呼叫 `assertWritable()`，成功後 `CharVariantMapService::reset()`——該表**不在** `HANDLER_ROUTED_RESOURCES`，所以它的提案核准就是走這兩條。
- **`OperationsController::restore()` 不做內容替換**（S1 只在它掛 `assertWritable()` 結構驗證，兩者不同）。本步測試含「restore 後歷史字形原樣保留」的負向斷言，鎖住這個刻意的不對稱。

### S7：眾包回填、v1 端點、複製與修復工具（G6–G9）

- `CrowdsourcingController::confirm():202` 落庫前套 `replaceRow()`，寫入點：`BiogMain::create` `:238`、`OfficeCode::create` `:250`、`OfficeCodeTypeRel::create` `:263`、`OfficeTypeTree::create` `:273`（`c_office_type_desc_chn` 是中文欄）、`$biog->update($data)` **五處**（`:293`／`:303`／`:313`／`:322`／`:340`）。
- `Api\OperationsController`（v1 token API，仍在服役）：`add_operations():58`／`update_operations():92`。`$keyword['json']` 是**原始 JSON 字串**，目標表來自客戶端任意的 `resource`（`add` 在 `:68`、`update` 在 `:102`，皆無白名單）。做法：decode → `replaceRow()` → 以 `JSON_UNESCAPED_UNICODE` re-encode；**必須先過 `isKnownDataTable()`**，未知 resource 原樣存入。另兩點：(a) `json_decode` 失敗要 **fallback 原樣存入**（維持現況「原樣存、到 `confirm()` 才爆」），不可變成 `"null"` 或當場拋錯；(b) `resource_original`（`:144`／`:205`，歷史快照）**不替換**。要接受 re-encode 改變既存位元內容（鍵序／escaping）並在測試固定預期形狀。**`storeProcess():215` 不要動**——全庫零引用、未掛路由的死碼（附記：它完全沒有 token／權限檢查就 `BiogMain::create()`，既有問題，不在本階段修）。
- `BasicInformationController::saveas():1963`（`BiogMain::create` `:1981`）／`Duplicate_Collateral_Info():2006`（依序寫 BIOG_MAIN `:2027`、BIOG_ADDR_DATA、BIOG_SOURCE_DATA、KIN_DATA ×2、ASSOC_DATA ×2、BIOG_INST_DATA、STATUS_DATA 共 8 張）：`routes/web.php:161-162` **未掛 `legacy.form`**、不受 flag 影響、現在就是活的且無 React 替代品。文字是複製既有列而非新錄入，但既然要複製就該複製成正規化後的字形。
- `UnidirectionalRelationshipRepairController::executeRepair()`（insert `:190`）：建鏡像列時逐字複製 `c_text_title`／`c_notes`，落庫前套。
- 測試：v1 端點提交含「淸」的人物提案 → payload 已替換；經 `confirm()` 回填後 `BIOG_MAIN` 為「清」；另補「提案 payload 未替換（模擬歷史資料）→ 回填時仍替換」鎖住雙保險。

### S8：把「新增文本錄入必須掛落地替換」寫成常規

**硬性關卡，不得因時間壓力跳過。** 本階段價值有一半在「以後不會再漏」——第二階段之所以漏掉 19 個 handler，正是因為當時沒有任何機制會在漏掉時發出聲音。

**8a. `AGENTS.md` 新增 §1.3**（與 §1.1／§1.2 並列為資料完整性規則）：
- 任何**會把文本寫進資料庫**的新路徑，落庫前必須經過 `CharVariantMapService::replaceRow($data,$table)`（或單值的 `replaceFor()`）。
- 範圍由型別決定，呼叫端**不需**自己判斷「有沒有中文」、不要自己維護欄位清單。
- **掛鉤位置硬性要求**：必須在 **PK 計算、查重、去重鍵查詢、拼音派生之前**。已知文本型 PK 成員：`ALTNAME_DATA.c_alt_name_chn`、`ASSOC_DATA.c_text_title`、`BIOG_SOURCE_DATA.c_pages`；已知去重鍵：`SOCIAL_INSTITUTION_NAME_CODES.c_inst_name_hz`；已知標籤→代碼鍵：`DYNASTIES.c_dynasty_chn`、`SOCIAL_INSTITUTION_TYPES.c_inst_type_hz`／`_py`、`NIAN_HAO.c_nianhao_chn`；已知由中文派生：`OFFICE_CODES`／`TEXT_CODES` 拼音欄。
- **精確比對必須處理兩形並存**（D7）：既有列保留變體形、新列是參考形，所以去重／重用查詢要**兩形都探**，否則替換會**製造**新分裂。
- `$table` 一律傳目標資料表，永不傳 `operations`。
- **模式不需呼叫端選，且預設是寬鬆（全量）**；strict 是逐欄位例外，**不要假設「人物相關的表就該用 strict」**。
- **繼承既有基底類別即自動生效**；不繼承的新寫入路徑才需自己掛。
- **不該替換的東西**：指向 `VariantReplaceScope` 排除清單與其原則——本身做文本替換／字形對照，或語義上必須保留原字的地方一律不掛。**新增任何對照／映射性質的表、或任何需保留原始字形的欄位時必須加進排除清單。**
- **「不要再改舊 Blade」的例外**：資料完整性規則（§1.1／§1.2／§1.3）適用於所有**仍在服役**的寫入路徑，不分新舊；只有已被閘門下架且有替代品的路徑才豁免。否則下一個代理會把 S7 的改動判為違規。
- **新增對照時要評估搜尋端**（D9）：該字的新舊資料會互相搜不到，需評估**姓名搜尋／`CBDB__NAME_FTS` 建索引**。**不要去改 `VariantCharNormalizer`**——它做的是拼音派生。
- 「提交前最低檢查」補一行：新增或修改文本寫入路徑時，確認已掛上落地替換。

**8b. Skill**：`.claude/skills/mutation-api-record-editing.md` 補實作步驟（掛哪個 hook、`replaced` 怎麼接 `buildNotices()`／`withNotices()`、「子類自己再呼叫一次會讓通知消失」的陷阱、測試該斷言什麼含「同列 strict／lenient 混用」）。`.claude/skills/database-schema.md` 補交叉引用：新增文本欄自動進入範圍；新增對照／映射表必須加排除。

**8c. 機械化把關**：`tests/Unit/VariantReplaceHookCoverageTest.php` 列舉 `app/Services/Mutations/` 下所有寫 CBDB 表的 handler，斷言每個都**繼承已掛鉤的基底類別**或**明文列在例外清冊**（每筆寫理由）。新增繞過基底的 handler 時這支測試會紅。

### S9：文件與收尾

- 更新本文件的完成狀態與實作偏差。更新第二階段文件「不在本次範圍內」指向本文件。
- **`API.md` 必須同步**：`notices` 適用範圍從 BIOG_MAIN／ALTNAME 擴到所有子資源與代碼表，依 `AGENTS.md`「文檔維護原則」屬必須同步的 API 改動；`docs/openapi/openapi.yaml` 一併更新。
- `CHANGELOG.md` 記一則（行為擴張，非修 bug），**含 D9 的搜尋落差後果**。
- 跑全量 `./vendor/bin/phpunit`。

## 風險

- **大範圍新行為，不是重構**。經上述路徑寫入的文本欄，含這 7 個異體字者都會被靜默改寫（人名／別名 6 筆、其餘 7 筆）。PR 描述必須標註為行為擴張。
- **D6 + 精確比對 = 可能製造新分裂**（D7）。最尖銳的是 `resolveNameCode()`（會鑄出第二個 name code）與標籤→代碼查表（會讓整列匯入失敗）。所有身分／去重比對都必須兩形都探，這是本階段最容易做錯的地方。
- **文本型 PK 成員替換改變列身分**：`ALTNAME_DATA.c_alt_name_chn`、`ASSOC_DATA.c_text_title`、`BIOG_SOURCE_DATA.c_pages`（PK = `c_personid`+`c_textid`+`c_pages`，而 `c_pages` 依 D5 在範圍內）。掛鉤必須在 PK 計算與查重之前。`ASSOC_DATA.c_text_title` 另會改寫**對面那個人**的鏡像列 PK 成員、該側無衝突檢查（S3）。
- **`SOCIAL_INSTITUTION_NAME_CODES.c_inst_name_hz` 是去重鍵**：S4 上線後同機構名的異體字寫法會收斂到同一碼（想要的行為），但既有已分裂的重複碼不會自動合併（D6）。
- **對照表增修的風險放大**：新增一筆就影響全庫所有文本欄的錄入。D8 保證幂等但**不保證語義正確**；`c_strict_excluded` 預設 1（人名不受影響）的既有設計要維持。
- **靠內容巧合安全的地方**：各 `*_TYPE_REL`／`*_TYPES`／`KINREL_REDUCTION`／`KIN_MOURNING` 的 varchar 代碼 PK、三個哨兵字面值——現值全 ASCII／不含那 7 字。S0 掃描與 S1 不變式測試處理，但 review 時值得再掃一次。
- **行程內快取陳舊**：對照表與型別兩份快取都是行程內靜態陣列。S1 已要求 10 個寫入入口呼叫 `reset()`；migration 仍繞過，靠不變式測試兜底。

## 不在本階段範圍內

- **已被閘門下架、且有 React／v2 替代品的 legacy Blade 寫入路徑**（使用者決定：這些頁面之後都會刪除）。指 `BasicInformation*Controller` 的 12 組子資源 `store()`／`update()`／`updateQuery()`／`destroy*()`（含 `BasicInformationEntriesController::update():379`／`updateQuery():648` 與 `BasicInformationSocialInstController:289`／`:481` 那幾份與 repository 並存的獨立實作）、`BasicInformationController::store()`／`update()`、`BasicInformationProposalController::normalizePayloadForTable()`（它自己直接呼叫 `replaceStrict()`、不經 repository，所以 S3 的 repository 改動不會弄壞它，且會自動繼承 S1 對 `strictMap()` 的閉包改動）、以及只被它們呼叫的 `BiogMainRepository::altnameStoreById()`／`altnameUpdateById()`。已查證 `config/migration_flags.php` 全部 flag 皆為 `new`，這些端點的非 GET 都被 `LegacyBladeFormGate` 擋成 410，且 12 個子資源都有 React 編輯器與 v2 handler。
- 既有資料的批次回溯校正（D6）。
- `OperationsController::restore()` 的**內容替換**（S1 仍在它掛 `assertWritable()` 結構驗證）。
- 眾包 create 提交的 Web UI 端：`basicinformation.editor` flag = `new`（`config/migration_flags.php:51`）⇒ legacy POST 被 410；v2 `BiogMainCreateHandler:112-115` 對 `mode=proposal` 回 501。**現行設定下不可達**。（token API 那條仍活著且無替代品，在 S7 範圍內。）
- 紀錄／帳號類表與框架表（D2 fail-closed + D3）。
- 搜尋路徑／FTS 的異體字歸一化（D9，列為下一階段候選）。
- `VariantCharNormalizer` 類別的刪除清理（前兩階段皆列為獨立任務）。
- `MergePreviewController` 產生的**帶外 SQL 腳本**（`routes/web.php:381-382`）：它產生但不執行 SQL，其中 `INSERT INTO MERGED_PERSON_DATA (… c_notes …)`（`:350`）的 `c_notes` 在 PHP 端由兩人 `c_notes` 串接組出（`:465-485`），由 DBA 帶外執行 ⇒ 繞過所有應用層掛鉤。同表的 v2 路徑（`MergedPersonCreateHandler`，繼承 subresource 基底）**會**被 S3 覆蓋 ⇒ 兩條路徑行為不一致，與 G4 同構。記錄以免誤以為已覆蓋。
- 已查證的死碼，不補掛：`Api\OperationsController::storeProcess()`、`AltCodeRepository::updateById()`、`AddrCodeRepository::updateById()`（後兩者用 `$request->all()` 無白名單寫 `ALTNAME_CODES`／`ADDR_CODES`，全庫零呼叫端）、`CodesController::performDestroy()` 早退後的程式碼、`CodeTableDeleteHandler` 403 之後的程式碼。
- Access 時代的 metadata 表（`COPYTABLES`／`TABLESFIELDS`／`FOREIGNKEYS` 等，全庫零引用）。

### 仍在使用且尚無替代者的舊路徑 —— 留在範圍內

| 路徑 | 為何仍活著 | 處置 |
|---|---|---|
| `POST /api/operations/add`／`update`（v1 token API） | `routes/api.php:113`／`:114` 仍註冊（群組 `:106`、`del` `:115`）；v2 人物 create 提案回 501，**無替代品** | S7 |
| `CrowdsourcingController::confirm()`／`reject()` | `routes/web.php:424-425` 仍註冊、未被任何閘門攔；眾包提案回填 `BIOG_MAIN` 的唯一路徑 | S7 |
| `BasicInformationController::saveas()`／`Duplicate_Collateral_Info()` | `routes/web.php:161-162` **未掛 `legacy.form`** | S7 |
| `CodesController` 的 Blade 寫入路由 | `LegacyBladeFormGate` 只覆蓋 basicinformation、**不覆蓋 codes** | S2（與 React 共用 `perform*`，一次涵蓋兩者） |
| 三支批次匯入 controller 的 `store()` | Blade 與 React 共用同一 controller 方法 | S4／S5 |
| `applyKinshipProposal()`／`applyAssocProposal()` | 入口是提案核准（活的），只是內部呼叫 repository 而非重放 handler | S6 |
| `BiogMainRepository::store()`／`updateById()` | v2 handler 與 legacy **共用** | S3 改為 `replaceRow()` |
