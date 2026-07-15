# 異體字對照表 Work Plan：新增 `char_variant_map`

> English version: [CHAR_VARIANT_MAP_CONSOLIDATION_PLAN.en.md](./CHAR_VARIANT_MAP_CONSOLIDATION_PLAN.en.md)

> **狀態：規劃中**。本文件目前只涵蓋新表的 schema 設計、migration 設計與現有 7 筆資料的遷移方式；「呼叫點串接方向」一節描述的是**未來**要把 `AdminBatchLoadBookTitlesController::TITLE_VARIANT_MAP` 與 BIOG_MAIN／ALTNAME_DATA 人名相關寫入路徑改接這張表的方向，尚未實作，將於後續另開任務執行。

## 背景與目標

目前專案裡有兩套完全獨立、互不相通的「異體字對照」映射，資料共 10 筆：

1. **`VariantCharNormalizer::$fallbackMap`**（7 筆，`app/Services/VariantCharNormalizer.php:31-39`）——用於拼音生成前的臨時正規化，**不修改**原始資料（書名、人名等保持不變），只影響拼音查詢結果。呼叫點：`BiogMainRepository::auto_pinyin()`、`ApiController::buildPinyinWord()`、`AdminBatchLoadBookTitlesController::buildPinyin()`/`collectUnpinyinableHan()`。
2. **`AdminBatchLoadBookTitlesController::TITLE_VARIANT_MAP`**（3 筆，`app/Http/Controllers/AdminBatchLoadBookTitlesController.php:26-30`）——用於批次匯入書名時，**直接改寫存入資料庫的書名本身**（`TEXT_CODES.c_title_chn`），在 `parseEntries()`（同檔案 390-398 行）呼叫 `standardizeTitleVariants()` 一次性套用。

`VariantCharNormalizer::ensureLoaded()`（同檔案 65-74 行）留有 `$variantMap`（可從資料庫載入的版本）欄位，但從未接上任何資料表，方法內只是設 `$loaded = true` 就直接 return——是個「準備要接但沒接上」的半成品掛鉤。

**本計畫的範圍**：`char_variant_map` 只處理**落地替換**這一件事——把現行只服務書名匯入一個入口的 `TITLE_VARIANT_MAP`，擴充成當前所有表的錄入端（不限人名、書名）都能查詢的通用異體字落地替換對照。`VariantCharNormalizer::$fallbackMap` 所服務的拼音生成需求不透過本表處理，改為直接在既有的 `pinyin` 表裡為對應的異體字新增讀音資料——一個字若已在 `pinyin` 表裡有正確讀音，`VariantCharNormalizer::normalize()` 這層轉換即可省略，不需要額外的間接查表。`pinyin` 表目前已補齊 `$fallbackMap` 這 7 個字各自的讀音（見 `/app/codes/pinyin`），這部分屬於 `pinyin` 表的資料維護工作，是與本計畫相關但獨立的另一項任務，不在本文件範圍內；`VariantCharNormalizer` 類別本身的後續清理見下方「不在本次範圍內」。

**與 `CBDB__TRAD_SIMP_MAP`（繁簡轉換）的關係**：`CBDB__TRAD_SIMP_MAP` 的功能是**檢索比對**——讓同一人名同時以繁簡兩種寫法被索引到，供姓名搜尋使用，跟本表的**資料落地替換**是不同層次的功能，兩者不合併（見下方「不在本次範圍內」）。

**這次的重大改動**：新表要能讓當前所有表的錄入端都能查詢同一份異體字落地替換對照。一筆對照能不能被替換，分兩種場合判斷：
- **寬鬆模式**（書名、職官、機構名等一般場合）：只要這個異體字在表裡有一列，就可以替換——本表不收錄「完全不可替換」的字，那種字直接不進表（只在 `pinyin` 表處理拼音需求）。
- **嚴格模式**（目前定義為 BIOG_MAIN〔人物本名〕與 ALTNAME_DATA〔人物別名〕的寫入路徑）：在寬鬆模式的基礎上，可能還要**額外排除**一批「嚴格模式才需要跳過」的記錄。

嚴格模式的可替換範圍是寬鬆模式的子集，只有一個是非題要回答：「這筆對照要不要也排除嚴格模式（人名）」，對應到欄位設計就是單一布林欄位 `c_strict_excluded`。詳見下方「欄位設計」。

## 新表設計

