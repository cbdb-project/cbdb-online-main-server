<?php

namespace Tests\Feature;

use App\Http\Controllers\MergePreviewController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 合併預覽的朝代標籤：中文為空時要退回羅馬字。
 *
 * **為什麼單獨一支**：`buildPersonSummary()` 的兜底原本讀 `$dynasty->c_dynasty_name`，
 * 但 `DYNASTIES` 沒有這個欄（羅馬字欄叫 `c_dynasty`），而 Eloquent 對未知屬性一律回
 * `null` ⇒ 那條兜底等於不存在，且**不會報任何錯**。這種「讀不存在的欄位屬性」是本輪
 * 修的欄位漂移裡最安靜的一種：不像 WHERE 子句會 1054／500，它只是靜默少東西。
 *
 * **掛 `RefreshDatabase`**（不自建合成表）：欄名要來自 `database/migrations`。
 * 自己 CREATE TABLE 就等於自己決定 DYNASTIES 有哪些欄，那正是讓原始 bug 活下來的做法。
 */
class MergePreviewDynastyLabelTest extends TestCase {
    use RefreshDatabase;

    #[Test]
    public function dynasty_label_falls_back_to_romanised_name_when_chinese_is_empty(): void {
        DB::table('DYNASTIES')->insert([
            // 中文留空 ⇒ 走兜底；羅馬字欄名寫錯的話這裡會拿到 null。
            ['c_dy' => 9001, 'c_dynasty' => 'Tang', 'c_dynasty_chn' => ''],
            ['c_dy' => 9002, 'c_dynasty' => 'Song', 'c_dynasty_chn' => '宋'],
        ]);
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 9001001, 'c_name' => 'Fallback', 'c_dy' => 9001],
            ['c_personid' => 9001002, 'c_name' => 'Chinese', 'c_dy' => 9002],
        ]);

        $summary = new ReflectionMethod(MergePreviewController::class, 'buildPersonSummary');
        $summary->setAccessible(true);
        $controller = app(MergePreviewController::class);

        $fallback = $summary->invoke($controller, 9001001);
        $this->assertSame('Tang', $fallback['dynasty_name'], 'c_dynasty_chn 為空時應退回 DYNASTIES.c_dynasty');

        $chinese = $summary->invoke($controller, 9001002);
        $this->assertSame('宋', $chinese['dynasty_name'], '有中文時應優先用中文');
    }
}
