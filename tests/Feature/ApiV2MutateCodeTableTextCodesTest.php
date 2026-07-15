<?php

namespace Tests\Feature;

use App\Models\User;
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
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'tc@example.com'): User {
        return User::create([
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
    public function testDeleteRemovesRow(): void {
        $this->actingAs($this->makeUser(email: 'tc-del@example.com'));
        DB::table('TEXT_CODES')->insert(['c_textid' => 71853, 'c_title_chn' => '待刪']);

        $this->postJson('/api/v2/delete', [
            'resource' => 'text-codes',
            'person_id' => 0,
            'target' => ['pk' => ['c_textid' => 71853]],
        ])->assertOk()->assertJson(['ok' => true, 'operation' => 'delete']);

        $this->assertSame(0, DB::table('TEXT_CODES')->count());
        $this->assertSame(1, DB::table('audit_log')->where('operation', 'DELETE')->count());
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
}
