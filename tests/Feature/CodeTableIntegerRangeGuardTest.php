<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 代碼表寫入的整數值域護欄（`HandlesCodeTableWrites::integerRanges()`）。
 *
 * **為什麼要另立一支測試、而且用原生 SQL 建表**：`$table->smallInteger()` 在 SQLite
 * 一律被建成 `integer`（實測 `type_name` 就是 `integer`），所以走 Schema Builder 的
 * 合成表**驗不到** smallint 的值域——`c_firstyear` 收到 40000 會被當成合法的 int32。
 * SQLite 會把建表時宣告的型別字串原樣記在 `pragma table_info` 裡，所以只有原生 DDL
 * 能讓測試環境看到與 prod 相同的 `smallint`。
 *
 * 要防的是**靜默截斷**：本專案 `config/database.php` 設 `strict => false`，MariaDB 收到
 * 超範圍的值會截斷成邊界值（smallint → 32767）並只發 warning。回應是 200、資料是錯的、
 * 而且錯成一個看起來很正常的年份——例外分類的兜底（1264）在這種部署上永遠走不到。
 */
class CodeTableIntegerRangeGuardTest extends TestCase {
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

        $this->createSupportTables();
        $this->createAddrCodesWithProdTypes();
        $this->createAddrBelongsWithProdTypes();
    }

    protected function tearDown(): void {
        foreach (['ADDR_BELONGS_DATA', 'ADDR_CODES', 'audit_log', 'operations', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    protected function createSupportTables(): void {
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
    }

    /** 型別字串逐欄比照 prod 的 `SHOW COLUMNS FROM ADDR_CODES`。 */
    protected function createAddrCodesWithProdTypes(): void {
        DB::statement('CREATE TABLE "ADDR_CODES" (
            "c_addr_id" int NOT NULL PRIMARY KEY,
            "c_name" varchar(255) NULL,
            "c_name_chn" varchar(255) NULL,
            "c_firstyear" smallint NULL,
            "c_lastyear" smallint NULL,
            "c_admin_type" varchar(255) NULL,
            "c_admin_cat_code" smallint NOT NULL DEFAULT 0,
            "x_coord" double NULL,
            "y_coord" double NULL,
            "CHGIS_PT_ID" int NULL,
            "c_notes" longtext NULL,
            "c_alt_names" varchar(255) NULL,
            "c_created_by" varchar(255) NULL,
            "c_created_date" datetime NULL,
            "c_modified_by" varchar(255) NULL,
            "c_modified_date" datetime NULL
        )');
    }

    /** 四欄複合主鍵，其中兩欄是 smallint——主鍵的值域漏驗會直接建錯鍵。 */
    protected function createAddrBelongsWithProdTypes(): void {
        DB::statement('CREATE TABLE "ADDR_BELONGS_DATA" (
            "c_addr_id" int NOT NULL,
            "c_belongs_to" int NOT NULL,
            "c_firstyear" smallint NOT NULL,
            "c_lastyear" smallint NOT NULL,
            "c_source" int NULL,
            "c_pages" varchar(255) NULL,
            "c_notes" varchar(255) NULL,
            "c_created_by" varchar(255) NULL,
            "c_created_date" datetime NULL,
            "c_modified_by" varchar(255) NULL,
            "c_modified_date" datetime NULL,
            PRIMARY KEY ("c_addr_id", "c_belongs_to", "c_firstyear", "c_lastyear")
        )');
    }

    protected function makeUser(string $email): User {
        return User::forceCreate([
            'name' => '值域測試者',
            'email' => $email,
            'confirmation_token' => 'token-range',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    #[Test]
    public function testSchemaTypesAreVisibleToTheGuard(): void {
        // 前提斷言：這支測試的價值完全建立在「SQLite 真的回報 smallint」之上。
        // 若哪天建表方式改回 Schema Builder，這裡會先紅，而不是讓下面的案例假綠。
        $types = [];
        foreach (Schema::getColumns('ADDR_CODES') as $column) {
            $types[$column['name']] = strtolower($column['type_name']);
        }

        $this->assertSame('smallint', $types['c_firstyear']);
        $this->assertSame('int', $types['CHGIS_PT_ID']);
    }

    #[Test]
    public function testOutOfRangeSmallintIsRejectedOnUpdate(): void {
        $this->actingAs($this->makeUser('range-update@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 1, 'c_name' => 'Keep', 'c_firstyear' => 960, 'c_admin_cat_code' => 0]);

        foreach ([40000, -40000, '40000'] as $value) {
            $this->postJson('/api/v2/mutate', [
                'resource' => 'addr_codes',
                'person_id' => 0,
                'mode' => 'direct',
                'operation' => 'update',
                'target' => ['pk' => ['c_addr_id' => 1]],
                'changes' => ['c_firstyear' => $value],
            ])->assertStatus(422)
                ->assertJsonFragment(['c_firstyear' => ['c_firstyear 必須在 -32768 與 32767 之間']]);
        }

        // 原值未被截斷成 32767，也沒被改
        $this->assertSame(960, (int) DB::table('ADDR_CODES')->where('c_addr_id', 1)->value('c_firstyear'));
    }

    #[Test]
    public function testOutOfRangeSmallintIsRejectedOnCreate(): void {
        $this->actingAs($this->makeUser('range-create@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => ['c_name' => 'TooBig', 'c_lastyear' => 40000],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('ADDR_CODES')->count());
    }

    #[Test]
    public function testOutOfRangeIntIsRejected(): void {
        $this->actingAs($this->makeUser('range-int@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 2, 'c_name' => 'Keep', 'c_admin_cat_code' => 0]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 2]],
            'changes' => ['CHGIS_PT_ID' => 3000000000],
        ])->assertStatus(422)
            ->assertJsonFragment(['CHGIS_PT_ID' => ['CHGIS_PT_ID 必須在 -2147483648 與 2147483647 之間']]);

        $this->assertNull(DB::table('ADDR_CODES')->where('c_addr_id', 2)->value('CHGIS_PT_ID'));
    }

    #[Test]
    public function testBoundaryValuesAreAccepted(): void {
        // 反面對照：邊界值本身是合法的，護欄不可過度收緊。
        $this->actingAs($this->makeUser('range-boundary@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 3, 'c_name' => 'Keep', 'c_admin_cat_code' => 0]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 3]],
            'changes' => ['c_firstyear' => 32767, 'c_lastyear' => -32768],
        ])->assertOk();

        $row = DB::table('ADDR_CODES')->where('c_addr_id', 3)->first();
        $this->assertSame(32767, (int) $row->c_firstyear);
        $this->assertSame(-32768, (int) $row->c_lastyear);
    }

    #[Test]
    public function testOutOfRangePrimaryKeyIsRejectedOnCreate(): void {
        // 主鍵在白名單化時就被抽出去了，不經 CodeTableFieldValidator——所以要另驗。
        // 漏掉的後果比一般欄位更糟：非 strict 的 MariaDB 會把 40000 截斷成 32767，
        // 這一列就被建在**呼叫端沒有指定的主鍵**上，回應說 40000、實際是 32767，
        // 之後照回應的鍵去改或刪一律 404。
        $this->actingAs($this->makeUser('range-pk@example.com'));
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 10, 'c_name' => 'Child', 'c_admin_cat_code' => 0],
            ['c_addr_id' => 20, 'c_name' => 'Parent', 'c_admin_cat_code' => 0],
        ]);

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-belongs-data',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 10, 'c_belongs_to' => 20, 'c_firstyear' => 40000, 'c_lastyear' => 1279]],
            'changes' => ['c_pages' => '1'],
        ])->assertStatus(422)
            ->assertJsonFragment(['target.pk.c_firstyear' => ['out_of_range:-32768..32767']]);

        $this->assertSame(0, DB::table('ADDR_BELONGS_DATA')->count());

        // ADDR_CODES 的 c_addr_id 是 int：超過 int32 同樣要擋
        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 3000000000]],
            'changes' => ['c_name' => 'TooBigId'],
        ])->assertStatus(422)
            ->assertJsonFragment(['target.pk.c_addr_id' => ['out_of_range:-2147483648..2147483647']]);

        $this->assertSame(2, DB::table('ADDR_CODES')->count());
    }

    #[Test]
    public function testPrimaryKeyStringOverflowingPhpIntIsRejected(): void {
        // `(int) '99999999999999999999'` 會**飽和**成 PHP_INT_MAX 而不是報錯，
        // 於是值域檢查會看到一個「剛好在範圍內」的數字而放行。
        $this->actingAs($this->makeUser('range-pk-overflow@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => '99999999999999999999']],
            'changes' => ['c_name' => 'Overflow'],
        ])->assertStatus(422)
            ->assertJsonFragment(['target.pk.c_addr_id' => ['numeric']]);

        $this->assertSame(0, DB::table('ADDR_CODES')->count());
    }

    #[Test]
    public function testLeadingZeroPrimaryKeyIsStillAccepted(): void {
        // 溢位判斷用字串往返比對，前導零要先歸一，否則 '007' 會被誤判成溢位。
        $this->actingAs($this->makeUser('range-pk-zeros@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => '007']],
            'changes' => ['c_name' => 'LeadingZeros'],
        ])->assertOk()->assertJson(['result' => ['pk' => ['c_addr_id' => 7]]]);

        $this->assertDatabaseHas('ADDR_CODES', ['c_addr_id' => 7, 'c_name' => 'LeadingZeros']);
    }

    #[Test]
    public function testIntegerStringOverflowingPhpIntIsRejectedForMutableField(): void {
        $this->actingAs($this->makeUser('range-overflow-field@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 30, 'c_name' => 'Keep', 'c_admin_cat_code' => 0]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 30]],
            'changes' => ['CHGIS_PT_ID' => '99999999999999999999'],
        ])->assertStatus(422)
            ->assertJsonFragment(['CHGIS_PT_ID' => ['CHGIS_PT_ID 整數值超出可表示範圍']]);

        $this->assertNull(DB::table('ADDR_CODES')->where('c_addr_id', 30)->value('CHGIS_PT_ID'));
    }

    #[Test]
    public function testFloatColumnsAreNotRangeChecked(): void {
        // 座標是 double，值域檢查只針對整數欄——不該把合法的經緯度擋掉。
        $this->actingAs($this->makeUser('range-float@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 4, 'c_name' => 'Keep', 'c_admin_cat_code' => 0]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 4]],
            'changes' => ['x_coord' => 116.397128, 'y_coord' => -39.916527],
        ])->assertOk();

        $this->assertSame(116.397128, (float) DB::table('ADDR_CODES')->where('c_addr_id', 4)->value('x_coord'));
    }
}
