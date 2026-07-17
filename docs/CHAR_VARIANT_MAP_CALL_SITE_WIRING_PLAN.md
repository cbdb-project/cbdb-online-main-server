# 異體字對照表 Work Plan（第二階段）：串接 `char_variant_map` 呼叫點

> **前置文件**：[CHAR_VARIANT_MAP_CONSOLIDATION_PLAN.md](./CHAR_VARIANT_MAP_CONSOLIDATION_PLAN.md)（第一階段，已完成：`char_variant_map` 資料表 schema 設計與 migration）。該文件的「呼叫點串接方向」一節只記錄**方向**，本文件把那一節展開成具體、可逐步驗收的實作步驟，並對其中列出的「待決事項」給出明確決定。

## 背景

`char_variant_map` 表已存在（7 筆種子資料），但目前完全沒有程式碼查詢它——`TITLE_VARIANT_MAP` 仍是 `AdminBatchLoadBookTitlesController` 內建的陣列，BIOG_MAIN／ALTNAME_DATA 寫入路徑完全沒有異體字落地替換。本階段把這些呼叫點改接到 `char_variant_map`，並補上治理面（Codes UI + token API 的可稽核增修管道）。

## 範圍

本文件涵蓋前一份計畫文件「呼叫點串接方向」的全部 5 點，拆成 7 個實作環節（見下方「實作步驟」），每個環節各自過完整 review 才進下一個。**不涉及** `VariantCharNormalizer` 類別本身的刪除清理（那是前一份文件標註的、依賴條件已滿足但仍獨立於本階段之外的另一個任務）。

## 待決事項的決定

### 1. BIOG_MAIN 落地替換的欄位替換順序

前一份計畫文件已標註待決：替換順序是「先替換 `c_surname_chn`/`c_mingzi_chn` 分欄、再組出 `c_name_chn`」還是「只替換組合後的 `c_name_chn`」。

**決定**：採用前者——先替換分欄、再組字。理由：
- 維持現行 `c_name_chn === c_surname_chn.c_mingzi_chn` invariant，這個 invariant 被 `BiogMainRepository::namesByQuery()`、`c_name_chn` 相關的 LIKE 查詢等多處依賴，破壞它的影響面難以窮舉。
- `c_surname_chn`/`c_mingzi_chn` 本身也是使用者看得到、可獨立編輯的欄位（表單有各自的輸入框），只替換組合欄會讓表單顯示的姓/名與資料庫組合出的全名不一致，使用者會看到「我沒改姓氏欄，但存檔後全名變了」的困惑局面；分欄替換則兩邊一致。

### 2. BIOG_MAIN create／update 是否用同一個掛鉤點

**決定**：不用同一個掛鉤點，維持 create／update 分開實作（前一份文件已指出兩者不對稱，這裡是延續該結論的具體作法）：
- Update／proposal 路徑：在 `BiogMainMutationHandler::prepareProposalPayload()` 與 `BiogMainRepository::updateById()` 現有的組字那一行**之前**，各自插入一行「替換 `c_surname_chn`/`c_mingzi_chn`」。
- Create 路徑：在 `BiogMainRepository::store()` 內，`$data = $request->all()` 之後、`auto_pinyin($data)` 之前，新增一段「若 `c_surname_chn`/`c_mingzi_chn` 存在則替換，並同步重新組出 `c_name_chn`（若客戶端沒有另外組好的話——但為了與 update 路徑行為一致，一律由後端重新組字，不採信客戶端傳來的 `c_name_chn`）」。

  **子決定**：create 路徑要不要無條件由後端重組 `c_name_chn`（即忽略前端傳來的 `c_name_chn`，一律用替換後的 `c_surname_chn`+`c_mingzi_chn` 重新組字）？
  **決定**：**當請求裡出現 `c_surname_chn` 或 `c_mingzi_chn` 任一分欄鍵時**，是，無條件重組（用 `array_key_exists()` 判斷鍵是否存在，不是判斷值是否為空字串）。理由：這是能保證 create／update 兩條路徑「儲存後 `c_name_chn` 是否含有已落地替換的異體字」行為一致的做法；如果只在「前端沒傳 `c_name_chn`」時才重組，會出現「前端有沒有多送一個欄位」這種前端行為決定後端正規化結果的不一致局面。

  **實作時發現需要修正的例外**：v2 API 的 `BiogMainCreateHandler::ALLOWED_FIELDS`（`app/Services/Mutations/BiogMainCreateHandler.php:33-84`）把 `c_name_chn` 與 `c_surname_chn`/`c_mingzi_chn` 列為三個各自獨立、呼叫端可自由選擇送或不送的欄位——`tests/Feature/ApiV2CreateBiogMainTest.php` 的既有測試就是只送 `c_name_chn`（不送分欄）的真實使用情境，對應「無明確姓氏可拆分的歷史人物」這種資料。若不分情況、永遠假設分欄存在並無條件用兩個分欄相加覆寫 `c_name_chn`，當分欄根本沒出現在請求裡時，`(string) ($data['c_surname_chn'] ?? '')` 會靜默退回空字串，導致 `c_name_chn` 被覆寫成空字串——這不是「重組出正確值」，是把有效資料清空的資料損毀 bug（已於 review 前的測試跑驗中發現並修正，見下方測試小節）。**修正後的規則**：`c_surname_chn`／`c_mingzi_chn` 兩個鍵**只要有任一個**出現在請求裡（不論值是否為空字串），才視為「這次是分欄模式」，套用上方的無條件重組；兩個鍵都不存在時，改為只對 `c_name_chn` 本身做嚴格模式替換，不做任何組字/覆寫。這與「無條件重組」的決定精神並不衝突——「無條件」原本要擋的是「前端傳了分欄卻也傳了不同的 `c_name_chn`、該信哪個」的歧義，不是要在分欄根本不存在時憑空生出一個空字串組合。

### 3. ALTNAME_DATA 的複合主鍵欄位替換

**決定**：在 `AltnameCreateHandler::preprocessCreateData()` 與 `AltnameMutationHandler::preprocessUpdateData()` 內，對 `data['c_alt_name_chn']`（若存在）做嚴格模式替換，不需要額外處理——這兩個掛鉤點都發生在各自 handler 決定「新 PK 為何」**之前**：
- Update 路徑：已於程式碼確認 `AbstractPersonSubresourceMutationHandler::handle()` 第 135 行呼叫 `preprocessUpdateData()`，早於該方法第 152 行才 dispatch 到 `handleDirect()`，而 `buildNewPk()` 是 `handleDirect()` 內第 212 行才呼叫的——`preprocessUpdateData()` 與 `buildNewPk()` 分屬 `handle()`／`handleDirect()` 兩個方法，但呼叫順序上前者確實早於後者。
- Create 路徑：對應的是 `AbstractPersonSubresourceCreateHandler::extractPkFromRow()`（呼叫於 `app/Services/Mutations/AbstractPersonSubresourceCreateHandler.php:122`，方法定義於 314 行），不是 `buildNewPk()`（那是 mutation/update handler 的方法名，create handler 沒有這個方法）；`preprocessCreateData()`（119 行呼叫）同樣早於 `extractPkFromRow()`（122 行呼叫）執行，結論一致：替換後的值才是決定 PK 的依據。

