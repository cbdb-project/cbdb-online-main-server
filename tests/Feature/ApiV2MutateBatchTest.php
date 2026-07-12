<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MutationController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * batch_mutate（POST /api/v2/batch_mutate）回歸測試。
 *
 * 以 sources（BIOG_SOURCE_DATA）為載具，驗證批次端點：
 * - 沿用既有 handler（校驗/改鍵/授權/operations 一致），逐筆結果 + 彙總。
 * - atomic=false：逐筆獨立結算，單筆失敗不影響其餘。
 * - atomic=true：任一筆失敗整批回滾。
 * - 頂層預設（resource/mode）逐項可覆寫；超過上限 422；缺 items 422。
 */
class ApiV2MutateBatchTest extends TestCase {
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
        $this->createSanctumTables();
        $this->createOperationsTable();
        $this->createAuditLogTable();
        $this->createTextCodesTable();
        $this->createSourceTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_SOURCE_DATA');
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
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

    protected function createSanctumTables(): void {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
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

    protected function createTextCodesTable(): void {
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title')->nullable();
            $table->string('c_title_chn')->nullable();
        });
        DB::table('TEXT_CODES')->insert([
            ['c_textid' => 0, 'c_title' => 'n/a', 'c_title_chn' => '未詳'],
            ['c_textid' => 500, 'c_title' => 'Book A', 'c_title_chn' => '甲書'],
            ['c_textid' => 700, 'c_title' => 'Book B', 'c_title_chn' => '乙書'],
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

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'batch@example.com'): User {
        return User::create([
            'name' => 'batch-tester',
            'email' => $email,
            'confirmation_token' => 'token-b',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    /** 一筆 create item（sources）。 */
    protected function createItem(int $personid, int $textid, string $pages, array $changes = []): array {
        return [
            'resource' => 'sources',
            'operation' => 'create',
            'person_id' => $personid,
            'target' => ['pk' => ['c_personid' => $personid, 'c_textid' => $textid, 'c_pages' => $pages]],
            'changes' => array_merge(['c_textid' => $textid, 'c_pages' => $pages, 'c_notes' => 'b'], $changes),
        ];
    }

    #[Test]
    public function testMissingItemsReturns422(): void {
        $this->actingAs($this->makeUser(email: 'b-empty@example.com'));

        $this->postJson('/api/v2/batch_mutate', ['items' => []])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['items' => ['required']]]);
    }

    #[Test]
    public function testOverLimitReturns422(): void {
        $this->actingAs($this->makeUser(email: 'b-over@example.com'));

        $items = [];
        for ($i = 0; $i <= MutationController::BATCH_MAX_ITEMS; $i++) {
            $items[] = $this->createItem(1000, 500, (string) $i);
        }

        $this->postJson('/api/v2/batch_mutate', ['items' => $items])
            ->assertStatus(422)
            ->assertJsonPath('errors.items.0', 'too_many');
    }

    #[Test]
    public function testNonAtomicPartialSuccessKeepsGoing(): void {
        // item0 create OK；item1 = 與 item0 相同主鍵 → 409 已存在；item2 create OK。
        // 非原子：全部照跑，成功者落庫，失敗者不影響其餘。
        $this->actingAs($this->makeUser(email: 'b-partial@example.com'));

        $res = $this->postJson('/api/v2/batch_mutate', [
            'items' => [
                $this->createItem(1000, 500, '10'),
                $this->createItem(1000, 500, '10'), // duplicate → 409
                $this->createItem(1000, 500, '20'),
            ],
        ]);

        $res->assertOk()
            ->assertJson(['ok' => false, 'atomic' => false, 'summary' => ['total' => 3, 'ok' => 2, 'failed' => 1]]);
        $res->assertJsonPath('results.0.ok', true);
        $res->assertJsonPath('results.1.ok', false);
        $res->assertJsonPath('results.1.http_status', 409);
        $res->assertJsonPath('results.2.ok', true);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '10']);
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '20']);
        $this->assertSame(2, DB::table('BIOG_SOURCE_DATA')->count());
    }

    #[Test]
    public function testAtomicAllSuccessCommits(): void {
        $this->actingAs($this->makeUser(email: 'b-atomic-ok@example.com'));

        $this->postJson('/api/v2/batch_mutate', [
            'atomic' => true,
            'items' => [
                $this->createItem(1000, 500, '1'),
                $this->createItem(1000, 500, '2'),
                $this->createItem(1000, 700, '3'),
            ],
        ])->assertOk()->assertJson(['ok' => true, 'atomic' => true, 'summary' => ['total' => 3, 'ok' => 3, 'failed' => 0]]);

        $this->assertSame(3, DB::table('BIOG_SOURCE_DATA')->count());
    }

    #[Test]
    public function testAtomicFailureRollsBackWholeBatch(): void {
        // item0/1 create OK，item2 = 與 item1 撞主鍵 → 409；原子模式整批回滾，DB 一列都不留。
        $this->actingAs($this->makeUser(email: 'b-atomic-fail@example.com'));

        $res = $this->postJson('/api/v2/batch_mutate', [
            'atomic' => true,
            'items' => [
                $this->createItem(1000, 500, '1'),
                $this->createItem(1000, 500, '2'),
                $this->createItem(1000, 500, '2'), // duplicate of item1 → 409
            ],
        ]);

        $res->assertStatus(409)
            ->assertJson(['ok' => false, 'atomic' => true, 'failed_index' => 2]);
        $res->assertJsonPath('failed.http_status', 409);

        // 整批回滾：item0/1 雖曾成功，也一併撤銷。
        $this->assertSame(0, DB::table('BIOG_SOURCE_DATA')->count());
    }

    #[Test]
    public function testTopLevelDefaultsMergeIntoItems(): void {
        // 頂層帶 resource/mode/operation，item 只給 person_id/target/changes。
        $this->actingAs($this->makeUser(email: 'b-defaults@example.com'));

        $this->postJson('/api/v2/batch_mutate', [
            'resource' => 'sources',
            'mode' => 'direct',
            'operation' => 'create',
            'items' => [
                [
                    'person_id' => 1000,
                    'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => 'd1']],
                    'changes' => ['c_textid' => 500, 'c_pages' => 'd1', 'c_notes' => 'x'],
                ],
            ],
        ])->assertOk()->assertJson(['ok' => true, 'summary' => ['ok' => 1]]);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => 'd1']);
    }

    #[Test]
    public function testDirectRejectsCrowdsourcingUserPerItem(): void {
        // 直接寫入需 canWriteDirectly()；群眾外包帳號逐筆 403（沿用 handler 授權）。
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'b-crowd@example.com'));

        $res = $this->postJson('/api/v2/batch_mutate', [
            'items' => [$this->createItem(1000, 500, '1')],
        ]);

        $res->assertOk()->assertJson(['ok' => false, 'summary' => ['failed' => 1]]);
        $res->assertJsonPath('results.0.http_status', 403);
        $this->assertSame(0, DB::table('BIOG_SOURCE_DATA')->count());
    }
}
