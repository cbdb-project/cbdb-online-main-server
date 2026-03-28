<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NaturalLanguageQueryService;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricalQaTest extends TestCase {
    protected User $adminUser;
    protected User $regularUser;

    protected function setUp(): void {
        parent::setUp();

        Config::set('codes.tables', [
            'DYNASTIES' => 'Dynasties Table',
            'ALTNAME_DATA' => 'Altname Data',
            'BIOG_MAIN' => 'Biography Main',
        ]);
        Config::set('services.gemini.model', 'gemini-test-model');
        Config::set('services.gemini.api_key', 'test-api-key');

        $this->adminUser = new User();
        $this->adminUser->forceFill([
            'id' => 1,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'is_admin' => User::ROLE_EXPERT,
            'is_active' => User::STATUS_ACTIVE,
        ]);

        $this->regularUser = new User();
        $this->regularUser->forceFill([
            'id' => 2,
            'name' => 'Regular User',
            'email' => 'reg@example.com',
            'is_admin' => User::ROLE_REGULAR,
            'is_active' => User::STATUS_ACTIVE,
        ]);
    }

    // ──── Page Props ────

    #[Test]
    public function app_playground_returns_qa_endpoint_props() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->has('answerFromNlEndpoint')
                ->has('answerFromNlStreamEndpoint');
        });
    }

    #[Test]
    public function qa_endpoint_props_are_relative_urls() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->where('answerFromNlEndpoint', '/query-playground/answer-from-nl')
                ->where('answerFromNlStreamEndpoint', '/query-playground/answer-from-nl-stream');
        });
    }

    #[Test]
    public function qa_props_do_not_break_existing_props() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->has('initialSql')
                ->has('nlModel')
                ->has('qbeTables')
                ->has('pageUrl')
                ->has('runEndpoint')
                ->has('schemaEndpoint')
                ->has('generateFromNlEndpoint')
                ->has('generateFromNlStreamEndpoint')
                ->has('answerFromNlEndpoint')
                ->has('answerFromNlStreamEndpoint');
        });
    }

    // ──── Authorization ────

    #[Test]
    public function guests_cannot_access_answer_from_nl() {
        auth()->logout();
        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '李白是什麼時代的人？',
        ]);
        // Will redirect or 401
        $this->assertTrue(in_array($response->status(), [302, 401]));
    }

    #[Test]
    public function regular_users_cannot_access_answer_from_nl() {
        $this->be($this->regularUser);
        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '李白是什麼時代的人？',
        ]);
        $response->assertStatus(403);
    }

    #[Test]
    public function guests_cannot_access_answer_from_nl_stream() {
        auth()->logout();
        $response = $this->post(route('query-playground.answer-from-nl-stream'), [
            'question' => '李白是什麼時代的人？',
        ]);
        $this->assertTrue(in_array($response->status(), [302, 401]));
    }

    #[Test]
    public function regular_users_cannot_access_answer_from_nl_stream() {
        $this->be($this->regularUser);
        $response = $this->postJson(route('query-playground.answer-from-nl-stream'), [
            'question' => '李白是什麼時代的人？',
        ]);
        $response->assertStatus(403);
    }

    // ──── Validation ────

    #[Test]
    public function answer_from_nl_requires_question() {
        $this->be($this->adminUser);
        $response = $this->postJson(route('query-playground.answer-from-nl'), []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['question']);
    }

    #[Test]
    public function answer_from_nl_rejects_question_exceeding_max_length() {
        $this->be($this->adminUser);
        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => str_repeat('x', 1001),
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['question']);
    }

    #[Test]
    public function answer_from_nl_stream_requires_question() {
        $this->be($this->adminUser);
        $response = $this->postJson(route('query-playground.answer-from-nl-stream'), []);
        $response->assertStatus(422);
    }

    // ──── Successful answer (mocked service) ────

    #[Test]
    public function answer_from_nl_returns_answer_markdown_on_success() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')
            ->willReturn([
                'success' => true,
                'answer_markdown' => '## 李白\n\n李白是唐代詩人。',
                'summary' => '唐代詩人',
                'sql_used' => ['SELECT * FROM BIOG_MAIN WHERE c_name_chn = \'李白\''],
                'tool_calls' => [],
                'evidence' => [
                    ['type' => 'database', 'label' => 'BIOG_MAIN', 'detail' => '人物基本資料'],
                ],
                'caveat' => '部分歷史背景為模型補充。',
                'model' => 'gemini-test-model',
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '李白是什麼時代的人？',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'answer_markdown',
            'summary',
            'sql_used',
            'tool_calls',
            'evidence',
            'caveat',
            'model',
        ]);
        $response->assertJson(['success' => true]);
        $this->assertNotEmpty($response->json('answer_markdown'));
    }

    #[Test]
    public function answer_from_nl_returns_error_on_failure() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')
            ->willReturn([
                'success' => false,
                'error' => 'LLM API 調用失敗',
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '李白是什麼時代的人？',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => 'LLM API 調用失敗',
        ]);
    }

    #[Test]
    public function answer_from_nl_works_with_use_tools_false() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->expects($this->once())
            ->method('answerQuestion')
            ->with(
                '李白是什麼時代的人？',
                null,
                null,
                false
            )
            ->willReturn([
                'success' => true,
                'answer_markdown' => '李白是唐代詩人。',
                'summary' => '唐代詩人',
                'sql_used' => [],
                'tool_calls' => [],
                'evidence' => [],
                'caveat' => '',
                'model' => 'gemini-test-model',
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '李白是什麼時代的人？',
            'use_tools' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    // ──── Streaming basic tests ────

    #[Test]
    public function answer_from_nl_stream_returns_200_with_sse_headers() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')
            ->willReturn([
                'success' => true,
                'answer_markdown' => '李白是唐代詩人。',
                'summary' => '唐代詩人',
                'sql_used' => [],
                'tool_calls' => [],
                'evidence' => [],
                'caveat' => '',
                'model' => 'gemini-test-model',
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->call('POST', route('query-playground.answer-from-nl-stream'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/event-stream',
        ], json_encode(['question' => '李白是什麼時代的人？']));

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));
    }

    // ──── Regression: existing NL endpoints still work ────

    #[Test]
    public function old_generate_from_nl_endpoint_still_works() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('generateSQL')
            ->willReturn([
                'success' => true,
                'sql' => 'SELECT * FROM DYNASTIES',
                'explanation' => '查詢所有朝代',
                'model' => 'gemini-test-model',
                'tool_calls' => null,
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->postJson(route('query-playground.generate-from-nl'), [
            'question' => '顯示所有朝代',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'sql' => 'SELECT * FROM DYNASTIES',
        ]);
    }

    #[Test]
    public function old_generate_from_nl_stream_endpoint_still_works() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('generateSQL')
            ->willReturn([
                'success' => true,
                'sql' => 'SELECT * FROM DYNASTIES',
                'explanation' => '查詢所有朝代',
                'model' => 'gemini-test-model',
                'tool_calls' => null,
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->call('POST', route('query-playground.generate-from-nl-stream'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/event-stream',
        ], json_encode(['question' => '顯示所有朝代']));

        $response->assertStatus(200);
    }
}
