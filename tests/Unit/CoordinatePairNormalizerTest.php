<?php

namespace Tests\Unit;

use App\Support\CoordinatePairNormalizer as Normalizer;
use App\Support\CoordinateValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 經緯度空白／零值 → NULL 的正規化（{@see Normalizer}）。
 *
 * 這支測試刻意連「不該動的情形」一起鎖住：這一層的風險不在漏清，而在**多清**——
 * 逐欄更新沒提到座標時把座標動掉、或把該回 422 的爛輸入靜默清成 NULL，兩者都是
 * 比原本的 0,0 更糟的結果。
 */
class CoordinatePairNormalizerTest extends TestCase {
    // ── 會清空的情形 ────────────────────────────────────────

    /**
     * @param mixed $x
     * @param mixed $y
     */
    #[Test]
    #[DataProvider('zeroOrBlankPairs')]
    public function testClearsBothAxesOfAZeroOrBlankPair($x, $y, string $expectedReason): void {
        $result = Normalizer::normalizeRow(['x_coord' => $x, 'y_coord' => $y], 'ADDR_CODES');

        $this->assertNull($result['data']['x_coord']);
        $this->assertNull($result['data']['y_coord']);
        $this->assertSame($expectedReason, $result['cleared']['x_coord'] ?? null);
        $this->assertSame($expectedReason, $result['cleared']['y_coord'] ?? null);
    }

    /** @return array<string, array{0: mixed, 1: mixed, 2: string}> */
    public static function zeroOrBlankPairs(): array {
        return [
            'int zero' => [0, 0, Normalizer::REASON_ZERO],
            'float zero' => [0.0, 0.0, Normalizer::REASON_ZERO],
            'string zero' => ['0', '0', Normalizer::REASON_ZERO],
            // 「0.00*」：使用者／匯入端最常見的寫法，落到 double 就是精確的 0。
            'decimal zeros' => ['0.00000', '0.0000', Normalizer::REASON_ZERO],
            'signed zero' => ['-0', '-0.0', Normalizer::REASON_ZERO],
            'negative float zero' => [-0.0, -0.0, Normalizer::REASON_ZERO],
            'many decimal places' => ['0.000000000000', '0.0', Normalizer::REASON_ZERO],
            // 空字串在非 strict sql_mode 下會被 MariaDB 靜默轉成 0（已對真實庫實測），
            // 所以這一層不能依賴上游的 ConvertEmptyStringsToNull／validator 接住它。
            'empty strings' => ['', '', Normalizer::REASON_BLANK],
            'whitespace only' => ['  ', "\t", Normalizer::REASON_BLANK],
            // 帶 null 的情形刻意不列在這裡：已經是 null 的送來欄位不算「改動」、不進
            // `cleared`，由 testAlreadyNullColumnsAreNotReportedAsCleared() 與
            // testAPartlyNullPairOnlyReportsTheColumnThatChanged() 分別鎖住。
        ];
    }

    #[Test]
    public function testASingleZeroAxisClearsThePartnerToo(): void {
        // 只有一軸有值的座標同樣不可用，所以整對清空。
        $result = Normalizer::normalizeRow(['x_coord' => 105.36354, 'y_coord' => 0], 'ADDR_CODES');

        $this->assertNull($result['data']['x_coord']);
        $this->assertNull($result['data']['y_coord']);
    }

    #[Test]
    public function testReportsWhyEachColumnWasClearedSeparately(): void {
        // 逐欄原因是這個通知唯一有用的部分：使用者留空的是緯度，被丟掉的是他打的經度。
        // 對兩欄都回報「blank」等於告訴他「你把經度留空了」——他明明打了 105.36354。
        $result = Normalizer::normalizeRow(['x_coord' => 105.36354, 'y_coord' => ''], 'ADDR_CODES');

        $this->assertSame([
            'x_coord' => Normalizer::REASON_PARTNER,
            'y_coord' => Normalizer::REASON_BLANK,
        ], $result['cleared']);
    }

    #[Test]
    public function testReportsZeroAndPartnerSeparately(): void {
        $result = Normalizer::normalizeRow(['x_coord' => 113.11, 'y_coord' => 0], 'ADDR_CODES');

        $this->assertSame([
            'x_coord' => Normalizer::REASON_PARTNER,
            'y_coord' => Normalizer::REASON_ZERO,
        ], $result['cleared']);
    }

