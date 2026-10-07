<?php

namespace App\Services\Mutations\Concerns;

use App\Services\Import\SocialInstituteImportService;
use Illuminate\Support\Facades\DB;

/**
 * 「社會機構實體」聚合輸入的解析與校驗（update 用；create 維持 SocialInstitutionAggregateDefinition::validateCreate
 * 既有語義以相容批量匯入）。必填核心與 create 一致（AGENTS.md：必填欄位 create／update 一致）：
 * name、type_code、dynasty_code、source_id、至少一列地址。其餘欄位選填，給值時校驗參照表存在。
 * 別名清單 alt_names 選填、且**不帶＝不動**（見 parseSocialInstituteAltNames()），create 亦共用。
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

        // 白名單一律用 *Codes()：標籤歸一後鍵碰撞時 map 只留最小碼，
        // 拿 map 的值當白名單會讓另一個完全合法的代碼開始被判 invalid。
        $dynastyCodes = $service->dynastyCodes();

        $typeCode = $this->scalarOrNull($changes['type_code'] ?? $changes['c_inst_type_code'] ?? null);
        if ($typeCode === null || $typeCode === '' || !in_array((int) $typeCode, $service->typeCodes(), true)) {
            $errors['type'] = ['invalid'];
        }

        $dynastyCode = $this->scalarOrNull($changes['dynasty_code'] ?? $changes['c_inst_begin_dy'] ?? null);
        if ($dynastyCode === null || $dynastyCode === '' || !in_array((int) $dynastyCode, $dynastyCodes, true)) {
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
            if ($input[$k] !== null && !in_array((int) $input[$k], $dynastyCodes, true)) {
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
        $input['alt_names'] = $this->parseSocialInstituteAltNames($changes, $service, $errors);

        return [$errors, $input];
    }

    /**
     * `alt_names`（SOCIAL_INSTITUTION_ALTNAME_DATA）的解析與校驗；create／update 共用。
     *
     * **鍵不存在＝不動別名**（回 null），刻意不同於聚合其餘欄位的「全欄覆寫」：既有呼叫端
     * （React 編輯頁、舊客戶端）送的整份 payload 都沒有這個鍵，若照全欄覆寫語義解讀成「清空」，
     * 第一次存檔就會把別名全數刪掉。鍵存在（含空陣列）才是「以這份清單為準」做集合對賬。
     *
     * 每列：`name`（或 `c_inst_altname_hz`）必填；`type_code`（`c_inst_altname_type`）選填——
     * 鍵不存在預設 0、明示 null 則保留 null（讓 load() 讀回的列能原樣送回），給值須存在於
     * SOCIAL_INSTITUTION_ALTNAME_CODES；`source_id`（`c_source`）選填、須存在於 TEXT_CODES；
     * `pinyin`／`pages`／`notes` 選填。
     * 這裡只擋**字面完全相同**的重複（同類型、同原字）；「歸一後相同、字面不同」要看既有列才能
     * 判斷（兩形若本來就並存，送回兩形是合法的），交給 definition 的 guardWrite()。
     *
     * @param array<string, mixed>              $changes
     * @param array<string, array<int, mixed>>  $errors  就地追加錯誤
     * @return array<int, array<string, mixed>>|null
     */
    protected function parseSocialInstituteAltNames(array $changes, SocialInstituteImportService $service, array &$errors): ?array {
        if (!array_key_exists('alt_names', $changes)) {
            return null;
        }

        $raw = $changes['alt_names'];
        if (!is_array($raw) || ($raw !== [] && !array_is_list($raw))) {
            $errors['alt_names'] = ['invalid'];

            return null;
        }

        $typeCodes = $service->altNameTypeCodes();
        $text = function (array $row, string $field, string $column, string $label, int $i, ?int $max) use (&$errors) {
            $value = $row[$field] ?? $row[$column] ?? null;
            if ($value !== null && !is_scalar($value)) {
                $errors["alt_names.$i.$label"] = ['invalid'];

                return null;
            }
            $value = $value === null ? null : trim((string) $value);
            if ($value === '' || $value === null) {
                return null;
            }
            if ($max !== null && mb_strlen($value) > $max) {
                $errors["alt_names.$i.$label"] = ['too_long'];

                return null;
            }

            return $value;
        };
        $integer = function (array $row, string $field, string $column, int $i) use (&$errors): ?int {
            $value = $row[$field] ?? $row[$column] ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            if (!is_scalar($value) || !preg_match('/^-?\d+$/', (string) $value)) {
                $errors["alt_names.$i.$field"] = ['integer'];

                return null;
            }

            return (int) $value;
        };

        $rows = [];
        $seen = [];
        $sourceIds = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                $errors["alt_names.$i"] = ['invalid'];

                continue;
            }
            $name = $text($row, 'name', 'c_inst_altname_hz', 'name', $i, 255);
            if ($name === null) {
                $errors["alt_names.$i.name"] ??= ['required'];

                continue;
            }
            $typeGiven = array_key_exists('type_code', $row) || array_key_exists('c_inst_altname_type', $row);
            $type = $integer($row, 'type_code', 'c_inst_altname_type', $i);
            if ($type === null && !$typeGiven) {
                $type = 0;
            }
            if ($type !== null && !in_array($type, $typeCodes, true)) {
                $errors["alt_names.$i.type_code"] ??= ['not_found_in_altname_codes'];
            }
            $sourceId = $integer($row, 'source_id', 'c_source', $i);
            if ($sourceId !== null) {
                $sourceIds[$i] = $sourceId;
            }

            $dedupeKey = SocialInstituteImportService::altNameLiteralKey($type, $name);
            if (isset($seen[$dedupeKey])) {
                $errors["alt_names.$i"] = ['duplicate'];

                continue;
            }
            $seen[$dedupeKey] = true;

            $rows[] = [
                'type_code' => $type,
                'name' => $name,
                'pinyin' => $text($row, 'pinyin', 'c_inst_altname_py', 'pinyin', $i, 255),
                'source_id' => $sourceId,
                'pages' => $text($row, 'pages', 'c_pages', 'pages', $i, 255),
                'notes' => $text($row, 'notes', 'c_notes', 'notes', $i, null),
            ];
        }

        $missing = $service->missingSourceIds(array_values($sourceIds));
        foreach ($sourceIds as $i => $sourceId) {
            if (in_array($sourceId, $missing, true)) {
                $errors["alt_names.$i.source_id"] = ['not_found_in_text_codes'];
            }
        }

        return $rows;
    }
}
