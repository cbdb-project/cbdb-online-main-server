<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `batch_mutate` 的通知隔離：一筆的「系統改了你的輸入」通知不得出現在另一筆的回應上。
 *
 * 為什麼需要一支專門的測試：mutation handler 是容器解析的**單例**，`batch_mutate` 用同一個
 * 實例跑完整批（實測各 item 的 `spl_object_id` 相同），所以兩個通知累積器
 * （`AppliesVariantReplacement::$variantReplaced`、`NormalizesCoordinatePairs::$coordinateCleared`）
 * 都必須在每次 `handle()` 開頭重置。
 *
 * 而重置的**位置**也是承重的，這裡出過一個真實的回歸：交易外的 `tree_cycle` 422 早於異體字
 * 掛鉤，所以當 `resetVariantReplaced()` 還留在那個掛鉤旁邊時，那條 return 讀到的是**上一筆**
 * 的替換紀錄——回應會告訴使用者「你送的『淸』已正規化為『清』」，而這一筆他根本沒送那個字。
 * `OFFICE_TYPE_TREE` 同時有 `c_parent_id`（樹的上層欄）與可替換的 `c_office_type_desc_chn`，
 * 所以「改了字形又同時成環」是一個真的打得出來的請求，這條路徑不是理論上的。
 *
 * 這是這個 codebase 最在意的那一類通知 bug：回應對使用者宣告了一件他沒做過的事。
 */
