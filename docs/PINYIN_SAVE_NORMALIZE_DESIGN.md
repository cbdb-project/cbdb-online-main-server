# 保存時拼音 v→ü 自動歸一化（§D-12 實作設計）

> 對應 `docs/PINYIN_V_TO_UMLAUT_MIGRATION.md` §D-12「止血 2.0」。本文件是**實作前的設計定案**，用來鎖定
> **精確的欄位範圍**與**掛點**，確保「**只歸一化真正的漢語拼音欄位**」——避免把 `v` 誤改進 Wade-Giles
> 羅馬字或母語拉丁名等**合法含 `v`** 的欄位（Hongsu 明確要求：只替換拼音，以防錯誤替換）。

## 1. 背景與缺口

- **止血 1.0（M1，已上線）**：只掛在**生成路徑**——由中文自動生成拼音（`BiogMainRepository::auto_pinyin()`、
  三批次 `buildPinyin`）。生成出的拼音經 `PinyinUmlaut::normalize()` 已是 `ü` 正字。
- **缺口**：使用者在編輯頁**手動輸入**的拼音（直接打 `lv`）走的是**保存路徑、不經生成**，`v` 原樣入庫。
  Phase A 全量清乾淨的人名拼音，會被手動錄入重新污染。本設計即補上這一半。

## 2. 轉換規則（沿用既有 helper，不新增規則）

`App\Support\PinyinUmlaut::normalize()`（M1 已建於 develop）——依《漢語拼音方案》，`ü` 僅出現在聲母
`l`／`n` 之後，可窮舉四音節：

| v 形式 | 正字 | | v 形式 | 正字 |
|--------|------|--|--------|------|
| `lv`   | `lü` | | `nv`   | `nü` |
| `lve`  | `lüe`| | `nve`  | `nüe`|

正則 `/([LlNn])([Vv])(?![aiouAIOU])/u`：`l/n` 後的 `v`、且**其後非 a/i/o/u** 才轉（大小寫保留）。
- `lv`＋子音／邊界（`Lvzhai`、`Yelv`、`Lü Yin`）→ 轉。
- `lv`＋母音 a/i/o/u（西文名 `Silva`、`Calvin`、`Melville`、`Sylvia`）→ **不轉**（no-op）。

**本設計不改動此規則**，只決定「把它套用到**哪些欄位**、在**哪個掛點**」。

## 3. 核心原則：只歸一化漢語拼音欄，排除 `_rm`／`_proper`

⚠️ **不可沿用 `BracketNormalizer` 的欄位列**。`BracketNormalizer::BIOG_MAIN_PINYIN_FIELDS` 為了「括號補空格」
把下列欄位也納入——但這些**不是漢語拼音**，`v` 在其中是**合法字元**，一旦 v→ü 會**損壞資料**：

| 欄位 | 性質 | 處置 |
|------|------|-------|
| `c_surname`、`c_mingzi` | 漢語拼音（`c_*_rm`/`_proper` 另存其他羅馬化，故此二欄定義上即拼音） | ✅ **Tier 1** 後端靜默轉 |
| `c_name` | 由上兩者組出的全名 | ✅ **Tier 1**（與分量一致） |
| `c_alt_name_pinyin`、`c_alt_name_pinyin2`、`c_alt_name_pinyin3` | 明確標為「拼音」的別名拼音欄 | ✅ **Tier 1** 後端靜默轉 |
| `c_alt_name` | 別名羅馬化——**可能含西文別名**（如 Denver/Silver，`nve/lve` 為真實西文拼法） | ⚠️ **Tier 2** 前端偵測＋彈窗由使用者決定；後端**不**靜默轉 |
| `c_surname_rm`、`c_mingzi_rm`、`c_name_rm` | Wade-Giles／M-R 羅馬字（`ü` 用法不同） | ❌ **不轉**（不偵測、不彈窗） |
| `c_surname_proper`、`c_mingzi_proper`、`c_name_proper` | 母語拉丁原名（可能含**真** `v`，如 Silva） | ❌ **不轉** |
| `c_*_chn` | 中文 | ❌ 不轉 |

此表與 `PINYIN_V_TO_UMLAUT_MIGRATION.md` §4「不轉換的欄位」一致，並**修正** §D-12 條列中誤把
`c_*_rm`／`c_*_proper` 列為歸一化目標的錯誤。

