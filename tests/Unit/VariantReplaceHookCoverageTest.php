<?php

namespace Tests\Unit;

use App\Services\Mutations\Concerns\AppliesVariantReplacement;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * 機械化把關：**寫文本進資料庫的路徑必須掛上異體字落地替換**（AGENTS.md §1.3）。
 *
 * 為什麼需要這支測試：這個 rollout 的第二階段之所以漏掉 19 個 handler，正是因為當時沒有任何
 * 機制會在漏掉時發出聲音——只要有人新增一個繞過基底類別的寫入路徑，就靜默地少一道替換。
 *
 * 五道把關，各自針對一種真實會發生的退化：
 * 1. **handler 覆蓋**：`app/Services/Mutations` 下**每一個** handler 都必須掛（或繼承）
 *    `Concerns\AppliesVariantReplacement`，或明文列在例外清冊。
 *    刻意**不**先判斷「這個 handler 有沒有寫入」——判斷寫入靠的是掃 `->insert(`／`->update(`
 *    這類慣用法，而現存 8 個非基底 handler 裡有 4 個把寫入委派給 repository／service，
 *    自己檔案裡一個寫入慣用法都沒有。以「有寫入才要求登記」為前提的話，那 4 個
 *    ——以及任何新的同型 handler——會直接落在偵測範圍外（fail-open）。改成全體必須交代，
 *    代價是每個純讀取／純刪除的 handler 一行例外，換來的是 fail-closed。
 * 2. **掛鉤真的被呼叫**：光有 `use` 不算——基底檔案裡必須真的呼叫 `applyVariantReplacement()`
 *    等方法。刪掉那一行呼叫比刪掉 `use` 容易得多，後果一樣（21 個子類靜默失去替換）。
 * 3. **子類的額外寫入**：繼承了掛鉤的基底 ≠ 自己那些額外的副表／鏡像寫入也被覆蓋。
 *    這類 handler 必須明文登記，新增一個沒登記的就會紅。
 * 4. **掛鉤點清冊**：S2～S7 的替換掛在 handler 體系**之外**（controller／repository／
 *    import service），那些檔案各自被點名並**記數**，掛鉤被移除或減少時就會紅。
 * 5. **例外清冊自身的衛生**：殭屍豁免（已掛鉤卻還列著）、陳舊條目、短名撞名都會紅。
 *
 * 刻意不掃「有沒有呼叫 CharVariantMapService」來判斷 handler 合規：那樣會鼓勵在各處手寫替換，
 * 而本 rollout 的結論恰恰相反——替換集中在少數掛鉤點，呼叫端只要繼承對的基底。
 *
 * **偵測邊界（誠實話）**：本測試覆蓋的是 `app/Services/Mutations` **整個目錄**（handler 與
 * 非 handler 檔案）加上點名的 12 個體系外掛鉤點。它**不是**全庫掃描：
 * - 在 `app/Services/Mutations` 之外新開一個檔案直接落庫（新 repository／service／controller），
 *   本測試不會知道。那類新路徑靠 AGENTS.md §1.3 與 code review 把關；若它成為常態掛鉤點，
 *   請把它加進 `NON_HANDLER_HOOK_SITES`。
 * - 子類「委派給下層方法」的額外寫入（例如 `syncAssocMirrorOnUpdate()`）也偵測不到——
 *   `hasOwnWrite()` 只認自己檔案裡的寫入慣用法。那些路徑目前的正確性是**人工判斷**的結果，
 *   不是本測試證明的，而且理由不只一種：
 *   - 一般 update 的鏡像 payload 來自基底已替換的陣列；
 *   - pair-only 鏡像修復（`AssociationMutationHandler::handlePairOnlyMirrorSync()`）**刻意**
 *     整列複製既有列、只改碼與 id：那裡沒有任何使用者輸入的文本，且鏡像的定位鍵
 *     （`c_text_title`）**不可單邊歸一**（D6／S7：歸一了偵測就永遠找不到那一對）。
 *     所以「該路徑寫進去的是變體形」在既有列本來就是變體形時是**正確**行為，不是漏替換。
 */
