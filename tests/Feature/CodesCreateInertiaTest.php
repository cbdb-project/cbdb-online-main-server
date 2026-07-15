<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P2-3 codes/create Inertia 變體（app.codes.create/store/propose）測試。
 * 使用獨立表名 TEST_CREATE_CODES，避免與 CodesControllerTest 的 getKeyColumns
 * 靜態快取（以表名為鍵）在全套執行時互相污染。
 */
class CodesCreateInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-codes-create-inertia';
        if (!is_dir($compiledViewPath)) {
            mkdir($compiledViewPath, 0777, true);
        }
        config(['view.compiled' => $compiledViewPath]);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config(['codes.tables' => ['TEST_CREATE_CODES' => '測試代碼', 'ADDR_CODES' => '地址代碼', 'TEST_AUTOINC_CODES' => '自增測試']]);

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('confirmation_token')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_admin')->default(0);
            $table->timestamps();
        });

        Schema::create('TEST_CREATE_CODES', function ($table) {
            $table->integer('code_id')->primary();
            $table->string('description')->nullable();
        });

        Schema::create('ADDR_CODES', function ($table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
        });

        // auto_increment 主鍵 + (c_chn, c_lastname) 複合唯一鍵，模擬 pinyin 表結構，
        // 用於驗證「新增頁不帶明確 auto_increment id」的假性主鍵衝突修復。
        Schema::create('TEST_AUTOINC_CODES', function ($table) {
            $table->increments('id');
            $table->string('c_chn', 10);
            $table->string('c_pinyin', 30)->nullable();
            $table->tinyInteger('c_lastname')->default(0);
            $table->unique(['c_chn', 'c_lastname'], 'test_autoinc_chn_lastname_unique');
        });

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('c_personid')->default(0);
            $table->string('resource')->nullable();
            $table->text('resource_id')->nullable();
            $table->string('op_type')->nullable();
            $table->longText('resource_data')->nullable();
            $table->longText('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });
    }

    private function activeUser(int $role = User::ROLE_SUPER_ADMIN): User {
        return User::create([
            'name' => 'U', 'email' => 'u@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => $role,
        ]);
    }

    #[Test]
    public function create_renders_form_with_columns(): void {
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.create', ['table_name' => 'TEST_CREATE_CODES']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Create')
                ->where('table', 'TEST_CREATE_CODES')
                ->has('columns')
                ->where('can_propose', true)
                ->has('urls.store')
                ->has('urls.propose')
                ->where('tier2_fields', [])); // 非 Phase B 表：無 Tier 2 欄
    }

    #[Test]
    public function create_passes_tier2_fields_for_config_table(): void {
        // §D-6：ADDR_CODES.c_name 為 Tier 2 → 前端據此於保存時偵測 v→ü 並彈窗
        $this->actingAs($this->activeUser())
            ->get('/app/codes/ADDR_CODES/create')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Create')
                ->where('table', 'ADDR_CODES')
                ->where('tier2_fields', ['c_name']));
    }

    #[Test]
    public function store_inserts_row_and_redirects(): void {
        $this->actingAs($this->activeUser())
            ->post(route('app.codes.store', ['table_name' => 'TEST_CREATE_CODES']), [
                'code_id' => 42,
                'description' => 'answer',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('TEST_CREATE_CODES', ['code_id' => 42, 'description' => 'answer']);
    }

    #[Test]
    public function store_missing_primary_key_redirects_back_with_errors(): void {
        $this->actingAs($this->activeUser())
            ->from(route('app.codes.create', ['table_name' => 'TEST_CREATE_CODES']))
            ->post(route('app.codes.store', ['table_name' => 'TEST_CREATE_CODES']), ['description' => 'no key'])
            ->assertRedirect()
            ->assertSessionHasErrors();
    }

    #[Test]
    public function store_blank_not_null_with_default_uses_db_default_not_false_duplicate(): void {
        // 復現回報情境：使用者留白 c_lastname（NOT NULL，預設 0）。空字串經
        // ConvertEmptyStringsToNull 會變 null，若直接寫入會觸發 NOT NULL 違規（SQLSTATE 23000），
        // 舊 isDuplicateKeyException 又把整個 23000 誤判為重複 → 顯示假的「主鍵或唯一值已存在」。
        // 修復後：留白欄退回資料庫預設值 0，順利新增，且無任何錯誤。
        // 新增表單會預填主鍵 id（max+1），故 request 帶明確 id，比照真實表單送出。
        $this->actingAs($this->activeUser())
            ->from(route('app.codes.create', ['table_name' => 'TEST_AUTOINC_CODES']))
            ->post(route('app.codes.store', ['table_name' => 'TEST_AUTOINC_CODES']), [
                'id' => 100,
                'c_chn' => '菴',
                'c_pinyin' => 'an',
                'c_lastname' => '', // 留白 → null → 套用預設 0
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = DB::table('TEST_AUTOINC_CODES')->where('c_chn', '菴')->first();
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->c_lastname);
    }

    #[Test]
    public function store_still_rejects_genuine_unique_duplicate(): void {
        // 真正已存在的 (c_chn, c_lastname) 唯一鍵衝突仍須被擋下（不可誤放）。
        DB::table('TEST_AUTOINC_CODES')->insert(['id' => 1, 'c_chn' => '菴', 'c_pinyin' => 'an', 'c_lastname' => 0]);

        // PK id=2 為未使用，衝突純粹來自 (c_chn, c_lastname) 唯一鍵。
        $this->actingAs($this->activeUser())
            ->from(route('app.codes.create', ['table_name' => 'TEST_AUTOINC_CODES']))
            ->post(route('app.codes.store', ['table_name' => 'TEST_AUTOINC_CODES']), [
                'id' => 2,
                'c_chn' => '菴',
                'c_pinyin' => 'an',
                'c_lastname' => '', // 空 → 0，與既有列同鍵
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('duplicate');

        $this->assertSame(1, DB::table('TEST_AUTOINC_CODES')->where('c_chn', '菴')->count());
    }

    #[Test]
    public function update_blank_not_null_with_default_keeps_value_without_500(): void {
        // 編輯時留白 c_lastname（NOT NULL，有預設值）。修復前：null 寫入觸發 23000 →
        // 收窄 isDuplicateKeyException 後會直接 500。修復後：留白等於保留原值，順利更新其他欄位。
        DB::table('TEST_AUTOINC_CODES')->insert(['id' => 5, 'c_chn' => '菴', 'c_pinyin' => 'an', 'c_lastname' => 1]);

        $this->actingAs($this->activeUser())
            ->from(route('app.codes.edit', ['table_name' => 'TEST_AUTOINC_CODES', 'id' => 5]))
            ->patch(route('app.codes.update', ['table_name' => 'TEST_AUTOINC_CODES', 'id' => 5]), [
                'c_chn' => '菴',
                'c_pinyin' => 'AN',
                'c_lastname' => '', // 留白 → null → 保留原值 1
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = DB::table('TEST_AUTOINC_CODES')->where('id', 5)->first();
        $this->assertSame(1, (int) $row->c_lastname); // 原值保留
        $this->assertSame('AN', $row->c_pinyin);      // 其他欄位照常更新
    }

    #[Test]
    public function update_blank_not_null_without_default_reports_integrity_not_false_duplicate(): void {
        // 編輯時清空 c_chn（NOT NULL 且無預設值）：應得到誠實的完整性錯誤（非假「主鍵或唯一值已存在」、非 500）。
        DB::table('TEST_AUTOINC_CODES')->insert(['id' => 6, 'c_chn' => '菴', 'c_pinyin' => 'an', 'c_lastname' => 1]);

        $this->actingAs($this->activeUser())
            ->from(route('app.codes.edit', ['table_name' => 'TEST_AUTOINC_CODES', 'id' => 6]))
            ->patch(route('app.codes.update', ['table_name' => 'TEST_AUTOINC_CODES', 'id' => 6]), [
                'c_chn' => '', // NOT NULL 無預設 → null → 完整性錯誤
                'c_pinyin' => 'an',
                'c_lastname' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('integrity');

        // 原列未被破壞。
        $this->assertSame('菴', DB::table('TEST_AUTOINC_CODES')->where('id', 6)->value('c_chn'));
    }

    #[Test]
    public function propose_records_proposal_operation(): void {
        $this->actingAs($this->activeUser())
            ->post(route('app.codes.propose.store', ['table_name' => 'TEST_CREATE_CODES']), [
                'code_id' => 7,
                'description' => 'proposed',
            ])
            ->assertRedirect(route('app.codes.show', ['table_name' => 'TEST_CREATE_CODES']));

        // 提案不直接寫入資料表
        $this->assertDatabaseMissing('TEST_CREATE_CODES', ['code_id' => 7]);
        $this->assertDatabaseHas('operations', ['resource' => 'TEST_CREATE_CODES']);
    }
}
