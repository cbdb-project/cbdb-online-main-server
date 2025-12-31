<?php

namespace Tests\Unit;

use App\Services\DatabaseSchemaService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseSchemaServiceTest extends TestCase {
    #[Test]
    public function it_filters_tables_by_whitelist() {
        // Mock config
        Config::set('codes.tables', [
            'DYNASTIES' => 'Dynasties Table',
            'TEST_TABLE' => 'Test Table',
        ]);

        // 使用 partial mock 来避免实际的数据库查询
        $service = \Mockery::mock(DatabaseSchemaService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('getTableSchema')
            ->with('DYNASTIES')
            ->once()
            ->andReturn(['columns' => [], 'primary_keys' => []]);

        $result = $service->getSchemaInfo(['DYNASTIES', 'INVALID_TABLE']);

        $this->assertArrayHasKey('DYNASTIES', $result);
        $this->assertArrayNotHasKey('INVALID_TABLE', $result);
    }

    #[Test]
    public function it_generates_basic_schema_prompt_structure() {
        Config::set('codes.tables', [
            'DYNASTIES' => 'Dynasties Table',
        ]);

        // 使用 mock 避免实际数据库查询
        $service = \Mockery::mock(DatabaseSchemaService::class)->makePartial();
        $service->shouldReceive('getSchemaInfo')
            ->andReturn([
                'DYNASTIES' => [
                    'columns' => [
                        ['name' => 'c_dynasty_id', 'type' => 'int', 'nullable' => false, 'default' => null, 'comment' => '朝代ID'],
                    ],
                    'primary_keys' => ['c_dynasty_id'],
                ],
            ]);

        $prompt = $service->generateSchemaPrompt(['DYNASTIES']);

        $this->assertIsString($prompt);
        $this->assertStringContainsString('DYNASTIES', $prompt);
        $this->assertStringContainsString('表名', $prompt);
        $this->assertStringContainsString('主键', $prompt);
    }

    #[Test]
    public function it_can_clear_cache() {
        $service = new DatabaseSchemaService();

        // 测试清除缓存不会抛出异常
        $service->clearCache('DYNASTIES');
        $service->clearCache();

        $this->assertTrue(true); // 如果没有异常则通过
    }
}
