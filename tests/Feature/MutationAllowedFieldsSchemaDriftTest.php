<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * v2 mutation 白名單與實際 schema 的漂移守衛。
 *
 * **為什麼需要**：`allowedFields()` 只是一份手寫清單，與資料表的實際欄位之間沒有任何
 * 保證。列進去的欄位若不存在，白名單會照樣放行，錯誤要到 `DB::table()->update()`／
 * `->insert()` 才發生 ⇒ 使用者拿到的是 **500 而不是 422**。
 *
 * **必須掛 `RefreshDatabase`**，schema 才真的來自 `database/migrations`；不掛的話
 * `Schema` 只看得到各測試自己建的合成表，而合成表要什麼欄有什麼欄 ⇒ 守衛永遠假綠。
 * （同 VariantReplaceRegistryDriftTest 的理由。）
 *
 * **兩條線**：
 *  1. 人物子資源 handler：有 `tableName()`/`allowedFields()`/`keyColumns()` 合約，自動掃描。
 *  2. 不走那組基底的 handler（`AbstractMutationHandler` 直系）：欄位清單是自宣告常數、
 *     表名寫死在方法裡，無法自動推導，故以 `EXTRA_HANDLER_TABLES` 明列，並用
 *     `every_hand_written_column_constant_is_registered()` 機械檢查「有清單卻沒登記」。
 *     這條線是後補的——`BiogMainCreateHandler` 曾因只掃第 1 條而漏掉 5 個幻影欄。
 *
 * **邊界**：抓的是「白名單列了 migration 沒有的欄」。抓不到反向的
 * 「prod 有、migration 沒有」——那需要對 live schema 比對，不在單元測試能及的範圍。
 * 第 2 條線的「class → 表」是手寫對應，沒有機制保證它就是 handler 實際寫入的那張表。
 */
class MutationAllowedFieldsSchemaDriftTest extends TestCase {
    use RefreshDatabase;

    /**
     * 人物子資源 handler 的兩個基底類別；只有它們有 tableName()/allowedFields()/keyColumns()
     * 這組合約。代碼表與實體聚合 handler 的欄位來源不同（config／service），不在此守衛範圍。
     */
    private const BASE_CLASSES = [
        \App\Services\Mutations\AbstractPersonSubresourceCreateHandler::class,
        \App\Services\Mutations\AbstractPersonSubresourceMutationHandler::class,
    ];

    /**
     * 不走上述基底、但自帶手寫欄位清單的 handler：class => [目標表, [要檢查的常數名…]]。
     * 新增這類 handler 時要一併登記，否則下面的完備性檢查會紅。
     */
    private const EXTRA_HANDLER_TABLES = [
        \App\Services\Mutations\BiogMainCreateHandler::class => ['BIOG_MAIN', ['ALLOWED_FIELDS', 'BLOCKED_FIELDS']],
        \App\Services\Mutations\BiogMainMutationHandler::class => ['BIOG_MAIN', ['BLOCKED_FIELDS']],
        \App\Services\Mutations\PossessionCreateHandler::class => ['POSSESSION_DATA', ['ALLOWED_FIELDS']],
        \App\Services\Mutations\PostingCreateHandler::class => ['POSTED_TO_OFFICE_DATA', ['ALLOWED_FIELDS']],
        \App\Services\Mutations\SourceMutationHandler::class => ['BIOG_SOURCE_DATA', ['KEY_COLUMNS']],
    ];

