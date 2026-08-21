<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Services\CharVariantMapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutateTest extends TestCase {
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
        $this->createBiogMainTable();
        $this->createAltnameTable();
        $this->createSourceTable();
        $this->createTextCodesTable();
        $this->createCharVariantMapTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('BIOG_SOURCE_DATA');
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    /**
     * 與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php
     * 相同的 7 筆種子資料，供 BIOG_MAIN 異體字落地替換測試使用。
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

    protected function createBiogMainTable(): void {
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_name_proper')->nullable();
            $table->string('c_name_rm')->nullable();
            $table->string('c_surname_chn')->nullable();
            $table->string('c_mingzi_chn')->nullable();
            $table->string('c_surname')->nullable();
            $table->string('c_mingzi')->nullable();
            $table->string('c_surname_proper')->nullable();
            $table->string('c_mingzi_proper')->nullable();
            $table->string('c_surname_rm')->nullable();
            $table->string('c_mingzi_rm')->nullable();
            $table->integer('c_female')->nullable();
            $table->integer('c_by_intercalary')->default(0);
            $table->integer('c_dy_intercalary')->default(0);
            $table->integer('c_index_year')->nullable();
            $table->integer('c_death_age')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_tribe')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function createAltnameTable(): void {
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->nullable();
            $table->string('c_alt_name_chn');
            $table->string('c_alt_name')->nullable();
            $table->integer('c_alt_name_type_code');
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function createSourceTable(): void {
        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->string('c_pages');
            $table->text('c_notes')->nullable();
            $table->integer('c_main_source')->default(0);
            $table->integer('c_self_bio')->default(0);
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function createTextCodesTable(): void {
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title')->nullable();
            $table->string('c_title_chn')->nullable();
        });
    }

    protected function seedTextCode(int $textId = 99999): void {
        DB::table('TEXT_CODES')->insert([
            'c_textid' => $textId,
            'c_title' => 'Test Source',
            'c_title_chn' => '測試出處',
        ]);
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'tester@example.com'): User {
        return User::forceCreate([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function sourceCreatePayload(array $overrides = []): array {
        $payload = [
            'resource' => 'sources',
            'person_id' => 138841,
            'mode' => 'proposal',
            'operation' => 'create',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                    'c_textid' => 99999,
                    'c_pages' => '張忠墓誌',
                ],
            ],
            'changes' => [
                'c_personid' => 138841,
                'c_textid' => 99999,
                'c_pages' => '張忠墓誌',
                'c_notes' => '來自浙江大學圖書館中國歷代墓誌數據庫',
                'c_main_source' => 0,
                'c_self_bio' => 0,
            ],
            'meta' => [
                'comment' => '由 API 自動提交',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    protected function seedBiogMain(array $overrides = []): void {
        DB::table('BIOG_MAIN')->insert(array_replace([
            'c_personid' => 138841,
            'c_name_chn' => '張忠',
            'c_name' => 'Zhang Zhong',
            'c_name_proper' => 'Zhong Zhang',
            'c_name_rm' => 'Chung Chang',
            'c_surname_chn' => '張',
            'c_mingzi_chn' => '忠',
            'c_surname' => 'Zhang',
            'c_mingzi' => 'Zhong',
            'c_surname_proper' => 'Zhang',
            'c_mingzi_proper' => 'Zhong',
            'c_surname_rm' => 'Chang',
            'c_mingzi_rm' => 'Chung',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_index_year' => 1084,
            'c_death_age' => 60,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ], $overrides));
    }

    #[Test]
    public function testBiogMainNullableFieldsSentinelFullyIdempotent() {
        // basic_info 的 nullable 欄（c_female / c_index_year 等，real schema nullable（0/值有意義））語義與子資源碼欄不同：
        // 空表示（null/''/'NULL'）一律規範化為 **null**（非 0）、有效數值原樣保留、來回不翻。≥10 案例。
        $this->actingAs($this->makeUser(email: 'biog-sentinel@example.com'));
        $pid = 138841;
        $patch = function ($field, $value) use ($pid) {
            return $this->postJson('/api/v2/mutate', [
                'resource' => 'basicinformation', 'person_id' => $pid, 'mode' => 'direct', 'operation' => 'update',
                'target' => ['pk' => ['c_personid' => $pid]],
                'changes' => [$field => $value],
            ]);
        };
        $val = fn ($field) => DB::table('BIOG_MAIN')->where('c_personid', $pid)->value($field);

        // c_female：空表示（null/''/'NULL'）→ null（從非 null seed 確保真寫入）。
        foreach ([null, '', 'NULL'] as $sent) {
            DB::table('BIOG_MAIN')->where('c_personid', $pid)->delete();
            $this->seedBiogMain(['c_female' => 1]);
            $patch('c_female', $sent)->assertOk();
            $this->assertNull($val('c_female'), 'c_female 送 '.var_export($sent, true).' 應規範化為 null');
        }
        // c_female：有效數值（0/1/2）原樣保留為 int。
        foreach ([0, 1, 2] as $sent) {
            DB::table('BIOG_MAIN')->where('c_personid', $pid)->delete();
            $this->seedBiogMain(['c_female' => 9]);
            $patch('c_female', $sent)->assertOk();
            $this->assertSame($sent, (int) $val('c_female'), 'c_female 送 '.$sent.' 應保留');
        }
        // c_index_year：空→null；有效值保留。
        foreach ([null, ''] as $sent) {
            DB::table('BIOG_MAIN')->where('c_personid', $pid)->delete();
            $this->seedBiogMain(['c_index_year' => 1050]);
            $patch('c_index_year', $sent)->assertOk();
            $this->assertNull($val('c_index_year'), 'c_index_year 送 '.var_export($sent, true).' 應→null');
        }
        foreach ([500, -200] as $sent) {
            DB::table('BIOG_MAIN')->where('c_personid', $pid)->delete();
            $this->seedBiogMain(['c_index_year' => 1050]);
            $patch('c_index_year', $sent)->assertOk();
            $this->assertSame($sent, (int) $val('c_index_year'), 'c_index_year 送 '.$sent.' 應保留');
        }
    }

    #[Test]
    public function testDirectBiogMainUpdateCanPatchSingleFieldAndWritesAuditInfo() {
        $user = $this->makeUser(email: 'biog-main-single@example.com');
        $this->actingAs($user);
        $this->seedBiogMain();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                ],
            ],
            'changes' => [
                'c_surname_chn' => '章',
            ],
            'meta' => [
                'comment' => '單欄位修正',
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'basicinformation',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 138841,
                    ],
                    'updated_fields' => ['c_surname_chn'],
                ],
            ]);

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 138841,
            'c_surname_chn' => '章',
            'c_name_chn' => '章忠',
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_MAIN',
            'c_personid' => 138841,
            'op_type' => Operation::TYPE_UPDATE,
        ]);

        $operation = DB::table('operations')
            ->where('resource', 'BIOG_MAIN')
            ->where('c_personid', 138841)
            ->first();

        $resourceData = json_decode($operation->resource_data, true);
        $resourceOriginal = json_decode($operation->resource_original, true);

        $this->assertSame('章', $resourceData['c_surname_chn']);
        $this->assertSame('章忠', $resourceData['c_name_chn']);
        $this->assertSame('單欄位修正', $resourceData['__note']);
        $this->assertSame('張', $resourceOriginal['c_surname_chn']);
        $this->assertSame('張忠', $resourceOriginal['c_name_chn']);

        $audit = DB::table('audit_log')->where('table_name', 'BIOG_MAIN')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
        $this->assertSame('c_personid=138841', $audit->row_pk_text);

        $oldData = json_decode($audit->old_data, true);
        $newData = json_decode($audit->new_data, true);

        $this->assertSame('張', $oldData['c_surname_chn']);
        $this->assertSame('章', $newData['c_surname_chn']);
        $this->assertSame('張忠', $oldData['c_name_chn']);
        $this->assertSame('章忠', $newData['c_name_chn']);
    }

    #[Test]
    public function testDirectBiogMainUpdateCanPatchMultipleFields() {
        $user = $this->makeUser(email: 'biog-main-multi@example.com');
        $this->actingAs($user);
        $this->seedBiogMain();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'biogmain',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                ],
            ],
            'changes' => [
                'c_surname_chn' => '李',
                'c_mingzi_chn' => '安',
                'c_surname' => 'Li',
                'c_mingzi' => 'An',
                'c_female' => 1,
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'basicinformation',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => [
                        'c_surname_chn',
                        'c_mingzi_chn',
                        'c_surname',
                        'c_mingzi',
                        'c_female',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 138841,
            'c_surname_chn' => '李',
            'c_mingzi_chn' => '安',
            'c_name_chn' => '李安',
            'c_surname' => 'Li',
            'c_mingzi' => 'An',
            'c_name' => 'Li An',
            'c_female' => 1,
        ]);

        $audit = DB::table('audit_log')->where('table_name', 'BIOG_MAIN')->first();
        $newData = json_decode($audit->new_data, true);

        $this->assertSame('李安', $newData['c_name_chn']);
        $this->assertSame('Li An', $newData['c_name']);
        $this->assertSame(1, $newData['c_female']);
    }

    #[Test]
    public function testDirectBiogMainUpdateNormalizesPinyinVToUmlaut() {
        $user = $this->makeUser(email: 'biog-main-umlaut@example.com');
        $this->actingAs($user);
        $this->seedBiogMain();

        // Tier 1：c_surname/c_mingzi/c_name 靜默轉；_rm（Wade-Giles）與 _proper（母語拉丁名）不可轉。
        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'biogmain',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => [
                'c_surname' => 'Lv',
                'c_mingzi' => 'Meng',
                'c_surname_rm' => 'Lv',
                'c_surname_proper' => 'Silva',
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 138841,
            'c_surname' => 'Lü',        // Tier 1 靜默轉
            'c_mingzi' => 'Meng',
            'c_name' => 'Lü Meng',      // 由分量重算、同步為 ü
            'c_surname_rm' => 'Lv',     // Wade-Giles：不轉
            'c_surname_proper' => 'Silva', // 母語拉丁名：不轉
        ]);
    }

    #[Test]
    public function testProposalBiogMainUpdateNormalizesPinyinVToUmlautInPayload() {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'biog-proposal-umlaut@example.com');
        $this->actingAs($user);
        $this->seedBiogMain();

        // 提案於提交時歸一化（核准逐字套用）；_rm 不轉。
        $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation',
            'person_id' => 138841,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => [
                'c_surname' => 'Lv',
                'c_surname_rm' => 'Lv',
            ],
            'meta' => ['comment' => '提案修正姓氏拼音'],
        ])->assertOk();

        $operation = DB::table('operations')
            ->where('resource', 'BIOG_MAIN')
            ->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)
            ->first();
        $payload = json_decode($operation->resource_data, true);

        $this->assertSame('Lü', $payload['c_surname']);    // 提交時歸一化
        $this->assertSame('Lü Zhong', $payload['c_name']); // c_name 由分量重算後亦為 ü
        $this->assertSame('Lv', $payload['c_surname_rm']); // Wade-Giles：不轉
    }

    #[Test]
    public function testDirectBiogMainUpdateDoesNotReplaceStrictExcludedVariant() {
        $user = $this->makeUser(email: 'biog-variant-strict-excluded@example.com');
        $this->actingAs($user);
        $this->seedBiogMain();

        // 峯（c_strict_excluded=1）僅在寬鬆模式可替換，嚴格模式（人名）須排除。
        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'biogmain',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => [
                'c_mingzi_chn' => '峯',
            ],
        ]);

        $response->assertOk();
        $response->assertJsonMissing(['notices' => []]);
        $this->assertArrayNotHasKey('notices', $response->json());

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 138841,
            'c_surname_chn' => '張',
            'c_mingzi_chn' => '峯',
            'c_name_chn' => '張峯',
        ]);
    }

    #[Test]
    public function testDirectBiogMainUpdateReplacesStrictModeVariantAndReturnsNotice() {
        $user = $this->makeUser(email: 'biog-variant-strict-replace@example.com');
        $this->actingAs($user);
        $this->seedBiogMain();

        // 淸（c_strict_excluded=0）在嚴格模式也可替換：分欄先替換，再組出 c_name_chn，
        // 維持 c_name_chn === c_surname_chn.c_mingzi_chn 的 invariant。
        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'biogmain',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => [
                'c_mingzi_chn' => '淸',
            ],
        ]);

        $response->assertOk();
        $body = $response->json();
        $this->assertArrayHasKey('notices', $body);
        $this->assertStringContainsString('淸', $body['notices'][0]);
        $this->assertStringContainsString('清', $body['notices'][0]);

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 138841,
            'c_surname_chn' => '張',
            'c_mingzi_chn' => '清',
            'c_name_chn' => '張清',
        ]);
    }

    #[Test]
    public function testProposalBiogMainUpdateReplacesStrictModeVariantInPayloadAndReturnsNotice() {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'biog-proposal-variant@example.com');
        $this->actingAs($user);
        $this->seedBiogMain();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation',
            'person_id' => 138841,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => [
                'c_mingzi_chn' => '淸',
            ],
            'meta' => ['comment' => '提案修正名字異體字'],
        ]);

        $response->assertOk();
        $body = $response->json();
        $this->assertArrayHasKey('notices', $body);
        $this->assertStringContainsString('淸', $body['notices'][0]);
        $this->assertStringContainsString('清', $body['notices'][0]);

        $operation = DB::table('operations')
            ->where('resource', 'BIOG_MAIN')
            ->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)
            ->first();
        $payload = json_decode($operation->resource_data, true);

        $this->assertSame('清', $payload['c_mingzi_chn']);
        $this->assertSame('張清', $payload['c_name_chn']);
        // 提案未核准前，BIOG_MAIN 本身不應變更。
        $this->assertDatabaseHas('BIOG_MAIN', ['c_personid' => 138841, 'c_mingzi_chn' => '忠']);
    }

    /**
     * 只替換「使用者本次實際變更的欄」，不對他沒碰過的既有欄做回溯校正（D6）。
     *
     * BIOG_MAIN 是唯一在伺服器端把「原列 ∪ changes」合成整列再送進 repository 的資源，
     * 若整列替換會有三個後果：(1) 回溯改寫使用者沒送的欄；(2) updated_fields 與實際落庫
     * 不一致，提案審核 diff 會出現提案人沒改過的欄；(3) 某個舊欄被歸一會讓「完全沒改」
     * 的存檔變成一筆真實 UPDATE。
     */
    #[Test]
    public function testDirectBiogMainUpdateOnlyReplacesVariantsInSubmittedFields() {
        $this->actingAs($this->makeUser(email: 'biog-scoped-variant@example.com'));
        // 既有列的 c_notes 存著變體形（D6：既有資料不做回溯校正）。
        $this->seedBiogMain(['c_notes' => '淸流舊注']);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => ['c_death_age' => 61],
        ])->assertOk();

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 138841,
            'c_death_age' => 61,
            'c_notes' => '淸流舊注', // 使用者沒送 c_notes ⇒ 不得被歸一
        ]);
    }

    /** 送出的欄本身有變體字時照樣替換（與上一條互補，確保收窄沒有把機制關掉）。 */
    #[Test]
    public function testDirectBiogMainUpdateStillReplacesVariantsInTheFieldUserSubmitted() {
        $this->actingAs($this->makeUser(email: 'biog-scoped-variant-on@example.com'));
        $this->seedBiogMain(['c_notes' => '舊注']);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => ['c_notes' => '峯下人'],
        ])->assertOk();

        $this->assertDatabaseHas('BIOG_MAIN', ['c_personid' => 138841, 'c_notes' => '峰下人']);
        $this->assertNotEmpty($response->json('notices'));
    }

    /**
     * 使用者送的字被歸一成與現值相同 ⇒ 422「未偵測到任何修改內容」，
     * 這條回應也必須帶 notices，否則訊息看起來毫無道理。
     */
    #[Test]
    public function testDirectBiogMainUpdateNoChangesResponseCarriesVariantNotices() {
        $this->actingAs($this->makeUser(email: 'biog-nochange-notice@example.com'));
        $this->seedBiogMain(['c_notes' => '清流']);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => ['c_notes' => '淸流'],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('notices'), '422 也要帶異體字通知');
        $this->assertDatabaseHas('BIOG_MAIN', ['c_personid' => 138841, 'c_notes' => '清流']);
    }

    /**
     * 沒有明確姓氏的歷史人物（c_surname_chn／c_mingzi_chn 皆 NULL、c_name_chn 存完整姓名）：
     * 對他做任何更新都不得把 c_name_chn 清成空字串。
     *
     * 舊碼在 updateById()／prepareProposalPayload() 無條件 `c_name_chn = 姓 . 名`，
     * 而 v2 的 payload 是「原列 ∪ changes」⇒ 兩個 NULL 分欄相加＝''，姓名靜默消失。
     * store() 早就有等價保護。
     */
    #[Test]
    public function testDirectBiogMainUpdateDoesNotClearNameChnWhenPartsAreNull() {
        $this->actingAs($this->makeUser(email: 'biog-unsplit-name@example.com'));
        $this->seedBiogMain([
            'c_name_chn' => '完顏阿骨打',
            'c_surname_chn' => null,
            'c_mingzi_chn' => null,
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => ['c_death_age' => 61],
        ])->assertOk();

        $this->assertDatabaseHas('BIOG_MAIN', ['c_personid' => 138841, 'c_name_chn' => '完顏阿骨打', 'c_death_age' => 61]);
    }

    /** 提案路徑同理（payload 不經 repository）。 */
    #[Test]
    public function testProposalBiogMainUpdateDoesNotClearNameChnWhenPartsAreNull() {
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'biog-unsplit-proposal@example.com'));
        $this->seedBiogMain([
            'c_name_chn' => '完顏阿骨打',
            'c_surname_chn' => null,
            'c_mingzi_chn' => null,
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation',
            'person_id' => 138841,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => ['c_death_age' => 61],
            'meta' => ['comment' => '只改卒年'],
        ])->assertOk();

        $operation = DB::table('operations')->where('resource', 'BIOG_MAIN')->first();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('完顏阿骨打', $payload['c_name_chn'], '提案 payload 不得把未分欄的姓名清空');
    }

    /**
     * 提案 payload 也必須套用「姓名 strict、其餘文本欄 lenient」——這條路徑不經
     * BiogMainRepository，而是 BiogMainMutationHandler::prepareProposalPayload()。
     * 漏掉它會讓審核畫面看到的字形與核准後落庫的不一致（plan S3）。
     */
    #[Test]
    public function testProposalBiogMainUpdateAppliesLenientRuleToNonNameTextColumns() {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'biog-proposal-lenient@example.com');
        $this->actingAs($user);
        $this->seedBiogMain();

        $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation',
            'person_id' => 138841,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => [
                'c_mingzi_chn' => '峯',
                'c_notes' => '峯下人',
            ],
            'meta' => ['comment' => '提案：名用嚴格規則、備註用全量規則'],
        ])->assertOk();

        $operation = DB::table('operations')
            ->where('resource', 'BIOG_MAIN')
            ->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)
            ->first();
        $payload = json_decode($operation->resource_data, true);

        $this->assertSame('峯', $payload['c_mingzi_chn'], '名字欄 strict：峯 不替換');
        $this->assertSame('張峯', $payload['c_name_chn']);
        $this->assertSame('峰下人', $payload['c_notes'], '備註欄 lenient：峯→峰');
    }

    #[Test]
    public function testProposalBiogMainUpdateDoesNotReplaceStrictExcludedVariantInPayload() {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'biog-proposal-variant-excluded@example.com');
        $this->actingAs($user);
        $this->seedBiogMain();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation',
            'person_id' => 138841,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 138841]],
            'changes' => [
                'c_mingzi_chn' => '峯',
            ],
            'meta' => ['comment' => '提案修正名字'],
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('notices', $response->json());

        $operation = DB::table('operations')
            ->where('resource', 'BIOG_MAIN')
            ->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)
            ->first();
        $payload = json_decode($operation->resource_data, true);

        $this->assertSame('峯', $payload['c_mingzi_chn']);
        $this->assertSame('張峯', $payload['c_name_chn']);
    }

    #[Test]
    public function testProposalBiogMainUpdateCreatesPendingOperationWithoutChangingRow() {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'biog-main-proposal@example.com');
        $this->actingAs($user);
        $this->seedBiogMain();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation',
            'person_id' => 138841,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                ],
            ],
            'changes' => [
                'c_surname_chn' => '章',
                'c_surname' => 'Zhang',
            ],
            'meta' => [
                'comment' => '提案修正姓氏',
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'basicinformation',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 138841,
                    ],
                    'updated_fields' => [
                        'c_surname_chn',
                        'c_surname',
                    ],
                    'status' => 'proposal_updated',
                ],
            ]);

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 138841,
            'c_surname_chn' => '張',
            'c_name_chn' => '張忠',
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_MAIN',
            'c_personid' => 138841,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')
            ->where('resource', 'BIOG_MAIN')
            ->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)
            ->first();

        $payload = json_decode($operation->resource_data, true);
        $original = json_decode($operation->resource_original, true);

        $this->assertSame('c_personid=138841', $operation->resource_id);
        $this->assertSame('章', $payload['c_surname_chn']);
        $this->assertSame('章忠', $payload['c_name_chn']);
        $this->assertSame('pending', $payload['__review_status']);
        $this->assertSame('update', $payload['__proposal_meta']['action']);
        $this->assertSame('biogmain', $payload['__proposal_meta']['resource_type']);
        $this->assertSame('提案修正姓氏', $payload['__proposal_meta']['comment']);
        $this->assertSame('張', $original['c_surname_chn']);
        $this->assertSame('張忠', $original['c_name_chn']);

        $this->assertDatabaseCount('audit_log', 0);
    }

    #[Test]
    public function testBiogMainUpdateRejectsClearingMingziWhenOriginalNonEmpty() {
        // 「不可清空」語義：名（中）／拼音名原值非空時，direct 與 proposal 清空一律 422（四路徑對齊）。
        $this->actingAs($this->makeUser(email: 'biog-no-clear@example.com'));
        $this->seedBiogMain();

        foreach (['direct', 'proposal'] as $mode) {
            foreach ([['c_mingzi_chn' => ''], ['c_mingzi_chn' => null], ['c_mingzi' => '']] as $changes) {
                $this->postJson('/api/v2/mutate', [
                    'resource' => 'basicinformation', 'person_id' => 138841, 'mode' => $mode, 'operation' => 'update',
                    'target' => ['pk' => ['c_personid' => 138841]],
                    'changes' => $changes,
                ])->assertStatus(422);
            }
        }

        // 資料未被清空，且未產生任何 pending 提案。
        $this->assertDatabaseHas('BIOG_MAIN', ['c_personid' => 138841, 'c_mingzi_chn' => '忠', 'c_mingzi' => 'Zhong']);
        $this->assertDatabaseCount('operations', 0);
    }

    #[Test]
    public function testBiogMainUpdateAllowsKeepingMingziEmptyWhenOriginalEmpty() {
        // 「不可清空」語義的另一半：原本即為空的人物，維持空並編輯其他欄位可照常保存（direct 與 proposal）。
        $this->actingAs($this->makeUser(email: 'biog-keep-empty@example.com'));

        foreach (['direct', 'proposal'] as $mode) {
            DB::table('BIOG_MAIN')->where('c_personid', 138841)->delete();
            $this->seedBiogMain(['c_mingzi_chn' => '', 'c_mingzi' => null, 'c_name_chn' => '張', 'c_name' => 'Zhang']);

            $this->postJson('/api/v2/mutate', [
                'resource' => 'basicinformation', 'person_id' => 138841, 'mode' => $mode, 'operation' => 'update',
                'target' => ['pk' => ['c_personid' => 138841]],
                'changes' => ['c_index_year' => $mode === 'direct' ? 1101 : 1102],
                ...($mode === 'proposal' ? ['meta' => ['comment' => '僅改指數年']] : []),
            ])->assertOk();

            $row = DB::table('BIOG_MAIN')->where('c_personid', 138841)->first();
            $this->assertSame('', trim((string) $row->c_mingzi_chn), $mode.'：名（中）應維持空');
            if ($mode === 'direct') {
                $this->assertSame(1101, (int) $row->c_index_year, 'direct：其他欄位應照常寫入');
            } else {
                $this->assertDatabaseHas('operations', [
                    'resource' => 'BIOG_MAIN', 'c_personid' => 138841,
                    'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
                ]);
            }
        }
    }

    #[Test]
    public function testSessionAuthenticatedUserCanMutateAltnameSequenceViaApiV2Mutate() {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 11,
            'c_sequence' => 1,
            'c_alt_name_chn' => '子止',
            'c_alt_name' => 'Zi Zhi',
            'c_alt_name_type_code' => 4,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 11,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 11,
                    'c_alt_name_chn' => '子止',
                    'c_alt_name_type_code' => 4,
                ],
            ],
            'changes' => [
                'c_sequence' => 2,
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'altnames',
                'mode' => 'direct',
                'operation' => 'update',
            ]);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 11,
            'c_alt_name_chn' => '子止',
            'c_alt_name_type_code' => 4,
            'c_sequence' => 2,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'ALTNAME_DATA',
            'c_personid' => 11,
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testRejectsAltnameMutationWhenPersonIdDoesNotMatchTargetPk() {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 11,
            'c_sequence' => 1,
            'c_alt_name_chn' => '子止',
            'c_alt_name' => 'Zi Zhi',
            'c_alt_name_type_code' => 4,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 12,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 11,
                    'c_alt_name_chn' => '子止',
                    'c_alt_name_type_code' => 4,
                ],
            ],
            'changes' => [
                'c_sequence' => 9,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
            ]);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 11,
            'c_alt_name_chn' => '子止',
            'c_alt_name_type_code' => 4,
            'c_sequence' => 1,
        ]);
    }

    #[Test]
    public function testSourceProposalCreateWithBearerTokenAuthentication() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'token-user@example.com');
        $token = $user->createToken('api-test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v2/mutate', $this->sourceCreatePayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'sources',
                'mode' => 'proposal',
                'operation' => 'create',
                'result' => [
                    'pk' => [
                        'c_personid' => 138841,
                        'c_textid' => 99999,
                        'c_pages' => '張忠墓誌',
                    ],
                    'status' => 'proposal_created',
                ],
            ]);

        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_SOURCE_DATA',
            'c_personid' => 138841,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'BIOG_SOURCE_DATA')->where('op_type', Operation::TYPE_PROPOSAL_CREATE)->first();
        $payload = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $payload['__review_status']);
        $this->assertSame('sources', $payload['__proposal_meta']['resource_type']);
        $this->assertSame('由 API 自動提交', $payload['__proposal_meta']['comment']);
    }

    #[Test]
    public function testSourceProposalCreateDoesNotRequireChangesPersonId() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'no-change-person@example.com');
        $this->actingAs($user);

        $payload = $this->sourceCreatePayload();
        unset($payload['changes']['c_personid']);

        $response = $this->postJson('/api/v2/mutate', $payload);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'sources',
                'mode' => 'proposal',
                'operation' => 'create',
            ]);

        $operation = DB::table('operations')->where('resource', 'BIOG_SOURCE_DATA')->where('op_type', Operation::TYPE_PROPOSAL_CREATE)->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame(138841, $resourceData['c_personid']);
    }

    #[Test]
    public function testSourceProposalCreateAllowsEmptyPages() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'empty-pages@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload([
            'target' => [
                'pk' => [
                    'c_pages' => '',
                ],
            ],
            'changes' => [
                'c_pages' => '',
            ],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'result' => [
                    'pk' => [
                        'c_pages' => '',
                    ],
                    'status' => 'proposal_created',
                ],
            ]);

        $operation = DB::table('operations')->where('resource', 'BIOG_SOURCE_DATA')->where('op_type', Operation::TYPE_PROPOSAL_CREATE)->first();
        $this->assertSame('c_personid=138841&c_textid=99999&c_pages=', $operation->resource_id);
    }

    #[Test]
    public function testSourceProposalCreateDetectsLegacyNullEncodedPendingProposalForEmptyPages() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'legacy-empty-pages@example.com');
        $this->actingAs($user);

        DB::table('operations')->insert([
            'user_id' => $user->id,
            'c_personid' => 138841,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'BIOG_SOURCE_DATA',
            'resource_id' => 'c_personid=138841&c_textid=99999&c_pages=NULL',
            'resource_data' => json_encode([
                'c_personid' => 138841,
                'c_textid' => 99999,
                'c_pages' => '',
                'c_notes' => '舊提案',
                '__key_columns' => ['c_personid', 'c_textid', 'c_pages'],
                '__review_status' => 'pending',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => null,
            'crowdsourcing_status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload([
            'target' => [
                'pk' => [
                    'c_pages' => '',
                ],
            ],
            'changes' => [
                'c_pages' => '',
            ],
        ]));

        $response->assertStatus(409)
            ->assertJson([
                'ok' => false,
                'message' => '相同主鍵已有待審核提案',
            ]);
    }

    #[Test]
    public function testSourceProposalCreateRequiresAuthentication() {
        $this->seedTextCode();

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload());

        $response->assertStatus(401)
            ->assertJson([
                'ok' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    #[Test]
    public function testSourceProposalCreateRejectsDuplicateCompositePrimaryKey() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'duplicate@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
            'c_notes' => '既有資料',
            'c_main_source' => 0,
            'c_self_bio' => 0,
        ]);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload());

        $response->assertStatus(409)
            ->assertJson([
                'ok' => false,
                'message' => 'BIOG_SOURCE_DATA 記錄已存在',
            ]);
    }

    #[Test]
    public function testSourceProposalCreateTreatsSelect2ZeroTextIdAliasAsDuplicateCompositePrimaryKey() {
        $this->seedTextCode(0);
        $user = $this->makeUser(email: 'zero-text-duplicate@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 0,
            'c_pages' => '張忠墓誌',
            'c_notes' => '既有資料',
            'c_main_source' => 0,
            'c_self_bio' => 0,
        ]);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload([
            'target' => [
                'pk' => [
                    'c_textid' => -999,
                ],
            ],
            'changes' => [
                'c_textid' => -999,
            ],
        ]));

        $response->assertStatus(409)
            ->assertJson([
                'ok' => false,
                'message' => 'BIOG_SOURCE_DATA 記錄已存在',
            ]);
    }

    #[Test]
    public function testSourceProposalCreateRejectsUnknownTextId() {
        $user = $this->makeUser(email: 'invalid-text@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload([
            'target' => [
                'pk' => [
                    'c_textid' => 100001,
                ],
            ],
            'changes' => [
                'c_textid' => 100001,
            ],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'message' => '參數校驗失敗',
            ]);
    }

    #[Test]
    public function testDirectSourceCreateRequiresDirectWritePermission() {
        $this->seedTextCode();
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'crowd@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
            ]);
    }

    #[Test]
    public function testDirectSourceUpdateWritesDataAndOperationRecord() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'direct-update@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
            'c_notes' => '舊備註',
            'c_main_source' => 0,
            'c_self_bio' => 0,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                    'c_textid' => 99999,
                    'c_pages' => '張忠墓誌',
                ],
            ],
            'changes' => [
                'c_personid' => 138841,
                'c_notes' => '新備註',
                'c_main_source' => 1,
                'c_self_bio' => 0,
            ],
            'meta' => [
                'comment' => '直接更新',
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'sources',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'status' => 'updated',
                ],
            ]);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
            'c_notes' => '新備註',
            'c_main_source' => 1,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_SOURCE_DATA',
            'c_personid' => 138841,
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectSourceUpdateSupportsSelect2ZeroTextIdAliasWithoutChangesPersonId() {
        $this->seedTextCode(0);
        $user = $this->makeUser(email: 'zero-text-update@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 0,
            'c_pages' => '',
            'c_notes' => '舊備註',
            'c_main_source' => 0,
            'c_self_bio' => 0,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                    'c_textid' => -999,
                    'c_pages' => '',
                ],
            ],
            'changes' => [
                'c_notes' => '新備註',
                'c_main_source' => 1,
                'c_self_bio' => 0,
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'result' => [
                    'status' => 'updated',
                    'pk' => [
                        'c_personid' => 138841,
                        'c_textid' => 0,
                        'c_pages' => '',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 0,
            'c_pages' => '',
            'c_notes' => '新備註',
            'c_main_source' => 1,
        ]);
    }

    #[Test]
    public function testDirectSourceUpdateAcceptsNormalizedKeyAliasInChanges() {
        $this->seedTextCode(0);
        $user = $this->makeUser(email: 'zero-text-full-key@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 0,
            'c_pages' => '',
            'c_notes' => '舊備註',
            'c_main_source' => 0,
            'c_self_bio' => 0,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                    'c_textid' => 0,
                    'c_pages' => '',
                ],
            ],
            'changes' => [
                'c_personid' => 138841,
                'c_textid' => -999,
                'c_pages' => '',
                'c_notes' => '新備註',
                'c_main_source' => 1,
                'c_self_bio' => 0,
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'result' => [
                    'status' => 'updated',
                ],
            ]);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 0,
            'c_pages' => '',
            'c_notes' => '新備註',
            'c_main_source' => 1,
        ]);
    }

    #[Test]
    public function testSourceProposalUpdateCreatesPendingOperationWithoutChangingData() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'proposal-update@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
            'c_notes' => '舊備註',
            'c_main_source' => 0,
            'c_self_bio' => 0,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 138841,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                    'c_textid' => 99999,
                    'c_pages' => '張忠墓誌',
                ],
            ],
            'changes' => [
                'c_personid' => 138841,
                'c_notes' => '提案中的新備註',
                'c_main_source' => 1,
                'c_self_bio' => 0,
            ],
            'meta' => [
                'comment' => '提案更新',
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'sources',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'status' => 'proposal_updated',
                ],
            ]);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
            'c_notes' => '舊備註',
            'c_main_source' => 0,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_SOURCE_DATA',
            'c_personid' => 138841,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);
    }

    #[Test]
    public function testInvalidBearerTokenIsRejectedBeforeMutationHandling() {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token',
        ])->postJson('/api/v2/mutate', $this->sourceCreatePayload());

        $response->assertStatus(401);
        $this->assertStringContainsString('Invalid API token', $response->getContent());
    }
}
