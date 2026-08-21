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

    /**
     * QA 與 NL→SQL 共用同一張 nl_query_logs，唯一的區別是 answerFromNL 寫入時給 question
     * 加了 '[QA] ' 前綴。前端靠這個旗標決定「解析不出 JSON 的回應原文」要不要當成 Markdown
     * 渲染——NL→SQL 的同一條路徑可能是模型直接吐出的裸 SQL，渲染後單獨一行 `---` 會把前一行
     * 變成標題、成對的 `*` 會變斜體。判定寫錯的症狀是靜默的內容失真，故在此釘住。
     */
    #[Test]
    public function it_flags_qa_logs_via_question_prefix(): void {
        $admin = $this->makeSuperAdmin();
        // 列表按 created_at DESC；四筆若共用同一個時間戳，先後順序取決於未定義的 tiebreak，
        // 斷言就會變成碰運氣。這裡給遞減的時間戳，讓下方的索引順序是確定的。
        $this->seedLog($admin->id, ['question' => '[QA] 李白是誰？', 'created_at' => now()]);
        $this->seedLog($admin->id, ['question' => '誰是宋朝宰相？', 'created_at' => now()->subMinute()]);
        // 前綴出現在中段不算 QA，避免用 str_contains 之類的寬鬆比對。
        $this->seedLog($admin->id, ['question' => '請問 [QA] 是什麼意思？', 'created_at' => now()->subMinutes(2)]);
        $this->seedLog($admin->id, ['question' => null, 'created_at' => now()->subMinutes(3)]);

        $this->actingAs($admin)->get(route('app.query-playground.nl-query-logs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 4)
                ->where('logs.data.0.question', '[QA] 李白是誰？')
                ->where('logs.data.0.is_qa', true)
                ->where('logs.data.1.is_qa', false)
                ->where('logs.data.2.is_qa', false)
                ->where('logs.data.3.is_qa', false));
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
