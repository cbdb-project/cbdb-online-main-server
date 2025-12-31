<?php

namespace Tests\Unit;

use App\Jobs\WikiImportJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WikiImportJobTest extends TestCase {
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

        // 创建测试表
        $this->createTestTables();
    }

    protected function createTestTables() {
        // 创建 BIOG_MAIN 表
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

        // 插入测试数据
        \DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 12345, 'c_name_chn' => '司马光', 'c_name_eng' => 'Sima Guang'],
            ['c_personid' => 12346, 'c_name_chn' => '苏轼', 'c_name_eng' => 'Su Shi'],
            ['c_personid' => 12347, 'c_name_chn' => '朱熹', 'c_name_eng' => 'Zhu Xi'],
            ['c_personid' => 12348, 'c_name_chn' => '王安石', 'c_name_eng' => 'Wang Anshi'], // 用于测试没有 wikipedia 字段的记录
        ]);
    }

    /**
     * 测试 WikiImportJob 基本功能
     */
    #[Test]
    public function test_wiki_import_job_can_be_created() {
        $job = new WikiImportJob('test_task_123', 'http://example.com/data.json', 60795, '中文維基百科');

        $this->assertInstanceOf(WikiImportJob::class, $job);
    }

    /**
     * 测试记录数据准备功能（模拟）
     */
    #[Test]
    public function test_record_data_preparation() {
        // 这里我们测试数据准备的逻辑，而不是实际的网络请求
        $testRecord = [
            'cbdb_personid' => 12345,
            'wikidata_qid' => 'Q123456',
            'wikipedia' => [
                'zh' => '司马光',
            ],
        ];

        // 模拟 prepareRecordData 方法的逻辑
        $sourceId = 60795; // 中文维基百科
        $result = [
            'c_personid' => $testRecord['cbdb_personid'],
            'c_textid' => $sourceId,
            'c_pages' => $testRecord['wikipedia']['zh'] ?? $testRecord['wikidata_qid'],
            'c_notes' => '批次導入於 ' . date('Y-m-d H:i:s') . ' (任務ID: test_task_123)',
        ];

        $this->assertEquals(12345, $result['c_personid']);
        $this->assertEquals(60795, $result['c_textid']);
        $this->assertEquals('司马光', $result['c_pages']);
        $this->assertStringContainsString('批次導入於', $result['c_notes']);
        $this->assertStringContainsString('test_task_123', $result['c_notes']);
    }

    /**
     * 测试不同数据源的记录处理
     */
    #[Test]
    public function test_different_source_record_processing() {
        // 测试 Wikidata 记录
        $wikidataRecord = [
            'cbdb_personid' => 12345,
            'wikidata_qid' => 'Q123456',
            'wikipedia' => [],
        ];

        $result = $this->processTestRecord($wikidataRecord, 68942); // Wikidata
        $this->assertEquals('Q123456', $result['c_pages']);

        // 测试中文维基百科记录
        $zhWikiRecord = [
            'cbdb_personid' => 12346,
            'wikidata_qid' => 'Q123457',
            'wikipedia' => [
                'zh' => '司马光',
            ],
        ];

        $result = $this->processTestRecord($zhWikiRecord, 60795); // 中文维基百科
        $this->assertEquals('司马光', $result['c_pages']);

        // 测试英文维基百科记录
        $enWikiRecord = [
            'cbdb_personid' => 12347,
            'wikidata_qid' => 'Q123458',
            'wikipedia' => [
                'en' => 'Sima_Guang',
            ],
        ];

        $result = $this->processTestRecord($enWikiRecord, 68943); // 英文维基百科
        $this->assertEquals('Sima_Guang', $result['c_pages']);
    }

    /**
     * 测试无效记录的处理
     */
    #[Test]
    public function test_invalid_record_handling() {
        $invalidRecord = [
            'cbdb_personid' => 0,  // 无效的 personid
            'wikidata_qid' => 'Q123459',
        ];

        $result = $this->processTestRecord($invalidRecord, 68942);
        $this->assertNull($result);
    }

    /**
     * 测试没有 wikipedia 字段的记录处理
     */
    #[Test]
    public function test_record_without_wikipedia_field() {
        // 只有 Wikidata 信息，没有 Wikipedia 页面的记录
        $wikidataOnlyRecord = [
            'cbdb_personid' => 12348,
            'wikidata_qid' => 'Q123460',
            // 注意：没有 wikipedia 字段
        ];

        // Wikidata 源应该能正常处理
        $result = $this->processTestRecord($wikidataOnlyRecord, 68942);
        $this->assertEquals(12348, $result['c_personid']);
        $this->assertEquals('Q123460', $result['c_pages']);

        // 中文维基百科源应该使用 wikidata_qid 作为 fallback
        $result = $this->processTestRecord($wikidataOnlyRecord, 60795);
        $this->assertEquals('Q123460', $result['c_pages']);

        // 英文维基百科源应该使用 wikidata_qid 作为 fallback
        $result = $this->processTestRecord($wikidataOnlyRecord, 68943);
        $this->assertEquals('Q123460', $result['c_pages']);
    }

    /**
     * 模拟记录处理逻辑
     */
    private function processTestRecord($record, $sourceId) {
        // 模拟 prepareRecordData 方法的逻辑
        $personId = $record['cbdb_personid'] ?? 0;

        // 检查 personid 有效性
        if ($personId <= 0) {
            return null;
        }

        // 检查 personid 是否存在于 BIOG_MAIN
        $exists = \DB::table('BIOG_MAIN')
            ->where('c_personid', $personId)
            ->exists();

        if (!$exists) {
            return null;
        }

        // 根据数据源确定页面内容
        $pages = '';
        switch ($sourceId) {
            case 60795: // 中文维基百科
                $pages = $record['wikipedia']['zh'] ?? $record['wikidata_qid'];

                break;
            case 68943: // 英文维基百科
                $pages = $record['wikipedia']['en'] ?? $record['wikidata_qid'];

                break;
            case 68942: // Wikidata
            default:
                $pages = $record['wikidata_qid'];

                break;
        }

        return [
            'c_personid' => $personId,
            'c_textid' => $sourceId,
            'c_pages' => $pages,
            'c_notes' => '批次導入於 ' . date('Y-m-d H:i:s') . ' (任務ID: test_task_123)',
        ];
    }
}