### 表名：`char_variant_map`

延續近期新表（`pinyin`、`audit_log`、`operations`、`nl_query_logs`）採用的 Laravel 風格全小寫 `snake_case` 命名，不使用 `CBDB__` 前綴——`CBDB__` 前綴依 `.claude/skills/database-schema.md` 的既有定義，代表「非原始 CBDB schema、專案自建的內部輔助表」且慣例上在 `/codes` 為唯讀（如 `CBDB__TRAD_SIMP_MAP`）。這張表不是唯讀參考資料，而是要開放管理員透過既有 Codes CRUD 新增/編輯（比照 `pinyin` 表現行做法），所以採用與 `pinyin` 一致的命名與欄位風格。

「variant」一詞仍準確描述表內每一筆對照——例如「峯→峰」這種同字異形關係，本質上都是「異體字」，也與程式碼裡既有的 `VariantCharNormalizer` 類名一致，是既有詞彙的自然延伸。

### 欄位設計

比照 CBDB 慣例：資料欄位一律加 `c_` 前綴；`id` 為純技術性自增主鍵，不加前綴。

| 欄位 | 型別 | 約束 | 說明 |
|---|---|---|---|
| `id` | `bigIncrements` | PK | 技術性自增主鍵 |
| `c_variant_char` | `varchar(10)` | `NOT NULL`，唯一鍵 | 原字（異體字），例如「峯」 |
| `c_reference_char` | `varchar(10)` | `NOT NULL` | 參考字（正規化目標字），例如「峰」。**命名刻意避免用「標準字」／「正字」**：這幾筆對照裡並非每一組都是「錯字→對字」的關係，例如「峯→峰」，峯本身也是合法人名用字，不代表峯是異體/錯誤寫法，只是這張表選定「峰」作為需要落地替換時的目標字。因此本表的語意是「替換時歸一到哪個字」，不是「哪個字才正確」 |
| `c_strict_excluded` | `tinyInteger` | `NOT NULL DEFAULT 1` | 這筆對照**是否排除於嚴格模式**（BIOG_MAIN 人物本名、ALTNAME_DATA 人物別名）。**1**（預設）= 只在寬鬆模式（書名、職官、機構名等）可替換，嚴格模式排除。**0** = 寬鬆與嚴格模式皆可替換，需要**明確核實**這個字在人名裡也適合被強制改寫才設為 0。新增一筆對照時，多數情況是為了解決書名等一般場合的寫法統一，不必然代表也適合套用到人名——預設 1 讓「連人名都能替換」變成需要人工確認後才 opt-in 的動作。**欄位命名刻意不寫死「person_name」**：目前「嚴格模式」只涵蓋人名相關資料，但未來可能有其他場合也需要納入同一套排除規則，屆時只需在對應寫入路徑加上同樣的查詢條件，不需要改欄位名或 schema |
| `c_notes` | `varchar(255)` | `NULLABLE` | 備註，例如排除原因 |
| `created_at` / `updated_at` | `timestamps` | — | Laravel 預設時間戳（比照 `CBDB__NAME_FTS`、`audit_log` 等新表慣例，不使用 `c_created_by`/`c_created_date` 這組僅用於原始 CBDB 表的欄位） |

**索引**：`c_variant_char` 唯一鍵（`char_variant_map_c_variant_char_unique`）——每個異體字只對應一個參考字，也讓查詢（依原字找參考字）可以直接用主鍵式唯一鍵，不需要額外索引。

**為什麼不用 `CBDB__TRAD_SIMP_MAP` 的 `VARBINARY(4)` 設計**：那個設計是為了繞過「MySQL 8.0 對 utf8mb4 非 BMP 字符索引」的已知 bug（見 `2025_11_13_000000_create_internal_name_search_tables.php:35-37` 註解）。嚴謹來說該 bug 描述的是 utf8mb4 4-byte（非 BMP）字符的索引問題，不一定僅限於 PRIMARY KEY，`c_variant_char` 若未來收錄到超出 BMP 的罕見異體字，理論上唯一鍵也可能受影響；但本專案生產環境是 **MariaDB 10.3**（見 `AGENTS.md`），不是 MySQL 8.0，該 bug 的前提本身在本專案不成立，且 `pinyin` 表已用 `varchar(10)` + 唯一鍵在同一套 MariaDB 10.3 環境穩定運作多時，因此不採用 `VARBINARY` 設計。

