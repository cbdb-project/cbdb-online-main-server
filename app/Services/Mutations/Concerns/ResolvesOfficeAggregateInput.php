<?php

namespace App\Services\Mutations\Concerns;

use App\Services\Import\OfficeImportService;

/**
 * 「官職實體」聚合寫入的共用輸入解析與欄位校驗，供 create／update handler 共用，
 * 確保新增與編輯兩路的校驗語意單一真源、不漂移（CLAUDE.md：必填欄位 create／update 一致）。
 *
 * 使用端須為 AbstractMutationHandler 子類（提供 scalarOrNull()）。
 */
trait ResolvesOfficeAggregateInput {
    /** 解析類型 id 集合：type_ids[]（優先）或單值 type_id / c_office_tree_id。回傳去重、非空字串陣列。 */
    protected function resolveOfficeTypeIds(array $changes): array {
        $raw = $changes['type_ids'] ?? null;
        if (is_array($raw)) {
            $list = $raw;
        } else {
            $single = $this->scalarOrNull($changes['type_id'] ?? $changes['c_office_tree_id'] ?? null);
            $list = ($single === null || $single === '') ? [] : [$single];
        }
        $out = [];
        foreach ($list as $v) {
            if (is_array($v)) {
                continue;
            }
            $v = trim((string) $v);
            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * 共用欄位校驗（name / dynasty / type_ids / source）。
     *
     * @return array{0:array<string,array<int,string>>,1:array{name:string,translation:mixed,dynasty_code:?int,type_ids:array,source_id:?int}}
     *   [errors, resolved]；errors 非空時呼叫端回 422。
     */
    protected function validateOfficeAggregate(array $changes, OfficeImportService $service): array {
        $name = trim((string) ($this->scalarOrNull($changes['name'] ?? $changes['c_office_chn'] ?? null) ?? ''));
        $translation = $this->scalarOrNull($changes['translation'] ?? $changes['c_office_trans'] ?? null);
        $sourceId = $this->scalarOrNull($changes['source_id'] ?? $changes['c_source'] ?? null);

        // 朝代：給碼（dynasty_code/c_dy）優先；否則以朝代名（dynasty_label）解析。
        $dynastyMap = $service->dynastyMap();
        $dynastyCode = $this->scalarOrNull($changes['dynasty_code'] ?? $changes['c_dy'] ?? null);
        $dynastyLabelError = false;
        if (($dynastyCode === null || $dynastyCode === '') && isset($changes['dynasty_label'])) {
            $label = trim((string) ($this->scalarOrNull($changes['dynasty_label']) ?? ''));
            $dynastyCode = $dynastyMap[$label] ?? null;
            if ($dynastyCode === null) {
                $dynastyLabelError = true;
            }
        }

        $typeIds = $this->resolveOfficeTypeIds($changes);

        $errors = [];
        if ($dynastyLabelError) {
            $errors['dynasty_label'] = ['not_found'];
        }
        if ($name === '') {
            $errors['name'] = ['required'];
        }
        if ($dynastyCode === null || $dynastyCode === '' || !in_array((int) $dynastyCode, $dynastyMap, true)) {
            $errors['dynasty'] = ['invalid'];
        }
        if ($typeIds === []) {
            $errors['type_ids'] = ['required'];
        } elseif ($service->missingOfficeTypes($typeIds) !== []) {
            $errors['type_ids'] = ['not_found_in_office_type_tree'];
        }
        if ($sourceId === null || $sourceId === '' || !ctype_digit((string) $sourceId)) {
            $errors['source_id'] = ['required_integer'];
        } elseif ($service->missingSourceIds([(int) $sourceId]) !== []) {
            $errors['source_id'] = ['not_found_in_text_codes'];
        }

        return [$errors, [
            'name' => $name,
            'translation' => $translation,
            'dynasty_code' => ($dynastyCode !== null && $dynastyCode !== '') ? (int) $dynastyCode : null,
            'type_ids' => $typeIds,
            'source_id' => ($sourceId !== null && $sourceId !== '') ? (int) $sourceId : null,
        ]];
    }
}
