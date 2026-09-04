<?php

namespace Tests\Feature;

use App\Services\Mutations\AbstractMutationHandler;
use App\Services\Mutations\AbstractPersonSubresourceCreateHandler;
use App\Services\Mutations\AbstractPersonSubresourceMutationHandler;
use App\Services\Mutations\BiogMainCreateHandler;
use App\Services\Mutations\BiogMainMutationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v2 mutation：同一張表的 create 與 update 可寫欄位必須對稱。
 *
 * **為什麼需要**：`BIOG_MAIN` 的 `c_birthyear`／`c_deathyear`／`c_by_month`／`c_dy_month`／
 * `c_by_day`／`c_dy_day` 六欄，update 寫得進去、create 卻寫不進去——而且是**靜默**的：
 * `array_intersect_key()` 把白名單外的 key 直接丟掉，呼叫端拿到 200、人物建好了、
 * 生卒年月日是 NULL，沒有任何錯誤或警告。這種「少寫了資料卻回成功」比 500 更難發現。
 *
 * 成因不是設計取捨，是抄錯：create 白名單一度把月／日寫成 `c_by_yymm`／`c_by_yymm_day`／
 * `c_dy_yymm`／`c_dy_yymm_day`（本庫從無此組欄名），年又一併漏抄；同一組出生欄位裡
 * `c_by_intercalary`／`c_by_nh_code`／`c_by_nh_year`／`c_by_range`／`c_by_day_gz` 全都在。
 *
 * **必須掛 `RefreshDatabase`**：update 端的可寫集合是「實際欄位扣掉 BLOCKED_FIELDS」，
 * 得有真的來自 `database/migrations` 的 schema 才算得出來。
 *
 * **反方向（create 有、update 沒有）是合法的**，但必須逐筆登記在 CREATE_ONLY 裡：
 * `c_personid` 每個子資源都有（create 要帶人物，update 靠 PK 定位、不得改掛他人），
 * BIOG_MAIN 另有姓名合併欄（update 一律由分欄重組，不採信客戶端值）。
 */
class MutationCreateUpdateParityTest extends TestCase {
    use RefreshDatabase;

    /**
     * 不繼承 `AbstractPersonSubresourceCreateHandler`、自帶欄位常數的 create handler：
     * class => [目標表, 常數名]。
     *
     * 沒有這張表就會**靜默漏掉**：它們的 update 對手是走基底的，於是該表只有 update 端、
     * 被 `isset($pair['create'], $pair['update'])` 跳過，兩端不對稱也不會有人發現。
     * `POSSESSION_DATA`／`POSTED_TO_OFFICE_DATA` 一度就是這樣不在覆蓋範圍內。
     * 下面的 every_standalone_create_handler_is_registered() 會機械檢查這張表的完整性。
     */
    private const STANDALONE_CREATE_HANDLERS = [
        BiogMainCreateHandler::class => ['BIOG_MAIN', 'ALLOWED_FIELDS'],
        \App\Services\Mutations\PossessionCreateHandler::class => ['POSSESSION_DATA', 'ALLOWED_FIELDS'],
        \App\Services\Mutations\PostingCreateHandler::class => ['POSTED_TO_OFFICE_DATA', 'ALLOWED_FIELDS'],
    ];

    /**
     * 服務 create、但**刻意不納入**兩端比對的 handler：class => 理由。
     * 一樣是完整比對——不在這裡、也不在上面兩處的 create handler 會判紅。
     */
    private const CREATE_NOT_COMPARED = [
        // create 與 update 共用同一組欄位（BiogSourceRepository 的 KEY_COLUMNS + MUTABLE_COLUMNS），
        // 同一支 handler 內分支，結構上不可能不對稱。
        \App\Services\Mutations\SourceMutationHandler::class => 'create/update 共用同一組欄位常數',
        // 兩端都從同一個 config('code_table_mutations.<table>.allowed_fields') 讀，同上。
        \App\Services\Mutations\CodeTableCreateHandler::class => 'create/update 共用同一份 config 白名單',
        // 實體聚合的鍵是**領域鍵**（begin_year、by_nianhao_code…）而非欄名，且兩端**允許**不同
        // （social-institution 刻意 create 收 5 鍵、update 收 19 鍵，見 API.md §13.4）。
        // 要守的是另一組不變量，需另立守衛，不套用本測試的「update 有的 create 都要有」。
        \App\Services\Mutations\EntityAggregateCreateHandler::class => '聚合鍵非欄名，兩端刻意不同',
    ];