    #[Test]
    public function testClearsThePartnerColumnEvenWhenItWasNotSubmitted(): void {
        // 代碼表 update 是逐欄的，所以只送一欄是合法的。送 x=0 卻不送 y 時，補寫
        // y=null 是這條規則的重點——否則會留下 `NULL, 40.37` 這種半截列。
        // 補寫的伙伴欄一律回報 REASON_PARTNER：這一層看不到資料庫，不能假設它原本是 NULL，
        // 而在「可能沒變」與「可能靜默刪掉一個真值」之間寧可多講一句。
        $result = Normalizer::normalizeRow(['x_coord' => '0.000'], 'ADDR_CODES');

        $this->assertArrayHasKey('y_coord', $result['data']);
        $this->assertNull($result['data']['x_coord']);
        $this->assertNull($result['data']['y_coord']);
        $this->assertSame([
            'x_coord' => Normalizer::REASON_ZERO,
            'y_coord' => Normalizer::REASON_PARTNER,
        ], $result['cleared']);
    }

    #[Test]
    public function testAlreadyNullColumnsAreNotReportedAsCleared(): void {
        // 沒有改動就沒有要通知的事；否則每一次「本來就沒座標」的更新都會冒出通知。
        $result = Normalizer::normalizeRow(['x_coord' => null, 'y_coord' => null], 'ADDR_CODES');

        $this->assertSame([], $result['cleared']);
        $this->assertNull($result['data']['x_coord']);
        $this->assertNull($result['data']['y_coord']);
    }

    #[Test]
    public function testAPartlyNullPairOnlyReportsTheColumnThatChanged(): void {
        // x 本來就是 null（沒有改動），y 是使用者留空的——只有 y 該進通知。
        $result = Normalizer::normalizeRow(['x_coord' => null, 'y_coord' => ''], 'ADDR_CODES');

        $this->assertNull($result['data']['x_coord']);
        $this->assertNull($result['data']['y_coord']);
        $this->assertSame(['y_coord' => Normalizer::REASON_BLANK], $result['cleared']);
    }

    // ── 大小寫：同一欄的多種拼法 ─────────────────────────────

    #[Test]
    public function testMatchesColumnNamesCaseInsensitivelyAndKeepsTheSubmittedSpelling(): void {
        $result = Normalizer::normalizeRow(['X_COORD' => 0, 'Y_Coord' => 0], 'addr_codes');

        $this->assertNull($result['data']['X_COORD']);
        $this->assertNull($result['data']['Y_Coord']);
        $this->assertArrayNotHasKey('x_coord', $result['data']);
        $this->assertSame(Normalizer::REASON_ZERO, $result['cleared']['X_COORD']);
        $this->assertSame(Normalizer::REASON_ZERO, $result['cleared']['Y_Coord']);
    }

    #[Test]
    public function testClearsEverySpellingOfTheSameColumn(): void {
        // MySQL 欄名大小寫不敏感，而 CodesController::extractFormData() 是
        // `$request->all()` 去掉三個鍵、沒有欄位白名單——所以 `x_coord=0&X_COORD=113.5`
        // 兩個鍵都會進 SET 子句。只檢查其中一個的話，另一個會帶著值原樣落庫，
        // 這個類的目的等於被繞過。
        $result = Normalizer::normalizeRow([
            'x_coord' => 0,
            'X_COORD' => 113.5,
            'y_coord' => 40.3,
        ], 'ADDR_CODES');

        $this->assertNull($result['data']['x_coord']);
        $this->assertNull($result['data']['X_COORD']);
        $this->assertNull($result['data']['y_coord']);
        $this->assertSame(Normalizer::REASON_ZERO, $result['cleared']['x_coord']);
        $this->assertSame(Normalizer::REASON_PARTNER, $result['cleared']['X_COORD']);
        $this->assertSame(Normalizer::REASON_PARTNER, $result['cleared']['y_coord']);
    }

    #[Test]
    public function testASecondSpellingCannotSmuggleAZeroPast(): void {
        // 反向：第一個拼法是好值、第二個是零。整對仍然要清空。
        $result = Normalizer::normalizeRow([
            'x_coord' => 113.5,
            'X_COORD' => 0,
            'y_coord' => 40.3,
        ], 'ADDR_CODES');

        $this->assertNull($result['data']['x_coord']);
        $this->assertNull($result['data']['X_COORD']);
        $this->assertNull($result['data']['y_coord']);
        $this->assertSame(Normalizer::REASON_ZERO, $result['cleared']['X_COORD']);
    }

