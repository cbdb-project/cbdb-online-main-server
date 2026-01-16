<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportTradSimpMapTest extends TestCase {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();

        // 創建測試用的 CBDB__TRAD_SIMP_MAP 表
        if (!Schema::hasTable('CBDB__TRAD_SIMP_MAP')) {
            Schema::create('CBDB__TRAD_SIMP_MAP', function ($table) {
                $table->binary('trad_char', 4)->primary();
                $table->binary('simp_char', 4);
            });
        }
    }

    /**
     * 創建臨時測試文件
     */
    protected function createTempFile(string $content): string {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_opencc_');
        file_put_contents($tempFile, $content);

        return $tempFile;
    }

    /** @test */
    public function it_skips_comment_lines_starting_with_hash(): void {
        // 模擬 OpenCC 文件內容，包含註釋行
        $mockContent = <<<'TXT'
# Open Chinese Convert (OpenCC) Dictionary
# File: TSCharacters.txt
# Format: key	value(s) (values separated by spaces)

乾	干
幹	干
TXT;

        $tempFile = $this->createTempFile($mockContent);

        try {
            Artisan::call('cbdb:import-trad-simp-map', [
                '--truncate' => true,
                '--url' => 'file://' . $tempFile,
            ]);

            // 驗證只導入了數據行，沒有導入註釋行
            $this->assertEquals(2, DB::table('CBDB__TRAD_SIMP_MAP')->count());

            // 驗證具體的數據
            $this->assertDatabaseHas('CBDB__TRAD_SIMP_MAP', [
                'trad_char' => '乾',
                'simp_char' => '干',
            ]);

            $this->assertDatabaseHas('CBDB__TRAD_SIMP_MAP', [
                'trad_char' => '幹',
                'simp_char' => '干',
            ]);
        } finally {
            @unlink($tempFile);
        }
    }

    /** @test */
    public function it_handles_inline_comments(): void {
        // 測試行內註釋（# 後面的內容應被忽略）
        $mockContent = <<<'TXT'
乾	干 # 這是行內註釋
幹	干	# 另一個註釋
TXT;

        $tempFile = $this->createTempFile($mockContent);

        try {
            Artisan::call('cbdb:import-trad-simp-map', [
                '--truncate' => true,
                '--url' => 'file://' . $tempFile,
            ]);

            // 驗證數據正確導入
            $this->assertEquals(2, DB::table('CBDB__TRAD_SIMP_MAP')->count());

            $this->assertDatabaseHas('CBDB__TRAD_SIMP_MAP', [
                'trad_char' => '乾',
                'simp_char' => '干',
            ]);
        } finally {
            @unlink($tempFile);
        }
    }

    /** @test */
    public function it_skips_empty_lines(): void {
        $mockContent = <<<'TXT'

乾	干


幹	干

TXT;

        $tempFile = $this->createTempFile($mockContent);

        try {
            Artisan::call('cbdb:import-trad-simp-map', [
                '--truncate' => true,
                '--url' => 'file://' . $tempFile,
            ]);

            // 驗證只導入了數據行
            $this->assertEquals(2, DB::table('CBDB__TRAD_SIMP_MAP')->count());
        } finally {
            @unlink($tempFile);
        }
    }

    /** @test */
    public function it_skips_lines_with_only_comments(): void {
        $mockContent = <<<'TXT'
# 完整的註釋行
	# Tab 後的註釋
   # 空格後的註釋
乾	干
TXT;

        $tempFile = $this->createTempFile($mockContent);

        try {
            Artisan::call('cbdb:import-trad-simp-map', [
                '--truncate' => true,
                '--url' => 'file://' . $tempFile,
            ]);

            // 只應導入一筆數據
            $this->assertEquals(1, DB::table('CBDB__TRAD_SIMP_MAP')->count());

            $this->assertDatabaseHas('CBDB__TRAD_SIMP_MAP', [
                'trad_char' => '乾',
                'simp_char' => '干',
            ]);
        } finally {
            @unlink($tempFile);
        }
    }
}
