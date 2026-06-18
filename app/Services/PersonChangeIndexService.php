<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * person_change_index（人物層級修改水位線）的共用寫入邏輯。
 *
 * 同時供：
 * - 即時路徑 AuditLogService::logChange()（每筆 mutation 在 transaction commit 後更新水位線）
 * - 重建命令 RebuildPersonChangeIndex（全量／增量回填）
 *
 * 集中「NULL-safe GREATEST upsert」與「c_personid 反查」這兩段最關鍵、最不能在兩處分歧的邏輯。
 * 設計見 docs/PERSON_CHANGE_INDEX_DESIGN.md。
 */
class PersonChangeIndexService {
    /**
     * 人物相關、會貢獻水位線的表（含 audit_log.table_name 可能出現的 BIOG_TEXT_DATA 別名 TEXT_DATA）。
     */
    public const PERSON_SCOPED_TABLES = [
        'ALTNAME_DATA',
        'ASSOC_DATA',
        'BIOG_ADDR_DATA',
        'BIOG_INST_DATA',
        'BIOG_MAIN',
        'BIOG_SOURCE_DATA',
        'BIOG_TEXT_DATA',
        'TEXT_DATA',
        'ENTRY_DATA',
        'EVENTS_DATA',
        'KIN_DATA',
        'POSSESSION_DATA',
        'POSTED_TO_ADDR_DATA',
        'POSTED_TO_OFFICE_DATA',
        'STATUS_DATA',
    ];

    private bool $tableConfirmed = false;

    public function isPersonScopedTable(string $table): bool {
        return in_array($table, self::PERSON_SCOPED_TABLES, true);
    }

    /**
     * person_change_index 是否存在。
     *
     * 只快取「true」：一旦確認存在就快取（常駐表不會被刪），單一 request/command 內不再重查 schema。
     * 不快取「false」：避免長駐程序（Octane/queue worker）若在 migration 前解析過此 singleton，
     * 把 false 一路 stale 到 worker 重啟導致所有即時更新被誤判為 no-op——table 出現後會自癒。
     * 也因此不用 static，避免測試間建表/刪表的快取污染。
     */
    public function tableExists(): bool {
        if ($this->tableConfirmed) {
            return true;
        }

        if (Schema::hasTable('person_change_index')) {
            $this->tableConfirmed = true;
        }

        return $this->tableConfirmed;
    }

    /**
     * 即時路徑：由一筆 audit 變更更新該人物水位線（由呼叫端透過 afterCommit 在交易提交後執行）。
     * 不更新 c_created_date（人物建檔時間由 rebuild 從 BIOG_MAIN 權威覆寫）。
     */
    public function recordChange(string $table, ?array $rowPk, ?array $newData, ?array $oldData, string $occurredAt): void {
        if (!$this->isPersonScopedTable($table) || !$this->tableExists()) {
            return;
        }

        $personId = $this->resolvePersonId($rowPk, $newData, $oldData);
        if ($personId === null) {
            return;
        }

        $this->upsert([[
            'c_personid' => $personId,
            'c_last_modified_date' => $occurredAt,
            'c_created_date' => null,
            'updated_at' => $occurredAt,
        ]], false);
    }

    /**
     * 從變更資料解析 c_personid：依序 row_pk → new_data → old_data（DELETE 走 old_data）。
     */
    public function resolvePersonId(?array $rowPk, ?array $newData, ?array $oldData): ?int {
        foreach ([$rowPk, $newData, $oldData] as $source) {
            if (is_array($source) && isset($source['c_personid']) && is_numeric($source['c_personid'])) {
                return (int) $source['c_personid'];
            }
        }

        return null;
    }

    /**
     * NULL-safe GREATEST upsert（單一 SQL statement）。呼叫端負責分批與交易。
     *
     * 兩庫等價語意：任一邊為 NULL → 取另一邊；皆有值 → 取較大；皆 NULL → 維持 NULL。
     * MySQL/MariaDB scalar GREATEST 與 SQLite scalar max 遇 NULL 都回 NULL，故必須用 IF/CASE 包起來。
     *
     * @param array<int, array{c_personid:int,c_last_modified_date:?string,c_created_date:?string,updated_at:string}> $rows
     */
    public function upsert(array $rows, bool $writeCreated): void {
        if (empty($rows) || !$this->tableExists()) {
            return;
        }

        $placeholders = [];
        $bindings = [];
        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, ?, ?)';
            $bindings[] = $row['c_personid'];
            $bindings[] = $row['c_last_modified_date'];
            $bindings[] = $row['c_created_date'];
            $bindings[] = $row['updated_at'];
        }
        $values = implode(', ', $placeholders);

        if (is_sqlite()) {
            $createdClause = $writeCreated ? ', c_created_date = excluded.c_created_date' : '';
            $sql = "INSERT INTO person_change_index (c_personid, c_last_modified_date, c_created_date, updated_at)
                    VALUES {$values}
                    ON CONFLICT(c_personid) DO UPDATE SET
                        c_last_modified_date = CASE
                            WHEN excluded.c_last_modified_date IS NULL THEN person_change_index.c_last_modified_date
                            WHEN person_change_index.c_last_modified_date IS NULL THEN excluded.c_last_modified_date
                            ELSE max(person_change_index.c_last_modified_date, excluded.c_last_modified_date)
                        END{$createdClause},
                        updated_at = excluded.updated_at";
        } else {
            // MySQL/MariaDB。MariaDB 10.3 支援 VALUES() 指涉新值。
            $createdClause = $writeCreated ? ', c_created_date = VALUES(c_created_date)' : '';
            $sql = "INSERT INTO person_change_index (c_personid, c_last_modified_date, c_created_date, updated_at)
                    VALUES {$values}
                    ON DUPLICATE KEY UPDATE
                        c_last_modified_date = IF(
                            VALUES(c_last_modified_date) IS NULL,
                            c_last_modified_date,
                            IF(
                                c_last_modified_date IS NULL,
                                VALUES(c_last_modified_date),
                                GREATEST(c_last_modified_date, VALUES(c_last_modified_date))
                            )
                        ){$createdClause},
                        updated_at = VALUES(updated_at)";
        }

        DB::statement($sql, $bindings);
    }
}