所以替換後的值會自然成為新 PK 的一部分，`AltnameMutationHandler::performUpdate()` 既有的 PK 衝突偵測（79-95 行）與 `syncAltnameIndexAfterUpdate()` 的搜尋索引同步也都讀取替換後的 `updateData`，不需要修改這兩段既有邏輯。

**修正（盤點擴大）：ALTNAME_DATA 還有第二條完全獨立的寫入路徑，必須一併掛鉤，否則兩條路徑行為會不一致**：`AltnameCreateHandler`／`AltnameMutationHandler` 是 v2 API 路徑；legacy Blade 路徑（`BasicInformationAltnamesController::store()`／`update()`）**不會**呼叫這兩個 handler，而是各自直接呼叫 `BiogMainRepository::altnameStoreById()`（`app/Repositories/BiogMainRepository.php:3558-3597`）與 `BiogMainRepository::altnameUpdateById()`。這與 BIOG_MAIN 的情況不同——BIOG_MAIN 的 legacy Blade（非眾包分支）與 v2 API 剛好共用同一個 `BiogMainRepository::store()`／`updateById()`，所以掛一次鉤兩邊都受益；但 ALTNAME_DATA 的 legacy Blade 與 v2 API 是**兩份平行、互不相關的實作**，只掛 v2 handler 會讓 legacy Blade 路徑（目前仍是 flag-gated、非死碼，見 `AGENTS.md`）完全沒有落地替換，造成「同一張人物別名資料，走新版 React 頁面存會被替換、走舊版 Blade 頁面存不會被替換」的不一致。**決定**：`altnameStoreById()`（在重複檢查 `DB::table('ALTNAME_DATA')->where(...)` 之前）與 `altnameUpdateById()`（在組出 `$newPk3` 的邏輯——`app/Repositories/BiogMainRepository.php:3611-3615`——之前）也要各自呼叫 `CharVariantMapService::replaceStrict()`，與 v2 handler 的掛鉤點行為對齊。

### 4. `CharVariantMapService` 的介面設計

新增 `app/Services/CharVariantMapService.php`，介面：

```php
class CharVariantMapService {
    /**
     * 寬鬆模式：對整段文字做落地替換，表裡任何一筆都套用（忽略 c_strict_excluded）。
     *
     * @return array{text: string, replaced: array<string,string>} text 為替換後文字；
     *   replaced 為「這次呼叫實際套用了哪些對照」（僅列出輸入文字裡真的出現過的異體字，
     *   不是整張表），鍵為異體字、值為參考字，例如 ['峯' => '峰']。呼叫端據此決定要不要
     *   顯示非阻塞提示；不需要提示的呼叫端可以只取 ['text']，忽略 replaced。
     */
    public static function replaceLenient(string $text): array;

    /**
     * 嚴格模式：對整段文字做落地替換，只套用 c_strict_excluded = 0 的記錄。
     *
     * @return array{text: string, replaced: array<string,string>} 同上。
     */
    public static function replaceStrict(string $text): array;

    /** 清除靜態快取（測試用，比照 VariantCharNormalizer::reset() 慣例）。 */
    public static function reset(): void;

    /**
     * 把 replaced 組成非阻塞通知文字（待決事項 5）。實作時發現 BIOG_MAIN
     * update／create 與 legacy Blade 各自的呼叫端都需要同一份措辭，抽成這個共用
     * 方法，避免重複程式碼各自維護造成用語不一致（原始介面設計未列出，實作階段
     * 新增）。
     *
     * @param array<string,string> $replaced
     * @return array<int,string> 空陣列代表無需通知
     */
    public static function buildNotices(array $replaced): array;
}
```

**回傳形狀的決定**：兩個方法都回傳 `array{text, replaced}` 而非單純 `string`，是為了同時滿足「落地替換」與「讓錄入者知悉」兩個需求（後者見下方「待決事項 5」）——`replaced` 只列出這次呼叫**實際命中**的對照（用輸入文字逐一檢查 map 裡的異體字是否出現，而非回傳整張表），呼叫端可以直接用 `!empty($replaced)` 判斷要不要組出通知文字，不需要自己重新比對前後字串差異。

- 內部用一個靜態陣列快取（`$lenientMap`、`$strictMap`），第一次呼叫時各自查一次 `char_variant_map` 全表（`replaceLenient` 用全部記錄，`replaceStrict` 用 `WHERE c_strict_excluded = 0` 的子集），之後同一個 request 生命週期內重複呼叫不重複查表。快取邏輯比照 `VariantCharNormalizer::$loaded`/`ensureLoaded()` 的既有慣例，但這裡快取的是「兩個表全量已知不大（目前 7 筆，預期成長也是以十為單位）」的資料表，不做分頁或限制筆數。
- 用 `strtr($text, $map)` 做替換（與 `AdminBatchLoadBookTitlesController::standardizeTitleVariants()` 現行做法一致，`strtr` 對多字元 key 的陣列會自動處理成單一字元對單一字元的替換，這裡每個 key 恰好都是單一 CJK 字元，行為等同逐字元替換）；`replaced` 則另外用一次簡單迴圈（檢查輸入文字是否包含每個 map key）算出，不影響 `strtr` 本身的效能特性。
- 不做 request-scoped DI／不做 facade，維持靜態方法呼叫風格，與 `VariantCharNormalizer`、`PinyinDictionary`、`BracketNormalizer` 等現有工具類別的呼叫慣例一致（呼叫端都是 `XxxService::method()`，不注入建構子）。
- 測試時透過 `CharVariantMapService::reset()` 清快取，並在 `RefreshDatabase` 環境下對 `char_variant_map` 表插入/修改測試資料後呼叫 `reset()` 確保下次呼叫重新查表。

### 5. 落地替換通知機制（非阻塞，不彈窗）