    // ── 不該動的情形 ────────────────────────────────────────

    #[Test]
    public function testLeavesAValidCoordinatePairAlone(): void {
        $row = ['x_coord' => 113.11134338, 'y_coord' => 40.37184906];
        $result = Normalizer::normalizeRow($row, 'ADDR_CODES');

        $this->assertSame($row, $result['data']);
        $this->assertSame([], $result['cleared']);
    }

    #[Test]
    public function testDoesNothingWhenNeitherCoordinateColumnIsSubmitted(): void {
        // 逐欄更新只改名字時，絕不能順手把座標清掉。
        $row = ['c_name_chn' => '安定衛', 'c_firstyear' => 1368];
        $result = Normalizer::normalizeRow($row, 'ADDR_CODES');

        $this->assertSame($row, $result['data']);
        $this->assertArrayNotHasKey('x_coord', $result['data']);
        $this->assertSame([], $result['cleared']);
    }

    /** @param mixed $garbage */
    #[Test]
    #[DataProvider('nonNumericValues')]
    public function testLeavesNonNumericValuesForTheValidatorToReject($garbage): void {
        // 清成 NULL 會把一個該回 422 的請求變成「靜默存成沒有座標」。
        $row = ['x_coord' => $garbage, 'y_coord' => $garbage];
        $result = Normalizer::normalizeRow($row, 'ADDR_CODES');

        $this->assertEquals($row, $result['data']);
        $this->assertSame([], $result['cleared']);
    }

    /**
     * 每一個都是 CodeTableFieldValidator::looksNumeric() 會拒絕的形狀。
     *
     * @return array<string, array{0: mixed}>
     */
    public static function nonNumericValues(): array {
        return [
            'word' => ['east'],
            'scientific zero' => ['0e0'],
            'scientific' => ['1e5'],
            'hex zero' => ['0x0'],
            'trailing dot' => ['0.'],
            'leading dot' => ['.0'],
            'underscore' => ['1_0'],
            // 前導 `+` 是 validator 明確拒絕的（其 regex 是 `/\A-?\d+(\.\d+)?\z/`）。
            // 本類曾經放寬到接受它，結果 {"x":"0","y":"+40.5"} 變成 200＋NULL 而不是 422。
            'leading plus' => ['+40.5'],
            'untrimmed number' => [' 40.5 '],
            'array' => [['40.5']],
            'nested array' => [[['40.5']]],
            'infinity' => [INF],
            'negative infinity' => [-INF],
        ];
    }

    #[Test]
    public function testLeavesNanAlone(): void {
        // NAN 要單獨測：assertEquals 對 NAN 永遠不成立（NAN !== NAN）。
        $result = Normalizer::normalizeRow(['x_coord' => NAN, 'y_coord' => NAN], 'ADDR_CODES');

        $this->assertNan($result['data']['x_coord']);
        $this->assertNan($result['data']['y_coord']);
        $this->assertSame([], $result['cleared']);
    }

    #[Test]
    public function testLeavesTheWholePairAloneWhenOneAxisIsGarbageAndTheOtherIsZero(): void {
        // x=0, y="east" 必須仍然是 422，不能因為 x 是零就整對清空而回 200。
        $row = ['x_coord' => 0, 'y_coord' => 'east'];
        $result = Normalizer::normalizeRow($row, 'ADDR_CODES');

        $this->assertSame($row, $result['data']);
        $this->assertSame([], $result['cleared']);
    }

    #[Test]
    public function testLeavesThePairAloneWhenOneAxisIsInfiniteAndTheOtherIsZero(): void {
        // 這一條是 `isNumeric()` 裡 `is_finite()` 的唯一守門測試。
        //
        // 為什麼不能只靠 nonNumericValues 那組：那組把 INF 放在**兩軸**，而「兩軸都非數值」
        // 時有沒有 is_finite 都是「整對不動」，原版與把 is_finite 拿掉的版本輸出一模一樣。
        // 只有混合對才分得出來——拿掉 is_finite 之後，下面這筆會被整對清成 NULL 回 200，
        // 而不是讓 CodeTableFieldValidator 回「必須為有限數值」的 422。
        $row = ['x_coord' => 0, 'y_coord' => INF];
        $result = Normalizer::normalizeRow($row, 'ADDR_CODES');

        $this->assertSame($row, $result['data']);
        $this->assertSame([], $result['cleared']);
    }

