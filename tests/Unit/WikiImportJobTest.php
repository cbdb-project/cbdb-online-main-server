<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Jobs\WikiImportJob;
use App\Http\Controllers\WikiMaintenanceController;
use Mockery;

class WikiImportJobTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 测试 WikiImportJob 的基本属性
     */
    public function test_wiki_import_job_properties()
    {
        $taskId = 'test_task_123';
        $url = 'https://example.com/data.json';
        $targetSourceId = 60795;
        $sourceName = '中文維基百科 (Wikipedia)';

        $job = new WikiImportJob($taskId, $url, $targetSourceId, $sourceName);

        // 使用反射访问私有属性
        $reflection = new \ReflectionClass($job);

        $taskIdProperty = $reflection->getProperty('taskId');
        $taskIdProperty->setAccessible(true);
        $this->assertEquals($taskId, $taskIdProperty->getValue($job));

        $urlProperty = $reflection->getProperty('url');
        $urlProperty->setAccessible(true);
        $this->assertEquals($url, $urlProperty->getValue($job));

        $targetSourceIdProperty = $reflection->getProperty('targetSourceId');
        $targetSourceIdProperty->setAccessible(true);
        $this->assertEquals($targetSourceId, $targetSourceIdProperty->getValue($job));

        $sourceNameProperty = $reflection->getProperty('sourceName');
        $sourceNameProperty->setAccessible(true);
        $this->assertEquals($sourceName, $sourceNameProperty->getValue($job));
    }

    /**
     * 测试 WikiImportJob 实现了正确的接口
     */
    public function test_wiki_import_job_implements_should_queue()
    {
        $job = new WikiImportJob('test', 'http://example.com', 60795, 'Test');
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
    }

    /**
     * 测试 WikiImportJob 使用了正确的 traits
     */
    public function test_wiki_import_job_uses_correct_traits()
    {
        $job = new WikiImportJob('test', 'http://example.com', 60795, 'Test');
        $traits = class_uses($job);

        $expectedTraits = [
            'Illuminate\Bus\Queueable',
            'Illuminate\Queue\SerializesModels',
            'Illuminate\Queue\InteractsWithQueue',
            'Illuminate\Foundation\Bus\Dispatchable'
        ];

        foreach ($expectedTraits as $trait) {
            $this->assertContains($trait, $traits);
        }
    }

    /**
     * 测试 Job 可以被序列化和反序列化
     */
    public function test_job_serialization()
    {
        $taskId = 'test_task_serialize';
        $url = 'https://example.com/test.json';
        $targetSourceId = 68942;
        $sourceName = 'Test Source';

        $job = new WikiImportJob($taskId, $url, $targetSourceId, $sourceName);

        // 序列化
        $serialized = serialize($job);
        $this->assertTrue(is_string($serialized));

        // 反序列化
        $unserialized = unserialize($serialized);
        $this->assertInstanceOf(WikiImportJob::class, $unserialized);

        // 验证属性保持不变
        $reflection = new \ReflectionClass($unserialized);

        $taskIdProperty = $reflection->getProperty('taskId');
        $taskIdProperty->setAccessible(true);
        $this->assertEquals($taskId, $taskIdProperty->getValue($unserialized));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