**背景**：步驟 3／4／5（BIOG_MAIN、ALTNAME_DATA）是全新行為，上線後任何人物姓名／別名含這些異體字，儲存時會被**靜默**改寫。使用者體驗上應該讓錄入者至少知悉「系統幫你把字改了」，但**不需要**像既有 `PinyinUmlautConfirmDialog`（Tier 2 v→ü 彈窗）那樣要求使用者做決定——落地替換是無條件套用、沒有「轉換／保留」選項，跳出一個要使用者選擇的 modal 反而是誤導（看起來像可以拒絕，實際上不行）。因此本次採用**非阻塞、免互動**的提示通道，比照現有 codebase 已經在用的「flash 一則訊息、幾秒後自動消失」慣例，不新增彈窗或新的 UI 元件。

**各介面的通知通道決定**：

1. **Legacy Blade（`BasicInformationController`／`BasicInformationAltnamesController` 的 direct 儲存路徑）**：沿用既有 `laracasts/flash` 套件的 `info` 等級（`flash($msg, 'info')`），與現有 `flash(..., 'success'/'error')` 呼叫並列（見 `app/Http/Controllers/BasicInformationController.php` 既有多處 `flash()` 呼叫），渲染為 Bootstrap `alert-info`（`vendor/laracasts/flash/src/views/message.blade.php`，兩個 layout 都已 `@include`），非 modal、不需要新元件。若 `$replaced` 非空，組出類似「已將姓名中的「峯」正規化為「峰」」的訊息，`flash($msg, 'info')`。

2. **v2 JSON API（`BiogMainMutationHandler`、`AltnameCreateHandler`、`AltnameMutationHandler` 的 direct／proposal 回應）**：現行成功回應是 `{ok, resource, mode, operation, result}` 這個 envelope（`AbstractPersonSubresourceMutationHandler.php` 285-298 行、350-358 行附近），目前沒有任何 `notices`／`warnings` 欄位。**新增**一個**可選**（僅在有替換時才出現）的頂層 `notices` 陣列欄位，例如 `{"ok": true, ..., "notices": ["c_surname_chn：「峯」已正規化為「峰」"]}`。這是**新增欄位**、不影響既有回應結構，既有前端呼叫端若不讀取 `notices` 也不受影響（向後相容）。

3. **前端編輯器（`BasicInfoEditor.tsx`、`AltnameEditor.tsx`）**：兩個元件都已有「非阻塞、自動消失」的既有慣例可以直接複用，不需要新元件：
   - `BasicInfoEditor.tsx` 已有 `pinyinKinshipHint`（96 行）這個**非阻塞提示**的先例（400 行渲染為 `<div style={gWarnStyle}>...</div>`）。**注意**：需要使用者做決定的 `umlautPrompt` 彈窗機制**不在這個檔案裡**——`umlautPrompt` 實際定義在 `AltnameEditor.tsx`（90 行，213-231 行渲染為真正的 modal，`role="dialog" aria-modal="true"`），`BasicInfoEditor.tsx` 本身目前沒有任何彈窗式拼音確認機制可以對照；「非阻塞 vs. 需要使用者決定」的對比案例是跨檔案的（`BasicInfoEditor.tsx` 的 `pinyinKinshipHint` vs. `AltnameEditor.tsx` 的 `umlautPrompt`），不是同一檔案內兩種機制並存。新增邏輯：儲存成功後，若回應 `notices` 非空，比照 `pinyinKinshipHint` 的模式設一個新的 state（例如 `variantReplacedNotice`），渲染同樣的 `gWarnStyle` 提示列，可以跟 `flashSaved()`（86 行）的既有訊息一樣幾秒後自動消失，或維持顯示直到下次儲存（比照 `pinyinKinshipHint` 目前的行為，實作時二擇一，不需要新設計一套顯示邏輯）。
   - `AltnameEditor.tsx` 已有同構的 `message`/`flashSaved()`（68-69 行）**非阻塞**訊息機制，做法比照。

4. **書名批次匯入（`AdminBatchLoadBookTitlesController::store()`）**：這裡是寬鬆模式（`CharVariantMapService::replaceLenient()`），套用點在 `parseEntries()` 組 `$rows[]` 那一行（目前呼叫 `standardizeTitleVariants()`）。批次匯入的結果頁本來就會列出每一筆匯入結果（`$results[]`，132-174 行，含 `title`／`title_pinyin` 等欄位），**新增**一個 `variant_replacements` 欄位到每筆 `$results[]`（例如 `[['from' => '峯', 'to' => '峰']]`，無替換則為空陣列），交給既有的批次結果頁（`with('batch_results', $results)`）逐列顯示——這本來就是非互動的列表頁，不需要額外彈窗或提示元件，只是多顯示一欄／一個小標籤。

**這個通知機制不影響步驟 3／4／5 的核心邏輯**，純粹是「呼叫 `CharVariantMapService` 後，把回傳的 `replaced` 接到既有通知通道」，四個介面各自的接法可以在對應步驟（步驟 2、3、4、5）內各自完成，不需要額外開一個步驟。

## 實作步驟（每步完成後跑 review 機制才能進下一步）

> Review 機制同前一份文件：每個步驟完成後，先派一組會讀程式碼與讀改動的 review agent 檢查，直到沒有嚴重 issue；再用 `codex exec --dangerously-bypass-approvals-and-sandbox`（PowerShell + `Write-Output "..." |` 管道傳 prompt）做第二輪檢查，直到沒有嚴重 issue，才進下一步。**本輪要求：review 全部通過後先不 commit，待使用者確認再進行版本控制操作。**

### 步驟 0：本文件

先過 review 機制（review agent + codex）確認本文件的決定與既有程式碼流程描述無誤，再進入下一步。

### 步驟 1：`CharVariantMapService`

新增 `app/Services/CharVariantMapService.php`（見上方介面設計，`replaceLenient()`／`replaceStrict()` 回傳 `array{text, replaced}`）與對應單元測試 `tests/Unit/CharVariantMapServiceTest.php`（仿 `tests/Unit/VariantCharNormalizerTest.php` 的測試風格），至少涵蓋：
- 寬鬆模式：`c_strict_excluded=1` 的「峯」也會被替換成「峰」，且回傳的 `replaced` 含 `['峯' => '峰']`。
- 嚴格模式：「峯」不被替換（`text` 保持原樣、`replaced` 不含「峯」這個鍵），其餘 6 筆會被替換且各自出現在 `replaced`。
- `replaced` 只列出輸入文字裡「實際出現過」的異體字：例如輸入只含「峯」與「淸」兩個異體字時，`replaced` 只有這兩筆，不是整張表 7 筆。
- 快取：修改資料庫後、`reset()` 前後查詢結果應有差異（驗證快取確實生效、`reset()` 確實清除）。
- 空字串／不含任何對照字元的文字：`text` 原樣返回，`replaced` 為空陣列。