### 兩層機制（依 Hongsu 定案）

- **Tier 1｜後端靜默自動轉**——欄位**定義上即漢語拼音**、不可能含西文（BIOG `c_surname`/`c_mingzi`/`c_name`、
  ALTNAME `c_alt_name_pinyin`/`2`/`3`）。保存前套 `PinyinUmlaut::normalize()`，無需詢問。
- **Tier 2｜前端互動確認**——欄位**可能含西文別名**（目前僅 ALTNAME `c_alt_name`）。React 編輯器於保存時，
  **用我們的規則**（`l/n`+`v` 且後非 a/i/o/u；**不是** naive「凡 v／u↔ü」對應）掃描該欄；**唯有規則命中**時
  跳出彈窗，逐處列出「`Lv`→`Lü`？」交使用者「轉換／保留」。使用者若保留（西文名如 Denver），值原樣送出。
  **後端對 `c_alt_name` 不做靜默轉換**（尊重使用者於前端的決定；避免覆寫其「保留西文」選擇）。

> 為什麼 `c_alt_name` 走 Tier 2 而 BIOG `c_surname` 不用：BIOG 已用 `c_*_rm`/`c_*_proper` 分流其他羅馬化與
> 母語名，故 `c_surname`/`c_mingzi` **定義上**就是漢語拼音、不會是 Denver 這類西文；`c_alt_name` 則是單一
> 泛用別名羅馬化欄、無分流，可能落入西文，故需 Tier 2 由人判定。`_rm`/`_proper` 一律不碰（連彈窗都不需）。

## 4. 掛點（與既有 BracketNormalizer 呼叫相鄰，逐一列舉）

**範圍原則（決定性）**：本 PR 的目標是止住**人工在現行 UI 手動輸入**造成的重新污染，故**只覆蓋目前
flag 已翻 `new`、真正在用的 React／`/api/v2` 人工輸入寫入面**。舊 Blade 控制器（`BasicInformation*Controller`）
**一律不改**——遵 `AGENTS.md`「新功能一律只做在 React/Inertia 路徑，不要再改舊 Blade」。掛點統一選在
**v2 handler／共用 repository** 這一層。**非 UI 的程式化／遺留寫入面**（legacy `/api/v1`、提案核准落庫等）
**不在本 PR 範圍**、屬有意排除的殘留，逐一列於 §9。

| # | 檔案:方法 | 現有 BracketNormalizer | 加掛 allowlist | 覆蓋的（active）入口 |
|---|-----------|------------------------|----------------|-----------|
| 1 | `BiogMainRepository::updateById()` L259 | normalizeBiogMain | BIOG | `/api/v2/mutate` direct（`BiogMainMutationHandler` L103 委派此處）＝React basic-info 直改 |
| 2 | `BiogMainMutationHandler::prepareProposalPayload()` L201 | normalizeBiogMain | BIOG | `/api/v2/mutate` proposal（提交時歸一化；核准時逐字套用，故提交時歸一化為必要且充分） |
| 3 | `BiogMainRepository::store()` L355 | normalizeBiogMain | BIOG | **active** 新建人物落點：v2 `BiogMainCreateHandler`（`Create.tsx`→`api.v2.create.web`）委派此處（BiogMainCreateHandler L151），舊 Blade `store` 亦共用。`auto_pinyin` 已先歸一化 `c_surname/c_mingzi`，故此處為**冪等防禦**（防未來生成邏輯變動） |
| 4 | `AltnameMutationHandler::preprocessUpdateData()` L62 | normalizeAltname | ALTNAME（**僅 `_pinyin/2/3`**） | React 別名編輯 / `/api/v2/mutate`（direct + proposal 皆經此 handler） |
| 5 | `AltnameCreateHandler::preprocessCreateData()` L61 | normalizeAltname | ALTNAME（**僅 `_pinyin/2/3`**） | React 別名新增 / `/api/v2/create` |

