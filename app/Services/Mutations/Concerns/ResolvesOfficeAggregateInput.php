<?php

namespace App\Services\Mutations\Concerns;

use App\Services\Import\OfficeImportService;
use App\Support\VariantLabelMap;

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

        // 選填欄位（無 required 校驗；空字串由 service 折成 null）。
        $nameAlt = $this->scalarOrNull($changes['name_alt'] ?? $changes['c_office_chn_alt'] ?? null);
        $translationAlt = $this->scalarOrNull($changes['translation_alt'] ?? $changes['c_office_trans_alt'] ?? null);
        $pinyin = $this->scalarOrNull($changes['pinyin'] ?? $changes['c_office_pinyin'] ?? null);
        $pinyinAlt = $this->scalarOrNull($changes['pinyin_alt'] ?? $changes['c_office_pinyin_alt'] ?? null);
        $pages = $this->scalarOrNull($changes['pages'] ?? $changes['c_pages'] ?? null);
        $notes = $this->scalarOrNull($changes['notes'] ?? $changes['c_notes'] ?? null);

        // 朝代：給碼（dynasty_code/c_dy）優先；否則以朝代名（dynasty_label）解析。
        $dynastyMap = $service->dynastyMap();
        $dynastyCodes = $service->dynastyCodes();
        $dynastyCode = $this->scalarOrNull($changes['dynasty_code'] ?? $changes['c_dy'] ?? null);
        $dynastyLabelError = false;
        if (($dynastyCode === null || $dynastyCode === '') && isset($changes['dynasty_label'])) {
            $label = trim((string) ($this->scalarOrNull($changes['dynasty_label']) ?? ''));
            // map 的鍵已歸一，傳入標籤也要歸一，兩個方向才都命中（見 VariantLabelMap）。
            $dynastyCode = VariantLabelMap::lookup($dynastyMap, $label, 'DYNASTIES', 'c_dynasty_chn');
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
        // 白名單用 dynastyCodes() 而不是 map 的值：標籤歸一後鍵碰撞時 map 只留最小碼，
        // 拿 map 當白名單會讓另一個完全合法的 c_dy 開始被判 invalid。
        if ($dynastyCode === null || $dynastyCode === '' || !in_array((int) $dynastyCode, $dynastyCodes, true)) {
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
            'name_alt' => $nameAlt,
            'translation' => $translation,
            'translation_alt' => $translationAlt,
            'pinyin' => $pinyin,
            'pinyin_alt' => $pinyinAlt,
            'dynasty_code' => ($dynastyCode !== null && $dynastyCode !== '') ? (int) $dynastyCode : null,
            'type_ids' => $typeIds,
            'source_id' => ($sourceId !== null && $sourceId !== '') ? (int) $sourceId : null,
            'pages' => $pages,
            'notes' => $notes,
        ]];
    }
}
