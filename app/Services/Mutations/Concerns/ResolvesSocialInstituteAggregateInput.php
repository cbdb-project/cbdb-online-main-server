<?php

namespace App\Services\Mutations\Concerns;

use App\Services\Import\SocialInstituteImportService;
use Illuminate\Support\Facades\DB;

/**
 * 「社會機構實體」聚合輸入的解析與校驗（update 用；create 維持 SocialInstituteImportHandler
 * 既有語義以相容批量匯入）。必填核心與 create 一致（AGENTS.md：必填欄位 create／update 一致）：
 * name、type_code、dynasty_code、source_id、至少一列地址。其餘欄位選填，給值時校驗參照表存在。
 *
 * 回傳 [errors, input]；input 形狀即 SocialInstituteImportService::update() 的輸入。
 */
trait ResolvesSocialInstituteAggregateInput {
    /**
     * @param array<string, mixed> $changes
     * @return array{0: array<string, array<int, mixed>>, 1: array<string, mixed>}
     */
    protected function validateSocialInstituteAggregate(array $changes, SocialInstituteImportService $service): array {
        $errors = [];

        $name = trim((string) ($this->scalarOrNull($changes['name'] ?? $changes['c_inst_name_hz'] ?? null) ?? ''));
        if ($name === '') {
            $errors['name'] = ['required'];
        }

        $typeMap = $service->typeMap();
        $typeCode = $this->scalarOrNull($changes['type_code'] ?? $changes['c_inst_type_code'] ?? null);
        if ($typeCode === null || $typeCode === '' || !in_array((int) $typeCode, $typeMap, true)) {
            $errors['type'] = ['invalid'];
        }

        $dynastyMap = $service->dynastyMap();
        $dynastyCode = $this->scalarOrNull($changes['dynasty_code'] ?? $changes['c_inst_begin_dy'] ?? null);
        if ($dynastyCode === null || $dynastyCode === '' || !in_array((int) $dynastyCode, $dynastyMap, true)) {
            $errors['dynasty'] = ['invalid'];
        }

        $sourceId = $this->scalarOrNull($changes['source_id'] ?? $changes['c_source'] ?? null);
        if ($sourceId === null || $sourceId === '' || !ctype_digit((string) $sourceId)) {
            $errors['source_id'] = ['required_integer'];
        } elseif ($service->missingSourceIds([(int) $sourceId]) !== []) {
            $errors['source_id'] = ['not_found_in_text_codes'];
        }

        // 選填整數欄：給值須為整數（容許負年份不必要——年份欄實為 smallint，仍收整數）。
        $optInt = function (string $key, ...$aliases) use ($changes, &$errors) {
            $raw = null;
            foreach ([$key, ...$aliases] as $k) {
                if (array_key_exists($k, $changes)) {
                    $raw = $this->scalarOrNull($changes[$k]);

                    break;
                }
            }
            if ($raw === null || $raw === '') {
                return null;
            }
            if (!preg_match('/^-?\d+$/', (string) $raw)) {
                $errors[$key] = ['integer'];

                return null;
            }

            return (int) $raw;
        };

        $input = [
            'name' => $name,
            'type_code' => (int) $typeCode,
            'dynasty_code' => (int) $dynastyCode,
            'source_id' => (int) $sourceId,
            'begin_year' => $optInt('begin_year', 'c_inst_begin_year'),
            'by_nianhao_code' => $optInt('by_nianhao_code', 'c_by_nianhao_code'),
            'by_nianhao_year' => $optInt('by_nianhao_year', 'c_by_nianhao_year'),
            'by_year_range' => $optInt('by_year_range', 'c_by_year_range'),
            'floruit_dy' => $optInt('floruit_dy', 'c_inst_floruit_dy'),
            'first_known_year' => $optInt('first_known_year', 'c_inst_first_known_year'),
            'end_year' => $optInt('end_year', 'c_inst_end_year'),
            'ey_nianhao_code' => $optInt('ey_nianhao_code', 'c_ey_nianhao_code'),
            'ey_nianhao_year' => $optInt('ey_nianhao_year', 'c_ey_nianhao_year'),
            'ey_year_range' => $optInt('ey_year_range', 'c_ey_year_range'),
            'end_dy' => $optInt('end_dy', 'c_inst_end_dy'),
            'last_known_year' => $optInt('last_known_year', 'c_inst_last_known_year'),
            'pages' => ($v = $this->scalarOrNull($changes['pages'] ?? $changes['c_pages'] ?? null)) !== null && $v !== '' ? (string) $v : null,
            'notes' => ($v = $this->scalarOrNull($changes['notes'] ?? $changes['c_notes'] ?? null)) !== null && $v !== '' ? (string) $v : null,
        ];

        // 參照表存在性（僅對給值欄）：朝代／年號／year range 皆為 CASCADE 外鍵目標，寫入不存在
        // 的碼會直接 FK 失敗，這裡先以 422 擋下並給欄位級錯誤。
        foreach (['floruit_dy', 'end_dy'] as $k) {
            if ($input[$k] !== null && !in_array($input[$k], $dynastyMap, true)) {
                $errors[$k] = ['invalid'];
            }
        }
        foreach (['by_nianhao_code', 'ey_nianhao_code'] as $k) {
            if ($input[$k] !== null && !DB::table('NIAN_HAO')->where('c_nianhao_id', $input[$k])->exists()) {
                $errors[$k] = ['not_found_in_nian_hao'];
            }
        }
        foreach (['by_year_range', 'ey_year_range'] as $k) {
            if ($input[$k] !== null && !DB::table('YEAR_RANGE_CODES')->where('c_range_code', $input[$k])->exists()) {
                $errors[$k] = ['not_found_in_year_range_codes'];
            }
        }

        // 地址列：至少一列（與 create 必填 addr_id 一致）；逐列校驗 addr_id 存在。
        $rawAddresses = $changes['addresses'] ?? null;
        $addresses = [];
        if (!is_array($rawAddresses) || $rawAddresses === []) {
            $errors['addresses'] = ['required'];
        } else {
            $addrIds = [];
            foreach (array_values($rawAddresses) as $i => $row) {
                if (!is_array($row)) {
                    $errors["addresses.$i"] = ['invalid'];

                    continue;
                }
                $addrId = $this->scalarOrNull($row['addr_id'] ?? $row['c_inst_addr_id'] ?? null);
                if ($addrId === null || $addrId === '' || !ctype_digit((string) $addrId)) {
                    $errors["addresses.$i.addr_id"] = ['required_integer'];

                    continue;
                }
                $addrIds[] = (int) $addrId;
                $addresses[] = [
                    'addr_id' => (int) $addrId,
                    'addr_type_code' => (int) ($this->scalarOrNull($row['addr_type_code'] ?? $row['c_inst_addr_type_code'] ?? null) ?? 1),
                    'begin_year' => $this->scalarOrNull($row['begin_year'] ?? $row['c_inst_addr_begin_year'] ?? null),
                    'end_year' => $this->scalarOrNull($row['end_year'] ?? $row['c_inst_addr_end_year'] ?? null),
                    'xcoord' => (float) ($this->scalarOrNull($row['xcoord'] ?? $row['inst_xcoord'] ?? null) ?? 0),
                    'ycoord' => (float) ($this->scalarOrNull($row['ycoord'] ?? $row['inst_ycoord'] ?? null) ?? 0),
                    'source_id' => $this->scalarOrNull($row['source_id'] ?? $row['c_source'] ?? null),
                    'pages' => $this->scalarOrNull($row['pages'] ?? $row['c_pages'] ?? null),
                    'notes' => $this->scalarOrNull($row['notes'] ?? $row['c_notes'] ?? null),
                ];
            }
            $missing = $service->missingAddrIds($addrIds);
            if ($missing !== []) {
                $errors['addresses'] = ['not_found_in_addr_codes' => $missing];
            }
        }
        $input['addresses'] = $addresses;

        return [$errors, $input];
    }
}