> **#4/#5 的 allowlist 不含 `c_alt_name`**（走 Tier 2 前端，見 §3、§4.2）；後端只靜默轉三個 `_pinyin` 欄。
> 註：`c_alt_name_pinyin/2/3` **未在 `AltnameEditor.tsx` 前端表單暴露**（該編輯器可手打的別名羅馬字欄
> 只有 `c_alt_name`；`NON_PK` 雖另含 `c_source/c_pages/c_notes/c_sequence`，但無 `_pinyin/2/3`），
> 故其 Tier 1 後端歸一化屬「冪等防禦」（涵蓋 API-direct 與未來 UI 擴充），非保護當前手動輸入面；
> 目前 React 唯一可手打的別名羅馬字欄就是走 Tier 2 的 `c_alt_name`。

> 說明：#1/#3 雖是共用 repository 方法（舊 Blade direct 也會路過、順帶受惠），但它們**同時是** active v2
> 路徑的落點，故屬「改 v2／共用資料層」而非「改舊 Blade」。#4/#5 的 v2 handler 同時承接 React 的
> **direct 與 proposal** 兩種模式（proposal 走 handler 內建 proposal 分支、亦經 `preprocess*`），故別名的
> active 提交面已由 #4/#5 全覆蓋，無需再碰任何 Blade 控制器。

**BIOG 的 `c_name` 一致性**：`c_name` 在各掛點都已由 `c_surname`+`c_mingzi` 組好（`updateById` L247、
`prepareProposalPayload` L197）；把 `c_name` 一併列入 allowlist，於 BracketNormalizer 後歸一化，
即與分量保持一致（`"Lv X"→"Lü X"` 等同於用歸一化後分量重組）。**不觸碰** `c_name_rm`／`c_name_proper`。

### 4.2 Tier 2 前端互動確認（`c_alt_name`）

- **位置**：React 別名編輯器 `resources/js/inertia/components/AltnameEditor.tsx`（新增前的 create 與編輯的
  update 兩條保存路徑）。
- **偵測**：新增前端工具 `resources/js/inertia/utils/pinyinUmlaut.ts`，**忠實移植** `PinyinUmlaut` 的規則
  （同一正則 `/([LlNn])([Vv])(?![aiouAIOU])/gu`、大小寫保留），提供 `detectUmlautConversions(value)`（回傳
  命中清單 `{from,to,index}`）與 `applyUmlaut(value)`。**務必與後端規則位元一致**（見 §7 有交叉測試）。
  - ⚠️ **替換契約（易錯點）**：`ü` 的大小寫**只由 group 2（`V`/`v`）決定**、group 1 原樣保留，須用捕獲組
    回呼 `(_, g1, g2) => g1 + (g2 === 'V' ? 'Ü' : 'ü')`——**不可**用「整段 match 大小寫折疊」的寫法
    （會在 `lV`／`Nv` 這類混合大小寫上與後端 `PinyinUmlaut.php:34` 分歧）。`/g` 為 JS 全域替換所必需；
    `/u` 與否不影響結果（規則與 lookahead 皆純 ASCII）。
- **流程**：使用者按「儲存」→ 對 `c_alt_name` 跑 `detectUmlautConversions`：
  - 無命中 → 照舊直接提交（絕大多數情況，零打擾）。
  - 有命中 → 彈出確認框，列出每處 `Lv → Lü`，預設**建議轉換**（多數別名確為拼音），但提供
    「保留原樣」；使用者確認後以其選擇的字串提交。**取消**則留在編輯器不提交。
- **後端配合**：`c_alt_name` **不在** #4/#5 的後端 allowlist；後端對其值原樣寫入，故使用者「保留西文」的
  決定不會被後端覆寫。（`c_alt_name_pinyin/2/3` 仍由後端 Tier 1 靜默轉，無需前端彈窗。）
- **API-direct 例外**：不經前端、直接打 `/api/v2` 的腳本呼叫，`c_alt_name` 不會被轉也不會彈窗——屬進階
  用法、自負其責；靜默轉才會有誤傷西文之虞，故以「原樣保留」為安全預設。

### 4.1 有意排除、非遺漏的路徑（逐一交代，供 review 對帳）

