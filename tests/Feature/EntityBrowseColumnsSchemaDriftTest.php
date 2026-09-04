<?php

namespace Tests\Feature;

use App\Support\BrowsesEntityTable;
use App\Support\EntityTableBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * 實體列表頁瀏覽描述子（EntityTableBrowser descriptor）與實際 schema 的漂移守衛。
 *
 * **為什麼需要**：descriptor 的 `columns` 是一份手寫清單。EntityTableBrowser 的關鍵字搜尋
 * 會對其中**每一欄**下 `LIKE`，排序／篩選也以它（＋計算欄）為白名單。列進去卻不存在的欄，
 * 列表首頁看不出來（`select TABLE.*` 不碰它），但使用者一按「搜尋」就是
 * `1054 Unknown column` ⇒ **500 而不是空結果**。/app/office 的 c_category_1..4 與
 * c_office_id_old 就是這樣（2026-07-16 寫進清單時，那 5 欄早已被 migration 移除——
 * 清單是照著移除前的表形抄的，一出生就是壞的）。
 *
 * **必須掛 `RefreshDatabase`**，schema 才真的來自 `database/migrations`；不掛的話
 * `Schema` 只看得到各測試自己建的合成表，而 OfficeEntityIndexTest 正是把那 5 個幻影欄
 * 建了出來 ⇒ 守衛永遠假綠。（同 MutationAllowedFieldsSchemaDriftTest 的理由。）
 *
 * **本測試只看得到 `browseDescriptor()` 回傳的東西**，所以「controller 真的把這份描述子
 * 交給 `EntityTableBrowser`」要另外把關，分兩層：`payload()` 收 `BrowsesEntityTable`
 * 實作物件、不收陣列（字面陣列繞道會 TypeError），加上
 * `every_entity_controller_passes_itself_to_the_browser()` 逐一檢查**每一處** `payload()`
 * 呼叫（含 `?->`）語義上都是 `payload($request, $this)`（擋掉「改傳另一個實作」）。
 * 少了任一層，本守衛都會退化成「檢查一份沒人在用的清單」。
 *
 * **邊界**：抓的是「descriptor 列了 migration 沒有的欄」。抓不到反向的「prod 有、migration
 * 沒有」，也不驗證 computed 運算式（那是原始 SQL，只能靠實際查詢的測試覆蓋）。
 */
class EntityBrowseColumnsSchemaDriftTest extends TestCase {
    use RefreshDatabase;

    /**
     * 現行實體列表頁。釘住「有哪些」而不只是「有幾個」：光靠總欄數門檻的話，某個
     * controller 整個從掃描裡消失（改名／搬到子命名空間／autoload 壞掉）仍可能過關，
     * 而那正是本 bug 所在的那一個。新增實體列表頁時要一併補進來。
     */
    private const EXPECTED_CONTROLLERS = [
        \App\Http\Controllers\OfficeEntityController::class,
        \App\Http\Controllers\SocialInstitutionEntityController::class,
        \App\Http\Controllers\TextEntityController::class,
    ];

