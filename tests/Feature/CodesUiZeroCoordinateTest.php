<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Codes UI 表單路徑（Blade 與 React `/app/codes` 共用）的經緯度歸零守衛。
 *
 * 這條路徑是 `ADDR_CODES` 實際上最主要的人工寫入端，而它與 v2 API 有兩個結構差異，
 * 兩者都讓它比 API 那條路更容易寫進一個 `0`：
 *
 *  1. `extractFormData()` 是 `$request->all()` 去掉三個鍵——**沒有欄位白名單**，
 *     所以任意鍵（含 `x_coord` 與 `X_COORD` 兩種拼法）都會進 SET 子句；
 *  2. `CodeTableFieldValidator` **從來沒有被任何 controller 引用過**，所以
 *     `x_coord=0e0`／`x_coord=east` 原本會以字串直接進 `double` 欄，被非 strict
 *     sql_mode 的 MariaDB 靜默轉成 `0`。
 *
 * 測試跑 SQLite，型別轉換行為與 prod 的 MariaDB 不同，所以這裡斷言的是**送到資料庫層的
 * 值已經是 `null`**（或請求被擋下、資料沒動），那在兩種引擎上都一樣可觀察。
 */
class CodesUiZeroCoordinateTest extends TestCase {
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
        foreach (['ADDR_CODES', 'audit_log', 'operations', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function actAsEditor(string $email = 'codes-coord@example.com'): User {
        $user = User::forceCreate([
            'name' => 'codes coord tester',
            'email' => $email,
            'confirmation_token' => 'token-codes-coord',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
        $this->actingAs($user);

        return $user;
    }

    private function seedRow(array $overrides = []): void {
        DB::table('ADDR_CODES')->insert($overrides + [
            'c_addr_id' => 4338,
            'c_name' => 'Anding Wei',
            'c_name_chn' => '安定衛',
            'c_admin_cat_code' => 176,
            'x_coord' => null,
            'y_coord' => null,
        ]);
    }

    private function row(int $id = 4338): ?object {
        return DB::table('ADDR_CODES')->where('c_addr_id', $id)->first();
    }

    // ── 直接更新（Blade `codes.update` 與 React `app.codes.update` 共用 performUpdate）──

    #[Test]
    public function testFormUpdateStoresAZeroPairAsNull(): void {
        $this->actAsEditor();
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $this->put('/app/codes/ADDR_CODES/4338', [
            'c_name' => 'Anding Wei',
            'c_name_chn' => '安定衛',
            'c_admin_cat_code' => 176,
            'x_coord' => '0',
            'y_coord' => '0',
        ]);

        $row = $this->row();
        $this->assertNull($row->x_coord);
        $this->assertNull($row->y_coord);
    }

    #[Test]
    public function testFormUpdateStoresDecimalZerosAsNull(): void {
        $this->actAsEditor('codes-coord-dec@example.com');
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $this->put('/app/codes/ADDR_CODES/4338', [
            'c_name_chn' => '安定衛',
            'c_admin_cat_code' => 176,
            'x_coord' => '0.00000',
            'y_coord' => '0.0000',
        ]);

        $row = $this->row();
        $this->assertNull($row->x_coord);
        $this->assertNull($row->y_coord);
    }

    #[Test]
    public function testFormUpdateClearsThePartnerWhenOneAxisIsLeftBlank(): void {
        // 表單留白經全域 ConvertEmptyStringsToNull 變成 null，於是整對清空——
        // 使用者打的那個經度會被丟掉，所以必須有通知。
        $this->actAsEditor('codes-coord-blank@example.com');
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $response = $this->put('/app/codes/ADDR_CODES/4338', [
            'c_name_chn' => '安定衛',
            'c_admin_cat_code' => 176,
            'x_coord' => '105.36354',
            'y_coord' => '',
        ]);

        $row = $this->row();
        $this->assertNull($row->x_coord, '緯度留空時經度也必須一併清空');
        $this->assertNull($row->y_coord);
        $this->assertStringContainsString(
            'x_coord',
            json_encode(session()->all(), JSON_UNESCAPED_UNICODE),
            '丟掉了使用者打的經度卻沒有任何 flash 訊息'
        );
    }

    #[Test]
    public function testFormUpdateRejectsANonNumericCoordinateInsteadOfCoercingItToZero(): void {
        // 這是這條路徑獨有的洞：沒有欄位驗證層，`0e0` 會被 MariaDB 靜默轉成 0。
        $this->actAsEditor('codes-coord-bad@example.com');
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $this->put('/app/codes/ADDR_CODES/4338', [
            'c_name_chn' => '安定衛',
            'c_admin_cat_code' => 176,
            'x_coord' => '0e0',
            'y_coord' => '40.5',
        ])->assertSessionHasErrors();

        $row = $this->row();
        $this->assertSame(113.11134338, (float) $row->x_coord, '被拒絕的請求不可以動到任何資料');
        $this->assertSame(40.37184906, (float) $row->y_coord);
    }

    #[Test]
    public function testFormUpdateRejectsAWordAsACoordinate(): void {
        $this->actAsEditor('codes-coord-word@example.com');
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $this->put('/app/codes/ADDR_CODES/4338', [
            'c_name_chn' => '安定衛',
            'c_admin_cat_code' => 176,
            'x_coord' => 'east',
            'y_coord' => '40.5',
        ])->assertSessionHasErrors();

        $this->assertSame(113.11134338, (float) $this->row()->x_coord);
    }

    #[Test]
    public function testFormUpdateKeepsAValidPairUntouched(): void {
        $this->actAsEditor('codes-coord-ok@example.com');
        $this->seedRow();

        $this->put('/app/codes/ADDR_CODES/4338', [
            'c_name_chn' => '安定衛',
            'c_admin_cat_code' => 176,
            'x_coord' => '113.11134338',
            'y_coord' => '40.37184906',
        ]);

        $row = $this->row();
        $this->assertSame(113.11134338, (float) $row->x_coord);
        $this->assertSame(40.37184906, (float) $row->y_coord);
    }

    #[Test]
    public function testASecondSpellingCannotSmuggleAZeroPastTheFormPath(): void {
        // `extractFormData()` 沒有白名單，所以兩種拼法都會進 SET 子句。
        // MySQL 欄名大小寫不敏感，只檢查其中一個等於守衛被繞過。
        $this->actAsEditor('codes-coord-case@example.com');
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $this->put('/app/codes/ADDR_CODES/4338', [
            'c_name_chn' => '安定衛',
            'c_admin_cat_code' => 176,
            'x_coord' => '113.5',
            'X_COORD' => '0',
            'y_coord' => '40.3',
        ]);

        $row = (array) $this->row();
        foreach ($row as $column => $value) {
            if (stripos((string) $column, '_coord') !== false) {
                $this->assertNull($value, $column.' 應該被清空');
            }
        }
    }

    // ── 直接新增（performStore，整列模式）────────────────────

    #[Test]
    public function testFormCreateStoresAZeroPairAsNull(): void {
        $this->actAsEditor('codes-coord-create@example.com');

        $this->post('/app/codes/ADDR_CODES', [
            'c_addr_id' => '5501',
            'c_name_chn' => '失里綿衛',
            'c_admin_cat_code' => '176',
            'x_coord' => '0',
            'y_coord' => '0',
        ]);

        $row = $this->row(5501);
        $this->assertNotNull($row, '這一筆應該新增成功');
        $this->assertNull($row->x_coord);
        $this->assertNull($row->y_coord);
    }

    #[Test]
    public function testFormCreateWithOnlyOneAxisStoresNeither(): void {
        // 新增走整列模式：$data 就是要 insert 的完整列，缺席／留空的那一軸必然是 NULL，
        // 所以「只填經度」已經是半截座標。與 v2 的 create 對稱。
        $this->actAsEditor('codes-coord-create-half@example.com');

        $this->post('/app/codes/ADDR_CODES', [
            'c_addr_id' => '5502',
            'c_name_chn' => '半截',
            'c_admin_cat_code' => '176',
            'x_coord' => '105.36354',
            'y_coord' => '',
        ]);

        $row = $this->row(5502);
        $this->assertNotNull($row);
        $this->assertNull($row->x_coord, '新增不該存出 `105.36, NULL` 這種半截座標');
        $this->assertNull($row->y_coord);
    }

    #[Test]
    public function testFormCreateRejectsANonNumericCoordinate(): void {
        $this->actAsEditor('codes-coord-create-bad@example.com');

        $this->post('/app/codes/ADDR_CODES', [
            'c_addr_id' => '5503',
            'c_name_chn' => '壞座標',
            'c_admin_cat_code' => '176',
            'x_coord' => '0e0',
            'y_coord' => '40.5',
        ])->assertSessionHasErrors();

        $this->assertNull($this->row(5503), '被拒絕的新增不可以建出任何列');
    }

    // ── 提案路徑（payload 就該存歸一後的值，核准端才不需要補救）──

    #[Test]
    public function testFormProposalPayloadCarriesTheNormalizedNulls(): void {
        $this->actAsEditor('codes-coord-prop@example.com');
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $this->post('/codes/ADDR_CODES/4338/proposal', [
            'c_name_chn' => '安定衛',
            'c_admin_cat_code' => 176,
            'x_coord' => '0',
            'y_coord' => '0',
        ]);

        $op = DB::table('operations')->where('resource', 'ADDR_CODES')->first();
        if ($op === null) {
            $this->markTestSkipped('此環境沒有建立提案（路由或授權不同），改由 v2 提案測試覆蓋');
        }
        $payload = json_decode($op->resource_data, true);
        $this->assertNull($payload['x_coord'], '提案 payload 就該存歸一後的值');
        $this->assertNull($payload['y_coord']);

        // 提案階段不得動到資料。
        $this->assertSame(113.11134338, (float) $this->row()->x_coord);
    }

    // ── restore ─────────────────────────────────────────────

    #[Test]
    public function testRestoringASnapshotThatCarriesZeroDoesNotPutTheZeroBack(): void {
        // 刻意與 §1.3 對異體字的 restore 豁免不同：`0,0` 不承載資訊（讀取端判它無效），
        // 而且會主動製造錯誤答案（v1 鄰近查詢的自連接），所以還原它是重新武裝一個 bug，
        // 不是還原一個歷史值。而 restore 本來就不是位元級忠實重放——它已經會蓋掉
        // `c_modified_*`。
        $admin = User::forceCreate([
            'name' => 'restore admin',
            'email' => 'restore-coord@example.com',
            'confirmation_token' => 'token-restore-coord',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $before = (array) $this->row();
        $snapshotWithZeros = array_merge($before, ['x_coord' => 0, 'y_coord' => 0]);

        $operationId = DB::table('operations')->insertGetId([
            'user_id' => $admin->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ADDR_CODES',
            'resource_id' => 'c_addr_id=4338',
            'resource_data' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode($snapshotWithZeros, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);
        $this->post('/operations/'.$operationId.'/restore');

        $row = $this->row();
        $this->assertNull($row->x_coord, '還原一個 0,0 快照不可以把 0,0 放回資料庫');
        $this->assertNull($row->y_coord);
    }

    #[Test]
    public function testRestoringASnapshotWithANonNumericCoordinateIsRefused(): void {
        $admin = User::forceCreate([
            'name' => 'restore admin bad',
            'email' => 'restore-coord-bad@example.com',
            'confirmation_token' => 'token-restore-bad',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $before = (array) $this->row();
        $badSnapshot = array_merge($before, ['x_coord' => '0e0']);

        $operationId = DB::table('operations')->insertGetId([
            'user_id' => $admin->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ADDR_CODES',
            'resource_id' => 'c_addr_id=4338',
            'resource_data' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode($badSnapshot, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);
        $this->post('/operations/'.$operationId.'/restore');

        // 中止還原，原值毫髮無傷——絕不可以變成 0。
        $this->assertSame(113.11134338, (float) $this->row()->x_coord);
    }
}
