<?php

namespace Tests\Unit;

use App\Services\DatabaseSchemaService;
use App\Services\Mcp\ReadOnlyTableQueryService;
use App\Services\NlQueryToolsService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NlQueryToolsServiceTest extends TestCase {
    // NOTE: Do not use RefreshDatabase here; full migrations on SQLite
    // can fail due to foreign key mismatch constraints.

    protected NlQueryToolsService $service;

    protected function setUp(): void {
        parent::setUp();
        $schemaService = new DatabaseSchemaService();
        $this->service = new NlQueryToolsService($schemaService);

        // 禁用外键约束检查（SQLite）
        DB::statement('PRAGMA foreign_keys = OFF');

        // 設定测试白名单
        Config::set('codes.tables', [
            'DYNASTIES' => '朝代表',
            'BIOG_MAIN' => '人物主表',
        ]);
    }

    /** @test */
    public function it_can_get_sample_data_for_allowed_table(): void {
        // 创建测试表
        DB::statement('DROP TABLE IF EXISTS DYNASTIES');
        DB::statement('CREATE TABLE DYNASTIES (c_dy VARCHAR(255), c_dynasty_chn VARCHAR(255))');

        // 插入测试数据
        DB::table('DYNASTIES')->insert([
            ['c_dy' => 'Tang', 'c_dynasty_chn' => '唐'],
            ['c_dy' => 'Song', 'c_dynasty_chn' => '宋'],
            ['c_dy' => 'Ming', 'c_dynasty_chn' => '明'],
        ]);

        $result = $this->service->getSampleDataForTable('DYNASTIES', 2);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertIsArray($result['data']);
        $this->assertCount(2, $result['data']);
        $this->assertEquals('Tang', $result['data'][0]['c_dy']);
        $this->assertEquals('Song', $result['data'][1]['c_dy']);
    }

    /** @test */
    public function it_rejects_table_not_in_whitelist(): void {
        $result = $this->service->getSampleDataForTable('users', 10);

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertStringContainsString('不在允許的表格清單中', $result['error']);
    }

    /** @test */
    public function it_handles_non_existent_table(): void {
        // 确保表不存在
        DB::statement('DROP TABLE IF EXISTS BIOG_MAIN');

        // 表在白名单中但实际不存在
        $result = $this->service->getSampleDataForTable('BIOG_MAIN', 10);

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertNotNull($result['error']);
    }

    /** @test */
    public function it_respects_limit_parameter(): void {
        // 创建测试表
        DB::statement('DROP TABLE IF EXISTS DYNASTIES');
        DB::statement('CREATE TABLE DYNASTIES (c_dy VARCHAR(255))');

        // 插入 20 条测试数据
        for ($i = 1; $i <= 20; $i++) {
            DB::table('DYNASTIES')->insert(['c_dy' => "Dynasty{$i}"]);
        }

        // 请求 5 条
        $result = $this->service->getSampleDataForTable('DYNASTIES', 5);

        $this->assertTrue($result['success']);
        $this->assertCount(5, $result['data']);
    }

    /** @test */
    public function it_can_execute_get_sample_data_tool(): void {
        // 创建测试表
        DB::statement('DROP TABLE IF EXISTS DYNASTIES');
        DB::statement('CREATE TABLE DYNASTIES (c_dy VARCHAR(255))');
        DB::table('DYNASTIES')->insert(['c_dy' => 'Tang']);

        $result = $this->service->executeTool('get_sample_data_for_table', [
            'table_name' => 'DYNASTIES',
            'limit' => 10,
        ]);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['data']);
    }

    /** @test */
    public function it_handles_unknown_tool(): void {
        $result = $this->service->executeTool('unknown_tool', []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('未知的工具', $result['error']);
    }

    /** @test */
    public function it_returns_tool_definitions(): void {
        Config::set('nl_query_tools.tools', [
            [
                'name' => 'get_sample_data_for_table',
                'description' => 'Get sample data',
            ],
        ]);

        $definitions = $this->service->getToolDefinitions();

        $this->assertIsArray($definitions);
        $this->assertCount(1, $definitions);
        $this->assertEquals('get_sample_data_for_table', $definitions[0]['name']);
    }

    /** @test */
    public function it_handles_empty_table(): void {
        // 创建空表
        DB::statement('DROP TABLE IF EXISTS DYNASTIES');
        DB::statement('CREATE TABLE DYNASTIES (c_dy VARCHAR(255))');

        $result = $this->service->getSampleDataForTable('DYNASTIES', 10);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['data']);
        $this->assertCount(0, $result['data']);
    }

    /** @test */
    public function it_is_case_insensitive_for_table_names(): void {
        // 创建测试表
        DB::statement('DROP TABLE IF EXISTS DYNASTIES');
        DB::statement('CREATE TABLE DYNASTIES (c_dy VARCHAR(255))');
        DB::table('DYNASTIES')->insert(['c_dy' => 'Tang']);

        // 使用小写表名
        $result = $this->service->getSampleDataForTable('dynasties', 10);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['data']);
    }

    /** @test */
    public function it_can_execute_list_allowed_tables_tool(): void {
        $readOnlyService = $this->createMock(ReadOnlyTableQueryService::class);
        $readOnlyService->method('listAllowedTables')
            ->willReturn(['DYNASTIES', 'BIOG_MAIN']);

        $service = new NlQueryToolsService(new DatabaseSchemaService(), $readOnlyService);
        $result = $service->executeTool('list_allowed_tables', []);

        $this->assertTrue($result['success']);
        $this->assertEquals(['DYNASTIES', 'BIOG_MAIN'], $result['data']);
    }

    /** @test */
    public function it_can_execute_query_table_schema_tool(): void {
        $readOnlyService = $this->createMock(ReadOnlyTableQueryService::class);
        $readOnlyService->method('queryTableSchema')
            ->with('DYNASTIES')
            ->willReturn([
                'table_name' => 'DYNASTIES',
                'columns' => [],
                'indexes' => [],
                'foreign_keys' => [],
                'table_info' => [],
            ]);

        $service = new NlQueryToolsService(new DatabaseSchemaService(), $readOnlyService);
        $result = $service->executeTool('query_table_schema', [
            'table_name' => 'DYNASTIES',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('DYNASTIES', $result['data']['table_name']);
    }

    /** @test */
    public function it_can_execute_query_read_only_sql_tool(): void {
        $readOnlyService = $this->createMock(ReadOnlyTableQueryService::class);
        $readOnlyService->method('queryReadOnlySql')
            ->with('SELECT * FROM DYNASTIES', 5, 0)
            ->willReturn([
                'sql' => 'SELECT * FROM DYNASTIES',
                'rows' => [['c_dy' => 1]],
                'returned_rows' => 1,
            ]);

        $service = new NlQueryToolsService(new DatabaseSchemaService(), $readOnlyService);
        $result = $service->executeTool('query_read_only_sql', [
            'sql' => 'SELECT * FROM DYNASTIES',
            'limit' => 5,
            'offset' => 0,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['data']['returned_rows']);
    }
}
