<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 補回 EVENTS_ADDR_ibfk_3（EVENTS_ADDR.c_event_code → EVENT_CODES.c_event_code）。
 *
 * 背景：`2026_02_12_000001_convert_fields_to_smallint` 為改 EVENTS_ADDR.c_event_code
 * 型別，於 dropForeignKeys() 動態刪除此 FK，但 restoreForeignKeys() 的靜態白名單漏了
 * 這一條，導致該 FK 自此靜默遺失（見 docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md §9.2）。
 *
 * ON DELETE 採 RESTRICT（而非原本的 CASCADE）：EVENT_CODES 已是 CASCADE→RESTRICT
 * 分批翻轉（批次 3，見同上文件）的被引用表之一，同表另一入邊 EVENTS_DATA_ibfk_3 亦已
 * 翻為 RESTRICT，此處直接補為終態，避免補回 CASCADE 後又要再翻一次。
 */
return new class () extends Migration {
    private const CONSTRAINT_NAME = 'EVENTS_ADDR_ibfk_3';

    public function up(): void {
        if (!is_mysql()) {
            return; // SQLite 無外鍵，測試環境本就無此問題
        }

        $state = $this->currentConstraintState();

        if ($state !== null) {
            if (!$this->isExpectedState($state)) {
                throw new \RuntimeException($this->mismatchMessage($state));
            }

            return; // 已是目標定義，冪等跳過
        }

        $orphanCount = DB::selectOne('
            SELECT COUNT(*) AS c
            FROM `EVENTS_ADDR` ea
            LEFT JOIN `EVENT_CODES` ec ON ea.c_event_code = ec.c_event_code
            WHERE ec.c_event_code IS NULL
        ')->c;

        if ($orphanCount > 0) {
            throw new \RuntimeException(
                "無法補回 EVENTS_ADDR_ibfk_3：EVENTS_ADDR 中有 {$orphanCount} 筆 c_event_code ".
                '在 EVENT_CODES 找不到對應值，需先清洗孤兒資料才能重建外鍵。'
            );
        }

        DB::statement('
            ALTER TABLE `EVENTS_ADDR`
            ADD CONSTRAINT `'.self::CONSTRAINT_NAME.'`
            FOREIGN KEY (`c_event_code`) REFERENCES `EVENT_CODES` (`c_event_code`)
            ON DELETE RESTRICT ON UPDATE CASCADE
        ');
    }

    public function down(): void {
        if (!is_mysql()) {
            return;
        }

        $state = $this->currentConstraintState();

        if ($state === null) {
            return;
        }

        if (!$this->isExpectedState($state)) {
            throw new \RuntimeException(
                '無法回滾 EVENTS_ADDR_ibfk_3：同名約束存在，但定義與本 migration 補上的不符，'.
                '不確定是否為同一條約束，拒絕自動刪除。'.$this->mismatchMessage($state)
            );
        }

        DB::statement('ALTER TABLE `EVENTS_ADDR` DROP FOREIGN KEY `'.self::CONSTRAINT_NAME.'`');
    }

    /**
     * 目前定義是否等於本 migration 意圖建立／回滾的目標狀態
     * （EVENTS_ADDR.c_event_code -> EVENT_CODES.c_event_code, RESTRICT/CASCADE）。
     */
    private function isExpectedState(object $state): bool {
        return $state->column_name === 'c_event_code'
            && $state->ref_table === 'EVENT_CODES'
            && $state->ref_column === 'c_event_code'
            && in_array($state->delete_rule, ['RESTRICT', 'NO ACTION'], true)
            && $state->update_rule === 'CASCADE';
    }

    private function mismatchMessage(object $state): string {
        return "（column={$state->column_name}, ref={$state->ref_table}.{$state->ref_column}, ".
            "delete={$state->delete_rule}, update={$state->update_rule}）需人工確認後再處理。";
    }

    /**
     * 讀取同名約束目前的完整定義（欄位、被引用表欄、刪除／更新規則），
     * 而非只查名稱是否存在——避免同名但定義不符時被誤判為「已補回」或被誤刪。
     * 要求剛好 1 筆：若同名約束是多欄位複合外鍵，视为定義不符（非本 migration 預期形狀）。
     */
    private function currentConstraintState(): ?object {
        $rows = DB::select('
            SELECT
                kcu.COLUMN_NAME AS column_name,
                kcu.REFERENCED_TABLE_NAME AS ref_table,
                kcu.REFERENCED_COLUMN_NAME AS ref_column,
                rc.DELETE_RULE AS delete_rule,
                rc.UPDATE_RULE AS update_rule
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
                AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                AND rc.TABLE_NAME = kcu.TABLE_NAME
            WHERE kcu.TABLE_SCHEMA = DATABASE()
            AND kcu.TABLE_NAME = ?
            AND kcu.CONSTRAINT_NAME = ?
            ORDER BY kcu.ORDINAL_POSITION
        ', ['EVENTS_ADDR', self::CONSTRAINT_NAME]);

        if (count($rows) !== 1) {
            return count($rows) === 0 ? null : (object) [
                'column_name' => 'MULTI_COLUMN('.count($rows).')',
                'ref_table' => $rows[0]->ref_table,
                'ref_column' => 'MULTI_COLUMN',
                'delete_rule' => $rows[0]->delete_rule,
                'update_rule' => $rows[0]->update_rule,
            ];
        }

        return $rows[0];
    }
};