### 步驟 2：`AdminBatchLoadBookTitlesController::TITLE_VARIANT_MAP` 改接

移除 `TITLE_VARIANT_MAP` 常數與 `standardizeTitleVariants()` 內的 `strtr()` 呼叫，改為呼叫 `CharVariantMapService::replaceLenient()`（保留其 `text` 部分供 `parseEntries()` 組 `$rows[]` 用，`replaced` 部分依「待決事項 5」第 4 點存進該筆 `$row['variant_replacements']`，最終在 `store()` 132-174 行組 `$results[]` 時一併帶出）。更新／新增測試（`tests/Feature/AdminBatchLoadBookTitlesTest.php`），確認：
- 現行 3 筆書名替換（峯→峰、靑→青、頴→穎）行為不變。
- 新增的「淸→清」「厰→廠」在書名匯入時現在**也會**被替換（這是本次真正的行為擴張——這兩筆原本不在 `TITLE_VARIANT_MAP` 裡）。
- 「愼→慎」「槀→稿」（原僅用於拼音的 2 筆）現在**也會**改寫書名本身（這是寬鬆模式的定義：表裡任何一筆都套用，不分原本是哪個舊機制的資料）。
- 批次匯入結果（`$results[]`）裡，發生替換的列有非空 `variant_replacements`（例如 `[['from' => '峯', 'to' => '峰']]`），沒發生替換的列 `variant_replacements` 為空陣列；批次結果頁（Inertia）能正確渲染這個欄位（哪怕只是簡單文字列出，不需要特別設計 UI）。

### 步驟 3：BIOG_MAIN update／proposal 路徑改接

依「待決事項 1」的決定，在 `BiogMainMutationHandler::prepareProposalPayload()`（`app/Services/Mutations/BiogMainMutationHandler.php:196-200`）與 `BiogMainRepository::updateById()`（`app/Repositories/BiogMainRepository.php:246`）組字那一行之前，各自對 `c_surname_chn`/`c_mingzi_chn` 呼叫 `CharVariantMapService::replaceStrict()`。依「待決事項 5」，把兩個呼叫回傳的 `replaced` 合併後接上通知通道：v2 API direct／proposal 回應加上 `notices` 欄位；legacy Blade 路徑（`BasicInformationController::update()`，同檔案 1774 行呼叫 `biogMainRepository->updateById($request, $id)`，即本步驟掛鉤的同一個方法）比照該方法既有的 `flash(..., 'info')` 慣例（1778 行「無實質更新，資料未變更」即為一例）補上一則 `flash($msg, 'info')`；`BasicInfoEditor.tsx` 讀取 `notices` 並比照 `pinyinKinshipHint` 顯示非阻塞提示列。（訊息組字邏輯最初各自寫在 `BiogMainMutationHandler`/`BasicInformationController` 內；步驟 4 實作時發現同一段邏輯要在第三處重複，已回頭抽成 `CharVariantMapService::buildNotices()` 共用方法，見「待決事項 4」補充，步驟 3 的既有測試不受影響、行為不變，僅程式碼去重。）

**實作時發現的第三個掛鉤點（原計畫未列出）**：legacy Blade 的提案路徑（`action=proposal`，`BasicInformationController::update()` 1743-1772 行 → `BasicInformationProposalController::proposalUpdateWithPk()` → `doProposalUpdate()`）**不會**呼叫 `BiogMainMutationHandler::prepareProposalPayload()`，而是走這條 controller 自己的 `normalizePayloadForTable()`（`app/Http/Controllers/BasicInformationProposalController.php:490-508`，這裡本來就有 `BIOG_SOURCE_DATA`／`ASSOC_DATA` 的逐表特例，屬於既有慣例）。這與 ALTNAME_DATA 發現的情況同構：若只掛 v2 API 的 `prepareProposalPayload()`，legacy Blade 提案路徑會漏掉異體字落地替換，造成「同一份輸入，走 v2 API 提案會替換、走 legacy Blade 提案不會替換」的不一致。**已修正**：在 `normalizePayloadForTable()` 新增 `$table === 'BIOG_MAIN'` 分支，複製與 `prepareProposalPayload()`／`updateById()` 相同的「先替換分欄、再組 `c_name_chn`」邏輯（`BasicInformationController::update()` 1748 行本身也有一份更早、未替換的組字邏輯，但 `normalizePayloadForTable()` 在更後面執行、會用替換後的值覆蓋掉，順序上不構成問題）。這個掛鉤點沒有額外的 notice 需求（`doProposalUpdate()` 已經有「已提交修改提案，等待管理員審核」的 `flash(..., 'info')`，未疊加異體字專屬訊息，屬於接受的最小實作）。

新增／更新測試（`tests/Feature/ApiV2MutateTest.php`、`tests/Feature/BiogMainProposalTest.php`、`tests/Feature/BiogMainBasicInfoNameMergeTest.php`、`tests/Feature/BasicInformationUpdateTest.php`）：
- 更新一筆人物，姓氏或名字含「峯」：因 `c_strict_excluded=1`，儲存後 `c_surname_chn`/`c_mingzi_chn`/`c_name_chn` 都仍是「峯」，不被替換，回應／flash 不出現任何替換通知。
- 更新一筆人物，姓氏或名字含「淸」：儲存後三個欄位都變成「清」，且 `c_name_chn === c_surname_chn.c_mingzi_chn` 成立；v2 API 回應含 `notices`（提及「淸」→「清」），legacy Blade 直接儲存路徑則觸發對應的 `flash(..., 'info')`。
- Proposal 模式（v2 API）同上，驗證 `prepareProposalPayload()` 產出的 payload，以及 proposal 回應的 `notices`。
- Proposal 模式（legacy Blade，`action=proposal`）：驗證 `normalizePayloadForTable()` 的替換結果同樣進了 `operations.resource_data`，且與 v2 API 提案結果一致（同一份輸入不會因為走哪條路徑而有不同落地結果）。
- 4 個既有測試檔（`ApiV2MutateTest`、`BiogMainProposalTest`、`BiogMainBasicInfoNameMergeTest`、`BasicInformationUpdateTest`）原本手動建表的 setUp 都需要補上 `char_variant_map` 表與 7 筆種子資料（比照 migration），否則 `updateById()`／`normalizePayloadForTable()` 查詢該表會因表不存在而整批噴錯。

### 步驟 4：BIOG_MAIN create 路徑改接

