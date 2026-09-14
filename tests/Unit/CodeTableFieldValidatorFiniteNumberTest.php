<?php

namespace Tests\Unit;

use App\Support\CodeTableFieldValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `CodeTableFieldValidator` 對非有限浮點（INF／NAN）的拒絕。
 *
 * 為什麼補這條：原本的型別檢查對 `float_fields` 只問 `is_float()`，而長度／值域那幾支
 * 都只看字串，所以 `INF` 與 `NAN` 兩層都穿得過去、一路寫進 double 欄。而且不需要刻意
 * 構造——`json_decode('{"x_coord":1e999}')` 直接就是 `float(INF)`，一個溢位的數字字面值
 * 就夠了。
 *
 * 這與 {@see \App\Support\CoordinatePairNormalizer} 是一組：那一層刻意把非有限值
 * **原樣留下**交給這裡回 422（清成 NULL 會把該報錯的請求變成靜默成功），所以這裡不擋
 * 就沒人擋。{@see \App\Support\CoordinateValidator::reason()} 的讀取端判定早就排除
 * NAN／INF，這條是把寫入端對齊過去。
 */
class CodeTableFieldValidatorFiniteNumberTest extends TestCase {
    /** @return array<string, array<int, string>> */
    private function coordSpec(): array {
        return [
            'float_fields' => ['x_coord', 'y_coord'],
            'integer_fields' => ['c_firstyear'],
        ];
    }

    #[Test]
    public function testRejectsInfinityOnAFloatField(): void {
        $errors = CodeTableFieldValidator::validate(['x_coord' => INF], $this->coordSpec());

        $this->assertSame(['x_coord' => ['x_coord 必須為有限數值']], $errors);
    }

    #[Test]
    public function testRejectsNegativeInfinityOnAFloatField(): void {
        $errors = CodeTableFieldValidator::validate(['y_coord' => -INF], $this->coordSpec());

        $this->assertSame(['y_coord' => ['y_coord 必須為有限數值']], $errors);
    }

    #[Test]
    public function testRejectsNan(): void {
        $errors = CodeTableFieldValidator::validate(['x_coord' => NAN], $this->coordSpec());

        $this->assertSame(['x_coord' => ['x_coord 必須為有限數值']], $errors);
    }

    #[Test]
    public function testRejectsAnOverflowingJsonNumberTheWayAClientActuallySendsIt(): void {
        // 這是實際的抵達方式：呼叫端送的是一個十進位字面值，PHP 的 json_decode 把它
        // 變成 INF，handler 拿到的就已經是浮點了。
        $decoded = json_decode('{"x_coord": 1e999, "y_coord": 40.5}', true);
        $this->assertIsFloat($decoded['x_coord']);
        $this->assertFalse(is_finite($decoded['x_coord']));

        $errors = CodeTableFieldValidator::validate($decoded, $this->coordSpec());

        $this->assertSame(['x_coord' => ['x_coord 必須為有限數值']], $errors);
    }

    #[Test]
    public function testFiniteFloatsAndTheUsualShapesStillPass(): void {
        $errors = CodeTableFieldValidator::validate([
            'x_coord' => 113.11134338,
            'y_coord' => '40.37184906',
            'c_firstyear' => 1368,
        ], $this->coordSpec());

        $this->assertSame([], $errors);
    }

    #[Test]
    public function testNullAndEmptyStringAreStillAcceptedOnNullableNumericFields(): void {
        // 空字串在 normalize() 已被轉成 null；validate() 仍須接受兩者，
        // 否則「清空座標」這件事本身會變成 422。
        $errors = CodeTableFieldValidator::validate(
            ['x_coord' => null, 'y_coord' => ''],
            $this->coordSpec()
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function testNonFiniteIsAlreadyRejectedOnIntegerOnlyFieldsByTheTypeCheck(): void {
        // 純整數欄不需要新分支：INF 是浮點，既有的型別檢查（`is_int()` 為假、
        // 且該欄不在 float_fields）本來就擋下了。鎖住這件事，是為了說清楚新分支
        // 實際只在 float_fields 上發揮作用——而不是讓人以為整數欄還有漏。
        $errors = CodeTableFieldValidator::validate(['c_firstyear' => INF], $this->coordSpec());

        $this->assertSame(['c_firstyear' => ['c_firstyear 必須為字串、整數或 null']], $errors);
    }

    #[Test]
    public function testANonFiniteFloatOnATextFieldStillGetsTheTypeMessage(): void {
        // 未登記為數值欄的欄位走的是既有的型別訊息，不該被新分支搶走。
        $errors = CodeTableFieldValidator::validate(['c_name' => INF], $this->coordSpec());

        $this->assertSame(['c_name' => ['c_name 必須為字串或 null']], $errors);
    }

    #[Test]
    public function testNotNullNumericFieldStillReportsEmptinessFirst(): void {
        // not_null 的判定在型別檢查之前，新分支不該改變那個順序。
        $errors = CodeTableFieldValidator::validate(
            ['c_admin_cat_code' => null],
            ['integer_fields' => ['c_admin_cat_code'], 'not_null_fields' => ['c_admin_cat_code']]
        );

        $this->assertSame(['c_admin_cat_code' => ['c_admin_cat_code 不可為空']], $errors);
    }
}
