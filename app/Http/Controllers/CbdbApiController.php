<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class CbdbApiController extends Controller
{
    public function person(Request $request)
    {
        $mode = strtolower((string) $request->query('mode', $request->query('o', 'html')));

        $validator = Validator::make($request->all(), [
            'id' => ['nullable', 'regex:/^\d{1,7}$/', function ($attribute, $value, $fail) {
                if ((int) $value < 1) {
                    $fail('The id must be at least 1.');
                }
            }],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($request) {
            if (!$request->filled('id') && !$request->filled('name')) {
                $validator->errors()->add('id', 'Either id or name parameter must be provided.');
            }
        });

        if ($validator->fails()) {
            if (!in_array($mode, ['json', 'xml'], true)) {
                // HTML mode: return error page
                return response()->view('cbdbapi.person', [
                    'personId' => '',
                    'searchResults' => [],
                    'searchTerm' => '',
                    'validationErrors' => $validator->errors()->all(),
                ], 422);
            } else {
                // JSON/XML mode: return JSON error
                return response()->json([
                    'error' => [
                        'code' => 422,
                        'message' => 'Validation failed.',
                        'details' => $validator->errors()->all(),
                    ],
                ], 422);
            }
        }

        $idParam = $request->query('id');
        $requestedNumericId = ($idParam !== null && $idParam !== '') ? (int) $idParam : null;
        $nameParam = $request->query('name');

        $searchResults = [];
        $resolvedId = null;

        if ($idParam) {
            $resolvedId = (int) $idParam;
        } elseif ($nameParam) {
            $searchResults = $this->searchPersonCandidates($nameParam, 20);
            $resolvedId = $searchResults[0]['id'] ?? null;
        }

        if (!in_array($mode, ['json', 'xml'], true)) {
            if ($resolvedId === null) {
                return response()->view('cbdbapi.person', [
                    'personId' => '',
                    'searchResults' => $searchResults,
                    'searchTerm' => $nameParam,
                ], 404);
            }

            return response()->view('cbdbapi.person', [
                'personId' => $resolvedId,
                'searchResults' => $searchResults,
                'searchTerm' => $nameParam,
            ]);
        }

        $personId = $resolvedId ?? $this->resolvePersonId($idParam, $nameParam);
        if ($personId === null) {
            return $this->jsonPersonNotFoundResponse($requestedNumericId);
        }

        $basicInfo = $this->fetchSingle($this->sqlBasicInfo(), [$personId]);

        if (!$basicInfo) {
            return $this->jsonPersonNotFoundResponse($requestedNumericId ?? $personId);
        }

        $sources = $this->fetchAll($this->sqlSources(), [$personId]);
        $sourcesAs = $this->fetchAll($this->sqlSourcesAs(), [$personId]);
        $aliases = $this->fetchAll($this->sqlAliases(), [$personId]);
        $addresses = $this->fetchAll($this->sqlAddresses(), [$personId]);
        $entries = $this->fetchAll($this->sqlEntries(), [$personId]);
        $postings = $this->fetchAll($this->sqlPostings(), [$personId]);
        $statuses = $this->fetchAll($this->sqlStatuses(), [$personId]);
        $kinships = $this->fetchAll($this->sqlKinships(), [$personId]);
        $associations = $this->fetchAll($this->sqlAssociations(), [$personId]);
        $texts = $this->fetchAll($this->sqlTexts(), [$personId]);

        $basicInfoString = $this->stringifyRow($basicInfo);
        $sourcesString = array_map([$this, 'stringifyRow'], $sources);
        $sourcesAsString = array_map([$this, 'stringifyRow'], $sourcesAs);
        $aliasesString = array_map([$this, 'stringifyRow'], $aliases);
        $addressesString = array_map([$this, 'stringifyRow'], $addresses);
        $entriesString = array_map([$this, 'stringifyRow'], $entries);
        $postingsString = array_map([$this, 'stringifyRow'], $postings);
        $statusesString = array_map([$this, 'stringifyRow'], $statuses);
        $kinshipsString = array_map([$this, 'stringifyRow'], $kinships);
        $associationsString = array_map([$this, 'stringifyRow'], $associations);
        $textsString = array_map([$this, 'stringifyRow'], $texts);

        $personPayload = [
            'BasicInfo' => $basicInfoString,
            'PersonSources' => [
                'Source' => $sourcesString,
            ],
            'PersonSourcesAs' => [
                'SourceAs' => $sourcesAsString,
            ],
            'PersonAliases' => [
                'Alias' => $aliasesString,
            ],
            'PersonAddresses' => [
                'Address' => $addressesString,
            ],
            'PersonEntryInfo' => [
                'Entry' => $entriesString,
            ],
            'PersonPostings' => [
                'Posting' => $postingsString,
            ],
            'PersonSocialStatus' => [
                'SocialStatus' => $statusesString,
            ],
            'PersonKinshipInfo' => [
                'Kinship' => $kinshipsString,
            ],
            'PersonSocialAssociation' => [
                'Association' => $associationsString,
            ],
            'PersonTexts' => [
                'Text' => $textsString,
            ],
        ];

        $personPayload = $this->stripEmptyCollections($personPayload);

        return response()->json([
            'Package' => [
                'PersonAuthority' => [
                    'DataSource' => 'CBDB',
                    'Version' => '20131220',
                    'PersonInfo' => [
                        'Person' => $personPayload,
                    ],
                ],
            ],
        ]);
    }

    protected function fetchAll(string $sql, array $bindings): array
    {
        return array_map(function ($row) {
            return $this->normalizeRow((array) $row);
        }, DB::select($sql, $bindings));
    }

    protected function fetchSingle(string $sql, array $bindings): ?array
    {
        $rows = $this->fetchAll($sql, $bindings);
        return $rows[0] ?? null;
    }

    protected function normalizeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value === null) {
                $row[$key] = null;
                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    $row[$key] = null;
                    continue;
                }
                if (is_numeric($trimmed) && !preg_match('/^0[0-9]+$/', $trimmed)) {
                    $row[$key] = strpos($trimmed, '.') === false ? (int) $trimmed : (float) $trimmed;
                } else {
                    $row[$key] = $trimmed;
                }
                continue;
            }

            if (is_numeric($value)) {
                $row[$key] = (strpos((string) $value, '.') === false) ? (int) $value : (float) $value;
            }
        }

        return $row;
    }

    protected function stringifyRow(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if ($value === null) {
                $result[$key] = '';
                continue;
            }
            if (is_scalar($value)) {
                $result[$key] = (string) $value;
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    protected function stripEmptyCollections(array $personPayload): array
    {
        foreach ($personPayload as $section => $wrapper) {
            if (!is_array($wrapper)) {
                continue;
            }
            $elementKey = $this->arrayKeyFirst($wrapper);
            if ($elementKey === null) {
                continue;
            }
            $items = $wrapper[$elementKey];
            if (is_array($items) && empty($items)) {
                $personPayload[$section] = '';
            }
        }

        return $personPayload;
    }

    protected function arrayKeyFirst(array $array)
    {
        foreach ($array as $key => $value) {
            return $key;
        }

        return null;
    }

    protected function resolvePersonId(?string $idParam, ?string $nameParam): ?int
    {
        if ($idParam !== null && $idParam !== '') {
            return (int) $idParam;
        }

        if ($nameParam === null) {
            return null;
        }

        return $this->findPersonIdByName($nameParam);
    }

protected function findPersonIdByName(string $name): ?int
{
        $candidate = $this->searchPersonCandidates($name, 1);
        return $candidate[0]['id'] ?? null;
}

    protected function searchPersonCandidates(string $name, int $limit = 20): array
    {
        $term = trim($name);

        if ($term === '') {
            return [];
        }

        $limit = max(1, $limit);
        $collector = [];

        $this->appendPersonIdsFromQuery(
            DB::table('BIOG_MAIN')
                ->select('c_personid')
                ->where(function ($query) use ($term) {
                    $query->where('c_name_chn', $term)
                        ->orWhere('c_name', $term)
                        ->orWhere('c_name_rm', $term);
                })
                ->orderBy('c_personid'),
            $collector,
            $limit
        );

        $this->appendPersonIdsFromQuery(
            DB::table('ALTNAME_DATA')
                ->select('c_personid')
                ->where(function ($query) use ($term) {
                    $query->where('c_alt_name_chn', $term)
                        ->orWhere('c_alt_name', $term);
                })
                ->orderBy('c_personid'),
            $collector,
            $limit
        );

        $likeTerm = '%' . $term . '%';

        $this->appendPersonIdsFromQuery(
            DB::table('BIOG_MAIN')
                ->select('c_personid')
                ->where(function ($query) use ($likeTerm) {
                    $query->where('c_name_chn', 'like', $likeTerm)
                        ->orWhere('c_name', 'like', $likeTerm)
                        ->orWhere('c_name_rm', 'like', $likeTerm);
                })
                ->orderBy('c_personid'),
            $collector,
            $limit
        );

        $this->appendPersonIdsFromQuery(
            DB::table('ALTNAME_DATA')
                ->select('c_personid')
                ->where(function ($query) use ($likeTerm) {
                    $query->where('c_alt_name_chn', 'like', $likeTerm)
                        ->orWhere('c_alt_name', 'like', $likeTerm);
                })
                ->orderBy('c_personid'),
            $collector,
            $limit
        );

        if (empty($collector)) {
            return [];
        }

        $collector = array_slice($collector, 0, $limit);

        $people = DB::table('BIOG_MAIN')
            ->select('c_personid', 'c_name_chn', 'c_name', 'c_name_rm')
            ->whereIn('c_personid', $collector)
            ->get()
            ->keyBy('c_personid');

        $results = [];
        foreach ($collector as $personId) {
            $personId = (int) $personId;
            if (!isset($people[$personId])) {
                continue;
            }
            $row = (array) $people[$personId];
            $label = $row['c_name_chn'] ?? '';
            if ($label === '') {
                $label = $row['c_name'] ?? '';
            }
            if ($label === '') {
                $label = $row['c_name_rm'] ?? '';
            }
            if ($label === '') {
                $label = 'ID ' . $personId;
            }
            $results[] = [
                'id' => $personId,
                'label' => $label,
            ];
        }

        return $results;
    }

    protected function appendPersonIdsFromQuery($builder, array &$collector, int $limit): void
    {
        if (count($collector) >= $limit) {
            return;
        }

        $fetchLimit = max($limit * 5, 20);
        $ids = $builder->limit($fetchLimit)->pluck('c_personid')->toArray();

        foreach ($ids as $id) {
            $id = (int) $id;
            if (!in_array($id, $collector, true)) {
                $collector[] = $id;
            }
            if (count($collector) >= $limit) {
                break;
            }
        }
    }

    protected function jsonPersonNotFoundResponse(?int $requestedId = null)
    {
        $error = [
            'code' => 404,
            'message' => 'Person not found.',
        ];

        if ($requestedId !== null) {
            $hint = $this->buildMergeHint($requestedId);
            if ($hint !== null) {
                $error['merge_hint'] = $hint;
            }
        }

        return response()->json(['error' => $error], 404);
    }

    protected function buildMergeHint(int $personId): ?array
    {
        if (!Schema::hasTable('MERGED_PERSON_DATA')) {
            return null;
        }

        $record = DB::table('MERGED_PERSON_DATA')
            ->select('c_personid', 'c_notes')
            ->where('c_merged_to_personid', $personId)
            ->orderByDesc('c_modified_date')
            ->orderByDesc('c_created_date')
            ->first();

        if (!$record) {
            return null;
        }

        $reason = $record->c_notes !== null ? trim((string) $record->c_notes) : null;
        if ($reason === '') {
            $reason = null;
        }

        return [
            'merged_to_person_id' => (int) $record->c_personid,
            'reason' => $reason,
        ];
    }

    protected function sqlBasicInfo(): string
    {
        $yearsLivedApprox = 'NULL AS YearsLivedApprox,';
        if (Schema::hasColumn('BIOG_MAIN', 'c_death_age_approx')) {
            $yearsLivedApprox = "(SELECT c_range_chn FROM YEAR_RANGE_CODES WHERE c_range_code = BIOG_MAIN.c_death_age_approx) AS YearsLivedApprox,";
        } elseif (Schema::hasColumn('BIOG_MAIN', 'c_death_age_range')) {
            $yearsLivedApprox = "(SELECT c_range_chn FROM YEAR_RANGE_CODES WHERE c_range_code = BIOG_MAIN.c_death_age_range) AS YearsLivedApprox,";
        }

        $sourceField = 'NULL AS Source,';
        if (Schema::hasColumn('BIOG_MAIN', 'c_source')) {
            $sourceField = "(SELECT c_title_chn FROM TEXT_CODES WHERE c_textid = BIOG_MAIN.c_source) AS Source,";
        }

        $sourcePagesField = Schema::hasColumn('BIOG_MAIN', 'c_pages') ? 'BIOG_MAIN.c_pages AS SourcePages,' : 'NULL AS SourcePages,';
        $notesField = Schema::hasColumn('BIOG_MAIN', 'c_notes') ? 'BIOG_MAIN.c_notes AS Notes' : 'NULL AS Notes';

        return <<<SQL
SELECT c_personid AS PersonId, c_name AS EngName, c_name_chn AS ChName, c_index_year AS IndexYear, c_index_addr_id AS IndexAddrId,
       (SELECT c_name_chn FROM ADDR_CODES WHERE c_addr_id = BIOG_MAIN.c_index_addr_id) AS IndexAddr,
       c_female AS Gender,
       c_birthyear AS YearBirth,
       (SELECT c_dynasty_chn FROM NIAN_HAO WHERE c_nianhao_id = BIOG_MAIN.c_by_nh_code) AS DynastyBirth,
       (SELECT c_dy FROM NIAN_HAO WHERE c_nianhao_id = BIOG_MAIN.c_by_nh_code) AS DynastyBirthId,
       (SELECT c_nianhao_chn FROM NIAN_HAO WHERE c_nianhao_id = BIOG_MAIN.c_by_nh_code) AS EraBirth,
       BIOG_MAIN.c_by_nh_code AS EraBirthId,
       c_by_nh_year AS EraYearBirth,
       c_deathyear AS YearDeath,
       (SELECT c_dynasty_chn FROM NIAN_HAO WHERE c_nianhao_id = BIOG_MAIN.c_dy_nh_code) AS DynastyDeath,
       (SELECT c_dy FROM NIAN_HAO WHERE c_nianhao_id = BIOG_MAIN.c_dy_nh_code) AS DynastyDeathId,
       (SELECT c_nianhao_chn FROM NIAN_HAO WHERE c_nianhao_id = BIOG_MAIN.c_dy_nh_code) AS EraDeath,
       BIOG_MAIN.c_dy_nh_code AS EraDeathId,
       c_dy_nh_year AS EraYearDeath,
       c_death_age AS YearsLived,
       {$yearsLivedApprox}
       (SELECT c_dynasty_chn FROM DYNASTIES WHERE c_dy = BIOG_MAIN.c_dy) AS Dynasty,
       BIOG_MAIN.c_dy AS DynastyId,
       (SELECT c_choronym_chn FROM CHORONYM_CODES WHERE c_choronym_code = BIOG_MAIN.c_choronym_code) AS JunWang,
       BIOG_MAIN.c_choronym_code AS JunWangId,
       {$sourceField}
       {$sourcePagesField}
       {$notesField}
FROM BIOG_MAIN
WHERE c_personid = ?
SQL;
    }

    protected function sqlSources(): string
    {
        return <<<SQL
SELECT (SELECT c_title_chn FROM TEXT_CODES WHERE c_textid = BIOG_SOURCE_DATA.c_textid) AS Source,
       c_textid AS SourceId,
       c_pages AS Pages,
       c_notes AS Notes
FROM BIOG_SOURCE_DATA
WHERE c_personid = ?
SQL;
    }

    protected function sqlSourcesAs(): string
    {
        return <<<SQL
SELECT (SELECT c_title_chn FROM TEXT_CODES WHERE c_textid = BIOG_SOURCE_DATA.c_textid) AS Source,
       c_textid AS SourceId,
       c_pages AS Pages,
       c_notes AS Notes
FROM BIOG_SOURCE_DATA
WHERE c_textid = 9602 AND c_personid = ?
SQL;
    }

    protected function sqlAliases(): string
    {
        return <<<SQL
SELECT (SELECT c_name_type_desc_chn FROM ALTNAME_CODES WHERE c_name_type_code = ALTNAME_DATA.c_alt_name_type_code) AS AliasType,
       ALTNAME_DATA.c_alt_name_type_code AS AliasTypeId,
       ALTNAME_DATA.c_alt_name_chn AS AliasName
FROM ALTNAME_DATA
WHERE c_personid = ?
SQL;
    }

    protected function sqlAddresses(): string
    {
        return <<<SQL
SELECT BIOG_ADDR_DATA.c_addr_type AS AddrTypeId,
       (SELECT c_addr_desc_chn FROM BIOG_ADDR_CODES WHERE c_addr_type = BIOG_ADDR_DATA.c_addr_type) AS AddrType,
       BIOG_ADDR_DATA.c_addr_id AS AddrId,
       (SELECT c_name_chn FROM ADDR_CODES WHERE c_addr_id = BIOG_ADDR_DATA.c_addr_id) AS AddrName,
       ADDR.belongs1_Name AS belongs1_name,
       ADDR.belongs1_Id AS belongs1_id,
       ADDR.belongs2_Name AS belongs2_name,
       ADDR.belongs2_Id AS belongs2_id,
       ADDR.belongs3_Name AS belongs3_name,
       ADDR.belongs3_Id AS belongs3_id,
       ADDR.belongs4_Name AS belongs4_name,
       ADDR.belongs4_Id AS belongs4_id,
       ADDR.belongs5_Name AS belongs5_name,
       ADDR.belongs5_Id AS belongs5_id,
       BIOG_ADDR_DATA.c_sequence AS MoveCount,
       BIOG_ADDR_DATA.c_firstyear AS FirstYear,
       BIOG_ADDR_DATA.c_lastyear AS LastYear,
       (SELECT c_title_chn FROM TEXT_CODES WHERE c_textid = BIOG_ADDR_DATA.c_source) AS Source,
       BIOG_ADDR_DATA.c_pages AS Pages,
       BIOG_ADDR_DATA.c_notes AS Notes
FROM BIOG_ADDR_DATA
LEFT JOIN View_Address AS ADDR
     ON ADDR.c_addr_id = BIOG_ADDR_DATA.c_addr_id
     AND (
         BIOG_ADDR_DATA.c_firstyear IS NULL
         OR ADDR.c_lastyear IS NULL
         OR BIOG_ADDR_DATA.c_firstyear <= ADDR.c_lastyear
     )
     AND (
         BIOG_ADDR_DATA.c_lastyear IS NULL
         OR ADDR.c_firstyear IS NULL
         OR BIOG_ADDR_DATA.c_lastyear >= ADDR.c_firstyear
     )
     AND NOT EXISTS (
         SELECT 1 FROM View_Address VA2
         WHERE VA2.c_addr_id = ADDR.c_addr_id
         AND (
             BIOG_ADDR_DATA.c_firstyear IS NULL
             OR VA2.c_lastyear IS NULL
             OR BIOG_ADDR_DATA.c_firstyear <= VA2.c_lastyear
         )
         AND (
             BIOG_ADDR_DATA.c_lastyear IS NULL
             OR VA2.c_firstyear IS NULL
             OR BIOG_ADDR_DATA.c_lastyear >= VA2.c_firstyear
         )
         AND (
             VA2.c_firstyear < ADDR.c_firstyear
             OR (VA2.c_firstyear = ADDR.c_firstyear AND VA2.c_lastyear < ADDR.c_lastyear)
         )
     )
WHERE BIOG_ADDR_DATA.c_personid = ?
SQL;
    }

    protected function sqlEntries(): string
    {
    return <<<SQL
SELECT
    et.c_entry_type_desc_chn AS EntryType,
    et.c_entry_type AS EntryTypeId,
    ec.c_entry_desc_chn AS EntryCode,
    ed.c_entry_code AS EntryCodeId,
    ed.c_year AS RuShiYear,
    ed.c_age AS RuShiAge,
    tc.c_title_chn AS Source,
    ed.c_pages AS Pages,
    ed.c_notes AS Notes
FROM ENTRY_DATA ed
LEFT JOIN ENTRY_CODES ec ON ed.c_entry_code = ec.c_entry_code
LEFT JOIN ENTRY_CODE_TYPE_REL ectr ON ed.c_entry_code = ectr.c_entry_code
LEFT JOIN ENTRY_TYPES et ON ectr.c_entry_type = et.c_entry_type
LEFT JOIN TEXT_CODES tc ON ed.c_source = tc.c_textid
WHERE ed.c_personid = ?
SQL;
    }

    protected function sqlPostings(): string
    {
        return <<<SQL
SELECT POSTED_TO_OFFICE_DATA.c_office_id AS OfficeId,
       OFFICE_CODES.c_office_chn AS OfficeName,
       (SELECT c_addr_id FROM POSTED_TO_ADDR_DATA
            WHERE c_posting_id = POSTED_TO_OFFICE_DATA.c_posting_id
              AND c_personid = POSTED_TO_OFFICE_DATA.c_personid
              AND c_office_id = POSTED_TO_OFFICE_DATA.c_office_id
            LIMIT 1) AS AddrId,
       (SELECT c_name_chn FROM ADDRESSES
            WHERE c_addr_id = (SELECT c_addr_id FROM POSTED_TO_ADDR_DATA
                                WHERE c_posting_id = POSTED_TO_OFFICE_DATA.c_posting_id
                                  AND c_personid = POSTED_TO_OFFICE_DATA.c_personid
                                  AND c_office_id = POSTED_TO_OFFICE_DATA.c_office_id
                                LIMIT 1)
            LIMIT 1) AS AddrName,
       POSTED_TO_OFFICE_DATA.c_firstyear AS FirstYear,
       (SELECT c_nianhao_chn FROM NIAN_HAO WHERE c_nianhao_id = POSTED_TO_OFFICE_DATA.c_fy_nh_code) AS FirstYearNianhao,
       POSTED_TO_OFFICE_DATA.c_fy_nh_year AS FirstYearNiaohaoYear, -- Legacy typo retained intentionally
       (SELECT c_range_chn FROM YEAR_RANGE_CODES WHERE c_range_code = POSTED_TO_OFFICE_DATA.c_fy_range) AS FirstYearRange,
       POSTED_TO_OFFICE_DATA.c_lastyear AS LastYear,
       (SELECT c_nianhao_chn FROM NIAN_HAO WHERE c_nianhao_id = POSTED_TO_OFFICE_DATA.c_ly_nh_code) AS LastYearNianhao,
       POSTED_TO_OFFICE_DATA.c_ly_nh_year AS LastYearNianhaoYear,
       (SELECT c_range_chn FROM YEAR_RANGE_CODES WHERE c_range_code = POSTED_TO_OFFICE_DATA.c_ly_range) AS LastYearRange,
       (SELECT c_appt_desc_chn FROM APPOINTMENT_CODES WHERE c_appt_code = POSTED_TO_OFFICE_DATA.c_appt_type_code) AS ChuShouType,
       (SELECT c_assume_office_desc_chn FROM ASSUME_OFFICE_CODES WHERE c_assume_office_code = POSTED_TO_OFFICE_DATA.c_assume_office_code) AS WhetherTakesOrNot,
       (SELECT c_title_chn FROM TEXT_CODES WHERE c_textid = POSTED_TO_OFFICE_DATA.c_source) AS Source,
       POSTED_TO_OFFICE_DATA.c_pages AS Pages,
       POSTED_TO_OFFICE_DATA.c_notes AS Notes
FROM POSTED_TO_OFFICE_DATA
INNER JOIN OFFICE_CODES ON POSTED_TO_OFFICE_DATA.c_office_id = OFFICE_CODES.c_office_id
WHERE POSTED_TO_OFFICE_DATA.c_personid = ?
SQL;
    }

    protected function sqlStatuses(): string
    {
        return <<<SQL
SELECT c_status_code AS StatusId,
       (SELECT c_status_desc_chn FROM STATUS_CODES WHERE c_status_code = STATUS_DATA.c_status_code) AS StatusName,
       c_firstyear AS FirstYear,
       c_lastyear AS LastYear
FROM STATUS_DATA
WHERE c_personid = ?
SQL;
    }

    protected function sqlKinships(): string
    {
        return <<<SQL
SELECT c_kin_id AS KinPersonId,
       (SELECT c_name_chn FROM BIOG_MAIN WHERE c_personid = KIN_DATA.c_kin_id) AS KinPersonName,
       c_kin_code AS KinCode,
       (SELECT c_kinrel FROM KINSHIP_CODES WHERE c_kincode = KIN_DATA.c_kin_code) AS KinRel,
       (SELECT c_kinrel_chn FROM KINSHIP_CODES WHERE c_kincode = KIN_DATA.c_kin_code) AS KinRelName,
       (SELECT c_title_chn FROM TEXT_CODES WHERE c_textid = KIN_DATA.c_source) AS Source,
       c_pages AS Pages,
       c_notes AS Notes
FROM KIN_DATA
WHERE c_personid = ?
SQL;
    }

    protected function sqlAssociations(): string
    {
        return <<<SQL
SELECT c_assoc_id AS AssocPersonId,
       (SELECT c_name_chn FROM BIOG_MAIN WHERE c_personid = ASSOC_DATA.c_assoc_id) AS AssocPersonName,
       c_assoc_code AS AssocCode,
       (SELECT c_assoc_desc_chn FROM ASSOC_CODES WHERE c_assoc_code = ASSOC_DATA.c_assoc_code) AS AssocName,
       c_assoc_first_year AS Year,
       c_text_title AS TextTitle,
       c_kin_id AS KinPersonId,
       (SELECT c_name_chn FROM BIOG_MAIN WHERE c_personid = ASSOC_DATA.c_kin_id) AS KinPersonName,
       (SELECT c_kinrel_chn FROM KINSHIP_CODES WHERE c_kincode = ASSOC_DATA.c_kin_code) AS KinRelName,
       c_assoc_kin_id AS AssocKinPersonId,
       (SELECT c_name_chn FROM BIOG_MAIN WHERE c_personid = ASSOC_DATA.c_assoc_kin_id) AS AssocKinPersonName,
       (SELECT c_kinrel_chn FROM KINSHIP_CODES WHERE c_kincode = ASSOC_DATA.c_assoc_kin_code) AS AssocKinRelName,
       (SELECT c_title_chn FROM TEXT_CODES WHERE c_textid = ASSOC_DATA.c_source) AS Source,
       c_pages AS Pages,
       c_notes AS Notes
FROM ASSOC_DATA
WHERE c_personid = ?
SQL;
    }

    protected function sqlTexts(): string
    {
        return <<<SQL
SELECT TEXT_CODES.c_textid AS TextId,
       TEXT_CODES.c_title_chn AS TextName,
       TEXT_CODES.c_text_year AS Year,
       (SELECT c_role_desc_chn FROM TEXT_ROLE_CODES WHERE c_role_id = BIOG_TEXT_DATA.c_role_id) AS Role,
       (SELECT c_title_chn FROM TEXT_CODES b WHERE c_textid = TEXT_CODES.c_source) AS Source,
       TEXT_CODES.c_pages AS Pages,
       TEXT_CODES.c_notes AS Notes
FROM TEXT_CODES
INNER JOIN BIOG_TEXT_DATA ON TEXT_CODES.c_textid = BIOG_TEXT_DATA.c_textid
WHERE BIOG_TEXT_DATA.c_personid = ?
SQL;
    }
}