**`c_variant_char`/`c_reference_char` 為何用 `varchar(10)` 而非更緊湊的長度**：實際資料都是單一 CJK 字元（`varchar(n)` 在 MySQL/MariaDB 中的 `n` 是字元數，非位元組數；utf8mb4 下每字元最多 4 bytes），`varchar(10)` 只是比照 `pinyin` 表既有的 `c_chn varchar(10)` 慣例留出安全餘裕（例如未來若需容納變體選擇符 variation selector 等組合字符），並非資料本身需要 10 字元，儲存成本可忽略不計。

### 為什麼 `c_strict_excluded` 是全域單一欄位、而非逐表例外清單

`c_strict_excluded` 的排除語意套用在**全域單一欄位**上，不做成如 `char_variant_map_exceptions`（`variant_map_id + table_name`）的多對多例外表。理由是目前需要排除的場景就是「人名相關資料」這一組（BIOG_MAIN + ALTNAME_DATA，兩者共用同一個排除語意，本來就綁在一起判斷），做成通用多對多例外表在目前只有這一組例外的情況下是過度設計。**已知限制**：若未來出現「BIOG_MAIN 要排除、ALTNAME_DATA 不排除」這種把人名相關資料進一步拆開的衝突情境，或是「某個非人名表也想排除、但另一個非人名表不想排除」的情境，目前的欄位設計無法表達，屆時需要重新評估是否要拆成逐表例外表——本計畫不預先為這個假設情境做設計。

## Migration 設計