class VariantReplaceHookCoverageTest extends TestCase {
    /** handler 所在目錄（相對 repo 根）。 */
    private const HANDLER_DIR = 'app/Services/Mutations';

    /**
     * 明文例外：**不需要**自己掛替換的 handler，每筆必須寫理由。
     *
     * ⚠️ 這裡只能放「確實不需要掛」的：已經掛上（或繼承）trait 的類別**不可**列在這裡——
     * 那會讓它從此不再被檢查（見 testExemptEntriesAreNotAlreadyHooked）。
     *
     * ⚠️ 「目前沒有寫入」不等於「以後也不會有」：這些條目**會替未來加進去的文本寫入背書**，
     * 所以在其中任一個 handler 裡新增文本寫入時，必須同時把它從這裡移出並掛上替換。
     *
     * @var array<string,string>
     */
    private const EXEMPT = [
        // ── 抽象基底：本身不落庫 ─────────────────────────────
        'AbstractMutationHandler' => '抽象基底，只做授權／驗證／envelope，不落庫',
        'AbstractEntityAggregateHandler' => '抽象基底，落庫委派給 Import service（見 EXEMPT_DELEGATES 的兩個子類）',

        // ── 刪除：只刪列，不寫任何文本 ─────────────────────────
        'AbstractPersonSubresourceDeleteHandler' => '刪除路徑不寫文本；快照是審計事實，替換等於偽造紀錄',
        'AddressDeleteHandler' => '同上（刪除）',
        'AltnameDeleteHandler' => '同上（刪除）',
        'AssociationDeleteHandler' => '同上（刪除）；鏡像清理委派給基底與 repository',
        // ⚠️ 這一筆不是「不寫文本」：軟刪除是 UPDATE，會把 c_name_chn 覆寫成 DELETE_MARKER
        // 常數（固定字面值、非使用者輸入，所以沒有替換的必要）。哪天它開始寫任何輸入衍生的
        // 文本，就必須從這裡移出並掛上替換。
        'BiogMainDeleteHandler' => '軟刪除以 UPDATE 寫入固定的 DELETE_MARKER 常數，非使用者輸入 ⇒ 無需替換',
        'CodeTableDeleteHandler' => '同上（刪除）；且碼表刪除目前全域停用（回 403）',
        'EntityAggregateDeleteHandler' => '同上（刪除）',
        'EntryDeleteHandler' => '同上（刪除）',
        'EventDeleteHandler' => '同上（刪除）',
        'KinshipDeleteHandler' => '同上（刪除）；鏡像清理委派給基底與 repository',
        'MergedPersonDeleteHandler' => '同上（刪除）',
        'PossessionDeleteHandler' => '同上（刪除）',
        'PostingDeleteHandler' => '同上（刪除）',
        'SocialInstitutionDeleteHandler' => '同上（刪除）',
        'SourceDeleteHandler' => '同上（刪除）',
        'StatusDeleteHandler' => '同上（刪除）',
        'TextDeleteHandler' => '同上（刪除）',
    ];

    /**
     * 「寫入委派給下層」的 handler ⇒ 下層檔案必須仍然含**足量**的替換呼叫。
     *
     * 這不是免檢清單，而是**把檢查轉移到真正落庫的那一層**。純文字理由不夠：下層那一行被
     * 刪掉時，理由字串還在、測試照樣綠，例外就變成謊言——正是 S8 要防的失效模式。
     *
     * 值是「該檔案至少要有幾個替換呼叫」。**記數是必要的**：`BiogMainRepository` 有 8 處掛鉤，
     * 只斷言「檔案裡有一處」的話，刪掉 `store()` 的那一處仍會被 KIN_DATA 的那處遮住而假綠。
     *
     * @var array<string,array<string,int>>
     */
    private const EXEMPT_DELEGATES = [
        'BiogMainCreateHandler' => ['app/Repositories/BiogMainRepository.php' => 8],
        // direct 走 repository::updateById()、proposal 走本檔的 prepareProposalPayload()，兩處都要有。
        'BiogMainMutationHandler' => [
            'app/Repositories/BiogMainRepository.php' => 8,
            'app/Services/Mutations/BiogMainMutationHandler.php' => 1,
        ],
        'EntityAggregateCreateHandler' => [
            'app/Services/Import/OfficeImportService.php' => 1,
            'app/Services/Import/SocialInstituteImportService.php' => 4,
        ],
        'EntityAggregateUpdateHandler' => [
            'app/Services/Import/OfficeImportService.php' => 1,
            'app/Services/Import/SocialInstituteImportService.php' => 4,
        ],
    ];