- **所有舊 Blade 控制器路徑（休眠、flag=new）**：`BasicInformationController::update()` 的 proposal 分支
  （L1743-1771）、`BasicInformationAltnamesController` 的 proposal 分支（L111-114 新增、L447-458 編輯）
  等，會繞過上表掛點、直接交 `BasicInformationProposalController` 逐字寫入 `operations`。**本 PR 不改這些
  舊 Blade 控制器**（遵 AGENTS.md）。這些路徑目前**不可達**（頁面 flag 全為 `new`）；風險僅存在於「有人
  把 flag 手動翻回 `old`」的回退情境，屆時應**連同回退一起**在 Blade 側補守衛（並非本 PR 職責）。M1 生成
  路徑守衛在任何情況下仍有效。→ 見 §9「回退期殘留風險」。
- **BIOG「複製」路徑（無手動輸入）**：`BasicInformationController::saveas()`（L1822）、
  `Duplicate_Collateral_Info()`（L1868）直接 `BiogMain::create($data)` 複製既有 DB 列，**不接受手打拼音**——
  值來自來源列（Phase A 已於源頭清理），無「重新污染」風險，故不納入。
- **新建人物的「提案」模式（既有限制、非本 PR 造成）**：`BiogMainCreateHandler::handle` 對 `mode='proposal'`
  回 501（L112-116），故眾包用戶目前**無** v2 途徑「提案新建一個人」；此為既有功能缺口，與本歸一化無關。
  （人物**更新**無論走一般提案（#2 `prepareProposalPayload`）或眾包用戶的待審路徑（#1 `updateById` 的
  `isCrowdsourcingUser` 分支）皆已歸一化，涵蓋無虞。）

## 5. 範圍決策：Code 表（OFFICE/TEXT/INST…）與書名內聯拼音——**本 PR 不做，留待 Phase B**

盤點發現 `CodesController`（`store`/`update`/`proposalStore`/`proposalUpdate`/`proposalUpdateExisting`）
是**泛型直寫**：`Arr::except($request->all(), [...metadata])` 後 `DB::table($table)->insert/update`，
**無欄位 allowlist、無任何拼音歸一化**，且**全庫不存在**「每張 code 表的漢語拼音欄清單」登錄檔。
`AdminBatchLoadBookTitlesController::updatePinyin()`（TEXT_CODES.c_title 內聯編輯）同屬 code 表、
目前只做大小寫／空白收斂、未做 v→ü。

**決定不在本 PR 覆蓋 code 表，理由：**
1. **無安全的欄位判定依據**：泛型直寫要對任意表安全套用 v→ü，必須先有「表→漢語拼音欄」白名單，
   以區隔 Wade-Giles（如 `c_office_pinyin_alt` 可能為另一種羅馬化）、英文譯名（`c_office_trans`）、
   母語欄。此白名單目前**不存在**；憑欄名 `_py`/`_pinyin` 猜測會誤傷，正犯「錯誤替換」之忌。
2. **Code 表屬 Phase B、尚未清理**：§4 已定 code 表為 Phase B，且動工前需先建**受審計的 code 表寫入 API**
   （見 `CODE_TABLE_MUTATION_API_PLAN.md`）；現行 `/codes` proposal 只寫 `operations`、不寫 `audit_log`。
   Phase B 的掃描與人工複核，正是產出上述欄位白名單的自然時機。
3. **無「重新污染」急迫性**：止血 2.0 的目的是保護 **Phase A 已清乾淨**的資料（人名）。code 表尚未清理，
   談不上「被重新污染」；等 Phase B 一併清理＋建立 API＋建欄位白名單時再納入，最安全。

**於 Phase B 待辦記錄**（並補進 §D-12／CODE_TABLE_MUTATION_API_PLAN）：
- 建立「表→漢語拼音欄」白名單（隨掃描＋人工複核產出）。
- `CodesController` 各寫入方法與 `AdminBatchLoadBookTitlesController::updatePinyin()` 依白名單加掛 v→ü。
- 修正 `updatePinyin` 內聯編輯與其批次 `buildPinyin`（已用 PinyinUmlaut）行為不一致的問題。

## 6. Helper 設計

在 `App\Support\PinyinUmlaut` 新增一個純函式（單一職責、無狀態、可測）：

```php
/** 對 $data 中列於 $fields 的字串欄套用 normalize()；非字串／缺欄／null 原樣略過。 */
public static function normalizeFields(array $data, array $fields): array {
    foreach ($fields as $field) {
        if (array_key_exists($field, $data) && is_string($data[$field])) {
            $data[$field] = self::normalize($data[$field]);
        }
    }
    return $data;
}
```