    #[Test]
    public function testLeavesThePairAloneWhenOneAxisIsNanAndTheOtherIsZero(): void {
        // 同上，NAN 版。NAN 不能用 assertSame（NAN !== NAN）。
        $result = Normalizer::normalizeRow(['x_coord' => 0, 'y_coord' => NAN], 'ADDR_CODES');

        $this->assertSame(0, $result['data']['x_coord']);
        $this->assertNan($result['data']['y_coord']);
        $this->assertSame([], $result['cleared']);
    }

    #[Test]
    public function testLeavesThePairAloneWhenGarbageHidesUnderASecondSpelling(): void {
        $row = ['x_coord' => 0, 'X_COORD' => 'east', 'y_coord' => 0];
        $result = Normalizer::normalizeRow($row, 'ADDR_CODES');

        $this->assertSame($row, $result['data']);
        $this->assertSame([], $result['cleared']);
    }

    #[Test]
    public function testDoesNotTreatBooleansAsNumbers(): void {
        $row = ['x_coord' => false, 'y_coord' => true];
        $result = Normalizer::normalizeRow($row, 'ADDR_CODES');

        $this->assertSame($row, $result['data']);
        $this->assertSame([], $result['cleared']);
    }

    #[Test]
    public function testValuesThatUnderflowToZeroAreCleared(): void {
        // 判定「是不是零」看的是**落庫後的 double**，不是使用者打了幾個字。
        // 1e-400 下溢成精確的 0.0，資料庫要存的就是 0——正是本類要防的那一列。
        $result = Normalizer::normalizeRow(
            ['x_coord' => '0.'.str_repeat('0', 400).'1', 'y_coord' => 40.37184906],
            'ADDR_CODES'
        );

        $this->assertNull($result['data']['x_coord']);
        $this->assertNull($result['data']['y_coord']);
        $this->assertSame(Normalizer::REASON_ZERO, $result['cleared']['x_coord']);
    }

    #[Test]
    public function testTinyNonZeroValuesSurvive(): void {
        // 破壞性的規則取最窄定義：只有精確的 0.0 算零。1e-9 一樣連不上地圖，但那是
        // CoordinateValidator 該說的話，不是這一層該刪掉的資料。
        $row = ['x_coord' => 1.0E-9, 'y_coord' => 1.0E-9];
        $result = Normalizer::normalizeRow($row, 'ADDR_CODES');

        $this->assertSame($row, $result['data']);
        $this->assertSame([], $result['cleared']);
    }

    #[Test]
    public function testUnregisteredTablesAreUntouched(): void {
        // fail-closed：未登記的表一律不處理（與 VariantReplaceScope 同樣的取向）。
        $row = ['x_coord' => 0, 'y_coord' => 0];

        foreach (['BIOG_MAIN', null, ''] as $table) {
            $result = Normalizer::normalizeRow($row, $table);
            $this->assertSame($row, $result['data'], 'table: '.var_export($table, true));
            $this->assertSame([], $result['cleared']);
            $this->assertFalse(Normalizer::handles($table));
            $this->assertSame([], Normalizer::pairsFor($table));
        }
    }

    #[Test]
    public function testRegisteredTablesAreRecognisedRegardlessOfCase(): void {
        foreach (['ADDR_CODES', 'addr_codes', 'Addr_Codes', 'ADDRESSES', 'addresses'] as $table) {
            $this->assertTrue(Normalizer::handles($table), 'table: '.$table);
            $this->assertSame([['x_coord', 'y_coord']], Normalizer::pairsFor($table));

            $result = Normalizer::normalizeRow(['x_coord' => 0, 'y_coord' => 0], $table);
            $this->assertNull($result['data']['x_coord'], 'table: '.$table);
            $this->assertNull($result['data']['y_coord'], 'table: '.$table);
        }
    }

