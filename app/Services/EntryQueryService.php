<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EntryQueryService {
    public function getEntryTypes(): Collection {
        return DB::table('ENTRY_TYPES')
            ->select(
                'c_entry_type',
                'c_entry_type_desc',
                'c_entry_type_desc_chn',
                'c_entry_type_parent_id',
                'c_entry_type_level',
                'c_entry_type_sortorder'
            )
            ->orderBy('c_entry_type_sortorder')
            ->orderBy('c_entry_type')
            ->get();
    }

    public function getEntryCodes(?string $typeId = null): Collection {
        $query = DB::table('ENTRY_CODES as ec')
            ->select(
                'ec.c_entry_code',
                'ec.c_entry_desc',
                'ec.c_entry_desc_chn'
            )
            ->orderBy('ec.c_entry_code');

        if ($typeId) {
            $query->join('ENTRY_CODE_TYPE_REL as rel', 'ec.c_entry_code', '=', 'rel.c_entry_code')
                ->where('rel.c_entry_type', $typeId);
        }

        return $query->get();
    }

    public function getEntryCodesByIds(array $entryCodes): Collection {
        if ($entryCodes === []) {
            return collect();
        }

        return DB::table('ENTRY_CODES')
            ->select('c_entry_code', 'c_entry_desc', 'c_entry_desc_chn')
            ->whereIn('c_entry_code', $entryCodes)
            ->orderBy('c_entry_code')
            ->get();
    }

    public function getDynasties(): Collection {
        return DB::table('DYNASTIES')
            ->select('c_dy', 'c_dynasty', 'c_dynasty_chn', 'c_start', 'c_end')
            ->orderByRaw("
                CASE
                    WHEN COALESCE(c_dynasty_chn, c_dynasty) IN ('朝鮮', '韓國', '高麗', '新羅') THEN 1
                    ELSE 0
                END
            ")
            ->orderBy('c_start')
            ->orderBy('c_end')
            ->orderBy('c_dy')
            ->get();
    }

    public function searchPlaces(?string $keyword = null, int $limit = 20): Collection {
        $query = DB::table('ADDR_CODES')
            ->select('c_addr_id', 'c_name', 'c_name_chn')
            ->orderBy('c_name_chn')
            ->orderBy('c_name')
            ->orderBy('c_addr_id');

        if ($keyword !== null && $keyword !== '') {
            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('c_name_chn', 'like', "%{$keyword}%")
                    ->orWhere('c_name', 'like', "%{$keyword}%")
                    ->orWhere('c_addr_id', 'like', "%{$keyword}%");
            });
        }

        return $query->limit(max(1, min($limit, 50)))->get();
    }

    public function getPlacesByIds(array $placeIds): Collection {
        if ($placeIds === []) {
            return collect();
        }

        return DB::table('ADDR_CODES')
            ->select('c_addr_id', 'c_name', 'c_name_chn')
            ->whereIn('c_addr_id', $placeIds)
            ->orderBy('c_name_chn')
            ->orderBy('c_name')
            ->get();
    }

    public function normalizeFilters(array $filters): array {
        $entryCodes = array_values(array_unique(array_map('intval', array_filter($filters['entry_codes'] ?? [], static fn ($value) => $value !== null && $value !== ''))));
        $placeIds = array_values(array_unique(array_map('intval', array_filter($filters['place_ids'] ?? [], static fn ($value) => $value !== null && $value !== ''))));
        sort($entryCodes);
        sort($placeIds);

        $normalized = [
            'person_keyword' => $this->normalizeText($filters['person_keyword'] ?? null),
            'type_id' => $this->normalizeText($filters['type_id'] ?? null),
            'entry_codes' => $entryCodes,
            'place_ids' => $placeIds,
            'include_sub_units' => $this->toBoolean($filters['include_sub_units'] ?? false),
            'use_index_year_range' => $this->toBoolean($filters['use_index_year_range'] ?? false),
            'index_year_from' => $this->normalizeNullableInt($filters['index_year_from'] ?? null),
            'index_year_to' => $this->normalizeNullableInt($filters['index_year_to'] ?? null),
            'use_entry_year_range' => $this->toBoolean($filters['use_entry_year_range'] ?? false),
            'entry_year_from' => $this->normalizeNullableInt($filters['entry_year_from'] ?? null),
            'entry_year_to' => $this->normalizeNullableInt($filters['entry_year_to'] ?? null),
            'dynasty_codes' => $this->normalizeStringArray($filters['dynasty_codes'] ?? []),
            'records_page' => max(1, (int) ($filters['records_page'] ?? 1)),
            'people_page' => max(1, (int) ($filters['people_page'] ?? 1)),
        ];

        if ($normalized['use_index_year_range']) {
            $normalized['index_year_from'] ??= -200;
            $normalized['index_year_to'] ??= 1911;
        }

        if ($normalized['use_entry_year_range']) {
            $normalized['entry_year_from'] ??= -200;
            $normalized['entry_year_to'] ??= 1911;
        }

        return $normalized;
    }

    public function hasConditions(array $filters): bool {
        return $filters['person_keyword'] !== null
            || $filters['entry_codes'] !== []
            || $filters['place_ids'] !== []
            || $filters['use_index_year_range']
            || $filters['use_entry_year_range']
            || $filters['dynasty_codes'] !== [];
    }

    public function search(array $filters): array {
        $normalized = $this->normalizeFilters($filters);
        $recordsQuery = $this->buildRecordsQuery($normalized);
        $peopleQuery = $this->buildPeopleQuery($normalized);

        $records = (clone $recordsQuery)
            ->orderBy('ec.c_entry_desc_chn')
            ->orderBy('ec.c_entry_desc')
            ->orderBy('ed.c_year')
            ->orderBy('bm.c_personid')
            ->orderBy('ed.c_sequence')
            ->paginate(20, ['*'], 'records_page', $normalized['records_page']);

        $people = (clone $peopleQuery)
            ->orderBy('c_personid')
            ->paginate(20, ['*'], 'people_page', $normalized['people_page']);

        return [
            'filters' => $normalized,
            'records' => $records,
            'people' => $people,
            'summary' => [
                'record_count' => $records->total(),
                'person_count' => $people->total(),
                'selected_entry_code_count' => count($normalized['entry_codes']),
                'selected_place_count' => count($normalized['place_ids']),
            ],
        ];
    }

    private function buildRecordsQuery(array $filters): Builder {
        $query = DB::table('ENTRY_DATA as ed')
            ->join('BIOG_MAIN as bm', 'ed.c_personid', '=', 'bm.c_personid')
            ->join('ENTRY_CODES as ec', 'ed.c_entry_code', '=', 'ec.c_entry_code')
            ->leftJoin('DYNASTIES as dynasties', 'dynasties.c_dy', '=', 'bm.c_dy')
            ->leftJoin('ADDR_CODES as index_addr', 'index_addr.c_addr_id', '=', 'bm.c_index_addr_id')
            ->leftJoin('ADDR_CODES as entry_addr', 'entry_addr.c_addr_id', '=', 'ed.c_entry_addr_id')
            ->select(
                'bm.c_personid',
                'bm.c_name_chn',
                'bm.c_name',
                'bm.c_dy',
                'dynasties.c_dynasty',
                'dynasties.c_dynasty_chn',
                'bm.c_index_year',
                'bm.c_index_addr_id',
                'index_addr.c_name as c_index_addr_name',
                'index_addr.c_name_chn as c_index_addr_chn',
                'ed.c_entry_code',
                'ec.c_entry_desc_chn',
                'ec.c_entry_desc',
                'ed.c_year',
                'ed.c_sequence',
                'ed.c_entry_addr_id',
                'entry_addr.c_name as c_entry_addr_name',
                'entry_addr.c_name_chn as c_entry_addr_chn',
                'ed.c_exam_rank',
                'ed.c_notes',
                'ed.c_posting_notes'
            )
            ->selectRaw("
                CASE
                    WHEN bm.c_female = 1 THEN 'F'
                    WHEN bm.c_female = 0 THEN 'M'
                    ELSE NULL
                END AS c_sex_label
            ");

        if (Schema::hasTable('INDEXYEAR_TYPE_CODES')) {
            $query->leftJoin('INDEXYEAR_TYPE_CODES as index_year_types', 'index_year_types.c_index_year_type_code', '=', 'bm.c_index_year_type_code')
                ->addSelect(DB::raw('COALESCE(index_year_types.c_index_year_type_hz, index_year_types.c_index_year_type_desc) as c_index_year_type_label'));
        } else {
            $query->addSelect(DB::raw('NULL as c_index_year_type_label'));
        }

        if (Schema::hasTable('NIAN_HAO')) {
            $query->leftJoin('NIAN_HAO as nianhao', 'nianhao.c_nianhao_id', '=', 'ed.c_entry_nh_id')
                ->addSelect('ed.c_entry_nh_year')
                ->addSelect(DB::raw('COALESCE(nianhao.c_nianhao_chn, nianhao.c_nianhao_pin) as c_entry_nianhao_label'));
        } else {
            $query->addSelect(DB::raw('NULL as c_entry_nh_year'))
                ->addSelect(DB::raw('NULL as c_entry_nianhao_label'));
        }

        if (Schema::hasTable('YEAR_RANGE_CODES')) {
            $query->leftJoin('YEAR_RANGE_CODES as year_ranges', 'year_ranges.c_range_code', '=', 'ed.c_entry_range')
                ->addSelect(DB::raw('COALESCE(year_ranges.c_range_chn, year_ranges.c_range) as c_entry_range_label'));
        } else {
            $query->addSelect(DB::raw('NULL as c_entry_range_label'));
        }

        if (Schema::hasTable('PARENTAL_STATUS_CODES')) {
            $query->leftJoin('PARENTAL_STATUS_CODES as parental_status', 'parental_status.c_parental_status_code', '=', 'ed.c_parental_status_code')
                ->addSelect(DB::raw('COALESCE(parental_status.c_parental_status_desc_chn, parental_status.c_parental_status_desc) as c_parental_status_label'));
        } else {
            $query->addSelect(DB::raw('NULL as c_parental_status_label'));
        }

        if (Schema::hasTable('TEXT_CODES')) {
            $query->leftJoin('TEXT_CODES as sources', 'sources.c_textid', '=', 'ed.c_source')
                ->addSelect(DB::raw('COALESCE(sources.c_title_chn, sources.c_title) as c_source_label'));
        } else {
            $query->addSelect(DB::raw('NULL as c_source_label'));
        }

        if ($filters['person_keyword'] !== null) {
            // #85：拼音 ü→v 規範化（CBDB 以 v 存 ü 韻）。
            $keyword = '%' . \App\Support\PinyinSearchNormalizer::umlautToV($filters['person_keyword']) . '%';

            $query->where(function (Builder $builder) use ($keyword) {
                $builder->where('bm.c_name_chn', 'like', $keyword)
                    ->orWhere('bm.c_name', 'like', $keyword);

                if (Schema::hasTable('ALTNAME_DATA')) {
                    $builder->orWhereExists(function (Builder $subquery) use ($keyword) {
                        $subquery->select(DB::raw(1))
                            ->from('ALTNAME_DATA as alt')
                            ->whereColumn('alt.c_personid', 'bm.c_personid')
                            ->where(function (Builder $altQuery) use ($keyword) {
                                $altQuery->where('alt.c_alt_name_chn', 'like', $keyword)
                                    ->orWhere('alt.c_alt_name', 'like', $keyword);
                            });
                    });
                }
            });
        }

        if ($filters['entry_codes'] !== []) {
            $query->whereIn('ed.c_entry_code', $filters['entry_codes']);
        }

        if ($filters['use_entry_year_range']) {
            $entryYearFrom = min($filters['entry_year_from'], $filters['entry_year_to']);
            $entryYearTo = max($filters['entry_year_from'], $filters['entry_year_to']);
            $query->whereBetween('ed.c_year', [$entryYearFrom, $entryYearTo]);
        }

        if ($filters['use_index_year_range']) {
            $indexYearFrom = min($filters['index_year_from'], $filters['index_year_to']);
            $indexYearTo = max($filters['index_year_from'], $filters['index_year_to']);
            $query->whereBetween('bm.c_index_year', [$indexYearFrom, $indexYearTo]);
        }

        if ($filters['place_ids'] !== []) {
            $query->where(function (Builder $builder) use ($filters) {
                $builder->whereIn('ed.c_entry_addr_id', $filters['place_ids']);

                if ($filters['include_sub_units'] && Schema::hasTable('ADDR_BELONGS_DATA')) {
                    $builder->orWhereExists(function (Builder $subquery) use ($filters) {
                        $subquery->select(DB::raw(1))
                            ->from('ADDR_BELONGS_DATA as belongs')
                            ->whereColumn('belongs.c_addr_id', 'ed.c_entry_addr_id')
                            ->whereIn('belongs.c_belongs_to', $filters['place_ids']);
                    });
                }
            });
        }

        if ($filters['dynasty_codes'] !== []) {
            $query->whereIn('bm.c_dy', $filters['dynasty_codes']);
        }

        return $query;
    }

    private function buildPeopleQuery(array $filters): Builder {
        return DB::query()
            ->fromSub($this->buildRecordsQuery($filters), 'records')
            ->select(
                'c_personid',
                'c_name_chn',
                'c_name',
                'c_dy',
                'c_dynasty',
                'c_dynasty_chn',
                'c_index_year',
                'c_index_addr_id',
                'c_index_addr_name',
                'c_index_addr_chn',
                'c_index_year_type_label',
                'c_sex_label'
            )
            ->selectRaw('MIN(c_entry_addr_id) as c_entry_addr_id')
            ->selectRaw('MIN(c_entry_addr_name) as c_entry_addr_name')
            ->selectRaw('MIN(c_entry_addr_chn) as c_entry_addr_chn')
            ->selectRaw('COUNT(*) as entry_count')
            ->groupBy(
                'c_personid',
                'c_name_chn',
                'c_name',
                'c_dy',
                'c_dynasty',
                'c_dynasty_chn',
                'c_index_year',
                'c_index_addr_id',
                'c_index_addr_name',
                'c_index_addr_chn',
                'c_index_year_type_label',
                'c_sex_label'
            );
    }

    private function normalizeText(mixed $value): ?string {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeNullableInt(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeStringArray(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($item) => $this->normalizeText($item),
            $value
        ))));
        sort($normalized);

        return $normalized;
    }

    private function toBoolean(mixed $value): bool {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
