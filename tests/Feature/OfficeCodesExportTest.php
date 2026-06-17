<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OFFICE_CODES 全量導出 endpoint（GET /codes/{table}/export）的回歸測試。
 *
 * 採 SQLite in-memory 真表（非 FakeDatabaseManager），以便對串流輸出做逐 byte / round-trip 斷言。
 * 契約見 docs/OFFICE_CODES_EXPORT_SYNC.md §5.2 / §6。
 */
class OfficeCodesExportTest extends TestCase {
    /** 11 欄契約（= config('codes.export_columns.OFFICE_CODES')，順序即下游 .txt 欄序）。 */
    private const EXPORT_COLUMNS = [
        'c_office_id', 'c_dy', 'c_office_pinyin', 'c_office_chn', 'c_office_pinyin_alt',
        'c_office_chn_alt', 'c_office_trans', 'c_office_trans_alt', 'c_source', 'c_pages', 'c_notes',
    ];

    /** index 3（c_office_chn）含內嵌 tab + 雙引號的邊界值，用於驗證 quote-aware round-trip。 */
    private const EDGE_CHN = "提\t舉\"院";

    protected function setUp(): void {
        parent::setUp();

        // OFFICE_CODES 可匯出；ADDR_CODES 在 allowlist 但未配置匯出（負向 404 測試用）。
        config(['codes.tables' => ['OFFICE_CODES' => '官職代碼表', 'ADDR_CODES' => '地址代碼表']]);
        config(['codes.connection' => null]); // 用預設（測試 sqlite）連線
        config(['codes.export_columns' => ['OFFICE_CODES' => self::EXPORT_COLUMNS]]);

        $compiledPath = base_path('tests/storage/views');
        if (!is_dir($compiledPath)) {
            mkdir($compiledPath, 0777, true);
        }
        config(['view.compiled' => $compiledPath]);

        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->smallInteger('c_dy')->nullable();
            $table->string('c_office_pinyin')->nullable();
            $table->string('c_office_chn')->nullable();
            $table->string('c_office_pinyin_alt')->nullable();
            $table->string('c_office_chn_alt')->nullable();
            $table->string('c_office_trans')->nullable();
            $table->string('c_office_trans_alt')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        // 故意亂序插入（7,0,3），以驗證 endpoint 依 c_office_id 升冪輸出。
        // id=7 全欄填滿且互異（c_source 用大整數 1000000），供「完整欄序 + 數值不格式化」斷言。
        DB::table('OFFICE_CODES')->insert([
            ['c_office_id' => 7, 'c_dy' => 15, 'c_office_pinyin' => 'cui kan yuan', 'c_office_chn' => '三司推勘院', 'c_office_pinyin_alt' => 'cui alt', 'c_office_chn_alt' => '別名院', 'c_office_trans' => 'Investigations Office', 'c_office_trans_alt' => 'Inv. alt', 'c_source' => 1000000, 'c_pages' => '卷一', 'c_notes' => '備註'],
            ['c_office_id' => 0, 'c_dy' => 15, 'c_office_pinyin' => null, 'c_office_chn' => '未詳', 'c_office_pinyin_alt' => null, 'c_office_chn_alt' => null, 'c_office_trans' => null, 'c_office_trans_alt' => null, 'c_source' => null, 'c_pages' => null, 'c_notes' => null],
            ['c_office_id' => 3, 'c_dy' => 15, 'c_office_pinyin' => 'ti ju', 'c_office_chn' => self::EDGE_CHN, 'c_office_pinyin_alt' => null, 'c_office_chn_alt' => null, 'c_office_trans' => null, 'c_office_trans_alt' => null, 'c_source' => null, 'c_pages' => null, 'c_notes' => null],
        ]);

        // ADDR_CODES：在 allowlist 但未配置匯出，用於「export 404」與「show 頁不顯示下載連結」反向案例。
        // 需建真表，show 頁才渲染得出（否則查無表會 redirect 而非 200）。
        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name')->nullable();
        });
        DB::table('ADDR_CODES')->insert([['c_addr_id' => 1, 'c_name' => 'somewhere']]);
    }

    protected function tearDown(): void {
        Schema::dropIfExists('OFFICE_CODES');
        Schema::dropIfExists('ADDR_CODES');
        parent::tearDown();
    }

    /**
     * 以 csv.reader 等價方式（str_getcsv，escape='' 對齊 endpoint 的 fputcsv）解析輸出為列陣列。
     *
     * 註：以 explode("\n") 切行，僅在「欄內無嵌入換行」的 fixture 下成立（本測試 fixture 如此）；
     * 若日後 fixture 加入欄內 `\n`，需改用 fgetcsv 逐筆讀以正確處理引號內換行。
     */
    private function parseRows(string $body): array {
        $lines = explode("\n", rtrim($body, "\n"));

        return array_map(fn ($line) => str_getcsv($line, "\t", '"', ''), $lines);
    }

    #[Test]
    public function export_is_eleven_columns_no_header_sorted_no_bom_lf(): void {
        $response = $this->get('/codes/OFFICE_CODES/export');
        $response->assertOk();

        $body = $response->streamedContent();

        // 無 BOM（首 3 byte 非 EF BB BF），首字元為資料。
        $this->assertNotSame("\xEF\xBB\xBF", substr($body, 0, 3));
        $this->assertSame('0', $body[0]);
        // LF、無 CR。
        $this->assertStringNotContainsString("\r", $body);

        $rows = $this->parseRows($body);
        $this->assertCount(3, $rows); // 列數 == 表列數
        foreach ($rows as $row) {
            $this->assertCount(11, $row); // 11 欄
        }

        // 無表頭（首列第 0 欄為資料 '0'，非欄名）；依 c_office_id 升冪。
        $this->assertSame(['0', '3', '7'], array_map(fn ($r) => $r[0], $rows));
    }

    #[Test]
    public function export_quotes_embedded_tab_and_quote_and_roundtrips(): void {
        $rows = $this->parseRows($this->get('/codes/OFFICE_CODES/export')->streamedContent());

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row[0]] = $row;
        }

        // index 3 = c_office_chn，含內嵌 tab + 引號的邊界值須完整還原（證明 quote-aware、非裸 TSV）。
        $this->assertSame(self::EDGE_CHN, $byId['3'][3]);
        // NULL → 空字串（c_office_pinyin = index 2）。
        $this->assertSame('', $byId['0'][2]);
        // 中文正常。
        $this->assertSame('三司推勘院', $byId['7'][3]);
    }

    #[Test]
    public function export_sets_text_plain_and_attachment_headers(): void {
        $response = $this->get('/codes/OFFICE_CODES/export');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('OFFICE_CODES.txt', (string) $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function export_is_public_for_guests(): void {
        // 未登入仍可匯出（對齊已公開的 show；公開唯讀為有意識決策）。
        $this->get('/codes/OFFICE_CODES/export')->assertOk();
    }

    #[Test]
    public function export_404_for_allowlisted_but_not_export_configured_table(): void {
        // ADDR_CODES 通過 guardTable 但不在 export_columns → 404（防泛用 route 被誤用成全表匯出）。
        $this->get('/codes/ADDR_CODES/export')->assertNotFound();
    }

    #[Test]
    public function export_404_for_table_not_in_allowlist(): void {
        $this->get('/codes/NOT_A_REAL_TABLE/export')->assertNotFound();
    }

    #[Test]
    public function export_500_when_config_column_missing_from_live_schema(): void {
        // 模擬 schema 漂移：config 多一個 live 表沒有的欄 → fail-fast 500，不輸出錯位資料。
        config(['codes.export_columns.OFFICE_CODES' => array_merge(self::EXPORT_COLUMNS, ['c_bogus_dropped'])]);

        $this->get('/codes/OFFICE_CODES/export')->assertStatus(500);
    }

    #[Test]
    public function show_page_shows_download_link_for_exportable_table(): void {
        $response = $this->get('/codes/OFFICE_CODES');

        $response->assertOk();
        $response->assertSee('/codes/OFFICE_CODES/export', false);
    }

    #[Test]
    public function show_page_hides_download_link_for_non_exportable_table(): void {
        // ADDR_CODES 在 allowlist 但不在 export_columns → exportable 為 false → 不應出現下載連結。
        $response = $this->get('/codes/ADDR_CODES');

        $response->assertOk();
        $response->assertDontSee('/codes/ADDR_CODES/export', false);
    }

    #[Test]
    public function empty_export_columns_config_is_treated_as_not_exportable(): void {
        // 空陣列（保留設定鍵但不開放匯出）：export 須 404，且 show 頁不顯示下載連結（兩處共用 isExportable，不漂移）。
        config(['codes.export_columns.OFFICE_CODES' => []]);

        $this->get('/codes/OFFICE_CODES/export')->assertNotFound();
        $this->get('/codes/OFFICE_CODES')->assertOk()->assertDontSee('/codes/OFFICE_CODES/export', false);
    }

    #[Test]
    public function export_preserves_full_column_order_and_does_not_format_numbers(): void {
        $rows = $this->parseRows($this->get('/codes/OFFICE_CODES/export')->streamedContent());

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row[0]] = $row;
        }

        // 逐欄鎖定 11 欄輸出順序 = config 順序；c_source=1000000 直出為 '1000000'（無千分位/科學記號）。
        $this->assertSame(
            ['7', '15', 'cui kan yuan', '三司推勘院', 'cui alt', '別名院', 'Investigations Office', 'Inv. alt', '1000000', '卷一', '備註'],
            $byId['7']
        );
    }
}
