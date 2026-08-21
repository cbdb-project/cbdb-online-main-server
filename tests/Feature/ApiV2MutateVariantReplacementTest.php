<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Services\CharVariantMapService;
use App\Support\CompositePrimaryKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 異體字落地替換在人物子資源 v2 mutation 全面生效（plan S3）。
 *
 * 掛鉤在兩個抽象基底類別（`AbstractPersonSubresourceCreateHandler`／
 * `AbstractPersonSubresourceMutationHandler`）與體系外三個例外
 * （`PossessionCreateHandler`／`PostingCreateHandler`／`SourceMutationHandler`）。
 * 這支測試不逐一列舉 21 個子類，而是抽樣覆蓋三類風險：
 *
 * 1. **文本型 PK 成員**被替換（`BIOG_SOURCE_DATA.c_pages`、`ASSOC_DATA.c_text_title`、
 *    `ALTNAME_DATA.c_alt_name_chn`）——替換必須早於 PK 計算與查重。
 * 2. **strict／lenient 分流**：只有人名／別名欄用 strict（`峯` 不替換），
 *    同一列的 `c_notes` 等一般文本欄用 lenient（`峯`→`峰`）。
 * 3. 一般文本欄（`c_event`／`c_exam_rank`／`c_supplement`／`c_possession_desc_chn`／
 *    各表 `c_notes`）確實被替換，且回應帶 `notices`。
 */
