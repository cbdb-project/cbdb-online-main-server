<?php

namespace Tests\Feature;

use App\Models\User;
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
        config(['codes.tables' => ['TEST_CREATE_CODES' => '測試代碼']]);

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
                ->has('urls.propose'));
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
