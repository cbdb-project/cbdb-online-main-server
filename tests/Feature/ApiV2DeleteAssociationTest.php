<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2DeleteAssociationTest extends TestCase {
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
        $this->createAssocTable();
        $this->createAssocCodesTable();
        $this->createKinshipCodesTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('KINSHIP_CODES');
        Schema::dropIfExists('ASSOC_CODES');
        Schema::dropIfExists('ASSOC_DATA');
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

    protected function createAssocTable(): void {
        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_assoc_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_assoc_kin_code')->default(0);
            $table->integer('c_assoc_kin_id')->default(0);
            $table->string('c_text_title', 255)->default('');
            $table->integer('c_assoc_first_year')->default(-9999);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->primary([
                'c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id',
                'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year',
            ]);
        });
    }

    /** 關係碼配對表：syncAssocMirrorOnDelete 策略 2 以舊關係碼查配對碼定位反向列。code 1↔2 互為配對。 */
    protected function createAssocCodesTable(): void {
        Schema::create('ASSOC_CODES', function (Blueprint $table) {
            $table->integer('c_assoc_code')->primary();
            $table->integer('c_assoc_pair')->nullable();
            $table->integer('c_assoc_pair2')->nullable();
        });
        DB::table('ASSOC_CODES')->insert([
            ['c_assoc_code' => 1, 'c_assoc_pair' => 2, 'c_assoc_pair2' => null],
            ['c_assoc_code' => 2, 'c_assoc_pair' => 1, 'c_assoc_pair2' => null],
            ['c_assoc_code' => 100, 'c_assoc_pair' => 101, 'c_assoc_pair2' => null],
            ['c_assoc_code' => 101, 'c_assoc_pair' => 100, 'c_assoc_pair2' => null],
        ]);
    }

    /** 親屬碼配對表：reverseKinPairCode 以 c_kin_pair1 查反向親屬碼。75↔76 互為配對。 */
    protected function createKinshipCodesTable(): void {
        Schema::create('KINSHIP_CODES', function (Blueprint $table) {
            $table->integer('c_kincode')->primary();
            $table->integer('c_kin_pair1')->nullable();
            $table->integer('c_kin_pair2')->nullable();
        });
        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 75, 'c_kin_pair1' => 76, 'c_kin_pair2' => null],
            ['c_kincode' => 76, 'c_kin_pair1' => 75, 'c_kin_pair2' => null],
        ]);
    }

    protected function pk(array $overrides = []): array {
        return array_replace([
            'c_personid' => 1000,
            'c_assoc_code' => 100,
            'c_assoc_id' => 2000,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '史記',
            'c_assoc_first_year' => 1080,
        ], $overrides);
    }

    protected function seedAssoc(array $overrides = []): void {
        DB::table('ASSOC_DATA')->insert(array_replace($this->pk(), ['c_source' => 10], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'delete-assoc-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function deletePayload(array $overrides = []): array {
        return array_replace_recursive([
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => ['pk' => $this->pk()],
        ], $overrides);
    }

    #[Test]
    public function testDirectAssociationDeleteSucceeds(): void {
        $user = $this->makeUser(email: 'delete-assoc-direct@example.com');
        $this->actingAs($user);
        $this->seedAssoc();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'associations',
            'mode' => 'direct',
            'operation' => 'delete',
        ]);
        $this->assertNotNull($response->json('result.operation_id'));

        $this->assertDatabaseMissing('ASSOC_DATA', [
            'c_personid' => 1000,
            'c_assoc_code' => 100,
            'c_assoc_id' => 2000,
            'c_text_title' => '史記',
        ]);
    }

    #[Test]
    public function testDirectAssociationDeleteRemovesReciprocalMirror(): void {
        // 後台自動雙向同步（32a-delete）：刪正向關係時，反向鏡像列同步刪除（重用 syncAssocMirrorOnDelete）。
        $this->actingAs($this->makeUser(email: 'delete-assoc-mirror@example.com'));
        $this->seedAssoc();
        // 反向鏡像（對方 2000 擁有、指回 1000、反向碼 101、對稱 0,0 → 策略 1 可定位）。
        DB::table('ASSOC_DATA')->insert(array_replace($this->pk([
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000,
        ]), ['c_source' => 10]));

        $this->postJson('/api/v2/delete', $this->deletePayload())->assertOk()->assertJson(['ok' => true]);

        // 正向已刪。
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);
        // 反向鏡像同步刪除（雙向）。
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000]);
    }

    #[Test]
    public function testDirectAssociationDeleteRemovesMirrorViaPairCodeStrategy(): void {
        // 策略 2：c_kin_id≠0（非對稱 0,0），以舊關係碼的配對碼（ASSOC_CODES[100].pair=101）定位反向鏡像並刪除。
        $this->actingAs($this->makeUser(email: 'delete-assoc-strat2@example.com'));
        $this->seedAssoc(['c_kin_id' => 5]);
        DB::table('ASSOC_DATA')->insert(array_replace($this->pk([
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000, 'c_kin_id' => 5,
        ]), ['c_source' => 10]));

        $this->postJson('/api/v2/delete', $this->deletePayload([
            'target' => ['pk' => $this->pk(['c_kin_id' => 5])],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_kin_id' => 5]);
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000]);
    }

    #[Test]
    public function testDirectAssociationDeletePreciselyTargetsMirrorAmongMultipleKinDimensions(): void {
        // codex MAJOR 修復：同對人/碼/書/年下多筆「僅 kin 維度不同」的關係，刪其一須精確命中該筆的反向鏡像，
        // 不誤刪他筆反向、也不因歧義漏刪。第一級以反向鏡像完整親屬維度（反向親屬碼＋kin_id=原人）精確定位。
        $this->actingAs($this->makeUser(email: 'delete-assoc-precise@example.com'));

        // 兩筆正向（同 1000-100-2000-…-史記-1080，僅 c_kin_code 不同：0 與 75）。
        $this->seedAssoc(['c_kin_code' => 0]);
        $this->seedAssoc(['c_kin_code' => 75]);
        // 對應反向鏡像（符合 create 鏡像約定：反向碼 101、反向親屬碼、kin_id=原人 1000）。
        DB::table('ASSOC_DATA')->insert(array_replace($this->pk([
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000,
            'c_kin_code' => 0, 'c_kin_id' => 1000, 'c_assoc_kin_id' => 1000,
        ]), ['c_source' => 10]));
        DB::table('ASSOC_DATA')->insert(array_replace($this->pk([
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000,
            'c_kin_code' => 76, 'c_kin_id' => 1000, 'c_assoc_kin_id' => 1000,
        ]), ['c_source' => 10]));

        // 刪 c_kin_code=75 的正向 → 精確刪 c_kin_code=76 的反向，保留 c_kin_code=0 的反向。
        $this->postJson('/api/v2/delete', $this->deletePayload([
            'target' => ['pk' => $this->pk(['c_kin_code' => 75])],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_kin_code' => 75]);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_kin_code' => 0]);
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_kin_code' => 76]);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_kin_code' => 0]);
    }

    #[Test]
    public function testDirectAssociationDeleteWithHyphenTextTitleSucceeds(): void {
        // c_text_title 含「-」：驗證 query-path/結構化 PK delete 不會誤拆
        $user = $this->makeUser(email: 'delete-assoc-hyphen@example.com');
        $this->actingAs($user);
        $this->seedAssoc(['c_text_title' => '論語-註釋']);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'target' => ['pk' => $this->pk(['c_text_title' => '論語-註釋'])],
        ]));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('ASSOC_DATA', [
            'c_personid' => 1000,
            'c_text_title' => '論語-註釋',
        ]);
    }

    #[Test]
    public function testDirectAssociationDeleteWritesOperationAndAudit(): void {
        $user = $this->makeUser(email: 'delete-assoc-op@example.com');
        $this->actingAs($user);
        $this->seedAssoc();

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'ASSOC_DATA',
            'op_type' => Operation::TYPE_DELETE,
        ]);
        $audit = DB::table('audit_log')->where('table_name', 'ASSOC_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('DELETE', $audit->operation);
    }

    #[Test]
    public function testProposalAssociationDeleteWritesPendingProposal(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-assoc-proposal@example.com');
        $this->actingAs($user);
        $this->seedAssoc();

        $this->postJson('/api/v2/delete', $this->deletePayload(['mode' => 'proposal']))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'mode' => 'proposal',
                'operation' => 'delete',
                'result' => ['status' => 'proposal_deleted'],
            ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'ASSOC_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
        ]);
        $op = DB::table('operations')->where('op_type', Operation::TYPE_PROPOSAL_DELETE)->first();
        $payload = json_decode($op->resource_data, true);
        $this->assertSame('pending', $payload['__review_status']);

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);

        $this->assertSame(0, DB::table('audit_log')->where('table_name', 'ASSOC_DATA')->where('operation', 'DELETE')->count());
    }

    #[Test]
    public function testDeleteTargetMissingReturns404(): void {
        $user = $this->makeUser(email: 'delete-assoc-404@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(404);
    }

    #[Test]
    public function testDeleteWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'delete-assoc-mismatch@example.com');
        $this->actingAs($user);
        $this->seedAssoc();

        $this->postJson('/api/v2/delete', $this->deletePayload(['person_id' => 9999]))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testDeleteRejectsUnauthenticatedUser(): void {
        $this->seedAssoc();
        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(401);
    }

    #[Test]
    public function testDeleteRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'delete-assoc-inactive@example.com');
        $this->actingAs($user);
        $this->seedAssoc();

        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(403);
    }

    #[Test]
    public function testDirectDeleteRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-assoc-crowd@example.com');
        $this->actingAs($user);
        $this->seedAssoc();

        $this->postJson('/api/v2/delete', $this->deletePayload(['mode' => 'direct']))->assertStatus(403);
    }
}
