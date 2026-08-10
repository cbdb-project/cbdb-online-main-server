<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-1 參考頁 admin/audit-logs 的 Inertia 變體（app.admin.audit-logs）測試。
 * 守護元件名稱、props 契約、授權（403/404）、篩選與排序。
 */
class AdminAuditLogInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-admin-audit-log-inertia';
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
    }

    private function createAuditTable(): void {
        Schema::create('audit_log', function ($table) {
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

    private function makeAdmin(): User {
        return User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'token',
            'is_active' => 1,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    private function seedRow(array $overrides = []): void {
        DB::table('audit_log')->insert(array_merge([
            'occurred_at' => now(),
            'created_at' => now(),
            'table_name' => 'ALTNAME_DATA',
            'operation' => 'UPDATE',
            'actor_type' => 'user',
            'actor_id' => '1',
            'operation_id' => '01HXXXXXXXXXXXXXXXXXXXXX01',
            'row_pk' => json_encode(['c_personid' => 1001], JSON_UNESCAPED_UNICODE),
            'row_pk_text' => 'c_personid=1001',
            'old_data' => json_encode(['c_name' => '舊'], JSON_UNESCAPED_UNICODE),
            'new_data' => json_encode(['c_name' => '新'], JSON_UNESCAPED_UNICODE),
        ], $overrides));
    }

    #[Test]
    public function it_renders_inertia_component_with_props(): void {
        $this->createAuditTable();
        $admin = $this->makeAdmin();
        $this->seedRow(['operation' => 'INSERT', 'table_name' => 'BIOG_MAIN']);
        $this->seedRow(['operation' => 'DELETE', 'table_name' => 'ALTNAME_DATA']);

        $response = $this->actingAs($admin)->get(route('app.admin.audit-logs'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AuditLogs/Index')
            ->has('logs.data', 2)
            ->where('logs.meta.total', 2)
            ->has('logs.meta', fn (Assert $meta) => $meta
                ->where('current_page', 1)
                ->where('per_page', 20)
                ->etc())
            ->has('table_names')
            ->has('actor_types')
            ->where('sort', 'occurred_at')
            ->where('direction', 'desc')
            ->has('filters')
            ->has('logs.data.0', fn (Assert $row) => $row
                ->has('id')
                ->has('operation')
                ->has('pk_description')
                ->has('occurred_at_iso')
                ->has('diff_rows')
                ->etc())
            ->has('page_translations.admin'));
    }

    #[Test]
    public function it_applies_table_name_filter(): void {
        $this->createAuditTable();
        $admin = $this->makeAdmin();
        $this->seedRow(['table_name' => 'BIOG_MAIN']);
        $this->seedRow(['table_name' => 'ALTNAME_DATA']);

        $response = $this->actingAs($admin)->get(route('app.admin.audit-logs', ['table_name' => 'BIOG_MAIN']));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('filters.table_name', 'BIOG_MAIN'));
    }

    #[Test]
    public function it_honours_sort_whitelist_and_direction(): void {
        $this->createAuditTable();
        $admin = $this->makeAdmin();
        $this->seedRow();

        // 合法欄位
        $this->actingAs($admin)->get(route('app.admin.audit-logs', ['sort' => 'table_name', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page->where('sort', 'table_name')->where('direction', 'asc'));

        // 非白名單欄位 → 回退 occurred_at；非法方向 → desc
        $this->actingAs($admin)->get(route('app.admin.audit-logs', ['sort' => 'evil; DROP', 'direction' => 'sideways']))
            ->assertInertia(fn (Assert $page) => $page->where('sort', 'occurred_at')->where('direction', 'desc'));
    }

    #[Test]
    public function non_admin_gets_403(): void {
        $this->createAuditTable();
        $regular = User::forceCreate([
            'name' => 'Reg', 'email' => 'r@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_REGULAR,
        ]);

        $this->actingAs($regular)->get(route('app.admin.audit-logs'))->assertForbidden();
    }

    #[Test]
    public function missing_table_gives_404(): void {
        // 不建立 audit_log 表
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('app.admin.audit-logs'))->assertNotFound();
    }
}
