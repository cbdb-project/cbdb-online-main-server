<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GenerateSchemaDocsTest extends TestCase {
    protected string $tempOutputPath;

    protected function setUp(): void {
        parent::setUp();
        $this->tempOutputPath = base_path('tests/temp_schema_' . uniqid() . '.md');

        // 檢查 SQLite 是否可用
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite 擴展未安裝');
        }
    }

    protected function tearDown(): void {
        if (File::exists($this->tempOutputPath)) {
            File::delete($this->tempOutputPath);
        }
        parent::tearDown();
    }

    /** @test */
    public function it_can_generate_sqlite_schema_documentation() {
        // 運行命令生成文檔
        Artisan::call('cbdb:generate-schema-docs', [
            '--output' => basename($this->tempOutputPath),
        ]);

        // 驗證文件已生成
        $this->assertFileExists($this->tempOutputPath);

        // 讀取生成的文檔
        $content = File::get($this->tempOutputPath);

        // 驗證基本結構
        $this->assertStringContainsString('# 數據庫 Schema 文檔', $content);
        $this->assertStringContainsString('## SQLite Schema', $content);

        // 驗證核心表存在
        $this->assertStringContainsString('### users', $content);
        $this->assertStringContainsString('### operations', $content);
        $this->assertStringContainsString('### BIOG_MAIN', $content);
    }

    /** @test */
    public function it_includes_table_columns_and_types() {
        Artisan::call('cbdb:generate-schema-docs', [
            '--output' => basename($this->tempOutputPath),
        ]);

        $content = File::get($this->tempOutputPath);

        // 驗證 users 表的列信息
        $this->assertStringContainsString('### users', $content);
        $this->assertStringContainsString('email', $content);
        $this->assertStringContainsString('password', $content);
    }

    /** @test */
    public function it_marks_views_differently_from_tables() {
        Artisan::call('cbdb:generate-schema-docs', [
            '--output' => basename($this->tempOutputPath),
        ]);

        $content = File::get($this->tempOutputPath);

        // 驗證視圖標記
        if (str_contains($content, 'View_BiogInstData')) {
            $this->assertStringContainsString('（視圖）', $content);
        }
    }

    /** @test */
    public function it_shows_primary_keys() {
        Artisan::call('cbdb:generate-schema-docs', [
            '--output' => basename($this->tempOutputPath),
        ]);

        $content = File::get($this->tempOutputPath);

        // 驗證主鍵信息
        $this->assertStringContainsString('**主鍵**', $content);
    }

    /** @test */
    public function it_can_handle_custom_output_path() {
        $customPath = 'tests/custom_schema_output_' . uniqid() . '.md';

        try {
            Artisan::call('cbdb:generate-schema-docs', [
                '--output' => $customPath,
            ]);

            $this->assertFileExists(base_path($customPath));
        } finally {
            if (File::exists(base_path($customPath))) {
                File::delete(base_path($customPath));
            }
        }
    }
}
