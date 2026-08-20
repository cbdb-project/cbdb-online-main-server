<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CharVariantMapService;
use App\Services\PinyinDictionary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsPinyinDictionary;
use Tests\TestCase;

class AdminBatchLoadBookTitlesTest extends TestCase {
    use SeedsPinyinDictionary;

    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->string('avatar')->nullable();
            $table->json('settings')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title_chn')->nullable();
            $table->string('c_title')->nullable();
            $table->string('c_text_type_id')->nullable();
            $table->string('c_text_dy')->nullable();
            $table->string('c_source')->nullable();
            $table->longText('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_dy')->nullable();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            // Production schema (database/migrations/2025_01_01_000000_import_cbdb_schema.php:2306-2318)
            // declares operations.c_personid as int NOT NULL with a FK to
            // BIOG_MAIN(c_personid). We mirror only the NOT NULL part here, not
            // the FK: enforcing the FK in tests would require seeding a
            // c_personid=0 stub row in BIOG_MAIN (the codebase-wide sentinel that
            // every batch importer writes for non-person resources via empty
            // string -> 0 cast), which would ripple through the rest of the
            // batch-importer test suite. The unit under test here is the
            // null-vs-0 coercion in OperationsController::recordRestoreOperation,
            // which the NOT NULL constraint alone is sufficient to verify.
            $table->integer('c_personid');
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->longText('resource_data');
            $table->longText('resource_original')->nullable();
            $table->timestamps();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
        });

        // TextImportService（store() 已改走聚合根）除 operations 外亦寫 audit_log。
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

        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn');
            $table->string('c_pinyin')->nullable();
            $table->tinyInteger('c_lastname')->default(0);
            $table->unique(['c_chn', 'c_lastname']);
        });

        // char_variant_map：與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php
        // 相同的 7 筆種子資料，供 standardizeTitleVariants() 走 CharVariantMapService::replaceLenient()。
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

        // 書名逐字轉拼音走一般轉換路徑，需要真實字典資料才能跟現行
        // Pinyin::$dic 行為一致（見 docs/PINYIN_TABLE_CONSOLIDATION_PLAN.md 步驟4）。
        $this->seedPinyinDictionary();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('pinyin');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    protected function makeUser(array $attributes = []): User {
        $user = new User([
            'name' => 'Batch Admin',
            'email' => uniqid('admin', true).'@example.com',
            'password' => bcrypt('secret'),
            'avatar' => 'avatar0.png',
            'confirmation_token' => Str::random(10),
        ]);

        foreach ($attributes as $key => $value) {
            $user->{$key} = $value;
        }

        if (!isset($attributes['is_active'])) {
            $user->is_active = 1;
        }

        if (!isset($attributes['is_admin'])) {
            $user->is_admin = 1;
        }

        $user->save();

        return $user;
    }

    #[Test]
    public function testUpdatePinyinNormalizesVToUmlaut(): void {
        // §D-6：inline 編輯書名拼音 c_title（Tier 1）應套 v→ü，修正與批次 buildPinyin 的不一致。
        $this->actingAs($this->makeUser());
        $batch = '20250101090000-ABCDEF';
        DB::table('TEXT_CODES')->insert(['c_textid' => 7001, 'c_title_chn' => '呂齋', 'c_title' => null, 'c_notes' => '['.$batch.']']);

        $this->postJson(route('admin.batch-load-book-titles.update-pinyin'), [
            'c_textid' => 7001,
            'batch_id' => $batch,
            'pinyin' => 'Lvzhai',
        ])->assertOk();

        // normalizePinyinInput 先小寫，再 PinyinUmlaut：Lvzhai → lvzhai → lüzhai
        $this->assertSame('lüzhai', DB::table('TEXT_CODES')->where('c_textid', 7001)->value('c_title'));
    }

    #[Test]
    public function test_non_admin_cannot_access_page(): void {
        $user = $this->makeUser(['is_admin' => 0]);
        $this->actingAs($user);

        $response = $this->get(route('admin.batch-load-book-titles'));
        $response->assertStatus(403);
    }

    #[Test]
    public function test_admin_can_view_form(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->get(route('admin.batch-load-book-titles'));
        $response->assertStatus(200)->assertSee('批次匯入書稿資料');
    }

    #[Test]
    public function test_admin_can_upload_batch_entries(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 12345,
            'c_dy' => '88',
        ]);
        DB::table('TEXT_CODES')->insert([
            'c_textid' => 54321,
            'c_title_chn' => '來源書',
        ]);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "12345\t測試稿: 卷一\t54321",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));

        $record = DB::table('TEXT_CODES')->where('c_textid', 54322)->first();
        $this->assertNotNull($record);
        $this->assertSame('測試稿: 卷一', $record->c_title_chn);
        $this->assertSame('ce shi gao', $record->c_title);
        $this->assertSame('01', $record->c_text_type_id);
        $this->assertSame('Batch Admin', $record->c_created_by);
        $this->assertSame('88', $record->c_text_dy);
        $this->assertSame('54321', $record->c_source);
        $this->assertMatchesRegularExpression('/^\[[0-9]{14}-[0-9A-F]{6}\]$/', $record->c_notes);
        $this->assertNull($record->c_modified_by);
        $this->assertNull($record->c_modified_date);

        $operation = DB::table('operations')->where('resource', 'TEXT_CODES')->first();
        $this->assertNotNull($operation);
        $this->assertSame((string) $record->c_textid, $operation->resource_id);
        $encoded = json_decode($operation->resource_data, true);
        $this->assertSame('ce shi gao', $encoded['c_title']);
        // TextImportService（聚合根）落庫前把 c_source 轉整數（prod 欄位本為 int），
        // operations 快照隨之為整數；原內聯實作存的是使用者輸入字串。
        $this->assertSame(54321, $encoded['c_source']);
        $this->assertSame($record->c_notes, $encoded['c_notes']);

        $followUp = $this->get(route('admin.batch-load-book-titles'));
        $followUp->assertSee('本次批次編號');
        $followUp->assertSee('書名拼音');
        $followUp->assertSee('批次編號');
    }

    #[Test]
    public function test_invalid_lines_are_reported(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "abc\t\n",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));

        $followUp = $this->get(route('admin.batch-load-book-titles'));
        $followUp->assertSee('匯入失敗');
        $followUp->assertSee('未找到三欄資料');
        $this->assertSame(0, DB::table('TEXT_CODES')->count());
    }

    #[Test]
    public function test_fullwidth_parentheses_are_converted(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 100,
            'c_dy' => '1',
        ]);
        DB::table('TEXT_CODES')->insert(['c_textid' => 99999, 'c_title_chn' => '來源']);

        $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "100\t測試稿（附錄）\t99999",
        ]);

        $record = DB::table('TEXT_CODES')->orderByDesc('c_textid')->first();
        $this->assertNotNull($record);
        $this->assertSame('測試稿(附錄)', $record->c_title_chn);
    }

    #[Test]
    public function test_fullwidth_colon_is_converted_with_single_space(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 101,
            'c_dy' => '2',
        ]);
        DB::table('TEXT_CODES')->insert(['c_textid' => 99998, 'c_title_chn' => '來源']);

        $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "101\t測試稿：卷一\t99998",
        ]);

        $record = DB::table('TEXT_CODES')->orderByDesc('c_textid')->first();
        $this->assertNotNull($record);
        $this->assertSame('測試稿: 卷一', $record->c_title_chn);
    }

    #[Test]
    public function test_fullwidth_colon_with_spaces_does_not_add_extra(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 102,
            'c_dy' => '3',
        ]);
        DB::table('TEXT_CODES')->insert(['c_textid' => 99997, 'c_title_chn' => '來源']);

        $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "102\t測試稿：  卷一\t99997",
        ]);

        $record = DB::table('TEXT_CODES')->orderByDesc('c_textid')->first();
        $this->assertNotNull($record);
        // Spaces are removed first, then colon adds exactly one space
        $this->assertSame('測試稿: 卷一', $record->c_title_chn);
    }

    #[Test]
    public function test_combined_fullwidth_punctuation_normalization(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 103,
            'c_dy' => '4',
        ]);
        DB::table('TEXT_CODES')->insert(['c_textid' => 99996, 'c_title_chn' => '來源']);

        $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "103\t測試稿（附錄）： 卷一\t99996",
        ]);

        $record = DB::table('TEXT_CODES')->orderByDesc('c_textid')->first();
        $this->assertNotNull($record);
        $this->assertSame('測試稿(附錄): 卷一', $record->c_title_chn);
    }

    #[Test]
    public function test_blank_source_is_rejected(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "12345\t測試稿\t",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $response->assertSessionHas('batch_errors');
        $errors = $response->getSession()->get('batch_errors', []);
        $this->assertNotEmpty($errors);

        $followUp = $this->get(route('admin.batch-load-book-titles'));
        $followUp->assertSee('匯入失敗');
        $this->assertSame(0, DB::table('TEXT_CODES')->count());
    }

    #[Test]
    public function test_unknown_author_id_is_rejected(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('TEXT_CODES')->insert(['c_textid' => 700, 'c_title_chn' => '來源']);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "9999999\t測試書\t700",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $errors = $response->getSession()->get('batch_errors', []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('不存在於 BIOG_MAIN', implode("\n", $errors));
        $this->assertSame(1, DB::table('TEXT_CODES')->count());
        $this->assertSame(0, DB::table('operations')->count());
    }

    #[Test]
    public function test_unknown_source_text_id_is_rejected(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 200, 'c_dy' => '5']);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "200\t測試書\t8888888",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $errors = $response->getSession()->get('batch_errors', []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('不存在於 TEXT_CODES', implode("\n", $errors));
        $this->assertSame(0, DB::table('TEXT_CODES')->count());
        $this->assertSame(0, DB::table('operations')->count());
    }

    #[Test]
    public function test_non_numeric_source_text_id_is_rejected(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 201, 'c_dy' => '5']);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "201\t測試書\tabc",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $errors = $response->getSession()->get('batch_errors', []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('必須為整數', implode("\n", $errors));
        $this->assertSame(0, DB::table('TEXT_CODES')->count());
    }

    #[Test]
    public function test_title_with_allowed_punctuation_passes(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 204, 'c_dy' => '7']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 703, 'c_title_chn' => '來源']);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "204\t四書講義(屠錫光)\t703",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertEmpty($response->getSession()->get('batch_errors', []));
        $this->assertSame(2, DB::table('TEXT_CODES')->count());
    }

    #[Test]
    public function test_failed_validation_does_not_log_or_import_any_row(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 205, 'c_dy' => '8']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 704, 'c_title_chn' => '來源']);

        // 𰻞 (U+30EDE) is unmapped in the Pinyin dict — pinyin 表與 opencc-pinyin
        // 靜態字典（zdic 不含 Ext G 區）皆查無讀音 — which fails the pinyin check.
        $entries = "205\t合法書名\t704\n205\t𰻞瑣稿\t704";

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => $entries,
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $errors = $response->getSession()->get('batch_errors', []);
        $this->assertNotEmpty($errors);
        // Only the seeded TEXT_CODES row remains: nothing from this batch was inserted
        $this->assertSame(1, DB::table('TEXT_CODES')->count());
        $this->assertSame(0, DB::table('operations')->count());
    }

    #[Test]
    public function test_unpinyinable_han_character_is_rejected(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 260, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 760, 'c_title_chn' => '來源']);

        // 𰻞 (U+30EDE) is a valid Han character but not in the Pinyin dict（pinyin 表與
        // opencc-pinyin 靜態字典皆無，zdic 不含 Ext G 區）, so without this check it
        // would survive untranslated in c_title (e.g. "𰻞 suo xian na gao").
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "260\t𰻞瑣獻納稿\t760",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $errors = $response->getSession()->get('batch_errors', []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('無拼音對應', implode("\n", $errors));
        $this->assertStringContainsString('𰻞', implode("\n", $errors));
        $this->assertSame(1, DB::table('TEXT_CODES')->count());
        $this->assertSame(0, DB::table('operations')->count());
    }

    #[Test]
    public function test_force_submit_bypasses_pinyin_check_only(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 270, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 770, 'c_title_chn' => '來源']);

        // Same title that fails the pinyin check; with force=1 the row should import.
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "270\t靑瑣獻納稿\t770",
            'force' => '1',
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertEmpty($response->getSession()->get('batch_errors', []));
        $this->assertSame(2, DB::table('TEXT_CODES')->count());
    }

    #[Test]
    public function test_force_submit_still_enforces_id_checks(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('TEXT_CODES')->insert(['c_textid' => 771, 'c_title_chn' => '來源']);

        // Author 9999999 does not exist — force flag must NOT bypass this.
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "9999999\t靑瑣獻納稿\t771",
            'force' => '1',
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $errors = $response->getSession()->get('batch_errors', []);
        $this->assertStringContainsString('不存在於 BIOG_MAIN', implode("\n", $errors));
        $this->assertSame(1, DB::table('TEXT_CODES')->count());
    }

    #[Test]
    public function test_pinyin_dict_now_covers_zhi_and_xi_additions(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 280, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 780, 'c_title_chn' => '來源']);

        // 巵→zhi, 繫→xi were added to Pinyin::$dic. They should now pass the check.
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "280\t莊巵言\t780\n280\t易繫詞講\t780",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertEmpty($response->getSession()->get('batch_errors', []));

        $rows = DB::table('TEXT_CODES')->where('c_textid', '>', 780)->orderBy('c_textid')->get();
        $this->assertSame('zhuang zhi yan', $rows[0]->c_title);
        $this->assertSame('yi xi ci jiang', $rows[1]->c_title);
    }

    #[Test]
    public function test_pinyin_dict_now_covers_tai_and_jing_additions(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 290, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 790, 'c_title_chn' => '來源']);

        // 臺→tai、淨→jing 已加入 Pinyin::$dic，含這兩個字的書名應能通過拼音檢查並轉出。
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "290\t臺灣府志\t790\n290\t淨土錄\t790",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertEmpty($response->getSession()->get('batch_errors', []));

        $rows = DB::table('TEXT_CODES')->where('c_textid', '>', 790)->orderBy('c_textid')->get();
        $this->assertSame('tai wan fu zhi', $rows[0]->c_title);
        $this->assertSame('jing tu lu', $rows[1]->c_title);
    }

    #[Test]
    public function test_variant_glyph_feng_is_standardized_in_stored_title(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 291, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 791, 'c_title_chn' => '來源']);

        // 峯（U+5CEF）一律標準化為標準字形峰（U+5CF0）。標準化發生在 parseEntries，
        // 因此「存入的中文書名」本身就被改寫，拼音也據此轉為 feng，不會被無拼音檢查擋下。
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "291\t東坡集峯卷一\t791",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertSame([], $response->getSession()->get('batch_errors', []));

        $record = DB::table('TEXT_CODES')->where('c_textid', '>', 791)->orderByDesc('c_textid')->first();
        $this->assertNotNull($record);
        // 存入的書名本身已標準化：峯 → 峰
        $this->assertSame('東坡集峰卷一', $record->c_title_chn);
        $this->assertStringNotContainsString('峯', $record->c_title_chn);
        $this->assertSame('dong po ji feng juan yi', $record->c_title);
    }

    #[Test]
    public function test_variant_glyph_qing_is_standardized_in_stored_title(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 293, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 793, 'c_title_chn' => '來源']);

        // 靑（U+9751）一律標準化為標準字形青（U+9752）。標準化發生在 parseEntries，
        // 因此「存入的中文書名」本身就被改寫，拼音也據此轉為 qing，不會被無拼音檢查擋下。
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "293\t靑瑣稿\t793",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertEmpty($response->getSession()->get('batch_errors', []));

        $record = DB::table('TEXT_CODES')->where('c_textid', '>', 793)->orderByDesc('c_textid')->first();
        $this->assertNotNull($record);
        // 存入的書名本身已標準化：靑 → 青
        $this->assertSame('青瑣稿', $record->c_title_chn);
        $this->assertStringNotContainsString('靑', $record->c_title_chn);
        $this->assertSame('qing suo gao', $record->c_title);
    }

    #[Test]
    public function test_variant_glyph_ying_is_standardized_in_stored_title(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 294, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 794, 'c_title_chn' => '來源']);

        // 頴（U+9834）一律標準化為標準字形穎（U+7A4E）。標準化發生在 parseEntries，
        // 因此「存入的中文書名」本身就被改寫，拼音也據此轉為 ying，不會被無拼音檢查擋下。
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "294\t頴集\t794",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertEmpty($response->getSession()->get('batch_errors', []));

        $record = DB::table('TEXT_CODES')->where('c_textid', '>', 794)->orderByDesc('c_textid')->first();
        $this->assertNotNull($record);
        // 存入的書名本身已標準化：頴 → 穎
        $this->assertSame('穎集', $record->c_title_chn);
        $this->assertStringNotContainsString('頴', $record->c_title_chn);
        $this->assertSame('ying ji', $record->c_title);
    }

    #[Test]
    public function test_newly_added_variant_glyph_qing_ti_is_standardized_in_stored_title(): void {
        // 淸（U+6DF8）與厰（U+53B0）是 char_variant_map 新增收錄的 2 筆對照，原本不在
        // 舊版 TITLE_VARIANT_MAP（僅峯／靑／頴 3 筆）裡；改接 CharVariantMapService::replaceLenient()
        // 後，寬鬆模式套用全表 7 筆，這兩筆現在也會改寫書名本身——這是本步驟的行為擴張驗證。
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 295, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 795, 'c_title_chn' => '來源']);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "295\t淸厰集\t795",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertEmpty($response->getSession()->get('batch_errors', []));

        $record = DB::table('TEXT_CODES')->where('c_textid', '>', 795)->orderByDesc('c_textid')->first();
        $this->assertNotNull($record);
        $this->assertSame('清廠集', $record->c_title_chn);
        $this->assertStringNotContainsString('淸', $record->c_title_chn);
        $this->assertStringNotContainsString('厰', $record->c_title_chn);
    }

    #[Test]
    public function test_formerly_pinyin_only_variant_shen_now_also_rewrites_stored_title(): void {
        // 愼（U+613C）原本只在 VariantCharNormalizer::$fallbackMap 裡、只影響拼音查詢，
        // 不改動書名本身。char_variant_map 收錄後，寬鬆模式下這筆對照現在也會改寫書名——
        // 這是「不分原本是哪個舊機制的資料，表裡任何一筆都套用」的行為擴張驗證。
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 296, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 796, 'c_title_chn' => '來源']);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "296\t愼獄集\t796",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertEmpty($response->getSession()->get('batch_errors', []));

        $record = DB::table('TEXT_CODES')->where('c_textid', '>', 796)->orderByDesc('c_textid')->first();
        $this->assertNotNull($record);
        $this->assertSame('慎獄集', $record->c_title_chn);
        $this->assertStringNotContainsString('愼', $record->c_title_chn);
    }

    #[Test]
    public function test_batch_results_include_variant_replacements_per_row(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 297, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 797, 'c_title_chn' => '來源']);

        $entries = "297\t東坡集峯卷一\t797\n297\t普通書名\t797";

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => $entries,
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertEmpty($response->getSession()->get('batch_errors', []));

        $results = $response->getSession()->get('batch_results', []);
        $this->assertCount(2, $results);

        $withVariant = collect($results)->firstWhere('line', 1);
        $this->assertSame([['from' => '峯', 'to' => '峰']], $withVariant['variant_replacements']);

        $withoutVariant = collect($results)->firstWhere('line', 2);
        $this->assertSame([], $withoutVariant['variant_replacements']);
    }

    #[Test]
    public function test_simplified_jing_is_still_rejected(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 292, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 792, 'c_title_chn' => '來源']);

        // 簡體混入偵測改由 SimplifiedOnlyChars 顯式承擔（opencc-pinyin 靜態字典
        // 補全後，净 也能轉出拼音，「無拼音對應」不再兼任簡體防火牆）。
        // 含净的書名預設仍被攔下，訊息明確指出是簡體字形。
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "292\t净土錄\t792",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $errors = $response->getSession()->get('batch_errors', []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('簡體字形', implode("\n", $errors));
        $this->assertStringContainsString('净', implode("\n", $errors));
        // 只有預先插入的來源列，未新增任何資料。
        $this->assertSame(1, DB::table('TEXT_CODES')->count());
        $this->assertSame(0, DB::table('operations')->count());
    }

    #[Test]
    public function test_simplified_char_can_be_force_imported_as_vulgar_variant(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 293, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 793, 'c_title_chn' => '來源']);

        // 簡體字形在古籍中可能是俗字（文獻原貌），故簡體嫌疑是「警告＋強制放行」
        // 而非硬性拒絕：force=1 應可匯入，且書名保留原字形、拼音照常轉出。
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "293\t净土錄\t793",
            'force' => '1',
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertEmpty($response->getSession()->get('batch_errors', []));
        $record = DB::table('TEXT_CODES')->where('c_textid', '>', 793)->orderByDesc('c_textid')->first();
        $this->assertNotNull($record);
        $this->assertSame('净土錄', $record->c_title_chn);
        $this->assertSame('jing tu lu', $record->c_title);
    }

    #[Test]
    public function test_unpinyinable_han_after_volume_separator_is_ignored(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 261, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 761, 'c_title_chn' => '來源']);

        // Anything after a colon is dropped before pinyin conversion (see stripVolumeInfo),
        // so unpinyinable chars in the volume annotation should not block the import.
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "261\t測試稿: 卷靑\t761",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $this->assertEmpty($response->getSession()->get('batch_errors', []));
    }

    #[Test]
    public function test_undo_deletes_text_codes_and_operations_for_batch(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 400, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 900, 'c_title_chn' => '來源']);

        $store = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "400\t第一本書\t900\n400\t第二本書\t900",
        ]);
        $batchId = $store->getSession()->get('batch_id');
        $this->assertNotNull($batchId);
        $this->assertSame(3, DB::table('TEXT_CODES')->count());
        $this->assertSame(2, DB::table('operations')->where('resource', 'TEXT_CODES')->count());

        $undo = $this->post(route('admin.batch-load-book-titles.undo'), [
            'batch_id' => $batchId,
        ]);
        $undo->assertRedirect(route('admin.batch-load-book-titles'));
        $toast = $undo->getSession()->get('toast', []);
        $this->assertStringContainsString('共刪除 2 筆', $toast['msg'] ?? '');
        $this->assertSame('success', $toast['type'] ?? '');

        // Only the originally seeded source row remains.
        $this->assertSame(1, DB::table('TEXT_CODES')->count());
        $this->assertSame(0, DB::table('operations')->where('resource', 'TEXT_CODES')->count());
    }

    #[Test]
    public function test_undo_only_affects_matching_batch(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 401, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 901, 'c_title_chn' => '來源']);

        $first = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "401\t批次甲\t901",
        ]);
        $firstBatch = $first->getSession()->get('batch_id');

        // Each batch gets a random suffix, so two imports inside the same second
        // still receive distinct ids. No sleep needed.
        $second = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "401\t批次乙\t901",
        ]);
        $secondBatch = $second->getSession()->get('batch_id');
        $this->assertNotSame($firstBatch, $secondBatch);

        $this->post(route('admin.batch-load-book-titles.undo'), [
            'batch_id' => $firstBatch,
        ]);

        // Second batch should survive.
        $this->assertNotNull(DB::table('TEXT_CODES')->where('c_notes', '['.$secondBatch.']')->first());
        $this->assertNull(DB::table('TEXT_CODES')->where('c_notes', '['.$firstBatch.']')->first());
    }

    #[Test]
    public function test_undo_with_unknown_batch_id_is_safe(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('TEXT_CODES')->insert(['c_textid' => 902, 'c_title_chn' => '來源']);

        $undo = $this->post(route('admin.batch-load-book-titles.undo'), [
            'batch_id' => '20990101000000-DEADBE',
        ]);
        $undo->assertRedirect(route('admin.batch-load-book-titles'));
        $toast = $undo->getSession()->get('toast', []);
        $this->assertStringContainsString('找不到對應批次', $toast['msg'] ?? '');
        $this->assertSame('warning', $toast['type'] ?? '');
        $this->assertSame(1, DB::table('TEXT_CODES')->count());
    }

    #[Test]
    public function test_undo_rejects_malformed_batch_id(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $undo = $this->post(route('admin.batch-load-book-titles.undo'), [
            'batch_id' => 'not-a-batch',
        ]);
        // Laravel validation failure → redirect back with errors.
        $undo->assertStatus(302);
        $this->assertNotEmpty($undo->getSession()->get('errors'));
    }

    #[Test]
    public function test_non_admin_cannot_undo(): void {
        $user = $this->makeUser(['is_admin' => 0]);
        $this->actingAs($user);

        $undo = $this->post(route('admin.batch-load-book-titles.undo'), [
            'batch_id' => '20260101000000-ABCDEF',
        ]);
        $undo->assertStatus(403);
    }

    #[Test]
    public function test_admin_can_update_pinyin_and_response_returns_stored_value(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 500, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 1000, 'c_title_chn' => '來源']);

        $store = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "500\t測試稿\t1000",
        ]);
        $batchId = $store->getSession()->get('batch_id');
        $created = DB::table('TEXT_CODES')->where('c_notes', '['.$batchId.']')->first();
        $this->assertSame('ce shi gao', $created->c_title);

        $response = $this->post(route('admin.batch-load-book-titles.update-pinyin'), [
            'c_textid' => $created->c_textid,
            'batch_id' => $batchId,
            'pinyin' => '  Ce  Shi  GAO  ',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'c_textid' => (int) $created->c_textid,
            'c_title' => 'ce shi gao',
        ]);

        $fresh = DB::table('TEXT_CODES')->where('c_textid', $created->c_textid)->first();
        $this->assertSame('ce shi gao', $fresh->c_title);
        $this->assertSame('Batch Admin', $fresh->c_modified_by);
        $this->assertNotNull($fresh->c_modified_date);

        $update = DB::table('operations')
            ->where('resource', 'TEXT_CODES')
            ->where('resource_id', (string) $created->c_textid)
            ->where('op_type', 3)
            ->first();
        $this->assertNotNull($update);
        $payload = json_decode($update->resource_data, true);
        $this->assertSame('ce shi gao', $payload['c_title']);

        // resource_data must NOT carry columns this endpoint never mutates.
        // OperationRepository::getArrDiff() walks resource_data keys and would
        // otherwise show a false "c_title_chn changed null → 來源" / "c_textid
        // changed null → N" line in the comparison modal on every pinyin edit.
        $this->assertArrayNotHasKey('c_title_chn', $payload);
        $this->assertArrayNotHasKey('c_textid', $payload);

        // resource_original must include every column this endpoint writes,
        // otherwise restoreUpdate would leave the post-edit modifier/timestamp
        // on a row whose pinyin was reverted.
        $original = json_decode($update->resource_original, true);
        $this->assertSame('ce shi gao', $original['c_title']);
        $this->assertArrayHasKey('c_modified_by', $original);
        $this->assertArrayHasKey('c_modified_date', $original);
        $this->assertNull($original['c_modified_by']);
        $this->assertNull($original['c_modified_date']);
    }

    #[Test]
    public function test_update_pinyin_rejects_row_outside_supplied_batch(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 501, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 1100, 'c_title_chn' => '來源']);

        $store = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "501\t測試稿\t1100",
        ]);
        $batchId = $store->getSession()->get('batch_id');
        $created = DB::table('TEXT_CODES')->where('c_notes', '['.$batchId.']')->first();

        // A different (well-formed) batch id whose marker does not match the row.
        $response = $this->post(route('admin.batch-load-book-titles.update-pinyin'), [
            'c_textid' => $created->c_textid,
            'batch_id' => '20990101000000-AAAAAA',
            'pinyin' => 'something else',
        ]);

        $response->assertStatus(422);
        $fresh = DB::table('TEXT_CODES')->where('c_textid', $created->c_textid)->first();
        $this->assertSame('ce shi gao', $fresh->c_title);
        $this->assertSame(0, DB::table('operations')->where('op_type', 3)->count());
    }

    #[Test]
    public function test_update_pinyin_rejects_unknown_text_id(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->post(route('admin.batch-load-book-titles.update-pinyin'), [
            'c_textid' => 99999999,
            'batch_id' => '20260101000000-ABCDEF',
            'pinyin' => 'foo bar',
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function test_update_pinyin_rejects_empty_input(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 502, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 1200, 'c_title_chn' => '來源']);
        $store = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "502\t測試稿\t1200",
        ]);
        $batchId = $store->getSession()->get('batch_id');
        $created = DB::table('TEXT_CODES')->where('c_notes', '['.$batchId.']')->first();

        // Both empty and whitespace-only fail the `required` rule (Laravel trims
        // strings before the required check), so the request is rejected at
        // validation time with a redirect+session errors.
        foreach (['', '   '] as $value) {
            $response = $this->post(route('admin.batch-load-book-titles.update-pinyin'), [
                'c_textid' => $created->c_textid,
                'batch_id' => $batchId,
                'pinyin' => $value,
            ]);
            $response->assertStatus(302);
            $this->assertNotEmpty($response->getSession()->get('errors'));
        }

        $fresh = DB::table('TEXT_CODES')->where('c_textid', $created->c_textid)->first();
        $this->assertSame('ce shi gao', $fresh->c_title);
    }

    #[Test]
    public function test_update_pinyin_rejects_malformed_batch_id(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->post(route('admin.batch-load-book-titles.update-pinyin'), [
            'c_textid' => 1,
            'batch_id' => 'not-a-batch',
            'pinyin' => 'foo',
        ]);
        $response->assertStatus(302);
        $this->assertNotEmpty($response->getSession()->get('errors'));
    }

    #[Test]
    public function test_non_admin_cannot_update_pinyin(): void {
        $user = $this->makeUser(['is_admin' => 0]);
        $this->actingAs($user);

        $response = $this->post(route('admin.batch-load-book-titles.update-pinyin'), [
            'c_textid' => 1,
            'batch_id' => '20260101000000-ABCDEF',
            'pinyin' => 'foo',
        ]);
        $response->assertStatus(403);
    }

    #[Test]
    public function test_update_pinyin_log_can_be_restored_via_operations_endpoint(): void {
        // The pinyin update writes an op_type=3 operation against TEXT_CODES.
        // The /operations restore action requires TEXT_CODES to be in
        // OperationsController::resourceKeyColumns(); without that mapping, the
        // restore button on the operations index throws "缺少主鍵條件". This test
        // exercises the full round-trip so the integration cannot regress silently.
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 600, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 1400, 'c_title_chn' => '來源']);

        $store = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "600\t測試稿\t1400",
        ]);
        $batchId = $store->getSession()->get('batch_id');
        $created = DB::table('TEXT_CODES')->where('c_notes', '['.$batchId.']')->first();
        $this->assertSame('ce shi gao', $created->c_title);

        // Apply a manual edit, then locate the resulting update-operation row.
        $this->post(route('admin.batch-load-book-titles.update-pinyin'), [
            'c_textid' => $created->c_textid,
            'batch_id' => $batchId,
            'pinyin' => 'manually edited',
        ]);
        $this->assertSame('manually edited', DB::table('TEXT_CODES')->where('c_textid', $created->c_textid)->value('c_title'));

        $updateOp = DB::table('operations')
            ->where('resource', 'TEXT_CODES')
            ->where('resource_id', (string) $created->c_textid)
            ->where('op_type', 3)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($updateOp);

        $restore = $this->post(route('operations.restore', ['operation' => $updateOp->id]));
        $restore->assertRedirect(route('operations.index'));

        $reverted = DB::table('TEXT_CODES')->where('c_textid', $created->c_textid)->first();
        $this->assertSame('ce shi gao', $reverted->c_title);
        // 2026-08-05 語義定案：restore 也是一次實際寫入，c_modified_* 蓋還原人＋還原時刻，
        // 不回填快照舊值（此列匯入時為 NULL，但還原本身就是最後一次修改）。
        $this->assertSame('Batch Admin', $reverted->c_modified_by);
        $this->assertNotNull($reverted->c_modified_date);

        // The restore action also writes its own audit row (op_type=3) via
        // recordRestoreOperation. Production schema sets operations.c_personid
        // NOT NULL with a FK to BIOG_MAIN: this insert must therefore use the
        // 0-sentinel for non-person resources, not null. If that coercion
        // silently failed under exception, this row would be missing.
        $followUpAuditCount = DB::table('operations')
            ->where('resource', 'TEXT_CODES')
            ->where('resource_id', (string) $created->c_textid)
            ->where('op_type', 3)
            ->count();
        $this->assertSame(2, $followUpAuditCount);

        $restoreAudit = DB::table('operations')
            ->where('resource', 'TEXT_CODES')
            ->where('op_type', 3)
            ->orderByDesc('id')
            ->first();
        $this->assertSame(0, (int) $restoreAudit->c_personid);
    }

    #[Test]
    public function test_results_page_renders_editable_pinyin_cell(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 503, 'c_dy' => '6']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 1300, 'c_title_chn' => '來源']);
        $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "503\t測試稿\t1300",
        ]);

        $followUp = $this->get(route('admin.batch-load-book-titles'));
        $followUp->assertSee('class="pinyin-cell"', false);
        $followUp->assertSee('pinyin-edit-btn', false);
        $followUp->assertSee('pinyin-save-btn', false);
    }

    #[Test]
    public function test_check_rare_chars_flags_table_missing_char_even_when_opencc_covers_it(): void {
        // 罕見字檢測「只查 pinyin 表」：麤（U+9EA4）不在權威表，但 opencc-pinyin 靜態
        // 字典查得到讀音（cu）。匯入檢查（getPinyin）會放行，但本檢測仍要把它列為罕見字。
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->postJson(route('app.admin.batch-load-book-titles.check-rare-chars'), [
            'entries' => "1\t安石集\t2\n3\t麤俗編\t4",
        ]);

        $response->assertOk();
        $json = $response->json();
        $this->assertTrue($json['ok']);
        $this->assertSame(2, $json['checked']);
        $this->assertSame([], $json['parse_errors']);
        // 只有含麤的第 2 行被列出，安石集整行皆在表內不列。
        $this->assertCount(1, $json['missing']);
        // 麤 位在輸入的第 2 行（第一欄的 3 是作者 ID，不是行號）。
        $this->assertSame(2, $json['missing'][0]['line']);
        $this->assertSame('麤俗編', $json['missing'][0]['title']);
        $this->assertSame('麤', $json['missing'][0]['chars'][0]['char']);
        $this->assertSame('U+9EA4', $json['missing'][0]['chars'][0]['codepoint']);
        $this->assertSame(1, $json['unique_char_count']);

        // 佐證差異點：麤在匯入路徑（含 opencc 退回）查得到拼音，不會被 store() 擋。
        $this->assertSame('cu', PinyinDictionary::getPinyin('麤'));
        $this->assertFalse(PinyinDictionary::isInTable('麤'));
    }

    #[Test]
    public function test_check_rare_chars_returns_empty_missing_for_common_title(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->postJson(route('app.admin.batch-load-book-titles.check-rare-chars'), [
            'entries' => "1\t安石集\t2",
        ]);

        $response->assertOk();
        $json = $response->json();
        $this->assertSame(1, $json['checked']);
        $this->assertSame([], $json['missing']);
        $this->assertSame(0, $json['unique_char_count']);
    }

    #[Test]
    public function test_check_rare_chars_does_not_apply_variant_normalization(): void {
        // 罕見字檢測刻意「不套 VariantCharNormalizer 異體字歸一化」：菴（U+83F4）不在
        // pinyin 表，但 VariantCharNormalizer 會把它歸一為在表內的 庵。匯入路徑
        // （collectUnpinyinableHan）會先歸一化故放行；本檢測不歸一化，仍須把 菴 列為罕見字。
        // 這個 test 鎖住該設計差異——若有人把 normalize() 加進檢測，菴 會漏報而此測試失敗。
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->postJson(route('app.admin.batch-load-book-titles.check-rare-chars'), [
            'entries' => "1\t菴集\t2",
        ]);

        $response->assertOk();
        $json = $response->json();
        $this->assertCount(1, $json['missing']);
        $this->assertSame('菴', $json['missing'][0]['chars'][0]['char']);
        $this->assertSame('U+83F4', $json['missing'][0]['chars'][0]['codepoint']);

        // 佐證：菴 經 VariantCharNormalizer 歸一為在表內的 庵，故匯入路徑不會被擋。
        $this->assertSame('庵', \App\Services\VariantCharNormalizer::normalize('菴'));
        $this->assertTrue(PinyinDictionary::isInTable('庵'));
        $this->assertFalse(PinyinDictionary::isInTable('菴'));
    }

    #[Test]
    public function test_check_rare_chars_checks_stored_glyph_after_title_variant_standardization(): void {
        // 設計決策（已與需求方確認）：罕見字檢測看的是「實際入庫的字形」，而非原始貼上字形。
        // 峯（U+5CEF）不在 pinyin 表，但 TITLE_VARIANT_MAP 會在 parseEntries 就把書名本身
        // 改寫成標準字形 峰（入庫的 c_title_chn 即為 峰），而 峰 在表內，故不列為罕見字。
        // 此測試鎖住此決策：若有人改成檢測原始字形，峯 會被列出而此測試失敗。
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->postJson(route('app.admin.batch-load-book-titles.check-rare-chars'), [
            'entries' => "1\t峯集\t2",
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('missing'));
    }

    #[Test]
    public function test_check_rare_chars_dedups_repeated_chars_but_counts_lines(): void {
        // 同一罕見字重複出現：每行內去重（chars 不重複），unique_char_count 全域去重，
        // 但每一含該字的行都各列一筆 missing。
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->postJson(route('app.admin.batch-load-book-titles.check-rare-chars'), [
            'entries' => "1\t麤麤編\t2\n3\t麤俗集\t4",
        ]);

        $response->assertOk();
        $json = $response->json();
        // 兩行都含麤 → 兩筆 missing；但麤只算一個相異字。
        $this->assertCount(2, $json['missing']);
        $this->assertCount(1, $json['missing'][0]['chars']);
        $this->assertSame('麤', $json['missing'][0]['chars'][0]['char']);
        $this->assertSame(1, $json['unique_char_count']);
    }

    #[Test]
    public function test_check_rare_chars_ignores_non_han_characters(): void {
        // 非漢字（拉丁字母、數字、標點）不納入檢測。
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->postJson(route('app.admin.batch-load-book-titles.check-rare-chars'), [
            'entries' => "1\tABC 123 安石集\t2",
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('missing'));
    }

    #[Test]
    public function test_check_rare_chars_ignores_chars_after_volume_separator(): void {
        // 與 buildPinyin/匯入檢查一致：冒號後的卷冊註記不進拼音，也不納入罕見字檢測。
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->postJson(route('app.admin.batch-load-book-titles.check-rare-chars'), [
            'entries' => "1\t安石集: 麤卷\t2",
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('missing'));
    }

    #[Test]
    public function test_check_rare_chars_reports_parse_errors_for_malformed_lines(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->postJson(route('app.admin.batch-load-book-titles.check-rare-chars'), [
            'entries' => "只有一欄\n1\t麤俗編\t2",
        ]);

        $response->assertOk();
        $json = $response->json();
        // 格式不符的行進 parse_errors；合法行仍照常檢測。
        $this->assertNotEmpty($json['parse_errors']);
        $this->assertStringContainsString('未找到三欄資料', implode("\n", $json['parse_errors']));
        $this->assertSame(1, $json['checked']);
        $this->assertCount(1, $json['missing']);
    }

    #[Test]
    public function test_non_admin_cannot_check_rare_chars(): void {
        $user = $this->makeUser(['is_admin' => 0]);
        $this->actingAs($user);

        $response = $this->postJson(route('app.admin.batch-load-book-titles.check-rare-chars'), [
            'entries' => "1\t麤俗編\t2",
        ]);
        $response->assertStatus(403);
    }

    #[Test]
    public function test_results_page_renders_copy_button_with_payload(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 300, 'c_dy' => '9']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 800, 'c_title_chn' => '來源']);

        $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "300\t某某書\t800",
        ]);

        $followUp = $this->get(route('admin.batch-load-book-titles'));
        $followUp->assertSee('複製 textid 與書名');
        $followUp->assertSee("801\t某某書", false);
        $followUp->assertSee('id="copy-textid-title-source"', false);
    }
}
