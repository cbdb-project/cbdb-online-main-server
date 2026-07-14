<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MERGED_PERSON_DATA（resource=merged-person）mutation 回歸測試。
 *
 * 驗證：透過 /api/v2/create、/api/v2/delete、/api/v2/batch_mutate 補錄/刪除合併映射。
 * PK=(c_personid=survivor, c_merged_from_personid=已刪 id)；可寫欄 c_notes/c_source/c_pages。
 * 重點：c_merged_from_personid 為「刻意的已刪 id」，不對 BIOG_MAIN 做存在性校驗（本測試不建 BIOG_MAIN）。
 */
class ApiV2MutateMergedPersonTest extends TestCase {
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
        Schema::create('MERGED_PERSON_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_merged_from_personid');
            $table->longText('c_notes')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages', 255)->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary(['c_personid', 'c_merged_from_personid']);
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('MERGED_PERSON_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'mp@example.com'): User {
        return User::create([
            'name' => 'Merge Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function createPayload(int $survivor, int $deleted, array $changes = []): array {
        return [
            'resource' => 'merged-person',
            'person_id' => $survivor,
            'target' => ['pk' => ['c_personid' => $survivor, 'c_merged_from_personid' => $deleted]],
            'changes' => array_merge(['c_notes' => 'recovered', 'c_source' => 71853, 'c_pages' => '155367'], $changes),
        ];
    }

    #[Test]
    public function testDirectCreateInsertsRowWithOperation(): void {
        $this->actingAs($this->makeUser(email: 'mp-create@example.com'));

        $res = $this->postJson('/api/v2/create', $this->createPayload(31672, 145495));

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'merged-person',
            'operation' => 'create',
        ]);
        $this->assertNotNull($res->json('result.operation_id'));
        $this->assertDatabaseHas('MERGED_PERSON_DATA', [
            'c_personid' => 31672,
            'c_merged_from_personid' => 145495,
            'c_source' => 71853,
            'c_pages' => '155367',
        ]);
        // c_created_by 由服務端填入登入者
        $this->assertSame('Merge Tester', DB::table('MERGED_PERSON_DATA')->value('c_created_by'));
        // 寫入 operations（可回滾）
        $this->assertSame(1, DB::table('operations')->where('resource', 'MERGED_PERSON_DATA')->count());
    }

    /** c_merged_from_personid 為已刪 id、不在 BIOG_MAIN；不應被校驗拒絕。 */
    #[Test]
    public function testDeletedFromIdNotValidatedAgainstBiogMain(): void {
        $this->actingAs($this->makeUser(email: 'mp-deleted@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload(31672, 999999999))
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('MERGED_PERSON_DATA', ['c_merged_from_personid' => 999999999]);
    }

    #[Test]
    public function testDuplicatePkReturns409(): void {
        $this->actingAs($this->makeUser(email: 'mp-dup@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload(31672, 145495))->assertOk();
        $this->postJson('/api/v2/create', $this->createPayload(31672, 145495))
            ->assertStatus(409)
            ->assertJson(['ok' => false]);
        $this->assertSame(1, DB::table('MERGED_PERSON_DATA')->count());
    }

    #[Test]
    public function testPersonIdMismatchReturns422(): void {
        $this->actingAs($this->makeUser(email: 'mp-mismatch@example.com'));

        $payload = $this->createPayload(31672, 145495);
        $payload['person_id'] = 40000; // 與 target.pk.c_personid 不一致

        $this->postJson('/api/v2/create', $payload)->assertStatus(422);
        $this->assertSame(0, DB::table('MERGED_PERSON_DATA')->count());
    }

    #[Test]
    public function testBatchCreateViaBatchMutate(): void {
        $this->actingAs($this->makeUser(email: 'mp-batch@example.com'));

        $items = [
            ['person_id' => 31672, 'target' => ['pk' => ['c_personid' => 31672, 'c_merged_from_personid' => 145495]], 'changes' => ['c_notes' => 'a', 'c_source' => 71853, 'c_pages' => '155367']],
            ['person_id' => 33024, 'target' => ['pk' => ['c_personid' => 33024, 'c_merged_from_personid' => 145645]], 'changes' => ['c_notes' => 'b', 'c_source' => 71853, 'c_pages' => '140163']],
        ];
        $this->postJson('/api/v2/batch_mutate', [
            'resource' => 'merged-person',
            'operation' => 'create',
            'items' => $items,
        ])->assertOk()->assertJson(['ok' => true, 'summary' => ['total' => 2, 'ok' => 2, 'failed' => 0]]);

        $this->assertSame(2, DB::table('MERGED_PERSON_DATA')->count());
    }

    #[Test]
    public function testDeleteRemovesRow(): void {
        $this->actingAs($this->makeUser(email: 'mp-del@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload(31672, 145495))->assertOk();
        $this->postJson('/api/v2/delete', [
            'resource' => 'merged-person',
            'person_id' => 31672,
            'target' => ['pk' => ['c_personid' => 31672, 'c_merged_from_personid' => 145495]],
        ])->assertOk()->assertJson(['ok' => true, 'operation' => 'delete']);

        $this->assertSame(0, DB::table('MERGED_PERSON_DATA')->count());
    }
}
