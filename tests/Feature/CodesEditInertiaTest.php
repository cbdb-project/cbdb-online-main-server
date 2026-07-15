<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P2-4 codes/edit Inertia 變體（app.codes.edit/update/destroy/propose.update）測試。
 * 使用獨立表名避免 getKeyColumns 靜態快取在全套執行時互相污染。
 */
class CodesEditInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-codes-edit-inertia';
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
        config(['codes.tables' => ['TEST_EDIT_CODES' => '測試代碼', 'ADDR_CODES' => '地址代碼']]);

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

        Schema::create('TEST_EDIT_CODES', function ($table) {
            $table->integer('code_id')->primary();
            $table->string('description')->nullable();
        });
        DB::table('TEST_EDIT_CODES')->insert(['code_id' => 5, 'description' => 'old']);

        Schema::create('ADDR_CODES', function ($table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
        });
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 900, 'c_name' => 'Test', 'c_name_chn' => '測試']);

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

    private function activeUser(): User {
        return User::create([
            'name' => 'U', 'email' => 'u@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    #[Test]
    public function edit_renders_form_with_values(): void {
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'TEST_EDIT_CODES', 'id' => 5]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Edit')
                ->where('table', 'TEST_EDIT_CODES')
                ->where('values.code_id', 5)
                ->where('values.description', 'old')
                ->has('urls.update')
                ->has('urls.destroy')
                ->where('tier2_fields', [])); // 非 Phase B 表：無 Tier 2 欄
    }

    #[Test]
    public function edit_passes_tier2_fields_for_config_table(): void {
        // §D-6：編輯 ADDR_CODES 時，Tier 2 欄 c_name 傳給前端供保存時偵測 v→ü 彈窗
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'ADDR_CODES', 'id' => 900]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Edit')
                ->where('table', 'ADDR_CODES')
                ->where('tier2_fields', ['c_name']));
    }

    #[Test]
    public function edit_form_marks_required_columns(): void {
        // code_id（NOT NULL 手填主鍵、無預設）標必填；description 可空不標。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'TEST_EDIT_CODES', 'id' => 5]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Edit')
                ->where('required_columns', ['code_id']));
    }

    #[Test]
    public function update_modifies_row_and_redirects(): void {
        $this->actingAs($this->activeUser())
            ->patch(route('app.codes.update', ['table_name' => 'TEST_EDIT_CODES', 'id' => 5]), [
                'code_id' => 5,
                'description' => 'new',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('TEST_EDIT_CODES', ['code_id' => 5, 'description' => 'new']);
    }

    #[Test]
    public function destroy_is_disabled_and_keeps_row(): void {
        // 安全：碼表刪除已停用（防級聯刪除人物資料）。仍導回 show，但不得刪列。
        $this->actingAs($this->activeUser())
            ->delete(route('app.codes.destroy', ['table_name' => 'TEST_EDIT_CODES', 'id' => 5]))
            ->assertRedirect(route('app.codes.show', ['table_name' => 'TEST_EDIT_CODES']));

        $this->assertDatabaseHas('TEST_EDIT_CODES', ['code_id' => 5]);
    }

    #[Test]
    public function propose_update_records_proposal_without_writing(): void {
        $this->actingAs($this->activeUser())
            ->post(route('app.codes.propose.update', ['table_name' => 'TEST_EDIT_CODES', 'id' => 5]), [
                'code_id' => 5,
                'description' => 'proposed change',
            ])
            ->assertRedirect();

        // 原值不變、提案已記錄
        $this->assertDatabaseHas('TEST_EDIT_CODES', ['code_id' => 5, 'description' => 'old']);
        $this->assertDatabaseHas('operations', ['resource' => 'TEST_EDIT_CODES']);
    }

    #[Test]
    public function guest_cannot_update(): void {
        $this->patch(route('app.codes.update', ['table_name' => 'TEST_EDIT_CODES', 'id' => 5]), [
            'code_id' => 5, 'description' => 'hack',
        ]);

        $this->assertDatabaseHas('TEST_EDIT_CODES', ['code_id' => 5, 'description' => 'old']);
    }
}
