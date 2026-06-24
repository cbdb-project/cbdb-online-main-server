<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\BiogMainRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BasicInformationUpdateTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // 使用 in-memory SQLite 數據庫
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 創建 users 表
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->json('settings')->nullable();
            $table->string('avatar')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        // 創建 BIOG_MAIN 表（最小化結構）
        Schema::dropIfExists('BIOG_MAIN');
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_surname_chn')->nullable();
            $table->string('c_surname')->nullable();
            $table->string('c_mingzi_chn')->nullable();
            $table->string('c_mingzi')->nullable();
            $table->string('c_name_proper')->nullable();
            $table->string('c_name_rm')->nullable();
            $table->string('c_surname_proper')->nullable();
            $table->string('c_mingzi_proper')->nullable();
            $table->string('c_surname_rm')->nullable();
            $table->string('c_mingzi_rm')->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_female')->nullable()->default(0);
            $table->integer('c_by_intercalary')->default(0);
            $table->integer('c_dy_intercalary')->default(0);
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        // 創建 operations 表
        Schema::dropIfExists('operations');
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid');  // 使用 c_personid 而非 person_id
            $table->integer('op_type');
            $table->string('resource');  // 使用 resource 而非 table_name
            $table->string('resource_id');
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();  // 使用 resource_original 而非 resource_data_before
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });

        // 創建 audit_log 表（供審計記錄寫入）
        Schema::dropIfExists('audit_log');
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->char('operation_id', 26);
            $table->json('row_pk');
            $table->string('row_pk_text', 512);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    /**
     * 測試無修改時不寫入資料庫
     */
    #[Test]
    public function testNoUpdateWhenNoChanges() {
        // 創建測試用戶（活躍且非眾包）
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 模擬登入
        Auth::login($user);

        // 創建測試人物資料
        $personId = 1;
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $personId,
            'c_name_chn' => '張三',
            'c_name' => 'Zhang San',
            'c_surname_chn' => '張',
            'c_surname' => 'Zhang',
            'c_mingzi_chn' => '三',
            'c_mingzi' => 'San',
            'c_name_proper' => 'San Zhang',
            'c_name_rm' => 'San Zhang',
            'c_surname_proper' => 'Zhang',
            'c_mingzi_proper' => 'San',
            'c_surname_rm' => 'Zhang',
            'c_mingzi_rm' => 'San',
            'c_notes' => 'Test notes',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'Test User',
            'c_created_date' => '2024-01-01 00:00:00',
            'c_modified_by' => null,
            'c_modified_date' => null,
        ]);

        $originalModifiedDate = DB::table('BIOG_MAIN')
            ->where('c_personid', $personId)
            ->value('c_modified_date');

        $operationsCountBefore = DB::table('operations')->count();
        $auditLogCountBefore = DB::table('audit_log')->count();

        // 創建 Request 對象並直接調用 Repository
        $request = new Request([
            '_method' => 'PUT',
            '_token' => 'test-token',
            'c_surname_chn' => '張',
            'c_surname' => 'Zhang',
            'c_mingzi_chn' => '三',
            'c_mingzi' => 'San',
            'c_name_proper' => 'San Zhang',
            'c_surname_proper' => 'Zhang',
            'c_mingzi_proper' => 'San',
            'c_surname_rm' => 'Zhang',
            'c_mingzi_rm' => 'San',
            'c_notes' => 'Test notes',
            'c_female' => '0',
            'c_by_intercalary' => '0',
            'c_dy_intercalary' => '0',
        ]);

        $repository = new BiogMainRepository();
        $result = $repository->updateById($request, $personId);

        // 驗證返回無變更標記
        $this->assertTrue($result['no_changes']);

        // 驗證 c_modified_date 未變更
        $newModifiedDate = DB::table('BIOG_MAIN')
            ->where('c_personid', $personId)
            ->value('c_modified_date');
        $this->assertEquals($originalModifiedDate, $newModifiedDate);

        // 驗證 operations 表未新增記錄
        $operationsCountAfter = DB::table('operations')->count();
        $this->assertEquals($operationsCountBefore, $operationsCountAfter);

        // 驗證 audit_log 未新增記錄
        $auditLogCountAfter = DB::table('audit_log')->count();
        $this->assertSame($auditLogCountBefore, $auditLogCountAfter);
    }

    /**
     * 測試有修改時正常寫入
     */
    #[Test]
    public function testUpdateWhenChangesExist() {
        // 創建測試用戶（活躍且非眾包）
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 模擬登入
        Auth::login($user);

        // 創建測試人物資料
        $personId = 2;
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $personId,
            'c_name_chn' => '李四',
            'c_name' => 'Li Si',
            'c_surname_chn' => '李',
            'c_surname' => 'Li',
            'c_mingzi_chn' => '四',
            'c_mingzi' => 'Si',
            'c_name_proper' => 'Si Li',
            'c_name_rm' => 'Si Li',
            'c_surname_proper' => 'Li',
            'c_mingzi_proper' => 'Si',
            'c_surname_rm' => 'Li',
            'c_mingzi_rm' => 'Si',
            'c_notes' => 'Original notes',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'Test User',
            'c_created_date' => '2024-01-01 00:00:00',
            'c_modified_by' => null,
            'c_modified_date' => null,
        ]);

        $operationsCountBefore = DB::table('operations')->count();

        // 創建 Request 對象並直接調用 Repository
        $request = new Request([
            '_method' => 'PUT',
            '_token' => 'test-token',
            'c_surname_chn' => '李',
            'c_surname' => 'Li',
            'c_mingzi_chn' => '四',
            'c_mingzi' => 'Si',
            'c_name_proper' => 'Si Li',
            'c_surname_proper' => 'Li',
            'c_mingzi_proper' => 'Si',
            'c_surname_rm' => 'Li',
            'c_mingzi_rm' => 'Si',
            'c_notes' => 'Modified notes',  // 修改此欄位
            'c_female' => '0',
            'c_by_intercalary' => '0',
            'c_dy_intercalary' => '0',
        ]);

        $repository = new BiogMainRepository();
        $result = $repository->updateById($request, $personId);

        $this->assertFalse($result['no_changes']);

        $this->assertSame(1, DB::table('audit_log')->count());
        $row = DB::table('audit_log')->first();
        $this->assertNotNull($row);
        $this->assertSame('BIOG_MAIN', $row->table_name);
        $this->assertSame('UPDATE', $row->operation);
        $this->assertSame("c_personid={$personId}", $row->row_pk_text);
        $this->assertSame(['c_personid' => $personId], json_decode($row->row_pk, true));
        $newData = json_decode($row->new_data, true);
        $this->assertSame('李四', $newData['c_name_chn']);
        $this->assertSame('Li Si', $newData['c_name']);

        // 驗證資料庫已更新
        $updatedNotes = DB::table('BIOG_MAIN')
            ->where('c_personid', $personId)
            ->value('c_notes');
        $this->assertEquals('Modified notes', $updatedNotes);

        // 驗證 c_modified_date 已更新
        $modifiedDate = DB::table('BIOG_MAIN')
            ->where('c_personid', $personId)
            ->value('c_modified_date');
        $this->assertNotNull($modifiedDate);

        // 驗證 operations 表有新記錄
        $operationsCountAfter = DB::table('operations')->count();
        $this->assertEquals($operationsCountBefore + 1, $operationsCountAfter);

        // 驗證操作記錄的內容
        $operation = DB::table('operations')
            ->where('c_personid', $personId)
            ->where('resource', 'BIOG_MAIN')
            ->first();
        $this->assertNotNull($operation);
        $this->assertEquals(3, $operation->op_type); // 3 = 修改
    }

    /**
     * 測試 Laravel 框架欄位被正確過濾
     */
    #[Test]
    public function testFrameworkFieldsAreFilteredCorrectly() {
        // 創建測試用戶（活躍且非眾包）
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 模擬登入
        Auth::login($user);

        // 創建測試人物資料
        $personId = 3;
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $personId,
            'c_name_chn' => '王五',
            'c_name' => 'Wang Wu',
            'c_surname_chn' => '王',
            'c_surname' => 'Wang',
            'c_mingzi_chn' => '五',
            'c_mingzi' => 'Wu',
            'c_name_proper' => 'Wu Wang',
            'c_name_rm' => 'Wu Wang',
            'c_surname_proper' => 'Wang',
            'c_mingzi_proper' => 'Wu',
            'c_surname_rm' => 'Wang',
            'c_mingzi_rm' => 'Wu',
            'c_notes' => 'Test notes',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'Test User',
            'c_created_date' => '2024-01-01 00:00:00',
            'c_modified_by' => null,
            'c_modified_date' => null,
        ]);

        $operationsCountBefore = DB::table('operations')->count();

        // 創建 Request 對象，包含框架欄位（但業務資料相同）
        $request = new Request([
            '_method' => 'PUT',
            '_token' => 'different-token-value',  // 不同的 token
            '_wysihtml5_mode' => '1',  // 額外的框架欄位
            'c_surname_chn' => '王',
            'c_surname' => 'Wang',
            'c_mingzi_chn' => '五',
            'c_mingzi' => 'Wu',
            'c_name_proper' => 'Wu Wang',
            'c_surname_proper' => 'Wang',
            'c_mingzi_proper' => 'Wu',
            'c_surname_rm' => 'Wang',
            'c_mingzi_rm' => 'Wu',
            'c_notes' => 'Test notes',
            'c_female' => '0',
            'c_by_intercalary' => '0',
            'c_dy_intercalary' => '0',
        ]);

        $repository = new BiogMainRepository();
        $result = $repository->updateById($request, $personId);

        // 驗證返回無變更標記
        $this->assertTrue($result['no_changes']);

        // 驗證框架欄位不影響變更檢測
        $operationsCountAfter = DB::table('operations')->count();
        $this->assertEquals($operationsCountBefore, $operationsCountAfter);

        // 驗證 c_modified_date 未變更
        $modifiedDate = DB::table('BIOG_MAIN')
            ->where('c_personid', $personId)
            ->value('c_modified_date');
        $this->assertNull($modifiedDate);
    }

    /**
     * 測試性別欄位可更新為 NULL，且以真正的 null 寫入
     */
    #[Test]
    public function testUpdateGenderToNullPersistsAsDatabaseNull() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'null-gender@example.com',
            'password' => Hash::make('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        Auth::login($user);

        $personId = 4;
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $personId,
            'c_name_chn' => '趙六',
            'c_name' => 'Zhao Liu',
            'c_surname_chn' => '趙',
            'c_surname' => 'Zhao',
            'c_mingzi_chn' => '六',
            'c_mingzi' => 'Liu',
            'c_name_proper' => 'Liu Zhao',
            'c_name_rm' => 'Liu Zhao',
            'c_surname_proper' => 'Zhao',
            'c_mingzi_proper' => 'Liu',
            'c_surname_rm' => 'Zhao',
            'c_mingzi_rm' => 'Liu',
            'c_notes' => 'Original notes',
            'c_female' => 1,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'Test User',
            'c_created_date' => '2024-01-01 00:00:00',
            'c_modified_by' => null,
            'c_modified_date' => null,
        ]);

        $request = new Request([
            '_method' => 'PUT',
            '_token' => 'test-token',
            'c_surname_chn' => '趙',
            'c_surname' => 'Zhao',
            'c_mingzi_chn' => '六',
            'c_mingzi' => 'Liu',
            'c_name_proper' => 'Liu Zhao',
            'c_surname_proper' => 'Zhao',
            'c_mingzi_proper' => 'Liu',
            'c_surname_rm' => 'Zhao',
            'c_mingzi_rm' => 'Liu',
            'c_notes' => 'Original notes',
            'c_female' => 'NULL',
            'c_by_intercalary' => '0',
            'c_dy_intercalary' => '0',
        ]);

        $repository = new BiogMainRepository();
        $result = $repository->updateById($request, $personId);

        $this->assertFalse($result['no_changes']);
        $this->assertNull(DB::table('BIOG_MAIN')->where('c_personid', $personId)->value('c_female'));

        $operation = DB::table('operations')
            ->where('c_personid', $personId)
            ->where('resource', 'BIOG_MAIN')
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $this->assertNull(json_decode($operation->resource_data, true)['c_female']);

        $auditLog = DB::table('audit_log')->latest('id')->first();
        $this->assertNotNull($auditLog);
        $this->assertNull(json_decode($auditLog->new_data, true)['c_female']);
    }

    // ── #56 M 寫入等價（basic_info / BIOG_MAIN，含派生姓名重算）──────

    #[Test]
    #[Group('legacy-parity')] // 旧版下线時連同 legacy update（PUT /basicinformation/{id}）一併移除
    public function testBasicInfoUpdateWriteEquivalenceLegacyVsV2WithDerivedNames(): void {
        // #56 M（update，主檔派生欄）——復原-重做實驗：同一初始狀態下 ① 旧版 PUT 全表單改「中文名組件 c_mingzi_chn
        // 安石→雱」+「羅馬字名組件 c_mingzi Anshi→Pang」+ c_notes →記錄 → ② 復原初始 → ③ 新版 /api/v2/mutate 只送該三
        // 欄改動 → 對比 BIOG_MAIN 落庫列等價。核心：basic_info 的派生姓名（c_name_chn / c_name 等）由 updateById 從姓名
        // 組件「即時重算」（#61）；legacy 與 v2 direct 皆匯入同一 BiogMainRepository::updateById，本測試端到端鎖住「v2 以
        // changes 合併 original 後重算」與「legacy 全表單重算」落庫等價——c_name_chn 重算為「王雱」、c_name 重算為新值
        // 「Wang Pang」（同改一個羅馬字組件，使該斷言非恆等 seed；review S1），而未改的 *_proper/_rm 組件維持「Anshi Wang」
        // （鎖 v2 把未送組件由 original 合併保留）。
        $user = User::create([
            'name' => 'Test User', 'email' => 'biog-mupd@example.com', 'password' => Hash::make('x'),
            'confirmation_token' => 'tok', 'is_active' => 1, 'is_admin' => 1,
        ]);
        $this->actingAs($user);

        $personId = 2000;
        $seedInitial = function () use ($personId): void {
            DB::table('BIOG_MAIN')->where('c_personid', $personId)->delete();
            DB::table('BIOG_MAIN')->insert([
                'c_personid' => $personId,
                'c_name_chn' => '王安石', 'c_name' => 'Wang Anshi',
                'c_surname_chn' => '王', 'c_surname' => 'Wang',
                'c_mingzi_chn' => '安石', 'c_mingzi' => 'Anshi',
                'c_name_proper' => 'Anshi Wang', 'c_name_rm' => 'Anshi Wang',
                'c_surname_proper' => 'Wang', 'c_mingzi_proper' => 'Anshi',
                'c_surname_rm' => 'Wang', 'c_mingzi_rm' => 'Anshi',
                'c_notes' => '初始備註', 'c_female' => 0, 'c_by_intercalary' => 0, 'c_dy_intercalary' => 0,
                'c_created_by' => 'seed', 'c_created_date' => '2024-01-01 00:00:00',
                'c_modified_by' => null, 'c_modified_date' => null,
            ]);
        };
        // 比對 BIOG_MAIN 全欄，僅排除「必不同」的修改稽核欄（兩版各自寫入當下時間/當前 actor）。
        // c_created_by/c_created_date 不排除——update 不得篡改建檔欄（updateById 已 array_diff_key 掉），
        // 兩版皆應維持 seed 值，納入比對可順帶鎖住此不變量。
        $auditCols = ['c_modified_by', 'c_modified_date'];
        $pick = function ($row) use ($auditCols): ?array {
            if (!$row) {
                return null;
            }
            $a = array_diff_key((array) $row, array_flip($auditCols));
            ksort($a);

            return $a;
        };

        // ① 旧版 PUT 全表單（FormRequest 要求 c_mingzi_chn + c_mingzi）：改中文名組件 c_mingzi_chn 安石→雱、
        //    羅馬字名組件 c_mingzi Anshi→Pang、c_notes→改後。同改一個羅馬字組件，使 c_name 重算為「新值」Wang Pang，
        //    讓羅馬字派生側也走「真重算」而非恰好等於 seed（避免恆真斷言；review S1）。
        $seedInitial();
        $this->put('/basicinformation/' . $personId, [
            'action' => 'save',
            'c_surname_chn' => '王', 'c_surname' => 'Wang',
            'c_mingzi_chn' => '雱', 'c_mingzi' => 'Pang',
            'c_surname_proper' => 'Wang', 'c_mingzi_proper' => 'Anshi',
            'c_surname_rm' => 'Wang', 'c_mingzi_rm' => 'Anshi',
            'c_notes' => '改後備註', 'c_female' => '0', 'c_by_intercalary' => '0', 'c_dy_intercalary' => '0',
        ])->assertStatus(302); // legacy 成功後 redirect（真正的鎖在下方落庫斷言）
        $legacyRow = $pick(DB::table('BIOG_MAIN')->where('c_personid', $personId)->first());

        // ② 復原初始 → ③ 新版只送改動三欄（buildMergedPayload 會把未送組件由 original 合併後交同一 updateById 重算）。
        $seedInitial();
        $this->postJson('/api/v2/mutate', [
            'resource' => 'basicinformation', 'person_id' => $personId, 'mode' => 'direct', 'operation' => 'update',
            'target' => ['pk' => ['c_personid' => $personId]],
            'changes' => ['c_mingzi_chn' => '雱', 'c_mingzi' => 'Pang', 'c_notes' => '改後備註'],
        ])->assertOk()->assertJson(['ok' => true]);
        $v2Row = $pick(DB::table('BIOG_MAIN')->where('c_personid', $personId)->first());

        // 落庫列等價（排除修改稽核欄）；先確認兩版各自的派生欄都「真的重算到新值」，再比兩版一致。
        $this->assertNotNull($legacyRow, 'legacy 更新後主檔列不存在');
        $this->assertNotNull($v2Row, 'v2 更新後主檔列不存在');
        // 中文名派生：c_surname_chn 由 original 合併（未送）、c_mingzi_chn 為改動 → 重算「王雱」（鎖 v2 合併正確 + 重算）。
        $this->assertSame('王雱', $v2Row['c_name_chn'], 'v2 派生中文名 c_name_chn 應由組件重算為「王雱」');
        $this->assertSame('王雱', $legacyRow['c_name_chn'], 'legacy 派生中文名 c_name_chn 應重算為「王雱」（鎖 legacy 確有寫入，非對稱靜默失敗）');
        // 羅馬字名派生：c_surname 由 original 合併（未送）、c_mingzi 為改動 → 重算「Wang Pang」（新值，非恆等 seed）。
        $this->assertSame('Wang Pang', $v2Row['c_name'], 'v2 派生羅馬字 c_name 應由組件重算為「Wang Pang」');
        $this->assertSame('Wang Pang', $legacyRow['c_name'], 'legacy 派生羅馬字 c_name 應重算為「Wang Pang」');
        // proper/rm 組件皆未改 → 維持 seed「Anshi Wang」（鎖 v2 把未送的 *_proper/_rm 組件由 original 合併保留）。
        $this->assertSame('Anshi Wang', $v2Row['c_name_proper'], 'v2 c_name_proper 組件未變應維持「Anshi Wang」（未送組件由 original 合併）');
        $this->assertSame('改後備註', $v2Row['c_notes'], 'v2 c_notes 應更新為改後備註');
        $this->assertSame('改後備註', $legacyRow['c_notes'], 'legacy c_notes 應更新為改後備註（鎖 legacy 確有寫入）');
        $this->assertSame('雱', $v2Row['c_mingzi_chn'], 'v2 c_mingzi_chn 應更新為雱');
        $this->assertSame($legacyRow, $v2Row, 'BIOG_MAIN 落庫列 legacy vs v2 更新結果不等價（含派生姓名重算 + 未送組件合併）');
    }
}
