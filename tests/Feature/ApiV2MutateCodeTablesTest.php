<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase B code 表受審計更新 API（ConfigCodeTableMutationHandler + config/code_table_mutations.php）。
 * 涵蓋：單鍵單欄（GANZHI）、單鍵多欄（OFFICE）、單鍵三欄（ETHNICITY）、複合三鍵（TEXT_INSTANCE_DATA）。
 */
class ApiV2MutateCodeTablesTest extends TestCase {
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
        $this->createCodeTables();
    }

    protected function tearDown(): void {
        foreach (['GANZHI_CODES', 'OFFICE_CODES', 'ETHNICITY_TRIBE_CODES', 'TEXT_INSTANCE_DATA', 'audit_log', 'operations', 'personal_access_tokens', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
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

    protected function createCodeTables(): void {
        Schema::create('GANZHI_CODES', function (Blueprint $table) {
            $table->integer('c_ganzhi_code')->primary();
            $table->string('c_ganzhi_chn')->nullable();
            $table->string('c_ganzhi_py')->nullable();
        });
        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->string('c_office_chn')->nullable();
            $table->string('c_office_pinyin')->nullable();
            $table->string('c_office_pinyin_alt')->nullable();
            $table->string('c_office_trans')->nullable();
        });
        Schema::create('ETHNICITY_TRIBE_CODES', function (Blueprint $table) {
            $table->integer('c_ethnicity_code')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_romanized')->nullable();
            $table->string('c_surname')->nullable();
        });
        Schema::create('TEXT_INSTANCE_DATA', function (Blueprint $table) {
            $table->integer('c_textid');
            $table->integer('c_text_edition_id');
            $table->integer('c_text_instance_id');
            $table->string('c_instance_title')->nullable();
            $table->string('c_instance_title_chn')->nullable();
            $table->primary(['c_textid', 'c_text_edition_id', 'c_text_instance_id']);
        });
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'codetable-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    // ── 單鍵單欄：GANZHI_CODES ──────────────────────────────

    #[Test]
    public function testGanzhiDirectUpdateSucceedsAndAudits(): void {
        $this->actingAs($this->makeUser(email: 'ganzhi@example.com'));
        DB::table('GANZHI_CODES')->insert(['c_ganzhi_code' => 1, 'c_ganzhi_chn' => '甲子', 'c_ganzhi_py' => null]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'ganzhi_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_ganzhi_code' => 1]],
            'changes' => ['c_ganzhi_py' => 'jiazi'],
        ]);

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'ganzhi_codes',
            'result' => ['pk' => ['c_ganzhi_code' => 1], 'updated_fields' => ['c_ganzhi_py']],
        ]);
        $this->assertDatabaseHas('GANZHI_CODES', ['c_ganzhi_code' => 1, 'c_ganzhi_py' => 'jiazi']);
        // 審計 + operations（c_personid 一律 0）
        $this->assertDatabaseHas('audit_log', ['table_name' => 'GANZHI_CODES', 'operation' => 'UPDATE']);
        $this->assertDatabaseHas('operations', ['resource' => 'GANZHI_CODES', 'op_type' => Operation::TYPE_UPDATE, 'c_personid' => 0]);
    }

    #[Test]
    public function testCodeTableForcesPersonIdZeroEvenIfCallerSendsNonZero(): void {
        $this->actingAs($this->makeUser(email: 'ganzhi-pid@example.com'));
        DB::table('GANZHI_CODES')->insert(['c_ganzhi_code' => 2, 'c_ganzhi_chn' => '乙丑', 'c_ganzhi_py' => null]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'ganzhi_codes',
            'person_id' => 138841, // 呼叫端亂傳，handler 應忽略、一律存 0
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_ganzhi_code' => 2]],
            'changes' => ['c_ganzhi_py' => 'yichou'],
        ])->assertOk();

        $this->assertDatabaseHas('operations', ['resource' => 'GANZHI_CODES', 'c_personid' => 0]);
        $this->assertDatabaseMissing('operations', ['resource' => 'GANZHI_CODES', 'c_personid' => 138841]);
    }

    // ── 單鍵多欄：OFFICE_CODES ──────────────────────────────

    #[Test]
    public function testOfficeUpdatesBothPinyinColumnsAndRejectsTransColumn(): void {
        $this->actingAs($this->makeUser(email: 'office@example.com'));
        DB::table('OFFICE_CODES')->insert([
            'c_office_id' => 10, 'c_office_chn' => '尚書', 'c_office_pinyin' => null, 'c_office_pinyin_alt' => null, 'c_office_trans' => 'Minister',
        ]);

        // 允許同時更新 c_office_pinyin + c_office_pinyin_alt
        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_id' => 10]],
            'changes' => ['c_office_pinyin' => 'shangshu', 'c_office_pinyin_alt' => 'shang shu'],
        ])->assertOk();
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => 10, 'c_office_pinyin' => 'shangshu', 'c_office_pinyin_alt' => 'shang shu']);

        // 拒絕白名單外的欄位（c_office_trans 英譯欄不可經此 API 改）
        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_id' => 10]],
            'changes' => ['c_office_trans' => 'Secretary'],
        ])->assertStatus(422);
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => 10, 'c_office_trans' => 'Minister']); // 未被改
    }

    // ── 單鍵三欄：ETHNICITY_TRIBE_CODES ─────────────────────

    #[Test]
    public function testEthnicityUpdatesRomanizedColumn(): void {
        $this->actingAs($this->makeUser(email: 'ethnicity@example.com'));
        DB::table('ETHNICITY_TRIBE_CODES')->insert([
            'c_ethnicity_code' => 5, 'c_name_chn' => '契丹', 'c_name' => 'Qidan', 'c_romanized' => 'Kitan-Yelv', 'c_surname' => null,
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'ethnicity_tribe_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_ethnicity_code' => 5]],
            'changes' => ['c_romanized' => 'Kitan-Yelü'],
        ])->assertOk();
        $this->assertDatabaseHas('ETHNICITY_TRIBE_CODES', ['c_ethnicity_code' => 5, 'c_romanized' => 'Kitan-Yelü']);
    }

    // ── 複合三鍵：TEXT_INSTANCE_DATA ────────────────────────

    #[Test]
    public function testTextInstanceCompositeKeyUpdateSucceeds(): void {
        $this->actingAs($this->makeUser(email: 'textinstance@example.com'));
        DB::table('TEXT_INSTANCE_DATA')->insert([
            'c_textid' => 100, 'c_text_edition_id' => 2, 'c_text_instance_id' => 3, 'c_instance_title' => null, 'c_instance_title_chn' => '呂氏春秋',
        ]);
        // 放一列僅部分鍵相同者，確保 UPDATE 精準命中一列、不誤傷
        DB::table('TEXT_INSTANCE_DATA')->insert([
            'c_textid' => 100, 'c_text_edition_id' => 2, 'c_text_instance_id' => 9, 'c_instance_title' => 'other', 'c_instance_title_chn' => '別本',
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'text_instance_data',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_textid' => 100, 'c_text_edition_id' => 2, 'c_text_instance_id' => 3]],
            'changes' => ['c_instance_title' => 'Lüshi Chunqiu'],
        ])->assertOk()->assertJson([
            'result' => ['pk' => ['c_textid' => 100, 'c_text_edition_id' => 2, 'c_text_instance_id' => 3]],
        ]);

        $this->assertDatabaseHas('TEXT_INSTANCE_DATA', ['c_textid' => 100, 'c_text_instance_id' => 3, 'c_instance_title' => 'Lüshi Chunqiu']);
        // 另一列（c_text_instance_id=9）不受影響
        $this->assertDatabaseHas('TEXT_INSTANCE_DATA', ['c_textid' => 100, 'c_text_instance_id' => 9, 'c_instance_title' => 'other']);
    }

    // ── proposal 模式 ───────────────────────────────────────

    #[Test]
    public function testCodeTableProposalCreatesPendingOperationWithoutChangingRow(): void {
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'ganzhi-proposal@example.com'));
        DB::table('GANZHI_CODES')->insert(['c_ganzhi_code' => 7, 'c_ganzhi_chn' => '丙寅', 'c_ganzhi_py' => null]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'ganzhi_codes',
            'person_id' => 0,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => ['pk' => ['c_ganzhi_code' => 7]],
            'changes' => ['c_ganzhi_py' => 'bingyin'],
            'meta' => ['comment' => '補干支拼音'],
        ])->assertOk()->assertJson([
            'ok' => true,
            'mode' => 'proposal',
            'result' => ['status' => 'proposal_updated'],
        ]);

        // 原列未變
        $this->assertDatabaseHas('GANZHI_CODES', ['c_ganzhi_code' => 7, 'c_ganzhi_py' => null]);
        // 待審提案已建
        $this->assertDatabaseHas('operations', ['resource' => 'GANZHI_CODES', 'op_type' => Operation::TYPE_PROPOSAL_UPDATE]);
        $op = DB::table('operations')->where('resource', 'GANZHI_CODES')->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)->first();
        $payload = json_decode($op->resource_data, true);
        $this->assertSame('bingyin', $payload['c_ganzhi_py']);
        $this->assertSame(['c_ganzhi_code'], $payload['__key_columns']);
        $this->assertSame('干支代碼', $payload['__proposal_meta']['display_name']);
    }

    // ── 錯誤路徑 ────────────────────────────────────────────

    #[Test]
    public function testUnknownPkReturns404(): void {
        $this->actingAs($this->makeUser(email: 'ganzhi-404@example.com'));

        $this->postJson('/api/v2/mutate', [
            'resource' => 'ganzhi_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_ganzhi_code' => 999]],
            'changes' => ['c_ganzhi_py' => 'x'],
        ])->assertStatus(404);
    }

    #[Test]
    public function testInactiveUserForbidden(): void {
        $this->actingAs($this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'ganzhi-inactive@example.com'));
        DB::table('GANZHI_CODES')->insert(['c_ganzhi_code' => 8, 'c_ganzhi_chn' => '丁卯', 'c_ganzhi_py' => null]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'ganzhi_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_ganzhi_code' => 8]],
            'changes' => ['c_ganzhi_py' => 'dingmao'],
        ])->assertStatus(403);
    }
}
