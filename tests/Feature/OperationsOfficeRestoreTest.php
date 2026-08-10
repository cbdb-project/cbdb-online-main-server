<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POSTED_TO_OFFICE_DATA 操作復原整合測試
 *
 * 驗證：
 * - 復原更新操作（op_type=3）能正確回復 DB 資料
 * - getPreviousSnapshot 會跳過提案操作（op_type=8/9），避免「復原到修改後的值」
 */
class OperationsOfficeRestoreTest extends TestCase {
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

        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages')->nullable();
            $table->string('c_notes')->nullable();
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_ly_intercalary')->default(0);
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
            $table->primary(['c_office_id', 'c_posting_id']);
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
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

    /**
     * 當提案核准產生的 op_type=3 操作之前存在同 resource_id 的提案操作（op_type=9）時，
     * 復原應跳過提案操作，使用 resource_original 恢復到修改前的值。
     *
     * 場景：提案把 c_office_id 從 100 改為 200，核准後產生 op_type=3 操作。
     * 復原該操作應恢復 c_office_id=100，而非讀取提案的 resource_data（c_office_id=200）。
     */
    #[Test]
    public function test_restore_office_update_skips_proposal_operation(): void {
        $this->actingAsAdmin();

        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_office_id' => 200,
            'c_posting_id' => 500,
        ]);

        // DB 中的現行資料（核准後狀態：c_office_id 已改為 200）
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 1,
            'c_posting_id' => 500,
            'c_office_id' => 200,
            'c_sequence' => 0,
            'c_source' => 50,
            'c_notes' => '修改後備註',
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            'c_created_by' => 'seed',
            'c_created_date' => '2025-01-01 00:00:00',
            'c_modified_by' => 'Admin',
            'c_modified_date' => '2026-03-19 13:40:55',
        ]);

        // 先建立提案操作（op_type=9）——resource_data 存的是修改後的值
        $proposalOp = Operation::create([
            'user_id' => 100,
            'c_personid' => 1,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => $resourceId,
            'resource_data' => json_encode([
                'c_personid' => 1,
                'c_posting_id' => 500,
                'c_office_id' => 200,
                'c_sequence' => 0,
                'c_source' => 50,
                'c_notes' => '修改後備註',
                '__key_columns' => ['c_office_id', 'c_posting_id'],
                '__review_status' => 'approved',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 1,
                'c_posting_id' => 500,
                'c_office_id' => 100,
                'c_sequence' => 0,
                'c_source' => 50,
                'c_notes' => '原始備註',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now()->subMinutes(2),
            'updated_at' => Carbon::now()->subMinutes(2),
        ]);

        // 再建立核准後產生的實際更新操作（op_type=3）
        $updateOp = Operation::create([
            'user_id' => 1,
            'c_personid' => 1,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => $resourceId,
            'resource_data' => json_encode([
                'c_personid' => 1,
                'c_posting_id' => 500,
                'c_office_id' => 200,
                'c_sequence' => 0,
                'c_source' => 50,
                'c_notes' => '修改後備註',
                'c_modified_by' => 'Admin',
                'c_modified_date' => '2026-03-19 13:40:55',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 1,
                'c_posting_id' => 500,
                'c_office_id' => 100,
                'c_sequence' => 0,
                'c_source' => 50,
                'c_notes' => '原始備註',
                'c_created_by' => 'seed',
                'c_created_date' => '2025-01-01 00:00:00',
                'c_modified_by' => null,
                'c_modified_date' => null,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now()->subMinute(),
            'updated_at' => Carbon::now()->subMinute(),
        ]);

        // 執行復原
        $response = $this->post("/operations/{$updateOp->id}/restore");
        $response->assertRedirect();

        // 驗證 flash 無錯誤
        $flash = session('flash_notification', collect())->toArray();
        if (!empty($flash)) {
            $this->assertStringNotContainsString('恢復失敗', $flash[0]['message'] ?? '', '復原應成功，實際錯誤：'.($flash[0]['message'] ?? ''));
        }

        // 核心斷言：c_office_id 應恢復為 100（原始值），而非停留在 200（提案修改後的值）
        $restoredRow = DB::table('POSTED_TO_OFFICE_DATA')
            ->where('c_posting_id', 500)
            ->first();

        $this->assertNotNull($restoredRow, '復原後記錄應存在');
        $this->assertEquals(100, $restoredRow->c_office_id, 'c_office_id 應恢復為原始值 100，而非提案的 200');
        $this->assertSame('原始備註', $restoredRow->c_notes, 'c_notes 應恢復為原始值');
    }

    /**
     * 當沒有提案操作介入時，復原仍能正常使用 resource_original。
     */
    #[Test]
    public function test_restore_office_update_without_proposal_uses_resource_original(): void {
        $this->actingAsAdmin();

        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_office_id' => 300,
            'c_posting_id' => 600,
        ]);

        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 2,
            'c_posting_id' => 600,
            'c_office_id' => 300,
            'c_sequence' => 1,
            'c_source' => 0,
            'c_notes' => '新備註',
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            'c_created_by' => 'seed',
            'c_created_date' => '2025-01-01 00:00:00',
        ]);

        $operation = Operation::create([
            'user_id' => 1,
            'c_personid' => 2,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => $resourceId,
            'resource_data' => json_encode([
                'c_personid' => 2,
                'c_posting_id' => 600,
                'c_office_id' => 300,
                'c_sequence' => 1,
                'c_source' => 0,
                'c_notes' => '新備註',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 2,
                'c_posting_id' => 600,
                'c_office_id' => 300,
                'c_sequence' => 0,
                'c_source' => 0,
                'c_notes' => '舊備註',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->post("/operations/{$operation->id}/restore");
        $response->assertRedirect();

        $restoredRow = DB::table('POSTED_TO_OFFICE_DATA')
            ->where('c_posting_id', 600)
            ->first();

        $this->assertNotNull($restoredRow);
        $this->assertEquals(0, $restoredRow->c_sequence, 'c_sequence 應恢復為原始值');
        $this->assertSame('舊備註', $restoredRow->c_notes, 'c_notes 應恢復為原始值');
    }

    /**
     * 當 c_office_id 經過來回改動（10539 → 12356 → 10539），同 resource_id 下有歷史操作時，
     * 復原應使用 resource_original（c_office_id=12356），而非找到更早的同 resource_id 操作。
     *
     * 這模擬實際場景：同一個 resource_id 下有多筆歷史操作（來自 PK 來回變更），
     * getPreviousSnapshot 會找到的是「更早之前」的操作（PK 值相同但不是本次的直接前身）。
     */
    #[Test]
    public function test_restore_office_update_prefers_resource_original_over_stale_previous_operation(): void {
        $this->actingAsAdmin();

        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_office_id' => 10539,
            'c_posting_id' => 700,
        ]);

        // DB 中的現行資料
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 1,
            'c_posting_id' => 700,
            'c_office_id' => 10539,
            'c_sequence' => 0,
            'c_source' => 50,
            'c_notes' => '第三次修改的備註',
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            'c_created_by' => 'seed',
            'c_created_date' => '2025-01-01 00:00:00',
        ]);

        // 更早的操作（同 resource_id），代表 c_office_id 還是 10539 時的修改
        $earlierOp = Operation::create([
            'user_id' => 1,
            'c_personid' => 1,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => $resourceId,
            'resource_data' => json_encode([
                'c_personid' => 1,
                'c_posting_id' => 700,
                'c_office_id' => 10539,
                'c_sequence' => 0,
                'c_source' => 50,
                'c_notes' => '第一次修改的備註',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 1,
                'c_posting_id' => 700,
                'c_office_id' => 10539,
                'c_sequence' => 0,
                'c_source' => 50,
                'c_notes' => '原始備註',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now()->subMinutes(5),
            'updated_at' => Carbon::now()->subMinutes(5),
        ]);

        // 中間操作（不同 resource_id = 12356），代表 c_office_id 被改為 12356
        // 這筆不會被 getPreviousSnapshot 找到（resource_id 不同）
        Operation::create([
            'user_id' => 1,
            'c_personid' => 1,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => CompositePrimaryKey::buildStoredResourceId([
                'c_office_id' => 12356,
                'c_posting_id' => 700,
            ]),
            'resource_data' => json_encode([
                'c_personid' => 1,
                'c_posting_id' => 700,
                'c_office_id' => 12356,
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 1,
                'c_posting_id' => 700,
                'c_office_id' => 10539,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now()->subMinutes(3),
            'updated_at' => Carbon::now()->subMinutes(3),
        ]);

        // 當前操作：把 c_office_id 從 12356 改回 10539
        $currentOp = Operation::create([
            'user_id' => 1,
            'c_personid' => 1,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => $resourceId,
            'resource_data' => json_encode([
                'c_personid' => 1,
                'c_posting_id' => 700,
                'c_office_id' => 10539,
                'c_sequence' => 0,
                'c_source' => 50,
                'c_notes' => '第三次修改的備註',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 1,
                'c_posting_id' => 700,
                'c_office_id' => 12356,
                'c_sequence' => 0,
                'c_source' => 50,
                'c_notes' => '第二次修改的備註',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now()->subMinute(),
            'updated_at' => Carbon::now()->subMinute(),
        ]);

        // 執行復原
        $response = $this->post("/operations/{$currentOp->id}/restore");
        $response->assertRedirect();

        $flash = session('flash_notification', collect())->toArray();
        if (!empty($flash)) {
            $this->assertStringNotContainsString('恢復失敗', $flash[0]['message'] ?? '', '復原應成功，實際錯誤：'.($flash[0]['message'] ?? ''));
        }

        // 核心斷言：應恢復到 resource_original 的值（c_office_id=12356），
        // 而非 earlierOp 的 resource_data（c_office_id=10539）
        $restoredRow = DB::table('POSTED_TO_OFFICE_DATA')
            ->where('c_posting_id', 700)
            ->first();

        $this->assertNotNull($restoredRow, '復原後記錄應存在');
        $this->assertEquals(12356, $restoredRow->c_office_id, 'c_office_id 應恢復為 resource_original 的 12356，而非歷史操作的 10539');
        $this->assertSame('第二次修改的備註', $restoredRow->c_notes, 'c_notes 應恢復為 resource_original 的值');
    }
}
