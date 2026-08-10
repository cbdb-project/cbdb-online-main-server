<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 步驟 6：char_variant_map 註冊進 config('codes.tables') 後，確認 Codes UI
 * 既有的增修／稽核／列表機制對這張新表同樣生效（不需要新寫稽核邏輯）。
 * 見 docs/CHAR_VARIANT_MAP_CALL_SITE_WIRING_PLAN.md 步驟 6。
 */
class CodesCharVariantMapAuditTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiled = sys_get_temp_dir().'/cbdb-test-views-codes-charvariantmap';
        if (!is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }
        config(['view.compiled' => $compiled]);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config(['codes.tables' => ['char_variant_map' => '異體字落地替換對照表']]);
        config(['codes.ui_hidden' => []]);

        Schema::create('users', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('confirmation_token')->nullable();
            $t->tinyInteger('is_active')->default(0);
            $t->tinyInteger('is_admin')->default(0);
            $t->timestamps();
        });

        Schema::create('char_variant_map', function ($t) {
            $t->bigIncrements('id');
            $t->string('c_variant_char', 10);
            $t->string('c_reference_char', 10);
            $t->tinyInteger('c_strict_excluded')->default(1);
            $t->string('c_notes', 255)->nullable();
            $t->timestamps();

            $t->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });

        Schema::create('operations', function ($t) {
            $t->increments('id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->integer('c_personid')->default(0);
            $t->string('resource')->nullable();
            $t->text('resource_id')->nullable();
            $t->string('op_type')->nullable();
            $t->longText('resource_data')->nullable();
            $t->longText('resource_original')->nullable();
            $t->integer('crowdsourcing_status')->default(0);
            $t->timestamps();
        });

        Schema::create('audit_log', function ($t) {
            $t->bigIncrements('id');
            $t->dateTime('occurred_at');
            $t->dateTime('created_at');
            $t->string('table_name', 64);
            $t->string('operation', 16);
            $t->string('actor_type', 32);
            $t->string('actor_id', 128);
            $t->string('operation_id', 64);
            $t->text('row_pk');
            $t->string('row_pk_text', 512)->nullable();
            $t->longText('old_data')->nullable();
            $t->longText('new_data')->nullable();
        });
    }

    protected function tearDown(): void {
        foreach (['audit_log', 'operations', 'char_variant_map', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function activeUser(): User {
        return User::forceCreate([
            'name' => 'U', 'email' => 'u@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    private function seedSevenRows(): void {
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '愼', 'c_reference_char' => '慎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '槀', 'c_reference_char' => '稿', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
            ['c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0],
            ['c_variant_char' => '頴', 'c_reference_char' => '穎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
            ['c_variant_char' => '厰', 'c_reference_char' => '廠', 'c_strict_excluded' => 0],
        ]);
    }

    #[Test]
    public function show_lists_all_seven_seed_rows(): void {
        $this->actingAs($this->activeUser());
        $this->seedSevenRows();

        $response = $this->get('/codes/char_variant_map');

        $response->assertOk();
        foreach (['愼', '槀', '峯', '靑', '頴', '淸', '厰'] as $variant) {
            $response->assertSee($variant);
        }
    }

    #[Test]
    public function store_writes_audit_log_insert(): void {
        $this->actingAs($this->activeUser());

        $response = $this->post('/codes/char_variant_map', [
            'id' => 100,
            'c_variant_char' => '試',
            'c_reference_char' => '试',
            'c_strict_excluded' => 1,
        ]);

        $response->assertStatus(302);

        $this->assertDatabaseHas('char_variant_map', [
            'id' => 100,
            'c_variant_char' => '試',
        ]);

        $audit = DB::table('audit_log')->where('table_name', 'char_variant_map')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
        $this->assertSame('試', json_decode($audit->new_data, true)['c_variant_char']);
    }

    #[Test]
    public function update_writes_audit_log_update_with_old_and_new(): void {
        $this->actingAs($this->activeUser());
        DB::table('char_variant_map')->insert([
            'id' => 101,
            'c_variant_char' => '舊',
            'c_reference_char' => '旧',
            'c_strict_excluded' => 1,
        ]);

        $response = $this->put('/codes/char_variant_map/101', [
            'id' => 101,
            'c_variant_char' => '舊',
            'c_reference_char' => '新參考字',
            'c_strict_excluded' => 0,
        ]);

        $response->assertStatus(302);

        $audit = DB::table('audit_log')->where('table_name', 'char_variant_map')->where('operation', 'UPDATE')->first();
        $this->assertNotNull($audit);
        $this->assertSame('旧', json_decode($audit->old_data, true)['c_reference_char']);
        $this->assertSame('新參考字', json_decode($audit->new_data, true)['c_reference_char']);
    }
}
