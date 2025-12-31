<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WikiMaintenanceControllerTest extends TestCase {
    protected $user;

    protected function setUp(): void {
        parent::setUp();

        // 使用 in-memory SQLite 数据库
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 设置缓存为数组驱动
        config(['cache.default' => 'array']);

        // 使用数组驱动避免文件权限问题
        config(['session.driver' => 'array']);

        // 创建必要的表结构
        $this->createTestTables();

        // 创建测试用户
        $this->user = factory(User::class)->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_active' => 1,
            'is_admin' => 1,
            'confirmation_token' => 'test_token_' . time(),
        ]);
    }

    protected function createTestTables() {
        // 创建 users 表
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->json('settings')->nullable();
            $table->string('avatar')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        // 创建 BIOG_MAIN 表（简化版本）
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn', 50)->nullable();
            $table->string('c_name_eng', 50)->nullable();
            $table->timestamps();
        });

        // 创建 BIOG_SOURCE_DATA 表
        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->text('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->timestamps();
            $table->primary(['c_personid', 'c_textid']);
        });

        // 创建 TEXT_CODES 表
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title_chn', 255)->nullable();
            $table->string('c_url_api', 500)->nullable();
            $table->string('c_url_api_coda', 100)->nullable();
            $table->timestamps();
        });

        // 插入测试数据
        \DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 12345, 'c_name_chn' => '司马光', 'c_name_eng' => 'Sima Guang'],
            ['c_personid' => 12346, 'c_name_chn' => '苏轼', 'c_name_eng' => 'Su Shi'],
            ['c_personid' => 12347, 'c_name_chn' => '朱熹', 'c_name_eng' => 'Zhu Xi'],
        ]);

        // 插入 TEXT_CODES 测试数据
        \DB::table('TEXT_CODES')->insert([
            [
                'c_textid' => 60795,
                'c_title_chn' => '中文維基百科 (Wikipedia)',
                'c_url_api' => 'https://zh.wikipedia.org/wiki/',
                'c_url_api_coda' => '',
            ],
            [
                'c_textid' => 68942,
                'c_title_chn' => '維基數據 (Wikidata)',
                'c_url_api' => 'https://www.wikidata.org/wiki/',
                'c_url_api_coda' => '',
            ],
            [
                'c_textid' => 68943,
                'c_title_chn' => '英文維基百科 (Wikipedia)',
                'c_url_api' => 'https://en.wikipedia.org/wiki/',
                'c_url_api_coda' => '',
            ],
        ]);

        // 插入一些 BIOG_SOURCE_DATA 测试数据
        \DB::table('BIOG_SOURCE_DATA')->insert([
            ['c_personid' => 12345, 'c_textid' => 60795, 'c_pages' => '司马光', 'c_notes' => 'Test note 1'],
            ['c_personid' => 12346, 'c_textid' => 68942, 'c_pages' => 'Q123456', 'c_notes' => 'Test note 2'],
            ['c_personid' => 12347, 'c_textid' => 68943, 'c_pages' => 'Zhu_Xi', 'c_notes' => 'Test note 3'],
        ]);
    }

    /**
     * 测试未认证用户不能访问 Wiki 维护页面
     */
    #[Test]
    public function test_unauthenticated_user_cannot_access_wiki_maintenance() {
        $response = $this->get('/admin/wiki-maintenance');
        $response->assertRedirect('/login');
    }

    /**
     * 测试认证用户可以访问 Wiki 维护页面
     */
    #[Test]
    public function test_authenticated_user_can_access_wiki_maintenance() {
        $this->actingAs($this->user);

        $response = $this->get('/admin/wiki-maintenance');
        $response->assertStatus(200);
        $response->assertViewIs('admin.wiki-maintenance');
        $response->assertSee('Wiki 對照資料維護');
    }

    /**
     * 测试页面显示正确的数据源选项
     */
    #[Test]
    public function test_wiki_maintenance_shows_correct_source_options() {
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
    #[Test]
    public function test_delete_all_records_validation() {
        $this->actingAs($this->user);

        $response = $this->post('/admin/wiki-maintenance/delete-all', [
            'source_id' => 99999,  // 无效的 source_id
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    /**
     * 测试 URL 导入功能的输入验证
     */
    #[Test]
    public function test_import_url_validation() {
        $this->actingAs($this->user);

        // 测试缺少 URL
        $response = $this->postJson('/admin/wiki-maintenance/import-url', [
            'target_source' => 60795,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['import_url']);

        // 测试无效 URL
        $response = $this->postJson('/admin/wiki-maintenance/import-url', [
            'import_url' => 'not-a-url',
            'target_source' => 60795,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['import_url']);

        // 测试无效数据源
        $response = $this->postJson('/admin/wiki-maintenance/import-url', [
            'import_url' => 'https://example.com/data.json',
            'target_source' => 99999,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['target_source']);
    }

    /**
     * 测试有效的 URL 导入请求返回正确响应
     */
    #[Test]
    public function test_import_url_returns_success_response() {
        $this->actingAs($this->user);

        $response = $this->postJson('/admin/wiki-maintenance/import-url', [
            'import_url' => 'https://example.com/data.json',
            'target_source' => 60795,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => '導入任務已開始',
        ]);
        $response->assertJsonStructure([
            'success',
            'message',
            'task_id',
        ]);
    }

    /**
     * 测试进度查询功能 - 测试不存在的任务返回404
     */
    #[Test]
    public function test_get_import_progress_not_found() {
        $this->actingAs($this->user);

        $taskId = 'nonexistent_task';
        $response = $this->getJson("/admin/wiki-maintenance/progress/{$taskId}");
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => '找不到指定的任務',
        ]);
    }

    protected function tearDown(): void {
        parent::tearDown();
    }
}