    /**
     * 繼承了掛鉤基底、但**自己檔案裡另有寫入**的 handler。
     *
     * 基底的掛鉤只覆蓋它自己寫出去的那一列；子類額外插入的副表／鏡像列**不在**覆蓋範圍內
     * （AGENTS.md §1.3 的第一個邊界）。這裡逐筆交代那些額外寫入為什麼安全；新增一個沒登記的
     * 就會紅，迫使作者去想「我這個額外寫入的值替換過了嗎」。
     *
     * 自己顯式呼叫 `applyVariantReplacement()`／`replace*()` 的 handler 不必登記——它本身就是掛鉤點。
     *
     * @var array<string,string>
     */
    private const SUBCLASS_EXTRA_WRITES = [
        'AssociationCreateHandler' => '額外插入 ASSOC_DATA 鏡像列，但 $mirror 由基底已替換過的 $inserted 推導，不含未替換文本',
        'KinshipCreateHandler' => '額外插入 KIN_DATA 鏡像列，同上（$mirror 由已替換的 $inserted 推導）',
    ];

    /**
     * handler 體系**之外**的掛鉤點清冊（S2～S7），值為「至少幾處替換呼叫」。
     *
     * 這是 S2～S7 成果的唯一機械化鎖：那些路徑不繼承任何已掛鉤的基底，只要有人重構掉其中
     * 幾行，全套測試不見得會紅。記數讓「掛鉤變少」也會紅，而不只是「一個都不剩」才紅。
     *
     * @var array<string,array{hooks:int,why:string}>
     */
    private const NON_HANDLER_HOOK_SITES = [
        'app/Http/Controllers/CodesController.php' => [
            'hooks' => 6, 'why' => 'S2：代碼表 CRUD 的 5 條寫入路徑 + 共用的 applyVariantReplacement() 本體',
        ],
        'app/Repositories/BiogMainRepository.php' => [
            'hooks' => 8, 'why' => 'S3／S6：BIOG_MAIN 寫入、親屬與社會關係的核准寫入、legacy 別名 store／update 的 strict 替換',
        ],
        'app/Services/Import/OfficeImportService.php' => [
            'hooks' => 1, 'why' => 'S4：官職聚合（早於 buildPinyin）',
        ],
        'app/Services/Import/SocialInstituteImportService.php' => [
            'hooks' => 4, 'why' => 'S4：機構聚合、名稱碼解析（去重鍵）、地址子表',
        ],
        'app/Http/Controllers/OperationsProposalController.php' => [
            'hooks' => 2, 'why' => 'S6：提案核准的通用 create／update 分支',
        ],
        'app/Http/Controllers/CrowdsourcingController.php' => [
            'hooks' => 1, 'why' => 'S7：眾包回填 confirm()（delete-only 分支刻意用未替換的 $originalData 存快照）',
        ],
        'app/Http/Controllers/Api/OperationsController.php' => [
            'hooks' => 1, 'why' => 'S7：v1 token API 的 add／update 共用 replaceVariantsInJsonPayload()',
        ],
        'app/Http/Controllers/BasicInformationController.php' => [
            'hooks' => 10, 'why' => 'S7：saveas()、Duplicate_Collateral_Info() 的 BIOG_MAIN + 8 張子表寫入',
        ],
        'app/Http/Controllers/UnidirectionalRelationshipRepairController.php' => [
            'hooks' => 1, 'why' => 'S7：補建鏡像列（**僅非主鍵欄**——定位鍵單邊歸一會讓偵測永遠找不到）',
        ],
        'app/Services/PostingAutofillService.php' => [
            'hooks' => 2, 'why' => 'S4：年號與官名的兩形查表',
        ],
        'app/Services/Import/TextImportService.php' => [
            'hooks' => 3, 'why' => '文獻聚合：書名（早於拼音派生）、主列其餘文本欄、TEXT_INSTANCE_DATA 版本列整列',
        ],
        'app/Http/Controllers/BasicInformationProposalController.php' => [
            'hooks' => 3, 'why' => '前階段既有：提案 payload 的姓名／別名 strict 替換（不經 repository，本階段未改動但仍是落地點）',
        ],
    ];

