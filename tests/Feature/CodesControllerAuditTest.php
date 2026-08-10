<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §D-2：/codes 直寫路徑（store/update/destroy）補寫 audit_log，使 UI 與 v2 API 審計一致。
 */
class CodesControllerAuditTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiled = sys_get_temp_dir().'/cbdb-test-views-codes-audit';
        if (!is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }
        config(['view.compiled' => $compiled]);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config(['codes.tables' => ['TEST_AUDIT_CODES' => '審計測試代碼']]);

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
        Schema::create('TEST_AUDIT_CODES', function ($t) {
            $t->integer('code_id')->primary();
            $t->string('description')->nullable();
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
        foreach (['audit_log', 'operations', 'TEST_AUDIT_CODES', 'users'] as $t) {
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

    #[Test]
    public function store_writes_audit_log_insert(): void {
        $this->actingAs($this->activeUser());

        $this->post('/codes/TEST_AUDIT_CODES', ['code_id' => 7, 'description' => 'hello']);

        $audit = DB::table('audit_log')->where('table_name', 'TEST_AUDIT_CODES')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
        $this->assertSame('user', $audit->actor_type);
        $this->assertNull($audit->old_data);
        $this->assertSame('hello', json_decode($audit->new_data, true)['description']);
        $this->assertSame(['code_id' => 7], json_decode($audit->row_pk, true));
    }

    #[Test]
    public function update_writes_audit_log_update_with_old_and_new(): void {
        $this->actingAs($this->activeUser());
        DB::table('TEST_AUDIT_CODES')->insert(['code_id' => 8, 'description' => 'before']);

        $this->put('/codes/TEST_AUDIT_CODES/8', ['code_id' => 8, 'description' => 'after']);

        $audit = DB::table('audit_log')->where('operation', 'UPDATE')->first();
        $this->assertNotNull($audit);
        $this->assertSame('TEST_AUDIT_CODES', $audit->table_name);
        $this->assertSame('before', json_decode($audit->old_data, true)['description']);
        $this->assertSame('after', json_decode($audit->new_data, true)['description']);
    }

    #[Test]
    public function destroy_is_disabled_no_delete_no_audit(): void {
        // 安全：碼表刪除已停用（防級聯刪除人物資料）。不刪列、不寫 DELETE 審計。
        $this->actingAs($this->activeUser());
        DB::table('TEST_AUDIT_CODES')->insert(['code_id' => 9, 'description' => 'doomed']);

        $this->delete('/codes/TEST_AUDIT_CODES/9');

        $this->assertDatabaseHas('TEST_AUDIT_CODES', ['code_id' => 9]);
        $this->assertNull(DB::table('audit_log')->where('operation', 'DELETE')->first());
    }
}
