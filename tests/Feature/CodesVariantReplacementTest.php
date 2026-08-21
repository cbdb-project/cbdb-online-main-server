<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CharVariantMapService;
use App\Support\VariantReplaceScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S2：Codes UI 全表串接異體字落地替換。
 *
 * 見 docs/CHAR_VARIANT_MAP_TEXT_COLUMN_ROLLOUT_PLAN.md S2。Codes UI 的 5 條寫入路徑
 * （performStore／performUpdate／performProposalStore／performProposalUpdate／
 * proposalUpdateExisting）共用同一個掛鉤，且 Blade 與 React 版共用 perform*，
 * 所以一次涵蓋兩個入口。
 *
 * 註：`operations.resource_id`（序列化主鍵）在 Codes UI 這條路徑上**結構上不可能**被替換
 * ——該表的主鍵欄要嘛是數字，要嘛是已排除的代碼鍵（`ENTRY_TYPES.c_entry_type`、
 * `SOCIAL_INSTITUTION_TYPES.c_inst_type_code` 等）。用數字主鍵去斷言「它沒被改寫」
 * 證明不了任何事，所以這裡不做那條測試；排除本身由 `VariantReplaceScopeTest` 斷言。
 */
class CodesVariantReplacementTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiled = sys_get_temp_dir().'/cbdb-test-views-codes-variant-replacement';
        if (!is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }
        config(['view.compiled' => $compiled]);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config(['codes.tables' => [
            'ADDR_CODES' => '地址代碼',
            'ALTNAME_DATA' => '人物別名資料表',
            'char_variant_map' => '異體字落地替換對照表',
            'pinyin' => '拼音字典',
        ]]);
        config(['codes.ui_hidden' => []]);

        Schema::create('users', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('confirmation_token')->nullable();
            $t->tinyInteger('is_active')->default(0);
            $t->tinyInteger('is_admin')->default(0);
            $t->timestamps();
        });

        // 代表「一般代碼表」：有中文欄、拼音欄、數字欄
        Schema::create('ADDR_CODES', function ($t) {
            $t->integer('c_addr_id')->primary();
            $t->string('c_name_chn', 255)->nullable();
            $t->string('c_name', 255)->nullable();
            $t->integer('c_admin_type')->nullable();
            $t->string('c_notes', 255)->nullable();
        });

        // 文本主鍵成員（c_alt_name_chn）且在替換範圍內（strict）——Codes UI 裡
        // 唯一會踩到 D7「兩形並存」去重問題的形狀。
        Schema::create('ALTNAME_DATA', function ($t) {
            $t->integer('c_personid');
            $t->string('c_alt_name_chn', 255);
            $t->integer('c_alt_name_type_code');
            $t->string('c_notes', 255)->nullable();
            // ⚠️ 主鍵順序必須與 production 一致：
            // (c_alt_name_chn, c_alt_name_type_code, c_personid)
            // ——見 database/migrations/2025_01_01_000000_import_cbdb_schema.php:168。
            // 這個順序讓**可替換的文字欄排在第一位**，正是 resource_id 前綴收斂會失效
            // 的情形；早期版本把 c_personid 排前面，於是測不到 production 的實際行為。
            $t->primary(['c_alt_name_chn', 'c_alt_name_type_code', 'c_personid']);
        });

        Schema::create('char_variant_map', function ($t) {
            $t->bigIncrements('id');
            $t->string('c_variant_char', 10);
            $t->string('c_reference_char', 10);
            $t->tinyInteger('c_strict_excluded')->default(1);
            $t->string('c_notes', 255)->nullable();
            $t->timestamps();

            $t->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });

        Schema::create('pinyin', function ($t) {
            $t->bigIncrements('id');
            $t->string('c_chn', 10);
            $t->string('c_pinyin', 255)->nullable();
        });

        Schema::create('operations', function ($t) {
            $t->increments('id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->integer('c_personid')->default(0);
            $t->string('resource')->nullable();
            $t->text('resource_id')->nullable();
            $t->string('op_type')->nullable();
            $t->longText('resource_data')->nullable();
            $t->longText('resource_original')->nullable();
            $t->integer('crowdsourcing_status')->default(0);
            $t->timestamps();
        });

        Schema::create('audit_log', function ($t) {
            $t->bigIncrements('id');
            $t->dateTime('occurred_at');
            $t->dateTime('created_at');
            $t->string('table_name', 64);
            $t->string('operation', 16);
            $t->string('actor_type', 32);
            $t->string('actor_id', 128);
            $t->string('operation_id', 64);
            $t->text('row_pk');
            $t->string('row_pk_text', 512)->nullable();
            $t->longText('old_data')->nullable();
            $t->longText('new_data')->nullable();
        });

        $this->seedMappings();

        CharVariantMapService::reset();
        VariantReplaceScope::reset();
    }

    protected function tearDown(): void {
        foreach (['audit_log', 'operations', 'pinyin', 'char_variant_map', 'ALTNAME_DATA', 'ADDR_CODES', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function seedMappings(): void {
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
        ]);
    }

    private function activeUser(): User {
        return User::forceCreate([
            'name' => 'U', 'email' => 'u@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    /** @return array<int,string> flash 訊息 */
    private function flashMessages(): array {
        $flash = session('flash_notification', collect())->toArray();

        return array_map(fn ($item) => (string) ($item['message'] ?? ''), $flash);
    }

    private function assertFlashContains(string $needle): void {
        $messages = $this->flashMessages();
        $this->assertTrue(
            collect($messages)->contains(fn (string $m) => str_contains($m, $needle)),
            "flash 訊息不含「{$needle}」，實際為：".implode(' | ', $messages)
        );
    }

    // ─────────────────────── direct 寫入（performStore／performUpdate） ───────────────────────

    #[Test]
    public function testStoreReplacesChineseTextColumnAndFlashesNotice(): void {
        $this->actingAs($this->activeUser());

        $this->post('/codes/ADDR_CODES', [
            'c_addr_id' => 1,
            'c_name_chn' => '淸河縣',
            'c_name' => 'Qinghe',
            'c_admin_type' => 3,
        ])->assertStatus(302);

        $this->assertSame('清河縣', DB::table('ADDR_CODES')->where('c_addr_id', 1)->value('c_name_chn'));
        // 拼音欄是拉丁字串 ⇒ 必然 no-op（對照表 key 全是 CJK）
        $this->assertSame('Qinghe', DB::table('ADDR_CODES')->where('c_addr_id', 1)->value('c_name'));
        // 數字欄不受影響
        $this->assertSame(3, (int) DB::table('ADDR_CODES')->where('c_addr_id', 1)->value('c_admin_type'));

        $this->assertFlashContains('清');
    }

    #[Test]
    public function testUpdateReplacesChineseTextColumnAndFlashesNotice(): void {
        $this->actingAs($this->activeUser());
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 1, 'c_name_chn' => '某縣']);

        $this->put('/codes/ADDR_CODES/1', [
            'c_addr_id' => 1,
            'c_name_chn' => '淸河縣',
        ])->assertStatus(302);

        $this->assertSame('清河縣', DB::table('ADDR_CODES')->where('c_addr_id', 1)->value('c_name_chn'));
        $this->assertFlashContains('清');
    }

    /** 沒有命中任何對照時不該冒出通知（避免每次儲存都跳訊息）。 */
    #[Test]
    public function testNoNoticeWhenNothingWasReplaced(): void {
        $this->actingAs($this->activeUser());

        $this->post('/codes/ADDR_CODES', [
            'c_addr_id' => 2,
            'c_name_chn' => '無異體字',
        ])->assertStatus(302);

        $messages = $this->flashMessages();
        $this->assertFalse(
            collect($messages)->contains(fn (string $m) => str_contains($m, '正規化')),
            '沒有替換時不該出現異體字通知：'.implode(' | ', $messages)
        );
    }

    // ─────────────────────── 排除清單在真實路徑上生效 ───────────────────────

    /**
     * 對照表自身整表排除——否則新增一筆「淸→清」之後，任何含「淸」的既有列
     * （包括那筆對照自己的 c_variant_char）都會被改寫，對照表自我吞噬。
     */
    #[Test]
    public function testCharVariantMapItselfIsNeverReplaced(): void {
        $this->actingAs($this->activeUser());

        $this->post('/codes/char_variant_map', [
            'id' => 900,
            'c_variant_char' => '龴',
            'c_reference_char' => '乂',
            'c_strict_excluded' => 1,
            'c_notes' => '淸峯',
        ])->assertStatus(302);

        $row = DB::table('char_variant_map')->where('c_variant_char', '龴')->first();
        $this->assertNotNull($row);
        $this->assertSame('淸峯', $row->c_notes, '對照表自身的欄位不可被落地替換');

        // 正向對照：同一條路徑對**沒有**被排除的表確實會替換。少了這一段，
        // 上面的斷言在「掛鉤根本不存在」時也會綠（純負向斷言的假綠）。
        $this->post('/codes/ADDR_CODES', [
            'c_addr_id' => 77,
            'c_name_chn' => '淸',
        ])->assertStatus(302);
        $this->assertSame(
            '清',
            DB::table('ADDR_CODES')->where('c_addr_id', 77)->value('c_name_chn'),
            '正向對照失敗：這條路徑根本沒有掛上落地替換，上面的排除斷言等於假綠'
        );
    }

    /**
     * 拼音字典的鍵排除——第一階段明文設計「異體字各自在 pinyin 表有自己的讀音」，
     * 替換這欄會直接破壞該設計。
     */
    #[Test]
    public function testPinyinDictionaryKeyIsNeverReplaced(): void {
        $this->actingAs($this->activeUser());

        $this->post('/codes/pinyin', [
            'id' => 901,
            'c_chn' => '淸',
            'c_pinyin' => 'qing',
        ])->assertStatus(302);

        $this->assertSame(
            1,
            DB::table('pinyin')->where('c_chn', '淸')->count(),
            'pinyin.c_chn 必須保持原字形，否則異體字就沒有自己的讀音條目'
        );

        // 正向對照（同上，防假綠）
        $this->post('/codes/ADDR_CODES', [
            'c_addr_id' => 78,
            'c_name_chn' => '淸',
        ])->assertStatus(302);
        $this->assertSame(
            '清',
            DB::table('ADDR_CODES')->where('c_addr_id', 78)->value('c_name_chn'),
            '正向對照失敗：這條路徑根本沒有掛上落地替換'
        );
    }

    // ─────────────────────── char_variant_map 的結構把關 ───────────────────────

    #[Test]
    public function testStoreRejectsMultiCodepointMapping(): void {
        $this->actingAs($this->activeUser());

        $this->post('/codes/char_variant_map', [
            'id' => 902,
            'c_variant_char' => '甲乙',
            'c_reference_char' => '丙',
            'c_strict_excluded' => 1,
        ])->assertStatus(302);

        $this->assertSame(0, DB::table('char_variant_map')->where('c_variant_char', '甲乙')->count());
        $this->assertFlashContains(__('variant.single_codepoint_required'));
    }

    #[Test]
    public function testStoreRejectsMappingThatWouldCreateACycle(): void {
        $this->actingAs($this->activeUser());

        // 表裡已有 淸→清；新增 清→淸 會成環
        $this->post('/codes/char_variant_map', [
            'id' => 903,
            'c_variant_char' => '清',
            'c_reference_char' => '淸',
            'c_strict_excluded' => 1,
        ])->assertStatus(302);

        $this->assertSame(0, DB::table('char_variant_map')->where('c_variant_char', '清')->count());
        $this->assertFlashContains(__('variant.cycle_not_allowed', ['char' => '清']));
    }

    /**
     * 更新既有列時必須排除它自己的舊邊，否則會誤報環。
     *
     * 表有 淸→清；把那一列改成 淸→菁 是完全合法的，但若把被取代的舊邊（淸→清）
     * 也算進去，就會看到假的環。
     */
    #[Test]
    public function testUpdatingAnExistingMappingIsNotFalselyReportedAsACycle(): void {
        $this->actingAs($this->activeUser());
        $id = DB::table('char_variant_map')->where('c_variant_char', '淸')->value('id');

        $this->put("/codes/char_variant_map/{$id}", [
            'id' => $id,
            'c_variant_char' => '淸',
            'c_reference_char' => '菁',
            'c_strict_excluded' => 0,
        ])->assertStatus(302);

        $this->assertSame(
            '菁',
            DB::table('char_variant_map')->where('id', $id)->value('c_reference_char'),
            '更新既有對照不該被誤判成環'
        );
    }

    /** 寫入對照表之後，行程內快取要被清掉，下一次替換才會讀到新對照。 */
    #[Test]
    public function testWritingTheMappingTableResetsTheInProcessCache(): void {
        $this->actingAs($this->activeUser());

        // 先讓快取暖起來（此時「厰」還沒有對照）
        $this->assertSame('厰', CharVariantMapService::replaceLenient('厰')['text']);

        $this->post('/codes/char_variant_map', [
            'id' => 904,
            'c_variant_char' => '厰',
            'c_reference_char' => '廠',
            'c_strict_excluded' => 0,
        ])->assertStatus(302);

        $this->assertSame(
            '廠',
            CharVariantMapService::replaceLenient('厰')['text'],
            '新增對照後快取應已清除，替換要立刻生效'
        );
    }

    // ─────────────────────── 提案路徑 ───────────────────────

    /**
     * 提案路徑存進 operations.resource_data 的必須是**替換後**的值——核准端不做前處理，
     * 所以若這裡存原字形，核准落庫就會是原字形。
     */
    #[Test]
    public function testProposalStoreRecordsReplacedValues(): void {
        $user = User::forceCreate([
            'name' => 'P', 'email' => 'p@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => 0,
        ]);
        $this->actingAs($user);

        $this->post('/codes/ADDR_CODES/proposal', [
            'c_addr_id' => 9,
            'c_name_chn' => '淸河縣',
        ])->assertStatus(302);

        $operation = DB::table('operations')->where('resource', 'ADDR_CODES')->first();
        $this->assertNotNull($operation, '提案應被記錄');

        $payload = json_decode((string) $operation->resource_data, true);
        $this->assertSame('清河縣', $payload['c_name_chn'] ?? null, '提案 payload 必須是替換後的值');
    }

    /**
     * performProposalUpdate（修改提案）：payload 必須是替換後的值，且要有通知。
     *
     * 這條路徑原本零測試覆蓋——而「使用者原樣送出一列含異體字的既有資料、於是產生一筆
     * 他自己沒察覺的提案」這個行為就住在這裡。
     */
    #[Test]
    public function testProposalUpdateRecordsReplacedValuesAndNotifies(): void {
        $user = User::forceCreate([
            'name' => 'P', 'email' => 'pu@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => 0,
        ]);
        $this->actingAs($user);

        DB::table('ADDR_CODES')->insert(['c_addr_id' => 21, 'c_name_chn' => '淸河縣']);

        // 使用者把表單原樣送回（值與既有列一致），替換仍會把它歸一成參考字形
        $this->post('/codes/ADDR_CODES/21/proposal', [
            'c_addr_id' => 21,
            'c_name_chn' => '淸河縣',
        ])->assertStatus(302);

        $operation = DB::table('operations')->where('resource', 'ADDR_CODES')->first();
        $this->assertNotNull($operation, '修改提案應被記錄');

        $payload = json_decode((string) $operation->resource_data, true);
        $this->assertSame('清河縣', $payload['c_name_chn'] ?? null, '提案 payload 必須是替換後的值');

        // 使用者沒有主動改任何字，所以一定要告知系統改了什麼——否則他會拿到一筆
        // 自己沒察覺的提案。
        $this->assertFlashContains('清');
    }

    /**
     * proposalUpdateExisting（編輯既有提案）：修改一筆 char_variant_map 的 update 提案
     * **不可**被誤報成環。
     *
     * 表有 乙→甲 與 甲→丙；把那筆提案改成 丙→乙 的最終狀態是合法的 甲→丙→乙，
     * 但若 guard 沒有排除「被取代的舊邊」，就會看到假的環 乙→甲→丙→乙 而擋下合法修改。
     */
    #[Test]
    public function testEditingAnExistingMappingProposalIsNotFalselyReportedAsACycle(): void {
        $user = User::forceCreate([
            'name' => 'P', 'email' => 'pe@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => 0,
        ]);
        $this->actingAs($user);

        DB::table('char_variant_map')->truncate();
        DB::table('char_variant_map')->insert([
            ['id' => 2, 'c_variant_char' => '乙', 'c_reference_char' => '甲', 'c_strict_excluded' => 1],
            ['id' => 3, 'c_variant_char' => '甲', 'c_reference_char' => '丙', 'c_strict_excluded' => 1],
        ]);
        CharVariantMapService::reset();

        // 一筆針對 id=2 的 update 提案
        $operationId = DB::table('operations')->insertGetId([
            'user_id' => $user->id,
            'c_personid' => 0,
            'resource' => 'char_variant_map',
            'resource_id' => '2',
            'op_type' => \App\Models\Operation::TYPE_PROPOSAL_UPDATE,
            'resource_data' => json_encode([
                'id' => 2,
                'c_variant_char' => '乙',
                'c_reference_char' => '甲',
                'c_strict_excluded' => 1,
                '__proposal_meta' => ['submitted_by_id' => $user->id],
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'id' => 2, 'c_variant_char' => '乙', 'c_reference_char' => '甲', 'c_strict_excluded' => 1,
            ], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);

        // 刻意**不送 id**：excludeId 必須取自權威來源 operation.resource_id。
        // 舊寫法讀 request body 的 id，缺值時 (int)'' = 0 ⇒ 不排除 id=2 的舊邊
        // ⇒ 合法修改被誤報成環。這一行就是 #3 那個改動的全部意義。
        $this->patch("/codes/char_variant_map/proposals/{$operationId}", [
            'c_variant_char' => '丙',
            'c_reference_char' => '乙',
            'c_strict_excluded' => 1,
        ])->assertStatus(302);

        $payload = json_decode(
            (string) DB::table('operations')->where('id', $operationId)->value('resource_data'),
            true
        );
        $this->assertSame('丙', $payload['c_variant_char'] ?? null, '合法的提案修改不該被誤判成環而擋下');
        $this->assertSame('乙', $payload['c_reference_char'] ?? null);
    }

    /**
     * 最誤導的那條錯誤訊息必須附上通知。
     *
     * 既有列已是參考字形「清」，使用者輸入變體「淸」→ 替換後 payload 等於原值 →
     * diff 為 null → 使用者被告知「你什麼都沒改」，而 `withInput()` 回填的還是他打的「淸」。
     * 沒有通知的話他完全無法理解發生了什麼。
     */
    #[Test]
    public function testNoDiffProposalStillTellsTheUserTheGlyphWasNormalized(): void {
        $user = User::forceCreate([
            'name' => 'P', 'email' => 'pn@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => 0,
        ]);
        $this->actingAs($user);

        // 既有列已經是參考字形
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 31, 'c_name_chn' => '清河縣']);

        // 使用者送出變體字形 → 被替換回「清河縣」→ 與既有列一致 → 無 diff
        $this->post('/codes/ADDR_CODES/31/proposal', [
            'c_addr_id' => 31,
            'c_name_chn' => '淸河縣',
        ])->assertStatus(302);

        $this->assertSame(0, DB::table('operations')->count(), '無 diff 不該記提案');
        $this->assertFlashContains('未偵測到任何修改內容');
        $this->assertFlashContains(__('variant.notice_pair', ['variant' => '淸', 'reference' => '清']));
    }

    /**
     * 提案的「資料已存在」也要附通知——衝突可能是落地替換自己造成的
     * （使用者打的是自認為不同的字形）。
     */
    #[Test]
    public function testProposalStoreDuplicateConflictTellsTheUserTheGlyphWasNormalized(): void {
        $user = User::forceCreate([
            'name' => 'P', 'email' => 'pd@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => 0,
        ]);
        $this->actingAs($user);

        DB::table('ADDR_CODES')->insert(['c_addr_id' => 41, 'c_name_chn' => '清河縣']);

        // 主鍵撞既有列 → 走「資料已存在，請改用修改提案」
        $this->post('/codes/ADDR_CODES/proposal', [
            'c_addr_id' => 41,
            'c_name_chn' => '淸河縣',
        ])->assertStatus(302);

        $this->assertFlashContains('資料已存在');
        $this->assertFlashContains(__('variant.notice_pair', ['variant' => '淸', 'reference' => '清']));
    }

    // ─────────────── D7：兩形並存的去重（文本主鍵成員） ───────────────

    /**
     * 既有列的文本主鍵還是變體形時，輸入同樣的變體形**不可**鑄出第二列。
     *
     * D6 不做回溯校正 ⇒ 既有列的 `c_alt_name_chn` 可能還是「淸」。使用者輸入「淸」，
     * 替換把它歸一成「清」，於是**只用替換後的值查重就會錯過那一列**，插入語義重複的
     * 第二列——比不替換更糟。資料庫唯一鍵也擋不住（兩個字形是不同的鍵值）。
     */
    #[Test]
    public function testCreateDoesNotMintADuplicateWhenTheExistingRowStillHasTheVariantForm(): void {
        $this->actingAs($this->activeUser());

        // 既有列仍是變體形（D6：不回溯）
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1, 'c_alt_name_chn' => '淸客', 'c_alt_name_type_code' => 4,
        ]);

        $this->post('/codes/ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '淸客',
            'c_alt_name_type_code' => 4,
        ])->assertStatus(302);

        $this->assertSame(
            1,
            DB::table('ALTNAME_DATA')->count(),
            '替換後查不到既有的變體形列，鑄出了語義重複的第二列'
        );
        $this->assertFlashContains('主鍵或唯一值已存在');
        $this->assertFlashContains(__('variant.notice_pair', ['variant' => '淸', 'reference' => '清']));
    }

    /** 提案路徑同理：不可對既有的變體形列開一筆「新增提案」。 */
    #[Test]
    public function testProposalStoreDoesNotProposeADuplicateOfAVariantFormRow(): void {
        $user = User::forceCreate([
            'name' => 'P', 'email' => 'pv@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => 0,
        ]);
        $this->actingAs($user);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 2, 'c_alt_name_chn' => '淸客', 'c_alt_name_type_code' => 4,
        ]);

        $this->post('/codes/ALTNAME_DATA/proposal', [
            'c_personid' => 2,
            'c_alt_name_chn' => '淸客',
            'c_alt_name_type_code' => 4,
        ])->assertStatus(302);

        $this->assertSame(0, DB::table('operations')->count(), '不該為既有的變體形列開新增提案');
        $this->assertFlashContains('資料已存在');
    }

    /**
     * **不同**變體收斂到同一參考字時，也不可鑄出重複列。
     *
     * 對照是多對一（`c_variant_char` 有唯一鍵、`c_reference_char` 沒有），所以只探
     * 「輸入值 + 替換後值」兩形仍會漏掉「既有列是另一個變體」的情形：
     * 既有 `菁客`（菁→青）、新輸入 `靑客`（靑→青），兩者都歸一成 `青客`，
     * 但拿 `靑客`／`青客` 去查都找不到 `菁客`。
     */
    #[Test]
    public function testCreateDoesNotMintADuplicateWhenADifferentVariantConvergesToTheSameReference(): void {
        $this->actingAs($this->activeUser());

        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '菁', 'c_reference_char' => '青', 'c_strict_excluded' => 0],
            ['c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0],
        ]);
        CharVariantMapService::reset();

        // 既有列是**另一個**變體形
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 7, 'c_alt_name_chn' => '菁客', 'c_alt_name_type_code' => 4,
        ]);

        $this->post('/codes/ALTNAME_DATA', [
            'c_personid' => 7,
            'c_alt_name_chn' => '靑客',
            'c_alt_name_type_code' => 4,
        ])->assertStatus(302);

        $this->assertSame(
            1,
            DB::table('ALTNAME_DATA')->count(),
            '兩個不同變體都歸一成「青客」，不該因為既有列是另一個變體就鑄出第二列'
        );
    }

    /**
     * 待審的舊版（變體形）新增提案也要被視為衝突。
     *
     * S2 之前留下的 pending 提案帶的是變體形 `resource_id`，新提案帶的是歸一後的
     * `resource_id`，完全相等比對不會衝突 ⇒ 兩筆待審提案並存，依序核准就落成兩筆列。
     */
    #[Test]
    public function testProposalStoreConflictsWithAPendingProposalThatUsesTheVariantForm(): void {
        $user = User::forceCreate([
            'name' => 'P', 'email' => 'pp@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => 0,
        ]);
        $this->actingAs($user);

        // 既有的待審提案用變體形主鍵（S2 之前留下的樣子）
        DB::table('operations')->insert([
            'user_id' => $user->id,
            'c_personid' => 0,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '淸客_._4_._9',
            'op_type' => \App\Models\Operation::TYPE_PROPOSAL_CREATE,
            'resource_data' => json_encode([
                'c_personid' => 9, 'c_alt_name_chn' => '淸客', 'c_alt_name_type_code' => 4,
                // hasPendingCreateProposal() 只認 __review_status 為 pending／rejected 的提案
                '__review_status' => 'pending',
                '__proposal_meta' => ['submitted_by_id' => $user->id],
            ], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);

        $this->post('/codes/ALTNAME_DATA/proposal', [
            'c_personid' => 9,
            'c_alt_name_chn' => '淸客',
            'c_alt_name_type_code' => 4,
        ])->assertStatus(302);

        $this->assertSame(
            1,
            DB::table('operations')->count(),
            '不該與既有的變體形待審提案並存——兩筆依序核准會落成兩種字形的兩筆列'
        );
        $this->assertFlashContains('已有其他新增提案使用相同主鍵');
    }

    /**
     * 去重必須**不受對照表規模影響**——早期版本靠「列舉所有等價字形再逐一查」，
     * 為了避免組合爆炸設了上限，而超過上限就退回只比對正規形，**等於完全失去這道去重**。
     * 那不是效能取捨，是可由合法對照表資料觸發的正確性缺口。
     *
     * 這裡讓一個參考字有 6 個變體、主鍵含 2 個這樣的字（完整組合 7×7 = 49 > 舊上限 32），
     * 現行做法（固定其餘主鍵欄查一次、在 PHP 端歸一比對）必須照樣抓到。
     */
    #[Test]
    public function testDedupIsExactRegardlessOfHowManyVariantsShareAReferenceChar(): void {
        $this->actingAs($this->activeUser());

        $rows = [];
        foreach (['㆒', '㆓', '㆔', '㆕', '㆖', '㆗'] as $variant) {
            $rows[] = ['c_variant_char' => $variant, 'c_reference_char' => '甲', 'c_strict_excluded' => 0];
        }
        DB::table('char_variant_map')->insert($rows);
        CharVariantMapService::reset();

        // 既有列用其中兩個變體（歸一後是「甲甲」）
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 51, 'c_alt_name_chn' => '㆒㆓', 'c_alt_name_type_code' => 4,
        ]);

        // 新輸入用**另外兩個**變體，歸一後同樣是「甲甲」
        $this->post('/codes/ALTNAME_DATA', [
            'c_personid' => 51,
            'c_alt_name_chn' => '㆔㆕',
            'c_alt_name_type_code' => 4,
        ])->assertStatus(302);

        $this->assertSame(
            1,
            DB::table('ALTNAME_DATA')->count(),
            '等價字形組合數超過舊上限時仍必須抓到重複——去重不可因對照表規模而失效'
        );
    }

    /**
     * resource_id 前綴收斂**不可**把該抓的漏掉：不同 `c_personid` 的提案不該互相衝突，
     * 同一個 `c_personid` 的變體形提案則必須抓到。
     */
    #[Test]
    public function testPendingProposalConflictIsScopedByTheLeadingKeyColumn(): void {
        $user = User::forceCreate([
            'name' => 'P', 'email' => 'ps@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => 0,
        ]);
        $this->actingAs($user);

        // 另一個人物（c_personid 不同）的變體形待審提案 ⇒ 不該衝突
        DB::table('operations')->insert([
            'user_id' => $user->id,
            'c_personid' => 0,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '淸客_._4_._61',
            'op_type' => \App\Models\Operation::TYPE_PROPOSAL_CREATE,
            'resource_data' => json_encode([
                'c_personid' => 61, 'c_alt_name_chn' => '淸客', 'c_alt_name_type_code' => 4,
                '__review_status' => 'pending',
                '__proposal_meta' => ['submitted_by_id' => $user->id],
            ], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);

        $this->post('/codes/ALTNAME_DATA/proposal', [
            'c_personid' => 62,
            'c_alt_name_chn' => '淸客',
            'c_alt_name_type_code' => 4,
        ])->assertStatus(302);

        $this->assertSame(2, DB::table('operations')->count(), '不同人物的同名提案不該互相攔截');
    }

    /** 對照組：主鍵真的不同時，仍要能正常新增（去重不可過度攔截）。 */
    #[Test]
    public function testCreateStillSucceedsWhenTheKeyGenuinelyDiffers(): void {
        $this->actingAs($this->activeUser());

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 3, 'c_alt_name_chn' => '淸客', 'c_alt_name_type_code' => 4,
        ]);

        $this->post('/codes/ALTNAME_DATA', [
            'c_personid' => 3,
            'c_alt_name_chn' => '淸客',
            'c_alt_name_type_code' => 5, // 不同的 type code ⇒ 不同主鍵
        ])->assertStatus(302);

        $this->assertSame(2, DB::table('ALTNAME_DATA')->count());
        $this->assertSame(
            1,
            DB::table('ALTNAME_DATA')->where('c_alt_name_chn', '清客')->count(),
            '新列應以參考字形落庫'
        );
    }

    /** performUpdate 也要擋多字元對照（既有測試只涵蓋 performStore）。 */
    #[Test]
    public function testUpdateRejectsMultiCodepointMapping(): void {
        $this->actingAs($this->activeUser());
        $id = DB::table('char_variant_map')->where('c_variant_char', '淸')->value('id');

        $this->put("/codes/char_variant_map/{$id}", [
            'id' => $id,
            'c_variant_char' => '淸',
            'c_reference_char' => '兩個字',
            'c_strict_excluded' => 0,
        ])->assertStatus(302);

        $this->assertSame(
            '清',
            DB::table('char_variant_map')->where('id', $id)->value('c_reference_char'),
            '多字元參考字不該被寫入'
        );
        $this->assertFlashContains(__('variant.single_codepoint_required'));
    }
}