    /**
     * `app/Services/Mutations` 下**不以 Handler 結尾**卻自己落庫的檔案，逐筆交代。
     *
     * 有人把寫入搬進這類 helper 時，
     * testNonHandlerFilesInTheMutationsDirectoryDoNotWriteUnaccounted 會紅。
     *
     * @var array<string,string>
     */
    private const NON_HANDLER_MUTATION_WRITERS = [
        // 寫的是 ai_fill_logs.user_submitted（紀錄類表，語義是「使用者當時實際送了什麼」⇒ 不替換；
        // 且 $submitted 本來就是基底替換後的值）。該表不在型別註冊表內，fail-closed 也不會替換。
        'Concerns/RecordsAiFillSubmission.php' => 'ai_fill_logs 是紀錄類表：只存提交快照，不替換（值本身已是基底替換後的形）',
    ];

    /**
     * 判定「有寫入資料庫」用的**方法名**（涵蓋常見的 Laravel 寫入慣用法，不只 insert／update）。
     *
     * 比對的是**真正的方法呼叫 token**，不是字串比對（見 codeCalls()）：字串字面值與註解裡
     * 出現同名不算。過度收錄（例如 `Request::create()` 也算寫入）是刻意的——多一筆登記的
     * 成本遠低於漏掉一個寫入。
     *
     * @var array<int,string>
     */
    private const WRITE_METHODS = [
        'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'upsert',
        'update', 'updateOrInsert', 'updateOrCreate', 'firstOrCreate', 'createOrFirst',
        'truncate', 'delete', 'save', 'increment', 'decrement',
        'create', 'statement', 'unprepared', 'affectingStatement',
    ];

    /** 替換掛鉤的方法名（`CharVariantMapService::` 靜態呼叫）。 */
    private const HOOK_STATIC_METHODS = ['replaceRow', 'replaceFor', 'replaceStrict', 'replaceLenient'];

    /** 替換掛鉤的方法名（trait 提供的實例方法）。 */
    private const HOOK_INSTANCE_METHODS = ['applyVariantReplacement'];

    /**
     * `app/Services/Mutations` 下的每一個 handler 都必須掛上替換 trait，或列在例外清冊裡。
     *
     * **順序很重要**：先看有沒有掛 trait，才看例外清冊。反過來的話，任何例外條目都會讓
     * 那個類別從此不再被檢查——包含它日後被搬離已掛鉤的繼承鏈時。
     */
    #[Test]
    public function testEveryMutationHandlerHasTheVariantReplacementHookOrAnExplicitExemption(): void {
        $unhooked = [];

        foreach ($this->allHandlers() as $shortName => $class) {
            if ($this->usesReplacementTrait($class)) {
                continue;
            }

            if (array_key_exists($shortName, self::EXEMPT) || array_key_exists($shortName, self::EXEMPT_DELEGATES)) {
                continue;
            }

            $unhooked[] = $shortName;
        }

        sort($unhooked);

        $this->assertSame(
            [],
            $unhooked,
            "下列 mutation handler 既沒有掛上（或繼承）Concerns\\AppliesVariantReplacement，\n"
                ."也沒有列在本測試的例外清冊。\n"
                ."若它會寫文本 ⇒ 掛上替換（見 AGENTS.md §1.3 與 .claude/skills/mutation-api-record-editing.md）；\n"
                ."若寫入其實委派給下層 ⇒ 加進 EXEMPT_DELEGATES（要指名下層檔案與掛鉤數，會被機械檢查）；\n"
                ."若確實不需要（只刪列、抽象基底、純讀取）⇒ 加進 EXEMPT 並寫理由。\n"
                .implode("\n", $unhooked)
        );
    }

