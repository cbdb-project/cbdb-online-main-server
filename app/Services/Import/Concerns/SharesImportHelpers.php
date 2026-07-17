<?php

namespace App\Services\Import\Concerns;

use App\Models\Operation;
use App\Services\PinyinDictionary;
use App\Support\CompositePrimaryKey;
use App\Support\PinyinUmlaut;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Import service 共用的派生／校驗／審計基元。
 *
 * OfficeImportService 與 SocialInstituteImportService 共用：朝代對照、來源校驗、
 * 拼音派生與 operations + audit_log 記錄只有一份實作、不漂移。
 * 使用端須以建構子注入 $operationRepository（OperationRepository）與 $auditLogService（AuditLogService）。
 */
trait SharesImportHelpers {
    /** 朝代名→代碼對照。 */
    public function dynastyMap(): array {
        return DB::table('DYNASTIES')
            ->orderBy('c_dy')
            ->pluck('c_dy', 'c_dynasty_chn')
            ->mapWithKeys(fn ($code, $label) => [trim((string) $label) => (int) $code])
            ->toArray();
    }

    /** 校驗來源 TEXT_ID 存在於 TEXT_CODES；回傳缺失的 id。 */
    public function missingSourceIds(array $sourceIds): array {
        $unique = array_values(array_unique(array_map('intval', array_filter($sourceIds, fn ($v) => $v !== '' && $v !== null))));
        if (empty($unique)) {
            return [];
        }
        $found = DB::table('TEXT_CODES')->whereIn('c_textid', $unique)->pluck('c_textid')->map(fn ($v) => (int) $v)->all();

        return array_values(array_diff($unique, $found));
    }

    /** 中文字串轉空格分隔拼音（v→ü 正規化）。 */
    public function buildPinyin(string $value): string {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $syllables = [];
        foreach ($chars as $char) {
            if (preg_match('/\p{Han}/u', $char)) {
                $syllables[] = strtolower(PinyinDictionary::getPinyin($char));
            } elseif (preg_match('/[A-Za-z0-9]/u', $char)) {
                $syllables[] = strtolower($char);
            }
        }
        $syllables = array_filter($syllables, fn ($s) => $s !== '');
        if (empty($syllables)) {
            return strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
        }

        return PinyinUmlaut::normalize(implode(' ', $syllables));
    }

    /**
     * lockForUpdate 序列化的 max()+1 配號：兩個同時到達的請求若讀到同一 max，
     * 後者 insert 會撞主鍵而 500。MariaDB 生效；SQLite（測試）grammar 編譯為 no-op。
     */
    protected function allocateNextId(string $table, string $column): int {
        return max(0, (int) DB::table($table)->lockForUpdate()->max($column)) + 1;
    }

    /**
     * 引用計數：實體識別鍵在各引用表的列數總和（引用護欄用；>0 不可刪除／不可改識別性屬性）。
     *
     * @param array<int, array{0: string, 1: string}> $references [[table, fkColumn], ...]
     */
    protected function countReferences(array $references, int $id): int {
        $total = 0;
        foreach ($references as [$table, $column]) {
            $total += (int) DB::table($table)->where($column, $id)->count();
        }

        return $total;
    }

    /**
     * 配套列集合對賬：以列鍵（keyFn）比對現況與期望，只增刪差異；同鍵而值不同時
     * 僅改 $updatableColumns 內的非鍵欄（null＝同鍵永不改寫，如純關聯表）。
     * 每筆寫入逐一記 operations + audit_log（可個別復原）。
     *
     * $current／$desired 為「以 keyFn 結果為鍵」的正規化列陣列；pkFn(row) 兼作
     * 審計主鍵與 delete／update 的 where 條件（呼叫端須保證其足以唯一定位一列）。
     *
     * @param array<string, array> $current
     * @param array<string, array> $desired
     * @param callable(array): array $pkFn
     * @param callable(array): array $payloadFn
     * @param array<int, string>|null $updatableColumns payload 中允許同鍵改寫的欄名
     * @return array{added: int, removed: int, updated: int}
     */
    protected function reconcileRowSet(
        string $table,
        array $current,
        array $desired,
        callable $pkFn,
        callable $payloadFn,
        ?array $updatableColumns,
        int $actorPersonId
    ): array {
        $whereByPk = function (array $row) use ($table, $pkFn) {
            $query = DB::table($table);
            foreach ($pkFn($row) as $column => $value) {
                $query->where($column, $value);
            }

            return $query;
        };

        $added = 0;
        $removed = 0;
        $updated = 0;
        foreach ($current as $key => $row) {
            if (!isset($desired[$key])) {
                $whereByPk($row)->delete();
                $this->recordDelete($table, $pkFn($row), $payloadFn($row), $actorPersonId);
                $removed++;
            }
        }
        foreach ($desired as $key => $row) {
            if (!isset($current[$key])) {
                DB::table($table)->insert($payloadFn($row));
                $this->recordOp($table, $pkFn($row), $payloadFn($row), $actorPersonId);
                $added++;
            } elseif ($updatableColumns !== null && $current[$key] !== $row) {
                $whereByPk($row)->update(array_intersect_key($payloadFn($row), array_flip($updatableColumns)));
                $this->recordUpdate($table, $pkFn($row), $payloadFn($current[$key]), $payloadFn($row), $actorPersonId);
                $updated++;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'updated' => $updated];
    }

    /** 寫一筆 operations + audit_log（INSERT）。 */
    protected function recordOp(string $table, array $pk, array $rowData, int $actorPersonId): ?Operation {
        $operation = $this->operationRepository->store(
            Auth::id(),
            $actorPersonId,
            Operation::TYPE_CREATE,
            $table,
            CompositePrimaryKey::buildStoredResourceId($pk),
            $rowData,
            []
        );

        $this->auditLogService->write(
            $table,
            'INSERT',
            $pk,
            null,
            $this->auditLogService->normalizeRow($rowData),
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        return $operation;
    }

    /** 寫一筆 operations + audit_log（UPDATE）。resource_data=更新後、resource_original=更新前。 */
    protected function recordUpdate(string $table, array $pk, array $before, array $after, int $actorPersonId): ?Operation {
        $operation = $this->operationRepository->store(
            Auth::id(),
            $actorPersonId,
            Operation::TYPE_UPDATE,
            $table,
            CompositePrimaryKey::buildStoredResourceId($pk),
            $after,
            $before
        );

        $this->auditLogService->write(
            $table,
            'UPDATE',
            $pk,
            $this->auditLogService->normalizeRow($before),
            $this->auditLogService->normalizeRow($after),
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        return $operation;
    }

    /** 寫一筆 operations + audit_log（DELETE）。resource_data=刪除前快照（供復原重建）。 */
    protected function recordDelete(string $table, array $pk, array $before, int $actorPersonId): ?Operation {
        $operation = $this->operationRepository->store(
            Auth::id(),
            $actorPersonId,
            Operation::TYPE_DELETE,
            $table,
            CompositePrimaryKey::buildStoredResourceId($pk),
            $before,
            $before
        );

        $this->auditLogService->write(
            $table,
            'DELETE',
            $pk,
            $this->auditLogService->normalizeRow($before),
            null,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        return $operation;
    }
}