    #[Test]
    public function testOtherColumnsArePreservedWhileTheCoordinatesAreCleared(): void {
        $result = Normalizer::normalizeRow([
            'c_addr_id' => 4338,
            'c_name_chn' => '安定衛',
            'x_coord' => 0,
            'y_coord' => 0,
            'c_notes' => '',
        ], 'ADDR_CODES');

        // 座標真的被清了（少了這兩條，整個 pair 邏輯被拿掉這個測試也會綠）。
        $this->assertNull($result['data']['x_coord']);
        $this->assertNull($result['data']['y_coord']);

        $this->assertSame(4338, $result['data']['c_addr_id']);
        $this->assertSame('安定衛', $result['data']['c_name_chn']);
        // c_notes 是空字串但不是座標欄，不在這一層的職責範圍內。
        $this->assertSame('', $result['data']['c_notes']);
        $this->assertSame(['x_coord', 'y_coord'], array_keys($result['cleared']));
    }

    // ── 與 CoordinateValidator 的一致性 ──────────────────────

    /**
     * 本類清空的每一個值，都必須是 `CoordinateValidator` 判為**無效**的值。
     *
     * 這才是讓這一層安全的那條性質，也是「兩邊定義不漂移」的實際斷言。注意斷言的是
     * 「無效」而不是「`zero_axis`」：本類清空的集合包含空白，而空白在 validator 那邊是
     * `non_numeric` 而非 `zero_axis`。真正的不變式是 `reason() !== null`。
     *
     * 兩邊的判準**刻意不同且本類更窄**（validator 是 `|v| < epsilon`、本類是精確
     * `=== 0.0`），關係是包含。先前的版本讓本類讀 `config('chgis_map.epsilon')` 來
     * 「共用定義」，但那個 config 是 env 可調的（`CHGIS_MAP_EPSILON`），等於把一個地圖
     * 顯示參數變成破壞性寫入的開關。一致性要由這支測試保證，不能由共用部署旋鈕保證。
     */
    #[Test]
    public function testEverythingThisClearsIsAlsoInvalidForTheValidator(): void {
        $clearable = [
            // 零值：validator 判 zero_axis
            0, 0.0, -0.0, '0', '-0', '0.0', '0.00000', '0.000000000000',
            // 空白：validator 判 non_numeric
            null, '', '  ', "\t",
        ];

        foreach ($clearable as $value) {
            $label = var_export($value, true);

            $result = Normalizer::normalizeRow(
                ['x_coord' => $value, 'y_coord' => 40.37184906],
                'ADDR_CODES'
            );
            $this->assertNull(
                $result['data']['y_coord'],
                'normalizer should have cleared the pair for '.$label
            );
            $this->assertNotNull(
                CoordinateValidator::reason($value, 40.37184906),
                'CoordinateValidator considers '.$label.' a usable longitude'
            );
        }
    }

    #[Test]
    public function testTheZeroSubsetIsNarrowerThanTheValidatorsZeroAxis(): void {
        // 包含關係的另一半：validator 用容差、本類用精確零，所以有一段值是
        // 「validator 認為是零軸、本類刻意不清」。那不是漂移，是刻意更窄——
        // 破壞性寫入取最窄定義，把界內外的判斷留給讀取端。
        $tiny = 1.0E-9;

        $this->assertSame('zero_axis', CoordinateValidator::reason($tiny, 40.37184906));

        $result = Normalizer::normalizeRow(['x_coord' => $tiny, 'y_coord' => 40.37184906], 'ADDR_CODES');
        $this->assertSame($tiny, $result['data']['x_coord']);
        $this->assertSame([], $result['cleared']);
    }

    #[Test]
    public function testValuesThisKeepsAreNotRejectedForBeingZero(): void {
        // 反向：本類放過的有效座標，validator 不該說它是零軸。
        $result = Normalizer::normalizeRow(
            ['x_coord' => 113.11134338, 'y_coord' => 40.37184906],
            'ADDR_CODES'
        );

        $this->assertSame([], $result['cleared']);
        $this->assertNull(CoordinateValidator::reason(113.11134338, 40.37184906));
    }

    #[Test]
    public function testTheZeroRuleIsNotTunableByConfig(): void {
        // 破壞性寫入不可以掛在部署設定上。改 epsilon 只會影響讀取端的連結判定，
        // 絕不能讓「地圖調參」變成「刪掉使用者的座標」。
        config()->set('chgis_map.epsilon', 10.0);

        $result = Normalizer::normalizeRow(['x_coord' => 5.0, 'y_coord' => 5.0], 'ADDR_CODES');

        $this->assertSame(5.0, $result['data']['x_coord']);
        $this->assertSame(5.0, $result['data']['y_coord']);
        $this->assertSame([], $result['cleared']);
    }