    /**
     * 已經掛上 trait 的類別不可出現在例外清冊裡。
     *
     * 這種條目是**殭屍豁免**：它讓一個原本受檢的類別從此不再被檢查，日後被搬離繼承鏈時
     * 不會有任何東西變紅。（第一版就中過一次：`MergedPersonCreateHandler` 其實繼承了已掛鉤
     * 的基底，卻被我列為「無文本欄」而豁免——而它的 allowedFields() 明明有 c_notes／c_pages。）
     */
    #[Test]
    public function testExemptEntriesAreNotAlreadyHooked(): void {
        $handlers = $this->allHandlers();
        $redundant = [];

        foreach (array_merge(array_keys(self::EXEMPT), array_keys(self::EXEMPT_DELEGATES)) as $shortName) {
            $class = $handlers[$shortName] ?? null;
            if ($class !== null && $this->usesReplacementTrait($class)) {
                $redundant[] = $shortName;
            }
        }

        sort($redundant);

        $this->assertSame(
            [],
            $redundant,
            '下列 handler 已經掛上（或繼承）替換 trait，不該再列在例外清冊裡'
                .'——那會讓它從此不再被檢查。請直接從清冊刪除：'.implode(', ', $redundant)
        );
    }

    /** 「委派給下層」的例外：下層檔案必須仍然含足量的替換呼叫。 */
    #[Test]
    public function testDelegatedExemptionsStillHaveHooksInTheLowerLayer(): void {
        $broken = [];

        foreach (self::EXEMPT_DELEGATES as $handler => $files) {
            foreach ($files as $file => $expected) {
                $actual = $this->countHooks($file);
                if ($actual === null) {
                    $broken[] = "{$handler} → {$file}（檔案不存在）";

                    continue;
                }
                if ($actual < $expected) {
                    $broken[] = "{$handler} → {$file}（掛鉤數 {$actual} < 期望 {$expected}）";
                }
            }
        }

        $this->assertSame(
            [],
            $broken,
            "下列『寫入委派給下層』的例外已經站不住腳：下層檔案的替換呼叫變少或消失了，\n"
                ."也就是說 handler 沒掛、下層也（部分）沒掛，那個例外現在是謊言。\n"
                ."若是刻意減少（該路徑下架）⇒ 一併調降清冊裡的期望數並在 PR 說明理由。\n"
                .implode("\n", $broken)
        );
    }

    /**
     * 三個基底必須掛著 trait **並且真的呼叫它**。
     *
     * 只斷言 `use` 是不夠的：把那一行呼叫刪掉（保留 `use`）比刪 `use` 容易得多，
     * 而後果一樣——21 個子資源 handler 靜默失去替換。註解不算（比對前先去掉註解）。
     */
    #[Test]
    public function testBaseHandlersCarryTheHookAndActuallyCallIt(): void {
        $bases = [
            'app/Services/Mutations/AbstractPersonSubresourceCreateHandler.php' => \App\Services\Mutations\AbstractPersonSubresourceCreateHandler::class,
            'app/Services/Mutations/AbstractPersonSubresourceMutationHandler.php' => \App\Services\Mutations\AbstractPersonSubresourceMutationHandler::class,
            'app/Services/Mutations/AbstractCodeTableMutationHandler.php' => \App\Services\Mutations\AbstractCodeTableMutationHandler::class,
        ];

        foreach ($bases as $file => $class) {
            $this->assertTrue(
                $this->usesReplacementTrait($class),
                $class.' 必須掛著 AppliesVariantReplacement：它一被移除，所有繼承它的 handler 都靜默失去替換'
            );

            $this->assertFileExists(base_path($file));

            $missing = $this->missingInstanceCalls(
                $file,
                ['applyVariantReplacement', 'resetVariantReplaced', 'withVariantNotices']
            );

            $this->assertSame(
                [],
                $missing,
                $file.' 必須真的呼叫 '.implode('／', $missing)
                    .'（註解與字串字面值都不算）——光有 use 而不呼叫，等於沒有掛鉤'
            );
        }
    }