    #[Test]
    public function every_browse_descriptor_column_exists_in_the_migrated_schema(): void {
        $controllers = $this->classesMentioning('BrowsesEntityTable', [BrowsesEntityTable::class]);
        $controllers = array_values(array_filter(
            $controllers,
            fn (string $class) => !(new ReflectionClass($class))->isAbstract()
                && (new ReflectionClass($class))->implementsInterface(BrowsesEntityTable::class)
        ));
        sort($controllers);

        $expected = self::EXPECTED_CONTROLLERS;
        sort($expected);
        $this->assertSame(
            $expected,
            $controllers,
            '掃到的實體列表 controller 與清冊不符：新增了列表頁就補進 EXPECTED_CONTROLLERS；'
                . '少了則多半是掃描或 autoload 壞了，而不是真的沒有'
        );

        $drift = [];

        foreach ($controllers as $class) {
            $descriptor = app($class)->browseDescriptor();
            $table = $descriptor['table'];

            if (!Schema::hasTable($table)) {
                $drift[] = "{$class}：descriptor 的 table「{$table}」不存在於 migration 建出的 schema";

                continue;
            }

            $columns = array_map('strtolower', Schema::getColumnListing($table));
            $declared = array_unique(array_merge(
                $descriptor['columns'],
                [$descriptor['key_column']]
            ));

            // 逐個 controller 檢查，避免「某一個掃到 0 欄」被其他 controller 的數量蓋過去。
            $this->assertNotEmpty($declared, "{$class}：descriptor 沒有任何欄位，掃描可能沒真的跑起來");

            foreach ($declared as $column) {
                if (!in_array(strtolower((string) $column), $columns, true)) {
                    $drift[] = "{$class}：{$table}.{$column} 不存在於 migration 建出的 schema";
                }
            }

            // 計算欄名不可與實體欄撞名：撞名時 resolveColumn() 會把實體欄悄悄換成運算式。
            $lowerColumns = array_map('strtolower', $descriptor['columns']);
            foreach (array_keys($descriptor['computed'] ?? []) as $computedName) {
                if (in_array(strtolower((string) $computedName), $lowerColumns, true)) {
                    $drift[] = "{$class}：計算欄「{$computedName}」與實體欄同名，thead 會重複且篩選語義會被運算式蓋掉";
                }
            }
        }

        $this->assertSame([], $drift, implode("\n", array_merge(
            ['實體列表 descriptor 列了資料表沒有的欄位（按下「搜尋」會 500），請修正：'],
            $drift
        )));
    }

