<?php

namespace Tests\Feature;

use App\Repositories\BiogMainRepository;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 測試：修改官名（c_office_id）時地名記錄是否會丟失
 *
 * 問題描述：
 * 當用戶編輯任官記錄並修改官名（c_office_id）時，如果不修改地名列表，
 * 原有的地名記錄（POSTED_TO_ADDR_DATA）會被刪除但不會被重新插入。
 *
 * 根本原因：
 * 1. POSTED_TO_ADDR_DATA 的主鍵包含 c_office_id
 * 2. 當 c_office_id 改變時，代碼會刪除舊 office_id 的所有地址記錄（第 669-676 行）
 * 3. 但是差異比對邏輯（第 687-688 行）計算出的 newHave_diff 為空
 *    （因為 addressesForInsert 與 beforeAddressesForUpdate 內容相同）
 * 4. 因此不會執行任何插入操作（第 703-716 行）
 * 5. 結果：地址被刪除但沒有在新 office_id 下重新插入
 */
class OfficeIdChangeAddressLossTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required for this test.');
        }

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        // 創建 POSTED_TO_OFFICE_DATA 表
        // 複合主鍵：(c_office_id, c_posting_id)
        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_ly_intercalary')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
            $table->primary(['c_office_id', 'c_posting_id']);
        });

        // 創建 POSTED_TO_ADDR_DATA 表
        // 複合主鍵：(c_addr_id, c_office_id, c_posting_id)
        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id');
            $table->integer('c_addr_id');
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
            $table->primary(['c_addr_id', 'c_office_id', 'c_posting_id']);
        });

        // 創建 POSTING_DATA 表
        Schema::create('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id')->primary();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        // 創建 operations 表
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->integer('c_personid')->nullable();
            $table->tinyInteger('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->timestamps();
            $table->tinyInteger('crowdsourcing_status')->default(0);
            $table->tinyInteger('rate')->default(0);
        });

        Auth::guard()->setUser(new GenericUser(['id' => 1, 'name' => 'Testing Admin']));
    }

    /**
     * 測試：修改官名時地名記錄應該被保留
     *
     * 復現步驟：
     * 1. 創建一條任官記錄（c_office_id = 412）並關聯一個地名
     * 2. 用戶修改官名（c_office_id 從 412 改為 500），不修改地名
     * 3. 預期：地名記錄應該遷移到新的 c_office_id = 500
     * 4. 實際（目前的 bug）：地名記錄丟失
     */
    #[Test]
    public function testChangingOfficeIdShouldPreserveAddresses(): void {
        // 設置測試數據：人物 27706 的任官記錄
        $personId = 27706;
        $postingId = 18534;
        $oldOfficeId = 412;
        $newOfficeId = 500;
        $addrId = 100;  // 地名 ID

        // 創建 POSTING_DATA 記錄
        DB::table('POSTING_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 創建任官記錄
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => $personId,
            'c_office_id' => $oldOfficeId,
            'c_posting_id' => $postingId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        // 創建地名記錄
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_office_id' => $oldOfficeId,
            'c_addr_id' => $addrId,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 驗證初始狀態：地名記錄存在
        $initialAddresses = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->get();
        $this->assertCount(1, $initialAddresses, '初始狀態應該有 1 條地名記錄');

        // 用戶修改官名（c_office_id 從 412 改為 500），不修改地名
        // 注意：不傳入 c_addr 參數，模擬用戶只修改官名
        $request = new Request([
            '_id' => $personId,
            '_postingid' => $postingId,
            '_officeid' => $oldOfficeId,  // 原始的 office_id
            'c_office_id' => $newOfficeId, // 新的 office_id
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            // 注意：沒有 c_addr 參數，表示用戶沒有修改地名列表
        ]);

        $repository = new BiogMainRepository();
        $repository->officeUpdateById($request, $postingId, $personId);

        // 驗證：任官記錄的 c_office_id 已更新
        $office = DB::table('POSTED_TO_OFFICE_DATA')
            ->where('c_posting_id', $postingId)
            ->first();
        $this->assertEquals($newOfficeId, $office->c_office_id, '任官記錄的 c_office_id 應該更新為新值');

        // 驗證：地名記錄應該遷移到新的 office_id
        $newAddresses = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->where('c_office_id', $newOfficeId)
            ->get();

        // 這是預期的行為：地名記錄應該存在於新的 office_id 下
        $this->assertCount(
            1,
            $newAddresses,
            '地名記錄應該遷移到新的 c_office_id 下，但實際上丟失了'
        );

        // 驗證舊的 office_id 下沒有地名記錄
        $oldAddresses = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->where('c_office_id', $oldOfficeId)
            ->get();
        $this->assertCount(0, $oldAddresses, '舊的 c_office_id 下不應該有地名記錄');

        // 驗證地名 ID 保持不變
        if ($newAddresses->count() > 0) {
            $this->assertEquals($addrId, $newAddresses->first()->c_addr_id, '地名 ID 應該保持不變');
        }
    }

    /**
     * 測試：修改官名時同時有多個地名記錄
     */
    #[Test]
    public function testChangingOfficeIdWithMultipleAddresses(): void {
        $personId = 27707;
        $postingId = 18535;
        $oldOfficeId = 412;
        $newOfficeId = 600;

        // 創建 POSTING_DATA 記錄
        DB::table('POSTING_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 創建任官記錄
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => $personId,
            'c_office_id' => $oldOfficeId,
            'c_posting_id' => $postingId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        // 創建多個地名記錄
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            [
                'c_personid' => $personId,
                'c_posting_id' => $postingId,
                'c_office_id' => $oldOfficeId,
                'c_addr_id' => 101,
                'c_created_by' => 'Seeder',
                'c_created_date' => '2023-01-01 00:00:00',
            ],
            [
                'c_personid' => $personId,
                'c_posting_id' => $postingId,
                'c_office_id' => $oldOfficeId,
                'c_addr_id' => 102,
                'c_created_by' => 'Seeder',
                'c_created_date' => '2023-01-01 00:00:00',
            ],
            [
                'c_personid' => $personId,
                'c_posting_id' => $postingId,
                'c_office_id' => $oldOfficeId,
                'c_addr_id' => 103,
                'c_created_by' => 'Seeder',
                'c_created_date' => '2023-01-01 00:00:00',
            ],
        ]);

        // 驗證初始狀態
        $initialAddresses = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->get();
        $this->assertCount(3, $initialAddresses, '初始狀態應該有 3 條地名記錄');

        // 用戶修改官名，不修改地名
        $request = new Request([
            '_id' => $personId,
            '_postingid' => $postingId,
            '_officeid' => $oldOfficeId,
            'c_office_id' => $newOfficeId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        $repository = new BiogMainRepository();
        $repository->officeUpdateById($request, $postingId, $personId);

        // 驗證：所有地名記錄應該遷移到新的 office_id
        $newAddresses = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->where('c_office_id', $newOfficeId)
            ->pluck('c_addr_id')
            ->sort()
            ->values()
            ->all();

        $this->assertEquals(
            [101, 102, 103],
            $newAddresses,
            '所有地名記錄應該遷移到新的 c_office_id 下'
        );
    }

    /**
     * 測試：修改官名的同時也修改地名列表
     *
     * 這種情況下用戶明確傳入 c_addr 參數，應該按用戶意願處理
     */
    #[Test]
    public function testChangingOfficeIdWithExplicitAddressModification(): void {
        $personId = 27708;
        $postingId = 18536;
        $oldOfficeId = 412;
        $newOfficeId = 700;

        // 創建 POSTING_DATA 記錄
        DB::table('POSTING_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 創建任官記錄
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => $personId,
            'c_office_id' => $oldOfficeId,
            'c_posting_id' => $postingId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        // 創建原有地名記錄
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_office_id' => $oldOfficeId,
            'c_addr_id' => 200,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 用戶修改官名，同時修改地名列表
        $request = new Request([
            '_id' => $personId,
            '_postingid' => $postingId,
            '_officeid' => $oldOfficeId,
            'c_office_id' => $newOfficeId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr' => [201, 202],  // 用戶明確指定新的地名列表
        ]);

        $repository = new BiogMainRepository();
        $repository->officeUpdateById($request, $postingId, $personId);

        // 驗證：新的地名記錄應該存在於新的 office_id 下
        $newAddresses = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->where('c_office_id', $newOfficeId)
            ->pluck('c_addr_id')
            ->sort()
            ->values()
            ->all();

        $this->assertEquals(
            [201, 202],
            $newAddresses,
            '用戶明確指定的地名列表應該存在於新的 c_office_id 下'
        );
    }

    /**
     * 測試：修改官名時目標 office_id 已存在相同地址記錄（主鍵衝突場景）
     *
     * 這是一個邊界情況：如果目標 office_id 下已經存在相同的 addr_id，
     * UPDATE 會導致主鍵衝突。代碼應該拋出異常，提醒用戶存在數據衝突，
     * 而不是自動刪除可能正確的數據。
     */
    #[Test]
    public function testChangingOfficeIdWithExistingTargetAddressRecordsThrowsException(): void {
        $personId = 27709;
        $postingId = 18537;
        $oldOfficeId = 412;
        $newOfficeId = 800;

        // 創建 POSTING_DATA 記錄
        DB::table('POSTING_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 創建任官記錄
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => $personId,
            'c_office_id' => $oldOfficeId,
            'c_posting_id' => $postingId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        // 創建源 office_id 的地址記錄
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_office_id' => $oldOfficeId,
            'c_addr_id' => 301,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 模擬目標 office_id 下已存在相同 addr_id 的記錄（這是異常數據，但可能存在）
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_office_id' => $newOfficeId,
            'c_addr_id' => 301,  // 與源記錄相同的 addr_id
            'c_created_by' => 'Old Record',
            'c_created_date' => '2022-01-01 00:00:00',
        ]);

        // 用戶修改官名，不修改地址
        $request = new Request([
            '_id' => $personId,
            '_postingid' => $postingId,
            '_officeid' => $oldOfficeId,
            'c_office_id' => $newOfficeId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        $repository = new BiogMainRepository();

        // 應該拋出 ValidationException，提醒用戶存在數據衝突
        $this->expectException(ValidationException::class);

        $repository->officeUpdateById($request, $postingId, $personId);
    }

    /**
     * 測試：修改官名時，用戶可以通過移除衝突地址來解決問題
     *
     * 這是「改官名 + 移除衝突地址」的情境：
     * - 目標 office_id 下已存在某個地址（301）
     * - 用戶修改官名時，同時從地址列表中移除該衝突地址
     * - 操作應該成功，因為衝突地址不在「將保留」的列表中
     */
    #[Test]
    public function testChangingOfficeIdWithRemovingConflictingAddress(): void {
        $personId = 27710;
        $postingId = 18538;
        $oldOfficeId = 412;
        $newOfficeId = 900;

        // 創建 POSTING_DATA 記錄
        DB::table('POSTING_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 創建任官記錄
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => $personId,
            'c_office_id' => $oldOfficeId,
            'c_posting_id' => $postingId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        // 創建源 office_id 的地址記錄（包含衝突地址 301 和非衝突地址 302）
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            [
                'c_personid' => $personId,
                'c_posting_id' => $postingId,
                'c_office_id' => $oldOfficeId,
                'c_addr_id' => 301,  // 這個地址在目標 office_id 下也存在（衝突）
                'c_created_by' => 'Seeder',
                'c_created_date' => '2023-01-01 00:00:00',
            ],
            [
                'c_personid' => $personId,
                'c_posting_id' => $postingId,
                'c_office_id' => $oldOfficeId,
                'c_addr_id' => 302,  // 這個地址不衝突
                'c_created_by' => 'Seeder',
                'c_created_date' => '2023-01-01 00:00:00',
            ],
        ]);

        // 模擬目標 office_id 下已存在衝突地址
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_office_id' => $newOfficeId,
            'c_addr_id' => 301,  // 衝突地址
            'c_created_by' => 'Old Record',
            'c_created_date' => '2022-01-01 00:00:00',
        ]);

        // 用戶修改官名，同時移除衝突地址（只保留 302）
        $request = new Request([
            '_id' => $personId,
            '_postingid' => $postingId,
            '_officeid' => $oldOfficeId,
            'c_office_id' => $newOfficeId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr' => [302],  // 只保留非衝突地址
        ]);

        $repository = new BiogMainRepository();
        // 這裡不應該拋出異常，因為用戶已經移除了衝突地址
        $repository->officeUpdateById($request, $postingId, $personId);

        // 驗證：新的 office_id 下應該有遷移過來的 302
        // 注意：原有的 301 是另一條獨立的記錄，這裡不做斷言
        // 關鍵是操作成功完成，沒有拋出異常
        $migratedAddress = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->where('c_office_id', $newOfficeId)
            ->where('c_addr_id', 302)
            ->first();

        $this->assertNotNull($migratedAddress, '地址 302 應該成功遷移到新的 office_id');

        // 驗證：舊的 office_id 下的 301 應該被刪除（因為用戶移除了它）
        $removedAddress = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->where('c_office_id', $oldOfficeId)
            ->where('c_addr_id', 301)
            ->first();

        $this->assertNull($removedAddress, '被用戶移除的地址 301 應該從舊的 office_id 下刪除');
    }

    /**
     * 測試：清除所有地址（不修改官名）
     *
     * 問題描述：
     * 當用戶刪除所有地址時，HTML 多選框不會傳送 c_addr 參數，
     * 導致 $incomingAddr 為 null，被誤判為「無修改」。
     *
     * 預期：用戶清除所有地址後，系統應該刪除所有地名記錄。
     */
    #[Test]
    public function testClearingAllAddresses(): void {
        $personId = 27711;
        $postingId = 18539;
        $officeId = 412;

        // 創建 POSTING_DATA 記錄
        DB::table('POSTING_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 創建任官記錄
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => $personId,
            'c_office_id' => $officeId,
            'c_posting_id' => $postingId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        // 創建多個地址記錄
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            [
                'c_personid' => $personId,
                'c_posting_id' => $postingId,
                'c_office_id' => $officeId,
                'c_addr_id' => 401,
                'c_created_by' => 'Seeder',
                'c_created_date' => '2023-01-01 00:00:00',
            ],
            [
                'c_personid' => $personId,
                'c_posting_id' => $postingId,
                'c_office_id' => $officeId,
                'c_addr_id' => 402,
                'c_created_by' => 'Seeder',
                'c_created_date' => '2023-01-01 00:00:00',
            ],
        ]);

        // 驗證初始狀態
        $initialCount = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->count();
        $this->assertEquals(2, $initialCount, '初始狀態應該有 2 條地名記錄');

        // 用戶清除所有地址（使用 c_addr_cleared 標記，模擬實際 UI 行為）
        // 當 HTML 多選框無選項時不會傳送 c_addr，但前端 JavaScript 會設置 c_addr_cleared='1'
        $request = new Request([
            '_id' => $personId,
            '_postingid' => $postingId,
            '_officeid' => $officeId,
            'c_office_id' => $officeId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr_cleared' => '1',  // 前端設置的標記，表示用戶清除了所有地址
            // 注意：沒有 c_addr 參數（模擬 HTML multi-select 空白時的行為）
        ]);

        $repository = new BiogMainRepository();
        $result = $repository->officeUpdateById($request, $postingId, $personId);

        // 應該不返回 "no_changes"
        $this->assertFalse(
            $result['no_changes'] ?? false,
            '清除地址應該被視為有變更'
        );

        // 驗證：所有地址記錄應該被刪除
        $finalCount = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->count();
        $this->assertEquals(0, $finalCount, '所有地名記錄應該被刪除');
    }

    /**
     * 測試：-999 應該正規化為 0（「未詳」地址）
     *
     * 問題描述：
     * -999 是表單中「未選擇」的 sentinel 值，代表「未詳」地址（c_addr_id = 0）。
     * 衝突檢測和遷移邏輯應該將 -999 正規化為 0。
     */
    #[Test]
    public function testNegative999ShouldBeNormalizedToZero(): void {
        $personId = 27712;
        $postingId = 18540;
        $oldOfficeId = 412;
        $newOfficeId = 950;

        // 創建 POSTING_DATA 記錄
        DB::table('POSTING_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 創建任官記錄
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => $personId,
            'c_office_id' => $oldOfficeId,
            'c_posting_id' => $postingId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        // 創建「未詳」地址記錄（c_addr_id = 0）
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_office_id' => $oldOfficeId,
            'c_addr_id' => 0,  // 「未詳」地址
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 用戶修改官名，同時傳入 -999（代表「未詳」）
        $request = new Request([
            '_id' => $personId,
            '_postingid' => $postingId,
            '_officeid' => $oldOfficeId,
            'c_office_id' => $newOfficeId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr' => [-999],  // -999 應該等同於 0
        ]);

        $repository = new BiogMainRepository();
        $repository->officeUpdateById($request, $postingId, $personId);

        // 驗證：「未詳」地址記錄應該遷移到新的 office_id
        $migratedAddress = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->where('c_office_id', $newOfficeId)
            ->where('c_addr_id', 0)  // 注意：是 0，不是 -999
            ->first();

        $this->assertNotNull($migratedAddress, '「未詳」地址（c_addr_id=0）應該成功遷移到新的 office_id');

        // 驗證舊的 office_id 下沒有地址記錄
        $oldCount = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->where('c_office_id', $oldOfficeId)
            ->count();

        $this->assertEquals(0, $oldCount, '舊的 office_id 下不應該有地址記錄');
    }

    /**
     * 測試：-999 衝突檢測應該正確比對 c_addr_id = 0
     *
     * 當目標 office_id 下已存在 c_addr_id = 0 的記錄，
     * 而用戶傳入 -999（代表「未詳」），應該檢測到衝突。
     */
    #[Test]
    public function testNegative999ConflictDetectionWithZero(): void {
        $personId = 27713;
        $postingId = 18541;
        $oldOfficeId = 412;
        $newOfficeId = 960;

        // 創建 POSTING_DATA 記錄
        DB::table('POSTING_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 創建任官記錄
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => $personId,
            'c_office_id' => $oldOfficeId,
            'c_posting_id' => $postingId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        // 創建源 office_id 的「未詳」地址記錄
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_office_id' => $oldOfficeId,
            'c_addr_id' => 0,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        // 目標 office_id 下已存在「未詳」地址記錄（c_addr_id = 0）
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => $personId,
            'c_posting_id' => $postingId,
            'c_office_id' => $newOfficeId,
            'c_addr_id' => 0,  // 衝突地址
            'c_created_by' => 'Old Record',
            'c_created_date' => '2022-01-01 00:00:00',
        ]);

        // 用戶修改官名，傳入 -999（代表「未詳」）
        $request = new Request([
            '_id' => $personId,
            '_postingid' => $postingId,
            '_officeid' => $oldOfficeId,
            'c_office_id' => $newOfficeId,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr' => [-999],  // -999 應該等同於 0，應該檢測到衝突
        ]);

        $repository = new BiogMainRepository();

        // 應該拋出 ValidationException，因為 -999 正規化後為 0，與目標 office_id 下的記錄衝突
        $this->expectException(ValidationException::class);

        $repository->officeUpdateById($request, $postingId, $personId);
    }
}