allowlist 以常數集中定義（置於 `PinyinUmlaut`，與規則同源，避免各掛點各自列欄漂移）：

```php
// Tier 1（後端靜默）allowlist——皆為定義上的漢語拼音欄，不含可能含西文的 c_alt_name。
public const BIOG_MAIN_PINYIN_V_FIELDS = ['c_surname', 'c_mingzi', 'c_name'];
public const ALTNAME_PINYIN_V_FIELDS   = ['c_alt_name_pinyin', 'c_alt_name_pinyin2', 'c_alt_name_pinyin3'];
```

> `c_alt_name` **刻意不在**任何後端 allowlist——它走 Tier 2 前端互動（§4.2）。前端 TS 移植規則同源於此
> `normalize()`，兩邊以交叉測試釘死一致（§7）。

各掛點呼叫：`$data = PinyinUmlaut::normalizeFields($data, PinyinUmlaut::BIOG_MAIN_PINYIN_V_FIELDS);`
（緊接對應的 `BracketNormalizer::normalize*` 之後。）

**冪等性**：`normalize()` 對已是 `ü` 或不含 `l/n+v` 的字串為 no-op，重複套用安全。

## 7. 測試計畫（每個掛點補回歸）

**後端（PHP，Tier 1）**
- **正向**：手打 `Lv`／`lv`／`Nve` 保存後讀回為 `Lü`／`lü`／`nüe`。
  - BIOG：`c_surname='Lv'` → 讀回 `Lü`，且 `c_name` 同步為 `Lü …`。
  - ALTNAME：`c_alt_name_pinyin='lv...'` → 讀回 `lü...`（證明覆蓋了 BracketNormalizer 未覆蓋的數字拼音欄）。
- **不誤傷（核心保護）**：
  - `c_surname_rm='Lv'`、`c_surname_proper='Silva'`、`c_name_proper` 含 `v` → 保存後**不變**。
  - **`c_alt_name` 後端不轉**：送 `c_alt_name='Lv Meng'`（未經前端）→ 後端**原樣寫入**（Tier 2 由前端負責）。
- **中文欄**：`c_*_chn` 不受影響。
- **proposal**：`/api/v2/mutate` proposal 提交後，`operations.resource_data` 內 Tier 1 拼音欄已是 `ü`；`c_alt_name` 保持提交值。
- **冪等**：對已是 `ü` 的資料再保存一次，值不變、無多餘 operation/audit（沿用既有 no-change 偵測）。
- 依 SQLite 不折疊 `ü`／`u` 之特性（見 §3），測試直接斷言二進位相等，勿依賴 collation。

**前端（TS，Tier 2 + 規則一致性）**
- `detectUmlautConversions`／`applyUmlaut` 單元測試：`Lv`→命中`Lü`、`Nve`→`Nüe`；`Silva`/`Calvin`/`Melville`/
  `Denver`... 一律**無命中**除非符合規則（`Denver` 的 `nve` 會命中——正是需彈窗由人判定之例）；非 `l/n` 的 `v`
  （`David`）無命中。
- **規則位元一致性交叉測試**：以一組共用樣本（`pinyinUmlaut.test.ts` 的 `CANONICAL` 與後端
  `PinyinUmlautTest::canonicalFixtures()` **同一張表**），兩端各自斷言 `applyUmlaut(s)`／
  `PinyinUmlaut::normalize(s)` 命中該表，任一端改動都要同步另一端，防規則漂移。
- AltnameEditor 保存流程（有命中→彈窗、保留/轉換/取消三分支）：本 repo **無元件測試框架**（vitest 僅
  node 環境、測純函式），此互動流程以**人工驗證**為準（見下）；偵測與轉換的核心邏輯已由 `pinyinUmlaut.ts`
  單元測試覆蓋。若日後引入 jsdom + RTL，可補元件層自動化測試。

**人工驗證清單（Tier 2 彈窗）**：於別名編輯器 (1) 打 `Lv Meng` 按儲存→彈窗列 `Lv→Lü`；選「轉換」存為
`Lü Meng`、選「保留」存為 `Lv Meng`、「取消」不送出且欄位不變。(2) 打 `Denver` 按儲存→彈窗列
`nv→nü`，選「保留」存 `Denver`（驗證西文可保留）。(3) 打 `Silva`／`David` 按儲存→**不**彈窗、直接送出。

