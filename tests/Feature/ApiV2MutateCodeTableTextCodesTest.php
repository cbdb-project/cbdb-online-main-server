<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CharVariantMapService;
use App\Support\VariantReplaceScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * code 表 mutation（resource=text-codes → TEXT_CODES）回歸測試。
 *
 * 驗證 config 驅動的 CodeTableCreate/DeleteHandler：
 * - create 顯式主鍵 / 自動分配（max+1）/ 顯式撞號 409 / 不允許欄位 422
 * - delete 按主鍵
 * - batch_mutate 逐筆
 */
class ApiV2MutateCodeTableTextCodesTest extends TestCase {
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

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
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
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title_chn')->nullable();
            $table->string('c_title')->nullable();
            $table->integer('c_text_dy')->nullable();
            $table->integer('c_source')->nullable();
            $table->longText('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'tc@example.com'): User {
        return User::forceCreate([
            'name' => 'Code Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    #[Test]
    public function testCreateWithExplicitId(): void {
        $this->actingAs($this->makeUser(email: 'tc-explicit@example.com'));

        $res = $this->postJson('/api/v2/create', [
            'resource' => 'text-codes',
            'person_id' => 0,
            'target' => ['pk' => ['c_textid' => 71853]],
            'changes' => ['c_title_chn' => 'TBDB 1.5', 'c_source' => 0, 'c_notes' => 'x'],
        ]);

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'text-codes',
            'operation' => 'create',
            'result' => ['pk' => ['c_textid' => 71853]],
        ]);
        $this->assertNotNull($res->json('result.operation_id'));
        $this->assertDatabaseHas('TEXT_CODES', ['c_textid' => 71853, 'c_title_chn' => 'TBDB 1.5']);
        $this->assertSame('Code Tester', DB::table('TEXT_CODES')->where('c_textid', 71853)->value('c_created_by'));
        $this->assertSame(1, DB::table('operations')->where('resource', 'TEXT_CODES')->count());
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'TEXT_CODES')->count());
    }

    #[Test]
    public function testCreateAutoAssignsNextId(): void {
        $this->actingAs($this->makeUser(email: 'tc-auto@example.com'));
        DB::table('TEXT_CODES')->insert(['c_textid' => 100, 'c_title_chn' => '既有']);

        $res = $this->postJson('/api/v2/create', [
            'resource' => 'text-codes',
            'person_id' => 0,
            'target' => ['pk' => []],
            'changes' => ['c_title_chn' => '自動分配', 'c_source' => 0],
        ]);

        $res->assertOk()->assertJson(['ok' => true, 'result' => ['pk' => ['c_textid' => 101]]]);
        $this->assertDatabaseHas('TEXT_CODES', ['c_textid' => 101, 'c_title_chn' => '自動分配']);
    }

    #[Test]
    public function testExplicitDuplicateReturns409(): void {
        $this->actingAs($this->makeUser(email: 'tc-dup@example.com'));
        DB::table('TEXT_CODES')->insert(['c_textid' => 71853, 'c_title_chn' => '已存在']);

        $this->postJson('/api/v2/create', [
            'resource' => 'text-codes',
            'person_id' => 0,
            'target' => ['pk' => ['c_textid' => 71853]],
            'changes' => ['c_title_chn' => 'x'],
        ])->assertStatus(409);
    }

    #[Test]
    public function testDisallowedFieldReturns422(): void {
        $this->actingAs($this->makeUser(email: 'tc-bad@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'text-codes',
            'person_id' => 0,
            'target' => ['pk' => ['c_textid' => 71853]],
            'changes' => ['c_title_chn' => 'x', 'c_not_a_column' => 'y'],
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('TEXT_CODES')->count());
    }

    #[Test]
    public function testDeleteIsDisabled(): void {
        // 安全：碼表刪除已停用（防級聯刪除人物資料）——回 403、不刪列、不寫 DELETE 審計。
        $this->actingAs($this->makeUser(email: 'tc-del@example.com'));
        DB::table('TEXT_CODES')->insert(['c_textid' => 71853, 'c_title_chn' => '待刪']);

        $this->postJson('/api/v2/delete', [
            'resource' => 'text-codes',
            'person_id' => 0,
            'target' => ['pk' => ['c_textid' => 71853]],
        ])->assertStatus(403);

        $this->assertSame(1, DB::table('TEXT_CODES')->count());
        $this->assertSame(0, DB::table('audit_log')->where('operation', 'DELETE')->count());
    }

    #[Test]
    public function testBatchCreateViaBatchMutate(): void {
        $this->actingAs($this->makeUser(email: 'tc-batch@example.com'));

        $this->postJson('/api/v2/batch_mutate', [
            'resource' => 'text-codes',
            'operation' => 'create',
            'items' => [
                ['person_id' => 0, 'target' => ['pk' => ['c_textid' => 800]], 'changes' => ['c_title_chn' => '甲']],
                ['person_id' => 0, 'target' => ['pk' => ['c_textid' => 801]], 'changes' => ['c_title_chn' => '乙']],
            ],
        ])->assertOk()->assertJson(['ok' => true, 'summary' => ['total' => 2, 'ok' => 2, 'failed' => 0]]);

        $this->assertSame(2, DB::table('TEXT_CODES')->count());
    }
    // ── 異體字落地替換（plan S5：token API 代碼表 create／update）──────

    /**
     * 與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php 同源的
     * 最小種子（只要「淸→清」）。其餘測試不建這張表，走 CharVariantMapService 的
     * 「表不存在就降級」路徑、行為不變。
     */
    protected function seedCharVariantMap(): void {
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
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ]);
        CharVariantMapService::reset();
        VariantReplaceScope::reset();
    }

    /**
     * token API 的 create 也要落地替換，並在回應帶 notices。
     *
     * 這修掉 G4 的不一致：同一個「淸嘉錄」走 Codes UI／書名批次匯入會被歸一成「清嘉錄」，
     * 走 token API 卻原樣入庫 ⇒ 同一個輸入落庫兩種字形。
     */
    #[Test]
    public function testCreateReplacesVariantInChineseTitleAndReturnsNotices(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser(email: 'tc-variant-create@example.com'));

        $res = $this->postJson('/api/v2/create', [
            'resource' => 'text-codes',
            'person_id' => 0,
            'target' => ['pk' => ['c_textid' => 71860]],
            'changes' => ['c_title_chn' => '淸嘉錄', 'c_source' => 0, 'c_notes' => '淸人所撰'],
        ])->assertOk();

        $this->assertDatabaseHas('TEXT_CODES', [
            'c_textid' => 71860,
            'c_title_chn' => '清嘉錄',
            'c_notes' => '清人所撰',
        ]);
        $this->assertNotEmpty($res->json('notices'), '回應必須帶異體字通知');
        $this->assertSame('清嘉錄', $res->json('result.row.c_title_chn'), '回應要回落庫值');
    }

    /**
     * 送的字被歸一成與現值相同 ⇒ 422「未偵測到任何修改內容」，且**必須帶 notices**，
     * 否則錯誤訊息看起來毫無道理。
     *
     * 這條是「替換跑在變更偵測**之前**」的主鎖：把掛鉤搬到偵測之後就會變 200 而紅。
     * update 路徑在真實中文欄上的效果由
     * ApiV2MutateCodeTableTextInstanceTest::testUpdateReplacesVariantInPublisherAndReturnsNotices
     * 覆蓋（c_publisher 是 update config 裡唯一的中文欄）。
     */
    #[Test]
    public function testUpdateNormalizingToCurrentValueReturns422WithNotices(): void {
        $this->seedCharVariantMap();
        DB::table('TEXT_CODES')->insert(['c_textid' => 71862, 'c_title' => '清']);
        $this->actingAs($this->makeUser(email: 'tc-variant-nochange@example.com'));

        $res = $this->postJson('/api/v2/mutate', [
            'resource' => 'text_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_textid' => 71862]],
            'changes' => ['c_title' => '淸'],
        ]);

        $res->assertStatus(422);
        $this->assertNotEmpty($res->json('notices'), '422 也要帶異體字通知');
        $this->assertSame('清', DB::table('TEXT_CODES')->where('c_textid', 71862)->value('c_title'));
    }
}