    #[Test]
    public function every_allowed_field_exists_in_the_migrated_schema(): void {
        $handlers = $this->personSubresourceHandlers();
        $this->assertNotEmpty($handlers, '沒有掃到任何人物子資源 handler——掃描邏輯壞了，不是真的沒有');

        $drift = [];
        $checked = 0;
        $skipped = [];

        foreach ($handlers as $class) {
            $handler = app($class);
            $table = $this->contract($handler, 'tableName');

            if (!Schema::hasTable($table)) {
                // migration 沒建這張表（例如只存在於 prod 的表）——記下來但不判紅，
                // 否則守衛會因為與本題無關的原因變成雜訊。
                $skipped[] = "{$class} → {$table}";

                continue;
            }

            $columns = array_map('strtolower', Schema::getColumnListing($table));
            $declared = array_unique(array_merge(
                $this->contract($handler, 'allowedFields'),
                $this->contract($handler, 'keyColumns')
            ));

            foreach ($declared as $column) {
                ++$checked;
                if (!in_array(strtolower((string) $column), $columns, true)) {
                    $drift[] = "{$class}：{$table}.{$column} 不存在於 migration 建出的 schema";
                }
            }
        }

        $this->assertGreaterThan(100, $checked, '檢查到的欄位數異常地少，掃描可能沒真的跑起來');
        $this->assertSame([], $drift, implode("\n", array_merge(
            ['白名單列了資料表沒有的欄位（送出會 500 而非 422），請從 allowedFields() 移除：'],
            $drift,
            $skipped === [] ? [] : ['', '（下列 handler 的表不在 migration 裡，本次略過）', ...$skipped]
        )));
    }

    /**
     * 第 2 條線：`AbstractMutationHandler` 直系 handler 的手寫欄位常數。
     *
     * `BiogMainCreateHandler::ALLOWED_FIELDS` 曾列著 `c_by_yymm`／`c_by_yymm_day`／
     * `c_dy_yymm`／`c_dy_yymm_day`（本庫從來沒有這組欄名）與 `c_self_bio`（2026_03_13
     * 已從 BIOG_MAIN 移除），而該 handler 不繼承人物子資源基底，整個逃過第 1 條線。
     * 白名單外的欄位是靜默丟棄，白名單內卻不存在的欄則會一路帶到 INSERT ⇒ 500。
     */
    #[Test]
    public function every_registered_handler_constant_matches_the_migrated_schema(): void {
        $drift = [];
        $checked = 0;
        $perClass = [];

        foreach (self::EXTRA_HANDLER_TABLES as $class => [$table, $constants]) {
            // 這裡刻意硬判斷表存在（與第 1 條線的 $skipped 寬容不同）：登記表是**手寫**的，
            // 表名打錯或 handler 改指別張表時靜默略過等於守衛失效。代價是登記「只存在於
            // prod 的表」會誤紅——真有那天再加豁免清單。
            $this->assertTrue(
                Schema::hasTable($table),
                "{$class} 登記的表「{$table}」不存在於 migration 建出的 schema"
            );
            $columns = array_map('strtolower', Schema::getColumnListing($table));
            $reflection = new ReflectionClass($class);

            $perClass[$class] = 0;
            foreach ($constants as $constant) {
                $values = $reflection->getConstant($constant);
                $this->assertIsArray($values, "{$class}::{$constant} 不是陣列——登記表與程式碼不同步");
                $this->assertNotEmpty($values, "{$class}::{$constant} 是空的——登記表與程式碼不同步");

                foreach ($values as $column) {
                    ++$checked;
                    ++$perClass[$class];
                    if (!in_array(strtolower((string) $column), $columns, true)) {
                        $drift[] = "{$class}::{$constant}：{$table}.{$column} 不存在於 migration 建出的 schema";
                    }
                }
            }
        }

        // 逐類別計數，不只看總數：總數門檻擋不住「整個 handler 從登記表消失」
        // （抽掉 BiogMainCreateHandler 的 49 欄，剩下 52 仍過得了任何寬鬆門檻）。
        foreach (array_keys(self::EXTRA_HANDLER_TABLES) as $class) {
            $this->assertGreaterThan(
                0,
                $perClass[$class] ?? 0,
                "{$class} 一個欄位都沒檢查到——登記表與程式碼不同步"
            );
        }
        $this->assertGreaterThan(90, $checked, '檢查到的欄位數異常地少，登記表可能被清空了');
        $this->assertSame([], $drift, implode("\n", array_merge(
            ['handler 常數列了資料表沒有的欄位（送出會 500 而非 422），請修正：'],
            $drift
        )));
    }