新增一個 migration（class-based，比照近期慣例，檔名 `2026_07_15_000000_create_char_variant_map_table.php`）：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void {
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            column_comment($table->string('c_variant_char', 10), '原字（異體字）');
            column_comment($table->string('c_reference_char', 10), '參考字（落地替換時歸一到的目標字）');
            column_comment($table->tinyInteger('c_strict_excluded')->default(1), '是否排除於嚴格模式（BIOG_MAIN／ALTNAME_DATA 人名）；1=僅寬鬆模式可替換，0=兩種模式皆可替換');
            column_comment($table->string('c_notes', 255)->nullable(), '備註，例如排除原因');
            $table->timestamps();

            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });

        // 種子資料（7 筆），完整理由見下方「現有資料遷移」一節。
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '愼', 'c_reference_char' => '慎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '槀', 'c_reference_char' => '稿', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
            ['c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0],
            ['c_variant_char' => '頴', 'c_reference_char' => '穎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
            ['c_variant_char' => '厰', 'c_reference_char' => '廠', 'c_strict_excluded' => 0],
        ]);
    }

    public function down(): void {
        Schema::dropIfExists('char_variant_map');
    }
};
```

**MySQL/SQLite 相容性**：全程使用 Laravel Schema Builder（`string`/`tinyInteger`/`unique`/`timestamps`），不含 `ENGINE` 等資料庫專屬語法。欄位註解透過 `database/migrations/helpers.php` 既有的 `column_comment()` helper 加上，而非直接呼叫 `->comment()`——`column_comment()` 內部僅在 `is_mysql()` 時才套用 `->comment()`，SQLite 環境會靜默略過，兩邊都不需要額外分支即可安全呼叫。這與近期 `audit_log`、`person_change_index` 兩張新表的 migration 慣例一致，讓 schema 在 MySQL 端本身就有可讀的欄位說明。

**`down()` 不做安全閘門檢查**：這是一張全新表（不像 `pinyin` 是既有表重整、需要考慮回滾時保留線上人工修正），且種子資料只有 7 筆、非大量匯入。若上線後管理員透過 Codes UI 新增/編輯了更多筆，`migrate:rollback` 會連同這些人工資料一併刪除——這點與 `pinyin` 表資料 migration 刻意加上內容指紋閘門的情境不同（`pinyin` 是把 6910 筆字典資料一次性塞入既有表，需要防止誤刪；這裡是建立一張全新表，回滾整張表刪除是可預期、可接受的行為）。若未來認為需要保護，可以另外評估，非本次必要項。

## 現有資料遷移（7 筆種子資料）

原 `VariantCharNormalizer::$fallbackMap` 的 7 筆裡，只有「愼→慎」與「槀→稿」有落地替換需求，納入本表；其餘 5 筆（菴、攷、嶽、註、于）只在 `pinyin` 表新增讀音資料即可，不需要進 `char_variant_map`。另外新增「淸→清」與「厰→廠」兩筆，屬於全新收錄的異體字對照，不對應現行任何舊機制。

| c_variant_char | c_reference_char | c_strict_excluded | c_notes |
|---|---|---|---|
| 愼 | 慎 | 0 | 原 VariantCharNormalizer::$fallbackMap；愼/慎無歧義風險，可安全落地替換於任何場合，含人名 |
| 槀 | 稿 | 0 | 原 VariantCharNormalizer::$fallbackMap；槀/稿無歧義風險，可安全落地替換於任何場合，含人名 |
| 峯 | 峰 | 1 | 原 TITLE_VARIANT_MAP；書名等場合的落地替換可用，但 BIOG_MAIN（人物本名）與 ALTNAME_DATA（人物別名）場合的落地替換須排除，峯本身是合法人名用字，不應被強制改寫 |
| 靑 | 青 | 0 | 原 TITLE_VARIANT_MAP；靑/青無歧義風險，可安全落地替換於任何場合，含人名 |
| 頴 | 穎 | 0 | 原 TITLE_VARIANT_MAP；頴/穎無歧義風險，可安全落地替換於任何場合，含人名 |
| 淸 | 清 | 0 | 新增；淸/清無歧義風險，可安全落地替換於任何場合，含人名 |
| 厰 | 廠 | 0 | 新增；厰/廠無歧義風險，可安全落地替換於任何場合，含人名 |

種子資料直接寫在 migration 的 `up()` 內（`DB::table('char_variant_map')->insert([...])`），不需要像 `pinyin` 表整併那樣另外建立永久資料檔——7 筆資料量極小，寫死在 migration 內即可重現，且未來管理員可透過 Codes UI 直接增修，不依賴這份種子資料保持最新。

## 呼叫點串接方向（尚未實作，記錄未來方向）

以下描述**未來**要把 `TITLE_VARIANT_MAP` 與 BIOG_MAIN／ALTNAME_DATA 寫入路徑改接這張表的方向，本次僅完成表設計與 migration，不在本次一併變更程式碼：

1. **`AdminBatchLoadBookTitlesController::TITLE_VARIANT_MAP`／`standardizeTitleVariants()`**（同檔案 26-30、512-514 行）：改為查詢 `char_variant_map` 全表（目前全部 7 筆：愼、槀、峯、靑、頴、淸、厰）做 `strtr()` 落地替換。書名入口屬於「寬鬆模式」，`c_strict_excluded` 值不影響——只要在表裡就套用，這 7 筆（含峯→峰）都套用。

2. **BIOG_MAIN 人名寫入路徑**：實際盤點後，create 與 update 兩條路徑的現況並不對稱，實作階段需要分開處理：
   - **Update／proposal 路徑**：`BiogMainMutationHandler::prepareProposalPayload()`（`app/Services/Mutations/BiogMainMutationHandler.php:196-200`）與 `BiogMainRepository::updateById()`（`app/Repositories/BiogMainRepository.php:246`）都各自組合 `c_surname_chn`+`c_mingzi_chn` 成 `c_name_chn`，是明確的掛鉤點，查詢條件為 `c_strict_excluded = 0`（嚴格模式，目前對應 6 筆：愼、槀、靑、頴、淸、厰；「峯」因 `c_strict_excluded=1` 被排除）。
   - **Create 路徑**：`BiogMainCreateHandler.php` 本身沒有組字邏輯，只在欄位白名單列出 `c_name_chn`/`c_surname_chn`/`c_mingzi_chn`，實際委派給 `app/Repositories/BiogMainRepository.php:353-365` 的 `store(Request $request)`。`store()` 以 `$data = $request->all()` 起手，接著依序跑 `timestamp()`、`auto_pinyin($data)`、`BracketNormalizer::normalizeBiogMain()`、`PinyinUmlaut::normalizeFields()`（355-361 行）才 `BiogMain::create($data)`——**這些既有步驟都不會重新推導 `c_name_chn`**，換言之新建人物時 `c_name_chn` 全程是前端送來的原始字串，沒有從 `c_surname_chn`+`c_mingzi_chn` 重新組字的邏輯。這代表 create 路徑**沒有現成的「落地替換」掛鉤點可以修改**（不像 update 路徑本來就有組字邏輯可以就地加條件），要套用異體字落地替換得在 `store()` 內**新增**一段正規化程式碼——這是比 update 路徑更大的實作範圍，且屬於全新行為（現行 create 完全不做人名異體字替換），需要在後續任務規劃時把 create／update 分開估工，不能假設兩者對稱。

3. **ALTNAME_DATA（人物別名）寫入路徑**：與 BIOG_MAIN 不同，`AltnameCreateHandler`／`AltnameMutationHandler` 已經各自有明確的預處理掛鉤點（`AltnameCreateHandler::preprocessCreateData()` 61-71 行、`AltnameMutationHandler::preprocessUpdateData()` 61-66 行附近），現行已在這兩個方法內對 `c_alt_name_pinyin` 等欄位做 `BracketNormalizer`/`PinyinUmlaut::normalizeFields` 正規化，是比 BIOG_MAIN create 路徑更現成的掛鉤點。查詢條件同樣是 `c_strict_excluded = 0`（嚴格模式），套用在 `c_alt_name_chn` 欄位上（`c_alt_name_chn` 是 `ALTNAME_DATA` 複合主鍵的一部分：`c_personid + c_alt_name_chn + c_alt_name_type_code`，改寫這個欄位屬於「複合主鍵值變更」而非單純欄位更新，實作時需要跟現行改名／改主鍵的既有處理方式〔如 `AltnameMutationHandler` 對主鍵變更的處理邏輯〕保持一致，不能簡化成普通欄位覆寫)。

4. 新增 `app/Services/CharVariantMapService`（暫定名稱，命名可於實作階段再定）作為統一查詢介面。

5. **受審計的增修管道**：這張表的資料本身也是「錄入」（可能經常需要新增/修正對照組），變更應留下審計記錄，比照 [CODE_TABLE_MUTATION_API_PLAN.md](./CODE_TABLE_MUTATION_API_PLAN.md) 已確立的 code 表寫入慣例，而不是只依賴 Codes CRUD 或直接操作 DB：
   - **`config/codes.php` 註冊**（見上方，人工 UI 修改）：`CodesController` 的 `store`/`update`/`destroy` 直寫路徑已補上 `AuditLogService::write()`（§D-2 已完成，見 `app/Http/Controllers/CodesController.php` 建構子注入 `AuditLogService`），因此註冊進 `config/codes.php` 後，透過 `/app/codes/char_variant_map` UI 的增修刪已自動寫入 `audit_log`，不需要額外開發。
   - **`/api/v2/*` 受 token 的機器化寫入**（供外部腳本／未來批次維護異體字對照使用）：比照近期新增的 `TEXT_CODES` 範例，在 `config/code_table_writes.php` 的 `tables` 加入 `char_variant_map` 定義（`key_column` 建議用 `id`，`allowed_fields` 為 `c_variant_char`、`c_reference_char`、`c_strict_excluded`、`c_notes`），即可透過既有的 `CodeTableCreateHandler`／`CodeTableDeleteHandler`（`app/Services/Mutations/CodeTableCreateHandler.php`、`CodeTableDeleteHandler.php`）取得 create/delete，全程走 `operations` + `audit_log`、可回滾，不需要另外寫 handler 子類。若還需要 token 化的 update（例如批次修正 `c_reference_char`），另在 `config/code_table_mutations.php` 的 `tables` 加入對應定義，由既有的 config 驅動 update handler（`ConfigCodeTableMutationHandler`，見 `docs/CODE_TABLE_MUTATION_API_PLAN.md`）處理，同樣走 `audit_log`。
   - 這一步不影響前 4 點描述的落地替換查詢邏輯，純粹是「管理這張表本身資料」的治理面，可與前 4 點的程式串接分開排期。

實作階段需要補齊 `c_strict_excluded` 的分支測試（第 4 點的 `CharVariantMapService`，或各消費端各自查詢 `char_variant_map` 時的 WHERE 條件）：寬鬆模式驗證只要在表裡的記錄都能查到，`c_strict_excluded` 值不影響；嚴格模式再驗證只有 `c_strict_excluded=0` 的記錄可查到、`c_strict_excluded=1` 被排除。

`config/codes.php` 註冊與受審計增修管道見上方第 5 點。

## 不在本次範圍內

- **`CBDB__TRAD_SIMP_MAP`（繁簡轉換）機制不動**。`CBDB__TRAD_SIMP_MAP` 的功能是檢索比對（讓同一人名同時以繁簡兩種寫法被索引到，供姓名搜尋使用），跟本表的資料落地替換是不同層次的功能，不涉及合併問題，本計畫不涉及。
- **`VariantCharNormalizer::$fallbackMap` 的拼音正規化需求**：改由直接在 `pinyin` 表為對應異體字新增讀音資料處理，屬於 `pinyin` 表的資料維護工作，與本表的 schema 設計無關，不在本文件範圍內。
- **`VariantCharNormalizer` 類別本身的後續清理**：`pinyin` 表已補齊 `$fallbackMap` 原本 7 個字（菴、攷、嶽、愼、註、于、槀）各自的讀音（見 `/app/codes/pinyin`），`VariantCharNormalizer::normalize()` 這層轉換因此已經失去存在意義——每個字都能直接從 `pinyin` 表查到正確讀音，不再需要查表前的字元替換。可以整個刪除 `app/Services/VariantCharNormalizer.php`（`$fallbackMap`、`normalize()`、`ensureLoaded()`、`reset()`、`getMappingCount()`），並移除呼叫端（`BiogMainRepository::auto_pinyin()`、`ApiController::buildPinyinWord()`、`AdminBatchLoadBookTitlesController::buildPinyin()`/`collectUnpinyinableHan()`）對 `normalize()` 的呼叫。這項清理**依賴條件已滿足**，可獨立於本表的 schema 實作另開任務執行，不在本文件範圍內。
- **`TITLE_VARIANT_MAP` / BIOG_MAIN / ALTNAME_DATA 寫入路徑的程式碼改動**：本文件的「呼叫點串接方向」一節只記錄方向，實際程式碼變更、測試補強、`config/codes.php` 註冊皆留待後續任務執行，並各自走一輪 review 節點（見下方實作步驟）。

## 風險與待決事項

- **`c_strict_excluded` 是全域欄位，非逐表例外清單**：見上方「為什麼 `c_strict_excluded` 是全域單一欄位」一節。目前消費者是 BIOG_MAIN 與 ALTNAME_DATA（人名相關資料視為同一組），若未來出現這兩者之間或與其他表衝突的排除需求，需要重新設計。
- **`down()` 無安全閘門**：見上方 Migration 設計一節，回滾整表刪除是本表（全新表、7 筆種子資料）可接受的行為，與 `pinyin` 表整併時的情境不同。
- **BIOG_MAIN create／update 兩條路徑必須分開實作，不能假設對稱**：見上方「呼叫點串接方向」第 2 點——update／proposal 路徑已有組字邏輯可以就地加條件，但 create 路徑（`BiogMainRepository::store()`）完全沒有對應的組字/替換邏輯可改，需要新增程式碼。若只改到 update 路徑、漏掉 create 路徑，會導致新建人物與更新人物的正規化行為不一致。

## 實作步驟（每步完成後跑 review 機制才能進下一步）

> Review 機制：每個步驟完成後，先派一組會讀程式碼與讀改動的 review agent 檢查，直到沒有嚴重 issue；再用 `codex exec --dangerously-bypass-approvals-and-sandbox`（PowerShell + `Write-Output "..." |` 管道傳 prompt + 代理環境變數）做第二輪檢查，直到沒有嚴重 issue，才進下一步。

### 步驟 0：本 work plan 文件本身

先過 review 機制（review agent + codex）確認計畫本身沒有遺漏欄位設計、種子資料對照是否與現行機制完全一致，再進入下一步。

### 步驟 1：Migration — 建立 `char_variant_map` 表 + 種子資料

依上方「Migration 設計」與「現有資料遷移」實作 migration，執行 `php artisan migrate` 驗證 up/down 皆可正常執行（含 SQLite 測試環境）。此步驟只新增表，不改動任何現行呼叫點（`TITLE_VARIANT_MAP` 暫時繼續使用內建陣列，與新表資料重複但不衝突）。

### 步驟 2（後續任務，不在本次範圍）：串接呼叫點

依上方「呼叫點串接方向」逐一改接 `AdminBatchLoadBookTitlesController`、BIOG_MAIN／ALTNAME_DATA 寫入路徑，並補齊測試、`config/codes.php` 註冊。此步驟涉及既有行為變更（尤其 BIOG_MAIN／ALTNAME_DATA 新增落地替換是全新行為，現行完全沒有對人名做異體字落地替換），需要另外開任務規劃，走完整的 review 節點。