    /**
     * 繼承掛鉤基底、但自己另有寫入的 handler 必須明文登記。
     *
     * 基底的掛鉤只替換它自己寫的那一列；子類額外插的副表／鏡像列不在覆蓋範圍內。
     * 新增一個這種 handler 而沒登記時這裡會紅，逼作者交代那些值替換過了沒有。
     */
    #[Test]
    public function testSubclassesWithTheirOwnWritesAreAccountedFor(): void {
        $unaccounted = [];

        foreach ($this->allHandlers() as $shortName => $class) {
            // 自己就是掛鉤點的（顯式呼叫）不必登記。
            if (in_array(AppliesVariantReplacement::class, class_uses($class) ?: [], true)) {
                continue;
            }
            if (!$this->usesReplacementTrait($class)) {
                continue; // 由 test 1／例外清冊管。
            }
            if (!$this->hasOwnWrite($class)) {
                continue;
            }
            if (array_key_exists($shortName, self::SUBCLASS_EXTRA_WRITES)) {
                continue;
            }

            $unaccounted[] = $shortName;
        }

        sort($unaccounted);

        $this->assertSame(
            [],
            $unaccounted,
            "下列 handler 繼承了已掛鉤的基底，但**自己檔案裡另有資料庫寫入**。\n"
                ."基底的掛鉤只覆蓋它自己寫出去的那一列——你這個額外寫入（副表／鏡像／聚合列）\n"
                ."不在覆蓋範圍內。請確認那些值已經是替換後的形，然後登記進 SUBCLASS_EXTRA_WRITES\n"
                ."並寫明為什麼安全（或改成傳該副表表名再呼叫一次 applyVariantReplacement()）。\n"
                .implode("\n", $unaccounted)
        );
    }

    /** handler 體系之外的掛鉤點（S2～S7）必須都還在，而且數量沒少。 */
    #[Test]
    public function testNonHandlerHookSitesStillHaveTheirHooks(): void {
        $broken = [];

        foreach (self::NON_HANDLER_HOOK_SITES as $file => $spec) {
            $actual = $this->countHooks($file);
            if ($actual === null) {
                $broken[] = "{$file}（檔案不存在；{$spec['why']}）";

                continue;
            }
            if ($actual < $spec['hooks']) {
                $broken[] = "{$file}（掛鉤數 {$actual} < 期望 {$spec['hooks']}；{$spec['why']}）";
            }
        }

        $this->assertSame(
            [],
            $broken,
            "下列檔案是 handler 體系之外的替換掛鉤點，掛鉤數量變少或檔案不見了。\n"
                ."若是刻意移除（例如該路徑已下架）⇒ 調降或刪除 NON_HANDLER_HOOK_SITES 的該筆並在 PR 說明理由；\n"
                ."否則就是重構時漏掉了。\n"
                .implode("\n", $broken)
        );
    }

    /**
     * **底線斷言**：掃描本身必須真的掃到東西。
     *
     * 沒有這條，只要 `allHandlers()` 因為目錄搬移、命名慣例改變或 autoload 問題而回空集合，
     * 上面的主測試就會**空轉全綠**——正是本 rollout 想避免的「沒有機制在漏掉時發出聲音」。
     */
    #[Test]
    public function testScannerActuallyFindsHandlers(): void {
        $all = $this->allHandlers();

        $this->assertGreaterThan(40, count($all), 'handler 掃描結果異常少，掃描邏輯可能壞了');

        $hooked = array_filter($all, fn (string $c): bool => $this->usesReplacementTrait($c));
        $this->assertGreaterThan(20, count($hooked), '掛上替換 trait 的 handler 異常少，trait 偵測可能壞了');

        // 代表性 handler 必須被掃到（改名時這裡會紅，提醒同步更新掃描與清冊）。
        foreach ([
            'AbstractPersonSubresourceCreateHandler',
            'AbstractCodeTableMutationHandler',
            'CodeTableCreateHandler',
            'BiogMainDeleteHandler',
            'EntityAggregateCreateHandler',
        ] as $expected) {
            $this->assertArrayHasKey($expected, $all, $expected.' 應被掃到');
        }

        // 寫入偵測要涵蓋 insert／update 之外的慣用法：BiogMainDeleteHandler 只用 $biog->save()。
        $this->assertTrue(
            $this->hasOwnWrite($all['BiogMainDeleteHandler']),
            'WRITE_PATTERN 應涵蓋 save() 這類非 insert／update 的寫入慣用法'
        );
    }

