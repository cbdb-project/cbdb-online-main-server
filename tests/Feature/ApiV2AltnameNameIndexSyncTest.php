<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CharVariantMapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v2 mutation API 的別名姓名索引（CBDB__NAME_FTS）同步測試。
 *
 * 這是 NameSearchIndexAutoSyncTest 五個 ALTNAME_DATA 案例的 **v2 等價覆蓋**。原測試走
 * legacy Blade 路由（POST/PUT/DELETE /basicinformation/{id}/altnames/...），會隨
 * docs/BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md 環節 2 一併下架；但它驗的是
 * **AGENTS.md §1.3 的資料完整性行為**——索引必須與 ALTNAME_DATA 實際落地的字形一致，
 * 不能是異體字替換前的原始輸入。因此在刪除 legacy 版之前，先在 v2 路徑上把同一組行為
 * 鎖住（計畫環節 1.5）。
 *
 * 對應關係（legacy → v2）：
 *   test_creating_altname_automatically_creates_index            → POST /api/v2/create
 *   test_updating_altname_reindexes                              → POST /api/v2/mutate
 *   test_creating_altname_with_variant_char_indexes_replaced_value → POST /api/v2/create（異體字）
 *   test_updating_altname_with_variant_char_reindexes_replaced_value → POST /api/v2/mutate（異體字）
 *   test_deleting_altname_removes_index                          → POST /api/v2/delete
 *
 * 索引同步的實作在 AltnameCreateHandler::syncAltnameIndexAfterCreate()、
 * AltnameMutationHandler::syncAltnameIndexAfterUpdate()、
 * AltnameDeleteHandler::syncAltnameIndexAfterDelete()。
 */
class ApiV2AltnameNameIndexSyncTest extends TestCase {
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
        $this->createOperationsTable();
        $this->createAuditLogTable();
        $this->createAltnameTable();
        $this->createAltnameCodesTable();
        $this->createNameFtsTable();
        $this->createCharVariantMapTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('CBDB__NAME_FTS');
        Schema::dropIfExists('ALTNAME_CODES');
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    // ── Table Setup ─────────────────────────────────────────

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

