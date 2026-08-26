<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * v2 mutation 白名單與實際 schema 的漂移守衛（#1284）。
 *
 * **為什麼需要**：`allowedFields()` 只是一份手寫清單，與資料表的實際欄位之間沒有任何
 * 保證。列進去的欄位若不存在，白名單會照樣放行，錯誤要到 `DB::table()->update()`／
 * `->insert()` 才發生 ⇒ 使用者拿到的是 **500 而不是 422**。
 *
 * `ALTNAME_DATA` 就這樣帶了四個幻影欄（`c_alt_name_pinyin`／`_pinyin2`／`_pinyin3`／
 * `c_alt_name_role`）將近半年沒被發現，因為每支相關測試都**自己在合成表裡把那四欄建了
 * 出來**——測試永遠綠，prod 永遠打不到。
 *
 * 掛 `RefreshDatabase` 讓 schema 真的來自 `database/migrations`，守衛才有意義
 * （同 VariantReplaceRegistryDriftTest 的理由）。
 *
 * **邊界**：抓的是「白名單列了 migration 沒有的欄」。抓不到反向的
 * 「prod 有、migration 沒有」——那需要對 live schema 比對，不在單元測試能及的範圍。
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
     * @return array<int,class-string>
     */
    private function personSubresourceHandlers(): array {
        $classes = [];
        foreach (glob(app_path('Services/Mutations/*.php')) ?: [] as $file) {
            $class = 'App\\Services\\Mutations\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            foreach (self::BASE_CLASSES as $base) {
                if ($reflection->isSubclassOf($base)) {
                    $classes[] = $class;

                    break;
                }
            }
        }

        return $classes;
    }

    /** 呼叫 handler 的 protected 合約方法（名稱刻意不叫 call()——TestCase 已有同名 public 方法）。 */
    private function contract(object $handler, string $method): mixed {
        $reflection = new \ReflectionMethod($handler, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($handler);
    }
}
