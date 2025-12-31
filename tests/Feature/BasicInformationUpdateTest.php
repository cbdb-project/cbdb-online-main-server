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
            $table->integer('c_female')->default(0);
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
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('operations');
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

        // 驗證返回有變更標記
        $this->assertFalse($result['no_changes']);

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
}
