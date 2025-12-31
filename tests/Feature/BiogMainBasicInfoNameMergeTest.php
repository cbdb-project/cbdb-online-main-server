<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\BiogMainRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 測試 BiogMain 基本資料姓名欄位合併邏輯
 *
 * 根據 edit.blade.php 和 BiogMainRepository::updateById() 的實作：
 * - 姓名(中) = 姓 + 名
 * - 姓名(拼音) = Xing + ' ' + Ming
 * - 外文全名 = 外文名 + ' ' + 外文姓（名+姓順序）
 * - 外文羅馬字轉寫姓名 = 外文羅馬字轉寫名 + ' ' + 外文羅馬字轉寫姓
 */
class BiogMainBasicInfoNameMergeTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // 使用 in-memory SQLite 數據庫
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 創建必要的測試表結構

        // 創建 operations 表（用於記錄操作歷史）
        Schema::dropIfExists('operations');
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('c_personid')->nullable();
            $table->integer('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('BIOG_MAIN');
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            // 姓名相關欄位
            $table->string('c_surname_chn', 50)->nullable();
            $table->string('c_mingzi_chn', 50)->nullable();
            $table->string('c_name_chn', 100)->nullable();
            $table->string('c_surname', 50)->nullable();
            $table->string('c_mingzi', 50)->nullable();
            $table->string('c_name', 100)->nullable();
            $table->string('c_surname_proper', 100)->nullable();
            $table->string('c_mingzi_proper', 100)->nullable();
            $table->string('c_name_proper', 200)->nullable();
            $table->string('c_surname_rm', 100)->nullable();
            $table->string('c_mingzi_rm', 100)->nullable();
            $table->string('c_name_rm', 200)->nullable();
            // 其他必要欄位
            $table->smallInteger('c_female')->default(0);
            $table->smallInteger('c_by_intercalary')->default(0);
            $table->smallInteger('c_dy_intercalary')->default(0);
            $table->string('c_created_by', 50)->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by', 50)->nullable();
            $table->timestamp('c_modified_date')->nullable();
            // BiogMain 模型不使用 Laravel timestamps
        });

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('confirmation_token', 100)->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('operations');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    /**
     * 測試所有姓名欄位都有值時的合併邏輯
     */
    #[Test]
    public function testNameMergeWithAllFieldsFilled() {
        $user = $this->createActiveUser();
        $this->actingAs($user);

        // 使用 Eloquent 模型創建測試人物（這樣才能正確觸發更新邏輯）
        \App\Models\BiogMain::create([
            'c_personid' => 2345,
            'c_surname_chn' => '王',
            'c_mingzi_chn' => '安石',
            'c_name_chn' => '王安石',
            'c_surname' => 'Wang',
            'c_mingzi' => 'Anshi',
            'c_name' => 'Wang Anshi',
            'c_surname_proper' => 'Smith',
            'c_mingzi_proper' => 'John',
            'c_name_proper' => 'John Smith',
            'c_surname_rm' => 'Wáng',
            'c_mingzi_rm' => 'Ānshí',
            'c_name_rm' => 'Ānshí Wáng',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'test',
            'c_created_date' => '20250101',
        ]);

        // 更新姓名欄位（包含必填欄位）
        $response = $this->patch('/basicinformation/2345', [
            'c_surname_chn' => '李',
            'c_mingzi_chn' => '白',  // 必填
            'c_surname' => 'Li',
            'c_mingzi' => 'Bai',  // 必填
            'c_surname_proper' => 'Johnson',
            'c_mingzi_proper' => 'Mary',
            'c_surname_rm' => 'Lǐ',
            'c_mingzi_rm' => 'Bái',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
        ]);

        // 成功更新後應重定向到編輯頁面
        $response->assertStatus(302);
        $response->assertSessionHas('flash_notification');

        // 重新查詢以獲取更新後的數據
        $person = DB::table('BIOG_MAIN')->where('c_personid', 2345)->first();

        // 姓名(中) = 姓 + 名
        $this->assertSame('李白', $person->c_name_chn);

        // 姓名(拼音) = Xing + ' ' + Ming
        $this->assertSame('Li Bai', $person->c_name);

        // 外文全名 = 外文名 + ' ' + 外文姓（名+姓順序）
        $this->assertSame('Mary Johnson', $person->c_name_proper);

        // 外文羅馬字轉寫姓名 = 外文羅馬字轉寫名 + ' ' + 外文羅馬字轉寫姓
        $this->assertSame('Bái Lǐ', $person->c_name_rm);
    }

    /**
     * 測試部分姓名欄位為空時的合併邏輯
     */
    #[Test]
    public function testNameMergeWithPartialFields() {
        $user = $this->createActiveUser();
        $this->actingAs($user);

        \App\Models\BiogMain::create([
            'c_personid' => 2346,
            'c_surname_chn' => '蘇',
            'c_mingzi_chn' => '軾',
            'c_name_chn' => '蘇軾',
            'c_surname' => 'Su',
            'c_mingzi' => 'Shi',
            'c_name' => 'Su Shi',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'test',
            'c_created_date' => '20250101',
        ]);

        // 只更新中文姓名和拼音，外文欄位留空
        $response = $this->patch('/basicinformation/2346', [
            'c_surname_chn' => '杜',
            'c_mingzi_chn' => '甫',  // 必填
            'c_surname' => 'Du',
            'c_mingzi' => 'Fu',  // 必填
            'c_surname_proper' => '',
            'c_mingzi_proper' => '',
            'c_surname_rm' => '',
            'c_mingzi_rm' => '',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('flash_notification');

        // 重新查詢以獲取更新後的數據
        $person = DB::table('BIOG_MAIN')->where('c_personid', 2346)->first();

        // 中文姓名正常合併
        $this->assertSame('杜甫', $person->c_name_chn);
        $this->assertSame('Du Fu', $person->c_name);

        // 外文欄位為空時，合併結果應該是空字串（trim 後）
        $this->assertSame('', $person->c_name_proper);
        $this->assertSame('', $person->c_name_rm);
    }

    /**
     * 測試只有名沒有姓的情況
     */
    #[Test]
    public function testNameMergeWithOnlyGivenName() {
        $user = $this->createActiveUser();
        $this->actingAs($user);

        \App\Models\BiogMain::create([
            'c_personid' => 2347,
            'c_surname_chn' => '',
            'c_mingzi_chn' => '佛陀',
            'c_name_chn' => '佛陀',
            'c_surname' => '',
            'c_mingzi' => 'Buddha',
            'c_name' => 'Buddha',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'test',
            'c_created_date' => '20250101',
        ]);

        $response = $this->patch('/basicinformation/2347', [
            'c_surname_chn' => '',
            'c_mingzi_chn' => '孔子',  // 必填
            'c_surname' => '',
            'c_mingzi' => 'Confucius',  // 必填
            'c_surname_proper' => '',
            'c_mingzi_proper' => 'Aristotle',
            'c_surname_rm' => '',
            'c_mingzi_rm' => '',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('flash_notification');

        // 重新查詢以獲取更新後的數據
        $person = DB::table('BIOG_MAIN')->where('c_personid', 2347)->first();

        // 只有名時，姓名欄位應該等於名
        $this->assertSame('孔子', $person->c_name_chn);
        $this->assertSame('Confucius', $person->c_name); // trim 後移除前導空格
        $this->assertSame('Aristotle', $person->c_name_proper); // trim 後沒有空格
    }

    /**
     * 測試提交相同值時不產生變更（無操作記錄）
     */
    #[Test]
    public function testNameMergeWithNoChanges() {
        $user = $this->createActiveUser();
        $this->actingAs($user);

        \App\Models\BiogMain::create([
            'c_personid' => 2348,
            'c_surname_chn' => '歐陽',
            'c_mingzi_chn' => '修',
            'c_name_chn' => '歐陽修',
            'c_surname' => 'Ouyang',
            'c_mingzi' => 'Xiu',
            'c_name' => 'Ouyang Xiu',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'test',
            'c_created_date' => '20250101',
        ]);

        // 記錄提交前的操作記錄數量
        $operationCountBefore = DB::table('operations')->count();

        // 提交相同的值，應該檢測到無變更
        $response = $this->patch('/basicinformation/2348', [
            'c_surname_chn' => '歐陽',
            'c_mingzi_chn' => '修',  // 必填
            'c_surname' => 'Ouyang',
            'c_mingzi' => 'Xiu',  // 必填
            'c_surname_proper' => '',
            'c_mingzi_proper' => '',
            'c_surname_rm' => '',
            'c_mingzi_rm' => '',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('flash_notification');

        // 驗證資料未變更
        $person = DB::table('BIOG_MAIN')->where('c_personid', 2348)->first();
        $this->assertSame('歐陽修', $person->c_name_chn);
        $this->assertSame('Ouyang Xiu', $person->c_name);

        // 驗證沒有產生新的操作記錄（因為資料未變更）
        $operationCountAfter = DB::table('operations')->count();
        $this->assertSame($operationCountBefore, $operationCountAfter, '提交相同值時不應產生操作記錄');
    }

    /**
     * 測試外文姓名的名+姓順序（與中文相反）
     */
    #[Test]
    public function testProperNameOrderIsGivenNameFirst() {
        $user = $this->createActiveUser();
        $this->actingAs($user);

        \App\Models\BiogMain::create([
            'c_personid' => 2349,
            'c_surname_chn' => '王',
            'c_mingzi_chn' => '五',
            'c_name_chn' => '王五',
            'c_surname' => 'Wang',
            'c_mingzi' => 'Wu',
            'c_name' => 'Wang Wu',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'test',
            'c_created_date' => '20250101',
        ]);

        // 更新為不同的值以驗證合併邏輯
        $response = $this->patch('/basicinformation/2349', [
            'c_surname_chn' => '張',
            'c_mingzi_chn' => '三',  // 必填
            'c_surname' => 'Zhang',
            'c_mingzi' => 'San',  // 必填
            'c_surname_proper' => 'Doe',
            'c_mingzi_proper' => 'Jane',
            'c_surname_rm' => 'Zhāng',
            'c_mingzi_rm' => 'Sān',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('flash_notification');

        // 重新查詢以獲取更新後的數據
        $person = DB::table('BIOG_MAIN')->where('c_personid', 2349)->first();

        // 中文：姓+名
        $this->assertSame('張三', $person->c_name_chn);
        $this->assertSame('Zhang San', $person->c_name);

        // 外文：名+姓（與中文相反）
        $this->assertSame('Jane Doe', $person->c_name_proper);
        $this->assertSame('Sān Zhāng', $person->c_name_rm);
    }

    /**
     * 測試空格處理（trim 功能）
     */
    #[Test]
    public function testNameMergeTrimsWhitespace() {
        $user = $this->createActiveUser();
        $this->actingAs($user);

        \App\Models\BiogMain::create([
            'c_personid' => 2350,
            'c_surname_chn' => '趙',
            'c_mingzi_chn' => '雲',
            'c_name_chn' => '趙雲',
            'c_surname' => 'Zhao',
            'c_mingzi' => 'Yun',
            'c_name' => 'Zhao Yun',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'test',
            'c_created_date' => '20250101',
        ]);

        // 提交帶有前後空格的值
        $response = $this->patch('/basicinformation/2350', [
            'c_surname_chn' => '趙',
            'c_mingzi_chn' => '雲',  // 必填
            'c_surname' => 'Zhao',
            'c_mingzi' => 'Yun',  // 必填
            'c_surname_proper' => '  ',  // 只有空格
            'c_mingzi_proper' => '  ',   // 只有空格
            'c_surname_rm' => '',
            'c_mingzi_rm' => '',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('flash_notification');

        // 重新查詢以獲取更新後的數據
        $person = DB::table('BIOG_MAIN')->where('c_personid', 2350)->first();

        // trim 應該移除前後空格，Laravel 的 ConvertEmptyStringsToNull 中間件會將空字串轉為 null
        $this->assertNull($person->c_name_proper);
        $this->assertNull($person->c_name_rm);
    }

    /**
     * 測試未登入用戶無法更新
     */
    #[Test]
    public function testGuestCannotUpdateNames() {
        \App\Models\BiogMain::create([
            'c_personid' => 2351,
            'c_surname_chn' => '劉',
            'c_mingzi_chn' => '備',
            'c_name_chn' => '劉備',
            'c_surname' => 'Liu',
            'c_mingzi' => 'Bei',
            'c_name' => 'Liu Bei',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'test',
            'c_created_date' => '20250101',
        ]);

        $response = $this->patch('/basicinformation/2351', [
            'c_surname_chn' => '關',
            'c_mingzi_chn' => '羽',  // 必填
            'c_surname' => 'Guan',
            'c_mingzi' => 'Yu',  // 必填
            'c_surname_proper' => '',
            'c_mingzi_proper' => '',
            'c_surname_rm' => '',
            'c_mingzi_rm' => '',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
        ]);

        // 未登入應重定向（可能到登入頁或首頁）
        $response->assertStatus(302);

        // 驗證資料未變更
        $person = DB::table('BIOG_MAIN')->where('c_personid', 2351)->first();
        $this->assertSame('劉備', $person->c_name_chn);
        $this->assertSame('Liu Bei', $person->c_name);
    }

    /**
     * 測試非活躍用戶無法更新
     */
    #[Test]
    public function testInactiveUserCannotUpdateNames() {
        $user = User::create([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => Str::random(32),
            'is_active' => 0,
        ]);
        $this->actingAs($user);

        \App\Models\BiogMain::create([
            'c_personid' => 2352,
            'c_surname_chn' => '諸葛',
            'c_mingzi_chn' => '亮',
            'c_name_chn' => '諸葛亮',
            'c_surname' => 'Zhuge',
            'c_mingzi' => 'Liang',
            'c_name' => 'Zhuge Liang',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
            'c_created_by' => 'test',
            'c_created_date' => '20250101',
        ]);

        $response = $this->patch('/basicinformation/2352', [
            'c_surname_chn' => '司馬',
            'c_mingzi_chn' => '懿',  // 必填
            'c_surname' => 'Sima',
            'c_mingzi' => 'Yi',  // 必填
            'c_surname_proper' => '',
            'c_mingzi_proper' => '',
            'c_surname_rm' => '',
            'c_mingzi_rm' => '',
            'c_female' => 0,
            'c_by_intercalary' => 0,
            'c_dy_intercalary' => 0,
        ]);

        // 非活躍用戶應被拒絕並重定向
        $response->assertStatus(302);

        // 驗證資料未變更
        $person = DB::table('BIOG_MAIN')->where('c_personid', 2352)->first();
        $this->assertSame('諸葛亮', $person->c_name_chn);
        $this->assertSame('Zhuge Liang', $person->c_name);
    }

    /**
     * 創建活躍用戶的輔助方法
     * 注意：confirmation_token 必須為 null 才表示郵箱已驗證
     * 由於 is_active 不在 $fillable 中，需要直接設置屬性
     */
    private function createActiveUser(): User {
        $user = new User([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => null,  // null 表示已驗證
        ]);
        $user->is_active = 1;  // STATUS_ACTIVE - 直接設置因為不在 fillable 中
        $user->save();

        return $user;
    }
}
