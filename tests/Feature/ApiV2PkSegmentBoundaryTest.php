<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 複合主鍵「PK 片段邊界」回歸（F7 W3）。
 *
 * 收斂標準要求覆蓋 query 路徑 store/update/destroy 的 NULL PK 片段邊界。實務上：
 * - 必填 PK 片段：若缺省或為空字串，ConvertEmptyStringsToNull middleware 會轉成 null，
 *   再由 CompositePrimaryKey::validateOrFail 以 4xx 擋下，**不會把壞的 NULL 寫進 NOT NULL 主鍵**。
 *   本測試以 texts（3-key）與 associations（9-key 複合）為代表，驗證 create/delete 兩條路徑的此守衛。
 * - 唯一真正可空的 PK 片段是 BIOG_SOURCE_DATA.c_pages（canonical 為 ''）：其 create 空值覆蓋於
 *   ApiV2MutateTest，delete 空值 round-trip 覆蓋於 ApiV2DeleteSourceTest，故此處不重複。
 * - 哨兵 PK（assoc c_text_title='[n/a]'、c_assoc_first_year='-9999' 等）覆蓋於 ApiV2CreateAssociationTest。
 */
class ApiV2PkSegmentBoundaryTest extends TestCase {
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
        $this->createTextTable();
        $this->createAssocTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ASSOC_DATA');
        Schema::dropIfExists('BIOG_TEXT_DATA');
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

    protected function createTextTable(): void {
        Schema::create('BIOG_TEXT_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid')->default(0);
            $table->integer('c_role_id')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->primary(['c_personid', 'c_textid', 'c_role_id']);
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
            $table->text('c_notes')->nullable();
            $table->primary([
                'c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id',
                'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year',
            ]);
        });
    }

    protected function makeUser(string $email): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    // ── texts（3-key）：缺必填 PK 片段 ──────────────────────────

    #[Test]
    public function testTextCreateMissingRequiredPkSegmentRejected(): void {
        $this->actingAs($this->makeUser('pk-text-create@example.com'));

        // 缺 c_role_id（必填 PK 片段）
        $response = $this->postJson('/api/v2/create', [
            'resource' => 'texts',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500]],
            'changes' => ['c_source' => 20],
        ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame(0, DB::table('BIOG_TEXT_DATA')->count());
    }

    #[Test]
    public function testTextDeleteMissingRequiredPkSegmentRejected(): void {
        $this->actingAs($this->makeUser('pk-text-delete@example.com'));
        DB::table('BIOG_TEXT_DATA')->insert([
            'c_personid' => 1000, 'c_textid' => 500, 'c_role_id' => 1, 'c_source' => 10,
        ]);

        $response = $this->postJson('/api/v2/delete', [
            'resource' => 'texts',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500]],
        ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        // 記錄未被刪
        $this->assertDatabaseHas('BIOG_TEXT_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_role_id' => 1]);
    }

    // ── associations（9-key 複合）：缺必填 PK 片段 ───────────────

    protected function fullAssocPk(array $overrides = []): array {
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

    #[Test]
    public function testAssociationCreateMissingRequiredPkSegmentRejected(): void {
        $this->actingAs($this->makeUser('pk-assoc-create@example.com'));

        $pk = $this->fullAssocPk();
        unset($pk['c_assoc_id']); // 缺一段必填 PK

        $response = $this->postJson('/api/v2/create', [
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => ['pk' => $pk],
            'changes' => ['c_source' => 20],
        ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame(0, DB::table('ASSOC_DATA')->count());
    }

    #[Test]
    public function testAssociationDeleteMissingRequiredPkSegmentRejected(): void {
        $this->actingAs($this->makeUser('pk-assoc-delete@example.com'));
        DB::table('ASSOC_DATA')->insert(array_replace($this->fullAssocPk(), ['c_source' => 10]));

        $pk = $this->fullAssocPk();
        unset($pk['c_assoc_id']);

        $response = $this->postJson('/api/v2/delete', [
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => ['pk' => $pk],
        ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame(1, DB::table('ASSOC_DATA')->count());
    }
}