class ApiV2BatchNoticeIsolationTest extends TestCase {
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
            $table->string('password')->nullable();
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

        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_variant_char', 8);
            $table->string('c_reference_char', 8);
            $table->integer('c_strict_excluded')->default(0);
            $table->string('c_notes')->nullable();
        });
        DB::table('char_variant_map')->insert([
            'c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0,
        ]);

        Schema::create('OFFICE_TYPE_TREE', function (Blueprint $table) {
            $table->string('c_office_type_node_id', 255)->primary();
            $table->string('c_office_type_desc')->nullable();
            $table->string('c_office_type_desc_chn')->nullable();
            $table->string('c_parent_id', 255)->nullable();
        });
        DB::table('OFFICE_TYPE_TREE')->insert([
            ['c_office_type_node_id' => '06', 'c_office_type_desc_chn' => '吏部', 'c_parent_id' => '06'],
            ['c_office_type_node_id' => '0601', 'c_office_type_desc_chn' => '司', 'c_parent_id' => '06'],
            ['c_office_type_node_id' => '060102', 'c_office_type_desc_chn' => '清吏司', 'c_parent_id' => '0601'],
        ]);

        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
            $table->smallInteger('c_firstyear')->nullable();
            $table->smallInteger('c_lastyear')->nullable();
            $table->string('c_admin_type')->nullable();
            $table->smallInteger('c_admin_cat_code')->default(0);
            $table->double('x_coord')->nullable();
            $table->double('y_coord')->nullable();
            $table->integer('CHGIS_PT_ID')->nullable();
            $table->longText('c_notes')->nullable();
            $table->string('c_alt_names')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function tearDown(): void {
        foreach (['ADDR_CODES', 'OFFICE_TYPE_TREE', 'char_variant_map', 'audit_log', 'operations', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function actAsEditor(string $email): void {
        $this->actingAs(User::forceCreate([
            'name' => 'batch notice tester',
            'email' => $email,
            'confirmation_token' => 'token-batch',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]));
    }

    #[Test]
    public function testATreeCycle422DoesNotCarryThePreviousItemsVariantNotice(): void {
        $this->actAsEditor('batch-variant@example.com');

        $response = $this->postJson('/api/v2/batch_mutate', [
            'resource' => 'office-type-tree',
            'mode' => 'direct',
            'operation' => 'update',
            'items' => [
                // 第一筆送變體字，會產生異體字通知
                [
                    'person_id' => 0,
                    'target' => ['pk' => ['c_office_type_node_id' => '060102']],
                    'changes' => ['c_office_type_desc_chn' => '淸吏司'],
                ],
                // 第二筆讓樹成環 → 交易外的 tree_cycle 422。它完全沒送任何漢字，
                // 所以回應裡出現異體字通知就是洩漏。
                [
                    'person_id' => 0,
                    'target' => ['pk' => ['c_office_type_node_id' => '06']],
                    'changes' => ['c_parent_id' => '060102'],
                ],
            ],
        ])->assertOk();

        $results = $response->json('results');

        $this->assertNotEmpty($results[0]['notices'] ?? [], '第一筆應該帶異體字通知');
        $this->assertSame(422, $results[1]['http_status'], '第二筆應該是 tree_cycle 422');
        $this->assertEmpty(
            $results[1]['notices'] ?? [],
            '第二筆沒有送任何漢字，卻帶著第一筆的異體字通知——累積器重置得太晚，'
            .'排在這條 return 之後了'
        );
    }

    #[Test]
    public function testACreateTreeCycle422DoesNotCarryThePreviousItemsVariantNotice(): void {
        $this->actAsEditor('batch-variant-create@example.com');

        $response = $this->postJson('/api/v2/batch_mutate', [
            'resource' => 'office-type-tree',
            'mode' => 'direct',
            'operation' => 'create',
            'items' => [
                [
                    'person_id' => 0,
                    'target' => ['pk' => ['c_office_type_node_id' => '0602']],
                    'changes' => ['c_office_type_desc_chn' => '淸吏司', 'c_parent_id' => '06'],
                ],
                // 自我引用 → 1-環，交易外就被擋下。
                [
                    'person_id' => 0,
                    'target' => ['pk' => ['c_office_type_node_id' => '0603']],
                    'changes' => ['c_office_type_desc_chn' => 'X', 'c_parent_id' => '0603'],
                ],
            ],
        ])->assertOk();

        $results = $response->json('results');

        $this->assertNotEmpty($results[0]['notices'] ?? [], '第一筆應該帶異體字通知');
        $this->assertSame(422, $results[1]['http_status']);
        $this->assertEmpty(
            $results[1]['notices'] ?? [],
            '第二筆帶著第一筆的異體字通知——create 端的累積器也重置得太晚'
        );
    }

    #[Test]
    public function testACreateDoesNotCarryThePreviousItemsCoordinateNotice(): void {
        // create 端的 `resetCoordinateCleared()` 原本沒有任何測試守著：實測拿掉它，
        // 既有套件全綠，但批次第二筆會揹著第一筆的座標通知。
        $this->actAsEditor('batch-coord-create@example.com');

        $response = $this->postJson('/api/v2/batch_mutate', [
            'resource' => 'addr-codes',
            'mode' => 'direct',
            'operation' => 'create',
            'items' => [
                [
                    'person_id' => 0,
                    'target' => ['pk' => ['c_addr_id' => 9101]],
                    'changes' => ['c_name_chn' => '甲', 'x_coord' => 0, 'y_coord' => 0],
                ],
                [
                    'person_id' => 0,
                    'target' => ['pk' => ['c_addr_id' => 9102]],
                    'changes' => ['c_name_chn' => '乙', 'x_coord' => 113.11, 'y_coord' => 40.37],
                ],
            ],
        ])->assertOk();

        $results = $response->json('results');

        $this->assertNotEmpty($results[0]['notices'] ?? [], '第一筆應該帶座標通知');
        $this->assertEmpty(
            $results[1]['notices'] ?? [],
            '第二筆送的是有效座標，卻帶著第一筆的通知——create 端的 resetCoordinateCleared() 沒生效'
        );

        // 而且第二筆的座標必須原樣保留。
        $second = DB::table('ADDR_CODES')->where('c_addr_id', 9102)->first();
        $this->assertSame(113.11, (float) $second->x_coord);
        $this->assertSame(40.37, (float) $second->y_coord);
    }
}
