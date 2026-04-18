<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 針對 2026_04_18_000000_normalize_assoc_data_empty_text_title migration 的測試。
 *
 * 重點：驗證衝突解決路徑 —— 同 8-key 前綴同時存在 '' 與 '[n/a]' 時，
 * 不會因 9-key 複合主鍵違例而中斷 migration。一般 migrate:fresh 跑的是空 DB，
 * 跑不到這個分支，必須手動鋪資料模擬。
 */
class NormalizeAssocDataEmptyTextTitleMigrationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code');
            $table->integer('c_assoc_id');
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_assoc_kin_code')->default(0);
            $table->integer('c_assoc_kin_id')->default(0);
            $table->string('c_text_title')->default('');
            $table->integer('c_assoc_first_year')->default(-9999);
            $table->integer('c_source')->nullable();
            $table->primary(['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year']);
        });
    }

    protected function runMigration(): void {
        if (!class_exists('NormalizeAssocDataEmptyTextTitle', false)) {
            require_once database_path('migrations/2026_04_18_000000_normalize_assoc_data_empty_text_title.php');
        }
        (new \NormalizeAssocDataEmptyTextTitle())->up();
    }

    #[Test]
    public function migration_resolves_pk_conflict_and_normalizes_empty_text_title(): void {
        DB::table('ASSOC_DATA')->insert([
            // 衝突組：同 8-key 前綴下 '' 與 '[n/a]' 並存
            ['c_personid' => 100, 'c_assoc_code' => 1, 'c_assoc_id' => 2, 'c_assoc_first_year' => -1, 'c_text_title' => '', 'c_source' => 0],
            ['c_personid' => 100, 'c_assoc_code' => 1, 'c_assoc_id' => 2, 'c_assoc_first_year' => -1, 'c_text_title' => '[n/a]', 'c_source' => 9602],
            // 單純的 '' 紀錄
            ['c_personid' => 200, 'c_assoc_code' => 5, 'c_assoc_id' => 6, 'c_assoc_first_year' => 1000, 'c_text_title' => '', 'c_source' => 0],
        ]);

        $this->runMigration();

        // 衝突組：'[n/a]' 版被刪、'' 版更名為 '[n/a]'，保留 peter bol 的較早版本（c_source=0）
        $conflictRows = DB::table('ASSOC_DATA')
            ->where(['c_personid' => 100, 'c_assoc_code' => 1, 'c_assoc_id' => 2, 'c_assoc_first_year' => -1])
            ->get();
        $this->assertCount(1, $conflictRows, '衝突組應合併為一筆');
        $this->assertSame('[n/a]', $conflictRows->first()->c_text_title);
        $this->assertSame(0, (int) $conflictRows->first()->c_source, '應保留較早的 c_source=0 版本');

        // 單純紀錄：正常正規化
        $normalRow = DB::table('ASSOC_DATA')
            ->where(['c_personid' => 200, 'c_assoc_code' => 5])
            ->first();
        $this->assertSame('[n/a]', $normalRow->c_text_title);
    }

    #[Test]
    public function migration_is_idempotent(): void {
        DB::table('ASSOC_DATA')->insert([
            ['c_personid' => 300, 'c_assoc_code' => 1, 'c_assoc_id' => 2, 'c_assoc_first_year' => -1, 'c_text_title' => '[n/a]'],
        ]);

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(1, DB::table('ASSOC_DATA')->count());
    }
}
