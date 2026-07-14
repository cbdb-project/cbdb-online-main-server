<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 「新增官職」mutation（resource=office → OFFICE_CODES + OFFICE_CODE_TYPE_REL）回歸測試。
 *
 * 驗證 OfficeImportHandler / OfficeImportService 的複合存儲過程：兩表原子寫入、自動 office_id、
 * 拼音/朝代碼派生、operations + audit_log、以及來源/類型/朝代校驗。
 */
class ApiV2MutateOfficeImportTest extends TestCase {
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
        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->integer('c_dy')->nullable();
            $table->string('c_office_pinyin')->nullable();
            $table->string('c_office_trans')->nullable();
            $table->string('c_office_chn')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
        Schema::create('OFFICE_CODE_TYPE_REL', function (Blueprint $table) {
            $table->integer('c_office_id');
            $table->string('c_office_tree_id');
            $table->primary(['c_office_id', 'c_office_tree_id']);
        });
        Schema::create('OFFICE_TYPE_TREE', function (Blueprint $table) {
            $table->string('c_office_type_node_id')->primary();
        });
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
        });
        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn')->nullable();
            $table->string('c_pinyin')->nullable();
            $table->integer('c_lastname')->default(0);
        });

        DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);
        DB::table('OFFICE_TYPE_TREE')->insert(['c_office_type_node_id' => 'x01']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 7596]);
    }

    protected function tearDown(): void {
        foreach (['pinyin', 'TEXT_CODES', 'DYNASTIES', 'OFFICE_TYPE_TREE', 'OFFICE_CODE_TYPE_REL', 'OFFICE_CODES', 'audit_log', 'operations', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'of@example.com'): User {
        return User::create([
            'name' => 'Office Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function payload(array $overrides = []): array {
        return array_merge([
            'resource' => 'office',
            'person_id' => 0,
            'target' => ['pk' => []],
            'changes' => [
                'name' => '知府',
                'translation' => 'Prefect',
                'dynasty_code' => 15,
                'type_id' => 'x01',
                'source_id' => 7596,
            ],
        ], $overrides);
    }

    #[Test]
    public function testCreateWritesBothTablesAtomically(): void {
        DB::table('OFFICE_CODES')->insert(['c_office_id' => 100, 'c_office_chn' => '既有']);
        $this->actingAs($this->makeUser(email: 'of-create@example.com'));

        $res = $this->postJson('/api/v2/create', $this->payload());

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'office',
            'operation' => 'create',
            'result' => ['pk' => ['c_office_id' => 101]],
        ]);
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => 101, 'c_office_chn' => '知府', 'c_dy' => 15, 'c_source' => 7596]);
        $this->assertDatabaseHas('OFFICE_CODE_TYPE_REL', ['c_office_id' => 101, 'c_office_tree_id' => 'x01']);
        $this->assertSame('Office Tester', DB::table('OFFICE_CODES')->where('c_office_id', 101)->value('c_created_by'));
        // operations + audit：OFFICE_CODES 與 OFFICE_CODE_TYPE_REL 各一
        $this->assertSame(1, DB::table('operations')->where('resource', 'OFFICE_CODES')->count());
        $this->assertSame(1, DB::table('operations')->where('resource', 'OFFICE_CODE_TYPE_REL')->count());
        $this->assertSame(2, DB::table('audit_log')->count());
    }

    #[Test]
    public function testMissingSourceReturns422AndWritesNothing(): void {
        $this->actingAs($this->makeUser(email: 'of-src@example.com'));

        $p = $this->payload();
        $p['changes']['source_id'] = 999999; // 不在 TEXT_CODES
        $this->postJson('/api/v2/create', $p)->assertStatus(422);
        $this->assertSame(0, DB::table('OFFICE_CODES')->count());
    }

    #[Test]
    public function testMissingOfficeTypeReturns422(): void {
        $this->actingAs($this->makeUser(email: 'of-type@example.com'));

        $p = $this->payload();
        $p['changes']['type_id'] = 'nope'; // 不在 OFFICE_TYPE_TREE
        $this->postJson('/api/v2/create', $p)->assertStatus(422);
        $this->assertSame(0, DB::table('OFFICE_CODES')->count());
    }

    #[Test]
    public function testDynastyLabelResolvesToCode(): void {
        $this->actingAs($this->makeUser(email: 'of-dyn@example.com'));

        $p = $this->payload();
        unset($p['changes']['dynasty_code']);
        $p['changes']['dynasty_label'] = '宋';
        $this->postJson('/api/v2/create', $p)->assertOk();
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_chn' => '知府', 'c_dy' => 15]);
    }
}
