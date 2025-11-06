<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\User;

class WikiMaintenanceControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        // 设置缓存为数组驱动，避免文件权限问题
        config(['cache.default' => 'array']);

        // 创建测试用户（使用 Laravel 5.5 语法）
        $this->user = factory(User::class)->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_active' => 1,
            'is_admin' => 1
        ]);
    }

    /**
     * 测试未认证用户不能访问 Wiki 维护页面
     */
    public function test_unauthenticated_user_cannot_access_wiki_maintenance()
    {
        $response = $this->get('/admin/wiki-maintenance');
        $response->assertRedirect('/login');
    }

    /**
     * 测试认证用户可以访问 Wiki 维护页面
     */
    public function test_authenticated_user_can_access_wiki_maintenance()
    {
        $this->actingAs($this->user);

        $response = $this->get('/admin/wiki-maintenance');
        $response->assertStatus(200);
        $response->assertViewIs('admin.wiki-maintenance');
        $response->assertSee('Wiki 對照資料維護');
    }

    /**
     * 测试页面显示正确的数据源选项
     */
    public function test_wiki_maintenance_shows_correct_source_options()
    {
        $this->actingAs($this->user);

        $response = $this->get('/admin/wiki-maintenance');
        $response->assertStatus(200);
        $response->assertSee('中文維基百科 (Wikipedia)');
        $response->assertSee('維基數據 (Wikidata)');
        $response->assertSee('英文維基百科 (Wikipedia)');
    }

    /**
     * 测试删除全部记录功能需要有效的 source_id
     */
    public function test_delete_all_records_validation()
    {
        $this->actingAs($this->user);

        $response = $this->post('/admin/wiki-maintenance/delete-all', [
            'source_id' => 99999  // 无效的 source_id
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    /**
     * 测试 URL 导入功能的输入验证
     */
    public function test_import_url_validation()
    {
        $this->actingAs($this->user);

        // 测试缺少 URL
        $response = $this->postJson('/admin/wiki-maintenance/import-url', [
            'target_source' => 60795
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['import_url']);

        // 测试无效 URL
        $response = $this->postJson('/admin/wiki-maintenance/import-url', [
            'import_url' => 'not-a-url',
            'target_source' => 60795
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['import_url']);

        // 测试无效数据源
        $response = $this->postJson('/admin/wiki-maintenance/import-url', [
            'import_url' => 'https://example.com/data.json',
            'target_source' => 99999
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['target_source']);
    }

    /**
     * 测试有效的 URL 导入请求返回正确响应
     */
    public function test_import_url_returns_success_response()
    {
        $this->actingAs($this->user);

        // Mock 不实际执行导入任务
        $response = $this->postJson('/admin/wiki-maintenance/import-url', [
            'import_url' => 'https://example.com/data.json',
            'target_source' => 60795
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => '導入任務已開始'
        ]);
        $response->assertJsonStructure([
            'success',
            'message',
            'task_id'
        ]);
    }

    /**
     * 测试进度查询功能
     */
    public function test_get_import_progress()
    {
        $this->actingAs($this->user);

        $taskId = 'test_task_123';

        // 测试不存在的任务
        $response = $this->getJson("/admin/wiki-maintenance/progress/{$taskId}");
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => '找不到指定的任務'
        ]);

        // 模拟存在的任务
        Cache::put("import_progress_{$taskId}", [
            'task_id' => $taskId,
            'progress' => 50,
            'message' => '正在处理...',
            'status' => 'running'
        ], 3600);

        $response = $this->getJson("/admin/wiki-maintenance/progress/{$taskId}");
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'progress' => [
                'task_id' => $taskId,
                'progress' => 50,
                'message' => '正在处理...',
                'status' => 'running'
            ]
        ]);
    }

    /**
     * 测试进度初始化功能
     */
    public function test_initialize_progress()
    {
        $this->actingAs($this->user);

        $controller = new \App\Http\Controllers\WikiMaintenanceController();
        $taskId = 'test_task_init';
        $sourceName = '测试来源';

        // 使用反射访问私有方法
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('initializeProgress');
        $method->setAccessible(true);
        $method->invokeArgs($controller, [$taskId, $sourceName]);

        // 验证缓存中的进度数据
        $progress = Cache::get("import_progress_{$taskId}");
        $this->assertNotNull($progress);
        $this->assertEquals($taskId, $progress['task_id']);
        $this->assertEquals($sourceName, $progress['source_name']);
        $this->assertEquals(0, $progress['progress']);
        $this->assertEquals('running', $progress['status']);
    }

    /**
     * 测试进度更新功能
     */
    public function test_update_progress()
    {
        $this->actingAs($this->user);

        $controller = new \App\Http\Controllers\WikiMaintenanceController();
        $taskId = 'test_task_update';

        // 初始化进度
        $reflection = new \ReflectionClass($controller);
        $initMethod = $reflection->getMethod('initializeProgress');
        $initMethod->setAccessible(true);
        $initMethod->invokeArgs($controller, [$taskId, 'Test Source']);

        // 更新进度
        $updateMethod = $reflection->getMethod('updateProgress');
        $updateMethod->setAccessible(true);
        $updateMethod->invokeArgs($controller, [$taskId, 75, '正在完成...', 'running']);

        // 验证更新后的进度
        $progress = Cache::get("import_progress_{$taskId}");
        $this->assertEquals(75, $progress['progress']);
        $this->assertEquals('正在完成...', $progress['message']);
        $this->assertEquals('running', $progress['status']);

        // 测试完成状态
        $updateMethod->invokeArgs($controller, [$taskId, 100, '已完成', 'completed']);
        $progress = Cache::get("import_progress_{$taskId}");
        $this->assertEquals('completed', $progress['status']);
        $this->assertArrayHasKey('completed_at', $progress);
    }

    /**
     * 测试记录数据准备功能
     */
    public function test_prepare_record_data()
    {
        $this->actingAs($this->user);

        $controller = new \App\Http\Controllers\WikiMaintenanceController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('prepareRecordData');
        $method->setAccessible(true);

        // 测试 Wikidata 记录
        $wikidataRecord = [
            'cbdb_personid' => 12345,
            'wikidata_qid' => 'Q123456',
            'wikipedia' => []
        ];

        $result = $method->invokeArgs($controller, [$wikidataRecord, 68942]);
        $this->assertNotNull($result);
        $this->assertEquals(12345, $result['c_personid']);
        $this->assertEquals(68942, $result['c_textid']);
        $this->assertEquals('Q123456', $result['c_pages']);
        $this->assertContains('批次導入於', $result['c_notes']);

        // 测试中文维基百科记录
        $zhWikiRecord = [
            'cbdb_personid' => 12346,
            'wikidata_qid' => 'Q123457',
            'wikipedia' => [
                'zh' => '司马光'
            ]
        ];

        $result = $method->invokeArgs($controller, [$zhWikiRecord, 60795]);
        $this->assertNotNull($result);
        $this->assertEquals('司马光', $result['c_pages']);

        // 测试英文维基百科记录
        $enWikiRecord = [
            'cbdb_personid' => 12347,
            'wikidata_qid' => 'Q123458',
            'wikipedia' => [
                'en' => 'Sima_Guang'
            ]
        ];

        $result = $method->invokeArgs($controller, [$enWikiRecord, 68943]);
        $this->assertNotNull($result);
        $this->assertEquals('Sima_Guang', $result['c_pages']);

        // 测试无效记录
        $invalidRecord = [
            'cbdb_personid' => 0,  // 无效的 personid
            'wikidata_qid' => 'Q123459'
        ];

        $result = $method->invokeArgs($controller, [$invalidRecord, 68942]);
        $this->assertNull($result);
    }

    /**
     * 测试 JSON 错误消息功能
     */
    public function test_json_error_messages()
    {
        $controller = new \App\Http\Controllers\WikiMaintenanceController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('getJsonErrorMessage');
        $method->setAccessible(true);

        $testCases = [
            JSON_ERROR_NONE => '無錯誤',
            JSON_ERROR_SYNTAX => 'JSON 語法錯誤，請檢查格式是否正確',
            JSON_ERROR_UTF8 => 'JSON 包含無效的 UTF-8 字元',
            JSON_ERROR_DEPTH => 'JSON 資料結構太深，超過了最大深度限制',
            999 => '未知的 JSON 錯誤 (錯誤代碼: 999)'
        ];

        foreach ($testCases as $errorCode => $expectedMessage) {
            $result = $method->invokeArgs($controller, [$errorCode]);
            $this->assertEquals($expectedMessage, $result);
        }
    }

    /**
     * 测试 HTTP 错误消息功能
     */
    public function test_http_error_messages()
    {
        $controller = new \App\Http\Controllers\WikiMaintenanceController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('getHttpErrorMessage');
        $method->setAccessible(true);

        $testCases = [
            404 => 'URL 指向的檔案不存在 (HTTP 404)',
            403 => '沒有權限存取此 URL (HTTP 403)',
            500 => '目標服務器內部錯誤 (HTTP 500)',
            999 => 'HTTP 錯誤 999，請檢查 URL 或聯絡資料提供者'
        ];

        foreach ($testCases as $statusCode => $expectedMessage) {
            $result = $method->invokeArgs($controller, [$statusCode]);
            $this->assertContains((string)$statusCode, $result);
        }
    }

    /**
     * 测试 JSON 格式验证
     */
    public function test_json_format_validation()
    {
        $controller = new \App\Http\Controllers\WikiMaintenanceController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('looksLikeValidJson');
        $method->setAccessible(true);

        // 有效的 JSON 格式
        $this->assertTrue($method->invokeArgs($controller, ['{"test": "value"}']));
        $this->assertTrue($method->invokeArgs($controller, ['[1, 2, 3]']));
        $this->assertTrue($method->invokeArgs($controller, ['  {"test": "value"}  ']));

        // 无效的 JSON 格式
        $this->assertFalse($method->invokeArgs($controller, ['<html></html>']));
        $this->assertFalse($method->invokeArgs($controller, ['plain text']));
        $this->assertFalse($method->invokeArgs($controller, ['']));
    }

    /**
     * 测试 HTML 错误页面检测
     */
    public function test_html_error_page_detection()
    {
        $controller = new \App\Http\Controllers\WikiMaintenanceController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('looksLikeHtmlErrorPage');
        $method->setAccessible(true);

        // HTML 错误页面
        $htmlError = '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Error 404</h1></body></html>';
        $this->assertTrue($method->invokeArgs($controller, [$htmlError]));

        $htmlError2 = '<html><body><h1>Access Denied</h1><p>403 Forbidden</p></body></html>';
        $this->assertTrue($method->invokeArgs($controller, [$htmlError2]));

        // 正常 JSON 内容
        $jsonContent = '{"records": [{"test": "data"}]}';
        $this->assertFalse($method->invokeArgs($controller, [$jsonContent]));

        // 正常 HTML（不是错误页面）
        $normalHtml = '<html><body><h1>Welcome</h1><p>Normal page content</p></body></html>';
        $this->assertFalse($method->invokeArgs($controller, [$normalHtml]));
    }

    /**
     * 测试测试路由是否正常工作
     */
    public function test_progress_test_route()
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/admin/wiki-maintenance/test-progress');
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Test route works'
        ]);
    }

    protected function tearDown(): void
    {
        // 清理缓存
        Cache::flush();
        parent::tearDown();
    }
}
