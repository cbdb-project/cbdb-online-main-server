<?php

namespace App\Services;

use App\Support\PinyinSearchNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * 人名搜尋（別名感知）共用服務。
 *
 * 首頁人物瀏覽器（PersonBrowserService）與唯讀 MCP 工具（search_person_by_name）共用此處的
 * FTS 命中邏輯，避免兩套實現漂移。名字的「主名＋字／號／別名 union、繁簡雙寫、後綴展開」已在
 * 建索引階段烘焙進 CBDB__NAME_FTS（見 RebuildNameSearchIndex / NameSearchIndexService），
 * 故查詢端只需對 search_term 做前綴 LIKE（走 idx_cbdb__name_search_term 索引）。
 *
 * 註：異體字（潁/穎/頴、璵/嶼）目前不做歸一——與首頁行為一致；命中某異體寫法需該寫法本身
 * 已作為一條名字存在於索引中。
 */
class NameSearchService {
    /**
     * 從 CBDB__NAME_FTS 倒排索引以前綴匹配取得 person id（含主名與各類別名）。
     *
     * @param  int[]  $typeCodes  限定 name_type_code（如 [4] 只搜字、[5] 只搜號）；空＝不限
     * @return int[]  依相關度（search_term 長度升冪）去重後的 person id
     */
    public function ftsPersonIds(string $q, array $typeCodes = [], int $limit = 500): array {
        if ($q === '') {
            return [];
        }

        $query = DB::table('CBDB__NAME_FTS')
            ->where('search_term', 'LIKE', $q . '%');

        $typeCodes = array_values(array_unique(array_map('intval', $typeCodes)));
        if (!empty($typeCodes)) {
            $query->whereIn('name_type_code', $typeCodes);
        }

        return $query
            ->orderByRaw('LENGTH(search_term) ASC')
            ->limit($limit)
            ->pluck('c_personid')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * 拼音／拉丁字母查詢的回退：對 BIOG_MAIN 多欄位 LIKE（不含別名）。與 PersonBrowserService 同口徑。
     *
     * @return int[]
     */
    public function fallbackPersonIds(string $rawQ, int $limit = 500): array {
        $qForms = PinyinSearchNormalizer::expand($rawQ);
        if (empty($qForms)) {
            return [];
        }

        return DB::table('BIOG_MAIN')
            ->select('c_personid')
            ->where(function ($sub) use ($qForms) {
                foreach ($qForms as $form) {
                    $sub->orWhere('c_name_chn', 'like', '%' . $form . '%')
                        ->orWhere('c_name', 'like', '%' . $form . '%')
                        ->orWhere('c_surname', 'like', $form)
                        ->orWhere('c_mingzi', 'like', $form)
                        ->orWhere('c_name_proper', 'like', '%' . $form . '%')
                        ->orWhere('c_name_rm', 'like', '%' . $form . '%');
                }
            })
            ->limit($limit)
            ->pluck('c_personid')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * 別名感知的人名搜尋（MCP 用）。支援 personid、主名、字、號、別名等，效果對齊首頁人物瀏覽器。
     *
     * @param  int[]  $typeCodes  限定別名類型（僅對中文 FTS 路徑有效；拼音回退只查主名欄位）
     * @return array{keyword:string,total:int,limit:int,offset:int,rows:array<int,array<string,mixed>>}
     */
    public function searchPersons(string $keyword, ?int $dynasty = null, array $typeCodes = [], int $limit = 20, int $offset = 0): array {
        $rawQ = trim($keyword);
        $q = PinyinSearchNormalizer::umlautToV($rawQ);
        $limit = max(1, min($limit, (int) config('mcp.cbdb.max_limit', 100)));
        $offset = max(0, $offset);

        // 1. 解析候選 person id（三分支，與 PersonBrowserService::search 同口徑）
        $ids = [];
        $usedFts = false;
        if ($q !== '') {
            if (ctype_digit($q)) {
                $ids = [(int) $q];
            } elseif (PinyinSearchNormalizer::isChineseQuery($q)) {
                $ids = $this->ftsPersonIds($q, $typeCodes, 500);
                $usedFts = true;
                if (empty($ids) && empty($typeCodes)) {
                    // 中文查詢在 FTS 無命中時回退多欄位 LIKE（涵蓋 FTS 尚未索引的情況）。
                    // 注意：回退只查 BIOG_MAIN 主名欄位、無法尊重 name_type_codes，故僅在未限定類型時啟用。
                    $ids = $this->fallbackPersonIds($rawQ, 500);
                    $usedFts = false;
                }
            } else {
                $ids = $this->fallbackPersonIds($rawQ, 500);
            }
        }

        if ($q !== '' && empty($ids)) {
            return ['keyword' => $rawQ, 'total' => 0, 'limit' => $limit, 'offset' => $offset, 'rows' => []];
        }

        // 2. 朝代過濾 + 保序（保留候選相關度順序）
        $filtered = $this->applyDynastyFilter($ids, $dynasty, $q === '');
        $total = count($filtered);
        $pageIds = array_slice($filtered, $offset, $limit);
        if (empty($pageIds)) {
            return ['keyword' => $rawQ, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'rows' => []];
        }

        // 3. 補全展示欄位
        $rows = $this->hydrate($pageIds);

        // 4. 附上命中的名字類型（僅中文 FTS 路徑）
        if ($usedFts) {
            $this->attachMatchedTerms($rows, $pageIds, $q, $typeCodes);
        }

        return ['keyword' => $rawQ, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'rows' => array_values($rows)];
    }

    /**
     * 依朝代過濾候選 id 並保持原順序；$openList=true（空查詢）時直接依 id 排序取全體。
     *
     * @param  int[]  $ids
     * @return int[]
     */
    private function applyDynastyFilter(array $ids, ?int $dynasty, bool $openList): array {
        if ($openList) {
            $query = DB::table('BIOG_MAIN')->select('c_personid');
            if ($dynasty !== null) {
                $query->where('c_dy', '=', $dynasty);
            }

            return $query->orderBy('c_personid')
                ->limit(500)
                ->pluck('c_personid')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        if (empty($ids)) {
            return [];
        }

        // 始終回 BIOG_MAIN 核實存在（順帶套朝代過濾），避免 numeric 指到不存在的 id 令 total 與 rows 不一致。
        $query = DB::table('BIOG_MAIN')->whereIn('c_personid', $ids);
        if ($dynasty !== null) {
            $query->where('c_dy', '=', $dynasty);
        }
        $allowed = $query
            ->pluck('c_personid')
            ->map(fn ($id) => (int) $id)
            ->flip();

        return array_values(array_filter($ids, fn ($id) => $allowed->has($id)));
    }

    /**
     * 補全 person 展示欄位（主名／拼音／朝代／籍貫），並保持 $pageIds 順序。
     *
     * @param  int[]  $pageIds
     * @return array<int,array<string,mixed>>
     */
    private function hydrate(array $pageIds): array {
        $records = DB::table('BIOG_MAIN')
            ->select([
                'BIOG_MAIN.c_personid',
                'BIOG_MAIN.c_name_chn',
                'BIOG_MAIN.c_name',
                'BIOG_MAIN.c_dy',
                'DYNASTIES.c_dynasty_chn',
                'ADDR_CODES.c_name_chn AS index_addr_chn',
            ])
            ->leftJoin('DYNASTIES', 'DYNASTIES.c_dy', '=', 'BIOG_MAIN.c_dy')
            ->leftJoin('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id')
            ->whereIn('BIOG_MAIN.c_personid', $pageIds)
            ->get()
            ->keyBy('c_personid');

        $rows = [];
        foreach ($pageIds as $id) {
            $r = $records->get($id);
            if (!$r) {
                continue;
            }
            $rows[$id] = [
                'c_personid' => (int) $r->c_personid,
                'c_name_chn' => $r->c_name_chn,
                'c_name' => $r->c_name,
                'c_dy' => $r->c_dy !== null ? (int) $r->c_dy : null,
                'dynasty_chn' => $r->c_dynasty_chn,
                'index_addr_chn' => $r->index_addr_chn,
                'matched_terms' => [],
            ];
        }

        return $rows;
    }

    /**
     * 為每個 person 附上「命中的名字」（主名／字／號／別名…及其類型），來自 FTS。
     *
     * @param  array<int,array<string,mixed>>  $rows  by reference
     * @param  int[]  $pageIds
     * @param  int[]  $typeCodes
     */
    private function attachMatchedTerms(array &$rows, array $pageIds, string $q, array $typeCodes): void {
        $query = DB::table('CBDB__NAME_FTS')
            ->select(['c_personid', 'full_name', 'name_type_code', 'name_type_desc_chn', 'source'])
            ->whereIn('c_personid', $pageIds)
            ->where('search_term', 'LIKE', $q . '%');

        $typeCodes = array_values(array_unique(array_map('intval', $typeCodes)));
        if (!empty($typeCodes)) {
            $query->whereIn('name_type_code', $typeCodes);
        }

        $hits = $query->get();
        $seen = [];
        foreach ($hits as $h) {
            $pid = (int) $h->c_personid;
            if (!isset($rows[$pid])) {
                continue;
            }
            $key = $pid . '|' . $h->full_name . '|' . $h->name_type_code;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[$pid]['matched_terms'][] = [
                'term' => $h->full_name,
                'name_type_code' => $h->name_type_code !== null ? (int) $h->name_type_code : null,
                'name_type_desc_chn' => $h->name_type_desc_chn,
                'source' => $h->source,
            ];
        }
    }
}