依「待決事項 2」的決定，在 `BiogMainRepository::store()`（`app/Repositories/BiogMainRepository.php:353-365`）內 `$data = $request->all()` 之後、`auto_pinyin($data)` 之前，新增替換邏輯，依「待決事項 2」修正後的規則分兩種情況：`c_surname_chn`／`c_mingzi_chn` 兩鍵**任一個**存在於請求裡（`array_key_exists()`，不是判斷空字串）時，替換兩個分欄並無條件重組 `c_name_chn`；兩鍵都不存在時，只對 `c_name_chn` 本身做替換，不組字、不覆寫。**`store()` 回傳形狀需要跟著調整，才能把 `replaced` 傳給呼叫端**：`store()`（`app/Repositories/BiogMainRepository.php:353-379`）目前回傳 `DB::transaction()` 內建立的 `BiogMain` model 實例（`$flight`），沒有現成通道夾帶額外資訊。**決定**：把回傳值改成 `['model' => $flight, 'replaced' => $replaced]`（陣列，取代單一 model 實例），並同步修改兩個既有呼叫端解構這個新形狀：
- v2 API：`BiogMainCreateHandler::handle()`（`app/Services/Mutations/BiogMainCreateHandler.php:151`，`$flight = $this->biogMainRepository->store($proxy);`）——改為 `['model' => $flight, 'replaced' => $replaced] = $this->biogMainRepository->store($proxy);`，後續 156 行 `Schema::hasTable(...)` 與 `reindexPerson($flight)` 改用解構出的 `$flight`；`replaced` 併入 JSON 回應的 `notices` 欄位。
- Legacy Blade：`BasicInformationController::store()`（同檔案 1605 行，`$flight = $this->biogMainRepository->store($request);`）——同樣改為解構寫法，`replaced` 非空時呼叫 `flash($msg, 'info')`。

這是本步驟範圍內的必要連鎖修改（`store()` 只有這兩個呼叫端，已於前面「呼叫點串接方向」與本文件盤點過，沒有第三個呼叫端），不需要另外調查。

新增／更新測試（`tests/Feature/ApiV2CreateBiogMainTest.php`、`tests/Unit/BiogMainRepositoryTest.php`）：
- 新增人物，姓氏或名字含「峯」：不被替換。
- 新增人物，姓氏或名字含「淸」：`c_surname_chn`/`c_mingzi_chn`/`c_name_chn` 皆被替換成「清」。
- 新增人物，前端刻意送一個與「替換後分欄組字結果」不同的 `c_name_chn`：驗證後端仍以重組結果為準（覆蓋前端送來的值），確認「無條件重組」的決定確實生效。
- Legacy Blade 路徑（`BasicInformationController::store()`）**非眾包使用者**分支走同一個 `BiogMainRepository::store()`（`app/Http/Controllers/BasicInformationController.php:1603-1605`），不需要另開測試，但需在 review 時確認沒有在 `store()` 之外另有一份重複的組字邏輯繞過這個掛鉤點。

  **已知缺口（本階段不處理，僅記錄）**：`BasicInformationController::store()` 對**眾包使用者**（`Auth::user()->isCrowdsourcingUser()`）有獨立分支（同檔案 1596-1602 行）——這條分支完全不呼叫 `BiogMainRepository::store()`，只呼叫 `operationRepository->store(..., 'BIOG_MAIN', ..., $data, '', 2)` 把整包 `$data` 記成一筆待審 operation（`c_status=2` 眾包待審），從未寫入 `BIOG_MAIN` 本身。也就是說眾包使用者新增人物時，本步驟新增的異體字落地替換邏輯**完全不會被觸發**——這批資料要等到管理員審核／回填時才可能落地（回填路徑不在本次盤點範圍內，需要另外確認回填時是否經過 `store()` 或其他路徑）。這是本階段刻意不處理的已知缺口，不在本次「BIOG_MAIN create 路徑」的驗收範圍內，若日後要補齊，需要另外盤點眾包審核回填的實際寫入路徑再評估掛鉤點。

### 步驟 5：ALTNAME_DATA create／update 路徑改接

依「待決事項 3」，在 `AltnameCreateHandler::preprocessCreateData()`（`app/Services/Mutations/AltnameCreateHandler.php:61-71`）與 `AltnameMutationHandler::preprocessUpdateData()`（`app/Services/Mutations/AltnameMutationHandler.php:61-74`）內，對 `data['c_alt_name_chn']`（若存在）呼叫 `CharVariantMapService::replaceStrict()`。

**同時處理「待決事項 3」修正段落記錄的第二條寫入路徑**：`BiogMainRepository::altnameStoreById()`（`app/Repositories/BiogMainRepository.php:3558-3597`，`BasicInformationAltnamesController::store()` 呼叫）與 `BiogMainRepository::altnameUpdateById()`（`BasicInformationAltnamesController::update()` 呼叫）也要各自對 `c_alt_name_chn` 呼叫 `CharVariantMapService::replaceStrict()`——`altnameStoreById()` 掛鉤點在 3564 行 `BracketNormalizer::normalizeAltname($data)` 之後、3565 行重複檢查 `DB::table('ALTNAME_DATA')->where(...)` 之前（讓重複檢查看到的是替換後的值，避免替換前後各自查出不同的重複判定結果）；`altnameUpdateById()` 的掛鉤點在組出 `$newPk3` 的邏輯（`app/Repositories/BiogMainRepository.php:3611-3615`）之前——該方法 3604 行取得 `$data = $request->all()`，3608 行 `BracketNormalizer::normalizeAltname($data)` 之後即可插入替換呼叫，早於 3611 行組 `$newPk3` 與 3616-3625 行的衝突檢查（`if ($newPk3 !== $pk) { ... 查 ALTNAME_DATA 是否已有相同 3-key ... }`），確保衝突檢查用的是替換後的值。

依「待決事項 5」，四個掛鉤點（v2 API 的 create／update、legacy Blade 的 create／update）都要把 `CharVariantMapService::replaceStrict()` 回傳的 `replaced` 接上對應通知通道：v2 API 回應加 `notices`（實作時發現 `AltnameCreateHandler`/`AltnameMutationHandler` 繼承的抽象基底類別把回應結構固定寫在 `handleDirect()`/`handleProposal()` 內，子類無法在建構時插入額外欄位，因此新增 `CharVariantMapService::withNotices(JsonResponse, array): JsonResponse` 共用方法，對已組好的 `JsonResponse` 事後 decode／重新包裝加入 `notices`，僅在狀態碼 200 時套用，避免把 409 等錯誤回應也誤標成功替換）；legacy Blade 的 `BasicInformationAltnamesController::store()`／`update()` 比照既有 `flash(..., 'success'/'error')` 慣例（85-153、224-296 行附近）補上 `flash($msg, 'info')`；`AltnameEditor.tsx` 讀取 `notices` 並比照既有 `message`/`flashSaved()`（68-69 行）顯示非阻塞提示。