## 8. 交付範圍小結

| 項目 | 本 PR |
|------|-------|
| `PinyinUmlaut::normalizeFields()` + 兩組 Tier 1 allowlist 常數 | ✅ |
| Tier 1 後端：BIOG 三掛點（updateById / prepareProposalPayload / store）| ✅ |
| Tier 1 後端：ALTNAME 兩 handler（v2 update / create，僅 `_pinyin/2/3`）| ✅ |
| Tier 2 前端：`pinyinUmlaut.ts` 偵測工具 + AltnameEditor `c_alt_name` 保存彈窗 | ✅ |
| 後端測試（正向 / 不誤傷 / `c_alt_name` 不轉 / proposal / 冪等）| ✅ |
| 前端測試（偵測、規則與後端位元一致、彈窗流程）| ✅ |
| 修正 §D-12 誤列 `_rm`／`_proper` 的條文 | ✅ |
| 舊 Blade 控制器（biog/altname proposal 分支等） | ❌ 不改（遵 AGENTS.md；休眠、僅回退期殘留，見 §9） |
| Code 表（CodesController／書名內聯） | ❌ 留 Phase B（見 §5，記入待辦） |

## 9. 回退期殘留風險（明確記錄，非本 PR 職責）

本 PR 關閉的是**目前人工可達**的手動輸入面（React／`/api/v2`）。以下是**已知、有意排除**的非 UI／遺留
寫入面，逐一交代（codex 盤點），供日後對帳；均非「一般使用者在現行 UI 手動輸入」之列：

1. **舊 Blade 控制器（休眠，flag=new）**：若有人把某頁 flag 從 `new` 翻回 `old`，該頁舊 Blade 控制器
   （§4.1）會重新可達，其手動輸入的 `v` 不會被本 PR 掛點攔到。
   - 觸發：明確的管理員回退動作（非一般使用者）。緩解：M1 生成守衛仍有效。
   - 正解：任何 Blade 回退應**連同**在對應 Blade 控制器補一次 `PinyinUmlaut::normalizeFields()`（沿用本 PR
     helper／allowlist，成本極低），列入回退檢查清單，而非在本 PR 預改休眠舊碼。

2. **Legacy `/api/v1/add`、`/api/v1/update`（程式化整合 API，非 UI）**：`routes/api.php` v1 群組 →
   `ApiController@addC_presonid/updateC_presonid` → `App\v1::addC/updateC`（`app/v1.php:50-77`）直接
   `BiogMain::create/save`，**只寫呼叫端傳入的 `c_name`／`c_name_chn`**（不寫 `c_surname/c_mingzi`、不跑
   `auto_pinyin`）。若呼叫端傳入含 `Lv` 的 `c_name` 會原樣入庫。
   - 定位：2018 年建置的外部程式化 GET API，非人工 UI；與 `c_alt_name` 的 API-direct 例外同理（程式化呼叫
     端自負其責）。**本 PR 不改**。
   - ⚠️ **另註（非本 PR 職責、應另開 ticket）**：此 v1 寫入群組看來**未掛 `auth:sanctum`**（GET 即可新增／
     更新／刪除 `BIOG_MAIN`），屬既有安全弱點，建議獨立處理（加認證或下架）；屆時若保留，可順手補 `c_name`
     的 `PinyinUmlaut::normalize()`。

3. **提案核准落庫（approval-time apply）**：`OperationsProposalController` 的
   `applyCreateProposal()`／`applyUpdateProposal()`（L626 insert／L696 update）把 `resource_data` **逐字**
   寫入、不重跑歸一化。對**經本 PR v2 掛點（#2/#4/#5）提交的新提案**，payload 已於提交時歸一化，核准正確；
   但**核准前已存在的舊提案**、或非 v2 途徑產生的提案列，其殘留 `v` 會於核准時落庫。
   - 定位：存量有限、隨核准逐步排空；且核准者為審核人、非匿名輸入。
   - 不在核准端加泛型歸一化：該執行器對**所有資源型別**通用，逐型別判定拼音欄＝與 §5 code 表相同的
     「無欄位白名單」難題，風險高於收益。故以文件記錄殘留、不於本 PR 觸碰核准端。
