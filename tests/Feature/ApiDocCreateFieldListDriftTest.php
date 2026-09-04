<?php

namespace Tests\Feature;

use App\Services\Mutations\BiogMainCreateHandler;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * `API.md` 公布的人物主檔 create 可寫欄位，必須與 handler 白名單逐字一致。
 *
 * **為什麼需要**：`API.md` 是**唯一**對外的 API 文檔，外部協作者與眾包提交者照它實作。
 * 那份欄位清單是手打的 Markdown，與 `BiogMainCreateHandler::ALLOWED_FIELDS` 之間沒有任何
 * 機制保證同步，而兩種漂移方向都會咬人：
 *  - 文檔列了程式沒有的欄 ⇒ 客戶端照著送，被 `array_intersect_key()` **靜默丟棄**，
 *    回 200 但資料沒進去（`c_by_yymm` 那四個幻影欄就曾這樣被公布為對外契約）；
 *  - 程式收得下、文檔沒列 ⇒ 呼叫端根本不知道可以送，白白多跑一趟 `mutate`
 *    （生卒年月日六欄就是這樣被寫成「請 create 後再 mutate 補寫」）。
 *
 * 這支測試就是把那份清單釘死：**改白名單就必須同步改 API.md，否則紅**
 * （AGENTS.md「文檔維護原則」要求 API 改動與 `API.md` 同一個 commit）。
 * 比對含**順序**，兩邊都以物理／語意分組排列，順序不同通常代表其中一邊是手插進去的。
 */
class ApiDocCreateFieldListDriftTest extends TestCase {
    /** API.md 裡那一行的開頭；改標題文字時這裡要跟著改（找不到就紅，不會靜默略過）。 */
    private const LINE_PREFIX = '- **create**（只支援 `direct`）可寫欄位：';

    #[Test]
    public function api_md_lists_exactly_the_create_whitelist(): void {
        $documented = $this->documentedFields();
        $declared = (array) (new ReflectionClass(BiogMainCreateHandler::class))->getConstant('ALLOWED_FIELDS');

        $this->assertSame(
            $declared,
            $documented,
            'API.md 的 create 可寫欄位清單與 BiogMainCreateHandler::ALLOWED_FIELDS 不一致。'
                ."\n改白名單時要同步改 API.md（順序也要一致）——文檔多列的欄位送出去會被靜默丟棄，"
                .'少列的欄位則沒人知道可以送。'
        );
    }

    /**
     * @return array<int, string>
     */
    private function documentedFields(): array {
        $path = base_path('API.md');
        $this->assertFileExists($path);

        $matched = null;
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, self::LINE_PREFIX)) {
                $this->assertNull($matched, 'API.md 出現多行 create 可寫欄位清單，比對對象不唯一');
                $matched = $line;
            }
        }

        $this->assertNotNull(
            $matched,
            'API.md 找不到 create 可寫欄位那一行（前綴「'.self::LINE_PREFIX.'」）——'
                .'文案改了就要同步改這支測試的 LINE_PREFIX，不可讓比對靜默失效'
        );

        // 只取「：」之後的部分，避免把前綴裡的 `direct` 也當成欄位。
        $listing = substr($matched, strlen(self::LINE_PREFIX));
        preg_match_all('/`([^`]+)`/', $listing, $matches);

        return $matches[1];
    }
}
