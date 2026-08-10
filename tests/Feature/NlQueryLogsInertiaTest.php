<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-6 query_playground/nl-query-logs Inertia 變體（app.query-playground.nl-query-logs）測試。
 */
class NlQueryLogsInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-nl-query-logs-inertia';
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

        Schema::create('nl_query_logs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('question')->nullable();
            $table->text('generated_sql')->nullable();
            $table->boolean('success')->default(false);
            $table->text('error_message')->nullable();
            $table->text('explanation')->nullable();
            $table->longText('llm_prompt')->nullable();
            $table->longText('llm_response')->nullable();
            $table->integer('execution_time_ms')->nullable();
            $table->timestamps();
        });
    }

    private function makeSuperAdmin(): User {
        return User::forceCreate([
            'name' => 'Admin', 'email' => 'a@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    private function seedLog(int $userId, array $overrides = []): void {
        DB::table('nl_query_logs')->insert(array_merge([
            'user_id' => $userId,
            'question' => '誰是宋朝宰相？',
            'generated_sql' => 'SELECT 1',
            'success' => true,
            'explanation' => '示例說明',
            'llm_prompt' => 'prompt text',
            'llm_response' => json_encode(['model' => 'gpt-x', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]]),
            'execution_time_ms' => 800,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function it_renders_inertia_component_with_prepared_rows(): void {
        $admin = $this->makeSuperAdmin();
        $this->seedLog($admin->id);
        $this->seedLog($admin->id, ['success' => false, 'error_message' => 'boom', 'generated_sql' => null, 'llm_response' => null]);

        $response = $this->actingAs($admin)->get(route('app.query-playground.nl-query-logs'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/NlQueryLogs/Index')
            ->has('logs.data', 2)
            ->where('logs.meta.total', 2)
            ->has('users')
            ->has('filters')
            ->has('playground_url')
            ->has('page_translations.query')
            ->has('logs.data.0', fn (Assert $row) => $row
                ->has('id')
                ->has('question')
                ->has('success')
                ->has('llm_summary')
                ->etc()));
    }

    #[Test]
    public function it_filters_by_success(): void {
        $admin = $this->makeSuperAdmin();
        $this->seedLog($admin->id, ['success' => true]);
        $this->seedLog($admin->id, ['success' => false, 'llm_response' => null]);

        $this->actingAs($admin)->get(route('app.query-playground.nl-query-logs', ['success' => '0']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('filters.success', '0'));
    }

    #[Test]
    public function it_tolerates_malformed_llm_response(): void {
        $admin = $this->makeSuperAdmin();
        // 無效 JSON 與非物件 JSON 皆不應導致 500；llm_summary 應為 null。
        $this->seedLog($admin->id, ['llm_response' => 'not-json{']);
        $this->seedLog($admin->id, ['llm_response' => '"a string"']);

        $this->actingAs($admin)->get(route('app.query-playground.nl-query-logs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 2)
                ->where('logs.data.0.llm_summary', null)
                ->where('logs.data.1.llm_summary', null));
    }

    #[Test]
    public function non_super_admin_gets_403(): void {
        $expert = User::forceCreate([
            'name' => 'E', 'email' => 'e@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_EXPERT,
        ]);

        $this->actingAs($expert)->get(route('app.query-playground.nl-query-logs'))->assertForbidden();
    }
}