    protected function createAltnameTable(): void {
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->string('c_alt_name_chn', 255)->default('');
            $table->integer('c_alt_name_type_code')->default(0);
            $table->string('c_alt_name', 255)->nullable();
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_sequence')->default(0);
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code']);
        });
    }

    protected function createAltnameCodesTable(): void {
        Schema::create('ALTNAME_CODES', function (Blueprint $table) {
            $table->integer('c_name_type_code')->primary();
            $table->string('c_name_type_desc')->nullable();
            $table->string('c_name_type_desc_chn')->nullable();
        });

        DB::table('ALTNAME_CODES')->insert([
            ['c_name_type_code' => 4, 'c_name_type_desc' => 'zi', 'c_name_type_desc_chn' => '字'],
            ['c_name_type_code' => 5, 'c_name_type_desc' => 'hao', 'c_name_type_desc_chn' => '號'],
        ]);
    }

    protected function createNameFtsTable(): void {
        Schema::create('CBDB__NAME_FTS', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('c_personid');
            $table->unsignedSmallInteger('name_type_code')->nullable();
            $table->string('name_type_desc', 32);
            $table->string('name_type_desc_chn', 32);
            $table->string('search_term', 100);
            $table->string('full_name', 100);
            $table->string('source', 32);
            $table->string('source_key', 255)->nullable();
            $table->boolean('is_simplified')->default(false);
            $table->timestamps();

            $table->index(['search_term', 'c_personid'], 'idx_cbdb__name_search_term');
            $table->index('c_personid', 'idx_cbdb__name_person');
            $table->index('name_type_code', 'idx_cbdb__name_type');
        });
    }

    /**
     * 與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php
     * 相同的種子資料；本測試只用到 淸→清 與 厰→廠 兩筆（皆非 strict-excluded）。
     */
    protected function createCharVariantMapTable(): void {
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
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function makeActiveExpert(): User {
        return User::forceCreate([
            'name' => 'Admin Tester',
            'email' => 'v2-altname-index@example.com',
            'confirmation_token' => 'token-123',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_EXPERT,
        ]);
    }

    protected function seedAltname(int $personId, string $name, int $typeCode, int $sequence = 1): void {
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => $personId,
            'c_alt_name_chn' => $name,
            'c_alt_name_type_code' => $typeCode,
            'c_sequence' => $sequence,
            'c_source' => 0,
        ]);
    }

    /** 直接呼叫索引服務，模擬「該別名先前已建好索引」的狀態。 */
    protected function seedIndexFor(int $personId, int $typeCode, string $name): void {
        app(\App\Services\NameSearchIndexService::class)->indexAltname($personId, $typeCode, $name);
    }

    protected function indexExists(int $personId, string $searchTerm, ?int $typeCode = null): bool {
        $query = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', $personId)
            ->where('search_term', $searchTerm);

        if ($typeCode !== null) {
            $query->where('name_type_code', $typeCode);
        }

        return $query->exists();
    }

    // ── Create ───────────────────────────────────────────────

    #[Test]
    public function creating_altname_via_v2_creates_index(): void {
        $this->actingAs($this->makeActiveExpert())
            ->postJson('/api/v2/create', [
                'resource' => 'altnames',
                'person_id' => 2001,
                'mode' => 'direct',
                'target' => [
                    'pk' => [
                        'c_personid' => 2001,
                        'c_alt_name_chn' => '子瞻',
                        'c_alt_name_type_code' => 4,
                    ],
                ],
                'changes' => ['c_sequence' => 1],
            ])
            ->assertStatus(200);

        $this->assertTrue(
            $this->indexExists(2001, '子瞻', 4),
            'v2 新增別名後應建立 CBDB__NAME_FTS 索引'
        );
    }

    #[Test]
    public function creating_altname_with_variant_char_via_v2_indexes_replaced_value(): void {
        // 迴歸：索引必須與 ALTNAME_DATA 實際落地的字形一致（異體字替換後），
        // 不能殘留替換前的原始輸入——否則使用者用落地字形搜不到自己剛存的資料。
        $this->actingAs($this->makeActiveExpert())
            ->postJson('/api/v2/create', [
                'resource' => 'altnames',
                'person_id' => 2010,
                'mode' => 'direct',
                'target' => [
                    'pk' => [
                        'c_personid' => 2010,
                        'c_alt_name_chn' => '淸公',
                        'c_alt_name_type_code' => 4,
                    ],
                ],
                'changes' => ['c_sequence' => 1],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 2010,
            'c_alt_name_chn' => '清公',
        ]);

        $this->assertTrue($this->indexExists(2010, '清公', 4), '索引應以落地替換後的字形建立');
        $this->assertFalse($this->indexExists(2010, '淸公'), '索引不應殘留替換前的字形');
    }

    // ── Update ───────────────────────────────────────────────

    #[Test]
    public function updating_altname_via_v2_reindexes(): void {
        $this->seedAltname(2002, '東坡居士', 5);
        $this->seedIndexFor(2002, 5, '東坡居士');
        $this->assertTrue($this->indexExists(2002, '東坡居士', 5), '前置條件：舊索引應存在');

        $this->actingAs($this->makeActiveExpert())
            ->postJson('/api/v2/mutate', [
                'resource' => 'altnames',
                'person_id' => 2002,
                'mode' => 'direct',
                'operation' => 'update',
                'target' => [
                    'pk' => [
                        'c_personid' => 2002,
                        'c_alt_name_chn' => '東坡居士',
                        'c_alt_name_type_code' => 5,
                    ],
                ],
                'changes' => ['c_alt_name_chn' => '東坡先生'],
            ])
            ->assertStatus(200);

        $this->assertFalse($this->indexExists(2002, '東坡居士'), '舊別名索引應被刪除');
        $this->assertTrue($this->indexExists(2002, '東坡先生'), '新別名索引應被建立');
    }

    #[Test]
    public function updating_altname_with_variant_char_via_v2_reindexes_replaced_value(): void {
        $this->seedAltname(2011, '舊號', 5);
        $this->seedIndexFor(2011, 5, '舊號');

        $this->actingAs($this->makeActiveExpert())
            ->postJson('/api/v2/mutate', [
                'resource' => 'altnames',
                'person_id' => 2011,
                'mode' => 'direct',
                'operation' => 'update',
                'target' => [
                    'pk' => [
                        'c_personid' => 2011,
                        'c_alt_name_chn' => '舊號',
                        'c_alt_name_type_code' => 5,
                    ],
                ],
                'changes' => ['c_alt_name_chn' => '厰記'],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 2011,
            'c_alt_name_chn' => '廠記',
        ]);

        $this->assertTrue($this->indexExists(2011, '廠記'), '更新後索引應以落地替換後的字形重建');
        $this->assertFalse($this->indexExists(2011, '厰記'), '索引不應殘留替換前的字形');
        $this->assertFalse($this->indexExists(2011, '舊號'), '舊別名索引應被刪除');
    }

    // ── Delete ───────────────────────────────────────────────

    #[Test]
    public function deleting_altname_via_v2_removes_index(): void {
        $this->seedAltname(2003, '青蓮居士', 5);
        $this->seedIndexFor(2003, 5, '青蓮居士');
        $this->assertTrue($this->indexExists(2003, '青蓮居士', 5), '前置條件：索引應存在');

        $this->actingAs($this->makeActiveExpert())
            ->postJson('/api/v2/delete', [
                'resource' => 'altnames',
                'person_id' => 2003,
                'mode' => 'direct',
                'target' => [
                    'pk' => [
                        'c_personid' => 2003,
                        'c_alt_name_chn' => '青蓮居士',
                        'c_alt_name_type_code' => 5,
                    ],
                ],
            ])
            ->assertStatus(200);

        $this->assertFalse($this->indexExists(2003, '青蓮居士'), '刪除別名後索引應一併移除');
    }
}