    /**
     * 只有單邊 handler、無從比較的表：表 => 理由。
     */
    private const ONE_SIDED_TABLES = [
        'MERGED_PERSON_DATA' => 'MergedPersonCreateHandler 有 create，無對應的 update handler',
    ];

    /** 目前實際做到兩端比對的表數。掃描範圍縮水（handler 改繼承、改名、搬家）會讓這個數字掉下來。 */
    private const COMPARED_TABLE_COUNT = 12;

    /**
     * 允許「只有 create 收、update 不收」的欄位：表 => 欄位清單。
     * 這裡是**完整**比對（多一個少一個都紅），新增例外時要在下面補理由。
     */
    private const CREATE_ONLY = [
        // c_personid：create 必須帶人物；update 以 PK 定位該列，放行等於允許改掛到別人身上。
        'ALTNAME_DATA' => ['c_personid'],
        'BIOG_ADDR_DATA' => ['c_personid'],
        'ASSOC_DATA' => ['c_personid'],
        'ENTRY_DATA' => ['c_personid'],
        'EVENTS_DATA' => ['c_personid'],
        'KIN_DATA' => ['c_personid'],
        'BIOG_INST_DATA' => ['c_personid'],
        'STATUS_DATA' => ['c_personid'],
        'BIOG_TEXT_DATA' => ['c_personid'],
        // BIOG_MAIN 另加姓名欄：update 的 c_name_chn 一律由 c_surname_chn + c_mingzi_chn
        // 重組（見 BiogMainMutationHandler::BLOCKED_FIELDS 與 API.md），不採信客戶端傳來的
        // 合併欄；create 則必須收得下「只給全名、不拆姓名」的歷史人物。
        'BIOG_MAIN' => ['c_personid', 'c_name_chn', 'c_name', 'c_name_proper', 'c_name_rm'],
    ];

    #[Test]
    public function nothing_writable_by_update_is_rejected_by_create(): void {
        $tables = $this->writableFieldsByTable();
        $this->assertNotEmpty($tables, '沒有掃到任何 handler——掃描邏輯壞了');

        $asymmetric = [];
        $compared = [];
        $skipped = [];

        foreach ($tables as $table => $pair) {
            if (!isset($pair['create'], $pair['update'])) {
                $skipped[] = $table;

                continue;
            }
            $compared[] = $table;

            $updateOnly = array_values(array_diff($pair['update'], $pair['create']));
            if ($updateOnly !== []) {
                $asymmetric[] = "{$table}：update 寫得進但 create 收不下 —— ".implode('、', $updateOnly);
            }
        }

        // 單邊表要逐張登記：不然「某支 handler 改了繼承關係」會讓整張表靜默退出比對，
        // 而那正是 POSSESSION_DATA／POSTED_TO_OFFICE_DATA 一度發生的事。
        sort($skipped);
        $expectedSkipped = array_keys(self::ONE_SIDED_TABLES);
        sort($expectedSkipped);
        $this->assertSame($expectedSkipped, $skipped, '單邊（無從比較）的表與 ONE_SIDED_TABLES 不符');

        // 用確切張數而非下限：覆蓋範圍從 12 掉到 6 也要紅。
        $this->assertCount(
            self::COMPARED_TABLE_COUNT,
            $compared,
            '實際做到兩端比對的表數變了（現為 '.implode('、', $compared).'）——'
                . '新增實作要更新 COMPARED_TABLE_COUNT；數字變小通常代表掃描漏了某支 handler'
        );

        $this->assertSame([], $asymmetric, implode("\n", array_merge(
            ['create 白名單漏收了 update 寫得進去的欄位。送出時會被 array_intersect_key() '
                . '靜默丟棄（回 200 但資料沒進去），請補進 create 白名單：'],
            $asymmetric
        )));
    }

