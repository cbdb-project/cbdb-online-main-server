<?php

namespace Tests\Unit;

use App\Http\Controllers\CodesController;
use App\Support\VariantReplaceScope;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * 機械化守衛：`CodesController::$tableJoinConfigurations` 本身就是「哪些文字欄被當
 * join 鍵」的權威列表，這支測試從它推導出必須排除的欄位，而不是靠人工清單。
 *
 * 為什麼需要：S0 的人工掃描第一版只涵蓋了 7 組 `*_CODE_TYPE_REL` ↔ `*_TYPES` 裡的 1 組，
 * 漏了 6 組（各 2 欄）。人工清單會漏，而這份 config 每次有人新增 join 設定都會更新，
 * 所以從它推導才是可持續的。
 *
 * 若日後新增一組 join 設定而忘了把 join 鍵加進 VariantReplaceScope，這支測試會紅。
 */
class VariantReplaceJoinKeyGuardTest extends TestCase {
    /**
     * 明文豁免：join 鍵但**不需要**排除，每筆要寫理由。
     *
     * @var array<string,string>
     */
    private const EXEMPT = [
        // 數字型代碼欄本來就不在落地替換範圍內（機制只處理文本型別），
        // 列在這裡是為了讓「為什麼這欄沒進 EXCLUDED_COLUMNS」有據可查。
        'ADMIN_CAT_CODE_TYPE_REL.c_admin_cat_code' => '數字型代碼欄，非文本型別',
        'ADMIN_CAT_CODES.c_admin_cat_code' => '數字型代碼欄，非文本型別',
        'APPOINTMENT_CODE_TYPE_REL.c_appt_code' => '數字型代碼欄，非文本型別',
        'APPOINTMENT_CODES.c_appt_code' => '數字型代碼欄，非文本型別',
        'ASSOC_CODE_TYPE_REL.c_assoc_code' => '數字型代碼欄，非文本型別',
        'ASSOC_CODES.c_assoc_code' => '數字型代碼欄，非文本型別',
        'ENTRY_CODE_TYPE_REL.c_entry_code' => '數字型代碼欄，非文本型別',
        'ENTRY_CODES.c_entry_code' => '數字型代碼欄，非文本型別',
        'OFFICE_CODE_TYPE_REL.c_office_id' => '數字型代碼欄，非文本型別',
        'OFFICE_CODES.c_office_id' => '數字型代碼欄，非文本型別',
        'STATUS_CODE_TYPE_REL.c_status_code' => '數字型代碼欄，非文本型別',
        'STATUS_CODES.c_status_code' => '數字型代碼欄，非文本型別',
        'TEXT_BIBLCAT_CODE_TYPE_REL.c_text_cat_code' => '數字型代碼欄，非文本型別',
        'TEXT_BIBLCAT_CODES.c_text_cat_code' => '數字型代碼欄，非文本型別',
    ];

    /**
     * 每一個出現在 join `on` 條件裡的欄位，都必須要嘛登記在 VariantReplaceScope 的
     * 排除清單裡、要嘛列在上面的明文豁免裡。
     */
    #[Test]
    public function testEveryJoinKeyIsEitherExcludedOrExplicitlyExempt(): void {
        $unregistered = [];

        foreach ($this->joinKeyColumns() as $qualified) {
            [$table, $column] = explode('.', $qualified, 2);

            if (array_key_exists($qualified, self::EXEMPT)) {
                continue;
            }

            if ($this->isRegisteredAsExcluded($table, $column)) {
                continue;
            }

            $unregistered[] = $qualified;
        }

        sort($unregistered);

        $this->assertSame(
            [],
            $unregistered,
            "下列欄位被 CodesController::\$tableJoinConfigurations 當成 join 鍵使用，"
                ."但既沒登記在 VariantReplaceScope 的排除清單、也沒列入本測試的明文豁免。\n"
                ."若它是文本型 join 鍵 ⇒ 加進 VariantReplaceScope::EXCLUDED_COLUMNS"
                ."（替換會打斷關聯）；若它是數字型代碼欄 ⇒ 加進本測試的 EXEMPT 並寫理由。\n"
                .implode("\n", $unregistered)
        );
    }

    /** join 設定裡兩側的表都必須是「已知 CBDB 資料表」，否則 fail-closed 會讓它靜默跳過。 */
    #[Test]
    public function testEveryJoinedTableIsKnown(): void {
        $unknown = [];

        foreach ($this->joinKeyColumns() as $qualified) {
            [$table] = explode('.', $qualified, 2);
            if (!VariantReplaceScope::isKnownDataTable($table)) {
                $unknown[] = $table;
            }
        }

        $unknown = array_values(array_unique($unknown));
        sort($unknown);

        $this->assertSame([], $unknown, '下列 join 設定涉及的表不在已知 CBDB 表聯集內：'.implode(', ', $unknown));
    }

    /**
     * 從 `$tableJoinConfigurations` 抽出所有 join `on` 條件涉及的「表.欄位」。
     *
     * `on` 的形狀是 ['rel.c_x', '=', 'type.c_y']，其中 rel／type 是 alias，
     * 需要用 base_alias／各 join 的 alias 還原成真實表名。
     *
     * @return array<int,string>
     */
    private function joinKeyColumns(): array {
        $property = (new ReflectionClass(CodesController::class))->getProperty('tableJoinConfigurations');
        $property->setAccessible(true);
        $configurations = $property->getValue(app(CodesController::class));

        $columns = [];

        foreach ($configurations as $config) {
            $aliasToTable = [];
            if (isset($config['base_alias'], $config['base_table'])) {
                $aliasToTable[$config['base_alias']] = $config['base_table'];
            }
            foreach ($config['joins'] ?? [] as $join) {
                if (isset($join['alias'], $join['table'])) {
                    $aliasToTable[$join['alias']] = $join['table'];
                }
            }

            foreach ($config['joins'] ?? [] as $join) {
                foreach ([$join['on'][0] ?? null, $join['on'][2] ?? null] as $ref) {
                    if (!is_string($ref) || !str_contains($ref, '.')) {
                        continue;
                    }
                    [$alias, $column] = explode('.', $ref, 2);
                    $table = $aliasToTable[$alias] ?? $alias;
                    $columns[$table.'.'.$column] = true;
                }
            }
        }

        return array_keys($columns);
    }

    /** 是否已登記為排除（大小寫不敏感，比照 VariantReplaceScope 的比對方式）。 */
    private function isRegisteredAsExcluded(string $table, string $column): bool {
        $t = strtolower($table);
        $c = strtolower($column);

        foreach (VariantReplaceScope::EXCLUDED_TABLES as $excludedTable) {
            if (strtolower($excludedTable) === $t) {
                return true;
            }
        }

        foreach (VariantReplaceScope::EXCLUDED_COLUMNS_ANY_TABLE as $excludedColumn) {
            if (strtolower($excludedColumn) === $c) {
                return true;
            }
        }

        foreach (VariantReplaceScope::EXCLUDED_COLUMNS as $excludedTable => $excludedColumns) {
            if (strtolower($excludedTable) !== $t) {
                continue;
            }
            foreach ($excludedColumns as $excludedColumn) {
                if (strtolower($excludedColumn) === $c) {
                    return true;
                }
            }
        }

        return false;
    }
}