    /**
     * 完備性：任何 `AbstractMutationHandler` 直系 handler，只要自帶「看起來是欄位清單」的
     * 常數（陣列且含 `c_` 開頭的字串），就必須登記進 EXTRA_HANDLER_TABLES，
     * 否則它的清單不會被上面那條檢查覆蓋——這正是 BiogMainCreateHandler 當初的處境。
     */
    #[Test]
    public function every_hand_written_column_constant_is_registered(): void {
        $unregistered = [];

        foreach ($this->standaloneMutationHandlers() as $class) {
            $reflection = new ReflectionClass($class);
            $registered = self::EXTRA_HANDLER_TABLES[$class][1] ?? [];

            foreach ($reflection->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                if (!$this->looksLikeColumnList($constant->getValue())) {
                    continue;
                }
                if (!in_array($constant->getName(), $registered, true)) {
                    $unregistered[] = "{$class}::{$constant->getName()}";
                }
            }
        }

        $this->assertSame([], $unregistered, implode("\n", array_merge(
            ['下列 handler 常數看起來是欄位清單，卻沒登記進 EXTRA_HANDLER_TABLES，'
                . '不會被漂移守衛檢查（請補上「class => [表名, [常數名]]」）：'],
            $unregistered
        )));
    }

    /**
     * 陣列、非空、全為字串、且至少一個以 `c_` 開頭 ⇒ 視為欄位清單。
     *
     * 抓不到的形狀（已知邊界）：以欄名當**鍵**的對照陣列、混型陣列、
     * 以及寫在靜態屬性或方法內的清單。目前庫內符合條件的 5 個常數全部已登記。
     */
    private function looksLikeColumnList(mixed $value): bool {
        if (!is_array($value) || $value === []) {
            return false;
        }

        $hasColumnLike = false;
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
            if (str_starts_with($item, 'c_')) {
                $hasColumnLike = true;
            }
        }

        return $hasColumnLike;
    }

    /**
     * @return array<int,class-string>
     */
    private function personSubresourceHandlers(): array {
        return $this->mutationHandlers(true);
    }

    /**
     * @return array<int,class-string>
     */
    private function standaloneMutationHandlers(): array {
        return $this->mutationHandlers(false);
    }

    /**
     * 掃 app/Services/Mutations 下的具體 handler。
     *
     * 遞迴：`app/Services/Mutations/EntityAggregate/` 這種子目錄也要掃到，
     * 否則放進子目錄的新 handler 會靜默逃過完備性檢查。
     *
     * @param  bool $personSubresource true＝只要繼承兩個人物子資源基底的；false＝只要不繼承的
     * @return array<int,class-string>
     */
    private function mutationHandlers(bool $personSubresource): array {
        $root = app_path('Services/Mutations');
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $scanned = 0;
        $classes = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            ++$scanned;

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'App\\Services\\Mutations\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()
                || !$reflection->isSubclassOf(\App\Services\Mutations\AbstractMutationHandler::class)) {
                continue;
            }

            $isSubresource = false;
            foreach (self::BASE_CLASSES as $base) {
                if ($reflection->isSubclassOf($base)) {
                    $isSubresource = true;

                    break;
                }
            }

            if ($isSubresource === $personSubresource) {
                $classes[] = $class;
            }
        }

        $this->assertGreaterThan(30, $scanned, 'app/Services/Mutations 掃到的檔案異常地少，掃描邏輯壞了');

        return $classes;
    }

    /** 呼叫 handler 的 protected 合約方法（名稱刻意不叫 call()——TestCase 已有同名 public 方法）。 */
    private function contract(object $handler, string $method): mixed {
        $reflection = new \ReflectionMethod($handler, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($handler);
    }
}