class ApiV2MutateVariantReplacementTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        $this->app['env'] = 'testing';
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        $this->createUsersTable();
        $this->createOperationsTable();
        $this->createAuditLogTable();
        $this->createCharVariantMapTable();
        $this->createAltnameTable();
        $this->createAssociationTable();
        $this->createAssocCodesTable();
        $this->createKinshipCodesTable();
        $this->createEventTable();
        $this->createEventsAddrTable();
        $this->createEntryTable();
        $this->createStatusTable();
        $this->createPossessionTable();
        $this->createPossessionAddrTable();
        $this->createTextCodesTable();
        $this->createSourceTable();
        $this->createPostingTable();
        $this->createPostingAddrTable();
        $this->createPostingIdTable();
    }

    protected function tearDown(): void {
        foreach ([
            'POSTING_DATA', 'POSTED_TO_ADDR_DATA', 'POSTED_TO_OFFICE_DATA', 'BIOG_SOURCE_DATA', 'TEXT_CODES', 'POSSESSION_ADDR', 'POSSESSION_DATA',
            'STATUS_DATA', 'ENTRY_DATA', 'EVENTS_ADDR', 'EVENTS_DATA',
            'KINSHIP_CODES', 'ASSOC_CODES', 'ASSOC_DATA', 'ALTNAME_DATA',
            'char_variant_map', 'audit_log', 'operations', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    // ── 1. 文本型 PK 成員 ────────────────────────────────────

    /** BIOG_SOURCE_DATA 的 PK 第三欄就是文本欄 c_pages：替換必須早於查重與落庫。 */
    #[Test]
    public function testSourceCreateReplacesVariantInTextPrimaryKeyColumn(): void {
        $this->actingAs($this->makeUser('src-create@example.com'));

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '愼一']],
            'changes' => ['c_notes' => '槀本'],
        ])->assertOk();

        $this->assertTrue(
            DB::table('BIOG_SOURCE_DATA')->where('c_pages', '慎一')->where('c_notes', '稿本')->exists(),
            'PK 成員 c_pages 與一般文本欄 c_notes 都應以參考形落庫'
        );
        $this->assertFalse(DB::table('BIOG_SOURCE_DATA')->where('c_pages', '愼一')->exists());
        $this->assertNotEmpty($response->json('notices'), '回應應帶異體字通知');
    }

    /** D7「觸碰即歸一」：既有列存的是變體形，更新時把 PK 成員改成參考形＝改鍵。 */
    #[Test]
    public function testSourceUpdateRenamesVariantPrimaryKeyToReferenceForm(): void {
        $this->actingAs($this->makeUser('src-rename@example.com'));
        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '愼一', 'c_notes' => null,
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '愼一']],
            // 使用者原樣重送變體形頁碼、只改備註：頁碼會被歸一化成參考形（改鍵）。
            'changes' => ['c_pages' => '愼一', 'c_notes' => '補注'],
        ])->assertOk();

        $this->assertTrue(DB::table('BIOG_SOURCE_DATA')->where('c_pages', '慎一')->exists(), 'PK 應改名為參考形');
        $this->assertFalse(DB::table('BIOG_SOURCE_DATA')->where('c_pages', '愼一')->exists(), '變體形列不應殘留');
        $this->assertSame(1, DB::table('BIOG_SOURCE_DATA')->count(), '不得複製出第二列');
    }

    /** 更新時 targetPk 是定位器：既有列存變體形也必須定位得到（不可連 targetPk 一起替換）。 */
    #[Test]
    public function testSourceUpdateStillLocatesRowStoredInVariantForm(): void {
        $this->actingAs($this->makeUser('src-locate@example.com'));
        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '愼一', 'c_notes' => null,
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '愼一']],
            'changes' => ['c_notes' => '只改備註'],
        ])->assertOk();

        $row = DB::table('BIOG_SOURCE_DATA')->first();
        $this->assertSame('愼一', $row->c_pages, '未送 c_pages 時不做回溯校正（D6）');
        $this->assertSame('只改備註', $row->c_notes);
    }

    /** ALTNAME_DATA.c_alt_name_chn 是 PK 成員且走 strict：通用掛鉤接手後通知不可消失。 */
    #[Test]
    public function testAltnameCreateKeepsStrictNoticeAfterGenericHookTakesOver(): void {
        $this->actingAs($this->makeUser('altname-notice@example.com'));

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4]],
            'changes' => [],
        ])->assertOk();

        $this->assertTrue(DB::table('ALTNAME_DATA')->where('c_alt_name_chn', '慎齋')->exists());
        $this->assertNotEmpty(
            $response->json('notices'),
            '別名替換通知不可因通用掛鉤接手而消失（子類重複呼叫 + assign 的失效模式）'
        );
    }

    // ── 2. strict／lenient 分流 ──────────────────────────────

    /** 同一列：別名欄 strict（峯 保留），c_notes lenient（峯→峰）。 */
    #[Test]
    public function testAltnameStrictColumnKeepsExcludedVariantWhileNotesIsReplaced(): void {
        $this->actingAs($this->makeUser('altname-mixed@example.com'));

        $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '峯生', 'c_alt_name_type_code' => 4]],
            'changes' => ['c_notes' => '峯字備註'],
        ])->assertOk();

        $row = DB::table('ALTNAME_DATA')->first();
        $this->assertSame('峯生', $row->c_alt_name_chn, '人名／別名欄用 strict：峯 不替換');
        $this->assertSame('峰字備註', $row->c_notes, '一般文本欄用 lenient：峯→峰');
    }

    // ── 3. 一般文本欄與通知 ─────────────────────────────────

    /** EVENTS_DATA.c_event（longtext）。 */
    #[Test]
    public function testEventUpdateReplacesVariantInEventText(): void {
        $this->actingAs($this->makeUser('event-variant@example.com'));
        DB::table('EVENTS_DATA')->insert([
            'c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 7, 'c_event' => '舊事',
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'events',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 7]],
            'changes' => ['c_event' => '登靑雲', 'c_notes' => '峯頂'],
        ])->assertOk();

        $row = DB::table('EVENTS_DATA')->first();
        $this->assertSame('登青雲', $row->c_event);
        $this->assertSame('峰頂', $row->c_notes);
        $this->assertNotEmpty($response->json('notices'));
    }

    /** ENTRY_DATA.c_exam_rank。 */
    #[Test]
    public function testEntryUpdateReplacesVariantInExamRank(): void {
        $this->actingAs($this->makeUser('entry-variant@example.com'));
        DB::table('ENTRY_DATA')->insert([
            'c_personid' => 1000, 'c_entry_code' => 36, 'c_sequence' => 1, 'c_kin_code' => 0,
            'c_assoc_code' => 0, 'c_kin_id' => 0, 'c_year' => 0, 'c_assoc_id' => 0,
            'c_inst_code' => 0, 'c_inst_name_code' => 0, 'c_exam_rank' => '舊名次',
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'entries',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => [
                'c_personid' => 1000, 'c_entry_code' => 36, 'c_sequence' => 1, 'c_kin_code' => 0,
                'c_assoc_code' => 0, 'c_kin_id' => 0, 'c_year' => 0, 'c_assoc_id' => 0,
                'c_inst_code' => 0, 'c_inst_name_code' => 0,
            ]],
            'changes' => ['c_exam_rank' => '第一甲頴等'],
        ])->assertOk();

        $this->assertSame('第一甲穎等', DB::table('ENTRY_DATA')->value('c_exam_rank'));
    }

    /** STATUS_DATA.c_supplement。 */
    #[Test]
    public function testStatusUpdateReplacesVariantInSupplement(): void {
        $this->actingAs($this->makeUser('status-variant@example.com'));
        DB::table('STATUS_DATA')->insert([
            'c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 3, 'c_supplement' => '舊補',
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'statuses',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 3]],
            'changes' => ['c_supplement' => '淸流'],
        ])->assertOk();

        $this->assertSame('清流', DB::table('STATUS_DATA')->value('c_supplement'));
    }

    /** POSSESSION_DATA：走體系外的 PossessionCreateHandler。 */
    #[Test]
    public function testPossessionCreateReplacesVariantInDescription(): void {
        $this->actingAs($this->makeUser('possession-variant@example.com'));

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'possessions',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'create',
            'target' => ['pk' => []],
            'changes' => ['c_possession_desc_chn' => '靑瓷', 'c_notes' => '厰造'],
        ])->assertOk();

        $row = DB::table('POSSESSION_DATA')->first();
        $this->assertSame('青瓷', $row->c_possession_desc_chn);
        $this->assertSame('廠造', $row->c_notes);
        $this->assertNotEmpty($response->json('notices'));
    }

    /** 提案模式（proposal）：payload 落在 operations 裡的也必須是替換後的值。 */
    #[Test]
    public function testProposalPayloadStoresReplacedText(): void {
        $this->actingAs($this->makeUser('proposal-variant@example.com', User::ROLE_REGULAR));
        DB::table('STATUS_DATA')->insert([
            'c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 3, 'c_supplement' => '舊補',
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'statuses',
            'person_id' => 1000,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 3]],
            'changes' => ['c_supplement' => '淸流'],
        ])->assertOk();

        $payload = json_decode((string) DB::table('operations')->value('resource_data'), true);
        $this->assertSame('清流', $payload['c_supplement'], '提案 payload 必須存替換後的值，否則審核畫面與落庫不一致');
    }

    // ── 4. ASSOC_DATA.c_text_title：PK 成員 + 鏡像 ──────────

    /** 正向列改鍵成功時，對面鏡像列的同名 PK 成員一起收斂。 */
    #[Test]
    public function testAssociationUpdateReplacesTextTitleAndConvergesMirror(): void {
        $this->actingAs($this->makeUser('assoc-mirror@example.com'));
        $this->seedAssociationPair('愼書');

        $this->postJson('/api/v2/mutate', $this->associationPayload('愼書', [
            'c_text_title' => '愼書',
            'c_assocship_pair' => 2,
        ]))->assertOk();

        $this->assertTrue(
            DB::table('ASSOC_DATA')->where('c_personid', 1000)->where('c_text_title', '慎書')->exists(),
            '正向列的 c_text_title 應改為參考形'
        );
        $this->assertTrue(
            DB::table('ASSOC_DATA')->where('c_personid', 2000)->where('c_text_title', '慎書')->exists(),
            '對面鏡像列應一起收斂（觸碰即歸一）'
        );
        $this->assertSame(2, DB::table('ASSOC_DATA')->count(), '不得產生額外列');
    }

    /**
     * 對面已存在參考形鏡像列時，鏡像改鍵會撞唯一鍵——必須是乾淨的 409，
     * 不可冒成未捕捉的 500，且整筆（含正向列）回滾。
     */
    #[Test]
    public function testAssociationUpdateReturns409WhenMirrorRenameCollides(): void {
        $this->actingAs($this->makeUser('assoc-mirror-conflict@example.com'));
        $this->seedAssociationPair('愼書');
        // 對面那個人已經有一列參考形的同段關係鏡像：鏡像改鍵會撞上它。
        // c_kin_id／c_assoc_kin_id 必須與鏡像同步後的值一致（afterDirectUpdate 會把它們設成
        // 正向那個人的 id），否則 9 鍵 PK 不相同、撞不到唯一鍵，測不到要測的東西。
        $this->insertAssociation(2000, 2, 1000, '慎書', [
            'c_kin_id' => 1000,
            'c_assoc_kin_id' => 1000,
        ]);

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload('愼書', [
            'c_text_title' => '愼書',
            'c_assocship_pair' => 2,
        ]));

        $this->assertSame(409, $response->getStatusCode(), '鏡像改鍵撞號應回 409 而非 500');
        // 注意：errors 的鍵名本身含點（'target.pk'），不能用 json() 的點記法取值。
        $this->assertSame(['conflict'], $response->json('errors')['target.pk'] ?? null, '應走主鍵衝突分支');
        $this->assertNotEmpty(
            $response->json('notices'),
            '被擋下來的回應也要帶異體字通知，否則使用者看不懂 409 從哪來'
        );
        $this->assertTrue(
            DB::table('ASSOC_DATA')->where('c_personid', 1000)->where('c_text_title', '愼書')->exists(),
            '整筆交易應回滾，正向列維持原值'
        );
    }

    // ── 5. D7「兩形並存」查重（替換不可鑄出語義重複列）──────

    /**
     * 既有列存變體形（D6 不做回溯校正），使用者原樣再送一次同一個變體形：
     * 必須是乾淨的 409，**不可**因為替換後的值查不到而 INSERT 出第二列。
     *
     * 為什麼這條最重要：唯一鍵擋不住（`愼齋` 與 `慎齋` 是兩個不同鍵值），落地替換上線前
     * 這種輸入本來會被 409 擋下——沒有這道查重，替換反而比不替換更糟。
     */
    #[Test]
    public function testAltnameCreateReturns409WhenExistingRowIsStoredInVariantForm(): void {
        $this->actingAs($this->makeUser('altname-d7@example.com'));
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4,
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4]],
            'changes' => [],
        ]);

        $this->assertSame(409, $response->getStatusCode(), '既有變體形列應擋下等價字形的新增');
        $this->assertSame(1, DB::table('ALTNAME_DATA')->count(), '不得鑄出第二列語義重複的別名');
    }

    /** 同上，PK 第三欄是文本欄的 BIOG_SOURCE_DATA。 */
    #[Test]
    public function testSourceCreateReturns409WhenExistingRowIsStoredInVariantForm(): void {
        $this->actingAs($this->makeUser('src-d7@example.com'));
        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '愼一',
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '愼一']],
            'changes' => [],
        ]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(1, DB::table('BIOG_SOURCE_DATA')->count());
    }

    /**
     * 待審提案側同理：帶變體形 resource_id 的舊提案與帶歸一後 resource_id 的新提案
     * 不會相等 ⇒ 沒有這道查重就會兩筆並存、依序核准落成兩種字形的兩列。
     */
    #[Test]
    public function testAltnameProposalReturns409WhenPendingProposalIsInVariantForm(): void {
        $this->actingAs($this->makeUser('altname-d7-proposal@example.com', User::ROLE_REGULAR));
        DB::table('operations')->insert([
            'user_id' => 1,
            'c_personid' => 1000,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'ALTNAME_DATA',
            // 人物子資源的 resource_id ＝ CompositePrimaryKey::buildStoredResourceId()
            // ＝ http_build_query()（欄名帶在字串裡、值 percent-encoded），不是代碼表的 '_._' 位置式。
            'resource_id' => CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => 1000,
                'c_alt_name_chn' => '愼齋',
                'c_alt_name_type_code' => 4,
            ]),
            'resource_data' => json_encode([
                'c_personid' => 1000,
                'c_alt_name_chn' => '愼齋',
                'c_alt_name_type_code' => 4,
                '__review_status' => 'pending',
                '__key_columns' => ['c_alt_name_chn', 'c_alt_name_type_code', 'c_personid'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'proposal',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4]],
            'changes' => [],
        ]);

        $this->assertSame(409, $response->getStatusCode(), '等價字形的待審提案應擋下重複提交');
        $this->assertSame(1, DB::table('operations')->count(), '不得產生第二筆待審提案');
    }

    /**
     * 改鍵（update）側的 D7：把某列的文本型 PK 成員改成「歸一後等於另一既有變體形列」
     * 的值時，DB 唯一鍵擋不住（不同字形＝不同鍵值），必須自己擋成 409。
     */
    #[Test]
    public function testAltnameUpdateReturns409WhenRenameCollidesWithVariantFormRow(): void {
        $this->actingAs($this->makeUser('altname-d7-update@example.com'));
        DB::table('ALTNAME_DATA')->insert([
            ['c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4],
            ['c_personid' => 1000, 'c_alt_name_chn' => '甲號', 'c_alt_name_type_code' => 4],
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '甲號', 'c_alt_name_type_code' => 4]],
            'changes' => ['c_alt_name_chn' => '慎齋'],
        ]);

        $this->assertSame(409, $response->getStatusCode(), '歸一後與既有變體形列相同 ⇒ 應擋下');
        $this->assertSame(2, DB::table('ALTNAME_DATA')->count());
        $this->assertTrue(DB::table('ALTNAME_DATA')->where('c_alt_name_chn', '甲號')->exists(), '應整筆回滾');
    }

    /** 只改非 PK 欄時不得因為「候選列就是自己」而誤報 409。 */
    #[Test]
    public function testAltnameUpdateOfNonKeyFieldIsNotBlockedByItsOwnRow(): void {
        $this->actingAs($this->makeUser('altname-d7-self@example.com'));
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4, 'c_notes' => '舊',
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4]],
            'changes' => ['c_notes' => '新'],
        ])->assertOk();

        $this->assertSame('新', DB::table('ALTNAME_DATA')->value('c_notes'));
    }

    /**
     * codex round 1：候選集裡「自己」排在真正衝突的另一列**之前**時，不得因為
     * 「第一筆是自己」就放行。`愼齋` 與 `慬齋` 都歸一成 `慎齋`。
     */
    #[Test]
    public function testAltnameUpdateStillBlocksWhenSelfIsAlsoAnEquivalentCandidate(): void {
        $this->actingAs($this->makeUser('altname-d7-self-first@example.com'));
        DB::table('char_variant_map')->insert([
            'c_variant_char' => '慬', 'c_reference_char' => '慎', 'c_strict_excluded' => 0,
        ]);
        CharVariantMapService::reset();

        // 插入順序讓「正在編輯的自己」先被掃到。
        DB::table('ALTNAME_DATA')->insert([
            ['c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4],
            ['c_personid' => 1000, 'c_alt_name_chn' => '慬齋', 'c_alt_name_type_code' => 4],
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4]],
            'changes' => ['c_alt_name_chn' => '慎齋'],
        ]);

        $this->assertSame(409, $response->getStatusCode(), '另一列也歸一成 慎齋 ⇒ 必須擋下');
        $this->assertSame(2, DB::table('ALTNAME_DATA')->count());
        $this->assertTrue(DB::table('ALTNAME_DATA')->where('c_alt_name_chn', '愼齋')->exists(), '應整筆回滾');
    }

    /**
     * codex round 1：鏡像列改鍵時，對面若已有另一列「歸一後相同但字形不同」的關係，
     * DB 唯一鍵擋不住（不同字形＝不同鍵值），必須自己擋成 409 並回滾。
     */
    #[Test]
    public function testAssociationUpdateBlocksMirrorRenameCollidingWithVariantEquivalentRow(): void {
        $this->actingAs($this->makeUser('assoc-mirror-d7@example.com'));
        DB::table('char_variant_map')->insert([
            'c_variant_char' => '慬', 'c_reference_char' => '慎', 'c_strict_excluded' => 0,
        ]);
        CharVariantMapService::reset();

        $this->seedAssociationPair('愼書');
        // 對面另有一段同對方／同首年的關係，書名是**另一個**變體形，歸一後與新鍵相同。
        $this->insertAssociation(2000, 2, 1000, '慬書', [
            'c_kin_id' => 1000,
            'c_assoc_kin_id' => 1000,
        ]);

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload('愼書', [
            'c_text_title' => '愼書',
            'c_assocship_pair' => 2,
        ]));

        $this->assertSame(409, $response->getStatusCode(), '鏡像改鍵撞等價字形應回 409');
        $this->assertTrue(
            DB::table('ASSOC_DATA')->where('c_personid', 1000)->where('c_text_title', '愼書')->exists(),
            '整筆交易應回滾'
        );
        $this->assertTrue(
            DB::table('ASSOC_DATA')->where('c_personid', 2000)->where('c_text_title', '愼書')->exists(),
            '鏡像列也應維持原值'
        );
    }

    // ── 6. 其餘掛鉤點與 422 通知 ─────────────────────────────

    /** POSTED_TO_OFFICE_DATA：走體系外的 PostingCreateHandler。 */
    #[Test]
    public function testPostingCreateReplacesVariantInNotes(): void {
        $this->actingAs($this->makeUser('posting-variant@example.com'));

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'postings',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'create',
            'target' => ['pk' => []],
            'changes' => ['c_office_id' => 3000, 'c_notes' => '靑州任'],
        ])->assertOk();

        $this->assertSame('青州任', DB::table('POSTED_TO_OFFICE_DATA')->value('c_notes'));
        $this->assertNotEmpty($response->json('notices'));
    }

    /**
     * 使用者送的字被歸一成與現值相同 ⇒ 422「未偵測到任何修改內容」。
     * 這條回應**也要帶 notices**，否則錯誤訊息看起來毫無道理。
     */
    #[Test]
    public function testNoEffectiveChangesResponseStillCarriesVariantNotices(): void {
        $this->actingAs($this->makeUser('status-422@example.com'));
        DB::table('STATUS_DATA')->insert([
            'c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 3, 'c_supplement' => '清流',
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'statuses',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 3]],
            'changes' => ['c_supplement' => '淸流'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotEmpty($response->json('notices'), '422 也要帶異體字通知');
    }

    /**
     * codex round 2：鏡像**補建**（backfill）路徑也要做 D7 preflight。
     * 嚴格定位用舊書名精確比對，所以對面存另一個變體形（`慬書`）時定位落空 → 走補建，
     * 插入歸一後的 `慎書` 就與 `慬書` 並存（唯一鍵擋不住不同字形）。
     */
    #[Test]
    public function testAssociationMirrorBackfillBlocksVariantEquivalentDuplicate(): void {
        $this->actingAs($this->makeUser('assoc-backfill-d7@example.com'));
        DB::table('char_variant_map')->insert([
            'c_variant_char' => '慬', 'c_reference_char' => '慎', 'c_strict_excluded' => 0,
        ]);
        CharVariantMapService::reset();

        // 只有正向列（對面沒有可嚴格定位的鏡像）＋對面另有一列別的變體形。
        $this->insertAssociation(1000, 1, 2000, '愼書');
        $this->insertAssociation(2000, 2, 1000, '慬書', ['c_kin_id' => 1000, 'c_assoc_kin_id' => 1000]);

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload('愼書', [
            'c_text_title' => '愼書',
            'c_assocship_pair' => 2, // 顯式送配對碼 ⇒ 允許 backfill
        ]));

        $this->assertSame(409, $response->getStatusCode(), '補建的鏡像列與既有變體形列歸一後相同 ⇒ 應擋下');
        $this->assertSame(2, DB::table('ASSOC_DATA')->count(), '不得補出第三列');
        $this->assertTrue(
            DB::table('ASSOC_DATA')->where('c_personid', 1000)->where('c_text_title', '愼書')->exists(),
            '整筆交易應回滾'
        );
    }

    /**
     * codex round 2：#70 的 force 就地收斂路徑（碼漂移的疑似鏡像列）同樣是改鍵，
     * 也要做 D7 preflight。
     */
    #[Test]
    public function testAssociationMirrorForceConvergeBlocksVariantEquivalentDuplicate(): void {
        $this->actingAs($this->makeUser('assoc-force-d7@example.com'));
        DB::table('char_variant_map')->insert([
            'c_variant_char' => '慬', 'c_reference_char' => '慎', 'c_strict_excluded' => 0,
        ]);
        CharVariantMapService::reset();

        $this->insertAssociation(1000, 1, 2000, '愼書');
        // 對面的漂移鏡像列（碼 99 不是合法 ASSOC_CODE）＋另一列合法碼但別的變體形書名。
        $this->insertAssociation(2000, 99, 1000, '愼書', ['c_kin_id' => 1000, 'c_assoc_kin_id' => 1000]);
        $this->insertAssociation(2000, 2, 1000, '慬書', ['c_kin_id' => 1000, 'c_assoc_kin_id' => 1000]);

        $payload = $this->associationPayload('愼書', [
            'c_text_title' => '愼書',
            'c_assocship_pair' => 2,
        ]);
        $payload['meta'] = ['force' => true]; // #70：強制收斂漂移鏡像

        $response = $this->postJson('/api/v2/mutate', $payload);

        $this->assertSame(409, $response->getStatusCode(), 'force 收斂後會與既有變體形列歸一後相同 ⇒ 應擋下');
        $this->assertTrue(
            DB::table('ASSOC_DATA')->where('c_personid', 2000)->where('c_assoc_code', 99)->where('c_text_title', '愼書')->exists(),
            '漂移列應維持原值（整筆回滾）'
        );
    }

    /**
     * codex round 2：待審提案的等價查重必須涵蓋 `operations.c_personid = 0` 的歷史資料。
     * 漏抓的後果不是「少擋一次」——第二筆會在核准重放時才被擋，提案卡在 pending。
     */
    #[Test]
    public function testAltnameProposalDedupCoversLegacyZeroPersonIdOperations(): void {
        $this->actingAs($this->makeUser('altname-d7-zero@example.com', User::ROLE_REGULAR));
        DB::table('operations')->insert([
            'user_id' => 1,
            'c_personid' => 0, // 歷史／測試資料可能是 0
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => 1000,
                'c_alt_name_chn' => '愼齋',
                'c_alt_name_type_code' => 4,
            ]),
            'resource_data' => json_encode([
                'c_personid' => 1000,
                'c_alt_name_chn' => '愼齋',
                'c_alt_name_type_code' => 4,
                '__review_status' => 'pending',
                '__key_columns' => ['c_alt_name_chn', 'c_alt_name_type_code', 'c_personid'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'proposal',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4]],
            'changes' => [],
        ]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(1, DB::table('operations')->count());
    }

    /**
     * codex round 2：`BIOG_SOURCE_DATA` 的既有語義是「被拒絕的提案可以重新提交」，
     * 等價字形查重不得把它變成「字形相同可重送、只是異體等價不可重送」的不一致行為。
     */
    #[Test]
    public function testSourceProposalDedupIgnoresRejectedProposalsLikeExactMatchDoes(): void {
        $this->actingAs($this->makeUser('src-d7-rejected@example.com', User::ROLE_REGULAR));
        DB::table('operations')->insert([
            'user_id' => 1,
            'c_personid' => 1000,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'BIOG_SOURCE_DATA',
            'resource_id' => CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => 1000,
                'c_textid' => 500,
                'c_pages' => '愼一',
            ]),
            'resource_data' => json_encode([
                'c_personid' => 1000,
                'c_textid' => 500,
                'c_pages' => '愼一',
                '__review_status' => 'rejected',
                '__key_columns' => ['c_personid', 'c_textid', 'c_pages'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'proposal',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '愼一']],
            'changes' => [],
        ])->assertOk();

        $this->assertSame(2, DB::table('operations')->count(), '被拒絕的提案不應擋住重新提交');
    }

    /**
     * codex round 3：既有資料早就有兩列歸一後相同（D6 不做回溯校正）時，
     * 只改其中一列的**非 PK 欄**是合法操作，不得被 D7 preflight 誤擋成 409。
     *
     * 註：這條與下一條在目前實作下有兩道保護——「只在改鍵時才檢查」的閘門，以及
     * 「未送文本 PK 欄時 $after 仍是原字形、比不到已歸一的候選」。所以把閘門單獨拿掉
     * 它們仍會綠；留著是為了鎖住使用者可見行為（真正鎖住閘門的是鏡像那條測試）。
     */
    #[Test]
    public function testUpdatingNonKeyFieldIsAllowedEvenWhenTwoVariantFormsAlreadyCoexist(): void {
        $this->actingAs($this->makeUser('altname-d7-no-false-409@example.com'));
        DB::table('char_variant_map')->insert([
            'c_variant_char' => '慬', 'c_reference_char' => '慎', 'c_strict_excluded' => 0,
        ]);
        CharVariantMapService::reset();

        // 兩列歸一後都是「慎齋」，是落地替換上線前就存在的歷史資料。
        DB::table('ALTNAME_DATA')->insert([
            ['c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4, 'c_notes' => '舊'],
            ['c_personid' => 1000, 'c_alt_name_chn' => '慬齋', 'c_alt_name_type_code' => 4, 'c_notes' => '舊'],
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '愼齋', 'c_alt_name_type_code' => 4]],
            'changes' => ['c_notes' => '新'],
        ])->assertOk();

        $this->assertSame('新', DB::table('ALTNAME_DATA')->where('c_alt_name_chn', '愼齋')->value('c_notes'));
        $this->assertSame('舊', DB::table('ALTNAME_DATA')->where('c_alt_name_chn', '慬齋')->value('c_notes'));
    }

    /** 同上，BIOG_SOURCE_DATA（PK 第三欄是文本欄）。 */
    #[Test]
    public function testSourceUpdatingNonKeyFieldIsAllowedWhenTwoVariantFormsAlreadyCoexist(): void {
        $this->actingAs($this->makeUser('src-d7-no-false-409@example.com'));
        DB::table('char_variant_map')->insert([
            'c_variant_char' => '慬', 'c_reference_char' => '慎', 'c_strict_excluded' => 0,
        ]);
        CharVariantMapService::reset();

        DB::table('BIOG_SOURCE_DATA')->insert([
            ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '愼一'],
            ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '慬一'],
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '愼一']],
            'changes' => ['c_notes' => '只改備註'],
        ])->assertOk();

        $this->assertSame('只改備註', DB::table('BIOG_SOURCE_DATA')->where('c_pages', '愼一')->value('c_notes'));
    }

    /** 鏡像側同理：對面早有兩形並存時，只改正向列的非 PK 欄不得被誤擋。 */
    #[Test]
    public function testAssociationNonKeyUpdateIsAllowedWhenMirrorSideHasCoexistingVariantForms(): void {
        $this->actingAs($this->makeUser('assoc-d7-no-false-409@example.com'));
        DB::table('char_variant_map')->insert([
            'c_variant_char' => '慬', 'c_reference_char' => '慎', 'c_strict_excluded' => 0,
        ]);
        CharVariantMapService::reset();

        // 正向與鏡像的書名都已是參考形（改備註不會改鍵），但對面另有一列變體形。
        $this->seedAssociationPair('慎書');
        $this->insertAssociation(2000, 2, 1000, '慬書', ['c_kin_id' => 1000, 'c_assoc_kin_id' => 1000]);

        $this->postJson('/api/v2/mutate', $this->associationPayload('慎書', [
            'c_notes' => '只改備註',
            'c_assocship_pair' => 2,
        ]))->assertOk();

        $this->assertSame(
            '只改備註',
            DB::table('ASSOC_DATA')->where('c_personid', 1000)->value('c_notes')
        );
    }

    /**
     * codex round 4：反向關係碼為哨兵 0（無有效反向可定位）時走的是「盲插鏡像」分支。
     * 插入的 c_text_title 已是歸一後字形，對面若已有同一組固定 PK、書名為歷史變體形的列，
     * 這一插就造成兩形並存（唯一鍵擋不住不同字形），必須擋成 409 並回滾。
     */
    #[Test]
    public function testAssociationCreateWithZeroReversePairBlocksVariantEquivalentMirror(): void {
        $this->actingAs($this->makeUser('assoc-zero-pair-d7@example.com'));
        DB::table('char_variant_map')->insert([
            'c_variant_char' => '慬', 'c_reference_char' => '慎', 'c_strict_excluded' => 0,
        ]);
        CharVariantMapService::reset();

        // 關係碼 7 沒有反向配對碼（c_assoc_pair 為 null ⇒ 哨兵 0）。
        DB::table('ASSOC_CODES')->insert(['c_assoc_code' => 7, 'c_assoc_pair' => null, 'c_assoc_pair2' => null]);
        // 對面已有一列：固定 PK 與即將插入的鏡像相同，只有書名是另一個變體形。
        $this->insertAssociation(2000, 0, 1000, '慬書', ['c_kin_id' => 1000, 'c_assoc_kin_id' => 1000]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'create',
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_assoc_code' => 7,
                'c_assoc_id' => 2000,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => '愼書',
                'c_assoc_first_year' => 1060,
            ]],
            'changes' => ['c_source' => 10],
        ]);

        $this->assertSame(409, $response->getStatusCode(), '盲插鏡像撞等價字形應回 409');
        $this->assertSame(1, DB::table('ASSOC_DATA')->count(), '整筆交易應回滾（正向列也不留）');
    }

    // ── Fixtures ─────────────────────────────────────────────

    protected function makeUser(string $email, int $role = User::ROLE_SUPER_ADMIN): User {
        return User::forceCreate([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => $role,
        ]);
    }

    protected function insertAssociation(int $personId, int $assocCode, int $assocId, string $title, array $overrides = []): void {
        DB::table('ASSOC_DATA')->insert(array_replace([
            'c_personid' => $personId,
            'c_assoc_code' => $assocCode,
            'c_assoc_id' => $assocId,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => $title,
            'c_assoc_first_year' => 1060,
            'c_source' => 10,
        ], $overrides));
    }

    /** 1000 --1--> 2000 的正向列，與 2000 --2--> 1000 的鏡像列。 */
    protected function seedAssociationPair(string $title): void {
        $this->insertAssociation(1000, 1, 2000, $title);
        $this->insertAssociation(2000, 2, 1000, $title);
    }

    protected function associationPayload(string $pkTitle, array $changes): array {
        return [
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_assoc_code' => 1,
                'c_assoc_id' => 2000,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => $pkTitle,
                'c_assoc_first_year' => 1060,
            ]],
            'changes' => $changes,
        ];
    }

    /**
     * 與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php
     * 相同的 7 筆種子資料（`峯` 是唯一 strict 排除的一筆）。
     */
    protected function createCharVariantMapTable(): void {
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();

            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });

        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '愼', 'c_reference_char' => '慎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '槀', 'c_reference_char' => '稿', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
            ['c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0],
            ['c_variant_char' => '頴', 'c_reference_char' => '穎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
            ['c_variant_char' => '厰', 'c_reference_char' => '廠', 'c_strict_excluded' => 0],
        ]);
        CharVariantMapService::reset();
    }

    protected function createUsersTable(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function createOperationsTable(): void {
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->integer('c_personid')->default(0);
            $table->integer('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->longText('resource_data')->nullable();
            $table->longText('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });
    }

    protected function createAuditLogTable(): void {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->string('operation_id', 64);
            $table->text('row_pk');
            $table->string('row_pk_text', 512)->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
        });
    }

    protected function createAltnameTable(): void {
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->string('c_alt_name_chn', 255)->default('');
            $table->integer('c_alt_name_type_code')->default(0);
            $table->string('c_alt_name', 255)->nullable();
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_sequence')->default(0);
            $table->string('c_alt_name_pinyin', 255)->nullable();
            $table->string('c_alt_name_pinyin2', 255)->nullable();
            $table->string('c_alt_name_pinyin3', 255)->nullable();
            $table->string('c_alt_name_role', 50)->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code']);
        });
    }

    protected function createAssociationTable(): void {
        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_assoc_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_assoc_kin_code')->default(0);
            $table->integer('c_assoc_kin_id')->default(0);
            $table->string('c_text_title', 255)->default('');
            $table->integer('c_assoc_first_year')->default(0);
            $table->integer('c_assoc_last_year')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->integer('c_sequence')->default(0);
            $table->integer('c_assoc_count')->default(0);
            $table->integer('c_topic_code')->nullable();
            $table->integer('c_occasion_code')->nullable();
            $table->integer('c_tertiary_personid')->nullable();
            $table->text('c_tertiary_type_notes')->nullable();
            $table->integer('c_assoc_claimer_id')->nullable();
            $table->integer('c_addr_id')->nullable();
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary([
                'c_personid', 'c_assoc_code', 'c_assoc_id',
                'c_kin_code', 'c_kin_id', 'c_assoc_kin_code',
                'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year',
            ]);
        });
    }

    protected function createAssocCodesTable(): void {
        Schema::create('ASSOC_CODES', function (Blueprint $table) {
            $table->integer('c_assoc_code')->primary();
            $table->integer('c_assoc_pair')->nullable();
            $table->integer('c_assoc_pair2')->nullable();
        });
        DB::table('ASSOC_CODES')->insert([
            ['c_assoc_code' => 1, 'c_assoc_pair' => 2, 'c_assoc_pair2' => null],
            ['c_assoc_code' => 2, 'c_assoc_pair' => 1, 'c_assoc_pair2' => null],
        ]);
    }

    protected function createKinshipCodesTable(): void {
        Schema::create('KINSHIP_CODES', function (Blueprint $table) {
            $table->integer('c_kincode')->primary();
            $table->integer('c_kin_pair1')->nullable();
            $table->integer('c_kin_pair2')->nullable();
        });
        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 0, 'c_kin_pair1' => null, 'c_kin_pair2' => null],
        ]);
    }

    protected function createEventTable(): void {
        Schema::create('EVENTS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_event_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_year')->nullable();
            $table->integer('c_month')->nullable();
            $table->integer('c_day')->nullable();
            $table->integer('c_day_ganzhi')->nullable();
            $table->integer('c_nh_code')->nullable();
            $table->integer('c_nh_year')->nullable();
            $table->integer('c_yr_range')->nullable();
            $table->integer('c_intercalary')->default(0);
            $table->integer('c_addr_id')->nullable();
            $table->longText('c_event')->nullable();
            $table->string('c_role', 255)->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary(['c_personid', 'c_sequence', 'c_event_code']);
        });
    }

    protected function createEventsAddrTable(): void {
        Schema::create('EVENTS_ADDR', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_event_code')->default(0);
            $table->integer('c_addr_id')->default(0);
            $table->primary(['c_personid', 'c_sequence', 'c_event_code', 'c_addr_id']);
        });
    }

    protected function createEntryTable(): void {
        Schema::create('ENTRY_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_entry_code')->default(0);
            $table->integer('c_sequence')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_year')->default(0);
            $table->integer('c_assoc_id')->default(0);
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->integer('c_entry_addr_id')->nullable();
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_entry_nh_id')->nullable();
            $table->integer('c_entry_nh_year')->nullable();
            $table->integer('c_entry_range')->nullable();
            $table->string('c_exam_rank', 255)->nullable();
            $table->integer('c_attempt_count')->nullable();
            $table->string('c_exam_field', 255)->nullable();
            $table->integer('c_parental_status_code')->nullable();
            $table->integer('c_age')->nullable();
            $table->string('c_posting_notes', 255)->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary([
                'c_personid', 'c_entry_code', 'c_sequence', 'c_kin_code',
                'c_assoc_code', 'c_kin_id', 'c_year', 'c_assoc_id',
                'c_inst_code', 'c_inst_name_code',
            ]);
        });
    }

    protected function createStatusTable(): void {
        Schema::create('STATUS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_status_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_fy_nh_code')->nullable();
            $table->integer('c_fy_nh_year')->nullable();
            $table->integer('c_fy_range')->nullable();
            $table->integer('c_lastyear')->nullable();
            $table->integer('c_ly_nh_code')->nullable();
            $table->integer('c_ly_nh_year')->nullable();
            $table->integer('c_ly_range')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_sequence', 'c_status_code']);
        });
    }

    protected function createPossessionTable(): void {
        Schema::create('POSSESSION_DATA', function (Blueprint $table) {
            $table->integer('c_possession_record_id');
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_possession_act_code')->default(0);
            $table->string('c_possession_desc', 255)->nullable();
            $table->string('c_possession_desc_chn', 255)->nullable();
            $table->string('c_quantity', 255)->nullable();
            $table->integer('c_measure_code')->default(0);
            $table->integer('c_possession_yr')->nullable();
            $table->integer('c_possession_nh_code')->nullable();
            $table->integer('c_possession_nh_yr')->nullable();
            $table->integer('c_possession_yr_range')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary('c_possession_record_id');
        });
    }

    protected function createPossessionAddrTable(): void {
        Schema::create('POSSESSION_ADDR', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_possession_record_id');
            $table->integer('c_addr_id')->default(0);
            $table->primary(['c_possession_record_id', 'c_addr_id']);
        });
    }

    protected function createPostingTable(): void {
        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id')->default(0);
            $table->integer('c_posting_id');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_fy_nh_code')->nullable();
            $table->integer('c_fy_nh_year')->nullable();
            $table->integer('c_fy_range')->nullable();
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_fy_month')->nullable();
            $table->integer('c_fy_day')->nullable();
            $table->integer('c_fy_day_gz')->nullable();
            $table->integer('c_lastyear')->nullable();
            $table->integer('c_ly_nh_code')->nullable();
            $table->integer('c_ly_nh_year')->nullable();
            $table->integer('c_ly_range')->nullable();
            $table->integer('c_ly_intercalary')->default(0);
            $table->integer('c_ly_month')->nullable();
            $table->integer('c_ly_day')->nullable();
            $table->integer('c_ly_day_gz')->nullable();
            $table->integer('c_appt_code')->default(0);
            $table->integer('c_assume_office_code')->nullable();
            $table->integer('c_dy')->nullable();
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->integer('c_office_category_id')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_office_id', 'c_posting_id']);
        });
    }

    /** officeStoreById 由 POSTING_DATA 配發 c_posting_id（max+1）。 */
    protected function createPostingIdTable(): void {
        Schema::create('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id')->primary();
            // officeStoreById 經 ToolsRepository::timestamp 蓋建檔稽核欄，缺欄會 500。
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
        });
    }

    protected function createPostingAddrTable(): void {
        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid')->default(0);
            $table->integer('c_posting_id')->default(0);
            $table->integer('c_office_id')->default(0);
            $table->integer('c_addr_id')->default(0);
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
        });
    }

    protected function createTextCodesTable(): void {
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title')->nullable();
            $table->string('c_title_chn')->nullable();
        });
        DB::table('TEXT_CODES')->insert([
            ['c_textid' => 0, 'c_title' => 'n/a', 'c_title_chn' => '未詳'],
            ['c_textid' => 500, 'c_title' => 'Book A', 'c_title_chn' => '甲書'],
        ]);
    }

    protected function createSourceTable(): void {
        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid')->default(0);
            $table->string('c_pages', 255)->default('');
            $table->longText('c_notes')->nullable();
            $table->smallInteger('c_main_source')->nullable();
            $table->smallInteger('c_self_bio')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary(['c_personid', 'c_textid', 'c_pages']);
        });
    }
}
