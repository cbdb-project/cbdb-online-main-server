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
            ],
        ];
    }

    private function tabAltNames(int $personId): array {
        $rows = DB::table('ALTNAME_DATA')
            ->select([
                'ALTNAME_DATA.c_alt_name_chn',
                'ALTNAME_DATA.c_alt_name',
                'ALTNAME_DATA.c_alt_name_type_code',
                'ATC.c_name_type_desc_chn AS c_alt_name_type_desc_chn',
                'ATC.c_name_type_desc AS c_alt_name_type_desc',
                'ALTNAME_DATA.c_source',
                'ALTNAME_DATA.c_pages',
                'ALTNAME_DATA.c_notes',
            ])
            ->leftJoin('ALTNAME_CODES AS ATC', 'ATC.c_name_type_code', '=', 'ALTNAME_DATA.c_alt_name_type_code')
            ->where('ALTNAME_DATA.c_personid', $personId)
            ->orderBy('ALTNAME_DATA.c_alt_name_type_code')
            ->get();

        return [
            'columns' => [
                'c_alt_name_chn' => '中文別名',
                'c_alt_name' => '英文別名',
                'c_alt_name_type_desc_chn' => '類型（中文）',
                'c_alt_name_type_desc' => '類型（英文）',
                'c_source' => '出處',
                'c_pages' => '頁碼',
                'c_notes' => '備註',
            ],
            'rows' => $rows->map(fn ($r) => (array) $r)->values()->all(),
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
            'columns' => [
                'addr_chn' => '地址（中文）',
                'addr' => '地址（英文）',
                'c_addr_desc_chn' => '類型（中文）',
                'c_addr_desc' => '類型（英文）',
                'c_firstyear' => '始年',
                'c_lastyear' => '終年',
                'c_notes' => '備註',
            ],
            'rows' => $rows->map(fn ($r) => (array) $r)->values()->all(),
        ];
    }

    private function tabTexts(int $personId): array {
        $rows = DB::table('BIOG_TEXT_DATA')
            ->select([
                'TEXT_CODES.c_title_chn',
                'TEXT_CODES.c_title',
                'TEXT_CODES.c_text_year',
                'TEXT_ROLE_CODES.c_role_desc_chn AS c_role_chn',
                'TEXT_ROLE_CODES.c_role_desc AS c_role',
                'BIOG_TEXT_DATA.c_textid',
                'BIOG_TEXT_DATA.c_role_id',
            ])
            ->leftJoin('TEXT_CODES', 'TEXT_CODES.c_textid', '=', 'BIOG_TEXT_DATA.c_textid')
            ->leftJoin('TEXT_ROLE_CODES', 'TEXT_ROLE_CODES.c_role_id', '=', 'BIOG_TEXT_DATA.c_role_id')
            ->where('BIOG_TEXT_DATA.c_personid', $personId)
            ->get();

        return [
            'columns' => [
                'c_title_chn' => '著作（中文）',
                'c_title' => '著作（英文）',
                'c_text_year' => '年份',
                'c_role_chn' => '角色（中文）',
                'c_role' => '角色（英文）',
            ],
            'rows' => $rows->map(fn ($r) => (array) $r)->values()->all(),
        ];
    }

    private function tabSources(int $personId): array {
        $rows = DB::table('BIOG_SOURCE_DATA')
            ->select([
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
            'columns' => [
                'c_title_chn' => '出處（中文）',
                'c_title' => '出處（英文）',
                'c_pages' => '頁碼',
                'c_notes' => '備註',
                'c_main_source' => '主要出處',
                'c_self_bio' => '自傳',
            ],
            'rows' => $rows->map(fn ($r) => (array) $r)->values()->all(),
        ];
    }

    private function tabEntries(int $personId): array {
        $rows = DB::table('ENTRY_DATA')
            ->select([
                'ENTRY_CODES.c_entry_desc_chn',
                'ENTRY_CODES.c_entry_desc',
                'ENTRY_DATA.c_year',
                'ENTRY_DATA.c_sequence',
                'ENTRY_DATA.c_kin_code',
                'ENTRY_DATA.c_kin_id',
                'ENTRY_DATA.c_assoc_code',
                'ENTRY_DATA.c_assoc_id',
            ])
            ->leftJoin('ENTRY_CODES', 'ENTRY_CODES.c_entry_code', '=', 'ENTRY_DATA.c_entry_code')
            ->where('ENTRY_DATA.c_personid', $personId)
            ->orderBy('ENTRY_DATA.c_sequence')
            ->get();

        return [
            'columns' => [
                'c_entry_desc_chn' => '入仕方式（中文）',
                'c_entry_desc' => '入仕方式（英文）',
                'c_year' => '年份',
                'c_sequence' => '序號',
            ],
            'rows' => $rows->map(fn ($r) => (array) $r)->values()->all(),
        ];
    }

    private function tabEvents(int $personId): array {
        $rows = DB::table('EVENTS_DATA')
            ->select([
                'EVENT_CODES.c_event_name_chn AS c_event_desc_chn',
                'EVENT_CODES.c_event_name AS c_event_desc',
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
            'columns' => [
                'c_event_desc_chn' => '事件（中文）',
                'c_event_desc' => '事件（英文）',
                'c_year' => '年份',
                'c_month' => '月',
                'c_day' => '日',
                'c_source' => '出處',
                'c_pages' => '頁碼',
                'c_notes' => '備註',
            ],
            'rows' => $rows->map(fn ($r) => (array) $r)->values()->all(),
        ];
    }

    private function tabStatuses(int $personId): array {
        $rows = DB::table('STATUS_DATA')
            ->select([
                'STATUS_CODES.c_status_desc_chn',
                'STATUS_CODES.c_status_desc',
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
            'columns' => [
                'c_status_desc_chn' => '身分（中文）',
                'c_status_desc' => '身分（英文）',
                'c_firstyear' => '始年',
                'c_lastyear' => '終年',
                'c_source' => '出處',
                'c_pages' => '頁碼',
                'c_notes' => '備註',
            ],
            'rows' => $rows->map(fn ($r) => (array) $r)->values()->all(),
        ];
    }

    private function tabAssociations(int $personId): array {
        $rows = DB::table('ASSOC_DATA')
            ->select([
                'ASSOC_CODES.c_assoc_desc_chn',
                'ASSOC_CODES.c_assoc_desc',
                'ASSOC_DATA.c_assoc_id',
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
            'columns' => [
                'c_assoc_desc_chn' => '關係（中文）',
                'c_assoc_desc' => '關係（英文）',
                'c_assoc_id' => '關聯人物 ID',
                'assoc_name_chn' => '關聯人物（中文）',
                'assoc_name' => '關聯人物（英文）',
                'c_assoc_first_year' => '始年',
                'c_assoc_last_year' => '終年',
                'c_source' => '出處',
                'c_pages' => '頁碼',
                'c_notes' => '備註',
            ],
            'rows' => $rows->map(fn ($r) => (array) $r)->values()->all(),
        ];
    }

    private function tabKinship(int $personId): array {
        $rows = DB::table('KIN_DATA')
            ->select([
                'KINSHIP_CODES.c_kinrel_chn',
                'KINSHIP_CODES.c_kinrel',
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
            'columns' => [
                'c_kinrel_chn' => '關係（中文）',
                'c_kinrel' => '關係（英文）',
                'c_kin_id' => '親屬 ID',
                'kin_name_chn' => '親屬（中文）',
                'kin_name' => '親屬（英文）',
                'c_source' => '出處',
                'c_pages' => '頁碼',
                'c_notes' => '備註',
            ],
            'rows' => $rows->map(fn ($r) => (array) $r)->values()->all(),
        ];
    }

    private function tabPossessions(int $personId): array {
        $rows = DB::table('POSSESSION_DATA')
            ->select([
                'POSSESSION_ACT_CODES.c_possession_act_desc_chn',
                'POSSESSION_ACT_CODES.c_possession_act_desc',
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
            'columns' => [
                'c_possession_act_desc_chn' => '行為（中文）',
                'c_possession_act_desc' => '行為（英文）',
                'c_possession_desc_chn' => '財產（中文）',
                'c_possession_desc' => '財產（英文）',
                'c_quantity' => '數量',
                'c_possession_yr' => '年份',
                'c_source' => '出處',
                'c_pages' => '頁碼',
                'c_notes' => '備註',
            ],
            'rows' => $rows->map(fn ($r) => (array) $r)->values()->all(),
        ];
    }

    private function tabSocialInstitutions(int $personId): array {
        $rows = DB::table('BIOG_INST_DATA')
            ->select([
                'BIOG_INST_CODES.c_bi_role_chn',
                'BIOG_INST_CODES.c_bi_role_desc AS c_bi_role',
                'INST_NAMES.c_inst_name_hz AS c_inst_name_chn',
                'INST_NAMES.c_inst_name_py AS c_inst_name',
                'BIOG_INST_DATA.c_source',
                'BIOG_INST_DATA.c_pages',
                'BIOG_INST_DATA.c_notes',
            ])
            ->leftJoin('BIOG_INST_CODES', 'BIOG_INST_CODES.c_bi_role_code', '=', 'BIOG_INST_DATA.c_bi_role_code')
            ->leftJoin('SOCIAL_INSTITUTION_NAME_CODES AS INST_NAMES', 'INST_NAMES.c_inst_name_code', '=', 'BIOG_INST_DATA.c_inst_name_code')
            ->where('BIOG_INST_DATA.c_personid', $personId)
            ->get();

        return [
            'columns' => [
                'c_bi_role_chn' => '角色（中文）',
                'c_bi_role' => '角色（英文）',
                'c_inst_name_chn' => '機構（中文）',
                'c_inst_name' => '機構（英文）',
                'c_source' => '出處',
                'c_pages' => '頁碼',
                'c_notes' => '備註',
            ],
            'rows' => $rows->map(fn ($r) => (array) $r)->values()->all(),
        ];
    }

    private function tabPostings(int $personId): array {
        $rows = DB::table('POSTED_TO_OFFICE_DATA')
            ->select([
                'OFFICE_CODES.c_office_chn',
                'OFFICE_CODES.c_office_trans AS c_office',
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

        $result = $rows->map(function ($r) use ($addrRows) {
            $row = (array) $r;
            $postingId = $row['c_posting_id'] ?? null;
            $addrs = $addrRows->get($postingId, collect());
            $row['addresses'] = $addrs->map(function ($a) {
                return ($a->addr_chn ?? '') . ($a->addr ? ' / ' . $a->addr : '');
            })->implode('；');

            return $row;
        });

        return [
            'columns' => [
                'c_office_chn' => '官名（中文）',
                'c_office' => '官名（英文）',
                'addresses' => '任官地址',
                'c_sequence' => '序號',
                'c_firstyear' => '始年',
                'c_lastyear' => '終年',
                'c_source' => '出處',
                'c_pages' => '頁碼',
                'c_notes' => '備註',
            ],
            'rows' => $result->values()->all(),
        ];
    }

    // ─── Helpers ───

    private function genderLabel($female): string {
        if ($female === null || $female === '') {
            return '';
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
}