    #[Test]
    public function create_only_fields_match_the_registered_exceptions(): void {
        $tables = $this->writableFieldsByTable();

        $actual = [];
        foreach ($tables as $table => $pair) {
            if (!isset($pair['create'], $pair['update'])) {
                continue;
            }
            $createOnly = array_values(array_diff($pair['create'], $pair['update']));
            if ($createOnly !== []) {
                sort($createOnly);
                $actual[$table] = $createOnly;
            }
        }

        $expected = array_map(
            function (array $fields) {
                $fields = array_map('strtolower', $fields);
                sort($fields);

                return $fields;
            },
            self::CREATE_ONLY
        );
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual, '「只有 create 收」的欄位與登記清單不符：'
            . '新增這類欄位要在 CREATE_ONLY 補上並寫明理由；'
            . '若是無意間讓 update 少收了欄位，該修的是 update 端');
    }

    /**
     * 每張表的 create／update 可寫欄位（小寫）。
     *
     * 人物子資源兩個基底走 `allowedFields()`；BIOG_MAIN 兩支不走那組基底，
     * update 端的可寫集合是「實際欄位扣掉 BLOCKED_FIELDS」，需另外算。
     *
     * @return array<string, array{create?: array<int,string>, update?: array<int,string>}>
     */
    private function writableFieldsByTable(): array {
        $byTable = [];

        foreach ($this->concreteMutationHandlers() as $class) {
            $reflection = new ReflectionClass($class);
            $kind = match (true) {
                $reflection->isSubclassOf(AbstractPersonSubresourceCreateHandler::class) => 'create',
                $reflection->isSubclassOf(AbstractPersonSubresourceMutationHandler::class) => 'update',
                default => null,
            };
            if ($kind === null) {
                continue;
            }

            $handler = app($class);
            $table = $this->contract($handler, 'tableName');
            $byTable[$table][$kind] = array_map('strtolower', $this->contract($handler, 'allowedFields'));
        }

        // 不走基底的 create handler：欄位在自宣告常數裡，表名寫死在方法中，只能明列。
        foreach (self::STANDALONE_CREATE_HANDLERS as $class => [$table, $constant]) {
            $byTable[$table]['create'] = array_map(
                'strtolower',
                (array) (new ReflectionClass($class))->getConstant($constant)
            );
        }

        // BIOG_MAIN 的 update 端沒有 allowedFields()，可寫集合是「實際欄位扣掉 BLOCKED_FIELDS」。
        if (Schema::hasTable('BIOG_MAIN')) {
            $blocked = array_map(
                'strtolower',
                (array) (new ReflectionClass(BiogMainMutationHandler::class))->getConstant('BLOCKED_FIELDS')
            );
            $columns = array_map('strtolower', Schema::getColumnListing('BIOG_MAIN'));
            $byTable['BIOG_MAIN']['update'] = array_values(array_diff($columns, $blocked));
        }

        return $byTable;
    }

    /**
     * 完備性：每一支 create handler 要嘛繼承 `AbstractPersonSubresourceCreateHandler`
     * （自動掃到），要嘛登記在 STANDALONE_CREATE_HANDLERS 裡。漏了就是該表整個不在
     * 對稱檢查的覆蓋範圍內，而且不會有任何跡象。
     */
    #[Test]
    public function every_standalone_create_handler_is_registered(): void {
        $unregistered = [];

        foreach ($this->concreteMutationHandlers() as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isSubclassOf(AbstractPersonSubresourceCreateHandler::class)) {
                continue;
            }
            // 以類名判斷「這支是不是 create 路徑」有個例外：SourceMutationHandler 名字不帶
            // Create，卻在同一支裡處理 create——所以它也列進 CREATE_NOT_COMPARED。
            $servesCreate = str_ends_with($class, 'CreateHandler')
                || array_key_exists($class, self::CREATE_NOT_COMPARED);
            if (!$servesCreate) {
                continue;
            }

            if (!array_key_exists($class, self::STANDALONE_CREATE_HANDLERS)
                && !array_key_exists($class, self::CREATE_NOT_COMPARED)) {
                $unregistered[] = $class;
            }
        }

        $this->assertSame([], $unregistered, implode("\n", array_merge(
            ['下列 create handler 不繼承人物子資源基底、也沒登記進 STANDALONE_CREATE_HANDLERS '
                . '或 CREATE_NOT_COMPARED，它們的表不會被 create／update 對稱檢查覆蓋：'],
            $unregistered
        )));
    }

    /**
     * @return array<int, class-string>
     */
    private function concreteMutationHandlers(): array {
        $root = app_path('Services/Mutations');
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $classes = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'App\\Services\\Mutations\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if (!$reflection->isAbstract() && $reflection->isSubclassOf(AbstractMutationHandler::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function contract(object $handler, string $method): mixed {
        $reflection = new ReflectionMethod($handler, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($handler);
    }
}