    /** 例外清冊不可有殭屍條目（handler 改名／刪除後要一起清）。 */
    #[Test]
    public function testExemptListHasNoStaleEntries(): void {
        $existing = array_keys($this->allHandlers());
        $listed = array_merge(
            array_keys(self::EXEMPT),
            array_keys(self::EXEMPT_DELEGATES),
            array_keys(self::SUBCLASS_EXTRA_WRITES)
        );
        $stale = array_values(array_diff($listed, $existing));
        sort($stale);

        $this->assertSame(
            [],
            $stale,
            'EXEMPT／EXEMPT_DELEGATES／SUBCLASS_EXTRA_WRITES 裡有已不存在的 handler（改名或刪除後沒清）：'
                .implode(', ', $stale)
        );
    }

    /**
     * `app/Services/Mutations` 下**不以 Handler 結尾**的檔案若自己落庫，必須明文交代。
     *
     * 沒有這條，把寫入搬進同目錄的一個 helper（`Concerns\FooWriter`、
     * `EntityAggregate\BarDefinition`）就完全逃出偵測：它不是 handler、也不在點名清冊裡，
     * 九支測試全綠而它寫的是未替換的文本。
     */
    #[Test]
    public function testNonHandlerFilesInTheMutationsDirectoryDoNotWriteUnaccounted(): void {
        $root = base_path(self::HANDLER_DIR);
        $unaccounted = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_ends_with(basename($relative, '.php'), 'Handler')) {
                continue; // 由 handler 那幾支測試管。
            }
            if (!$this->fileHasWrite($file->getPathname())) {
                continue;
            }
            if (array_key_exists($relative, self::NON_HANDLER_MUTATION_WRITERS)) {
                continue;
            }

