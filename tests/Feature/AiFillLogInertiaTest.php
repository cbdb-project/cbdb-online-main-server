<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-2 admin/ai-fill-logs Inertia 變體（app.admin.ai-fill-logs）測試。
 */
class AiFillLogInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-ai-fill-logs-inertia';
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

        Schema::create('ai_fill_logs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('c_personid')->nullable();
            $table->string('route_name', 255)->nullable();
            $table->string('route_url', 500)->nullable();
            $table->text('source_text')->nullable();
            $table->longText('ai_raw')->nullable();
            $table->longText('ai_matched')->nullable();
            $table->longText('user_submitted')->nullable();
            $table->boolean('success')->default(false);
            $table->string('error_message', 500)->nullable();
            $table->integer('execution_time_ms')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('category', 32)->nullable();
            $table->timestamps();
        });
    }

    private function makeSuperAdmin(): User {
        return User::create([
            'name' => 'Admin', 'email' => 'a@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    private function seedLog(int $userId, array $overrides = []): void {
        DB::table('ai_fill_logs')->insert(array_merge([
            'user_id' => $userId,
            'c_personid' => 1001,
            'route_name' => 'basicinformation.offices.index',
            'route_url' => '/basicinformation/1001/offices',
            'source_text' => '某甲於某年任某官',
            'ai_raw' => json_encode(['x' => 1]),
            // 使用非代碼欄位（c_firstyear），避免 resolveCodeLabels 觸發對 OFFICE_CODES
            // 等代碼表的查詢（測試不建立那些表）。
            'ai_matched' => json_encode(['statistics' => ['matched_count' => 1], 'matched_fields' => ['c_firstyear' => ['value' => '1050', 'text' => '1050']]]),
            'user_submitted' => json_encode(['c_firstyear' => '1050']),
            'success' => true,
            'execution_time_ms' => 1200,
            'category' => 'posting',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function it_renders_inertia_component_with_prepared_rows(): void {
        $admin = $this->makeSuperAdmin();
        $this->seedLog($admin->id);
        $this->seedLog($admin->id, ['category' => 'assoc', 'ai_matched' => json_encode(['matched_codes' => []]), 'user_submitted' => null]);

        $response = $this->actingAs($admin)->get(route('app.admin.ai-fill-logs'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AiFillLogs/Index')
            ->has('logs.data', 2)
            ->where('logs.meta.total', 2)
            ->has('users')
            ->has('filters')
            ->has('page_translations.admin')
            ->has('logs.data.0', fn (Assert $row) => $row
                ->has('id')
                ->has('category')
                ->has('source_text')
                ->has('person_url')
                ->has('comparison_rows')
                ->has('ai_matched_pretty')
                ->etc()));
    }

    #[Test]
    public function it_filters_by_category(): void {
        $admin = $this->makeSuperAdmin();
        $this->seedLog($admin->id, ['category' => 'posting']);
        $this->seedLog($admin->id, ['category' => 'status', 'ai_matched' => json_encode(['matched_codes' => []]), 'user_submitted' => null]);

        $this->actingAs($admin)->get(route('app.admin.ai-fill-logs', ['category' => 'status']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('filters.category', 'status'));
    }

    #[Test]
    public function non_super_admin_gets_403(): void {
        $expert = User::create([
            'name' => 'Exp', 'email' => 'e@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_EXPERT,
        ]);

        $this->actingAs($expert)->get(route('app.admin.ai-fill-logs'))->assertForbidden();
    }
}
