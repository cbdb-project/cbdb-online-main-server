<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PersonBrowserService {
    /**
     * 搜尋人物列表。
     * 支援：c_personid、中文名、英文名/拼音名、別名（alt names）。
     *
     * @return array{data: array, pagination: array}
     */
    public function search(Request $request): array {
        $q = trim($request->input('q', ''));
        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        $idQuery = DB::table('BIOG_MAIN')
            ->select('BIOG_MAIN.c_personid');

        if ($q !== '') {
            if (ctype_digit($q)) {
                // 純數字：精確 personid 查詢
                $idQuery->where('BIOG_MAIN.c_personid', '=', (int) $q)
                    ->orderBy('BIOG_MAIN.c_personid', 'ASC');
            } else {
                // 先嘗試倒排索引
                $ftsIds = DB::table('CBDB__NAME_FTS')
                    ->where('search_term', 'LIKE', $q . '%')
                    ->orderByRaw('LENGTH(search_term) ASC')
                    ->limit(500)
                    ->pluck('c_personid')
                    ->unique()
                    ->values()
                    ->toArray();

                if (!empty($ftsIds)) {
                    $idQuery->whereIn('BIOG_MAIN.c_personid', $ftsIds)
                        ->orderByRaw($this->buildIdOrderCase('BIOG_MAIN.c_personid', $ftsIds));
                } else {
                    // 回退：多欄位 LIKE 搜尋
                    $idQuery->where(function ($sub) use ($q) {
                        $sub->where('BIOG_MAIN.c_name_chn', 'like', '%' . $q . '%')
                            ->orWhere('BIOG_MAIN.c_name', 'like', '%' . $q . '%')
                            ->orWhere('BIOG_MAIN.c_surname', 'like', $q)
                            ->orWhere('BIOG_MAIN.c_mingzi', 'like', $q)
                            ->orWhere('BIOG_MAIN.c_name_proper', 'like', '%' . $q . '%')
                            ->orWhere('BIOG_MAIN.c_name_rm', 'like', '%' . $q . '%');
                    })
                        ->orderBy('BIOG_MAIN.c_personid', 'ASC');
                }
            }
        } else {
            $idQuery->orderBy('BIOG_MAIN.c_personid', 'DESC');
        }

        $paginator = $idQuery->paginate($perPage, ['BIOG_MAIN.c_personid'], 'page', $page);
        $pageIds = collect($paginator->items())
            ->map(fn ($row) => (int) $row->c_personid)
            ->values()
            ->all();

        if (empty($pageIds)) {
            return [
                'data' => [],
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ];
        }

        $baseSelect = [
            'BIOG_MAIN.c_personid',
            'BIOG_MAIN.c_name_chn',
            'BIOG_MAIN.c_name',
            'DYNASTIES.c_dynasty_chn',
            'BIOG_MAIN.c_index_year',
            'ADDR_CODES.c_name_chn AS index_addr_chn',
        ];

        $rows = DB::table('BIOG_MAIN')
            ->select($baseSelect)
            ->leftJoin('DYNASTIES', 'DYNASTIES.c_dy', '=', 'BIOG_MAIN.c_dy')
            ->leftJoin('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id')
            ->whereIn('BIOG_MAIN.c_personid', $pageIds)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();

        $orderMap = array_flip($pageIds);
        usort($rows, function (array $left, array $right) use ($orderMap) {
            return ($orderMap[(int) $left['c_personid']] ?? PHP_INT_MAX)
                <=> ($orderMap[(int) $right['c_personid']] ?? PHP_INT_MAX);
        });

        return [
            'data' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function buildIdOrderCase(string $column, array $ids): string {
        $normalized = array_values(array_map('intval', $ids));

        if (empty($normalized)) {
            return sprintf('%s ASC', $column);
        }

        $cases = [];
        foreach ($normalized as $index => $id) {
            $cases[] = sprintf('WHEN %d THEN %d', $id, $index);
        }

        return sprintf(
            'CASE %s %s ELSE %d END',
            $column,
            implode(' ', $cases),
            count($normalized)
        );
    }

    /**
     * 取得人物摘要資訊。
     *
     * @return array|null
     */
    public function summary(int $personId): ?array {
        $row = DB::table('BIOG_MAIN')
            ->select([
                'BIOG_MAIN.c_personid',
                'BIOG_MAIN.c_name_chn',
                'BIOG_MAIN.c_name',
                'BIOG_MAIN.c_name_proper',
                'BIOG_MAIN.c_name_rm',
                'BIOG_MAIN.c_surname_chn',
                'BIOG_MAIN.c_mingzi_chn',
                'BIOG_MAIN.c_female',
                'BIOG_MAIN.c_birthyear',
                'BIOG_MAIN.c_deathyear',
                'BIOG_MAIN.c_index_year',
                'BIOG_MAIN.c_index_year_type_code',
                'BIOG_MAIN.c_dy',
                'BIOG_MAIN.c_index_addr_id',
                'BIOG_MAIN.c_notes',
                'DYNASTIES.c_dynasty_chn',
                'DYNASTIES.c_dynasty',
                'ADDR_CODES.c_name_chn AS index_addr_chn',
                'ADDR_CODES.c_name AS index_addr',
            ])
            ->leftJoin('DYNASTIES', 'DYNASTIES.c_dy', '=', 'BIOG_MAIN.c_dy')
            ->leftJoin('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id')
            ->where('BIOG_MAIN.c_personid', $personId)
            ->first();

        if (!$row) {
            return null;
        }

        $row = (array) $row;

        // 別名摘要（字、號）
        $altNames = DB::table('ALTNAME_DATA')
            ->select(['c_alt_name_chn', 'c_alt_name_type_code'])
            ->where('c_personid', $personId)
            ->whereIn('c_alt_name_type_code', [4, 5])
            ->orderBy('c_alt_name_type_code')
            ->get()
            ->groupBy('c_alt_name_type_code');

        // index year type 描述（取前兩碼對照 INDEXYEAR_TYPE_CODES，與 BiogMainRepository::byPersonId 一致）
        $indexYearTypeDesc = null;
        if (!empty($row['c_index_year_type_code'])) {
            $code = substr((string) $row['c_index_year_type_code'], 0, 2);
            $typeRow = DB::table('INDEXYEAR_TYPE_CODES')
                ->where('c_index_year_type_code', $code)
                ->first();
            if ($typeRow) {
                $indexYearTypeDesc = $typeRow->c_index_year_type_hz ?? null;
            }
        }

        // tab 計數
        $counts = $this->tabCounts($personId);

        return [
            'c_personid' => (int) $row['c_personid'],
            'c_name_chn' => $row['c_name_chn'],
            'c_name' => $row['c_name'],
            'c_name_proper' => $row['c_name_proper'],
            'c_name_rm' => $row['c_name_rm'],
            'c_surname_chn' => $row['c_surname_chn'],
            'c_mingzi_chn' => $row['c_mingzi_chn'],
            'gender' => $this->genderLabel($row['c_female'] ?? null),
            'c_birthyear' => $row['c_birthyear'],
            'c_deathyear' => $row['c_deathyear'],
            'c_index_year' => $row['c_index_year'],
            'index_year_type' => $indexYearTypeDesc,
            'dynasty_chn' => $row['c_dynasty_chn'],
            'dynasty' => $row['c_dynasty'],
            'index_addr_chn' => $row['index_addr_chn'],
            'index_addr' => $row['index_addr'],
            'c_notes' => $row['c_notes'],
            'alt_name_zi' => $altNames->get(4, collect())->pluck('c_alt_name_chn')->implode('、'),
            'alt_name_hao' => $altNames->get(5, collect())->pluck('c_alt_name_chn')->implode('、'),
            'tab_counts' => $counts,
        ];
    }

    /**
     * 取得各 tab 的資料計數。
     */
    public function tabCounts(int $personId): array {
        return [
            'basic_info' => 1,
            'alt_names' => (int) DB::table('ALTNAME_DATA')->where('c_personid', $personId)->count(),
            'addresses' => (int) DB::table('BIOG_ADDR_DATA')->where('c_personid', $personId)->count(),
            'texts' => (int) DB::table('BIOG_TEXT_DATA')->where('c_personid', $personId)->count(),
            'sources' => (int) DB::table('BIOG_SOURCE_DATA')->where('c_personid', $personId)->count(),
            'entries' => (int) DB::table('ENTRY_DATA')->where('c_personid', $personId)->count(),
            'events' => (int) DB::table('EVENTS_DATA')->where('c_personid', $personId)->count(),
            'statuses' => (int) DB::table('STATUS_DATA')->where('c_personid', $personId)->count(),
            'associations' => (int) DB::table('ASSOC_DATA')->where('c_personid', $personId)->count(),
            'kinship' => (int) DB::table('KIN_DATA')->where('c_personid', $personId)->count(),
            'possessions' => (int) DB::table('POSSESSION_DATA')->where('c_personid', $personId)->count(),
            'social_institutions' => (int) DB::table('BIOG_INST_DATA')->where('c_personid', $personId)->count(),
            'postings' => (int) DB::table('POSTED_TO_OFFICE_DATA')->where('c_personid', $personId)->count(),
        ];
    }

    /**
     * 取得指定 tab 的資料。
     *
     * @return array|null  null 表示無效的 tabKey
     */
    public function tabData(int $personId, string $tabKey): ?array {
        return match ($tabKey) {
            'basic_info' => $this->tabBasicInfo($personId),
            'alt_names' => $this->tabAltNames($personId),
            'addresses' => $this->tabAddresses($personId),
            'texts' => $this->tabTexts($personId),
            'sources' => $this->tabSources($personId),
            'entries' => $this->tabEntries($personId),
            'events' => $this->tabEvents($personId),
            'statuses' => $this->tabStatuses($personId),
            'associations' => $this->tabAssociations($personId),
            'kinship' => $this->tabKinship($personId),
            'possessions' => $this->tabPossessions($personId),
            'social_institutions' => $this->tabSocialInstitutions($personId),
            'postings' => $this->tabPostings($personId),
            default => null,
        };
    }

    /**
     * 所有合法的 tab keys。
     */
    public static function validTabKeys(): array {
        return [
            'basic_info',
            'alt_names',
            'addresses',
            'texts',
            'sources',
            'entries',
            'events',
            'statuses',
            'associations',
            'kinship',
            'possessions',
            'social_institutions',
            'postings',
        ];
    }

    // ─── Tab Data Methods ───

    private function tabBasicInfo(int $personId): array {
        $row = DB::table('BIOG_MAIN')
            ->select([
                'BIOG_MAIN.*',
                'DYNASTIES.c_dynasty_chn',
                'DYNASTIES.c_dynasty',
                'ADDR_CODES.c_name_chn AS index_addr_chn',
                'ADDR_CODES.c_name AS index_addr',
                'INDEX_SOURCE_PERSON.c_name_chn AS index_year_source_name_chn',
                'INDEX_SOURCE_PERSON.c_name AS index_year_source_name',
                'ETH.c_name_chn AS c_ethnicity_chn',
                'ETH.c_name AS c_ethnicity',
                'CHR.c_choronym_chn',
                'CHR.c_choronym_desc AS c_choronym',
            ])
            ->leftJoin('DYNASTIES', 'DYNASTIES.c_dy', '=', 'BIOG_MAIN.c_dy')
            ->leftJoin('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id')
            ->leftJoin('BIOG_MAIN AS INDEX_SOURCE_PERSON', 'INDEX_SOURCE_PERSON.c_personid', '=', 'BIOG_MAIN.c_index_year_source_id')
            ->leftJoin('ETHNICITY_TRIBE_CODES AS ETH', 'ETH.c_ethnicity_code', '=', 'BIOG_MAIN.c_ethnicity_code')
            ->leftJoin('CHORONYM_CODES AS CHR', 'CHR.c_choronym_code', '=', 'BIOG_MAIN.c_choronym_code')
            ->where('BIOG_MAIN.c_personid', $personId)
            ->first();

        if (!$row) {
            return ['sections' => []];
        }

        $row = (array) $row;

        // 年號對照
        $birthNH = $this->lookupNianHao($row['c_by_nh_code'] ?? null);
        $deathNH = $this->lookupNianHao($row['c_dy_nh_code'] ?? null);
        $flEarliestNH = $this->lookupNianHao($row['c_fl_ey_nh_code'] ?? null);
        $flLatestNH = $this->lookupNianHao($row['c_fl_ly_nh_code'] ?? null);
        $birthRange = $this->lookupYearRange($row['c_by_range'] ?? null);
        $deathRange = $this->lookupYearRange($row['c_dy_range'] ?? null);
        $deathAgeRange = $this->lookupYearRange($row['c_death_age_range'] ?? null);
        $birthGanzhi = $this->lookupGanzhi($row['c_by_day_gz'] ?? null);
        $deathGanzhi = $this->lookupGanzhi($row['c_dy_day_gz'] ?? null);
        $household = $this->lookupHouseholdStatus($row['c_household_status_code'] ?? null);
        $indexYearTypeCode = (string) ($row['c_index_year_type_code'] ?? '');
        $simplifiedIndexYearTypeCode = $indexYearTypeCode !== '' ? substr($indexYearTypeCode, 0, 2) : '';
        $indexYearTypeChn = '';
        $indexYearTypeEng = '';
        if ($simplifiedIndexYearTypeCode !== '') {
            $indexYearTypeRow = DB::table('INDEXYEAR_TYPE_CODES')
                ->where('c_index_year_type_code', $simplifiedIndexYearTypeCode)
                ->first();
            if ($indexYearTypeRow) {
                $indexYearTypeChn = $indexYearTypeRow->c_index_year_type_hz ?? '';
                $indexYearTypeEng = $indexYearTypeRow->c_index_year_type_desc ?? '';
            }
        }
        $indexYearSource = '';
        if (!empty($row['c_index_year_source_id'])) {
            $sourceLabel = $row['index_year_source_name_chn'] ?: ($row['index_year_source_name'] ?? '');
            $indexYearSource = trim($row['c_index_year_source_id'] . ' ' . $sourceLabel);
        }

        return [
            'sections' => [
                [
                    'title' => '姓名資料',
                    'fields' => [
                        ['label' => 'Person ID', 'value' => $row['c_personid']],
                        ['label' => '中文姓', 'value' => $row['c_surname_chn'] ?? ''],
                        ['label' => '中文名', 'value' => $row['c_mingzi_chn'] ?? ''],
                        ['label' => 'Xing', 'value' => $row['c_surname'] ?? ''],
                        ['label' => 'Ming', 'value' => $row['c_mingzi'] ?? ''],
                        ['label' => '外文姓', 'value' => $row['c_surname_proper'] ?? ''],
                        ['label' => '外文名', 'value' => $row['c_mingzi_proper'] ?? ''],
                        ['label' => '外文羅馬字轉寫姓', 'value' => $row['c_surname_rm'] ?? ''],
                        ['label' => '外文羅馬字轉寫名', 'value' => $row['c_mingzi_rm'] ?? ''],
                        ['label' => '姓名', 'value' => $row['c_name_chn'] ?? ''],
                        ['label' => '姓名拼音', 'value' => $row['c_name'] ?? ''],
                        ['label' => '外文全名', 'value' => $row['c_name_proper'] ?? ''],
                        ['label' => '外文羅馬字轉寫姓名', 'value' => $row['c_name_rm'] ?? ''],
                    ],
                ],
                [
                    'title' => '基本屬性',
                    'fields' => [
                        ['label' => '性別', 'value' => $this->genderLabel($row['c_female'] ?? null)],
                        ['label' => '朝代（中文）', 'value' => $row['c_dynasty_chn'] ?? ''],
                        ['label' => '朝代（英文）', 'value' => $row['c_dynasty'] ?? ''],
                        ['label' => '族裔（中文）', 'value' => $row['c_ethnicity_chn'] ?? ''],
                        ['label' => '族裔（英文）', 'value' => $row['c_ethnicity'] ?? ''],
                        ['label' => '郡望（中文）', 'value' => $row['c_choronym_chn'] ?? ''],
                        ['label' => '郡望（英文）', 'value' => $row['c_choronym'] ?? ''],
                        ['label' => '戶籍（中文）', 'value' => $household['chn']],
                        ['label' => '戶籍（英文）', 'value' => $household['eng']],
                    ],
                ],
                [
                    'title' => '生卒年',
                    'fields' => [
                        ['label' => '出生年', 'value' => $row['c_birthyear'] ?? ''],
                        ['label' => '出生年號', 'value' => $birthNH],
                        ['label' => '出生年號年', 'value' => $row['c_by_nh_year'] ?? ''],
                        ['label' => '出生年範圍', 'value' => $birthRange],
                        ['label' => '出生閏月', 'value' => $this->intercalaryLabel($row['c_by_intercalary'] ?? null)],
                        ['label' => '出生月', 'value' => $row['c_by_month'] ?? ''],
                        ['label' => '出生日', 'value' => $row['c_by_day'] ?? ''],
                        ['label' => '出生日時干支', 'value' => $birthGanzhi],
                        ['label' => '死亡年', 'value' => $row['c_deathyear'] ?? ''],
                        ['label' => '死亡年號', 'value' => $deathNH],
                        ['label' => '死亡年號年', 'value' => $row['c_dy_nh_year'] ?? ''],
                        ['label' => '死亡年範圍', 'value' => $deathRange],
                        ['label' => '死亡閏月', 'value' => $this->intercalaryLabel($row['c_dy_intercalary'] ?? null)],
                        ['label' => '死亡月', 'value' => $row['c_dy_month'] ?? ''],
                        ['label' => '死亡日', 'value' => $row['c_dy_day'] ?? ''],
                        ['label' => '死亡日時干支', 'value' => $deathGanzhi],
                        ['label' => '享年', 'value' => $row['c_death_age'] ?? ''],
                        ['label' => '享年範圍', 'value' => $deathAgeRange],
                    ],
                ],
                [
                    'title' => '活動年份',
                    'fields' => [
                        ['label' => '在世始年', 'value' => $row['c_fl_earliest_year'] ?? ''],
                        ['label' => '在世始年號', 'value' => $flEarliestNH],
                        ['label' => '在世始年號年', 'value' => $row['c_fl_ey_nh_year'] ?? ''],
                        ['label' => '在世始年註', 'value' => $row['c_fl_ey_notes'] ?? ''],
                        ['label' => '在世終年', 'value' => $row['c_fl_latest_year'] ?? ''],
                        ['label' => '在世終年號', 'value' => $flLatestNH],
                        ['label' => '在世終年號年', 'value' => $row['c_fl_ly_nh_year'] ?? ''],
                        ['label' => '在世終年註', 'value' => $row['c_fl_ly_notes'] ?? ''],
                    ],
                ],
                [
                    'title' => '備註',
                    'fields' => [
                        ['label' => '備註', 'value' => $row['c_notes'] ?? ''],
                    ],
                ],
                [
                    'title' => '指數資料',
                    'fields' => [
                        ['label' => 'Index Year', 'value' => $row['c_index_year'] ?? ''],
                        ['label' => 'Index Year Type', 'value' => $indexYearTypeCode],
                        ['label' => 'Index Year Type（中文）', 'value' => $indexYearTypeChn],
                        ['label' => 'Index Year Type（英文）', 'value' => $indexYearTypeEng],
                        ['label' => 'Index Year Source', 'value' => $indexYearSource],
                        ['label' => 'Index Address（中文）', 'value' => $row['index_addr_chn'] ?? ''],
                        ['label' => 'Index Address（英文）', 'value' => $row['index_addr'] ?? ''],
                        ['label' => 'Index Address Type', 'value' => $row['c_index_addr_type_code'] ?? ''],
                    ],
                ],
                [
                    'title' => '建立 / 修改資訊',
                    'fields' => [
                        ['label' => 'Created By', 'value' => $row['c_created_by'] ?? ''],
                        ['label' => 'Created Date', 'value' => $row['c_created_date'] ?? ''],
                        ['label' => 'Modified By', 'value' => $row['c_modified_by'] ?? ''],
                        ['label' => 'Modified Date', 'value' => $row['c_modified_date'] ?? ''],
                    ],
                ],
            ],
            'form' => [
                'person_id' => $personId,
                'fields' => $this->buildBasicInfoFormFields(
                    $row,
                    $household,
                    $birthNH,
                    $deathNH,
                    $birthRange,
                    $deathRange,
                    $deathAgeRange,
                    $birthGanzhi,
                    $deathGanzhi,
                    $flEarliestNH,
                    $flLatestNH,
                    $indexYearTypeCode,
                    $indexYearTypeChn,
                    $indexYearTypeEng,
                    $indexYearSource
                ),
            ],
        ];
    }

    private function tabAltNames(int $personId): array {
        $rows = DB::table('ALTNAME_DATA')
            ->select([
                'ALTNAME_DATA.c_sequence',
                'ALTNAME_DATA.c_alt_name_chn',
                'ALTNAME_DATA.c_alt_name',
                'ALTNAME_DATA.c_alt_name_type_code',
                'ATC.c_name_type_desc_chn',
                'ATC.c_name_type_desc',
                'ALTNAME_DATA.c_source',
                'ALTNAME_DATA.c_pages',
                'ALTNAME_DATA.c_notes',
            ])
            ->leftJoin('ALTNAME_CODES AS ATC', 'ATC.c_name_type_code', '=', 'ALTNAME_DATA.c_alt_name_type_code')
            ->where('ALTNAME_DATA.c_personid', $personId)
            ->orderBy('ALTNAME_DATA.c_alt_name_type_code')
            ->get();

        return [
            'tab' => 'alt_names',
            'items' => $rows->map(fn ($r) => [
                'pk' => [
                    'c_personid' => $personId,
                    'c_alt_name_chn' => $r->c_alt_name_chn,
                    'c_alt_name_type_code' => $r->c_alt_name_type_code,
                ],
                'sequence' => $r->c_sequence,
                'name_chn' => $r->c_alt_name_chn,
                'name' => $r->c_alt_name,
                'type_code' => $r->c_alt_name_type_code,
                'type_label_chn' => $r->c_name_type_desc_chn,
                'type_label' => $r->c_name_type_desc,
                'source_id' => $r->c_source,
                'pages' => $r->c_pages,
                'notes' => $r->c_notes,
            ])->values()->all(),
        ];
    }

    private function tabAddresses(int $personId): array {
        $rows = DB::table('BIOG_ADDR_DATA')
            ->select([
                'BIOG_ADDR_DATA.c_addr_id',
                'AC.c_name_chn AS addr_chn',
                'AC.c_name AS addr',
                'BIOG_ADDR_DATA.c_addr_type',
                'BAC.c_addr_desc_chn',
                'BAC.c_addr_desc',
                'BIOG_ADDR_DATA.c_firstyear',
                'BIOG_ADDR_DATA.c_lastyear',
                'BIOG_ADDR_DATA.c_sequence',
                'BIOG_ADDR_DATA.c_notes',
            ])
            ->leftJoin('ADDR_CODES AS AC', 'AC.c_addr_id', '=', 'BIOG_ADDR_DATA.c_addr_id')
            ->leftJoin('BIOG_ADDR_CODES AS BAC', 'BAC.c_addr_type', '=', 'BIOG_ADDR_DATA.c_addr_type')
            ->where('BIOG_ADDR_DATA.c_personid', $personId)
            ->orderBy('BIOG_ADDR_DATA.c_sequence')
            ->get();

        return [
            'tab' => 'addresses',
            'items' => $rows->map(fn ($r) => [
                'pk' => [
                    'c_personid' => $personId,
                    'c_addr_id' => $r->c_addr_id,
                    'c_addr_type' => $r->c_addr_type,
                    'c_sequence' => $r->c_sequence,
                ],
                'sequence' => $r->c_sequence,
                'addr_id' => $r->c_addr_id,
                'addr_chn' => $r->addr_chn,
                'addr' => $r->addr,
                'type_code' => $r->c_addr_type,
                'type_label_chn' => $r->c_addr_desc_chn,
                'type_label' => $r->c_addr_desc,
                'first_year' => $r->c_firstyear,
                'last_year' => $r->c_lastyear,
                'notes' => $r->c_notes,
            ])->values()->all(),
        ];
    }

    private function tabTexts(int $personId): array {
        $rows = DB::table('BIOG_TEXT_DATA')
            ->select([
                'TEXT_CODES.c_title_chn',
                'TEXT_CODES.c_title',
                'TEXT_CODES.c_text_year',
                'TEXT_ROLE_CODES.c_role_desc_chn',
                'TEXT_ROLE_CODES.c_role_desc',
                'BIOG_TEXT_DATA.c_textid',
                'BIOG_TEXT_DATA.c_role_id',
            ])
            ->leftJoin('TEXT_CODES', 'TEXT_CODES.c_textid', '=', 'BIOG_TEXT_DATA.c_textid')
            ->leftJoin('TEXT_ROLE_CODES', 'TEXT_ROLE_CODES.c_role_id', '=', 'BIOG_TEXT_DATA.c_role_id')
            ->where('BIOG_TEXT_DATA.c_personid', $personId)
            ->get();

        return [
            'tab' => 'texts',
            'items' => $rows->map(fn ($r) => [
                'pk' => [
                    'c_personid' => $personId,
                    'c_textid' => $r->c_textid,
                    'c_role_id' => $r->c_role_id,
                ],
                'text_id' => $r->c_textid,
                'title_chn' => $r->c_title_chn,
                'title' => $r->c_title,
                'year' => $r->c_text_year,
                'role_id' => $r->c_role_id,
                'role_chn' => $r->c_role_desc_chn,
                'role' => $r->c_role_desc,
            ])->values()->all(),
        ];
    }

    private function tabSources(int $personId): array {
        $rows = DB::table('BIOG_SOURCE_DATA')
            ->select([
                'BIOG_SOURCE_DATA.c_textid',
                'TEXT_CODES.c_title_chn',
                'TEXT_CODES.c_title',
                'BIOG_SOURCE_DATA.c_pages',
                'BIOG_SOURCE_DATA.c_notes',
                'BIOG_SOURCE_DATA.c_main_source',
                'BIOG_SOURCE_DATA.c_self_bio',
            ])
            ->leftJoin('TEXT_CODES', 'TEXT_CODES.c_textid', '=', 'BIOG_SOURCE_DATA.c_textid')
            ->where('BIOG_SOURCE_DATA.c_personid', $personId)
            ->get();

        return [
            'tab' => 'sources',
            'items' => $rows->map(fn ($r) => [
                'pk' => [
                    'c_personid' => $personId,
                    'c_textid' => $r->c_textid,
                    'c_pages' => $r->c_pages,
                ],
                'text_id' => $r->c_textid,
                'title_chn' => $r->c_title_chn,
                'title' => $r->c_title,
                'pages' => $r->c_pages,
                'notes' => $r->c_notes,
                'is_main_source' => (int) ($r->c_main_source ?? 0) === 1,
                'is_self_bio' => (int) ($r->c_self_bio ?? 0) === 1,
            ])->values()->all(),
        ];
    }

    private function tabEntries(int $personId): array {
        $rows = DB::table('ENTRY_DATA')
            ->select([
                'ENTRY_CODES.c_entry_desc_chn',
                'ENTRY_CODES.c_entry_desc',
                'ENTRY_DATA.c_entry_code',
                'ENTRY_DATA.c_year',
                'ENTRY_DATA.c_sequence',
                'ENTRY_DATA.c_kin_code',
                'ENTRY_DATA.c_kin_id',
                'ENTRY_DATA.c_assoc_code',
                'ENTRY_DATA.c_assoc_id',
                'ENTRY_DATA.c_inst_code',
                'ENTRY_DATA.c_inst_name_code',
            ])
            ->leftJoin('ENTRY_CODES', 'ENTRY_CODES.c_entry_code', '=', 'ENTRY_DATA.c_entry_code')
            ->where('ENTRY_DATA.c_personid', $personId)
            ->orderBy('ENTRY_DATA.c_sequence')
            ->get();

        // 批次查找關聯人物姓名
        $kinIds = $rows->pluck('c_kin_id')->filter()->unique()->values()->all();
        $assocIds = $rows->pluck('c_assoc_id')->filter()->unique()->values()->all();
        $allPersonIds = array_unique(array_merge($kinIds, $assocIds));
        $personNames = collect();
        if (!empty($allPersonIds)) {
            $personNames = DB::table('BIOG_MAIN')
                ->select(['c_personid', 'c_name_chn', 'c_name'])
                ->whereIn('c_personid', $allPersonIds)
                ->get()
                ->keyBy('c_personid');
        }

        // 查找親屬關係描述
        $kinCodes = $rows->pluck('c_kin_code')->filter()->unique()->values()->all();
        $kinLabels = collect();
        if (!empty($kinCodes)) {
            $kinLabels = DB::table('KINSHIP_CODES')
                ->select(['c_kincode', 'c_kinrel_chn', 'c_kinrel'])
                ->whereIn('c_kincode', $kinCodes)
                ->get()
                ->keyBy('c_kincode');
        }

        // 查找社會關係描述
        $assocCodes = $rows->pluck('c_assoc_code')->filter()->unique()->values()->all();
        $assocLabels = collect();
        if (!empty($assocCodes)) {
            $assocLabels = DB::table('ASSOC_CODES')
                ->select(['c_assoc_code', 'c_assoc_desc_chn', 'c_assoc_desc'])
                ->whereIn('c_assoc_code', $assocCodes)
                ->get()
                ->keyBy('c_assoc_code');
        }

        return [
            'tab' => 'entries',
            'items' => $rows->map(function ($r) use ($personId, $personNames, $kinLabels, $assocLabels) {
                $kinSummary = null;
                if (!empty($r->c_kin_id)) {
                    $kinPerson = $personNames->get($r->c_kin_id);
                    $kinLabel = $kinLabels->get($r->c_kin_code);
                    $parts = [];
                    if ($kinLabel) {
                        $parts[] = $kinLabel->c_kinrel_chn ?? $kinLabel->c_kinrel ?? '';
                    }
                    if ($kinPerson) {
                        $parts[] = $kinPerson->c_name_chn ?? $kinPerson->c_name ?? '';
                    }
                    $kinSummary = implode(' ', array_filter($parts)) ?: (string) $r->c_kin_id;
                }

                $assocSummary = null;
                if (!empty($r->c_assoc_id)) {
                    $assocPerson = $personNames->get($r->c_assoc_id);
                    $assocLabel = $assocLabels->get($r->c_assoc_code);
                    $parts = [];
                    if ($assocLabel) {
                        $parts[] = $assocLabel->c_assoc_desc_chn ?? $assocLabel->c_assoc_desc ?? '';
                    }
                    if ($assocPerson) {
                        $parts[] = $assocPerson->c_name_chn ?? $assocPerson->c_name ?? '';
                    }
                    $assocSummary = implode(' ', array_filter($parts)) ?: (string) $r->c_assoc_id;
                }

                return [
                    'pk' => [
                        'c_personid' => $personId,
                        'c_entry_code' => $r->c_entry_code,
                        'c_sequence' => $r->c_sequence,
                        'c_kin_code' => $r->c_kin_code,
                        'c_assoc_code' => $r->c_assoc_code,
                        'c_kin_id' => $r->c_kin_id,
                        'c_year' => $r->c_year,
                        'c_assoc_id' => $r->c_assoc_id,
                        'c_inst_code' => $r->c_inst_code,
                        'c_inst_name_code' => $r->c_inst_name_code,
                    ],
                    'sequence' => $r->c_sequence,
                    'entry_code' => $r->c_entry_code,
                    'entry_desc_chn' => $r->c_entry_desc_chn,
                    'entry_desc' => $r->c_entry_desc,
                    'year' => $r->c_year,
                    'kin_id' => $r->c_kin_id,
                    'kin_summary' => $kinSummary,
                    'assoc_id' => $r->c_assoc_id,
                    'assoc_summary' => $assocSummary,
                ];
            })->values()->all(),
        ];
    }

    private function tabEvents(int $personId): array {
        $rows = DB::table('EVENTS_DATA')
            ->select([
                'EVENT_CODES.c_event_name_chn',
                'EVENT_CODES.c_event_name',
                'EVENTS_DATA.c_event_code',
                'EVENTS_DATA.c_sequence',
                'EVENTS_DATA.c_year',
                'EVENTS_DATA.c_month',
                'EVENTS_DATA.c_day',
                'EVENTS_DATA.c_source',
                'EVENTS_DATA.c_pages',
                'EVENTS_DATA.c_notes',
            ])
            ->leftJoin('EVENT_CODES', 'EVENT_CODES.c_event_code', '=', 'EVENTS_DATA.c_event_code')
            ->where('EVENTS_DATA.c_personid', $personId)
            ->orderBy('EVENTS_DATA.c_sequence')
            ->get();

        return [
            'tab' => 'events',
            'items' => $rows->map(function ($r) use ($personId) {
                // 組合日期摘要
                $dateParts = [];
                if (!empty($r->c_year)) {
                    $dateParts[] = $r->c_year . '年';
                }
                if (!empty($r->c_month)) {
                    $dateParts[] = $r->c_month . '月';
                }
                if (!empty($r->c_day)) {
                    $dateParts[] = $r->c_day . '日';
                }

                return [
                    'pk' => [
                        'c_personid' => $personId,
                        'c_sequence' => $r->c_sequence,
                        'c_event_code' => $r->c_event_code,
                    ],
                    'sequence' => $r->c_sequence,
                    'event_code' => $r->c_event_code,
                    'event_chn' => $r->c_event_name_chn,
                    'event' => $r->c_event_name,
                    'year' => $r->c_year,
                    'month' => $r->c_month,
                    'day' => $r->c_day,
                    'date_summary' => implode('', $dateParts) ?: null,
                    'source_id' => $r->c_source,
                    'pages' => $r->c_pages,
                    'notes' => $r->c_notes,
                ];
            })->values()->all(),
        ];
    }

    private function tabStatuses(int $personId): array {
        $rows = DB::table('STATUS_DATA')
            ->select([
                'STATUS_CODES.c_status_desc_chn',
                'STATUS_CODES.c_status_desc',
                'STATUS_DATA.c_status_code',
                'STATUS_DATA.c_sequence',
                'STATUS_DATA.c_firstyear',
                'STATUS_DATA.c_lastyear',
                'STATUS_DATA.c_source',
                'STATUS_DATA.c_pages',
                'STATUS_DATA.c_notes',
            ])
            ->leftJoin('STATUS_CODES', 'STATUS_CODES.c_status_code', '=', 'STATUS_DATA.c_status_code')
            ->where('STATUS_DATA.c_personid', $personId)
            ->orderBy('STATUS_DATA.c_sequence')
            ->get();

        return [
            'tab' => 'statuses',
            'items' => $rows->map(fn ($r) => [
                'pk' => [
                    'c_personid' => $personId,
                    'c_sequence' => $r->c_sequence,
                    'c_status_code' => $r->c_status_code,
                ],
                'sequence' => $r->c_sequence,
                'status_code' => $r->c_status_code,
                'status_chn' => $r->c_status_desc_chn,
                'status' => $r->c_status_desc,
                'first_year' => $r->c_firstyear,
                'last_year' => $r->c_lastyear,
                'source_id' => $r->c_source,
                'pages' => $r->c_pages,
                'notes' => $r->c_notes,
            ])->values()->all(),
        ];
    }

    private function tabAssociations(int $personId): array {
        $rows = DB::table('ASSOC_DATA')
            ->select([
                'ASSOC_CODES.c_assoc_desc_chn',
                'ASSOC_CODES.c_assoc_desc',
                'ASSOC_DATA.c_assoc_code',
                'ASSOC_DATA.c_assoc_id',
                'ASSOC_DATA.c_kin_code',
                'ASSOC_DATA.c_kin_id',
                'ASSOC_DATA.c_assoc_kin_code',
                'ASSOC_DATA.c_assoc_kin_id',
                'ASSOC_DATA.c_text_title',
                'BM.c_name_chn AS assoc_name_chn',
                'BM.c_name AS assoc_name',
                'ASSOC_DATA.c_assoc_first_year',
                'ASSOC_DATA.c_assoc_last_year',
                'ASSOC_DATA.c_source',
                'ASSOC_DATA.c_pages',
                'ASSOC_DATA.c_notes',
            ])
            ->leftJoin('ASSOC_CODES', 'ASSOC_CODES.c_assoc_code', '=', 'ASSOC_DATA.c_assoc_code')
            ->leftJoin('BIOG_MAIN AS BM', 'BM.c_personid', '=', 'ASSOC_DATA.c_assoc_id')
            ->where('ASSOC_DATA.c_personid', $personId)
            ->orderBy('ASSOC_DATA.c_sequence')
            ->get();

        return [
            'tab' => 'associations',
            'items' => $rows->map(fn ($r) => [
                'pk' => [
                    'c_personid' => $personId,
                    'c_assoc_code' => $r->c_assoc_code,
                    'c_assoc_id' => $r->c_assoc_id,
                    'c_kin_code' => $r->c_kin_code,
                    'c_kin_id' => $r->c_kin_id,
                    'c_assoc_kin_code' => $r->c_assoc_kin_code,
                    'c_assoc_kin_id' => $r->c_assoc_kin_id,
                    'c_text_title' => $r->c_text_title,
                    'c_assoc_first_year' => $r->c_assoc_first_year,
                ],
                'assoc_code' => $r->c_assoc_code,
                'assoc_desc_chn' => $r->c_assoc_desc_chn,
                'assoc_desc' => $r->c_assoc_desc,
                'assoc_person_id' => $r->c_assoc_id,
                'assoc_person_name_chn' => $r->assoc_name_chn,
                'assoc_person_name' => $r->assoc_name,
                'first_year' => $r->c_assoc_first_year,
                'last_year' => $r->c_assoc_last_year,
                'source_id' => $r->c_source,
                'pages' => $r->c_pages,
                'notes' => $r->c_notes,
            ])->values()->all(),
        ];
    }

    private function tabKinship(int $personId): array {
        $rows = DB::table('KIN_DATA')
            ->select([
                'KINSHIP_CODES.c_kinrel_chn',
                'KINSHIP_CODES.c_kinrel',
                'KIN_DATA.c_kin_code',
                'KIN_DATA.c_kin_id',
                'BM.c_name_chn AS kin_name_chn',
                'BM.c_name AS kin_name',
                'KIN_DATA.c_source',
                'KIN_DATA.c_pages',
                'KIN_DATA.c_notes',
            ])
            ->leftJoin('KINSHIP_CODES', 'KINSHIP_CODES.c_kincode', '=', 'KIN_DATA.c_kin_code')
            ->leftJoin('BIOG_MAIN AS BM', 'BM.c_personid', '=', 'KIN_DATA.c_kin_id')
            ->where('KIN_DATA.c_personid', $personId)
            ->get();

        return [
            'tab' => 'kinship',
            'items' => $rows->map(fn ($r) => [
                'pk' => [
                    'c_personid' => $personId,
                    'c_kin_id' => $r->c_kin_id,
                    'c_kin_code' => $r->c_kin_code,
                ],
                'kin_code' => $r->c_kin_code,
                'relation_chn' => $r->c_kinrel_chn,
                'relation' => $r->c_kinrel,
                'kin_person_id' => $r->c_kin_id,
                'kin_person_name_chn' => $r->kin_name_chn,
                'kin_person_name' => $r->kin_name,
                'source_id' => $r->c_source,
                'pages' => $r->c_pages,
                'notes' => $r->c_notes,
            ])->values()->all(),
        ];
    }

    private function tabPossessions(int $personId): array {
        $rows = DB::table('POSSESSION_DATA')
            ->select([
                'POSSESSION_ACT_CODES.c_possession_act_desc_chn',
                'POSSESSION_ACT_CODES.c_possession_act_desc',
                'POSSESSION_DATA.c_possession_record_id',
                'POSSESSION_DATA.c_possession_act_code',
                'POSSESSION_DATA.c_possession_desc_chn',
                'POSSESSION_DATA.c_possession_desc',
                'POSSESSION_DATA.c_quantity',
                'POSSESSION_DATA.c_possession_yr',
                'POSSESSION_DATA.c_source',
                'POSSESSION_DATA.c_pages',
                'POSSESSION_DATA.c_notes',
            ])
            ->leftJoin('POSSESSION_ACT_CODES', 'POSSESSION_ACT_CODES.c_possession_act_code', '=', 'POSSESSION_DATA.c_possession_act_code')
            ->where('POSSESSION_DATA.c_personid', $personId)
            ->get();

        return [
            'tab' => 'possessions',
            'items' => $rows->map(fn ($r) => [
                'pk' => [
                    'c_possession_record_id' => $r->c_possession_record_id,
                ],
                'act_code' => $r->c_possession_act_code,
                'act_chn' => $r->c_possession_act_desc_chn,
                'act' => $r->c_possession_act_desc,
                'desc_chn' => $r->c_possession_desc_chn,
                'desc' => $r->c_possession_desc,
                'quantity' => $r->c_quantity,
                'year' => $r->c_possession_yr,
                'source_id' => $r->c_source,
                'pages' => $r->c_pages,
                'notes' => $r->c_notes,
            ])->values()->all(),
        ];
    }

    private function tabSocialInstitutions(int $personId): array {
        $rows = DB::table('BIOG_INST_DATA')
            ->select([
                'BIOG_INST_CODES.c_bi_role_chn',
                'BIOG_INST_CODES.c_bi_role_desc',
                'BIOG_INST_DATA.c_bi_role_code',
                'BIOG_INST_DATA.c_inst_code',
                'INST_NAMES.c_inst_name_hz',
                'INST_NAMES.c_inst_name_py',
                'BIOG_INST_DATA.c_inst_name_code',
                'BIOG_INST_DATA.c_source',
                'BIOG_INST_DATA.c_pages',
                'BIOG_INST_DATA.c_notes',
            ])
            ->leftJoin('BIOG_INST_CODES', 'BIOG_INST_CODES.c_bi_role_code', '=', 'BIOG_INST_DATA.c_bi_role_code')
            ->leftJoin('SOCIAL_INSTITUTION_NAME_CODES AS INST_NAMES', 'INST_NAMES.c_inst_name_code', '=', 'BIOG_INST_DATA.c_inst_name_code')
            ->where('BIOG_INST_DATA.c_personid', $personId)
            ->get();

        return [
            'tab' => 'social_institutions',
            'items' => $rows->map(fn ($r) => [
                'pk' => [
                    'c_personid' => $personId,
                    'c_inst_code' => $r->c_inst_code,
                    'c_inst_name_code' => $r->c_inst_name_code,
                    'c_bi_role_code' => $r->c_bi_role_code,
                ],
                'role_code' => $r->c_bi_role_code,
                'role_chn' => $r->c_bi_role_chn,
                'role' => $r->c_bi_role_desc,
                'inst_code' => $r->c_inst_code,
                'inst_name_code' => $r->c_inst_name_code,
                'inst_name_chn' => $r->c_inst_name_hz,
                'inst_name' => $r->c_inst_name_py,
                'source_id' => $r->c_source,
                'pages' => $r->c_pages,
                'notes' => $r->c_notes,
            ])->values()->all(),
        ];
    }

    private function tabPostings(int $personId): array {
        $rows = DB::table('POSTED_TO_OFFICE_DATA')
            ->select([
                'OFFICE_CODES.c_office_chn',
                'OFFICE_CODES.c_office_trans',
                'POSTED_TO_OFFICE_DATA.c_office_id',
                'POSTED_TO_OFFICE_DATA.c_posting_id',
                'POSTED_TO_OFFICE_DATA.c_sequence',
                'POSTED_TO_OFFICE_DATA.c_firstyear',
                'POSTED_TO_OFFICE_DATA.c_lastyear',
                'POSTED_TO_OFFICE_DATA.c_source',
                'POSTED_TO_OFFICE_DATA.c_pages',
                'POSTED_TO_OFFICE_DATA.c_notes',
            ])
            ->leftJoin('OFFICE_CODES', 'OFFICE_CODES.c_office_id', '=', 'POSTED_TO_OFFICE_DATA.c_office_id')
            ->where('POSTED_TO_OFFICE_DATA.c_personid', $personId)
            ->orderBy('POSTED_TO_OFFICE_DATA.c_sequence')
            ->get();

        // 取得任官地址
        $addrRows = DB::table('POSTED_TO_ADDR_DATA')
            ->select([
                'POSTED_TO_ADDR_DATA.c_posting_id',
                'AC.c_name_chn AS addr_chn',
                'AC.c_name AS addr',
            ])
            ->leftJoin('ADDR_CODES AS AC', 'AC.c_addr_id', '=', 'POSTED_TO_ADDR_DATA.c_addr_id')
            ->where('POSTED_TO_ADDR_DATA.c_personid', $personId)
            ->where('POSTED_TO_ADDR_DATA.c_office_id', '!=', -1)
            ->get()
            ->groupBy('c_posting_id');

        return [
            'tab' => 'postings',
            'items' => $rows->map(function ($r) use ($addrRows) {
                $postingId = $r->c_posting_id;
                $addrs = $addrRows->get($postingId, collect());

                // 組合任期摘要
                $tenureParts = [];
                if (!empty($r->c_firstyear)) {
                    $tenureParts[] = $r->c_firstyear;
                }
                if (!empty($r->c_lastyear)) {
                    $tenureParts[] = $r->c_lastyear;
                }
                $tenureSummary = implode('–', $tenureParts) ?: null;

                return [
                    'pk' => [
                        'c_office_id' => $r->c_office_id,
                        'c_posting_id' => $r->c_posting_id,
                    ],
                    'sequence' => $r->c_sequence,
                    'office_id' => $r->c_office_id,
                    'posting_id' => $r->c_posting_id,
                    'office_chn' => $r->c_office_chn,
                    'office' => $r->c_office_trans,
                    'first_year' => $r->c_firstyear,
                    'last_year' => $r->c_lastyear,
                    'tenure_summary' => $tenureSummary,
                    'addresses' => $addrs->map(fn ($a) => [
                        'addr_chn' => $a->addr_chn,
                        'addr' => $a->addr,
                    ])->values()->all(),
                    'address_summary' => $addrs->map(function ($a) {
                        return ($a->addr_chn ?? '') . ($a->addr ? ' / ' . $a->addr : '');
                    })->implode('；'),
                    'source_id' => $r->c_source,
                    'pages' => $r->c_pages,
                    'notes' => $r->c_notes,
                ];
            })->values()->all(),
        ];
    }

    // ─── Helpers ───

    private function genderLabel($female): string {
        if ($female === null || $female === '') {
            return '未詳';
        }

        return ((int) $female === 1) ? '女' : '男';
    }

    private function lookupNianHao($code): string {
        if (empty($code)) {
            return '';
        }
        $row = DB::table('NIAN_HAO')->where('c_nianhao_id', $code)->first();
        if (!$row) {
            return (string) $code;
        }

        return ($row->c_nianhao_chn ?? '') . ' / ' . ($row->c_nianhao ?? '');
    }

    private function lookupYearRange($code): string {
        if (empty($code)) {
            return '';
        }

        $row = DB::table('YEAR_RANGE_CODES')->where('c_range_code', $code)->first();
        if (!$row) {
            return (string) $code;
        }

        return trim(($row->c_approx_chn ?? '') . ' / ' . ($row->c_approx ?? ''), ' /');
    }

    private function lookupGanzhi($code): string {
        if (empty($code)) {
            return '';
        }

        $row = DB::table('GANZHI_CODES')->where('c_ganzhi_code', $code)->first();
        if (!$row) {
            return (string) $code;
        }

        return trim(($row->c_ganzhi_chn ?? '') . ' / ' . ($row->c_ganzhi_py ?? ''), ' /');
    }

    private function lookupHouseholdStatus($code): array {
        if (empty($code)) {
            return ['chn' => '', 'eng' => ''];
        }

        $row = DB::table('HOUSEHOLD_STATUS_CODES')->where('c_household_status_code', $code)->first();
        if (!$row) {
            return ['chn' => (string) $code, 'eng' => ''];
        }

        return [
            'chn' => $row->c_household_status_desc_chn ?? '',
            'eng' => $row->c_household_status_desc ?? '',
        ];
    }

    private function intercalaryLabel($value): string {
        if ($value === null || $value === '') {
            return '';
        }

        return ((int) $value === 1) ? '閏月' : '平月';
    }

    private function buildBasicInfoFormFields(
        array $row,
        array $household,
        string $birthNH,
        string $deathNH,
        string $birthRange,
        string $deathRange,
        string $deathAgeRange,
        string $birthGanzhi,
        string $deathGanzhi,
        string $flEarliestNH,
        string $flLatestNH,
        string $indexYearTypeCode,
        string $indexYearTypeChn,
        string $indexYearTypeEng,
        string $indexYearSource
    ): array {
        return [
            'c_personid' => $this->basicInfoFormField('c_personid', 'Person ID (c_personid)', $row['c_personid'] ?? '', 'text', false, [
                'send_on_save' => false,
            ]),

            'c_surname_chn' => $this->basicInfoFormField('c_surname_chn', '中文姓 (c_surname_chn)', $row['c_surname_chn'] ?? ''),
            'c_mingzi_chn' => $this->basicInfoFormField('c_mingzi_chn', '中文名 (c_mingzi_chn)', $row['c_mingzi_chn'] ?? ''),
            'c_name_chn' => $this->basicInfoFormField('c_name_chn', '中文姓名 (c_name_chn)', $row['c_name_chn'] ?? '', 'text', false, [
                'derived' => true,
                'send_on_save' => false,
            ]),

            'c_surname' => $this->basicInfoFormField('c_surname', '拼音姓 (c_surname)', $row['c_surname'] ?? ''),
            'c_mingzi' => $this->basicInfoFormField('c_mingzi', '拼音名 (c_mingzi)', $row['c_mingzi'] ?? ''),
            'c_name' => $this->basicInfoFormField('c_name', '拼音姓名 (c_name)', $row['c_name'] ?? '', 'text', false, [
                'derived' => true,
                'send_on_save' => false,
            ]),

            'c_surname_proper' => $this->basicInfoFormField('c_surname_proper', '外文姓 (c_surname_proper)', $row['c_surname_proper'] ?? ''),
            'c_mingzi_proper' => $this->basicInfoFormField('c_mingzi_proper', '外文名 (c_mingzi_proper)', $row['c_mingzi_proper'] ?? ''),
            'c_name_proper' => $this->basicInfoFormField('c_name_proper', '外文姓名 (c_name_proper)', $row['c_name_proper'] ?? '', 'text', false, [
                'derived' => true,
                'send_on_save' => false,
            ]),

            'c_surname_rm' => $this->basicInfoFormField('c_surname_rm', '外文羅馬字轉寫姓 (c_surname_rm)', $row['c_surname_rm'] ?? ''),
            'c_mingzi_rm' => $this->basicInfoFormField('c_mingzi_rm', '外文羅馬字轉寫名 (c_mingzi_rm)', $row['c_mingzi_rm'] ?? ''),
            'c_name_rm' => $this->basicInfoFormField('c_name_rm', '外文羅馬字轉寫姓名 (c_name_rm)', $row['c_name_rm'] ?? '', 'text', false, [
                'derived' => true,
                'send_on_save' => false,
            ]),

            'c_female' => $this->basicInfoFormField('c_female', '性別 (c_female)', $row['c_female'] ?? '', 'enum', true, [
                'display_value' => $this->genderLabel($row['c_female'] ?? null),
                'options' => [
                    ['value' => '', 'label' => 'NULL-未詳'],
                    ['value' => '0', 'label' => '0-男'],
                    ['value' => '1', 'label' => '1-女'],
                ],
            ]),
            'c_ethnicity_code' => $this->basicInfoFormField('c_ethnicity_code', '種族/部族 (c_ethnicity_code)', $row['c_ethnicity_code'] ?? '', 'enum', true, [
                'display_value' => trim(($row['c_ethnicity_chn'] ?? '').' / '.($row['c_ethnicity'] ?? ''), ' /'),
                'enum_model' => 'ethnicity',
            ]),
            'c_dy' => $this->basicInfoFormField('c_dy', '朝代 (c_dy)', $row['c_dy'] ?? '', 'enum', true, [
                'display_value' => trim(($row['c_dynasty_chn'] ?? '').' / '.($row['c_dynasty'] ?? ''), ' /'),
                'enum_model' => 'dynasty',
            ]),

            'c_birthyear' => $this->basicInfoFormField('c_birthyear', '年份 (c_birthyear)', $row['c_birthyear'] ?? '', 'number'),
            'c_by_nh_code' => $this->basicInfoFormField('c_by_nh_code', '年號 (c_by_nh_code)', $row['c_by_nh_code'] ?? '', 'enum', true, [
                'display_value' => $birthNH,
                'enum_model' => 'nianhao',
                'id_key' => 'c_nianhao_id',
            ]),
            'c_by_nh_year' => $this->basicInfoFormField('c_by_nh_year', '年號年 (c_by_nh_year)', $row['c_by_nh_year'] ?? '', 'number'),
            'c_by_range' => $this->basicInfoFormField('c_by_range', '範圍 (c_by_range)', $row['c_by_range'] ?? '', 'enum', true, [
                'display_value' => $birthRange,
                'enum_model' => 'range',
                'id_key' => 'c_range_code',
            ]),
            'c_by_intercalary' => $this->basicInfoFormField('c_by_intercalary', '閏月 (c_by_intercalary)', (string) ($row['c_by_intercalary'] ?? '0'), 'checkbox', true, [
                'display_value' => $this->intercalaryLabel($row['c_by_intercalary'] ?? null),
            ]),
            'c_by_month' => $this->basicInfoFormField('c_by_month', '月份 (c_by_month)', $row['c_by_month'] ?? '', 'number'),
            'c_by_day' => $this->basicInfoFormField('c_by_day', '日期 (c_by_day)', $row['c_by_day'] ?? '', 'number'),
            'c_by_day_gz' => $this->basicInfoFormField('c_by_day_gz', '日干支 (c_by_day_gz)', $row['c_by_day_gz'] ?? '', 'enum', true, [
                'display_value' => $birthGanzhi,
                'enum_model' => 'ganzhi',
                'id_key' => 'c_ganzhi_code',
            ]),

            'c_deathyear' => $this->basicInfoFormField('c_deathyear', '年份 (c_deathyear)', $row['c_deathyear'] ?? '', 'number'),
            'c_dy_nh_code' => $this->basicInfoFormField('c_dy_nh_code', '年號 (c_dy_nh_code)', $row['c_dy_nh_code'] ?? '', 'enum', true, [
                'display_value' => $deathNH,
                'enum_model' => 'nianhao',
                'id_key' => 'c_nianhao_id',
            ]),
            'c_dy_nh_year' => $this->basicInfoFormField('c_dy_nh_year', '年號年 (c_dy_nh_year)', $row['c_dy_nh_year'] ?? '', 'number'),
            'c_dy_range' => $this->basicInfoFormField('c_dy_range', '範圍 (c_dy_range)', $row['c_dy_range'] ?? '', 'enum', true, [
                'display_value' => $deathRange,
                'enum_model' => 'range',
                'id_key' => 'c_range_code',
            ]),
            'c_dy_intercalary' => $this->basicInfoFormField('c_dy_intercalary', '閏月 (c_dy_intercalary)', (string) ($row['c_dy_intercalary'] ?? '0'), 'checkbox', true, [
                'display_value' => $this->intercalaryLabel($row['c_dy_intercalary'] ?? null),
            ]),
            'c_dy_month' => $this->basicInfoFormField('c_dy_month', '月份 (c_dy_month)', $row['c_dy_month'] ?? '', 'number'),
            'c_dy_day' => $this->basicInfoFormField('c_dy_day', '日期 (c_dy_day)', $row['c_dy_day'] ?? '', 'number'),
            'c_dy_day_gz' => $this->basicInfoFormField('c_dy_day_gz', '日干支 (c_dy_day_gz)', $row['c_dy_day_gz'] ?? '', 'enum', true, [
                'display_value' => $deathGanzhi,
                'enum_model' => 'ganzhi',
                'id_key' => 'c_ganzhi_code',
            ]),

            'c_death_age' => $this->basicInfoFormField('c_death_age', '享年 (c_death_age)', $row['c_death_age'] ?? '', 'number'),
            'c_death_age_range' => $this->basicInfoFormField('c_death_age_range', '享年範圍 (c_death_age_range)', $row['c_death_age_range'] ?? '', 'enum', true, [
                'display_value' => $deathAgeRange,
                'enum_model' => 'range',
                'id_key' => 'c_range_code',
            ]),

            'c_index_year' => $this->basicInfoFormField('c_index_year', 'Index Year (c_index_year)', $row['c_index_year'] ?? '', 'number', false, [
                'derived' => true,
                'send_on_save' => false,
            ]),
            'c_index_year_type_code' => $this->basicInfoFormField('c_index_year_type_code', 'Index Year Type (c_index_year_type_code)', $indexYearTypeCode, 'text', false, [
                'derived' => true,
                'send_on_save' => false,
                'display_value' => trim($indexYearTypeChn.' / '.$indexYearTypeEng, ' /'),
            ]),
            'c_index_year_source_id' => $this->basicInfoFormField('c_index_year_source_id', 'Index Year Source (c_index_year_source_id)', $row['c_index_year_source_id'] ?? '', 'text', false, [
                'derived' => true,
                'send_on_save' => false,
                'display_value' => $indexYearSource,
            ]),
            'c_index_addr_id' => $this->basicInfoFormField('c_index_addr_id', 'Index Address (c_index_addr_id)', $row['c_index_addr_id'] ?? '', 'text', false, [
                'derived' => true,
                'send_on_save' => false,
                'display_value' => trim(($row['index_addr_chn'] ?? '').' / '.($row['index_addr'] ?? ''), ' /'),
            ]),
            'c_index_addr_type_code' => $this->basicInfoFormField('c_index_addr_type_code', 'Index Address Type (c_index_addr_type_code)', $row['c_index_addr_type_code'] ?? '', 'text', false, [
                'derived' => true,
                'send_on_save' => false,
            ]),

            'c_fl_earliest_year' => $this->basicInfoFormField('c_fl_earliest_year', '公元年份 (c_fl_earliest_year)', $row['c_fl_earliest_year'] ?? '', 'number'),
            'c_fl_ey_nh_code' => $this->basicInfoFormField('c_fl_ey_nh_code', '年號 (c_fl_ey_nh_code)', $row['c_fl_ey_nh_code'] ?? '', 'enum', true, [
                'display_value' => $flEarliestNH,
                'enum_model' => 'nianhao',
                'id_key' => 'c_nianhao_id',
            ]),
            'c_fl_ey_nh_year' => $this->basicInfoFormField('c_fl_ey_nh_year', '年號年 (c_fl_ey_nh_year)', $row['c_fl_ey_nh_year'] ?? '', 'number'),
            'c_fl_ey_notes' => $this->basicInfoFormField('c_fl_ey_notes', '備註 (c_fl_ey_notes)', $row['c_fl_ey_notes'] ?? '', 'textarea'),

            'c_fl_latest_year' => $this->basicInfoFormField('c_fl_latest_year', '公元年份 (c_fl_latest_year)', $row['c_fl_latest_year'] ?? '', 'number'),
            'c_fl_ly_nh_code' => $this->basicInfoFormField('c_fl_ly_nh_code', '年號 (c_fl_ly_nh_code)', $row['c_fl_ly_nh_code'] ?? '', 'enum', true, [
                'display_value' => $flLatestNH,
                'enum_model' => 'nianhao',
                'id_key' => 'c_nianhao_id',
            ]),
            'c_fl_ly_nh_year' => $this->basicInfoFormField('c_fl_ly_nh_year', '年號年 (c_fl_ly_nh_year)', $row['c_fl_ly_nh_year'] ?? '', 'number'),
            'c_fl_ly_notes' => $this->basicInfoFormField('c_fl_ly_notes', '備註 (c_fl_ly_notes)', $row['c_fl_ly_notes'] ?? '', 'textarea'),

            'c_choronym_code' => $this->basicInfoFormField('c_choronym_code', '郡望 (c_choronym_code)', $row['c_choronym_code'] ?? '', 'enum', true, [
                'display_value' => trim(($row['c_choronym_chn'] ?? '').' / '.($row['c_choronym'] ?? ''), ' /'),
                'enum_model' => 'choronym',
            ]),
            'c_household_status_code' => $this->basicInfoFormField('c_household_status_code', '戶籍 (c_household_status_code)', $row['c_household_status_code'] ?? '', 'enum', true, [
                'display_value' => trim(($household['chn'] ?? '').' / '.($household['eng'] ?? ''), ' /'),
                'enum_model' => 'household',
            ]),
            'c_notes' => $this->basicInfoFormField('c_notes', '備註 (c_notes)', $row['c_notes'] ?? '', 'textarea'),
            'c_created_by' => $this->basicInfoFormField('c_created_by', 'Created By (c_created_by)', $row['c_created_by'] ?? '', 'text', false, [
                'send_on_save' => false,
            ]),
            'c_created_date' => $this->basicInfoFormField('c_created_date', 'Created Date (c_created_date)', $row['c_created_date'] ?? '', 'text', false, [
                'send_on_save' => false,
            ]),
            'c_modified_by' => $this->basicInfoFormField('c_modified_by', 'Modified By (c_modified_by)', $row['c_modified_by'] ?? '', 'text', false, [
                'send_on_save' => false,
            ]),
            'c_modified_date' => $this->basicInfoFormField('c_modified_date', 'Modified Date (c_modified_date)', $row['c_modified_date'] ?? '', 'text', false, [
                'send_on_save' => false,
            ]),
        ];
    }

    private function basicInfoFormField(string $key, string $label, $value, string $input = 'text', bool $editable = true, array $extra = []): array {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'input' => $input,
            'editable' => $editable,
            'send_on_save' => $editable,
        ], $extra);
    }
}