            $unaccounted[] = $relative;
        }

        sort($unaccounted);

        $this->assertSame(
            [],
            $unaccounted,
            "下列 app/Services/Mutations 下的非 handler 檔案自己落庫，卻沒有交代異體字替換：\n"
                ."請確認落庫值已是參考形（掛上替換或由上游保證），然後登記進 NON_HANDLER_MUTATION_WRITERS\n"
                ."並寫明理由。\n"
                .implode("\n", $unaccounted)
        );
    }

    /**
     * handler 短名不可撞名。
     *
     * `allHandlers()`（與所有清冊）以短名為鍵，`EntityAggregate/` 這樣的子目錄已經存在；
     * 兩個同名檔案會讓其中一個被覆蓋掉、從此完全不受檢查，而且不會有任何測試變紅。
     */
    #[Test]
    public function testHandlerShortNamesAreUnique(): void {
        $byShortName = [];
        foreach ($this->handlerFiles() as $relative) {
            $byShortName[basename($relative, '.php')][] = $relative;
        }

        $collisions = array_filter($byShortName, fn (array $files): bool => count($files) > 1);

        $this->assertSame(
            [],
            $collisions,
            '有 handler 短名撞名；本測試（與各例外清冊）以短名為鍵，撞名會讓其中一個靜默不受檢查。'
                .'請改名，或改成以 FQCN 為鍵。'
        );
    }

    /**
     * `app/Services/Mutations` 下所有 handler 類別（含子目錄；abstract 也算，
     * 因為基底掛鉤被移除同樣是缺陷）。
     *
     * @return array<string,class-string>
     */
    private function allHandlers(): array {
        $handlers = [];

        foreach ($this->handlerFiles() as $relative) {
            $class = 'App\\Services\\Mutations\\'.str_replace('/', '\\', substr($relative, 0, -4));
            if (!class_exists($class)) {
                continue;
            }

            $handlers[basename($relative, '.php')] = $class;
        }

        return $handlers;
    }

    /**
     * handler 檔案（相對 HANDLER_DIR 的路徑，含子目錄）。
     *
     * Concerns／Exceptions 之類的輔助檔不是 handler；只收檔名以 Handler 結尾者。
     *
     * @return array<int,string>
     */
    private function handlerFiles(): array {
        $files = [];
        $root = base_path(self::HANDLER_DIR);

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (!str_ends_with(basename($relative, '.php'), 'Handler')) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }

    /** 類別自己或任一祖先是否 use 了替換 trait。 */
    private function usesReplacementTrait(string $class): bool {
        for ($current = $class; $current !== false; $current = get_parent_class($current)) {
            if (in_array(AppliesVariantReplacement::class, class_uses($current) ?: [], true)) {
                return true;
            }
        }

        return false;
    }

    /** 該類別**自己的檔案**裡是否有資料庫寫入。 */
    private function hasOwnWrite(string $class): bool {
        $file = (new ReflectionClass($class))->getFileName();
        if ($file === false) {
            return false;
        }

        return $this->fileHasWrite($file);
    }

    /** 該檔案裡是否有資料庫寫入（比對真實呼叫 token）。 */
    private function fileHasWrite(string $absolutePath): bool {
        foreach ($this->codeCalls($absolutePath) as $call) {
            if (in_array($call['method'], self::WRITE_METHODS, true)) {
                return true;
            }
        }

        return false;
    }

    /** 檔案裡的替換呼叫數（檔案不存在回 null）。 */
    private function countHooks(string $relativePath): ?int {
        $path = base_path($relativePath);
        if (!is_file($path)) {
            return null;
        }

        $count = 0;
        foreach ($this->codeCalls($path) as $call) {
            if ($call['class'] === 'CharVariantMapService' && in_array($call['method'], self::HOOK_STATIC_METHODS, true)) {
                $count++;

                continue;
            }
            if ($call['class'] === null && in_array($call['method'], self::HOOK_INSTANCE_METHODS, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 檔案裡有沒有以 `->name(` 的形式呼叫某個實例方法。
     *
     * **必須限定 `->` 形式**：只比對方法名的話，`SomeHelper::applyVariantReplacement()` 這種
     * 同名靜態呼叫也會讓斷言過關，而真正的 `$this->applyVariantReplacement()` 已經被刪掉了。
     *
     * @param array<int,string> $methods
     * @return array<int,string> 沒被（以實例方法形式）呼叫到的方法名
     */
    private function missingInstanceCalls(string $relativePath, array $methods): array {
        $called = [];
        foreach ($this->codeCalls(base_path($relativePath)) as $call) {
            if ($call['class'] === null) {
                $called[] = $call['method'];
            }
        }

        return array_values(array_filter($methods, fn (string $m): bool => !in_array($m, $called, true)));
    }

    /**
     * 檔案裡**真正的方法呼叫**清單。
     *
     * 為什麼要走 tokenizer 而不是正則／`php_strip_whitespace()`：去註解只擋得住註解，**擋不住
     * 字串字面值**。有人把真正的掛鉤刪掉、留下 `$x = 'CharVariantMapService::replaceRow(';`
     * 這種字面值，純文字比對就會照樣算它一次而假綠。token 級比對只認 `Foo::bar(`／`->bar(`
     * 的呼叫形式，字串與註解都不算。
     *
     * @return array<int,array{class:string|null,method:string}> class 為 null 表示 `->method(` 形式
     */
    private function codeCalls(string $absolutePath): array {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($absolutePath)),
            fn ($token): bool => !is_array($token)
                || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        $calls = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            // 必須真的是呼叫：後面緊接 `(`。
            $next = $tokens[$i + 1] ?? null;
            if ($next !== '(') {
                continue;
            }

            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                $classToken = $tokens[$i - 2] ?? null;
                $calls[] = [
                    'class' => is_array($classToken) ? $classToken[1] : null,
                    'method' => $token[1],
                ];

                continue;
            }

            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $calls[] = ['class' => null, 'method' => $token[1]];
            }
        }

        return $calls;
    }
}
