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
}
