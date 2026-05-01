<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminBatchLoadBookTitlesTest extends TestCase {
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
    }

    protected function tearDown(): void {
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
        $this->assertSame('54321', $encoded['c_source']);
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

        // 靑 (U+9751) is unmapped in the Pinyin dict, which fails the pinyin check.
        $entries = "205\t合法書名\t704\n205\t靑瑣稿\t704";

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

        // 靑 (U+9751) is a valid Han character but not in the Pinyin dict, so without
        // this check it would survive untranslated in c_title (e.g. "靑 suo xian na gao").
        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "260\t靑瑣獻納稿\t760",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $errors = $response->getSession()->get('batch_errors', []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('無拼音對應', implode("\n", $errors));
        $this->assertStringContainsString('靑', implode("\n", $errors));
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
        // c_modified_by/c_modified_date were NULL on the freshly-imported row;
        // restore must put them back to NULL, not leave the edit's modifier on
        // a row whose pinyin was reverted.
        $this->assertNull($reverted->c_modified_by);
        $this->assertNull($reverted->c_modified_date);

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
    public function test_results_page_renders_copy_button_with_payload(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 300, 'c_dy' => '9']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 800, 'c_title_chn' => '來源']);

        $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "300\t某某書\t800",
        ]);

        $followUp = $this->get(route('admin.batch-load-book-titles'));
        $followUp->assertSee('Copy textid and title');
        $followUp->assertSee("801\t某某書", false);
        $followUp->assertSee('id="copy-textid-title-source"', false);
    }
}