**實作時發現的第四個掛鉤點（原計畫未列出）**：`BasicInformationAltnamesController` 除了 resource 路由的 `update()` 外，還有一個獨立的查詢參數模式方法 `updateQuery()`（`routes/web.php:169-170` 註冊為 `basicinformation.altnames.update.query`，供 `/basicinformation/{id}/altnames/update?c_personid=...&c_alt_name_chn=...` 這種以 query string 帶 PK 的請求使用），內部同樣呼叫 `BiogMainRepository::altnameUpdateById()`，是與 `update()` 平行的第三個呼叫端。**修正前的既有邏輯還有一個獨立於本計畫的潛在 bug**：`update()`／`updateQuery()` 在更新後決定要不要同步 `CBDB__NAME_FTS` 搜尋索引時，原本都重新讀取 `$request->all()`（替換前的原始輸入）來判斷「名字是否變更」與「該索引哪個值」，而不是用 `altnameUpdateById()` 回傳的 `$newPk`（已含落地替換後的值）——這代表即使資料庫裡的 `ALTNAME_DATA.c_alt_name_chn` 已經正確替換，搜尋索引仍可能被建成替換前的舊字形，兩者不一致。**已一併修正**：兩個方法的索引同步邏輯改用 `$newPk['c_alt_name_chn']`/`$newPk['c_alt_name_type_code']`（信任 repository 回傳的權威值），不再重新讀取 `$request->all()`；`update()` 額外透過 `$request->input('c_alt_name_chn')` 對提交值單獨呼叫一次 `CharVariantMapService::replaceStrict()`（快取命中、無額外查表成本）取得 `replaced` 供 flash 訊息使用（`altnameUpdateById()`／`altnameStoreById()` 的既有回傳形狀受 `CompositePrimaryKey::buildUrl()` 以 `http_build_query()` 組 URL 查詢字串的限制，不能塞入陣列型別的 `replaced` 值，否則會把陣列序列化進 URL；`altnameStoreById()` 的回傳值不受此限制，改為直接在回傳陣列裡多帶一個 `__variant_replaced` key，於 `insert()` 呼叫**之後**才合併進去，避免被誤寫進資料表本身）。

新增／更新測試：
- v2 API（`tests/Feature/ApiV2CreateAltnameTest.php`、`tests/Feature/ApiV2MutateAltnameTest.php`）：
  - 新增別名「峯□」：不被替換。
  - 新增別名「淸□」：被替換成「清□」，回應含 `notices`。
  - 更新別名的 `c_alt_name_chn` 為含「淸」的新值：確認替換後的值成為新 PK 的一部分，回應含 `notices`。
  - 更新別名時，替換後的新 `c_alt_name_chn`+`c_alt_name_type_code` 與同一人物下另一筆既有別名衝突：確認既有的 PK 衝突偵測（`AltnameMutationHandler::performUpdate()` 79-95 行）仍正確觸發（用替換後的值判斷衝突，而非替換前的原始輸入）。
- Legacy Blade（`tests/Feature/BasicInformationAltnamesControllerTest.php`，涵蓋 `store()`／`update()`／`updateQuery()` 三個方法）：
  - 透過 `store()`／`updateQuery()` 新增／更新含「淸」的別名，確認 `ALTNAME_DATA` 落地的是替換後的值（而非 v2 API 才會替換、legacy Blade 路徑仍是原字），且觸發對應 `flash(..., 'info')`。這條測試特別驗證「待決事項 3」修正段落指出的問題已解決：同一份輸入，走 legacy Blade 與走 v2 API，`ALTNAME_DATA` 最終落地的 `c_alt_name_chn` 必須一致（都已替換），不能一個換一個沒換。
  - 透過 `updateQuery()` 更新別名為含「淸」的新值，且與同人物下另一筆既有別名（已是替換後的字形）衝突：確認既有的括號衝突偵測邏輯用替換後的值判斷、正確擋下並回 flash 錯誤。

### 步驟 6：`config/codes.php` 註冊（Codes UI 可增修）

在 `config/codes.php` 的 `'tables'` 陣列加入：
```php
'char_variant_map' => '異體字落地替換對照表',
```
新增／更新測試（`tests/Feature/CodesControllerAuditTest.php` 或新建對應測試）：
- 透過 `/app/codes/char_variant_map` UI 新增一筆對照，確認寫入 `audit_log`（比照現行 `CodesController` 對其他表的既有稽核測試模式，不需要新寫稽核邏輯，只需確認註冊後既有機制對這張新表也生效）。
- 確認 `/app/codes/char_variant_map` 列表頁可正常顯示 7 筆種子資料。

### 步驟 7：受 token API 的機器化寫入管道註冊

在 `config/code_table_writes.php` 的 `'tables'` 加入：
```php
'char_variant_map' => [
    'resource' => 'char-variant-map',
    'aliases' => ['char-variant-map', 'char_variant_map', 'charvariantmap'],
    'table' => 'char_variant_map',
    'display_name' => '異體字落地替換對照表',
    'key_column' => 'id',
    'auto_assign_id' => true,
    'allowed_fields' => ['c_variant_char', 'c_reference_char', 'c_strict_excluded', 'c_notes'],
],
```
在 `config/code_table_mutations.php` 加入對應 update 定義：
```php
[
    'resource' => 'char_variant_map',
    'table' => 'char_variant_map',
    'aliases' => ['char_variant_map', 'char-variant-map', 'charvariantmap'],
    'display_name' => '異體字落地替換對照',
    'key_columns' => ['id'],
    'allowed_fields' => ['c_variant_char', 'c_reference_char', 'c_strict_excluded', 'c_notes'],
    'tier1_fields' => [],
    'tier2_fields' => ['c_variant_char', 'c_reference_char', 'c_strict_excluded', 'c_notes'],
],
```
**`aliases` 須與 `code_table_writes.php` 的 create/delete 定義同步**：`code_table_writes.php` 側的 canonical resource 是 `char-variant-map`（連字號），`aliases` 含 `char-variant-map`／`char_variant_map`／`charvariantmap` 三種寫法；`code_table_mutations.php` 側（update）若只登記 `char_variant_map` 一種別名，會讓「用 create 那個 resource 字串打 update」的呼叫方拿到 501（`MutationController` 只做小寫正規化，不做連字號／底線互轉）。因此上面的 `aliases` 三種寫法都要列，與 create/delete 定義完全對齊，不能各自只挑一種。