    /**
     * 描述子必須真的送進引擎：清冊上的每個 controller，**每一處** `payload()` 呼叫都要
     * 長成 `payload($request, $this)`。型別已擋掉「傳字面陣列」，但擋不掉「傳另一個
     * BrowsesEntityTable 實作」（就地 new 一個匿名類別）——那樣 controller 自己的
     * browseDescriptor() 依舊乾淨、上面那條守衛依舊全綠，實際跑的卻是另一份沒被檢查過的
     * 欄位清單。這條補上那個缺口。
     *
     * 走 PHP tokenizer 而不是 regex：regex 版本被兩種寫法騙過去過——參數展開
     * （`payload(...$args)` 根本不符合樣式、於是不被檢查）以及「另外補一個沒人呼叫的
     * 合格呼叫當誘餌」。逐 token 比對是**全稱**的：每一個 payload 呼叫都要對，
     * 對不上就紅，誘餌與展開都逃不掉。`->` 與 `?->` 兩種呼叫運算子都算
     * （只認 `->` 時，改成 `?->payload(...)` 就整個溜過去了）。
     *
     * 比對做的是**語義**而非字面：具名引數與尾逗號都算合格（`payload(source: $this, …)`
     * 與 `payload($request, $this,)` 語義相同，守衛不該擋住等價重構）。動態方法分派則一律
     * 禁止（見 dynamicDispatchSites()）——那會讓方法名不再是字面 token。
     *
     * 這條在審查中被連續攻破四次（regex→參數展開／誘餌、tokenizer→`?->`、字面比對→誤擋
     * 等價重構、最後→動態分派），現在的形狀是那四輪的結果，**不要為了簡潔把它改回
     * regex 或單點比對**。同時要有自知之明：這是防無意漂移的守衛，不是安全邊界。
     */
    #[Test]
    public function every_entity_controller_passes_itself_to_the_browser(): void {
        $offenders = [];

        foreach (self::EXPECTED_CONTROLLERS as $class) {
            $file = (string) (new ReflectionClass($class))->getFileName();
            $calls = $this->payloadCalls((string) file_get_contents($file));

            if ($calls === []) {
                $offenders[] = "{$class}：找不到任何 payload() 呼叫";

                continue;
            }
            foreach ($calls as $arguments) {
                $bound = $this->bindPayloadArguments($arguments);
                if ($bound !== ['request' => '$request', 'source' => '$this']) {
                    $offenders[] = "{$class}：payload(".implode(', ', $arguments).') 不是 payload($request, $this)';
                }
            }

            // 動態分派會讓上面的字面比對整個失效（`$m = 'payload'; $this->browser->$m(…)`
            // 根本不長成 payload token）。這三個檔案本來就沒有動態分派的需要，直接全面禁止。
            foreach ($this->dynamicDispatchSites((string) file_get_contents($file)) as $site) {
                $offenders[] = "{$class}：出現動態方法分派（{$site}），"
                    . '會繞過本守衛的字面比對；實體列表 controller 不得使用';
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['實體列表 controller 的每一處 payload() 都必須是 payload($request, $this)，'
                . '否則實際使用的欄位清單不是守衛檢查的那一份：'],
            $offenders
        )));
    }

    /**
     * 找出以動態方式呼叫方法的地方：`->$method(`、`?->$method(`、`->{…}(`，
     * 以及 `call_user_func`／`call_user_func_array`。
     *
     * 這些寫法讓「方法名」不再是原始碼裡的字面 token，字面比對就看不到那次呼叫了。
     * 實體列表 controller 沒有任何理由需要它們，所以一律判紅——與其讓守衛在這裡留一個
     * 說不清楚的缺口，不如直接禁掉這種寫法。
     *
     * @return array<int, string>
     */
    private function dynamicDispatchSites(string $source): array {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($token) => !is_array($token)
                || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)
        ));

        $sites = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (is_array($token)
                && $token[0] === T_STRING
                && in_array(strtolower($token[1]), ['call_user_func', 'call_user_func_array'], true)) {
                $sites[] = $token[1].'()';

                continue;
            }

            $isArrow = is_array($token)
                && in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
            if (!$isArrow || !isset($tokens[$i + 1], $tokens[$i + 2])) {
                continue;
            }

            $next = $tokens[$i + 1];
            $isDynamicName = (is_array($next) && $next[0] === T_VARIABLE) || $next === '{';
            $isCall = $tokens[$i + 2] === '(' || $next === '{';
            if ($isDynamicName && $isCall) {
                $sites[] = $token[1].(is_array($next) ? $next[1] : '{…}').'(…)';
            }
        }

        return $sites;
    }

    /**
     * 把引數依 PHP 的規則綁到參數名上（位置引數照順序填，具名引數照名字填）。
     * 回傳已排序的 [參數名 => 引數字面]；形狀不對（引數過多、展開、重複綁定）一律回空陣列，
     * 交給呼叫端判紅——守衛寧可誤擋也不可放行看不懂的寫法。
     *
     * @param  array<int, string> $arguments
     * @return array<string, string>
     */
    private function bindPayloadArguments(array $arguments): array {
        $parameters = ['request', 'source'];

        $bound = [];
        $position = 0;
        foreach ($arguments as $argument) {
            if (preg_match('/^([A-Za-z_]\w*):(?!:)(.+)$/', $argument, $matches) === 1) {
                $name = $matches[1];
                $value = $matches[2];
            } else {
                if (!isset($parameters[$position])) {
                    return [];
                }
                $name = $parameters[$position];
                ++$position;
                $value = $argument;
            }

            if (!in_array($name, $parameters, true) || isset($bound[$name])) {
                return [];
            }
            $bound[$name] = $value;
        }

        ksort($bound);

        return $bound;
    }

    /**
     * 取出原始碼裡每一處 `->payload(...)`／`?->payload(...)` 的頂層引數（去空白、去註解）。
     *
     * 以括號深度配對找出呼叫的結尾並在頂層逗號切開，所以巢狀呼叫、多行寫法、匿名類別、
     * 尾逗號都能正確切出來；註解與 docblock 在 tokenizer 階段就被丟掉，
     * 寫在註解或字串裡的假呼叫騙不過它。
     *
     * **已知限制**：只看方法名叫 `payload` 就算數，不追型別。清冊上這三個 controller
     * 目前沒有別的 `payload()` 呼叫；日後真有同名方法，這裡要改成一併比對接收者。
     *
     * @return array<int, array<int, string>>
     */
    private function payloadCalls(string $source): array {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($token) => !is_array($token)
                || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)
        ));

        $calls = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $isPayloadCall = is_array($tokens[$i])
                && $tokens[$i][0] === T_STRING
                && $tokens[$i][1] === 'payload'
                && isset($tokens[$i - 1], $tokens[$i + 1])
                && is_array($tokens[$i - 1])
                && in_array($tokens[$i - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && $tokens[$i + 1] === '(';
            if (!$isPayloadCall) {
                continue;
            }

            $depth = 0;
            $arguments = [];
            $current = '';
            for ($j = $i + 1; $j < $count; ++$j) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

                if (in_array($text, ['(', '[', '{'], true)) {
                    ++$depth;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    $arguments[] = $current;
                    $current = '';

                    continue;
                }
                $current .= $text;
            }
            if ($current !== '') {
                $arguments[] = $current;
            }

            $calls[] = $arguments;
        }

        return $calls;
    }

    /**
     * 凡是碰到 EntityTableBrowser 的類別，一律要 implement BrowsesEntityTable，
     * 否則它的欄位清單（很可能又是 payload() 裡的字面陣列）不在上面那條守衛的覆蓋範圍內。
     *
     * 刻意用「原始碼提到 EntityTableBrowser」而非「建構子有這個型別的參數」來判定：
     * 方法注入、`app(EntityTableBrowser::class)`、以及放在子命名空間的類別都要算進來。
     */
    #[Test]
    public function every_class_touching_the_browser_exposes_its_descriptor(): void {
        // 引擎自己與契約自己（類註提到引擎）不在此列。
        $exempt = [EntityTableBrowser::class, BrowsesEntityTable::class];

        $missing = [];
        foreach ($this->classesMentioning('EntityTableBrowser', $exempt) as $class) {
            if (!(new ReflectionClass($class))->implementsInterface(BrowsesEntityTable::class)) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['下列類別用到 EntityTableBrowser 卻沒有 implement BrowsesEntityTable，'
                . '欄位清單不會被漂移守衛檢查（描述子請放進 browseDescriptor()）：'],
            $missing
        )));
    }

    /**
     * app/ 底下原始碼提到 $needle 的類別（PSR-4：App\ → app/，含子命名空間）。
     *
     * 先用原始碼過濾再 autoload，是為了不去載入與本題無關的類別——app/ 裡存在
     * 已經載不起來的遺留檔（例如繼承 Laravel 12 已移除的 `Json\Resource`），
     * 無差別 `class_exists()` 全掃會被那些檔案炸掉。載不起來卻又提到 $needle 的
     * 檔案則**直接判紅**，不可靜默略過——那正是守衛該看的東西。
     *
     * @param  array<int, string> $exempt
     * @return array<int, class-string>
     */
    private function classesMentioning(string $needle, array $exempt = []): array {
        $root = app_path();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $scanned = 0;
        $classes = [];
        $unloadable = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            ++$scanned;
            if (!str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'App' . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (in_array($class, $exempt, true)) {
                continue;
            }

            try {
                if (class_exists($class) || interface_exists($class)) {
                    $classes[] = $class;
                }
            } catch (\Throwable $e) {
                $unloadable[] = "{$class}（{$e->getMessage()}）";
            }
        }

        $this->assertGreaterThan(100, $scanned, 'app/ 底下掃到的檔案異常地少，掃描邏輯壞了');
        $this->assertSame([], $unloadable, implode("\n", array_merge(
            ["下列類別提到 {$needle} 卻載入失敗，守衛無法檢查它們："],
            $unloadable
        )));

        return $classes;
    }
}