    // ── invalidColumns()：給沒有驗證層的寫入路徑用的窄範圍檢查 ──

    #[Test]
    public function testInvalidColumnsFlagsValuesThatAreNotNumbers(): void {
        // normalizeRow() 對這些值刻意整對不動，前提是「下游 validator 會回 422」。
        // 但核准重放與 Codes UI 表單這兩條路徑**根本沒有驗證層**——實測一筆帶
        // x_coord: "0e0" 的歷史提案核准後，MariaDB 在非 strict sql_mode 下把它靜默轉成 0，
        // 正好重新造出這整套機制要防的那一列。這個方法就是給那些路徑自己擋的。
        foreach (['east', '0e0', '1e5', '0x0', '0.', '.0', '+40.5', ' 40.5 ', INF, -INF] as $bad) {
            $invalid = Normalizer::invalidColumns(
                ['x_coord' => $bad, 'y_coord' => 40.5],
                'ADDR_CODES'
            );
            $this->assertSame(
                ['x_coord' => 'non_numeric'],
                $invalid,
                'should have flagged '.var_export($bad, true)
            );
        }

        $this->assertSame(
            ['x_coord' => 'non_numeric'],
            Normalizer::invalidColumns(['x_coord' => NAN, 'y_coord' => 40.5], 'ADDR_CODES')
        );
    }

    #[Test]
    public function testInvalidColumnsAgreesWithWhatNormalizeRowRefusesToTouch(): void {
        // 這是那條「由構造保證一致」的斷言：凡是 normalizeRow() 因為「不是數」而整對不動的
        // 值，invalidColumns() 都要抓到。兩邊共用 isNumeric()／isBlank()，所以不可能漂移——
        // 但漂移一旦發生，後果是某條沒有驗證層的路徑靜默落庫一個 0，所以還是釘住。
        foreach (['east', '0e0', '+40.5', '0x0'] as $bad) {
            $row = ['x_coord' => 0, 'y_coord' => $bad];
            $result = Normalizer::normalizeRow($row, 'ADDR_CODES');

            $this->assertSame($row, $result['data'], 'normalizeRow should have left '.$bad.' alone');
            $this->assertNotSame(
                [],
                Normalizer::invalidColumns($row, 'ADDR_CODES'),
                'invalidColumns must flag what normalizeRow refuses to normalise: '.$bad
            );
        }
    }

    #[Test]
    public function testInvalidColumnsAcceptsEverythingNormalizeRowWillHandle(): void {
        // 反向：合法數值與空白都不該被擋。空白是「清空」的合法寫法，擋掉它等於不能清座標。
        foreach ([0, 0.0, '0', '-0', '0.00000', '', '  ', null, 113.11134338, '40.5', '-40.5'] as $ok) {
            $this->assertSame(
                [],
                Normalizer::invalidColumns(['x_coord' => $ok, 'y_coord' => 40.5], 'ADDR_CODES'),
                'should not have flagged '.var_export($ok, true)
            );
        }
    }

    #[Test]
    public function testInvalidColumnsIgnoresNonCoordinateColumnsAndUnregisteredTables(): void {
        // 只管座標欄：刻意不代替 CodeTableFieldValidator 檢查整列，因為那份型別清單是照
        // 各表的 v2 allowed_fields 寫的，而表單路徑會把整列所有欄位送回來（例如
        // long_text_fields 只為三張表登記過，套用整份驗證會讓使用者改一個無關欄位就 422）。
        $this->assertSame(
            [],
            Normalizer::invalidColumns(['c_name' => 'east', 'c_notes' => '0e0'], 'ADDR_CODES')
        );
        $this->assertSame(
            [],
            Normalizer::invalidColumns(['x_coord' => 'east'], 'BIOG_MAIN'),
            'fail-closed：未登記的表不處理'
        );
        $this->assertSame([], Normalizer::invalidColumns(['x_coord' => 'east'], null));
    }

    #[Test]
    public function testInvalidColumnsReportsTheSubmittedSpellingAndEverySpelling(): void {
        $invalid = Normalizer::invalidColumns(
            ['X_COORD' => 'east', 'x_coord' => 'west', 'y_coord' => 40.5],
            'ADDR_CODES'
        );

        $this->assertSame(['X_COORD' => 'non_numeric', 'x_coord' => 'non_numeric'], $invalid);
    }
}