**Step 7 還需要同步登記主鍵，否則 update API 會失敗**：`ConfigCodeTableMutationHandler` 走 `AbstractCodeTableMutationHandler::handle()`，內部會呼叫 `CompositePrimaryKey::validateOrFail($targetPk, $table)`（`app/Support/CompositePrimaryKey.php`），要求該表已在 `CompositePrimaryKey::SCHEMAS` 登錄、且欄位與 `config/code_table_mutations.php` 的 `key_columns` 完全一致。因此除了上面兩個 config，本步驟還要新增：
```php
// app/Support/CompositePrimaryKey.php SCHEMAS 陣列
'CHAR_VARIANT_MAP' => [
    'id',
],
```
```php
// app/Http/Controllers/OperationsController.php resourceKeyColumns()
'CHAR_VARIANT_MAP' => ['id'],
```
（比照既有 `TEXT_CODES`／`NIAN_HAO` 等 Phase B code 表的登錄方式，三處主鍵定義——`config/code_table_mutations.php` 的 `key_columns`、`CompositePrimaryKey::SCHEMAS`、`OperationsController::resourceKeyColumns()`——欄位順序須完全一致，這是 `AGENTS.md`「複合主鍵」一節與既有多張 Phase B 表共同遵守的規則。）

**`tier1_fields`/`tier2_fields` 分派說明**：`config/code_table_mutations.php` 的 docblock 明訂 `tier1_fields ∪ tier2_fields` 必須等於 `allowed_fields`（見該檔案第 17 行），且這組欄位是為 §D-6 拼音 v→ü 歸一化設計——tier1 = 保存時後端靜默做 v→ü 轉換的純拼音欄，tier2 = 可能混西文、後端不靜默轉、由前端彈窗讓使用者決定的欄。`char_variant_map` 的 4 個欄位全部**都不是**拼音／羅馬字欄（`c_variant_char`/`c_reference_char` 是漢字、`c_strict_excluded` 是整數、`c_notes` 是自由文字），嚴格來說沒有一個真正符合 tier1 或 tier2 原始設計的欄位語意。**決定**：全部 4 欄位放進 `tier2_fields`（而非 `tier1_fields`），理由：
  - tier1 語意是「後端保存時靜默套用 v→ü 轉換規則」，對非拼音欄套用是語意錯誤，且有極小機率誤傷（例如 `c_notes` 若剛好寫入含小寫 `v` 的文字，靜默轉換可能不是使用者原意）；tier2 語意是「不靜默轉、只在偵測到 v→ü pattern 時彈窗讓使用者選擇」——對這 4 個欄位而言，由於內容本來就不含拼音 pattern，彈窗實務上幾乎不會觸發，是更安全的預設。
  - 滿足該檔案宣告的 `tier1_fields ∪ tier2_fields = allowed_fields` 硬性 invariant，不需要新增例外分支。
  - 對 `php artisan cbdb:migrate-code-pinyin-v --tables=all` 批次遷移工具的影響侷限且可預期：以 `--tier=tier1`（或未指定 `--tier` 時的預設批次）執行時，`columnsForTier($def, 'tier1')` 對 `char_variant_map` 回傳空陣列，指令會 `continue` 跳過（見 `app/Console/Commands/MigrateCodeTablePinyinV.php:72-75`），完全不產生報告項目。但明確以 `--tier=tier2` 或 `--tier=all` 執行時，**確實會**掃描到這 4 個非拼音欄——目前種子資料 7 筆 `c_notes` 裡有 5 筆分別引用了 `VariantCharNormalizer`／`TITLE_VARIANT_MAP` 這類含大寫 `V` 的類別/常數名稱，會被 `CodeTablePinyinScanner`（`app/Services/Pinyin/CodeTablePinyinScanner.php:34-61`）的 `LIKE '%v%'` 預篩選中，經 `PinyinUmlaut::normalize()` 判定不符合拼音轉換規則後歸類進 `otherVs`（安全網清單，供人眼確認，非資料異動候選）。**這不是本表真的有內容跑歷史留存 v→ü 轉換需求，只是變數/類別名稱字面剛好含 `V`**——預期 `mutations`（實際會被改寫的候選）恆為 0 筆，但 `otherVs`（需人工過目、確認無需轉換）不會是 0，執行 `--tables=all` 的人需要知道這是預期內的雜訊，不是本表資料有問題。

新增測試（仿 `tests/Feature/ApiV2MutateCodeTableTextCodesTest.php` 對 `TEXT_CODES` 的測試結構，改為對 `char_variant_map`）：
- `/api/v2/*` create／update 可正常運作，並各自寫入 `operations` + `audit_log`。**delete「成功」不在測試範圍內，但需要補一則 delete 恆回 403 的負向回歸測試**：`CodeTableDeleteHandler::handle()`（`app/Services/Mutations/CodeTableDeleteHandler.php:44-49`）目前對**所有**已註冊的 code 表一律回傳 403（「代碼表刪除已停用（防止級聯刪除人物資料）」），這是系統性的既有安全閘門，不是針對特定表的開關，`char_variant_map` 註冊進 `code_table_writes.php` 後同樣會被這道閘門擋下、與其他表行為一致。因此本表的 token API 事實上只開放 create／update，delete 端點存在但恆 403——測試要驗證「delete 回 403」這個負向行為（確認閘門對新表同樣生效），而不是嘗試驗證「delete 成功」，且這是預期行為，不需要（也不應該）為了讓 char_variant_map 可刪除而去鬆綁這道全域閘門。
- 驗證 create 時 `c_variant_char` 唯一鍵衝突會被正確擋下並回傳合理錯誤（而非資料庫層 500）。

## 風險與待決事項（延續自前一份文件、本階段新增）

