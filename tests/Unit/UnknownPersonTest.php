<?php

namespace Tests\Unit;

use App\Support\UnknownPerson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `UnknownPerson::isUnknown()` 的行為表。
 *
 * 這個判定是四條守衛的共同依據（mutation trait 的對象檢查、Possession／Posting 的擁有者檢查、
 * 提案核准守衛、複製工具的髒列跳過），所以它的邊界必須被逐條鎖住——**兩個方向都會出事**：
 *  - 漏擋（該回 true 卻 false）＝ 髒邊寫進資料庫；
 *  - 誤擋（該回 false 卻 true）＝ 合法資料被永久卡住，而且錯誤訊息指向錯的東西。
 *
 * **這張表的重點是「與寫入時實際發生的轉型一致」**，不是「型別看起來對不對」。曾經有一版改成
 * 「只接受嚴格整數語義」，結果 `'0e10'`／`'0.0'`／`'-999.0'` 被判為**不是**未詳而放行——但它們
 * 寫進 INTEGER 欄之後就是 0 和 -999（PHP `(int)` 與 MariaDB／SQLite 的欄位轉型在此一致，已實測），
 * 等於守衛自己開了後門。下表把那批字串鎖回 true，就是那次退化的回歸鎖。
 */
class UnknownPersonTest extends TestCase {
    /** @return array<string,array{mixed,bool}> */
    public static function values(): array {
        return [
            // ── 確實是「未詳」 ────────────────────────────
            'int 0（落庫後的形式）' => [0, true],
            'int -999（正規化前的形式）' => [-999, true],
            "string '0'（DB 驅動可能回字串）" => ['0', true],
            "string '-999'" => ['-999', true],
            "string ' 0 '（前後空白）" => [' 0 ', true],
            "string '+0'" => ['+0', true],
            'float 0.0' => [0.0, true],
            'float -999.0' => [-999.0, true],

            // ── 不是「未詳」：整數語義但不是哨兵 ──────────
            'int 1' => [1, false],
            'int -1' => [-1, false],
            'int 999' => [999, false],
            "string '1000'" => ['1000', false],

            // ── 確實是「未詳」：數值但非嚴格整數，落庫後仍是哨兵（fail-closed）──
            // 主鍵只驗缺欄不驗型別，所以請求真的送得進這些字串。
            "string '0e10'（科學記號，落庫為 0）" => ['0e10', true],
            "string '0.0'（落庫為 0）" => ['0.0', true],
            "string '-999.0'（落庫為 -999）" => ['-999.0', true],
            'float 0.5（落庫為 0）' => [0.5, true],
            'float -999.9（落庫為 -999）' => [-999.9, true],

            // ── 不是「未詳」：非數值（主鍵不完整／型別錯誤，由別處報錯）──
            'null' => [null, false],
            "空字串" => ['', false],
            "string 'abc'" => ['abc', false],
            "string '0abc'（非數值：(int) 會壓成 0，必須先擋掉）" => ['0abc', false],
            'bool true' => [true, false],
            'bool false' => [false, false],
            'array' => [[0], false],
        ];
    }

    #[Test]
    #[DataProvider('values')]
    public function it_classifies_values(mixed $value, bool $expected): void {
        $this->assertSame($expected, UnknownPerson::isUnknown($value));
    }
}
