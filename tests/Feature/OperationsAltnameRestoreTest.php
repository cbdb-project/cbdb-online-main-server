<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Services\CharVariantMapService;
use App\Support\CompositePrimaryKey;
use App\Support\VariantReplaceScope;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ALTNAME_DATA 操作復原整合測試 (#834)
 *
 * 驗證：
 * - 復原更新操作（op_type=3）能正確回復 DB 資料
 * - 復原刪除操作（op_type=4）能正確重新插入資料
 * - 復原紀錄的 resource_id 一律使用 3-key query-string 格式
 * - 歷史 4-key 格式的操作亦可正確復原
 * - getPreviousSnapshot 跨格式匹配能找到前次操作
 */
class OperationsAltnameRestoreTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->default(0);
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->longText('resource_data');
            $table->longText('resource_original')->nullable();
            $table->timestamps();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
        });

        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->nullable();
            $table->integer('c_alt_name_type_code');
            $table->string('c_alt_name')->nullable();
            $table->string('c_alt_name_chn');
            // 真實 ALTNAME_DATA 有 c_notes；異體字還原測試需要一個一般文本欄。
            $table->text('c_notes')->nullable();
            $table->primary(['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code']);
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    protected function actingAsAdmin(): User {
        $user = User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        $this->actingAs($user);

        return $user;
    }

    // -------------------------------------------------------
    // 復原更新操作（3-key 新格式）
    // -------------------------------------------------------

    #[Test]
    public function test_restore_altname_update_with_3key_format(): void {
        $this->actingAsAdmin();

        // DB 中的現行資料（更新後狀態）
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 123,
            'c_sequence' => 2,
            'c_alt_name_type_code' => 10,
            'c_alt_name' => 'New Name',
            'c_alt_name_chn' => '張三',
        ]);

        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => 123,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ]);

        $operation = Operation::create([
            'user_id' => 1,
            'c_personid' => 123,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => $resourceId,
            'resource_data' => json_encode([
                'c_personid' => 123,
                'c_sequence' => 2,
                'c_alt_name_chn' => '張三',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'New Name',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 123,
                'c_sequence' => 1,
                'c_alt_name_chn' => '張三',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'Old Name',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->post("/operations/{$operation->id}/restore");
        $response->assertRedirect();

        // 驗證 DB 已恢復至 resource_original 的值
        $row = DB::table('ALTNAME_DATA')
            ->where('c_personid', 123)
            ->where('c_alt_name_chn', '張三')
            ->where('c_alt_name_type_code', 10)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Old Name', $row->c_alt_name);
        $this->assertEquals(1, $row->c_sequence);

        // 驗證新產生的復原紀錄使用 3-key query-string 格式
        $restoreOp = Operation::where('id', '>', $operation->id)
            ->where('resource', 'ALTNAME_DATA')
            ->where('op_type', Operation::TYPE_UPDATE)
            ->first();

        $this->assertNotNull($restoreOp);
        $this->assertStringContainsString('c_personid=', $restoreOp->resource_id);
        $this->assertStringContainsString('c_alt_name_type_code=', $restoreOp->resource_id);
        $this->assertStringNotContainsString('c_sequence', $restoreOp->resource_id);
    }

    // -------------------------------------------------------
    // 復原更新操作（歷史 4-key 格式）
    // -------------------------------------------------------

    #[Test]
    public function test_restore_altname_update_with_legacy_4key_format(): void {
        $this->actingAsAdmin();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 456,
            'c_sequence' => 3,
            'c_alt_name_type_code' => 5,
            'c_alt_name' => 'Updated',
            'c_alt_name_chn' => '李四',
        ]);

        // 歷史 4-key dash format: c_personid-c_sequence-c_alt_name_chn-c_alt_name_type_code
        $operation = Operation::create([
            'user_id' => 1,
            'c_personid' => 456,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '456-1-李四-5',
            'resource_data' => json_encode([
                'c_personid' => 456,
                'c_sequence' => 3,
                'c_alt_name_chn' => '李四',
                'c_alt_name_type_code' => 5,
                'c_alt_name' => 'Updated',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 456,
                'c_sequence' => 1,
                'c_alt_name_chn' => '李四',
                'c_alt_name_type_code' => 5,
                'c_alt_name' => 'Original',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->post("/operations/{$operation->id}/restore");
        $response->assertRedirect();

        // 驗證 DB 已恢復
        $row = DB::table('ALTNAME_DATA')
            ->where('c_personid', 456)
            ->where('c_alt_name_chn', '李四')
            ->where('c_alt_name_type_code', 5)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Original', $row->c_alt_name);
        $this->assertEquals(1, $row->c_sequence);

        // 驗證新復原紀錄的 resource_id 為 3-key query-string 格式（非 4-key）
        $restoreOp = Operation::where('id', '>', $operation->id)
            ->where('resource', 'ALTNAME_DATA')
            ->first();

        $this->assertNotNull($restoreOp);
        $this->assertStringContainsString('c_personid=', $restoreOp->resource_id);
        $this->assertStringNotContainsString('c_sequence', $restoreOp->resource_id);
        // 不應保留舊的 dash 格式
        $this->assertStringNotContainsString('456-1-', $restoreOp->resource_id);
    }

    // -------------------------------------------------------
    // 復原刪除操作（重新插入）
    // -------------------------------------------------------

    #[Test]
    public function test_restore_altname_delete_reinserts_row(): void {
        $this->actingAsAdmin();

        // 不插入 DB 資料——模擬已被刪除的狀態

        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => 789,
            'c_alt_name_chn' => '王五',
            'c_alt_name_type_code' => 3,
        ]);

        $operation = Operation::create([
            'user_id' => 1,
            'c_personid' => 789,
            'op_type' => Operation::TYPE_DELETE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => $resourceId,
            'resource_data' => json_encode([
                'c_personid' => 789,
                'c_sequence' => 1,
                'c_alt_name_chn' => '王五',
                'c_alt_name_type_code' => 3,
                'c_alt_name' => 'Deleted Name',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->post("/operations/{$operation->id}/restore");
        $response->assertRedirect();

        // 驗證 DB 行已重新插入
        $row = DB::table('ALTNAME_DATA')
            ->where('c_personid', 789)
            ->where('c_alt_name_chn', '王五')
            ->where('c_alt_name_type_code', 3)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Deleted Name', $row->c_alt_name);
        $this->assertEquals(1, $row->c_sequence);

        // 驗證復原紀錄 resource_id 為 3-key
        $restoreOp = Operation::where('id', '>', $operation->id)
            ->where('resource', 'ALTNAME_DATA')
            ->first();

        $this->assertNotNull($restoreOp);
        $this->assertStringContainsString('c_personid=', $restoreOp->resource_id);
        $this->assertStringNotContainsString('c_sequence', $restoreOp->resource_id);
    }

    // -------------------------------------------------------
    // 復原操作時 key 欄位有變（c_alt_name_chn 被改回）
    // -------------------------------------------------------

    #[Test]
    public function test_restore_altname_records_correct_resource_id_after_key_change(): void {
        $this->actingAsAdmin();

        // 更新操作把 c_alt_name_chn 從 '舊名' 改成 '新名'
        // DB 現在的狀態是 '新名'
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 300,
            'c_sequence' => 1,
            'c_alt_name_type_code' => 7,
            'c_alt_name' => 'After Update',
            'c_alt_name_chn' => '新名',
        ]);

        // resource_id 反映更新後的 key（新名）
        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => 300,
            'c_alt_name_chn' => '新名',
            'c_alt_name_type_code' => 7,
        ]);

        $operation = Operation::create([
            'user_id' => 1,
            'c_personid' => 300,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => $resourceId,
            'resource_data' => json_encode([
                'c_personid' => 300,
                'c_sequence' => 1,
                'c_alt_name_chn' => '新名',
                'c_alt_name_type_code' => 7,
                'c_alt_name' => 'After Update',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 300,
                'c_sequence' => 1,
                'c_alt_name_chn' => '舊名',
                'c_alt_name_type_code' => 7,
                'c_alt_name' => 'Before Update',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->post("/operations/{$operation->id}/restore");
        $response->assertRedirect();

        // 驗證 DB 行已恢復，c_alt_name_chn 變回 '舊名'
        $row = DB::table('ALTNAME_DATA')
            ->where('c_personid', 300)
            ->where('c_alt_name_chn', '舊名')
            ->where('c_alt_name_type_code', 7)
            ->first();

        $this->assertNotNull($row, 'c_alt_name_chn 應恢復為「舊名」');
        $this->assertSame('Before Update', $row->c_alt_name);

        // 驗證復原紀錄的 resource_id 反映復原後的 key 值（舊名），而非原操作的 key（新名）
        $restoreOp = Operation::where('id', '>', $operation->id)
            ->where('resource', 'ALTNAME_DATA')
            ->first();

        $this->assertNotNull($restoreOp);
        $parsed = CompositePrimaryKey::parseStoredResourceId($restoreOp->resource_id, 'ALTNAME_DATA');
        $this->assertNotNull($parsed);
        $this->assertSame('舊名', urldecode($parsed['c_alt_name_chn'] ?? ''));
    }

    // -------------------------------------------------------
    // getPreviousSnapshot 跨格式匹配
    // -------------------------------------------------------

    #[Test]
    public function test_get_previous_snapshot_matches_across_formats(): void {
        $this->actingAsAdmin();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 500,
            'c_sequence' => 2,
            'c_alt_name_type_code' => 10,
            'c_alt_name' => 'Current',
            'c_alt_name_chn' => '趙六',
        ]);

        // 第一筆操作：4-key dash 格式
        $op1 = Operation::create([
            'user_id' => 1,
            'c_personid' => 500,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '500-1-趙六-10',
            'resource_data' => json_encode([
                'c_personid' => 500,
                'c_sequence' => 1,
                'c_alt_name_chn' => '趙六',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'Version 1',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 500,
                'c_sequence' => 0,
                'c_alt_name_chn' => '趙六',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'Version 0',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now()->subMinutes(10),
            'updated_at' => Carbon::now()->subMinutes(10),
        ]);

        // 第二筆操作：3-key query-string 格式（指向同一行）
        $resourceId3Key = CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => 500,
            'c_alt_name_chn' => '趙六',
            'c_alt_name_type_code' => 10,
        ]);

        $op2 = Operation::create([
            'user_id' => 1,
            'c_personid' => 500,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => $resourceId3Key,
            'resource_data' => json_encode([
                'c_personid' => 500,
                'c_sequence' => 2,
                'c_alt_name_chn' => '趙六',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'Current',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => null, // 刻意留空，迫使 getPreviousSnapshot 搜尋前次操作
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // 復原第二筆操作
        $response = $this->post("/operations/{$op2->id}/restore");
        $response->assertRedirect();

        // getPreviousSnapshot 應透過跨格式匹配找到 op1 的 resource_data
        // 因此恢復目標為 op1 的 resource_data（Version 1），而非空陣列
        $row = DB::table('ALTNAME_DATA')
            ->where('c_personid', 500)
            ->where('c_alt_name_chn', '趙六')
            ->where('c_alt_name_type_code', 10)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Version 1', $row->c_alt_name);
        $this->assertEquals(1, $row->c_sequence);
    }

    // -------------------------------------------------------
    // 跨格式匹配：大量不相關操作不影響定位
    // -------------------------------------------------------

    #[Test]
    public function test_cross_format_match_survives_many_unrelated_operations(): void {
        // 驗證當目標操作與前次操作之間有大量不相關 ALTNAME 操作時，
        // findPreviousAltnameOperation 仍能正確匹配（以 c_personid 縮小範圍）。
        $this->actingAsAdmin();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 600,
            'c_sequence' => 2,
            'c_alt_name_type_code' => 10,
            'c_alt_name' => 'Current',
            'c_alt_name_chn' => '目標',
        ]);

        // 前次操作（4-key dash 格式，c_personid=600）
        $op1 = Operation::create([
            'user_id' => 1,
            'c_personid' => 600,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '600-1-目標-10',
            'resource_data' => json_encode([
                'c_personid' => 600,
                'c_sequence' => 1,
                'c_alt_name_chn' => '目標',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'Version 1',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 600,
                'c_sequence' => 0,
                'c_alt_name_chn' => '目標',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'Version 0',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now()->subMinutes(20),
            'updated_at' => Carbon::now()->subMinutes(20),
        ]);

        // 插入 60 筆不相關人物的 ALTNAME 操作，模擬高編輯量
        $bulkRows = [];
        $now = Carbon::now()->subMinutes(10);
        for ($i = 0; $i < 60; $i++) {
            $bulkRows[] = [
                'user_id' => 1,
                'c_personid' => 9000 + $i, // 不同人物
                'op_type' => Operation::TYPE_UPDATE,
                'resource' => 'ALTNAME_DATA',
                'resource_id' => (9000 + $i) . '-1-名' . $i . '-1',
                'resource_data' => json_encode(['c_personid' => 9000 + $i, 'c_alt_name_chn' => '名' . $i]),
                'resource_original' => json_encode(['c_personid' => 9000 + $i, 'c_alt_name_chn' => '舊' . $i]),
                'crowdsourcing_status' => 0,
                'rate' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('operations')->insert($bulkRows);

        // 目標操作（3-key query-string 格式，c_personid=600）
        $resourceId3Key = CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => 600,
            'c_alt_name_chn' => '目標',
            'c_alt_name_type_code' => 10,
        ]);

        $op2 = Operation::create([
            'user_id' => 1,
            'c_personid' => 600,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => $resourceId3Key,
            'resource_data' => json_encode([
                'c_personid' => 600,
                'c_sequence' => 2,
                'c_alt_name_chn' => '目標',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'Current',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => null, // 留空以迫使跨格式搜尋
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->post("/operations/{$op2->id}/restore");
        $response->assertRedirect();

        // 即使中間隔了 60 筆不相關操作，仍能透過 c_personid 篩選找到 op1
        $row = DB::table('ALTNAME_DATA')
            ->where('c_personid', 600)
            ->where('c_alt_name_chn', '目標')
            ->where('c_alt_name_type_code', 10)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Version 1', $row->c_alt_name);
        $this->assertEquals(1, $row->c_sequence);
    }

    /**
     * plan S6 的負向斷言：**restore 不做內容替換**。
     *
     * 這是刻意的不對稱。restore 的語義是「把資料恢復成歷史快照當時的樣子」——若順手把
     * 快照裡的變體形歸一，那就不是還原而是改寫歷史（而且會讓「還原後再比對快照」永遠不一致）。
     * S1 只在 restore 掛了 `char_variant_map` 的**結構**驗證（assertWritable），與內容替換是
     * 兩件不同的事。
     */
    #[Test]
    public function test_restore_does_not_normalize_historical_variant_forms(): void {
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
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ]);
        CharVariantMapService::reset();
        VariantReplaceScope::reset();

        // 正向對照：先證明替換機制在本測試環境**是活的**。少了這行，若對照表沒載進來
        // （建表失敗／範圍誤判／快取殘留），下面的「原樣保留」斷言會因為機制整體死掉而
        // 假綠——負向測試必須自己證明它測的東西存在。
        $this->assertSame('清', CharVariantMapService::replaceFor('ALTNAME_DATA', 'c_notes', '淸')['text']);

        $this->actingAsAdmin();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 321,
            'c_sequence' => 2,
            'c_alt_name_type_code' => 10,
            'c_alt_name' => 'New Name',
            'c_alt_name_chn' => '張三',
        ]);

        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => 321,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ]);

        // 歷史快照的備註帶變體形「淸」——還原後必須原樣保留。
        $operation = Operation::create([
            'user_id' => 1,
            'c_personid' => 321,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => $resourceId,
            'resource_data' => json_encode([
                'c_personid' => 321, 'c_sequence' => 2, 'c_alt_name_chn' => '張三',
                'c_alt_name_type_code' => 10, 'c_alt_name' => 'New Name',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 321, 'c_sequence' => 1, 'c_alt_name_chn' => '張三',
                'c_alt_name_type_code' => 10, 'c_alt_name' => 'Old Name',
                'c_notes' => '淸代舊注',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->post("/operations/{$operation->id}/restore")->assertRedirect();

        $row = DB::table('ALTNAME_DATA')->where('c_personid', 321)->first();
        $this->assertSame('淸代舊注', $row->c_notes, 'restore 必須原樣還原歷史字形，不可順手歸一');
        $this->assertSame('Old Name', $row->c_alt_name);

        Schema::dropIfExists('char_variant_map');
    }
}