- **`CharVariantMapService` 的行程內快取（per-request static cache）在同一個 PHP-FPM worker 跨請求間可能殘留舊資料**：與 `VariantCharNormalizer::$loaded` 的既有模式相同（該類別也是行程內快取、從未過期），這是專案既有慣例、非本次新增風險，但因為 `char_variant_map` 未來會透過 Codes UI 被管理員頻繁增修（不像 `VariantCharNormalizer::$fallbackMap` 是寫死陣列），快取陳舊的實際影響面更大：管理員在 Codes UI 新增一筆對照後，同一個長駐 worker 處理的下一個請求可能仍讀到舊快取，直到該 worker 重啟或收到新的 PHP 請求觸發類別重新載入（若部署方式是 opcache + 短生命週期 worker，此問題輕微；若是常駐 worker，需要額外評估）。**本階段先比照 `VariantCharNormalizer` 的既有慣例、不特別處理**；如果日後發現快取陳舊造成實際問題，可另外評估在 `CodesController` 的 `char_variant_map` update／destroy 路徑呼叫 `CharVariantMapService::reset()`，或改用 request-scoped 而非行程內快取。
- **步驟 2（TITLE_VARIANT_MAP 改接）是行為擴張，不是純重構**：寬鬆模式套用全部 7 筆而非原本的 3 筆，代表「淸→清」「厰→廠」「愼→慎」「槀→稿」這 4 筆從此也會改寫書名。這是本計畫從一開始就設計好的目標（見前一份文件「這次的重大改動」一節），但仍需在 review／測試時明確驗證這個擴張是預期行為、不是意外副作用。
- **步驟 3／4（BIOG_MAIN）是全新行為，非重構**：目前完全沒有對人物姓名做過任何異體字落地替換，上線後任何既有人物若透過 update 觸碰到姓名欄位、或任何新建人物姓名含這 6 個嚴格模式字元，會被靜默改寫。需要在 PR 描述中明確標註這是「新行為」而非「修 bug」，避免使用者誤以為是既有行為的意外改動。
- **既有測試不會因本次改動而假設舊行為失敗**：已搜尋現有測試對這 7 個異體字的引用，僅 `tests/Feature/AdminBatchLoadBookTitlesTest.php` 與 `tests/Unit/VariantCharNormalizerTest.php` 有相關斷言（分別對應步驟 2 的既有 3 筆書名替換行為、與 `VariantCharNormalizer` 本身，兩者皆不受本計畫影響或會依步驟 2 同步更新），BIOG_MAIN／ALTNAME_DATA 相關測試目前完全沒有針對這幾個字元的斷言，故不會有「舊測試斷言未替換、新程式碼替換了」的回歸衝突。
- **ALTNAME_DATA 有 legacy Blade／v2 API 兩條平行寫入路徑，只掛一條會造成兩版行為不一致**：見「待決事項 3」修正段落與步驟 5——`BasicInformationAltnamesController::store()`／`update()` 直接呼叫 `BiogMainRepository::altnameStoreById()`／`altnameUpdateById()`，完全不經過 `AltnameCreateHandler`／`AltnameMutationHandler`。若只改 v2 handler、漏掉這兩個 legacy Blade 呼叫的 repository 方法，會出現「新版 React 頁面存別名會落地替換、舊版 Blade 頁面存同一筆別名卻不會」的不一致，且因為 `AGENTS.md` 允許把頁面 flag 改回 `old` 觸發回退，這個不一致在 flag 切換情境下會實際發生，不是純理論風險。
- **BIOG_MAIN 的 legacy Blade 提案路徑是第三個需要掛鉤的地方，原計畫未列出**：見步驟 3 實作時發現的段落——`action=proposal` 的 legacy Blade 路徑不經過 `BiogMainMutationHandler::prepareProposalPayload()`，而是走 `BasicInformationProposalController::normalizePayloadForTable()` 自己的邏輯。與 ALTNAME_DATA 的情況同構，已在實作時發現並補上，記錄於此供之後盤點類似「一個資源、多條寫入路徑」的模式時參考——BIOG_MAIN／ALTNAME_DATA 這兩張表目前都至少有 v2 API 與 legacy Blade 兩條路徑，且兩條路徑的程式碼是各自獨立實作、不共用同一份組字/替換邏輯，這是這次盤點過程中反覆出現的模式，不是單一個案。
- **落地替換通知機制（待決事項 5）新增了兩個既有函式的回傳形狀變更，屬於本階段內部的連鎖修改**：`BiogMainRepository::store()` 從回傳單一 `BiogMain` model 改為回傳 `['model' => ..., 'replaced' => ...]` 陣列，兩個既有呼叫端（`BiogMainCreateHandler::handle()`、`BasicInformationController::store()`）都要同步改寫解構方式；若只改 `store()` 內部邏輯、漏改其中一個呼叫端，會直接造成該呼叫端把整個陣列當成 model 使用而壞掉（例如 `Schema::hasTable(...)` 判斷後對陣列呼叫 `reindexPerson($flight)` 會因型別不符而報錯）。這不是「錦上添花可以晚點做」的項目，而是步驟 4 沒做完就會直接讓既有功能壞掉的變更，實作與 review 時需要當作同一個原子變更檢查，不能只驗證新邏輯、忽略舊呼叫端有沒有同步更新。
- **ALTNAME_DATA legacy Blade 端有第四個掛鉤點（`updateQuery()`），且既有索引同步邏輯有一個獨立於本計畫的既存 bug**：見步驟 5「實作時發現的第四個掛鉤點」段落——`update()`／`updateQuery()` 原本都用替換前的 `$request->all()` 判斷是否要同步 `CBDB__NAME_FTS` 搜尋索引與該索引哪個字形，這與落地替換無關、原本就存在（即使沒有這次的異體字功能，任何未來對 `c_alt_name_chn` 的正規化都會踩到同一個問題），只是這次新增落地替換後才讓「索引字形與資料庫實際字形不一致」這個既存缺陷變得更容易被觀察到。已隨手修正（改用 `altnameUpdateById()` 回傳的 `$newPk` 而非重新讀取請求），但這提醒之後若還有類似「controller 重新讀取原始 request 而非信任 repository 回傳值」的模式，需要個案盤查，不能假設只有這兩處。
- **legacy Blade 提案更新路徑（`action=proposal`）在送出提案時不會預先檢查落地替換後的新 PK 是否已與既有別名衝突，v2 API 會**（codex review 於步驟 5 review 階段發現）：`BasicInformationProposalController::normalizePayloadForTable()` 對 `c_alt_name_chn` 做替換後直接記錄提案，不像 `AltnameMutationHandler::performUpdate()` 那樣在送出當下就查詢衝突並回 409。實際影響有限——`OperationsProposalController` 在**核准**提案時仍會查重複並擋下（`資料已存在，無法再次新增`／衝突偵測），不會造成資料損毀，只是使用者要等到管理員核准時才會被告知衝突，而非提交當下。這是 legacy Blade 提案路徑本身既有的、獨立於落地替換功能的行為落差（任何會改變 PK 的提案式更新都有這個問題，不是本次新增的缺陷），本階段不處理，記錄於此供之後盤點「legacy 提案路徑補送出時衝突檢查」時參考。

## 不在本次範圍內

- `VariantCharNormalizer` 類別本身的刪除清理（見前一份文件「不在本次範圍內」，依賴條件已滿足但屬於獨立任務）。
- `CBDB__TRAD_SIMP_MAP` 繁簡轉換機制（與本表功能層次不同，見前一份文件）。
- 對既有資料庫裡「已經含有這些異體字」的既有人物/書名記錄做批次回溯更新——本階段只處理「往後新增/修改時」的落地替換，不做歷史資料的批次校正（批次校正若有需要，屬於另一個獨立任務，且需要更謹慎的影響評估，不應該隨這次呼叫點串接一併做）。
