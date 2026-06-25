<?php

/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/14
 * Time: 12:46
 */

namespace App\Repositories;

use App\Models\AddrBelong;
use App\Models\AddrCode;
use App\Models\AddressCode;
use App\Models\AssocCode;
use App\Models\BiogAddrCode;
use App\Models\BiogMain;
use App\Models\Dynasty;
use App\Models\EntryCode;
use App\Models\KinshipCode;
use App\Models\OfficeCode;
use App\Models\Pinyin;
use App\Models\SocialInst;
use App\Models\SocialInstAddr;
use App\Models\SocialInstCode;
//20181112建安修改
use App\Models\TextCode;
use App\Repositories\Concerns\DetectsModelChanges;
//修改結束

//20210625建安修改
use App\Services\AuditLogService;
use App\Services\BracketNormalizer;
use App\Services\VariantCharNormalizer;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
//修改結束

//20230628建安修改
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

//修改結束

ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);

/**
 * Class BiogMainRepository
 * @package App\Repositories
 */
class BiogMainRepository {
    use DetectsModelChanges;
    private const UNKNOWN_DYNASTY_FILTER = '__unknown__';

    public function officePostingRepository(): OfficePostingRepository {
        return app(OfficePostingRepository::class);
    }

    public function relationshipRepository(): RelationshipRepository {
        return app(RelationshipRepository::class);
    }

    public function eventStatusRepository(): EventStatusRepository {
        return app(EventStatusRepository::class);
    }

    public function entryRepository(): EntryRepository {
        return app(EntryRepository::class);
    }

    public function possessionRepository(): PossessionRepository {
        return app(PossessionRepository::class);
    }

    public function socialInstitutionRepository(): SocialInstitutionRepository {
        return app(SocialInstitutionRepository::class);
    }

    public function biogSourceRepository(): BiogSourceRepository {
        return app(BiogSourceRepository::class);
    }

    /**
     * @param $id
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model|null|static|static[]
     */
    public function byPersonId($id) {
        $basicinformation = BiogMain::withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->find($id);

        if (!$basicinformation) {
            return null;
        }

        //20201207新增index year推算欄位
        $c_index_year_type_code = $basicinformation->c_index_year_type_code;
        if (!empty($c_index_year_type_code)) {
            $simplify_type_code = substr($c_index_year_type_code, 0, 2);
            $row = DB::table('INDEXYEAR_TYPE_CODES')->where([['c_index_year_type_code' , '=', $simplify_type_code]])->first();
            $ans_type_code = $c_index_year_type_code." ".$row->c_index_year_type_hz;
            $basicinformation->c_index_year_type_code = $ans_type_code;
        } else {
        }

        $c_index_year_source_id = $basicinformation->c_index_year_source_id;
        if (!empty($c_index_year_source_id)) {
            $name = $this->byPersonId($c_index_year_source_id);
            $ans_source_id = $c_index_year_source_id." ".$name->c_name_chn;
            $basicinformation->c_index_year_source_id = $ans_source_id;
        } else {
        }
        //新增結束
        //20210625新增指數地址(index_addr)與指數地址類型(index_addr_type)推算欄位
        $c_index_addr_id = $basicinformation->c_index_addr_id;
        if (!empty($c_index_addr_id)) {
            $addr_name = AddrCode::find($c_index_addr_id);
            if (!empty($addr_name)) {
                $ans_index_addr = $c_index_addr_id." ".$addr_name->c_name." ".$addr_name->c_name_chn;
                $basicinformation->c_index_addr_id = $ans_index_addr;
            }
        } else {
        }

        $c_index_addr_type_code = $basicinformation->c_index_addr_type_code;
        if (!empty($c_index_addr_type_code)) {
            $addr_type_name = BiogAddrCode::where('c_index_addr_default_rank', $c_index_addr_type_code)->first();
            if (!empty($addr_type_name)) {
                $ans_addr_type_name = $c_index_addr_type_code." ".$addr_type_name->c_addr_desc." ".$addr_type_name->c_addr_desc_chn;
                $basicinformation->c_index_addr_type_code = $ans_addr_type_name;
            }
        } else {
        }
        //新增結束

        return $basicinformation;
    }

    /**
     * @param $id
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function simpleByPersonId($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->find($id);

        return $basicinformation;
    }

    public function byIdWithAddr($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('biog_addresses')->find($id);

        return $basicinformation;
    }

    public function byIdWithAlt($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('altnames')->find($id);

        return $basicinformation;
    }

    public function byIdWithText($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('texts', 'texts_role')->find($id);

        return $basicinformation;
    }

    public function byIdWithSources($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])
            ->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')
            ->with(['sources' => function ($query) {
                $query->select('TEXT_CODES.*')->withPivot('c_pages', 'c_notes', 'c_main_source', 'c_self_bio');
            }])->find($id);

        return $basicinformation;
    }

    public function byIdWithOff($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('offices', 'offices_addr')->find($id);

        return $basicinformation;
    }

    public function byIdWithEntries($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('entries')->find($id);

        return $basicinformation;
    }

    public function byIdWithStatuses($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('statuses')->find($id);

        return $basicinformation;
    }

    public function byIdWithAssoc($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('assoc', 'assoc_name')->find($id);

        return $basicinformation;
    }

    public function byIdWithKinship($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('kinship', 'kinship_name')->find($id);

        return $basicinformation;
    }

    public function byIdWithPossession($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('possession')->find($id);

        return $basicinformation;
    }

    public function byIdWithSocialInst($id) {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('inst', 'inst_name')->find($id);

        return $basicinformation;
    }

    public function byQuery($query) {
        $params = explode(' ', $query);
        //        dump($params);
        /**
         * 这里我想到了两种方法，
         * 第一种：建索引表，跟搜索引擎一样，每个人物的有一个提取出一个关键特征向量，用二进制表示，把用户的查询条件转换成相应的特征向量，通过与或匹配
         * 第一种方法的优缺点都很明显，优点是搜索功能可以很强大，缺点是工程量比较大
         *
         * 第二种：先定义好查询的范围，再查
         *
         */
        $basicinformation = BiogMain::whereIn('c_name_chn', $params)->simplePaginate(5);
        $basicinformation->withPath(url('v1/api/biog?query='.$query));

        return $basicinformation;
    }

    /**
     * @param $request
     * @param $id
     */
    public function updateById($request, $id) {
        $data = $request->all();

        $c_name_chn = $request->c_surname_chn.$request->c_mingzi_chn;
        $c_name = trim($request->c_surname.' '.$request->c_mingzi);
        #20230626修改外文全名呈現順序
        #$c_name_proper = $request->c_surname_proper.' '.$request->c_mingzi_proper;
        #$c_name_rm = $request->c_surname_rm.' '.$request->c_mingzi_rm;
        $c_name_proper = trim($request->c_mingzi_proper.' '.$request->c_surname_proper);
        $c_name_rm = trim($request->c_mingzi_rm.' '.$request->c_surname_rm);
        $data['c_name_chn'] = $c_name_chn;
        $data['c_name'] = $c_name;
        $data['c_name_proper'] = $c_name_proper; // 自動由外文姓名組合生成
        $data['c_name_rm'] = $c_name_rm; // 自動由外文羅馬字轉寫姓名組合生成

        // 括號正規化：全角轉半角、括號前後補空格
        $data = BracketNormalizer::normalizeBiogMain($data);

        $female = $data['c_female'] ?? null;
        $data['c_female'] = ($female === null || $female === '' || $female === 'NULL')
            ? null
            : (int) $female;
        $data['c_by_intercalary'] = (int)($data['c_by_intercalary']);
        $data['c_dy_intercalary'] = (int)($data['c_dy_intercalary']);

        // FK 欄位空字串轉 null，避免表單提交時將空值寫為無效外鍵
        $data = self::nullifyEmptyForeignKeys($data);

        // 提取註解與動作，並從更新數據中移除，避免 SQL unknown column 錯誤
        $comment = $data['__proposal_comment'] ?? null;
        $data = array_diff_key($data, array_flip(['_method', '_token', '_wysihtml5_mode', 'action', '__proposal_comment', 'c_created_by', 'c_created_date']));

        $biogbasicinformation = BiogMain::find($id);
        $ori = $biogbasicinformation->toArray();

        // 檢查是否有實質變更
        $hasChanges = $this->hasMeaningfulChanges($data, $ori, ['c_modified_by', 'c_modified_date', 'c_created_by', 'c_created_date']);

        if (!$hasChanges) {
            return [
                'no_changes' => true,
            ];
        }

        $data = (new ToolsRepository())->timestamp($data);

        // 準備要存入 operations 表的數據，如果有的話加入註解
        $operationData = $data;
        if ($comment) {
            $operationData['__note'] = $comment;
        }

        //20190531判別是否為眾包用戶
        $operation = null;

        if (Auth::user()->isCrowdsourcingUser()) {
            $operation = (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_MAIN', $biogbasicinformation->c_personid, $operationData, $ori, 2);
        } else {
            DB::transaction(function () use ($data, $operationData, $id, $biogbasicinformation, $ori, &$operation) {
                $biogbasicinformation->update($data);
                $operation = (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_MAIN', $biogbasicinformation->c_personid, $operationData, $ori);

                $newData = $biogbasicinformation->fresh()->toArray();

                (new AuditLogService())->write(
                    'BIOG_MAIN',
                    'UPDATE',
                    ['c_personid' => $biogbasicinformation->c_personid],
                    $ori,
                    $newData,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
            });
        }
        //20190531修改結束

        return [
            'no_changes' => false,
            'operation_id' => isset($operation) && $operation ? $operation->id : null,
        ];
    }

    /**
     * BIOG_MAIN 中可為 NULL 的外鍵欄位：空字串轉 null。
     * 避免表單提交空值時被寫為無效外鍵（如 ''）或因 select 無空選項而帶入第一筆預設值。
     */
    public static function nullifyEmptyForeignKeys(array $data): array {
        $nullableFkFields = [
            'c_by_nh_code', 'c_by_range', 'c_by_day_gz',
            'c_dy_nh_code', 'c_dy_range', 'c_dy_day_gz',
            'c_death_age_range',
            'c_fl_ey_nh_code', 'c_fl_ly_nh_code',
            'c_ethnicity_code', 'c_choronym_code', 'c_household_status_code',
            'c_dy',
        ];

        foreach ($nullableFkFields as $field) {
            if (array_key_exists($field, $data) && ($data[$field] === '' || $data[$field] === 'null')) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    public function store(Request $request) {
        $data = $request->all();
        $data = (new ToolsRepository())->timestamp($data, true);
        $data = $this->auto_pinyin($data);
        // 括號正規化：全角轉半角、括號前後補空格
        $data = BracketNormalizer::normalizeBiogMain($data);

        return DB::transaction(function () use ($data) {
            $flight = BiogMain::create($data);
            $operation = (new OperationRepository())->store(Auth::id(), $data['c_personid'], 1, 'BIOG_MAIN', $data['c_personid'], $data);

            (new AuditLogService())->write(
                'BIOG_MAIN',
                'INSERT',
                ['c_personid' => $data['c_personid']],
                null,
                $flight->toArray(),
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return $flight;
        });
    }

    /**
     * @param $request
     * @param $num
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public static function namesByQuery(Request $request, $num = 20) {
        //20220303增加addslashes()防禦查詢參數
        $request->q = addslashes($request->q ?? '');
        if ($temp = $request->num) {
            $num = addslashes($temp);
        }
        // 朝代篩選參數
        $cDy = $request->input('c_dy');
        if (!$request->q) {
            //20211112註記，運用每次僅呈現20筆的特性，先快速提供人名資料，再查詢相關的朝代與字、號。
            //20251127修改：改為與其他查詢一致的 LeftJoin 方式，統一返回 Paginator 對象以支持 Blade 模板渲染
            //20251127性能優化：先分頁獲取 personid 列表（COUNT 快速），再 JOIN 查詢詳細信息

            // 第一步：獲取分頁的 personid 列表（COUNT 只統計 BIOG_MAIN，非常快）
            // 按 c_personid 排序（主鍵排序速度快）
            $personIdsPaginator = BiogMain::select('c_personid')
                ->orderBy('c_personid')
                ->paginate($num);

            // 如果沒有結果，直接返回空的 Paginator
            if ($personIdsPaginator->isEmpty()) {
                return $personIdsPaginator;
            }

            // 第二步：用這些 personid 去 JOIN 查詢完整資訊
            $personIds = $personIdsPaginator->pluck('c_personid')->toArray();

            $detailedItems = BiogMain::select('BIOG_MAIN.c_personid', 'BIOG_MAIN.c_name_chn', 'BIOG_MAIN.c_name', 'DYNASTIES.c_dynasty_chn', 'BIOG_MAIN.c_index_year', 'ADDR_CODES.c_name_chn AS ADDR_c_name_chn', 'A1.c_alt_name_chn as c_alt_name_chn_zi', 'A2.c_alt_name_chn as c_alt_name_chn_hao')
                ->leftJoin('DYNASTIES', 'DYNASTIES.c_dy', '=', 'BIOG_MAIN.c_dy')
                ->leftJoin('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id')
                ->leftJoin('ALTNAME_DATA as A1', function ($join) {
                    $join->on('A1.c_personid', '=', 'BIOG_MAIN.c_personid')
                         ->where('A1.c_alt_name_type_code', '=', 4);
                })
                ->leftJoin('ALTNAME_DATA as A2', function ($join) {
                    $join->on('A2.c_personid', '=', 'BIOG_MAIN.c_personid')
                         ->where('A2.c_alt_name_type_code', '=', 5);
                })
                ->whereIn('BIOG_MAIN.c_personid', $personIds)
                ->groupBy('BIOG_MAIN.c_personid')
                ->get()
                ->keyBy('c_personid');

            // 第三步：將詳細資訊按原順序填充到 Paginator 的 items 中
            // 由於第一步已按 c_personid 排序，這裡保持相同順序
            $orderedItems = collect($personIds)->map(function ($personId) use ($detailedItems) {
                return $detailedItems->get($personId);
            })->filter()->values();

            // 創建新的 Paginator 對象，保持原有的分頁信息
            $names = new \Illuminate\Pagination\LengthAwarePaginator(
                $orderedItems,
                $personIdsPaginator->total(),
                $personIdsPaginator->perPage(),
                $personIdsPaginator->currentPage(),
                ['path' => $personIdsPaginator->path()]
            );

            $names->appends(['q' => $request->q])->links();

            return $names;
        }

        // 20251109優化：當輸入為純數字時，直接按 c_personid 精確查詢，避免複雜的多條件搜尋
        if (ctype_digit($request->q)) {
            $names = BiogMain::select('BIOG_MAIN.c_personid', 'BIOG_MAIN.c_name_chn', 'BIOG_MAIN.c_name', 'DYNASTIES.c_dynasty_chn', 'BIOG_MAIN.c_index_year', 'ADDR_CODES.c_name_chn AS ADDR_c_name_chn', 'A1.c_alt_name_chn as c_alt_name_chn_zi', 'A2.c_alt_name_chn as c_alt_name_chn_hao')
                ->leftJoin('DYNASTIES', 'DYNASTIES.c_dy', '=', 'BIOG_MAIN.c_dy')
                ->leftJoin('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id')
                ->leftJoin('ALTNAME_DATA as A1', function ($join) {
                    $join->on('A1.c_personid', '=', 'BIOG_MAIN.c_personid')
                         ->where('A1.c_alt_name_type_code', '=', 4);
                })
                ->leftJoin('ALTNAME_DATA as A2', function ($join) {
                    $join->on('A2.c_personid', '=', 'BIOG_MAIN.c_personid')
                         ->where('A2.c_alt_name_type_code', '=', 5);
                })
                ->where('BIOG_MAIN.c_personid', '=', $request->q);

            self::applyDynastyFilter($names, $cDy);

            $names = $names->groupBy('BIOG_MAIN.c_personid')
                ->paginate($num);
            $names->appends(['q' => $request->q])->links();

            return $names;
        }

        // 20251115新增：使用倒排索引表進行高效姓名搜尋
        // 透過 CBDB__NAME_FTS 表實現前綴匹配，查詢效能從 1500ms 降至 3ms（500倍提升）
        $personIds = DB::table('CBDB__NAME_FTS')
            ->where('search_term', 'LIKE', $request->q . '%')
            ->orderByRaw('LENGTH(search_term) ASC')  // 優先精確匹配
            ->limit(500)  // 限制最多 500 個候選人
            ->pluck('c_personid')
            ->unique()
            ->toArray();

        // 如果倒排索引查到結果，按找到的 personIds 查詢完整資訊
        if (!empty($personIds)) {
            $query = BiogMain::select('BIOG_MAIN.c_personid', 'BIOG_MAIN.c_name_chn', 'BIOG_MAIN.c_name', 'DYNASTIES.c_dynasty_chn', 'BIOG_MAIN.c_index_year', 'ADDR_CODES.c_name_chn AS ADDR_c_name_chn', 'A1.c_alt_name_chn as c_alt_name_chn_zi', 'A2.c_alt_name_chn as c_alt_name_chn_hao')
                ->leftJoin('DYNASTIES', 'DYNASTIES.c_dy', '=', 'BIOG_MAIN.c_dy')
                ->leftJoin('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id')
                ->leftJoin('ALTNAME_DATA as A1', function ($join) {
                    $join->on('A1.c_personid', '=', 'BIOG_MAIN.c_personid')
                         ->where('A1.c_alt_name_type_code', '=', 4);
                })
                ->leftJoin('ALTNAME_DATA as A2', function ($join) {
                    $join->on('A2.c_personid', '=', 'BIOG_MAIN.c_personid')
                         ->where('A2.c_alt_name_type_code', '=', 5);
                })
                ->whereIn('BIOG_MAIN.c_personid', $personIds);

            // 朝代篩選
            if ($cDy) {
                self::applyDynastyFilter($query, $cDy);
            }

            $query->groupBy('BIOG_MAIN.c_personid');

            // 使用 FIELD() 排序保持匹配質量順序（完整匹配 → 長後綴 → 短後綴）
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                // MySQL/MariaDB：使用 FIELD() 函數
                $query->orderByRaw('FIELD(BIOG_MAIN.c_personid, ' . implode(',', $personIds) . ')');
            } else {
                // SQLite：使用 CASE WHEN 模擬 FIELD() 行為
                $caseClauses = [];
                foreach ($personIds as $index => $personId) {
                    $caseClauses[] = "WHEN BIOG_MAIN.c_personid = {$personId} THEN {$index}";
                }
                $caseStatement = 'CASE ' . implode(' ', $caseClauses) . ' ELSE 999999 END';
                $query->orderByRaw($caseStatement);
            }

            $names = $query->paginate($num);
            $names->appends(['q' => $request->q])->links();

            return $names;
        }

        // 回退方案：如果倒排索引未找到結果，使用原有的複雜搜尋（相容性保障）
        //20210827修改拼音檢索時以字為單位
        //$names = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->orWhere('c_personid', $request->q)->paginate($num);
        //20211112註記，已得到查詢條件，維持SQL LeftJoin的特性，一次性提供完整資料。
        $names = BiogMain::select('BIOG_MAIN.c_personid', 'BIOG_MAIN.c_name_chn', 'BIOG_MAIN.c_name', 'DYNASTIES.c_dynasty_chn', 'BIOG_MAIN.c_index_year', 'ADDR_CODES.c_name_chn AS ADDR_c_name_chn', 'A1.c_alt_name_chn as c_alt_name_chn_zi', 'A2.c_alt_name_chn as c_alt_name_chn_hao');
        $names = $names->leftJoin('DYNASTIES', 'DYNASTIES.c_dy', '=', 'BIOG_MAIN.c_dy'); //單筆
        $names = $names->leftJoin('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id'); //單筆
        $names = $names->leftJoin('ALTNAME_DATA as A1', function ($names) {
            $names->on('A1.c_personid', '=', 'BIOG_MAIN.c_personid')
                  ->where('A1.c_alt_name_type_code', '=', 4);
        });
        $names = $names->leftJoin('ALTNAME_DATA as A2', function ($names) {
            $names->on('A2.c_personid', '=', 'BIOG_MAIN.c_personid')
                  ->where('A2.c_alt_name_type_code', '=', 5);
        });

        $names = $names->where(function ($query) use ($request) {
            $query->where('BIOG_MAIN.c_name_chn', 'like', '%'.$request->q.'%')
                ->orWhere('BIOG_MAIN.c_name', 'like', $request->q)
                ->orWhere('BIOG_MAIN.c_surname', 'like', $request->q)
                ->orWhere('BIOG_MAIN.c_mingzi', 'like', $request->q)
                ->orWhere('BIOG_MAIN.c_personid', $request->q)
                #20230626增加[外文全名]與[外文羅馬字轉寫姓名]可查得
                ->orWhere('BIOG_MAIN.c_name_proper', 'like', $request->q)
                ->orWhere('BIOG_MAIN.c_name_rm', 'like', $request->q)
                ->orWhere('BIOG_MAIN.c_mingzi_proper', 'like', $request->q)
                ->orWhere('BIOG_MAIN.c_surname_proper', 'like', $request->q)
                ->orWhere('BIOG_MAIN.c_mingzi_rm', 'like', $request->q)
                ->orWhere('BIOG_MAIN.c_surname_rm', 'like', $request->q);
        });

        // 朝代篩選
        if ($cDy) {
            self::applyDynastyFilter($names, $cDy);
        }

        // 使用 FIELD() 排序讓姓氏完全匹配的排在前面
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            // MySQL/MariaDB：使用 FIELD() 函數
            $names = $names->orderByRaw("FIELD(BIOG_MAIN.c_surname, '$request->q') DESC");
        } else {
            // SQLite：使用 CASE WHEN 模擬姓氏優先排序
            $names = $names->orderByRaw("CASE WHEN BIOG_MAIN.c_surname = '$request->q' THEN 0 ELSE 1 END ASC");
        }

        $names = $names->orderBy('BIOG_MAIN.c_personid', 'ASC')
            ->groupBy('BIOG_MAIN.c_personid')
            ->having('BIOG_MAIN.c_personid', '>=', 0)
            ->paginate($num);
        $names->appends(['q' => $request->q])->links();

        return $names;
    }

    /**
     * 根據搜尋關鍵字統計各朝代人數（用於朝代篩選下拉選單）。
     * 複用 namesByQuery 的搜尋邏輯（倒排索引 → 回退 LIKE），但只做 GROUP BY 統計。
     *
     * @return \Illuminate\Support\Collection  每項含 c_dy, c_dynasty_chn, count
     */
    public static function dynastyFacetsByQuery(string $q): \Illuminate\Support\Collection {
        if ($q === '') {
            return collect();
        }

        $q = addslashes($q);

        // 純數字：單筆精確查詢，不需要 facet
        if (ctype_digit($q)) {
            return collect();
        }

        // 倒排索引路徑
        $personIds = DB::table('CBDB__NAME_FTS')
            ->where('search_term', 'LIKE', $q . '%')
            ->orderByRaw('LENGTH(search_term) ASC')
            ->limit(500)
            ->pluck('c_personid')
            ->unique()
            ->toArray();

        if (!empty($personIds)) {
            $validDynasties = DB::table('BIOG_MAIN')
                ->leftJoin('DYNASTIES', 'DYNASTIES.c_dy', '=', 'BIOG_MAIN.c_dy')
                ->whereIn('BIOG_MAIN.c_personid', $personIds)
                ->whereNotNull('BIOG_MAIN.c_dy')
                ->where('BIOG_MAIN.c_dy', '>', 0)
                ->select('BIOG_MAIN.c_dy', 'DYNASTIES.c_dynasty_chn', DB::raw('COUNT(*) as count'))
                ->groupBy('BIOG_MAIN.c_dy', 'DYNASTIES.c_dynasty_chn')
                ->orderByDesc('count')
                ->get();

            $unknownCount = DB::table('BIOG_MAIN')
                ->whereIn('BIOG_MAIN.c_personid', $personIds)
                ->where(function ($query) {
                    $query->whereNull('BIOG_MAIN.c_dy')
                        ->orWhere('BIOG_MAIN.c_dy', '<=', 0);
                })
                ->count();

            return self::appendUnknownDynastyFacet($validDynasties, $unknownCount);
        }

        // 回退 LIKE 路徑
        $fallbackBaseQuery = DB::table('BIOG_MAIN')
            ->leftJoin('DYNASTIES', 'DYNASTIES.c_dy', '=', 'BIOG_MAIN.c_dy')
            ->where(function ($query) use ($q) {
                $query->where('BIOG_MAIN.c_name_chn', 'like', '%' . $q . '%')
                    ->orWhere('BIOG_MAIN.c_name', 'like', $q)
                    ->orWhere('BIOG_MAIN.c_surname', 'like', $q)
                    ->orWhere('BIOG_MAIN.c_mingzi', 'like', $q)
                    ->orWhere('BIOG_MAIN.c_personid', $q)
                    ->orWhere('BIOG_MAIN.c_name_proper', 'like', $q)
                    ->orWhere('BIOG_MAIN.c_name_rm', 'like', $q)
                    ->orWhere('BIOG_MAIN.c_mingzi_proper', 'like', $q)
                    ->orWhere('BIOG_MAIN.c_surname_proper', 'like', $q)
                    ->orWhere('BIOG_MAIN.c_mingzi_rm', 'like', $q)
                    ->orWhere('BIOG_MAIN.c_surname_rm', 'like', $q);
            });

        $validDynasties = (clone $fallbackBaseQuery)
            ->whereNotNull('BIOG_MAIN.c_dy')
            ->where('BIOG_MAIN.c_dy', '>', 0)
            ->select('BIOG_MAIN.c_dy', 'DYNASTIES.c_dynasty_chn', DB::raw('COUNT(*) as count'))
            ->groupBy('BIOG_MAIN.c_dy', 'DYNASTIES.c_dynasty_chn')
            ->orderByDesc('count')
            ->get();

        $unknownCount = (clone $fallbackBaseQuery)
            ->where(function ($query) {
                $query->whereNull('BIOG_MAIN.c_dy')
                    ->orWhere('BIOG_MAIN.c_dy', '<=', 0);
            })
            ->count();

        return self::appendUnknownDynastyFacet($validDynasties, $unknownCount);
    }

    private static function applyDynastyFilter($query, $cDy): void {
        if (!$cDy) {
            return;
        }

        if ((string) $cDy === self::UNKNOWN_DYNASTY_FILTER) {
            $query->where(function ($subQuery) {
                $subQuery->whereNull('BIOG_MAIN.c_dy')
                    ->orWhere('BIOG_MAIN.c_dy', '<=', 0);
            });

            return;
        }

        $query->where('BIOG_MAIN.c_dy', $cDy);
    }

    private static function appendUnknownDynastyFacet(\Illuminate\Support\Collection $facets, int $unknownCount): \Illuminate\Support\Collection {
        if ($unknownCount <= 0) {
            return $facets;
        }

        $unknownFacet = (object) [
            'c_dy' => self::UNKNOWN_DYNASTY_FILTER,
            'c_dynasty_chn' => '未設定朝代',
            'count' => $unknownCount,
        ];

        return $facets->concat([$unknownFacet]);
    }

    /**
     * @param $id_
     * @return array
     */
    public function textById($id_) {
        $temp_l = explode("-", $id_);
        $row = DB::table('BIOG_TEXT_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_role_id', '=', $temp_l[2]],
        ])->first();
        $text = null;
        if ($row->c_textid || $row->c_textid === 0) {
            $text_ = TextCode::find($row->c_textid);
            //進行查詢資訊的擴充
            $c_bibl_cat_code = $text_->c_bibl_cat_code;
            $x1 = DB::table('TEXT_BIBLCAT_CODE_TYPE_REL')->select('c_text_cat_type_id')->where('c_text_cat_code', $c_bibl_cat_code)->get();
            $ans1 = [];
            foreach ($x1 as $object) {
                $ans1[0] = $object->c_text_cat_type_id;
            }
            //20201106這裡增加判斷式，因為TEXT_BIBLCAT_CODE_TYPE_REL的c_text_cat_code沒有完整對應TEXT_CODES資料表的c_bibl_cat_code。
            if (!empty($ans1[0])) {
                for ($j = 0; $j <= 3; $j++) {
                    $x[$j] = $this->searchTextSub($ans1[$j]);
                    foreach ($x[$j] as $object) {
                        $ans1[$j + 1] = $object->c_text_cat_type_parent_id;
                        $ans2[$j + 1] = $object->c_text_cat_type_desc_chn;
                    }
                }
                $word = $ans2[1]."/".$ans2[2]."/".$ans2[3];
            } else {
                $word = '';
            }
            $text = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn." ".$word;
            //$text = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }
        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }

        return ['row' => $row, 'text' => $text, 'text_str' => $text_str];
    }

    public function searchTextSub($request) {
        $data = DB::table('TEXT_BIBLCAT_TYPES')->select('c_text_cat_type_parent_id', 'c_text_cat_type_desc_chn')->where('c_text_cat_type_id', $request)->get();

        return $data;
    }

    public function textUpdateById(Request $request, $id, $id_) {
        $temp_l = explode("-", $id_);
        $data = $request->all();
        $comment = $data['__proposal_comment'] ?? null;
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment', 'c_personid']);
        $data = (new ToolsRepository())->timestamp($data);

        return DB::transaction(function () use ($id, $temp_l, $data, $comment) {
            $ori = DB::table('BIOG_TEXT_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_textid', '=', $temp_l[1]],
                ['c_role_id', '=', $temp_l[2]],
            ])->lockForUpdate()->first();

            if (!$ori) {
                return null;
            }

            // 若主鍵欄位有變動，檢查新主鍵是否與既有記錄衝突
            $newTextid = $data['c_textid'] ?? $ori->c_textid;
            $newRoleId = $data['c_role_id'] ?? $ori->c_role_id;
            $pkChanged = (string) $newTextid !== (string) $ori->c_textid
                      || (string) $newRoleId !== (string) $ori->c_role_id;

            if ($pkChanged) {
                $conflict = DB::table('BIOG_TEXT_DATA')->where([
                    ['c_personid', '=', $temp_l[0]],
                    ['c_textid', '=', $newTextid],
                    ['c_role_id', '=', $newRoleId],
                ])->exists();

                if ($conflict) {
                    throw new \InvalidArgumentException(
                        '目標著述代碼與著述角色的組合已存在，請使用不同的值。'
                    );
                }
            }

            DB::table('BIOG_TEXT_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_textid', '=', $temp_l[1]],
                ['c_role_id', '=', $temp_l[2]],
            ])->update($data);

            $newPk = [
                'c_personid' => $id,
                'c_textid' => $data['c_textid'] ?? $ori->c_textid,
                'c_role_id' => $data['c_role_id'] ?? $ori->c_role_id,
            ];

            $operationData = $data;
            if ($comment) {
                $operationData['__note'] = $comment;
            }

            $operation = (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_TEXT_DATA', CompositePrimaryKey::buildStoredResourceId($newPk), $operationData, $ori);

            (new AuditLogService())->write(
                'BIOG_TEXT_DATA',
                'UPDATE',
                $newPk,
                (new AuditLogService())->normalizeRow($ori),
                array_merge((new AuditLogService())->normalizeRow($ori), $data),
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return $newPk;
        });
    }

    public function textStoreById(Request $request, $id) {
        $data = $request->all();
        $data = Arr::except($data, ['_token', 'action', '__proposal_comment']);
        $data['c_personid'] = $id;
        $duplicate = DB::table('BIOG_TEXT_DATA')->where([
            ['c_personid', '=', $data['c_personid']],
            ['c_textid', '=', $data['c_textid']],
            ['c_role_id', '=', $data['c_role_id']],
        ])->first();
        if (!blank($duplicate)) {
            return false;
        }
        $data = (new ToolsRepository())->timestamp($data, true);

        return DB::transaction(function () use ($id, $data) {
            DB::table('BIOG_TEXT_DATA')->insert($data);
            $operation = (new OperationRepository())->store(Auth::id(), $id, 1, 'BIOG_TEXT_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $data['c_personid'],
                'c_textid' => $data['c_textid'],
                'c_role_id' => $data['c_role_id'],
            ]), $data);

            (new AuditLogService())->write(
                'BIOG_TEXT_DATA',
                'INSERT',
                [
                    'c_personid' => $data['c_personid'],
                    'c_textid' => $data['c_textid'],
                    'c_role_id' => $data['c_role_id'],
                ],
                null,
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return $data;
        });
    }

    public function textDeleteById($id_, $c_personid) {
        $temp_l = explode("-", $id_);

        return DB::transaction(function () use ($c_personid, $temp_l) {
            $row = DB::table('BIOG_TEXT_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_textid', '=', $temp_l[1]],
                ['c_role_id', '=', $temp_l[2]],
            ])->lockForUpdate()->first();

            if (!$row) {
                return false;
            }

            DB::table('BIOG_TEXT_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_textid', '=', $temp_l[1]],
                ['c_role_id', '=', $temp_l[2]],
            ])->delete();

            $pk = [
                'c_personid' => $row->c_personid,
                'c_textid' => $row->c_textid,
                'c_role_id' => $row->c_role_id,
            ];

            $operation = (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'BIOG_TEXT_DATA', CompositePrimaryKey::buildStoredResourceId($pk), $row);

            (new AuditLogService())->write(
                'BIOG_TEXT_DATA',
                'DELETE',
                $pk,
                (new AuditLogService())->normalizeRow($row),
                null,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return true;
        });
    }

    public function entryStoreById(Request $request, $id) {
        $data = $request->all();
        $comment = $data['__proposal_comment'] ?? null;
        $data = Arr::except($data, ['_token', 'action', '__proposal_comment']);
        $data['c_personid'] = $id;
        $duplicate = DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $data['c_personid']],
            ['c_entry_code', '=', $data['c_entry_code']],
            ['c_sequence', '=', $data['c_sequence']],
            ['c_kin_code', '=', $data['c_kin_code']],
            ['c_assoc_code', '=', $data['c_assoc_code']],
            ['c_kin_id', '=', $data['c_kin_id']],
            ['c_year', '=', $data['c_year']],
            ['c_assoc_id', '=', $data['c_assoc_id']],
            ['c_inst_code', '=', $data['c_inst_code']],
            ['c_inst_name_code', '=', $data['c_inst_name_code']],
        ])->first();
        if (!blank($duplicate)) {
            return false;
        }
        $data = (new ToolsRepository())->timestamp($data, true);

        return DB::transaction(function () use ($id, $data, $comment) {
            DB::table('ENTRY_DATA')->insert($data);

            $operationData = $data;
            if ($comment) {
                $operationData['__note'] = $comment;
            }

            $pk = [
                'c_personid' => $data['c_personid'],
                'c_entry_code' => $data['c_entry_code'],
                'c_sequence' => $data['c_sequence'],
                'c_kin_code' => $data['c_kin_code'],
                'c_assoc_code' => $data['c_assoc_code'],
                'c_kin_id' => $data['c_kin_id'],
                'c_year' => $data['c_year'],
                'c_assoc_id' => $data['c_assoc_id'],
                'c_inst_code' => $data['c_inst_code'],
                'c_inst_name_code' => $data['c_inst_name_code'],
            ];

            $operation = (new OperationRepository())->store(Auth::id(), $id, 1, 'ENTRY_DATA', CompositePrimaryKey::buildStoredResourceId($pk), $operationData);

            (new AuditLogService())->write(
                'ENTRY_DATA',
                'INSERT',
                $pk,
                null,
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return $data;
        });
    }

    public function entryUpdateById(Request $request, $id, $id_) {
        $id = str_replace("--", "-minus", $id_);
        $addr_a = explode("-", $id);
        foreach ($addr_a as $key => $value) {
            $addr_a[$key] = str_replace("minus", "-", $value);
        }

        $data = $request->all();
        $comment = $data['__proposal_comment'] ?? null;
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment']);
        $data = (new ToolsRepository())->timestamp($data);

        return DB::transaction(function () use ($id, $addr_a, $data, $comment) {
            $ori = DB::table('ENTRY_DATA')->where([
                ['c_personid', '=', $addr_a[0]],
                ['c_entry_code', '=', $addr_a[1]],
                ['c_sequence', '=', $addr_a[2]],
                ['c_kin_code', '=', $addr_a[3]],
                ['c_assoc_code', '=', $addr_a[4]],
                ['c_kin_id', '=', $addr_a[5]],
                ['c_year', '=', $addr_a[6]],
                ['c_assoc_id', '=', $addr_a[7]],
                ['c_inst_code', '=', $addr_a[8]],
                ['c_inst_name_code', '=', $addr_a[9]],
            ])->lockForUpdate()->first();

            if (!$ori) {
                return null;
            }

            DB::table('ENTRY_DATA')->where([
                ['c_personid', '=', $addr_a[0]],
                ['c_entry_code', '=', $addr_a[1]],
                ['c_sequence', '=', $addr_a[2]],
                ['c_kin_code', '=', $addr_a[3]],
                ['c_assoc_code', '=', $addr_a[4]],
                ['c_kin_id', '=', $addr_a[5]],
                ['c_year', '=', $addr_a[6]],
                ['c_assoc_id', '=', $addr_a[7]],
                ['c_inst_code', '=', $addr_a[8]],
                ['c_inst_name_code', '=', $addr_a[9]],
            ])->update($data);

            $newPk = [
                'c_personid' => $addr_a[0],
                'c_entry_code' => $data['c_entry_code'] ?? $ori->c_entry_code,
                'c_sequence' => $data['c_sequence'] ?? $ori->c_sequence,
                'c_kin_code' => $data['c_kin_code'] ?? $ori->c_kin_code,
                'c_assoc_code' => $data['c_assoc_code'] ?? $ori->c_assoc_code,
                'c_kin_id' => $data['c_kin_id'] ?? $ori->c_kin_id,
                'c_year' => $data['c_year'] ?? $ori->c_year,
                'c_assoc_id' => $data['c_assoc_id'] ?? $ori->c_assoc_id,
                'c_inst_code' => $data['c_inst_code'] ?? $ori->c_inst_code,
                'c_inst_name_code' => $data['c_inst_name_code'] ?? $ori->c_inst_name_code,
            ];

            $operationData = $data;
            if ($comment) {
                $operationData['__note'] = $comment;
            }

            $operation = (new OperationRepository())->store(Auth::id(), $addr_a[0], 3, 'ENTRY_DATA', CompositePrimaryKey::buildStoredResourceId($newPk), $operationData, $ori);

            (new AuditLogService())->write(
                'ENTRY_DATA',
                'UPDATE',
                $newPk,
                (new AuditLogService())->normalizeRow($ori),
                array_merge((new AuditLogService())->normalizeRow($ori), $data),
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return $newPk;
        });
    }

    public function entryDeleteById($id_, $c_personid) {
        $id = str_replace("--", "-minus", $id_);
        $addr_a = explode("-", $id);
        foreach ($addr_a as $key => $value) {
            $addr_a[$key] = str_replace("minus", "-", $value);
        }

        return DB::transaction(function () use ($c_personid, $addr_a) {
            $row = DB::table('ENTRY_DATA')->where([
                ['c_personid', '=', $addr_a[0]],
                ['c_entry_code', '=', $addr_a[1]],
                ['c_sequence', '=', $addr_a[2]],
                ['c_kin_code', '=', $addr_a[3]],
                ['c_assoc_code', '=', $addr_a[4]],
                ['c_kin_id', '=', $addr_a[5]],
                ['c_year', '=', $addr_a[6]],
                ['c_assoc_id', '=', $addr_a[7]],
                ['c_inst_code', '=', $addr_a[8]],
                ['c_inst_name_code', '=', $addr_a[9]],
            ])->lockForUpdate()->first();

            if (!$row) {
                return false;
            }

            DB::table('ENTRY_DATA')->where([
                ['c_personid', '=', $addr_a[0]],
                ['c_entry_code', '=', $addr_a[1]],
                ['c_sequence', '=', $addr_a[2]],
                ['c_kin_code', '=', $addr_a[3]],
                ['c_assoc_code', '=', $addr_a[4]],
                ['c_kin_id', '=', $addr_a[5]],
                ['c_year', '=', $addr_a[6]],
                ['c_assoc_id', '=', $addr_a[7]],
                ['c_inst_code', '=', $addr_a[8]],
                ['c_inst_name_code', '=', $addr_a[9]],
            ])->delete();

            $pk = [
                'c_personid' => $row->c_personid,
                'c_entry_code' => $row->c_entry_code,
                'c_sequence' => $row->c_sequence,
                'c_kin_code' => $row->c_kin_code,
                'c_assoc_code' => $row->c_assoc_code,
                'c_kin_id' => $row->c_kin_id,
                'c_year' => $row->c_year,
                'c_assoc_id' => $row->c_assoc_id,
                'c_inst_code' => $row->c_inst_code,
                'c_inst_name_code' => $row->c_inst_name_code,
            ];

            $operation = (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'ENTRY_DATA', CompositePrimaryKey::buildStoredResourceId($pk), $row);

            (new AuditLogService())->write(
                'ENTRY_DATA',
                'DELETE',
                $pk,
                (new AuditLogService())->normalizeRow($row),
                null,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return true;
        });
    }

    public function officeById($id) {
        $temp_l = explode("-", $id);
        $row = DB::table('POSTED_TO_OFFICE_DATA')->where([
            ['c_office_id', '=', $temp_l[0]],
            ['c_posting_id', '=', $temp_l[1]],
        ])->first();
        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }
        $office_str = null;
        if ($row->c_office_id || $row->c_office_id === 0) {
            $text_ = OfficeCode::find($row->c_office_id);
            $dy = Dynasty::where('c_dy', $text_->c_dy)->first()->c_dynasty_chn;
            $office_str = $text_->c_office_id." ".$text_->c_office_pinyin." ".$text_->c_office_chn." ".$dy;
            if (!empty($text_->c_office_trans)) {
                $office_str .= " ".e($text_->c_office_trans);
            }
        }
        $posting_str = null;
        //        dd($row->c_inst_name_code);
        if ($row->c_inst_code || $row->c_inst_code === 0) {
            //20211020進行改寫
            //$text_ = SocialInst::find($row->c_inst_code);
            //$posting_str = $text_->c_inst_name_code." ".$text_->c_inst_name_py." ".$text_->c_inst_name_hz;
            $text_ = SocialInstCode::where([
                ['c_inst_code', '=', $row->c_inst_code],
                ['c_inst_name_code', '=', $row->c_inst_name_code],
            ])->first();
            $name_hz = SocialInst::where('c_inst_name_code', $text_->c_inst_name_code)->first()->c_inst_name_hz;
            $name_py = SocialInst::where('c_inst_name_code', $text_->c_inst_name_code)->first()->c_inst_name_py;
            $res = SocialInstAddr::where('c_inst_code', $text_->c_inst_code)->first();
            if (count((array)$res) == 0) {
                $addr = "未詳";
            } else {
                $addr = AddressCode::where('c_addr_id', $res->c_inst_addr_id)->first()->c_name_chn;
            }
            $dy = $text_->c_inst_begin_year;
            $dy2 = $text_->c_inst_floruit_dy;
            $dy3 = $text_->c_inst_end_year;
            $dy4 = $text_->c_inst_last_known_year;
            if ($name_hz == null) {
                $name_hz = "未詳";
            }
            if ($name_py == null) {
                $name_py = "未詳";
            }
            if ($addr == null) {
                $addr = "未詳";
            }
            if ($dy == null) {
                $dy = "未詳";
            }
            if ($dy2 == null) {
                $dy2 = "未詳";
            }
            if ($dy3 == null) {
                $dy3 = "未詳";
            }
            if ($dy4 == null) {
                $dy4 = "未詳";
            }
            $posting_str = $text_->c_inst_code." (社交機構代碼)-".$name_hz." ".$name_py."(社交機構名稱)-".$text_->c_inst_name_code."(社交機構名稱代碼)-".$addr."(地址)-".$dy."(起年)-".$dy2."(最早見諸文獻年)-".$dy3."(訖年)-".$dy4."(最晚見諸文獻年)";
            //修改結束
        }
        //        dd($posting_str);
        $addr_ = DB::table('POSTED_TO_ADDR_DATA')->where('c_personid', $row->c_personid)->where('c_posting_id', $row->c_posting_id)->get();
        $addr_str = [];
        foreach ($addr_ as $key => $value) {
            $id_ = $value->c_addr_id == 0 ? -999 : $value->c_addr_id;
            $item = [$id_, $this->addr_str($value->c_addr_id)];
            $addr_str[$key] = $item;
        }

        return ['row' => $row, 'text_str' => $text_str, 'office_str' => $office_str, 'posting_str' => $posting_str, 'addr_str' => $addr_str];
    }

    public function officeUpdateById(Request $request, $id, $c_personid) {
        return $this->officePostingRepository()->officeUpdateById($request, $id, $c_personid);
    }

    public function officeStoreById(Request $request, $id) {
        return $this->officePostingRepository()->officeStoreById($request, $id);
    }

    public function officeCloneById($id, $cpk) {
        return $this->officePostingRepository()->officeCloneById($id, $cpk);
    }

    public function officeDeleteById($id, $c_personid) {
        $this->officePostingRepository()->officeDeleteById($id, $c_personid);
    }

    public function entryById($id) {
        $id = str_replace("--", "-minus", $id);
        $addr_a = explode("-", $id);
        foreach ($addr_a as $key => $value) {
            $addr_a[$key] = str_replace("minus", "-", $value);
        }
        $row = DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $addr_a[0]],
            ['c_entry_code', '=', $addr_a[1]],
            ['c_sequence', '=', $addr_a[2]],
            ['c_kin_code', '=', $addr_a[3]],
            ['c_assoc_code', '=', $addr_a[4]],
            ['c_kin_id', '=', $addr_a[5]],
            ['c_year', '=', $addr_a[6]],
            ['c_assoc_id', '=', $addr_a[7]],
            ['c_inst_code', '=', $addr_a[8]],
            ['c_inst_name_code', '=', $addr_a[9]],
        ])->first();

        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        $entry_str = null;
        if ($row->c_entry_code || $row->c_entry_code === 0) {
            $text_ = EntryCode::find($row->c_entry_code);
            $entry_str = $text_->c_entry_code." ".$text_->c_entry_desc_chn." ".$text_->c_entry_desc;
        }
        $addr_str = null;
        if ($row->c_entry_addr_id || $row->c_entry_addr_id === 0) {
            $text_ = AddressCode::find($row->c_entry_addr_id);
            $addr_str = $text_->c_addr_id." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $kin_str = null;
        if ($row->c_kin_code || $row->c_kin_code === 0) {
            $text_ = KinshipCode::find($row->c_kin_code);
            $kin_str = $text_->c_kin_code." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
        }
        //20181112建安修改
        $biog_str = null;
        if ($row->c_kin_id || $row->c_kin_id === 0) {
            $text_ = BiogMain::find($row->c_kin_id);
            $biog_str = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $biog_str2 = null;
        if ($row->c_assoc_id || $row->c_assoc_id === 0) {
            $text_ = BiogMain::find($row->c_assoc_id);
            $biog_str2 = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        //修改結束
        $assoc_str = null;
        if ($row->c_assoc_code || $row->c_assoc_code === 0) {
            $text_ = AssocCode::find($row->c_assoc_code);
            $assoc_str = $text_->c_assoc_code." ".$text_->c_assoc_desc_chn." ".$text_->c_assoc_desc;
        }
        //20210804建安新增社交機構輸出文字的程式碼
        $inst_code = null;
        if ($row->c_inst_code || $row->c_inst_code === 0) {
            $text_ = SocialInstCode::where([
                ['c_inst_code', '=', $row->c_inst_code],
                ['c_inst_name_code', '=', $row->c_inst_name_code],
            ])->first();
            $name_hz = SocialInst::where('c_inst_name_code', $text_->c_inst_name_code)->first()->c_inst_name_hz;
            $name_py = SocialInst::where('c_inst_name_code', $text_->c_inst_name_code)->first()->c_inst_name_py;
            $res = SocialInstAddr::where('c_inst_code', $text_->c_inst_code)->first();
            if (count((array)$res) == 0) {
                $addr = "未詳";
            } else {
                $addr = AddressCode::where('c_addr_id', $res->c_inst_addr_id)->first()->c_name_chn;
            }
            $dy = $text_->c_inst_begin_year;
            $dy2 = $text_->c_inst_floruit_dy;
            $dy3 = $text_->c_inst_end_year;
            $dy4 = $text_->c_inst_last_known_year;
            if ($name_hz == null) {
                $name_hz = "未詳";
            }
            if ($name_py == null) {
                $name_py = "未詳";
            }
            if ($addr == null) {
                $addr = "未詳";
            }
            if ($dy == null) {
                $dy = "未詳";
            }
            if ($dy2 == null) {
                $dy2 = "未詳";
            }
            if ($dy3 == null) {
                $dy3 = "未詳";
            }
            if ($dy4 == null) {
                $dy4 = "未詳";
            }
            $inst_code = $text_->c_inst_code." (社交機構代碼)-".$name_hz." ".$name_py."(社交機構名稱)-".$text_->c_inst_name_code."(社交機構名稱代碼)-".$addr."(地址)-".$dy."(起年)-".$dy2."(最早見諸文獻年)-".$dy3."(訖年)-".$dy4."(最晚見諸文獻年)";
        }
        //新增結束

        return ['row' => $row, 'text_str' => $text_str, 'entry_str' => $entry_str, 'addr_str' => $addr_str, 'kin_str' => $kin_str, 'assoc_str' => $assoc_str, 'biog_str' => $biog_str, 'biog_str2' => $biog_str2, 'inst_code' => $inst_code];
    }

    public function statuseById($id) {
        return $this->eventStatusRepository()->statuseById($id);
    }

    public function statuseUpdateById(Request $request, $id, $c_personid) {
        return $this->eventStatusRepository()->statuseUpdateById($request, $id, $c_personid);
    }

    public function statuseStoreById(Request $request, $id) {
        return $this->eventStatusRepository()->statuseStoreById($request, $id);
    }

    public function statuseDeleteById($id, $c_personid) {
        return $this->eventStatusRepository()->statuseDeleteById($id, $c_personid);
    }

    public function kinshipById($id) {
        $id = str_replace("--", "-minus", $id);
        $temp_l = explode("-", $id);
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus", "-", $value);
        }

        $row = DB::table('KIN_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_kin_id', '=', $temp_l[1]],
            ['c_kin_code', '=', $temp_l[2]],
        ])->first();
        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        $kin_str = null;
        if ($row->c_kin_code || $row->c_kin_code === 0) {
            $text_ = KinshipCode::find($row->c_kin_code);
            //20201026修改，提供給前端c_kincode可以更新親屬關係。
            //$kin_str = $text_->c_status_code." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
            $kin_str = $text_->c_kincode." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
        }
        $biog_str = null;
        $kinpair_str = null;
        if ($row->c_kin_id || $row->c_kin_id === 0) {
            $text_ = BiogMain::find($row->c_kin_id);
            $biog_str = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
            $k_p_code = DB::table('KIN_DATA')->where([['c_kin_id',$row->c_personid], ['c_personid', $row->c_kin_id]])->first()->c_kin_code;
            //$k_p_code = $row->c_kin_code;
            $text_ = KinshipCode::find($k_p_code);
            //20201026修改，提供給前端c_kincode可以更新親屬關係。
            //$kinpair_str = $text_->c_status_code." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
            $kinpair_str = $text_->c_kincode." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
        }

        //        dd($biog_str);
        return ['row' => $row, 'text_str' => $text_str, 'kin_str' => $kin_str, 'biog_str' => $biog_str, 'kinpair_str' => $kinpair_str, 'k_p_code' => $k_p_code];
    }

    public function kinshipUpdateById(Request $request, $id, $id_, bool $detectConflict = false) {
        $auditLog = new AuditLogService();
        $id_ = str_replace("--", "-minus", $id_);
        $temp_l = explode("-", $id_);
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus", "-", $value);
        }

        $row = DB::table('KIN_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_kin_id', '=', $temp_l[1]],
            ['c_kin_code', '=', $temp_l[2]],
        ])->first();
        if (!$row) {
            return ['err' => 0];
        }

        $data = $request->all();
        $kin_pair = $data['c_kinship_pair'];
        $kin_id = $data['c_kin_id'];
        $c_autogen_notes = $row->c_autogen_notes;
        $old_kin_id = $row->c_kin_id;
        $old_kin_code = $row->c_kin_code;
        $data = Arr::except($data, ['_token', '_method', 'action', '__proposal_comment', 'c_kinship_pair', 'c_created_by', 'c_created_date', 'ai_fill_log_id']);
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_kin_id'] = $data['c_kin_id'] == -999 ? '0' : $data['c_kin_id'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data);

        $ori_data = $data;
        $sumCount = 0;

        DB::transaction(function () use (&$ori_data, &$sumCount, $id, $id_, $temp_l, $row, $data, $kin_pair, $kin_id, $c_autogen_notes, $old_kin_id, $old_kin_code, $auditLog, $detectConflict) {
            DB::table('KIN_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_kin_id', '=', $temp_l[1]],
                ['c_kin_code', '=', $temp_l[2]],
            ])->update($data);

            $operation = (new OperationRepository())->store(Auth::id(), $id, 3, 'KIN_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $id,
                'c_kin_id' => $data['c_kin_id'],
                'c_kin_code' => $data['c_kin_code'],
            ]), $data, $row);
            $operationId = $operation ? (string) $operation->id : null;

            $auditLog->write(
                'KIN_DATA',
                'UPDATE',
                [
                    'c_personid' => $data['c_personid'],
                    'c_kin_id' => $data['c_kin_id'],
                    'c_kin_code' => $data['c_kin_code'],
                ],
                $auditLog->normalizeRow($row),
                $data,
                'user',
                (string) Auth::id(),
                $operationId
            );

            $data_mirror = $data;
            $data_mirror['c_kin_code'] = $kin_pair;
            $data_mirror['c_personid'] = $kin_id;
            $data_mirror = Arr::except($data_mirror, ['c_kin_id']);

            #20240710修正對應親屬的查詢方式，依據KINSHIP_CODES的c_kin_pair1和c_kin_pair2查詢
            // 反向鏡像同步抽出為共用方法（legacy 與 v2 KinshipMutationHandler 共用）；
            // 舊碼配對查找與缺碼 fail-closed 一併移入該方法（保留 legacy 原子失敗語意）。
            $sumCount = $this->syncKinMirrorOnUpdate(
                $data_mirror,
                (int) $id,
                $old_kin_id,
                $c_autogen_notes,
                $old_kin_code,
                $operation,
                $auditLog,
                // kin 維持 allowBackfill=false：syncKinMirrorOnUpdate 的 #70 疑似偵測**不受 allowBackfill 閘控**（與 assoc
                // 不同，無 early-return），故 detectConflict=true 下對面碼漂移仍會拋 MirrorSuspectedException 中止核准；
                // 對面無鏡像時 sumCount=0 → applyKinshipProposal 既有 guard 拋「更新失敗」中止（亦 fail-safe）。
                // 不可改 true：backfill 雖補列但 sumCount 仍 0 → guard 仍拋 → 反把 backfill 回滾（測試實證）。
                false,            // allowBackfill
                $detectConflict,  // detectConflict
                $detectConflict ? $this->buildApprovalMirrorBaselines('KIN_DATA', $row) : []
            );
        });

        $ori_data['err'] = $sumCount;

        return $ori_data;
    }

    public function kinshipStoreById(Request $request, $id, bool $detectConflict = false) {
        $auditLog = new AuditLogService();
        $data = $request->all();
        $kin_pair = $data['c_kinship_pair'];
        $data = Arr::except($data, ['_token', 'action', '__proposal_comment', 'c_kinship_pair']);
        $data['c_personid'] = $id;
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_kin_id'] = $data['c_kin_id'] == -999 ? '0' : $data['c_kin_id'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data, true);

        $ori_Data = $data;

        DB::transaction(function () use ($id, $data, $kin_pair, $auditLog, $detectConflict, &$ori_Data) {
            DB::table('KIN_DATA')->insert($data);
            $operation = (new OperationRepository())->store(Auth::id(), $id, 1, 'KIN_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $data['c_personid'],
                'c_kin_id' => $data['c_kin_id'],
                'c_kin_code' => $data['c_kin_code'],
            ]), $data);
            $operationId = $operation ? (string) $operation->id : null;
            $auditLog->write(
                'KIN_DATA',
                'INSERT',
                [
                    'c_personid' => $data['c_personid'],
                    'c_kin_id' => $data['c_kin_id'],
                    'c_kin_code' => $data['c_kin_code'],
                ],
                null,
                $data,
                'user',
                (string) Auth::id(),
                $operationId
            );

            // #82：提案核准（$detectConflict=true）且反向碼非哨兵 0 時，反向鏡像改走已 gate 的 syncKinMirrorOnUpdate
            // （含 #66 衝突 + #72 疑似偵測 + allowBackfill），與 v2 direct create 行為一致——對面分歧/碼漂移→拋例外
            // 中止核准（approve() 友善 flash + 整筆回滾），不再盲插重複/衝突鏡像。legacy direct（false）維持原盲插 parity。
            if ($detectConflict && (int) $kin_pair !== 0) {
                $dataMirror = $data;
                unset($dataMirror['c_kin_id']);
                $dataMirror['c_kin_code'] = $kin_pair;
                $dataMirror['c_personid'] = $data['c_kin_id'];
                $this->syncKinMirrorOnUpdate(
                    $dataMirror,
                    (int) $id,
                    (int) $data['c_kin_id'],
                    $data['c_autogen_notes'] ?? null,
                    $data['c_kin_code'],
                    $operation,
                    $auditLog,
                    true,   // allowBackfill：對面無對應列即補建
                    true,   // detectConflict：偵測對面衝突/疑似
                    $this->createKinMirrorBaselines($dataMirror, $data['c_kin_code'])
                );
            } else {
                $data_mirror = $data;
                $data_mirror['c_kin_code'] = $kin_pair;
                $data_mirror['c_personid'] = $data['c_kin_id'];
                $data_mirror['c_kin_id'] = $id;
                DB::table('KIN_DATA')->insert($data_mirror);
                $auditLog->write(
                    'KIN_DATA',
                    'INSERT',
                    [
                        'c_personid' => $data_mirror['c_personid'],
                        'c_kin_id' => $data_mirror['c_kin_id'],
                        'c_kin_code' => $data_mirror['c_kin_code'],
                    ],
                    null,
                    $data_mirror,
                    'user',
                    (string) Auth::id(),
                    $operationId
                );
            }
        });

        return $ori_Data;
    }

    public function kinshipDeleteById($id, $id_) {
        $auditLog = new AuditLogService();
        $id = str_replace("--", "-minus", $id);
        $temp_l = explode("-", $id);
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus", "-", $value);
        }

        $row = DB::table('KIN_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_kin_id', '=', $temp_l[1]],
            ['c_kin_code', '=', $temp_l[2]],
        ])->first();

        if (!$row) {
            return;
        }

        DB::transaction(function () use ($id, $temp_l, $row, $auditLog) {
            $operation = (new OperationRepository())->store(Auth::id(), $id, 4, 'KIN_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $temp_l[0],
                'c_kin_id' => $temp_l[1],
                'c_kin_code' => $temp_l[2],
            ]), $row);
            $operationId = $operation ? (string) $operation->id : null;
            $auditLog->write(
                'KIN_DATA',
                'DELETE',
                [
                    'c_personid' => $temp_l[0],
                    'c_kin_id' => $temp_l[1],
                    'c_kin_code' => $temp_l[2],
                ],
                $auditLog->normalizeRow($row),
                null,
                'user',
                (string) Auth::id(),
                $operationId
            );

            DB::table('KIN_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_kin_id', '=', $temp_l[1]],
                ['c_kin_code', '=', $temp_l[2]],
            ])->delete();

            // 反向鏡像刪除抽出為共用方法（legacy 與 v2 KinshipDeleteHandler 共用）。
            // legacy 路徑無互動確認，沿用「刪除全部對應反向列」語義（$force=true）：取得 #81 §6 廣集定位的孤兒修正，
            // 但不啟用多筆確認閘（避免在非互動的 legacy 刪除拋 MirrorDeleteMultipleException 中斷）。
            $this->syncKinMirrorOnDelete((array) $row, $operation, $auditLog, true);
        });
    }

    public function possessionById($id) {
        $row = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id)->first();
        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }

        $addr_ = DB::table('POSSESSION_ADDR')->where('c_possession_record_id', $id)->get();
        //        dd($addr_);
        $addr_str = [];
        foreach ($addr_ as $key => $value) {
            $id_ = $value->c_addr_id == 0 ? -999 : $value->c_addr_id;
            $item = [$id_, $this->addr_str($value->c_addr_id)];
            $addr_str[$key] = $item;
        }

        return ['row' => $row, 'text_str' => $text_str, 'addr_str' => $addr_str];
    }

    public function possessionUpdateById(Request $request, $id, $id_) {
        $data = $request->all();
        // 僅當 c_addr_id 為陣列才同步 POSSESSION_ADDR（legacy 表單與帶 aux 的 proposal 會送）；
        // 未送（proposal 未帶地址 aux）時為 null → 不動既有地址，避免 TypeError 與誤刪副表。
        $c_addr_id = $data['c_addr_id'] ?? null;
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment', 'c_addr_id']);
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data);
        $ori = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id_)->first();
        if (!$ori) {
            return;
        }

        DB::transaction(function () use ($id, $id_, $data, $c_addr_id, $ori) {
            if (is_array($c_addr_id)) {
                $this->insertAddrPo($c_addr_id, $id_, $id);
            }
            DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id_)->update($data);
            $operation = (new OperationRepository())->store(Auth::id(), $id, 3, 'POSSESSION_DATA', $id_, $data, $ori);
            (new AuditLogService())->write(
                'POSSESSION_DATA',
                'UPDATE',
                ['c_possession_record_id' => $id_],
                (new AuditLogService())->normalizeRow($ori),
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });
    }

    public function possessionStoreById(Request $request, $id) {
        $data = $request->all();
        $data['c_personid'] = $id;
        $addr = $data['c_addr_id'];
        $data = Arr::except($data, ['_token', 'action', '__proposal_comment', 'c_addr_id']);
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data, true);

        $newId = null;
        DB::transaction(function () use ($id, $data, $addr, &$newId) {
            // surrogate id 必須在交易內以 lockForUpdate 配發，避免併發 create 取得相同 id（與 officeStoreById 一致）
            $lastId = DB::table('POSSESSION_DATA')
                ->lockForUpdate()
                ->orderByDesc('c_possession_record_id')
                ->value('c_possession_record_id');
            $newId = ((int) $lastId) + 1;
            $data['c_possession_record_id'] = $newId;

            DB::table('POSSESSION_DATA')->insert($data);
            $this->insertAddrPo($addr, $newId, $data['c_personid']);
            $operation = (new OperationRepository())->store(Auth::id(), $id, 1, 'POSSESSION_DATA', $newId, $data);
            (new AuditLogService())->write(
                'POSSESSION_DATA',
                'INSERT',
                ['c_possession_record_id' => $newId],
                null,
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });

        return $newId;
    }

    public function possessionDeleteById($id, $c_personid) {
        $row = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id)->first();
        if (!$row) {
            return;
        }

        DB::transaction(function () use ($id, $c_personid, $row) {
            DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id)->delete();
            DB::table('POSSESSION_ADDR')->where('c_possession_record_id', $row->c_possession_record_id)->delete();
            $operation = (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'POSSESSION_DATA', $id, $row);
            (new AuditLogService())->write(
                'POSSESSION_DATA',
                'DELETE',
                ['c_possession_record_id' => $id],
                (new AuditLogService())->normalizeRow($row),
                null,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });
    }

    public function socialInstById($id) {
        //建安修改20181113 //20211022修改增加c_inst_code與c_inst_name_code
        $addr_l = explode("-", $id);
        if ($addr_l[1] == '') {
            $addr_l[1] = null;
        }
        if ($addr_l[2] == '') {
            $addr_l[2] = null;
        }
        $row = DB::table('BIOG_INST_DATA')->where('c_personid', $addr_l[0])->where('c_inst_code', $addr_l[1])->where('c_inst_name_code', $addr_l[2])->where('c_bi_role_code', $addr_l[3])->first();
        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }

        //20210804建安新增社交機構輸出文字的程式碼
        $inst_code = null;
        if ($row->c_inst_code || $row->c_inst_code === 0) {
            $text_ = SocialInstCode::where([
                ['c_inst_code', '=', $row->c_inst_code],
                ['c_inst_name_code', '=', $row->c_inst_name_code],
            ])->first();
            $name_hz = SocialInst::where('c_inst_name_code', $text_->c_inst_name_code)->first()->c_inst_name_hz;
            $name_py = SocialInst::where('c_inst_name_code', $text_->c_inst_name_code)->first()->c_inst_name_py;
            $res = SocialInstAddr::where('c_inst_code', $text_->c_inst_code)->first();
            if (count((array)$res) == 0) {
                $addr = "未詳";
            } else {
                $addr = AddressCode::where('c_addr_id', $res->c_inst_addr_id)->first()->c_name_chn;
            }
            $dy = $text_->c_inst_begin_year;
            $dy2 = $text_->c_inst_floruit_dy;
            $dy3 = $text_->c_inst_end_year;
            $dy4 = $text_->c_inst_last_known_year;
            if ($name_hz == null) {
                $name_hz = "未詳";
            }
            if ($name_py == null) {
                $name_py = "未詳";
            }
            if ($addr == null) {
                $addr = "未詳";
            }
            if ($dy == null) {
                $dy = "未詳";
            }
            if ($dy2 == null) {
                $dy2 = "未詳";
            }
            if ($dy3 == null) {
                $dy3 = "未詳";
            }
            if ($dy4 == null) {
                $dy4 = "未詳";
            }
            $inst_code = $text_->c_inst_code." (社交機構代碼)-".$name_hz." ".$name_py."(社交機構名稱)-".$text_->c_inst_name_code."(社交機構名稱代碼)-".$addr."(地址)-".$dy."(起年)-".$dy2."(最早見諸文獻年)-".$dy3."(訖年)-".$dy4."(最晚見諸文獻年)";
        }
        //新增結束

        return ['row' => $row, 'text_str' => $text_str, 'inst_code' => $inst_code];
    }

    public function socialInstStoreById(Request $request, $id) {
        $data = $request->all();
        $data['c_personid'] = $id;
        $data = Arr::except($data, ['_token', 'action', '__proposal_comment']);
        $data['c_source'] = ($data['c_source'] == -999) ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data, true);

        $newid = '';

        DB::transaction(function () use ($id, $data, &$newid) {
            DB::table('BIOG_INST_DATA')->insert($data);
            //新增的聯合主鍵 //20211022修改增加c_inst_code與c_inst_name_code
            $newid = CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $data['c_personid'],
                'c_inst_code' => $data['c_inst_code'],
                'c_inst_name_code' => $data['c_inst_name_code'],
                'c_bi_role_code' => $data['c_bi_role_code'],
            ]);
            $operation = (new OperationRepository())->store(Auth::id(), $id, 1, 'BIOG_INST_DATA', $newid, $data);
            (new AuditLogService())->write(
                'BIOG_INST_DATA',
                'INSERT',
                [
                    'c_personid' => $data['c_personid'],
                    'c_inst_code' => $data['c_inst_code'],
                    'c_inst_name_code' => $data['c_inst_name_code'],
                    'c_bi_role_code' => $data['c_bi_role_code'],
                ],
                null,
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });

        return $newid;
    }

    public function socialInstDeleteById($id, $c_personid) {
        // 複合主鍵格式為 c_personid-c_inst_code-c_inst_name_code-c_bi_role_code（4 個欄位）
        // 向後兼容：舊版操作記錄可能只有 2 個欄位（c_personid 和 c_bi_role_code）
        $addr_l = explode("-", $id);

        // 處理空字串為 null
        foreach ($addr_l as $key => $value) {
            if ($value === '') {
                $addr_l[$key] = null;
            }
        }

        $row = null;

        // 如果有 4 個欄位，使用新格式查詢
        if (count($addr_l) >= 4) {
            $row = DB::table('BIOG_INST_DATA')
                ->where('c_personid', $addr_l[0])
                ->where('c_inst_code', $addr_l[1])
                ->where('c_inst_name_code', $addr_l[2])
                ->where('c_bi_role_code', $addr_l[3])
                ->first();
        }

        // 如果新格式找不到，嘗試舊版 2-field 格式（c_personid 和 c_bi_role_code）
        if (!$row && count($addr_l) >= 2) {
            $row = DB::table('BIOG_INST_DATA')
                ->where('c_personid', $addr_l[0])
                ->where('c_bi_role_code', $addr_l[1])
                ->first();
        }

        if (!$row) {
            return;
        }

        DB::transaction(function () use ($id, $c_personid, $row) {
            DB::table('BIOG_INST_DATA')
                ->where('c_personid', $row->c_personid)
                ->where('c_inst_code', $row->c_inst_code)
                ->where('c_inst_name_code', $row->c_inst_name_code)
                ->where('c_bi_role_code', $row->c_bi_role_code)
                ->delete();

            // 記錄操作
            $instResourceId = CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $row->c_personid,
                'c_inst_code' => $row->c_inst_code,
                'c_inst_name_code' => $row->c_inst_name_code,
                'c_bi_role_code' => $row->c_bi_role_code,
            ]);
            $operation = (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'BIOG_INST_DATA', $instResourceId, $row);

            (new AuditLogService())->write(
                'BIOG_INST_DATA',
                'DELETE',
                [
                    'c_personid' => $row->c_personid,
                    'c_inst_code' => $row->c_inst_code,
                    'c_inst_name_code' => $row->c_inst_name_code,
                    'c_bi_role_code' => $row->c_bi_role_code,
                ],
                (new AuditLogService())->normalizeRow($row),
                null,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });
    }

    public function eventById($id) {
        return $this->eventStatusRepository()->eventById($id);
    }

    public function eventUpdateById(Request $request, $id, $id_) {
        return $this->eventStatusRepository()->eventUpdateById($request, $id, $id_);
    }

    public function eventStoreById(Request $request, $id) {
        return $this->eventStatusRepository()->eventStoreById($request, $id);
    }

    public function eventDeleteById($id, $c_personid) {
        return $this->eventStatusRepository()->eventDeleteById($id, $c_personid);
    }

    public function assocById($id) {
        //20200709聯合主鍵保留字弱點防禦函式
        // 複合主鍵格式: c_personid-c_assoc_code-c_assoc_id-c_kin_code-c_kin_id-c_assoc_kin_code-c_assoc_kin_id-c_text_title-c_assoc_first_year
        // 修正：新增第 9 個欄位 c_assoc_first_year（數據庫 PRIMARY KEY 包含此欄位）

        $temp_l = explode("-", $id);
        // 對每個部分進行解碼
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = $this->unionPKDef_decode($value);
        }

        // 向後兼容邏輯：由於新舊格式在某些情況下無法通過 ID 結構區分
        // （例如：舊 8-field 標題含 - vs 新 9-field 正年份，兩者都有 9 個段且都不含 (minus)）
        // 因此使用數據庫查詢作為 fallback：
        // 1. 先嘗試新 9-field 格式（最後一個元素為年份）
        // 2. 如果查不到，再嘗試舊 8-field 格式（index 7 之後全部為 c_text_title，年份用默認值 -9999）

        $row = null;

        // 嘗試新 9-field 格式
        if (count($temp_l) >= 9) {
            $c_assoc_first_year_new = end($temp_l);
            $totalParts = count($temp_l);
            if ($totalParts > 9) {
                $c_text_title_new = implode('-', array_slice($temp_l, 7, $totalParts - 8));
            } else {
                $c_text_title_new = $temp_l[7] ?? '';
            }

            $row = DB::table('ASSOC_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_assoc_code', '=', $temp_l[1]],
                ['c_assoc_id', '=', $temp_l[2]],
                ['c_kin_code', '=', $temp_l[3]],
                ['c_kin_id', '=', $temp_l[4]],
                ['c_assoc_kin_code', '=', $temp_l[5]],
                ['c_assoc_kin_id', '=', $temp_l[6]],
                ['c_text_title', '=', $c_text_title_new],
                ['c_assoc_first_year', '=', $c_assoc_first_year_new],
            ])->first();

            if ($row) {
                $c_text_title = $c_text_title_new;
                $c_assoc_first_year = $c_assoc_first_year_new;
            }
        }

        // 如果新格式找不到，嘗試舊 8-field 格式
        if (!$row && count($temp_l) >= 8) {
            $c_text_title_old = implode('-', array_slice($temp_l, 7));
            $c_assoc_first_year_old = '-9999';

            $row = DB::table('ASSOC_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_assoc_code', '=', $temp_l[1]],
                ['c_assoc_id', '=', $temp_l[2]],
                ['c_kin_code', '=', $temp_l[3]],
                ['c_kin_id', '=', $temp_l[4]],
                ['c_assoc_kin_code', '=', $temp_l[5]],
                ['c_assoc_kin_id', '=', $temp_l[6]],
                ['c_text_title', '=', $c_text_title_old],
                ['c_assoc_first_year', '=', $c_assoc_first_year_old],
            ])->first();

            if ($row) {
                $c_text_title = $c_text_title_old;
                $c_assoc_first_year = $c_assoc_first_year_old;
            }
        }

        // 如果兩種格式都找不到，使用新格式的解析結果（讓後續代碼處理 null row）
        if (!$row) {
            $c_assoc_first_year = count($temp_l) > 8 ? end($temp_l) : ($temp_l[8] ?? '-9999');
            $totalParts = count($temp_l);
            if ($totalParts > 9) {
                $c_text_title = implode('-', array_slice($temp_l, 7, $totalParts - 8));
            } else {
                $c_text_title = $temp_l[7] ?? '';
            }
        }
        if (!$row) {
            abort(404, '找不到指定的社會關係記錄');
        }

        return $this->assocBuildResult($row);
    }

    /**
     * 使用複合主鍵陣列直接查詢社會關係記錄
     *
     * 避免舊格式 ID 字串的編碼/解碼，直接以 PK 欄位值查詢資料庫。
     *
     * @param array $pk 複合主鍵欄位陣列
     * @return array 包含 row 與相關顯示資料的陣列
     */
    public function assocByPk(array $pk) {
        $where = [];
        $fields = ['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'];
        foreach ($fields as $field) {
            if (isset($pk[$field]) && $pk[$field] !== '') {
                $where[] = [$field, '=', $pk[$field]];
            } else {
                // 對於未提供的可選欄位使用預設值
                $defaults = [
                    'c_kin_code' => '0', 'c_kin_id' => '0',
                    'c_assoc_kin_code' => '0', 'c_assoc_kin_id' => '0',
                    'c_text_title' => '', 'c_assoc_first_year' => '-9999',
                ];
                if (isset($defaults[$field])) {
                    $where[] = [$field, '=', $defaults[$field]];
                }
            }
        }

        $row = DB::table('ASSOC_DATA')->where($where)->first();

        if (!$row) {
            abort(404, '找不到指定的社會關係記錄');
        }

        return $this->assocBuildResult($row);
    }

    /**
     * 從 PK 陣列建立 ASSOC_DATA 的 WHERE 條件
     *
     * @param array $pk 複合主鍵欄位陣列
     * @return array WHERE 條件陣列
     */
    private function buildAssocWhereFromPk(array $pk): array {
        $fields = ['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'];
        $defaults = [
            'c_kin_code' => '0', 'c_kin_id' => '0',
            'c_assoc_kin_code' => '0', 'c_assoc_kin_id' => '0',
            'c_text_title' => '', 'c_assoc_first_year' => '-9999',
        ];
        $where = [];
        foreach ($fields as $field) {
            $value = $pk[$field] ?? ($defaults[$field] ?? null);
            if ($value !== null) {
                $where[] = [$field, '=', $value];
            }
        }

        return $where;
    }

    /**
     * 從 ASSOC_DATA 記錄建立編輯頁面所需的結果陣列
     *
     * @param object $row ASSOC_DATA 記錄
     * @return array 包含 row 與相關顯示資料的陣列
     */
    private function assocBuildResult($row) {
        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        $kin_code = null;
        if ($row->c_kin_code || $row->c_kin_code === 0) {
            $text_ = KinshipCode::find($row->c_kin_code);
            $kin_code = $text_->c_kincode." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
        }
        //20210705新增，20210708[親屬關係人]與[社會關係人親屬]的[姓名]欄位調整為對應關係修改
        // 修正：查詢配對記錄時需要包含 c_assoc_first_year，避免當存在相同 c_text_title 但不同 year 時返回錯誤的記錄
        $kinship_pair = null;
        if ($row->c_kin_id || $row->c_kin_id === 0) {
            $k_p_row = DB::table('ASSOC_DATA')->where([
                ['c_assoc_id', $row->c_personid],
                ['c_personid', $row->c_assoc_id],
                ['c_text_title', $row->c_text_title],
                ['c_assoc_first_year', $row->c_assoc_first_year],
            ])->first();
            if ($k_p_row) {
                $text_ = KinshipCode::find($k_p_row->c_kin_code);
                $kinship_pair = $text_->c_kincode." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
            }
        }
        $assoc_kinship_pair = null;
        if ($row->c_assoc_kin_id || $row->c_assoc_kin_id === 0) {
            $a_k_p_row = DB::table('ASSOC_DATA')->where([
                ['c_assoc_id', $row->c_personid],
                ['c_personid', $row->c_assoc_id],
                ['c_text_title', $row->c_text_title],
                ['c_assoc_first_year', $row->c_assoc_first_year],
            ])->first();
            if ($a_k_p_row) {
                $text_ = KinshipCode::find($a_k_p_row->c_assoc_kin_code);
                $assoc_kinship_pair = $text_->c_kincode." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
            }
        }
        //新增結束
        $kin_id = null;
        if ($row->c_kin_id || $row->c_kin_id === 0) {
            $text_ = BiogMain::find($row->c_kin_id);
            $kin_id = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $assoc_code = null;
        if ($row->c_assoc_code || $row->c_assoc_code === 0) {
            $text_ = AssocCode::find($row->c_assoc_code);
            $assoc_code = $text_->c_assoc_code." ".$text_->c_assoc_desc_chn." ".$text_->c_assoc_desc;
        }
        $assoc_id = null;
        if ($row->c_assoc_id || $row->c_assoc_id === 0) {
            $text_ = BiogMain::find($row->c_assoc_id);
            $assoc_id = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $assoc_kin_code = null;
        if ($row->c_assoc_kin_code || $row->c_assoc_kin_code === 0) {
            $text_ = KinshipCode::find($row->c_assoc_kin_code);
            $assoc_kin_code = $text_->c_kincode." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
        }
        $assoc_kin_id = null;
        if ($row->c_assoc_kin_id || $row->c_assoc_kin_id === 0) {
            $text_ = BiogMain::find($row->c_assoc_kin_id);
            $assoc_kin_id = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $tertiary_personid = null;
        if ($row->c_tertiary_personid || $row->c_tertiary_personid === 0) {
            $text_ = BiogMain::find($row->c_tertiary_personid);
            $tertiary_personid = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $assoc_claimer_id = null;
        if ($row->c_assoc_claimer_id || $row->c_assoc_claimer_id === 0) {
            $text_ = BiogMain::find($row->c_assoc_claimer_id);
            $assoc_claimer_id = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $addr_id = null;
        if ($row->c_addr_id || $row->c_addr_id === 0) {
            $text_ = AddressCode::find($row->c_addr_id);
            if (!$text_) {
                $text_ = AddressCode::find(0);
            }
            $addr_id = $text_->c_addr_id." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $inst_code = null;
        if ($row->c_inst_code || $row->c_inst_code === 0) {
            //20210204進行改寫
            //$text_ = SocialInst::find($row->c_inst_code);
            //$inst_code = $text_->c_inst_name_code." ".$text_->c_inst_name_hz." ".$text_->c_inst_name_py;
            //$text_ = SocialInstCode::find($row->c_inst_code);
            $text_ = SocialInstCode::where([
                ['c_inst_code', '=', $row->c_inst_code],
                ['c_inst_name_code', '=', $row->c_inst_name_code],
            ])->first();
            $name_hz = SocialInst::where('c_inst_name_code', $text_->c_inst_name_code)->first()->c_inst_name_hz;
            $name_py = SocialInst::where('c_inst_name_code', $text_->c_inst_name_code)->first()->c_inst_name_py;
            $res = SocialInstAddr::where('c_inst_code', $text_->c_inst_code)->first();
            if (count((array)$res) == 0) {
                $addr = "未詳";
            } else {
                $addr = AddressCode::where('c_addr_id', $res->c_inst_addr_id)->first()->c_name_chn;
            }
            $dy = $text_->c_inst_begin_year;
            $dy2 = $text_->c_inst_floruit_dy;
            $dy3 = $text_->c_inst_end_year;
            $dy4 = $text_->c_inst_last_known_year;
            if ($name_hz == null) {
                $name_hz = "未詳";
            }
            if ($name_py == null) {
                $name_py = "未詳";
            }
            if ($addr == null) {
                $addr = "未詳";
            }
            if ($dy == null) {
                $dy = "未詳";
            }
            if ($dy2 == null) {
                $dy2 = "未詳";
            }
            if ($dy3 == null) {
                $dy3 = "未詳";
            }
            if ($dy4 == null) {
                $dy4 = "未詳";
            }
            $inst_code = $text_->c_inst_code." (社交機構代碼)-".$name_hz." ".$name_py."(社交機構名稱)-".$text_->c_inst_name_code."(社交機構名稱代碼)-".$addr."(地址)-".$dy."(起年)-".$dy2."(最早見諸文獻年)-".$dy3."(訖年)-".$dy4."(最晚見諸文獻年)";
            //修改結束
        }

        return ['row' => $row, 'text_str' => $text_str, 'kin_code' => $kin_code, 'kin_id' => $kin_id,
            'assoc_code' => $assoc_code, 'assoc_id' => $assoc_id, 'assoc_kin_code' => $assoc_kin_code, 'assoc_kin_id' => $assoc_kin_id,
            'tertiary_personid' => $tertiary_personid, 'assoc_claimer_id' => $assoc_claimer_id, 'addr_id' => $addr_id, 'inst_code' => $inst_code, 'kinship_pair' => $kinship_pair, 'assoc_kinship_pair' => $assoc_kinship_pair];
    }

    /**
     * 使用複合主鍵陣列更新社會關係記錄
     *
     * @param Request $request HTTP 請求
     * @param array $pk 複合主鍵欄位陣列
     * @param int|string $c_personid 人物 ID
     * @return array 更新後的資料
     */
    public function assocUpdateByPk(Request $request, array $pk, $c_personid) {
        $whereConditions = $this->buildAssocWhereFromPk($pk);
        $row = DB::table('ASSOC_DATA')->where($whereConditions)->first();

        if (!$row) {
            abort(404, '找不到指定的社會關係記錄');
        }

        return $this->assocPerformUpdate($request, $whereConditions, $row, $c_personid);
    }

    public function assocUpdateById(Request $request, $id, $c_personid, bool $detectConflict = false) {
        $auditLog = new AuditLogService();
        //20200709聯合主鍵保留字弱點防禦函式
        // 複合主鍵格式: c_personid-c_assoc_code-c_assoc_id-c_kin_code-c_kin_id-c_assoc_kin_code-c_assoc_kin_id-c_text_title-c_assoc_first_year
        // 修正：新增第 9 個欄位 c_assoc_first_year（數據庫 PRIMARY KEY 包含此欄位）

        $temp_l = explode("-", $id);
        // 對每個部分進行解碼
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = $this->unionPKDef_decode($value);
        }

        // 向後兼容邏輯：使用數據庫查詢作為 fallback
        $row = null;

        // 嘗試新 9-field 格式
        if (count($temp_l) >= 9) {
            $c_assoc_first_year_new = end($temp_l);
            $totalParts = count($temp_l);
            if ($totalParts > 9) {
                $c_text_title_new = implode('-', array_slice($temp_l, 7, $totalParts - 8));
            } else {
                $c_text_title_new = $temp_l[7] ?? '';
            }

            $row = DB::table('ASSOC_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_assoc_code', '=', $temp_l[1]],
                ['c_assoc_id', '=', $temp_l[2]],
                ['c_kin_code', '=', $temp_l[3]],
                ['c_kin_id', '=', $temp_l[4]],
                ['c_assoc_kin_code', '=', $temp_l[5]],
                ['c_assoc_kin_id', '=', $temp_l[6]],
                ['c_text_title', '=', $c_text_title_new],
                ['c_assoc_first_year', '=', $c_assoc_first_year_new],
            ])->first();

            if ($row) {
                $c_text_title = $c_text_title_new;
                $c_assoc_first_year = $c_assoc_first_year_new;
            }
        }

        // 如果新格式找不到，嘗試舊 8-field 格式
        if (!$row && count($temp_l) >= 8) {
            $c_text_title_old = implode('-', array_slice($temp_l, 7));
            $c_assoc_first_year_old = '-9999';

            $row = DB::table('ASSOC_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_assoc_code', '=', $temp_l[1]],
                ['c_assoc_id', '=', $temp_l[2]],
                ['c_kin_code', '=', $temp_l[3]],
                ['c_kin_id', '=', $temp_l[4]],
                ['c_assoc_kin_code', '=', $temp_l[5]],
                ['c_assoc_kin_id', '=', $temp_l[6]],
                ['c_text_title', '=', $c_text_title_old],
                ['c_assoc_first_year', '=', $c_assoc_first_year_old],
            ])->first();

            if ($row) {
                $c_text_title = $c_text_title_old;
                $c_assoc_first_year = $c_assoc_first_year_old;
            }
        }

        // 如果兩種格式都找不到，使用新格式的解析結果
        if (!$row) {
            $c_assoc_first_year = count($temp_l) > 8 ? end($temp_l) : ($temp_l[8] ?? '-9999');
            $totalParts = count($temp_l);
            if ($totalParts > 9) {
                $c_text_title = implode('-', array_slice($temp_l, 7, $totalParts - 8));
            } else {
                $c_text_title = $temp_l[7] ?? '';
            }
        }

        if (!$row) {
            return [];
        }

        $whereConditions = [
            ['c_personid', '=', $temp_l[0]],
            ['c_assoc_code', '=', $temp_l[1]],
            ['c_assoc_id', '=', $temp_l[2]],
            ['c_kin_code', '=', $temp_l[3]],
            ['c_kin_id', '=', $temp_l[4]],
            ['c_assoc_kin_code', '=', $temp_l[5]],
            ['c_assoc_kin_id', '=', $temp_l[6]],
            ['c_text_title', '=', $c_text_title],
            ['c_assoc_first_year', '=', $c_assoc_first_year],
        ];

        return $this->assocPerformUpdate($request, $whereConditions, $row, $c_personid, $detectConflict);
    }

    /**
     * 執行社會關係更新的共用邏輯
     *
     * @param Request $request HTTP 請求
     * @param array $whereConditions WHERE 條件陣列
     * @param object $row 原始記錄
     * @param int|string $c_personid 人物 ID
     * @return array 更新後的資料
     */
    private function assocPerformUpdate(Request $request, array $whereConditions, $row, $c_personid, bool $detectConflict = false) {
        $auditLog = new AuditLogService();

        $data = $request->all();
        $data = $this->formatSelect($data);
        $assoc_pair = $data['c_assocship_pair'];
        $kin_pair = $data['c_kinship_pair'];
        $assoc_kin_pair = $data['c_assoc_kinship_pair'];
        $assoc_id = $data['c_assoc_id'];
        $old_assoc_id = $row->c_assoc_id;
        $old_c_text_title = $row->c_text_title;
        $old_c_assoc_first_year = $row->c_assoc_first_year;
        //20210910增加$old_c_assocship_pair用來查詢對應的資料
        $old_c_assoc_code = $row->c_assoc_code;
        $old_c_assocship_pair = AssocCode::where('c_assoc_code', '=', $old_c_assoc_code)->first();
        $old_c_assocship_pair1 = $old_c_assocship_pair['c_assoc_pair'] ?? null;
        $old_c_assocship_pair2 = $old_c_assocship_pair['c_assoc_pair2'] ?? null;

        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment', 'c_assocship_pair', 'c_kinship_pair', 'c_assoc_kinship_pair', 'ai_fill_log_id']);
        $data = (new ToolsRepository())->timestamp($data);

        $ori_data = $data;

        DB::transaction(function () use (&$ori_data, $c_personid, $whereConditions, $row, $data, $kin_pair, $assoc_kin_pair, $assoc_pair, $assoc_id, $old_assoc_id, $old_c_text_title, $old_c_assoc_first_year, $old_c_assocship_pair1, $old_c_assocship_pair2, $auditLog, $detectConflict) {
            DB::table('ASSOC_DATA')->where($whereConditions)->update($data);

            // 修正：operation record ID 包含第 9 個欄位 c_assoc_first_year
            $operation = (new OperationRepository())->store(Auth::id(), $c_personid, 3, 'ASSOC_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $c_personid,
                'c_assoc_code' => $data['c_assoc_code'],
                'c_assoc_id' => $data['c_assoc_id'],
                'c_kin_code' => $data['c_kin_code'],
                'c_kin_id' => $data['c_kin_id'],
                'c_assoc_kin_code' => $data['c_assoc_kin_code'],
                'c_assoc_kin_id' => $data['c_assoc_kin_id'],
                'c_text_title' => $data['c_text_title'] ?? '',
                'c_assoc_first_year' => $data['c_assoc_first_year'] ?? '-9999',
            ]), $data, $row);
            $operationId = $operation ? (string) $operation->id : null;

            $auditLog->write(
                'ASSOC_DATA',
                'UPDATE',
                [
                    'c_personid' => $c_personid,
                    'c_assoc_code' => $data['c_assoc_code'],
                    'c_assoc_id' => $data['c_assoc_id'],
                    'c_kin_code' => $data['c_kin_code'],
                    'c_kin_id' => $data['c_kin_id'],
                    'c_assoc_kin_code' => $data['c_assoc_kin_code'],
                    'c_assoc_kin_id' => $data['c_assoc_kin_id'],
                    'c_text_title' => $data['c_text_title'] ?? '',
                    'c_assoc_first_year' => $data['c_assoc_first_year'] ?? '-9999',
                ],
                $auditLog->normalizeRow($row),
                $data,
                'user',
                (string) Auth::id(),
                $operationId
            );

            $data_mirror = $data;
            $data_mirror['c_kin_code'] = $kin_pair;
            $data_mirror['c_assoc_kin_code'] = $assoc_kin_pair;
            $data_mirror['c_kin_id'] = $c_personid;
            $data_mirror['c_assoc_kin_id'] = $c_personid;
            $data_mirror['c_assoc_code'] = $assoc_pair;
            $data_mirror['c_personid'] = $assoc_id;
            // 反向列指回原人；補建鏡像時需要此 PK 段（update 為同值無害）。
            $data_mirror['c_assoc_id'] = $c_personid;

            // 定位並同步反向鏡像列（共用方法；找不到反向列則補建＝永遠雙向同步）。
            $this->syncAssocMirrorOnUpdate(
                $data_mirror,
                (int) $c_personid,
                $old_assoc_id,
                $old_c_text_title,
                $old_c_assoc_first_year,
                $old_c_assocship_pair1,
                $old_c_assocship_pair2,
                $operation,
                $auditLog,
                // #77：核准（detectConflict=true）視為「比照 v2 direct 的權威雙向套用」→ allowBackfill 亦 true：
                // (a) 嚴格命中+內容分歧 → #66 衝突中止；(b) 嚴格落空+對面碼漂移 → #70 疑似中止（allowBackfill=false 會在
                // 偵測前 early-return 而漏掉，造成「核准成功但鏡像沒同步」的 false-green，codex SERIOUS）；(c) 對面無鏡像
                // → 補建（雙向同步）。legacy direct（detectConflict=false）維持 allowBackfill=false＝行為不變。
                $detectConflict,  // allowBackfill
                $detectConflict,  // detectConflict
                $detectConflict ? $this->buildApprovalMirrorBaselines('ASSOC_DATA', $row) : []
            );
        });

        return $ori_data;
    }

    /**
     * #77：以「正向編輯前的列」($row) 建提案核准用的鏡像衝突基準。
     * 內容欄基準＝舊值（對面≠舊值＝被獨立改過＝真分歧）；碼欄基準＝舊碼的合法反向集（對面碼∉集＝被改成無關碼＝分歧）。
     *
     * 與 v2 *MutationHandler::conflictBaselines 的差異（刻意）：v2 只對「本次有變更」的內容欄建基準，因 v2 鏡像同步
     * 只寫變更欄（$dataMirror=changed-only）；但 legacy assocPerformUpdate/kinshipUpdateById 的 $data_mirror=**整列**，
     * 核准時會把整列鏡像欄覆寫成提案值——故須對**全部**內容欄建基準（任一欄被對面獨立改過都會被覆寫，理應擋下）。
     * 此「全欄」基準對 full-row 覆寫語義是正確的、且核准為高風險不可逆操作，從嚴合理。僅供 detectConflict=true（提案核准）
     * 使用；legacy direct 寫入不傳（行為不變）。
     */
    private function buildApprovalMirrorBaselines(string $table, $row): array {
        $row = (array) $row;
        $baselines = [];
        if ($table === 'ASSOC_DATA') {
            foreach (['c_notes', 'c_source', 'c_pages', 'c_assoc_first_year', 'c_assoc_last_year'] as $f) {
                if (array_key_exists($f, $row)) {
                    $baselines[$f] = $row[$f];
                }
            }
            if ($vr = $this->validAssocReverseSet($row['c_assoc_code'] ?? null)) {
                $baselines['c_assoc_code'] = $vr;
            }
            if ($vr = $this->validKinReverseSet($row['c_kin_code'] ?? null)) {
                $baselines['c_kin_code'] = $vr;
            }
            if ($vr = $this->validKinReverseSet($row['c_assoc_kin_code'] ?? null)) {
                $baselines['c_assoc_kin_code'] = $vr;
            }
        } elseif ($table === 'KIN_DATA') {
            foreach (['c_notes', 'c_source', 'c_pages'] as $f) {
                if (array_key_exists($f, $row)) {
                    $baselines[$f] = $row[$f];
                }
            }
            if ($vr = $this->validKinReverseSet($row['c_kin_code'] ?? null)) {
                $baselines['c_kin_code'] = $vr;
            }
        }

        return $baselines;
    }

    /** §8：合法反向碼集收斂於 RelationshipMirrorService（單一真相來源）。 */
    private function validAssocReverseSet($code): array {
        return app(\App\Services\RelationshipMirrorService::class)->validReverseAssocSet($code);
    }

    private function validKinReverseSet($code): array {
        return app(\App\Services\RelationshipMirrorService::class)->validReverseKinSet($code);
    }

    /**
     * #82：核准 CREATE 反向鏡像的真分歧基準（對齊 v2 KinshipCreateHandler::createMirrorBaselines）。
     * 以「本次欲寫入鏡像內容」為基準：對面同內容→冪等通過、不同→真分歧→MirrorConflictException；碼欄＝正向碼合法反向集。
     */
    private function createKinMirrorBaselines(array $dataMirror, $forwardKinCode): array {
        $baselines = [];
        foreach (['c_notes', 'c_source', 'c_pages'] as $f) {
            if (array_key_exists($f, $dataMirror)) {
                $baselines[$f] = $dataMirror[$f];
            }
        }
        if ($vr = $this->validKinReverseSet($forwardKinCode)) {
            $baselines['c_kin_code'] = $vr;
        }

        return $baselines;
    }

    /**
     * #82：核准 CREATE 反向鏡像的真分歧基準（對齊 v2 AssociationCreateHandler::createMirrorBaselines）。
     */
    private function createAssocMirrorBaselines(array $mirror, $forwardAssocCode): array {
        $baselines = [];
        foreach (['c_notes', 'c_source', 'c_pages', 'c_assoc_first_year', 'c_assoc_last_year'] as $f) {
            if (array_key_exists($f, $mirror)) {
                $baselines[$f] = $mirror[$f];
            }
        }
        if ($vr = $this->validAssocReverseSet($forwardAssocCode)) {
            $baselines['c_assoc_code'] = $vr;
        }

        return $baselines;
    }

    /**
     * 定位並同步 ASSOC_DATA 反向（互逆）鏡像列。供 legacy assocPerformUpdate 與 v2 AssociationMutationHandler 共用。
     *
     * 行為對齊 legacy assocPerformUpdate 的鏡像區塊：用「對方擁有(c_personid=oldAssocId)、指回原人(c_assoc_id=cPersonid)、
     * 同舊 c_text_title / c_assoc_first_year、且 c_assoc_code 屬舊關係碼的配對碼(pair1/pair2)」定位反向列，找到則更新成 $dataMirror。
     *
     * 「永遠雙向同步」改進（修正 legacy 選擇性跳過）：若定位不到反向列（舊資料缺鏡像、或舊關係碼無配對碼而漏更新），
     * 則直接補建 $dataMirror，確保關係恆為雙向。更新既有列時不覆蓋其建檔資訊（c_created_by/date）。
     *
     * @param array      $dataMirror 反向鏡像列的完整資料（已反向變換：含 c_personid=對方、c_assoc_id=原人、反向關係/親屬碼、其餘欄位）
     * @param int        $cPersonid  原人 c_personid（反向列的 c_assoc_id）
     * @param mixed      $oldAssocId 更新前的對方 c_assoc_id（反向列擁有者）
     * @param mixed      $oldTextTitle 更新前的 c_text_title
     * @param mixed      $oldFirstYear 更新前的 c_assoc_first_year
     * @param mixed      $oldPair1   舊關係碼的 ASSOC_CODES.c_assoc_pair
     * @param mixed      $oldPair2   舊關係碼的 ASSOC_CODES.c_assoc_pair2
     * @param mixed      $operation  本次操作的 Operation（鏡像 audit 沿用其 id）；可為 null
     */
    /**
     * 雙向鏡像同步衝突偵測（#66 資料安全；僅 v2 direct 路徑啟用、legacy 不傳＝不偵測）。
     *
     * 「真分歧」語義（修正過度觸發）：對每筆既有鏡像列，逐 $conflictFields 比對
     * **鏡像當前值（非空）vs 正向「編輯前的舊值」($forwardOld)**——
     * - 鏡像當前值 == 正向舊值 → 二者本來同步（鏡像只是跟隨正向）→ 正常編輯，**不報警**、靜默同步。
     * - 鏡像當前值 ≠ 正向舊值 → 對面被**獨立改過**（存了正向這側不知道的內容）→ 同步會洗掉它 → **報衝突**。
     * 注意：基準是「正向舊值」**不是「即將寫入的新值」**——否則任何把已填欄位改成新值的正常編輯都會誤報。
     * 既有值為空白／哨兵（null/''/0/-1/-999/-9999）視為無內容、可安全覆寫，不算衝突。
     * 有衝突則拋 MirrorConflictException（在 handleDirect 交易內 → 整筆回滾），帶對面鏡像列 PK 與衝突明細
     *（existing=對面現值、incoming=本次將寫入值，供前端展示）。
     *
     * @param iterable<int,object> $mirroredRows 既有鏡像列
     * @param array<string,mixed> $baselines 欄位 → 同步基準。每欄基準有兩型（達成「真分歧」）：
     *   - 純量（內容欄）：正向「編輯前的舊值」；對面值 ≠ 此 → 對面被獨立改過 → 真分歧。
     *   - 陣列（關係/配對碼）：正向舊碼的「合法反向碼集」（含 pair1/pair2）；對面碼 ∈ 此集＝仍是合法反向（同步，
     *     pair1↔pair2 互換不誤報）、∉ 此集＝被改成無關碼 → 真分歧。
     * @param array<string,mixed> $incoming 即將寫入鏡像列的值（僅供衝突明細展示）
     */
    private function detectMirrorConflicts(string $table, iterable $mirroredRows, array $baselines, array $incoming, AuditLogService $auditLog): void {
        $isBlank = static fn ($v): bool => $v === null || trim((string) $v) === '' || in_array(trim((string) $v), ['0', '-1', '-999', '-9999'], true);
        foreach ($mirroredRows as $row) {
            $existingRow = $auditLog->normalizeRow($row);
            $conflicts = [];
            foreach ($baselines as $f => $baseline) {
                $existing = $existingRow[$f] ?? null; // 鏡像當前值
                if ($isBlank($existing)) {
                    continue; // 對面空白／哨兵 → 無內容、可安全覆寫
                }
                if (is_array($baseline)) {
                    // 碼欄：對面碼 ∈ baseline 集 → 非分歧；否則真分歧。assoc baseline 為 validReverseAssocSet；
                    // kin 經 #86（語義 b）已於 syncKinMirrorOnUpdate 把 baseline 覆寫為 [本次欲寫入反向碼]（preserve 模式則移除碼欄不判）。
                    $valid = array_map(static fn ($v) => (string) (int) $v, $baseline);
                    $isConflict = !in_array((string) (int) $existing, $valid, true);
                } else {
                    // 內容欄：對面值 ≠ 正向舊值 → 對面被獨立改過 → 真分歧。
                    $isConflict = trim((string) $existing) !== trim((string) ($baseline ?? ''));
                }
                if ($isConflict) {
                    $conflicts[] = ['field' => $f, 'existing' => $existing, 'incoming' => $incoming[$f] ?? null];
                }
            }
            if ($conflicts !== []) {
                throw new \App\Services\Mutations\MirrorConflictException(
                    $table,
                    $auditLog->buildRowPkFromData($table, $existingRow),
                    $conflicts
                );
            }
        }
    }

    public function syncAssocMirrorOnUpdate(array $dataMirror, int $cPersonid, $oldAssocId, $oldTextTitle, $oldFirstYear, $oldPair1, $oldPair2, $operation = null, ?AuditLogService $auditLog = null, bool $allowBackfill = false, bool $detectConflict = false, array $conflictBaselines = []): void {
        $auditLog = $auditLog ?? new AuditLogService();
        $operationId = $operation ? (string) $operation->id : null;

        $query = DB::table('ASSOC_DATA')->where([
            ['c_assoc_id', '=', $cPersonid],
            ['c_personid', '=', $oldAssocId],
            ['c_text_title', '=', $oldTextTitle],
            ['c_assoc_first_year', '=', $oldFirstYear],
        ])
        ->where(function ($q) use ($oldPair1, $oldPair2) {
            if ($oldPair1 !== null) {
                $q->where('c_assoc_code', '=', $oldPair1);
            }
            if ($oldPair2 !== null) {
                $q->orWhere('c_assoc_code', '=', $oldPair2);
            }
        });

        $mirroredRows = (clone $query)->get();

        if ($mirroredRows->isEmpty()) {
            // legacy 預設行為：找不到反向列即跳過（不補建），保持原選擇性行為不變。
            // v2 在「調用方明確送了配對碼」時才補建（$allowBackfill=true）＝永遠雙向同步，
            // 修正 legacy 單邊缺鏡像；且不臆造無效反向碼（c_assoc_code=0）的垃圾列。
            $mirrorCode = $dataMirror['c_assoc_code'] ?? null;
            if (!$allowBackfill || $mirrorCode === null || (string) $mirrorCode === '0' || (int) $mirrorCode === 0) {
                return;
            }

            // #70 放寬探測：嚴格定位（含碼∈合法反向集）落空時，再以「相同對方/本人/書名/首年、不限關係碼」查疑似列。
            // 僅作 UI 疑似提示／強制收斂之用，不入冪等／確定匹配規則（確定性同步永遠只認上方嚴格定位）。
            $relaxed = DB::table('ASSOC_DATA')->where([
                ['c_assoc_id', '=', $cPersonid],
                ['c_personid', '=', $oldAssocId],
                ['c_text_title', '=', $oldTextTitle],
                ['c_assoc_first_year', '=', $oldFirstYear],
            ]);
            $suspects = (clone $relaxed)->get();

            // Option 2 安全判別：ASSOC_DATA 為 9 鍵 PK，同對方+同書+同首年下可合法存在「另一段不同社會關係」（僅 c_assoc_code 等不同）。
            // 故放寬命中的列**只有碼不是任何合法 ASSOC_CODE（純漂移垃圾值，如 99）**時，才可能是本段關係的漂移鏡像、可警告／就地收斂；
            // 碼是合法 code 的列極可能是另一段合法關係的鏡像，**絕不可覆寫**（會靜默吞掉他段關係）→ 視為非本段疑似、不阻擋本段 backfill。
            if ($suspects->isNotEmpty()) {
                $validCodes = DB::table('ASSOC_CODES')->pluck('c_assoc_code')->map(static fn ($c) => (int) $c)->all();
                $drifted = $suspects->filter(static fn ($r) => !in_array((int) $r->c_assoc_code, $validCodes, true))->values();

                if ($drifted->isNotEmpty()) {
                    if ($detectConflict) {
                        // 非強制：偵測到漂移疑似列 → 不自動同步、不 backfill → 拋疑似中止 → 整筆回滾 → 409 + 跳對面 + 強制收斂。
                        throw new \App\Services\Mutations\MirrorSuspectedException(
                            'ASSOC_DATA',
                            $drifted->map(fn ($r) => $auditLog->buildRowPkFromData('ASSOC_DATA', $auditLog->normalizeRow($r)))->all(),
                            (int) $mirrorCode
                        );
                    }
                    // 強制：就地收斂「第一條」漂移列為權威反向鏡像（碼為垃圾值、確定非合法他段關係，安全）；以「該列
                    // 完整 PK」精確定位，不可用放寬 query 更新（會誤改同位置的他段合法關係）。多條漂移時其餘留待人工去
                    // 對面刪——對齊 kin 的「至少收斂第一條」（修掉舊「多條→落 backfill 補新列、舊漂移全保留＝對面殘留
                    // 多條垃圾鏡像、force 名義成功實際沒清」的不一致；codex MINOR）。
                    $only = $drifted->first();
                    $updateSet = Arr::except($dataMirror, ['c_created_by', 'c_created_date']);
                    $pkWhere = [];
                    foreach (CompositePrimaryKey::SCHEMAS['ASSOC_DATA'] as $col) {
                        $pkWhere[] = [$col, '=', $only->$col];
                    }
                    DB::table('ASSOC_DATA')->where($pkWhere)->update($updateSet);
                    $oldData = $auditLog->normalizeRow($only);
                    $newData = array_merge($oldData, $updateSet);
                    $auditLog->write(
                        'ASSOC_DATA',
                        'UPDATE',
                        $auditLog->buildRowPkFromData('ASSOC_DATA', $newData),
                        $oldData,
                        $newData,
                        'user',
                        (string) Auth::id(),
                        $operationId
                    );

                    return;
                }
                // 無漂移疑似（命中的都是合法他段關係或無）→ 落到 backfill 補本段鏡像，不動他段。
            }

            $insert = $dataMirror;
            if (empty($insert['c_created_by'])) {
                $insert['c_created_by'] = Auth::user()->name ?? '';
                $insert['c_created_date'] = Carbon::now();
            }
            DB::table('ASSOC_DATA')->insert($insert);
            $auditLog->write(
                'ASSOC_DATA',
                'INSERT',
                $auditLog->buildRowPkFromData('ASSOC_DATA', $insert),
                null,
                $insert,
                'user',
                (string) Auth::id(),
                $operationId
            );

            return;
        }

        // 更新既有反向鏡像列；不覆蓋其建檔資訊（c_created_by/date）。
        $updateSet = Arr::except($dataMirror, ['c_created_by', 'c_created_date']);

        // #66：覆寫前偵測衝突（僅 v2 direct 啟用）。對面對應欄已有不同內容 → 拋例外回滾整筆，回 409 警告。
        if ($detectConflict) {
            $this->detectMirrorConflicts('ASSOC_DATA', $mirroredRows, $conflictBaselines, $updateSet, $auditLog);
        }

        $query->update($updateSet);

        foreach ($mirroredRows as $mirroredRow) {
            $oldMirroredData = $auditLog->normalizeRow($mirroredRow);
            $newMirroredData = array_merge($oldMirroredData, $updateSet);

            $auditLog->write(
                'ASSOC_DATA',
                'UPDATE',
                $auditLog->buildRowPkFromData('ASSOC_DATA', $newMirroredData),
                $oldMirroredData,
                $newMirroredData,
                'user',
                (string) Auth::id(),
                $operationId
            );
        }
    }

    /**
     * 同步親屬（KIN_DATA）反向鏡像列（update 場景）。legacy kinshipUpdateById 與 v2 KinshipMutationHandler 共用。
     *
     * 反向列定位（對齊 legacy）：對方為客體指向本人（c_kin_id=本人、c_personid=舊對方），舊備註相符，
     * 親屬碼 ∈ kinReverseLocatorCodes(舊正向碼)＝「指向該碼的碼集」∪「該碼自身 pair1/pair2」聯集（#87，與偵測
     * locateOppositeEdges 同源，涵蓋非對稱配對）。精確命中 ≥1 筆＝以配對定位更新全部命中列；否則退回寬鬆定位
     * （僅 c_kin_id/c_personid/c_autogen_notes）＝完全保留 legacy 行為。
     *
     * $dataMirror 須已備妥：c_kin_code=反向碼、c_personid=新對方、且「不含 c_kin_id」（反向列 c_kin_id 維持本人）。
     * $allowBackfill=true 時（v2 永遠雙向同步），找不到反向列且鏡像碼有效則補建——修正 legacy 單邊缺鏡像；
     * legacy 呼叫一律傳 false＝行為不變。回傳精確配對命中數（legacy 以此填 err）。
     */
    public function syncKinMirrorOnUpdate(array $dataMirror, int $cPersonid, $oldKinId, $oldAutogenNotes, $oldKinCode, $operation = null, ?AuditLogService $auditLog = null, bool $allowBackfill = false, bool $detectConflict = false, array $conflictBaselines = []): int {
        $auditLog = $auditLog ?? new AuditLogService();
        $operationId = $operation ? (string) $operation->id : null;

        // 反向列以「舊正向碼」的權威配對定位。舊碼缺於 KINSHIP_CODES＝資料完整性已破壞：
        // fail-closed 中止（拋例外回滾整筆交易），與 legacy（解參考 null 致命錯誤）同為原子失敗，
        // 避免 fall-through 到寬鬆定位誤改他組關係或留下單邊鏡像孤兒（codex MAJOR 修正）。
        $kinCodePair = KinshipCode::find($oldKinCode);
        if (!$kinCodePair) {
            throw new \App\Services\Mutations\MirrorIntegrityException('找不到原親屬關係碼的配對資訊（KINSHIP_CODES 缺碼），為避免反向鏡像失準已中止本次同步。');
        }
        // 反向鏡像定位碼集＝聯集（#87，收斂於 RelationshipMirrorService::kinReverseLocatorCodes，單一真相）：
        // 「指向 oldKinCode 的碼」（含使用者手選替代反向碼，如 74↔72，非僅權威 c_kin_pair1）∪「oldKinCode 自身 pair1/pair2
        // 指向的碼」。修正原「僅指向集、空集才退回自身」在非對稱配對（如 75.c_kin_pair2=180 但無碼回指 75）漏掉以 180
        // 編碼的合法反向列 → 落 relaxed 被誤判他段、偵測誤報缺邊、sync 漏定位補重複。偵測 locateOppositeEdges 用同一聯集。
        $legitReverses = app(\App\Services\RelationshipMirrorService::class)->kinReverseLocatorCodes($oldKinCode);

        // #87（autogen 根因）：嚴格定位**不**納入 c_autogen_notes。它是描述性註記、鏡像兩側天生不對稱（自動生成側帶
        // 「Auto-generated from PersonID=…」字串、原始手填側為 NULL），舊版精確比對 autogen 會讓「自動生成側 vs NULL 原始側」
        // 嚴格落空 → 偵測誤報缺邊、且 sync 漏定位（存檔時對面反向列不被同步、或補出重複）。反向關係身分由
        // (c_kin_id=本人, c_personid=對方, c_kin_code ∈ 反向碼集) 唯一決定；與偵測 locateOppositeEdges 同套（皆不認 autogen）。
        $pairWhere = function ($query) use ($cPersonid, $oldKinId, $legitReverses) {
            $query->where('c_kin_id', $cPersonid)
                ->where('c_personid', $oldKinId)
                ->whereIn('c_kin_code', $legitReverses ?: [-99999]); // 空集 → 不命中（落 relaxed）
        };

        $sumCount = DB::table('KIN_DATA')->where($pairWhere)->count();

        // 找不到反向列即補建（僅 allowBackfill；legacy 一律 false＝跳過）。抽成閉包供多處重用。
        $mirrorCode = $dataMirror['c_kin_code'] ?? null;
        $backfill = function () use ($dataMirror, $cPersonid, $mirrorCode, $allowBackfill, $auditLog, $operationId): void {
            if (!$allowBackfill || $mirrorCode === null || (string) $mirrorCode === '0' || (int) $mirrorCode === 0) {
                return;
            }
            $insert = $dataMirror;
            $insert['c_kin_id'] = $cPersonid; // 反向列客體＝本人（dataMirror 不含 c_kin_id，補回）
            if (empty($insert['c_created_by'])) {
                $insert['c_created_by'] = Auth::user()->name ?? '';
                $insert['c_created_date'] = Carbon::now();
            }
            DB::table('KIN_DATA')->insert($insert);
            $auditLog->write('KIN_DATA', 'INSERT', $auditLog->buildRowPkFromData('KIN_DATA', $insert), null, $insert, 'user', (string) Auth::id(), $operationId);
        };

        if ($sumCount >= 1) {
            // 精確配對命中（一筆或多筆本段合法反向列）：走 #66 內容衝突偵測 + 同步全部命中列。
            // 用 >= 1（非 === 1）：拓寬後合法反向集可 ≥2（如 73 與 74 並存或重複髒列），它們都是本段鏡像、應一併同步，
            // 不可落到下方 relaxed 把合法反向碼誤判他段而漏同步（review S1）。
            $updateQuery = DB::table('KIN_DATA')->where($pairWhere);
            $mirroredRows = (clone $updateQuery)->get();
            if ($detectConflict) {
                // #86（語義 b）：碼欄衝突基準＝本次「實際欲寫入對面的反向碼」（$dataMirror['c_kin_code']），於此單點權威覆寫
                // 呼叫端傳入的碼欄基準。理由：定位器命中列其碼必為合法反向，但若對面碼 ≠ 我方欲寫入碼（如對面為更具體的排行碼
                // 77、我方寫通用 76），update 會覆寫丟失原碼 → 應提示確認；對面碼 == 欲寫入碼（含手選同碼）→ 冪等通過、不誤擋。
                // 內容欄基準（c_notes/c_source/c_pages）維持呼叫端所傳不變。
                $kinBaselines = $conflictBaselines;
                if (isset($dataMirror['c_kin_code'])) {
                    $writtenReverse = (int) $dataMirror['c_kin_code'];
                    if (in_array($writtenReverse, $legitReverses, true)) {
                        // 未改正向碼（欲寫入反向碼仍屬舊正向碼的反向集）：嚴格＝[欲寫入碼]（保護：對面為「別的」合法反向碼→提示）。
                        $kinBaselines['c_kin_code'] = [$writtenReverse];
                    } else {
                        // 正向碼改碼(recode)：欲寫入的是「新正向碼」的反向碼，對面持有的是「舊正向碼」的合法反向碼（$legitReverses）、
                        // 正被遷移到新碼——非分歧，不應誤擋。故基準併入舊反向集，容許遷移既有鏡像（仍擋真正∉兩者的漂移/他段碼）。
                        $kinBaselines['c_kin_code'] = array_values(array_unique(array_merge([$writtenReverse], $legitReverses)));
                    }
                } else {
                    // preserve 模式（純內容編輯：未送 c_kinship_pair、正向碼未變 → KinshipMutationHandler unset c_kin_code）：
                    // 本次根本不改寫對面反向碼 → 不應對碼欄判衝突（否則對面既有合法手選碼如 74 ∉ 呼叫端窄基準會被誤擋）。
                    unset($kinBaselines['c_kin_code']);
                }
                $this->detectMirrorConflicts('KIN_DATA', $mirroredRows, $kinBaselines, $dataMirror, $auditLog);
            }
            $updateQuery->update($dataMirror);
            foreach ($mirroredRows as $mirroredRow) {
                $oldMirroredData = $auditLog->normalizeRow($mirroredRow);
                $newMirroredData = array_merge($oldMirroredData, $dataMirror);
                $auditLog->write('KIN_DATA', 'UPDATE', $auditLog->buildRowPkFromData('KIN_DATA', $newMirroredData), $oldMirroredData, $newMirroredData, 'user', (string) Auth::id(), $operationId);
            }

            return $sumCount;
        }

        // sumCount === 0（本段合法反向集無命中）：#70 放寬探測「相同對方/本人、不限親屬碼」的疑似列。
        // 僅作 UI 疑似提示／強制收斂之用，不入冪等／確定匹配（確定性同步只認上方精確配對）。
        // #87：與 strict/偵測一致，relaxed 亦**不**認 c_autogen_notes（鏡像兩側天生不對稱、非身分；當前程式已不生成 autogen）。
        // 否則「漂移碼 + autogen 不對稱」時 relaxed 漏抓疑似 → create 補出重複、direct update 靜默漏同步、本該 409 變成功。
        $relaxed = DB::table('KIN_DATA')->where([
            ['c_kin_id', '=', $cPersonid],
            ['c_personid', '=', $oldKinId],
        ]);
        $suspects = (clone $relaxed)->get();

        if ($suspects->isEmpty()) {
            $backfill();

            return $sumCount;
        }

        // Option 2 防資料損壞：同對方/本人/舊備註下可能存在「另一段合法親屬關係」（僅 c_kin_code 不同）。
        // 故只有「碼∉任何合法 KINSHIP_CODE 的漂移垃圾列」才可能是本段漂移鏡像、可警告／就地收斂；
        // 碼∈合法 code 的列極可能是他段合法關係，**絕不可覆寫**（修掉舊「寬鬆定位無條件更新」會吞他段的 latent bug）。
        $validCodes = DB::table('KINSHIP_CODES')->pluck('c_kincode')->map(static fn ($c) => (int) $c)->all();
        $drifted = $suspects->filter(static fn ($r) => !in_array((int) $r->c_kin_code, $validCodes, true))->values();

        if ($drifted->isNotEmpty()) {
            // 權威反向碼：mirrorCode（preserveMirrorCode 場景被 handler unset 為 null/0）時回退到合法反向集首碼。
            // 收斂漂移列**必須**把主鍵碼修為此權威碼——否則純內容編輯（preserve、未送配對碼）下強制收斂只改內容、
            // 漂移碼 99 不會被修回 73，資料仍 drift（codex 揪出的真漏洞）。
            $authoritative = (int) ($mirrorCode ?? 0);
            if ($authoritative === 0) {
                // 權威預設＝正向碼的 c_kin_pair1（與 ResolvesKinshipReversePair 一致、確定性）；再退合法反向集首碼。
                $authoritative = (int) ($kinCodePair->c_kin_pair1 ?? ($legitReverses[0] ?? 0));
            }
            // fail-closed：若連權威反向碼都推不出（正向碼無任何反向配對，極端髒資料），不可靜默跳過收斂假成功——
            // 與同方法「缺配對碼」fail-closed 一致，拋例外回滾整筆（codex：不能 200 後什麼都沒做）。
            if ($authoritative === 0) {
                throw new \App\Services\Mutations\MirrorIntegrityException('偵測到對面疑似漂移鏡像，但正向親屬碼無任何權威反向碼可收斂（KINSHIP_CODES 配對缺失），為避免假成功已中止本次同步。');
            }

            if ($detectConflict) {
                // 非強制：偵測到漂移疑似列 → 不自動同步 → 拋疑似中止 → 整筆回滾 → 409 + 跳對面 + 強制收斂。
                throw new \App\Services\Mutations\MirrorSuspectedException(
                    'KIN_DATA',
                    $drifted->map(fn ($r) => $auditLog->buildRowPkFromData('KIN_DATA', $auditLog->normalizeRow($r)))->all(),
                    $authoritative
                );
            }
            // legacy／強制：收斂「第一條」漂移列為權威反向碼（按該列完整 PK 精確定位，不用寬鬆 query，避免誤改他段）——
            // 修出一條正確鏡像。多條漂移時其餘留待人工去對面刪（kin allowBackfill=false，不能像 assoc 用 backfill 補；
            // 故改為收斂第一條，避免「多條→backfill no-op→force 名義成功實際沒修」的漏洞）。
            // 不做 targetExists 防呆：若目標 PK（權威碼）罕見地已被他列佔用（同人同碼、不同 autogen），任 update 撞 KIN_DATA
            // 唯一鍵 → QueryException → handleDirect 既有 catch 轉 409「主鍵衝突」（誠實告知無法自動解，需人工），
            // 而非靜默 no-op 假成功（codex 修正：先前的 targetExists guard 反而造成 silent no-op）。
            $only = $drifted->first();
            $pkWhere = [];
            foreach (CompositePrimaryKey::SCHEMAS['KIN_DATA'] as $col) {
                $pkWhere[] = [$col, '=', $only->$col];
            }
            // 強制把漂移碼修為權威反向碼（即使 preserve 場景 dataMirror 不含 c_kin_code）——這才是「收斂」。
            $collapseSet = array_merge($dataMirror, ['c_kin_code' => $authoritative]);
            DB::table('KIN_DATA')->where($pkWhere)->update($collapseSet);
            $oldMirroredData = $auditLog->normalizeRow($only);
            $newMirroredData = array_merge($oldMirroredData, $collapseSet);
            $auditLog->write('KIN_DATA', 'UPDATE', $auditLog->buildRowPkFromData('KIN_DATA', $newMirroredData), $oldMirroredData, $newMirroredData, 'user', (string) Auth::id(), $operationId);

            return $sumCount;
        }

        // 無漂移疑似（命中的都是合法碼他段關係或無）→ 不覆寫他段，改 backfill 補本段鏡像（kin update allowBackfill=false 即跳過）。
        $backfill();

        return $sumCount;
    }

    /**
     * 同步親屬（KIN_DATA）反向鏡像列（delete 場景）。legacy kinshipDeleteById 與 v2 KinshipDeleteHandler 共用。
     * $row 為被刪除的「正向列」（陣列）。以 kinReverseLocatorCodes（指向正向碼 ∪ 正向碼自身配對的聯集，#87；與
     * update/偵測 locateOppositeEdges 同源）定位反向列（c_kin_id=正向 c_personid、c_personid=正向 c_kin_id、
     * c_kin_code ∈ kinReverseLocatorCodes(正向碼)；#87 起不認 c_autogen_notes，鏡像兩側天生不對稱非身分），
     * 涵蓋排行子/非對稱配對等多碼，修正舊窄定位漏刪→孤兒。
     * 命中 0 → 不刪（合法單邊）；命中 1 → 精確刪該列；命中 >1 且未帶 $force → 拋 MirrorDeleteMultipleException
     * （整筆交易回滾，回 409 供使用者確認）；>1 且 $force → 一併精確刪除全部候選。正向列本身由呼叫端負責刪除。
     */
    public function syncKinMirrorOnDelete(array $row, $operation = null, ?AuditLogService $auditLog = null, bool $force = false): void {
        $auditLog = $auditLog ?? new AuditLogService();
        $operationId = $operation ? (string) $operation->id : null;

        // 正向碼缺於 KINSHIP_CODES＝資料完整性已破壞：fail-closed 中止（拋 MirrorIntegrityException 回滾整筆交易，
        // 與 update/create 同型別→由 handleDirect/approve 轉結構化 422，而非裸 RuntimeException 漏成 500，三路一致）。
        // 注意：這與「反向列不存在」（合法的單邊資料）不同——後者下方仍正常 return 不刪。
        if (!KinshipCode::find($row['c_kin_code'] ?? null)) {
            throw new \App\Services\Mutations\MirrorIntegrityException('找不到親屬關係碼的配對資訊（KINSHIP_CODES 缺碼），為避免反向鏡像孤兒已中止刪除。');
        }

        // 反向鏡像定位用 kinReverseLocatorCodes 聯集（與 update / 偵測 locateOppositeEdges 同源，#87），
        // 涵蓋排行子/非對稱配對等多碼，修正舊窄定位漏刪具體反向碼列→孤兒。
        $mirror = app(\App\Services\RelationshipMirrorService::class);
        $candidates = $mirror->locateOppositeEdges('kinship', [
            'person_id' => $row['c_personid'] ?? null,
            'opposite_id' => $row['c_kin_id'] ?? null,
            'autogen_notes' => $row['c_autogen_notes'] ?? null,
            'forward_code' => $row['c_kin_code'] ?? 0,
        ]);

        if ($candidates->isEmpty()) {
            return; // 對面無對應反向列（合法的單邊資料），不刪。
        }

        // 安全單筆：對面命中多筆且未確認 → 偵測即停（拋例外 → handleDirect 交易回滾，正向列亦不刪），
        // 回 409 + 候選明細供使用者裁決；確認後帶 $force 重送才一併刪除全部候選。單筆則直接精確刪除。
        if ($candidates->count() > 1 && !$force) {
            throw new \App\Services\Mutations\MirrorDeleteMultipleException('KIN_DATA', $mirror->formatRecords('kinship', $candidates));
        }

        foreach ($candidates as $mirroredRow) {
            // 逐列以完整識別欄精確刪除（c_modified_date 有值時加入），不誤刪他列。
            $deleteQuery = DB::table('KIN_DATA')
                ->where('c_personid', $mirroredRow->c_personid)
                ->where('c_kin_id', $mirroredRow->c_kin_id)
                ->where('c_kin_code', $mirroredRow->c_kin_code)
                ->where('c_source', $mirroredRow->c_source)
                ->where('c_autogen_notes', $mirroredRow->c_autogen_notes);
            if (!is_null($mirroredRow->c_modified_date ?? null)) {
                $deleteQuery->where('c_modified_date', $mirroredRow->c_modified_date);
            }
            $deleteQuery->delete();

            $mirroredRowData = $auditLog->normalizeRow($mirroredRow);
            $auditLog->write('KIN_DATA', 'DELETE', $auditLog->buildRowPkFromData('KIN_DATA', $mirroredRowData), $mirroredRowData, null, 'user', (string) Auth::id(), $operationId);
        }
    }

    public function assocStoreById(Request $request, $id, bool $detectConflict = false) {
        $auditLog = new AuditLogService();
        $data = $request->all();
        $data = $this->formatSelect($data);
        $assoc_pair = $data['c_assocship_pair'];
        $kin_pair = $data['c_kinship_pair'];
        $assoc_kin_pair = $data['c_assoc_kinship_pair'];
        $data['c_personid'] = $id;
        $data = Arr::except($data, ['_token', 'action', '__proposal_comment', 'c_assocship_pair', 'c_kinship_pair', 'c_assoc_kinship_pair', 'ai_fill_log_id']);

        #20250417「社會關係始年」缺省值製作，若「社會關係始年」為空，則自動填充為-9999。
        if ($data['c_assoc_first_year'] == '') {  #這個判斷式只會將「社會關係始年」為空白時，填充為-9999，如果使用者填寫0，會維持0的值而不更動。
            $data['c_assoc_first_year'] = '-9999';
        }
        $data = (new ToolsRepository())->timestamp($data, true);

        $ori_Data = $data;

        DB::transaction(function () use ($id, $data, $assoc_pair, $kin_pair, $assoc_kin_pair, $detectConflict, &$ori_Data, $auditLog) {
            DB::table('ASSOC_DATA')->insert($data);
            // 修正：operation record ID 包含第 9 個欄位 c_assoc_first_year
            $operation = (new OperationRepository())->store(Auth::id(), $id, 1, 'ASSOC_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $data['c_personid'],
                'c_assoc_code' => $data['c_assoc_code'],
                'c_assoc_id' => $data['c_assoc_id'],
                'c_kin_code' => $data['c_kin_code'],
                'c_kin_id' => $data['c_kin_id'],
                'c_assoc_kin_code' => $data['c_assoc_kin_code'],
                'c_assoc_kin_id' => $data['c_assoc_kin_id'],
                'c_text_title' => $data['c_text_title'] ?? '',
                'c_assoc_first_year' => $data['c_assoc_first_year'] ?? '-9999',
            ]), $data);
            $operationId = $operation ? (string) $operation->id : null;

            $auditLog->write(
                'ASSOC_DATA',
                'INSERT',
                [
                    'c_personid' => $data['c_personid'],
                    'c_assoc_code' => $data['c_assoc_code'],
                    'c_assoc_id' => $data['c_assoc_id'],
                    'c_kin_code' => $data['c_kin_code'],
                    'c_kin_id' => $data['c_kin_id'],
                    'c_assoc_kin_code' => $data['c_assoc_kin_code'],
                    'c_assoc_kin_id' => $data['c_assoc_kin_id'],
                    'c_text_title' => $data['c_text_title'] ?? '',
                    'c_assoc_first_year' => $data['c_assoc_first_year'] ?? '-9999',
                ],
                null,
                $data,
                'user',
                (string) Auth::id(),
                $operationId
            );

            $data_mirror = $data;
            $data_mirror['c_assoc_code'] = $assoc_pair;
            $data_mirror['c_personid'] = $data['c_assoc_id'];
            $data_mirror['c_assoc_id'] = $id;
            $data_mirror['c_kin_code'] = $kin_pair;
            $data_mirror['c_assoc_kin_code'] = $assoc_kin_pair;
            $data_mirror['c_kin_id'] = $id;
            $data_mirror['c_assoc_kin_id'] = $id;

            // #82：提案核准（$detectConflict=true）且反向社會關係碼非哨兵 0 時，反向鏡像改走已 gate 的
            // syncAssocMirrorOnUpdate（含 #66 衝突 + #72 疑似偵測 + allowBackfill），與 v2 direct create 一致——
            // 對面分歧/碼漂移→拋例外中止核准（approve() 友善 flash + 回滾）。legacy direct（false）維持原盲插 parity。
            if ($detectConflict && (int) $assoc_pair !== 0) {
                $this->syncAssocMirrorOnUpdate(
                    $data_mirror,
                    (int) $id,
                    (int) $data['c_assoc_id'],
                    $data['c_text_title'] ?? '',
                    $data['c_assoc_first_year'] ?? '-9999',
                    $assoc_pair,
                    null,
                    $operation,
                    $auditLog,
                    true,   // allowBackfill
                    true,   // detectConflict
                    $this->createAssocMirrorBaselines($data_mirror, $data['c_assoc_code'])
                );
            } else {
                DB::table('ASSOC_DATA')->insert($data_mirror);
                $auditLog->write(
                    'ASSOC_DATA',
                    'INSERT',
                    [
                        'c_personid' => $data_mirror['c_personid'],
                        'c_assoc_code' => $data_mirror['c_assoc_code'],
                        'c_assoc_id' => $data_mirror['c_assoc_id'],
                        'c_kin_code' => $data_mirror['c_kin_code'],
                        'c_kin_id' => $data_mirror['c_kin_id'],
                        'c_assoc_kin_code' => $data_mirror['c_assoc_kin_code'],
                        'c_assoc_kin_id' => $data_mirror['c_assoc_kin_id'],
                        'c_text_title' => $data_mirror['c_text_title'] ?? '',
                        'c_assoc_first_year' => $data_mirror['c_assoc_first_year'] ?? '-9999',
                    ],
                    null,
                    $data_mirror,
                    'user',
                    (string) Auth::id(),
                    $operationId
                );
            }
        });

        return $ori_Data;
    }

    /**
     * 使用複合主鍵陣列刪除社會關係記錄
     *
     * @param array $pk 複合主鍵欄位陣列
     * @param int|string $c_personid 人物 ID
     */
    public function assocDeleteByPk(array $pk, $c_personid) {
        $whereConditions = $this->buildAssocWhereFromPk($pk);
        $row = DB::table('ASSOC_DATA')->where($whereConditions)->first();

        if (!$row) {
            abort(404, '找不到指定的社會關係記錄');
        }

        $this->assocPerformDelete($whereConditions, $row, $c_personid);
    }

    public function assocDeleteById($id, $c_personid) {
        $auditLog = new AuditLogService();
        //20200709聯合主鍵保留字弱點防禦函式
        // 複合主鍵格式: c_personid-c_assoc_code-c_assoc_id-c_kin_code-c_kin_id-c_assoc_kin_code-c_assoc_kin_id-c_text_title-c_assoc_first_year
        // 修正：新增第 9 個欄位 c_assoc_first_year（數據庫 PRIMARY KEY 包含此欄位）

        $temp_l = explode("-", $id);
        // 對每個部分進行解碼
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = $this->unionPKDef_decode($value);
        }

        // 向後兼容邏輯：使用數據庫查詢作為 fallback
        $row = null;

        // 嘗試新 9-field 格式
        if (count($temp_l) >= 9) {
            $c_assoc_first_year_new = end($temp_l);
            $totalParts = count($temp_l);
            if ($totalParts > 9) {
                $c_text_title_new = implode('-', array_slice($temp_l, 7, $totalParts - 8));
            } else {
                $c_text_title_new = $temp_l[7] ?? '';
            }

            $row = DB::table('ASSOC_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_assoc_code', '=', $temp_l[1]],
                ['c_assoc_id', '=', $temp_l[2]],
                ['c_kin_code', '=', $temp_l[3]],
                ['c_kin_id', '=', $temp_l[4]],
                ['c_assoc_kin_code', '=', $temp_l[5]],
                ['c_assoc_kin_id', '=', $temp_l[6]],
                ['c_text_title', '=', $c_text_title_new],
                ['c_assoc_first_year', '=', $c_assoc_first_year_new],
            ])->first();

            if ($row) {
                $c_text_title = $c_text_title_new;
                $c_assoc_first_year = $c_assoc_first_year_new;
            }
        }

        // 如果新格式找不到，嘗試舊 8-field 格式
        if (!$row && count($temp_l) >= 8) {
            $c_text_title_old = implode('-', array_slice($temp_l, 7));
            $c_assoc_first_year_old = '-9999';

            $row = DB::table('ASSOC_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_assoc_code', '=', $temp_l[1]],
                ['c_assoc_id', '=', $temp_l[2]],
                ['c_kin_code', '=', $temp_l[3]],
                ['c_kin_id', '=', $temp_l[4]],
                ['c_assoc_kin_code', '=', $temp_l[5]],
                ['c_assoc_kin_id', '=', $temp_l[6]],
                ['c_text_title', '=', $c_text_title_old],
                ['c_assoc_first_year', '=', $c_assoc_first_year_old],
            ])->first();

            if ($row) {
                $c_text_title = $c_text_title_old;
                $c_assoc_first_year = $c_assoc_first_year_old;
            }
        }

        // 如果兩種格式都找不到，使用新格式的解析結果
        if (!$row) {
            $c_assoc_first_year = count($temp_l) > 8 ? end($temp_l) : ($temp_l[8] ?? '-9999');
            $totalParts = count($temp_l);
            if ($totalParts > 9) {
                $c_text_title = implode('-', array_slice($temp_l, 7, $totalParts - 8));
            } else {
                $c_text_title = $temp_l[7] ?? '';
            }
        }

        if (!$row) {
            return;
        }

        $whereConditions = [
            ['c_personid', '=', $temp_l[0]],
            ['c_assoc_code', '=', $temp_l[1]],
            ['c_assoc_id', '=', $temp_l[2]],
            ['c_kin_code', '=', $temp_l[3]],
            ['c_kin_id', '=', $temp_l[4]],
            ['c_assoc_kin_code', '=', $temp_l[5]],
            ['c_assoc_kin_id', '=', $temp_l[6]],
            ['c_text_title', '=', $c_text_title],
            ['c_assoc_first_year', '=', $c_assoc_first_year],
        ];

        $this->assocPerformDelete($whereConditions, $row, $c_personid);
    }

    /**
     * 執行社會關係刪除的共用邏輯
     *
     * @param array $whereConditions WHERE 條件陣列
     * @param object $row 原始記錄
     * @param int|string $c_personid 人物 ID
     */
    private function assocPerformDelete(array $whereConditions, $row, $c_personid) {
        $auditLog = new AuditLogService();

        DB::transaction(function () use ($c_personid, $whereConditions, $row, $auditLog) {
            DB::table('ASSOC_DATA')->where($whereConditions)->delete();

            $assocResourceId = CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $row->c_personid,
                'c_assoc_code' => $row->c_assoc_code,
                'c_assoc_id' => $row->c_assoc_id,
                'c_kin_code' => $row->c_kin_code,
                'c_kin_id' => $row->c_kin_id,
                'c_assoc_kin_code' => $row->c_assoc_kin_code,
                'c_assoc_kin_id' => $row->c_assoc_kin_id,
                'c_text_title' => $row->c_text_title ?? '',
                'c_assoc_first_year' => $row->c_assoc_first_year ?? '-9999',
            ]);
            $operation = (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'ASSOC_DATA', $assocResourceId, $row);
            $operationId = $operation ? (string) $operation->id : null;

            $auditLog->write(
                'ASSOC_DATA',
                'DELETE',
                [
                    'c_personid' => $row->c_personid,
                    'c_assoc_code' => $row->c_assoc_code,
                    'c_assoc_id' => $row->c_assoc_id,
                    'c_kin_code' => $row->c_kin_code,
                    'c_kin_id' => $row->c_kin_id,
                    'c_assoc_kin_code' => $row->c_assoc_kin_code,
                    'c_assoc_kin_id' => $row->c_assoc_kin_id,
                    'c_text_title' => $row->c_text_title ?? '',
                    'c_assoc_first_year' => $row->c_assoc_first_year ?? '-9999',
                ],
                $auditLog->normalizeRow($row),
                null,
                'user',
                (string) Auth::id(),
                $operationId
            );

            // 同步刪除反向鏡像列（共用方法；定位策略：對稱 0,0 → 舊關係碼配對碼；找不到則跳過不誤刪）。
            $this->syncAssocMirrorOnDelete((array) $row, $operation, $auditLog);
        });
    }

    /**
     * 定位並刪除 ASSOC_DATA 反向（互逆）鏡像列。供 legacy assocPerformDelete 與 v2 AssociationDeleteHandler 共用。
     *
     * 定位策略（對齊 legacy）：(1) 若 c_kin_id 與 c_assoc_kin_id 皆 0，直接以對稱 0,0 匹配反向列；
     * (2) 否則以舊關係碼的 ASSOC_CODES.c_assoc_pair / c_assoc_pair2 匹配。找不到配對映射則跳過（避免誤刪）。
     * 須在刪除正向列之後呼叫（反向列以 c_personid=對方、c_assoc_id=原人 定位，不受正向列刪除影響）。
     *
     * @param array $row 被刪除的正向列資料（c_personid / c_assoc_id / c_assoc_code / c_kin_id / c_assoc_kin_id / c_text_title / c_assoc_first_year）
     */
    public function syncAssocMirrorOnDelete(array $row, $operation = null, ?AuditLogService $auditLog = null): void {
        $auditLog = $auditLog ?? new AuditLogService();
        $operationId = $operation ? (string) $operation->id : null;
        $textTitle = $row['c_text_title'] ?? '';
        $firstYear = $row['c_assoc_first_year'] ?? '-9999';

        // 反向鏡像定位：優先以舊關係碼的配對碼精確匹配反向列的 c_assoc_code（避免同對人／同書名／同年
        // 有多筆關係時，僅憑 person/0,0/text/year 抓錯反向列而誤刪——對齊「永遠正確同步」）。
        // 無配對映射但對稱 0,0 時，回退 legacy 的對稱匹配（罕見；此時無 code 可驗）。
        $assocCodePair = AssocCode::where('c_assoc_code', '=', $row['c_assoc_code'])->first();
        $assocPair1 = $assocCodePair?->c_assoc_pair;
        $assocPair2 = $assocCodePair?->c_assoc_pair2;
        $isSymmetric = (int) ($row['c_kin_id'] ?? 0) === 0 && (int) ($row['c_assoc_kin_id'] ?? 0) === 0;

        if ($assocPair1 === null && $assocPair2 === null && !$isSymmetric) {
            // 無關係配對映射且非對稱：無法可靠定位反向列，跳過不誤刪（對齊 legacy no_pair_mapping）。
            Log::info('[ASSOC_DATA] 跳過反向記錄刪除', [
                'reason' => 'no_pair_mapping',
                'c_personid' => $row['c_personid'] ?? null,
                'c_assoc_id' => $row['c_assoc_id'] ?? null,
                'c_assoc_code' => $row['c_assoc_code'] ?? null,
            ]);

            return;
        }

        // 反向鏡像基底：對方擁有、指回原人、同書名/年。
        $baseWhere = [
            ['c_personid', $row['c_assoc_id']],
            ['c_assoc_id', $row['c_personid']],
            ['c_text_title', $textTitle],
            ['c_assoc_first_year', $firstYear],
        ];
        $pairFilter = function ($query) use ($assocPair1, $assocPair2) {
            if ($assocPair1 !== null) {
                $query->where('c_assoc_code', '=', $assocPair1);
            }
            if ($assocPair2 !== null) {
                $query->orWhere('c_assoc_code', '=', $assocPair2);
            }
        };

        // 第一級：以反向鏡像的完整親屬維度精確定位（對齊 legacy create/update 鏡像的反向變換：
        // 親屬碼取反向配對碼、kin_id/assoc_kin_id 皆為原人）。唯一識別，合法多筆（同對人/書/年/碼、
        // 僅 kin 維度不同）也能各自命中正確反向列，不誤刪、不漏刪。
        $row2 = null;
        if ($assocPair1 !== null || $assocPair2 !== null) {
            $row2 = DB::table('ASSOC_DATA')->where($baseWhere)
                ->where('c_kin_code', $this->reverseKinPairCode($row['c_kin_code'] ?? 0))
                ->where('c_kin_id', $row['c_personid'])
                ->where('c_assoc_kin_code', $this->reverseKinPairCode($row['c_assoc_kin_code'] ?? 0))
                ->where('c_assoc_kin_id', $row['c_personid'])
                ->where($pairFilter)
                ->first();
        }

        // 第二級回退（兼容不完全符合鏡像約定的舊資料）：僅以 person/書名/年（＋配對碼或對稱 0,0）定位，
        // 唯一才刪；多筆無法區分則跳過（寧留待人工，也不誤刪他筆關係的反向列）。
        if ($row2 === null) {
            $fallback = DB::table('ASSOC_DATA')->where($baseWhere);
            if ($assocPair1 !== null || $assocPair2 !== null) {
                $fallback->where($pairFilter);
            } elseif ($isSymmetric) {
                $fallback->where('c_kin_id', 0)->where('c_assoc_kin_id', 0);
            }
            $candidates = $fallback->get();
            if ($candidates->count() === 1) {
                $row2 = $candidates->first();
            } elseif ($candidates->count() > 1) {
                Log::warning('[ASSOC_DATA] 反向鏡像列不唯一，跳過刪除以避免誤刪', [
                    'c_personid' => $row['c_personid'] ?? null,
                    'c_assoc_id' => $row['c_assoc_id'] ?? null,
                    'c_assoc_code' => $row['c_assoc_code'] ?? null,
                    'candidates' => $candidates->count(),
                ]);

                return;
            } else {
                return;
            }
        }

        $deleteMirrorQuery = DB::table('ASSOC_DATA')->where([
            ['c_personid', $row2->c_personid],
            ['c_assoc_id', $row2->c_assoc_id],
            ['c_assoc_code', $row2->c_assoc_code],
            ['c_kin_code', $row2->c_kin_code],
            ['c_kin_id', $row2->c_kin_id],
            ['c_assoc_kin_code', $row2->c_assoc_kin_code],
            ['c_assoc_kin_id', $row2->c_assoc_kin_id],
            ['c_text_title', $row2->c_text_title],
            ['c_assoc_first_year', $row2->c_assoc_first_year],
        ]);

        $mirroredRows = (clone $deleteMirrorQuery)->get();
        $deleteMirrorQuery->delete();

        foreach ($mirroredRows as $mirroredRow) {
            $mirroredRowData = $auditLog->normalizeRow($mirroredRow);
            $auditLog->write(
                'ASSOC_DATA',
                'DELETE',
                $auditLog->buildRowPkFromData('ASSOC_DATA', $mirroredRowData),
                $mirroredRowData,
                null,
                'user',
                (string) Auth::id(),
                $operationId
            );
        }
    }

    /** 親屬碼的反向配對碼（KINSHIP_CODES.c_kin_pair1）；0／查無 → 0。供 syncAssocMirrorOnDelete 精確定位反向列。 */
    private function reverseKinPairCode($kinCode): int {
        if ($kinCode === null || (int) $kinCode === 0) {
            return 0;
        }
        $v = DB::table('KINSHIP_CODES')->where('c_kincode', $kinCode)->value('c_kin_pair1');

        return $v !== null ? (int) $v : 0;
    }

    public function addrStoreById(Request $request, $id) {
        $data = $request->all();
        $data = Arr::except($data, ['_token', 'action', '__proposal_comment']);
        $data['c_personid'] = $id;
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary'] ?? 0);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary'] ?? 0);
        if (!isset($data['c_sequence']) || $data['c_sequence'] === '' || $data['c_sequence'] === null) {
            $data['c_sequence'] = 0;
        }
        $duplicate = DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $data['c_personid']],
            ['c_addr_id', '=', $data['c_addr_id']],
            ['c_addr_type', '=', $data['c_addr_type']],
            ['c_sequence', '=', $data['c_sequence']],
        ])->first();
        if (!blank($duplicate)) {
            return false;
        }
        $data = (new ToolsRepository())->timestamp($data, true);

        return DB::transaction(function () use ($id, $data) {
            DB::table('BIOG_ADDR_DATA')->insert($data);
            $operation = (new OperationRepository())->store(Auth::id(), $id, 1, 'BIOG_ADDR_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $data['c_personid'],
                'c_addr_id' => $data['c_addr_id'],
                'c_addr_type' => $data['c_addr_type'],
                'c_sequence' => $data['c_sequence'],
            ]), $data);

            (new AuditLogService())->write(
                'BIOG_ADDR_DATA',
                'INSERT',
                [
                    'c_personid' => $data['c_personid'],
                    'c_addr_id' => $data['c_addr_id'],
                    'c_addr_type' => $data['c_addr_type'],
                    'c_sequence' => $data['c_sequence'],
                ],
                null,
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return $data;
        });
    }

    public function addrUpdateById(Request $request, $id, $addr) {
        $addr_l = $this->parseAddrId($addr);
        $data = $request->all();
        $comment = $data['__proposal_comment'] ?? null;
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment', 'c_personid']);
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary'] ?? 0);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary'] ?? 0);
        $data = (new ToolsRepository())->timestamp($data);

        return DB::transaction(function () use ($id, $addr_l, $data, $comment) {
            $ori = DB::table('BIOG_ADDR_DATA')->where([
                ['c_personid', '=', $addr_l[0]],
                ['c_addr_id', '=', $addr_l[1]],
                ['c_addr_type', '=', $addr_l[2]],
                ['c_sequence', '=', $addr_l[3]],
            ])->lockForUpdate()->first();

            if (!$ori) {
                return null;
            }

            // 若主鍵欄位有變動，檢查新主鍵是否與既有記錄衝突
            $newAddrId = $data['c_addr_id'] ?? $ori->c_addr_id;
            $newAddrType = $data['c_addr_type'] ?? $ori->c_addr_type;
            $newSequence = $data['c_sequence'] ?? $ori->c_sequence;
            $pkChanged = (string) $newAddrId !== (string) $ori->c_addr_id
                      || (string) $newAddrType !== (string) $ori->c_addr_type
                      || (string) $newSequence !== (string) $ori->c_sequence;

            if ($pkChanged) {
                $conflict = DB::table('BIOG_ADDR_DATA')->where([
                    ['c_personid', '=', $addr_l[0]],
                    ['c_addr_id', '=', $newAddrId],
                    ['c_addr_type', '=', $newAddrType],
                    ['c_sequence', '=', $newSequence],
                ])->exists();

                if ($conflict) {
                    throw new \InvalidArgumentException(
                        '目標地址、地址類別與遷徙次序的組合已存在，請使用不同的值。'
                    );
                }
            }

            DB::table('BIOG_ADDR_DATA')->where([
                ['c_personid', '=', $addr_l[0]],
                ['c_addr_id', '=', $addr_l[1]],
                ['c_addr_type', '=', $addr_l[2]],
                ['c_sequence', '=', $addr_l[3]],
            ])->update($data);

            $newPk = [
                'c_personid' => $id,
                'c_addr_id' => $data['c_addr_id'] ?? $ori->c_addr_id,
                'c_addr_type' => $data['c_addr_type'] ?? $ori->c_addr_type,
                'c_sequence' => $data['c_sequence'] ?? $ori->c_sequence,
            ];

            $operationData = $data;
            if ($comment) {
                $operationData['__note'] = $comment;
            }

            $operation = (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_ADDR_DATA', CompositePrimaryKey::buildStoredResourceId($newPk), $operationData, $ori);

            (new AuditLogService())->write(
                'BIOG_ADDR_DATA',
                'UPDATE',
                $newPk,
                (new AuditLogService())->normalizeRow($ori),
                array_merge((new AuditLogService())->normalizeRow($ori), $data),
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return $newPk;
        });
    }

    public function addrDeleteById($id, $c_personid) {
        $addr_l = $this->parseAddrId($id);

        return DB::transaction(function () use ($c_personid, $addr_l) {
            $row = DB::table('BIOG_ADDR_DATA')->where([
                ['c_personid', '=', $addr_l[0]],
                ['c_addr_id', '=', $addr_l[1]],
                ['c_addr_type', '=', $addr_l[2]],
                ['c_sequence', '=', $addr_l[3]],
            ])->lockForUpdate()->first();

            if (!$row) {
                return false;
            }

            DB::table('BIOG_ADDR_DATA')->where([
                ['c_personid', '=', $addr_l[0]],
                ['c_addr_id', '=', $addr_l[1]],
                ['c_addr_type', '=', $addr_l[2]],
                ['c_sequence', '=', $addr_l[3]],
            ])->delete();

            $pk = [
                'c_personid' => $row->c_personid,
                'c_addr_id' => $row->c_addr_id,
                'c_addr_type' => $row->c_addr_type,
                'c_sequence' => $row->c_sequence,
            ];

            $operation = (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'BIOG_ADDR_DATA', CompositePrimaryKey::buildStoredResourceId($pk), $row);

            (new AuditLogService())->write(
                'BIOG_ADDR_DATA',
                'DELETE',
                $pk,
                (new AuditLogService())->normalizeRow($row),
                null,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return true;
        });
    }

    protected function parseAddrId($addr) {
        $addr = str_replace("--", "-minus", $addr);
        $addr_l = explode("-", $addr);
        foreach ($addr_l as $key => $value) {
            $addr_l[$key] = str_replace("minus", "-", $value);
        }

        return $addr_l;
    }

    /**
     * 依複合主鍵取得 ALTNAME_DATA 記錄
     *
     * 使用 3-key (c_personid, c_alt_name_chn, c_alt_name_type_code) 定位，
     * c_sequence 不參與定位。接受字串或 3-key 關聯陣列。
     *
     * @param string|array $id 複合主鍵字串或 3-key 關聯陣列
     */
    public function altnameById($id) {
        $pk = is_array($id) ? $id : $this->parseAltnameId($id);

        return DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $pk['c_personid']],
            ['c_alt_name_chn', '=', $pk['c_alt_name_chn']],
            ['c_alt_name_type_code', '=', $pk['c_alt_name_type_code']],
        ])->first();
    }

    public function altnameStoreById(Request $request, $id) {
        $data = $request->all();
        $data = Arr::except($data, ['_token', 'action', '__proposal_comment']);
        $data['c_personid'] = $id;
        // 括號正規化：全角→半角（中文）、全角→半角+空格（拼音）
        $data = BracketNormalizer::normalizeAltname($data);
        // 重複檢查使用 3-key，c_sequence 不參與定位
        $duplicate = DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $data['c_personid']],
            ['c_alt_name_chn', '=', $data['c_alt_name_chn']],
            ['c_alt_name_type_code', '=', $data['c_alt_name_type_code']],
        ])->first();
        if (!blank($duplicate)) {
            return false;
        }
        $data = (new ToolsRepository())->timestamp($data, true);

        return DB::transaction(function () use ($id, $data) {
            DB::table('ALTNAME_DATA')->insert($data);
            $pk3 = [
                'c_personid' => $data['c_personid'],
                'c_alt_name_chn' => $data['c_alt_name_chn'],
                'c_alt_name_type_code' => $data['c_alt_name_type_code'],
            ];
            $operation = (new OperationRepository())->store(Auth::id(), $id, 1, 'ALTNAME_DATA', CompositePrimaryKey::buildStoredResourceId($pk3), $data);

            (new AuditLogService())->write(
                'ALTNAME_DATA',
                'INSERT',
                $pk3,
                null,
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return $data;
        });
    }

    /**
     * @param string|array $alt 複合主鍵字串或 3-key 關聯陣列
     */
    public function altnameUpdateById(Request $request, $id, $alt) {
        $pk = is_array($alt) ? $alt : $this->parseAltnameId($alt);
        $data = $request->all();
        $comment = $data['__proposal_comment'] ?? null;
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment']);
        // 括號正規化：全角→半角（中文）、全角→半角+空格（拼音）
        $data = BracketNormalizer::normalizeAltname($data);

        // 計算更新後的 3-key，若與原 PK 不同則檢查是否會與既有記錄衝突
        $newPk3 = [
            'c_personid' => $pk['c_personid'],
            'c_alt_name_chn' => $data['c_alt_name_chn'] ?? $pk['c_alt_name_chn'],
            'c_alt_name_type_code' => $data['c_alt_name_type_code'] ?? $pk['c_alt_name_type_code'],
        ];
        if ($newPk3 !== $pk) {
            $conflict = DB::table('ALTNAME_DATA')->where([
                ['c_personid', '=', $newPk3['c_personid']],
                ['c_alt_name_chn', '=', $newPk3['c_alt_name_chn']],
                ['c_alt_name_type_code', '=', $newPk3['c_alt_name_type_code']],
            ])->first();
            if ($conflict) {
                return 'bracket_conflict';
            }
        }

        $data = (new ToolsRepository())->timestamp($data);

        // WHERE 使用 3-key 定位
        $where3key = [
            ['c_personid', '=', $pk['c_personid']],
            ['c_alt_name_chn', '=', $pk['c_alt_name_chn']],
            ['c_alt_name_type_code', '=', $pk['c_alt_name_type_code']],
        ];

        return DB::transaction(function () use ($id, $where3key, $data, $comment) {
            $ori = DB::table('ALTNAME_DATA')->where($where3key)->lockForUpdate()->first();

            if (!$ori) {
                return null;
            }

            DB::table('ALTNAME_DATA')->where($where3key)->update($data);

            $newPk = [
                'c_personid' => $id,
                'c_alt_name_chn' => $data['c_alt_name_chn'] ?? $ori->c_alt_name_chn,
                'c_alt_name_type_code' => $data['c_alt_name_type_code'] ?? $ori->c_alt_name_type_code,
            ];

            $operationData = $data;
            if ($comment) {
                $operationData['__note'] = $comment;
            }

            $operation = (new OperationRepository())->store(Auth::id(), $id, 3, 'ALTNAME_DATA', CompositePrimaryKey::buildStoredResourceId($newPk), $operationData, $ori);

            (new AuditLogService())->write(
                'ALTNAME_DATA',
                'UPDATE',
                $newPk,
                (new AuditLogService())->normalizeRow($ori),
                array_merge((new AuditLogService())->normalizeRow($ori), $data),
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return $newPk;
        });
    }

    /**
     * @param string|array $id 複合主鍵字串或 3-key 關聯陣列
     * @param int|string $c_personid 人物 ID
     */
    public function altnameDeleteById($id, $c_personid) {
        $pk = is_array($id) ? $id : $this->parseAltnameId($id);

        // WHERE 使用 3-key 定位
        $where3key = [
            ['c_personid', '=', $pk['c_personid']],
            ['c_alt_name_chn', '=', $pk['c_alt_name_chn']],
            ['c_alt_name_type_code', '=', $pk['c_alt_name_type_code']],
        ];

        return DB::transaction(function () use ($c_personid, $where3key) {
            $row = DB::table('ALTNAME_DATA')->where($where3key)->lockForUpdate()->first();

            if (!$row) {
                return false;
            }

            DB::table('ALTNAME_DATA')->where($where3key)->delete();

            $pk3 = [
                'c_personid' => $row->c_personid,
                'c_alt_name_chn' => $row->c_alt_name_chn,
                'c_alt_name_type_code' => $row->c_alt_name_type_code,
            ];

            $operation = (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'ALTNAME_DATA', CompositePrimaryKey::buildStoredResourceId($pk3), $row);

            (new AuditLogService())->write(
                'ALTNAME_DATA',
                'DELETE',
                $pk3,
                (new AuditLogService())->normalizeRow($row),
                null,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return true;
        });
    }

    /**
     * 解析 ALTNAME_DATA 複合主鍵字串為 3-key 關聯陣列
     *
     * 支援歷史 4-key 與新 3-key 格式，委派給 CompositePrimaryKey 統一處理。
     *
     * @param string $alt 複合主鍵字串
     * @return array 3-key 關聯陣列 ['c_personid' => ..., 'c_alt_name_chn' => ..., 'c_alt_name_type_code' => ...]
     */
    public function parseAltnameId($alt) {
        $parsed = CompositePrimaryKey::parseStoredResourceId($alt, 'ALTNAME_DATA');
        if ($parsed !== null) {
            return $parsed;
        }

        Log::error("ALTNAME_DATA ID 格式不正確: {$alt}");
        abort(400, 'ALTNAME_DATA ID 格式不正確');
    }

    public function sourceById($id, $_id) {
        //20200715聯合主鍵保留字弱點防禦函式
        // 複合主鍵格式: c_personid-c_textid-c_pages
        // 先分割，再對每個部分解碼（因為 - 被編碼為 (minus)，所以可以安全地用 - 分割）
        $temp_l = explode("-", $_id);
        // 對每個部分進行解碼
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = $this->unionPKDef_decode($value);
        }
        $row = $this->sourceQueryFromLegacyId($temp_l)->first();
        $text_str = null;
        if ($row && ($row->c_textid || $row->c_textid === 0)) {
            $text_ = TextCode::find($row->c_textid);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }

        return ['row' => $row, 'text_str' => $text_str];
    }

    public function sourceUpdateById(Request $request, $id, $id_) {
        //20200715聯合主鍵保留字弱點防禦函式
        // 複合主鍵格式: c_personid-c_textid-c_pages
        // 先分割，再對每個部分解碼（因為 - 被編碼為 (minus)，所以可以安全地用 - 分割）
        $temp_l = explode("-", $id_);
        // 對每個部分進行解碼
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = $this->unionPKDef_decode($value);
        }
        $row = $this->sourceQueryFromLegacyId($temp_l)->first();
        if (!$row) {
            return [];
        }

        $data = $request->all();
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment', 'c_created_by', 'c_created_date']);
        $data['c_personid'] = $id;
        // Select2 對 id=0 處理有問題，API 中 c_textid=0 會被轉換為 -999，需轉回 0
        $data['c_textid'] = $data['c_textid'] == -999 ? '0' : $data['c_textid'];
        $data['c_main_source'] = (int)$data['c_main_source'];
        $data['c_self_bio'] = (int)$data['c_self_bio'];
        $c_modified_by = Auth::user()->name ?? Auth::id();
        $c_modified_date = Carbon::now();
        $data['c_modified_by'] = $c_modified_by;
        $data['c_modified_date'] = $c_modified_date;

        DB::transaction(function () use ($id, $temp_l, $row, $data) {
            $this->sourceQueryFromLegacyId($temp_l)->update($data);

            $operation = (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_SOURCE_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $data['c_personid'],
                'c_textid' => $data['c_textid'],
                'c_pages' => $data['c_pages'],
            ]), $data, $row);

            (new AuditLogService())->write(
                'BIOG_SOURCE_DATA',
                'UPDATE',
                [
                    'c_personid' => $data['c_personid'],
                    'c_textid' => $data['c_textid'],
                    'c_pages' => $data['c_pages'],
                ],
                (new AuditLogService())->normalizeRow($row),
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });

        return $data;
    }

    public function sourceStoreById(Request $request, $id) {
        $data = $request->all();
        $data = Arr::except($data, ['_token', 'action', '__proposal_comment']);
        $data['c_personid'] = $id;
        // Select2 對 id=0 處理有問題，API 中 c_textid=0 會被轉換為 -999，需轉回 0
        $data['c_textid'] = $data['c_textid'] == -999 ? '0' : $data['c_textid'];
        $data['c_main_source'] = (int)$data['c_main_source'];
        $data['c_self_bio'] = (int)$data['c_self_bio'];
        $c_created_by = Auth::user()->name ?? Auth::id();
        $c_created_date = Carbon::now();
        $data['c_created_by'] = $c_created_by;
        $data['c_created_date'] = $c_created_date;

        DB::transaction(function () use ($id, $data) {
            DB::table('BIOG_SOURCE_DATA')->insert($data);
            $operation = (new OperationRepository())->store(Auth::id(), $id, 1, 'BIOG_SOURCE_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $data['c_personid'],
                'c_textid' => $data['c_textid'],
                'c_pages' => $data['c_pages'],
            ]), $data);
            (new AuditLogService())->write(
                'BIOG_SOURCE_DATA',
                'INSERT',
                [
                    'c_personid' => $data['c_personid'],
                    'c_textid' => $data['c_textid'],
                    'c_pages' => $data['c_pages'],
                ],
                null,
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });

        return $data;
    }

    public function sourceDeleteById($id, $id_) {
        //20200715聯合主鍵保留字弱點防禦函式
        // 複合主鍵格式: c_personid-c_textid-c_pages
        // 先分割，再對每個部分解碼（因為 - 被編碼為 (minus)，所以可以安全地用 - 分割）
        $temp_l = explode("-", $id_);
        // 對每個部分進行解碼
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = $this->unionPKDef_decode($value);
        }
        $row = $this->sourceQueryFromLegacyId($temp_l)->first();

        if (!$row) {
            return;
        }

        DB::transaction(function () use ($id, $temp_l, $row) {
            $this->sourceQueryFromLegacyId($temp_l)->delete();
            $operation = (new OperationRepository())->store(Auth::id(), $id, 4, 'BIOG_SOURCE_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $temp_l[0],
                'c_textid' => $temp_l[1],
                'c_pages' => $this->normalizeLegacySourcePages($temp_l[2] ?? ''),
            ]), $row);
            (new AuditLogService())->write(
                'BIOG_SOURCE_DATA',
                'DELETE',
                [
                    'c_personid' => $temp_l[0],
                    'c_textid' => $temp_l[1],
                    'c_pages' => $this->normalizeLegacySourcePages($temp_l[2] ?? ''),
                ],
                (new AuditLogService())->normalizeRow($row),
                null,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });
    }

    protected function addr_str($id) {
        /*20211015遮除，不使用[ADDRESSES]表，改以[ADDR_CODES]和[ADDR_BELONGS_DATA]查得資料。*/
        /*
        $row = AddressCode::find($id);
        $belongs = $row->belongs1_Name." ".$row->belongs2_Name." ".$row->belongs3_Name." ".$row->belongs4_Name." ".$row->belongs5_Name;
        return $row->c_addr_id.' '.$row->c_name.' '.$row->c_name_chn.' '.trim($belongs);
        */
        $row = AddrCode::find($id);
        $belongs = "";
        $originalText = $row->c_addr_id." ".$row->c_name." ".$row->c_name_chn." ".trim($belongs)." ".$row->c_firstyear."~".$row->c_lastyear;
        $add = "";
        $dy = AddrBelong::where('c_addr_id', $row->c_addr_id)->value('c_belongs_to');
        $dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
        if ($dy == null) {
            $dy = 0;
            $add = "";
        } else {
            $dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
            $add = "[[".$dy." ".$dy2."]]";
        }

        return $originalText." ".$add;
    }

    /**
     * 公開包裝：v2 PossessionMutationHandler 於同交易內同步 POSSESSION_ADDR 副表
     * （以 c_possession_record_id 刪除既有列後重插整組；-999→0）。create 路徑沿用 possessionStoreById。
     */
    public function syncPossessionAddresses(array $c_addr_id, $c_possession_record_id, $c_personid): void {
        $this->insertAddrPo($c_addr_id, $c_possession_record_id, $c_personid);
    }

    protected function insertAddrPo(array $c_addr_id, $c_possession_record_id, $c_personid) {
        DB::table('POSSESSION_ADDR')->where('c_possession_record_id', $c_possession_record_id)->delete();
        foreach ($c_addr_id as $item) {
            DB::table('POSSESSION_ADDR')->insert(
                [
                    'c_personid' => $c_personid,
                    'c_possession_record_id' => $c_possession_record_id,
                    'c_addr_id' => $item == -999 ? 0 : $item,
                ]
            );
        }
    }

    protected function formatSelect(array $array) {
        foreach ($array as $key => $value) {
            if ($value == -999) {
                $array[$key] = 0;
            }
        }

        return $array;
    }

    //20200709聯合主鍵保留字弱點防禦函式
    public function unionPKDef($key) {
        $key = str_replace("/", "(slash)", $key);
        //因為反斜線在php有用途, 兩個反斜線代表一個反斜線.
        $key = str_replace("\\", "(backslash)", $key);
        $key = str_replace("{", "(brackets)", $key);
        $key = str_replace("}", "(brackets_r)", $key);
        // URL 特殊字符處理：? 會被解析為查詢字符串開始，# 會被解析為錨點，& 會被解析為參數分隔符
        $key = str_replace("?", "(question)", $key);
        $key = str_replace("#", "(hash)", $key);
        $key = str_replace("&", "(amp)", $key);
        // 複合主鍵分隔符處理：- 是複合主鍵的分隔符，必須編碼以避免解析錯誤
        $key = str_replace("-", "(minus)", $key);
        $result = $key;

        return $result;
    }

    //20200709欄位值解析保留字
    public function unionPKDef_decode($key) {
        $key = str_replace("(slash)", "/", $key);
        $key = str_replace("(backslash)", "\\", $key);
        $key = str_replace("(brackets)", "{", $key);
        $key = str_replace("(brackets_r)", "}", $key);
        // URL 特殊字符處理
        $key = str_replace("(question)", "?", $key);
        $key = str_replace("(hash)", "#", $key);
        $key = str_replace("(amp)", "&", $key);
        // 複合主鍵分隔符處理
        $key = str_replace("(minus)", "-", $key);
        $result = $key;

        return $result;
    }

    protected function sourceQueryFromLegacyId(array $temp_l) {
        $query = DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
        ]);

        $cPages = $this->normalizeLegacySourcePages($temp_l[2] ?? '');

        if ($cPages === null) {
            return $query->whereNull('c_pages');
        }

        return $query->where('c_pages', '=', $cPages);
    }

    protected function normalizeLegacySourcePages($value): ?string {
        return $value === 'NULL' ? null : (string) $value;
    }

    //20230628觸發「自動生成」功能
    public function auto_pinyin($data) {
        $c_surname_chn = $c_surname = $c_mingzi_chn = $c_mingzi = $c_name = '';
        $name = trim((string) ($data['c_name_chn'] ?? ''));
        if ($name === '') {
            $data['c_surname_chn'] = '';
            $data['c_surname'] = '';
            $data['c_mingzi_chn'] = '';
            $data['c_mingzi'] = '';
            $data['c_name'] = '';

            return $data;
        }

        $normalizedNameForLookup = VariantCharNormalizer::normalize($name);
        $len = mb_strlen($normalizedNameForLookup, 'utf-8');
        $prefixes = [];
        for ($i = $len; $i >= 1; $i--) {
            $prefixes[] = mb_substr($normalizedNameForLookup, 0, $i, 'utf-8');
        }

        $surnameRows = DB::table('pinyin')
            ->select('lastname_chn', 'lastname_pinyin')
            ->whereIn('lastname_chn', $prefixes)
            ->get()
            ->keyBy('lastname_chn');

        // 以最長前綴優先，盡量命中已知姓氏（包含複姓）
        foreach ($prefixes as $prefix) {
            $row = $surnameRows->get($prefix);
            if (!empty($row?->lastname_pinyin)) {
                $c_surname_chn = $prefix;
                $c_surname = (string) $row->lastname_pinyin;

                break;
            }
        }

        if ($c_surname_chn != '') {
            $surnameLength = mb_strlen($c_surname_chn, 'utf-8');
            $c_surname_chn = mb_substr($name, 0, $surnameLength, 'utf-8');
            $c_mingzi_chn = mb_substr($name, $surnameLength, null, 'utf-8');
            // 標準化異體字（僅用於拼音轉換，不修改原始名字）
            $normalizedMingzi = VariantCharNormalizer::normalize($c_mingzi_chn);
            $c_mingzi = ucfirst(Pinyin::getPinyin($normalizedMingzi)) ?? '';
            $c_name = trim($c_surname.' '.$c_mingzi);
            $data['c_surname_chn'] = $c_surname_chn;
            $data['c_surname'] = $c_surname;
            $data['c_mingzi_chn'] = $c_mingzi_chn;
            $data['c_mingzi'] = $c_mingzi;
            $data['c_name'] = $c_name;
        } else {
            $c_mingzi_chn = $name;
            // 標準化異體字（僅用於拼音轉換，不修改原始名字）
            $normalizedMingzi = VariantCharNormalizer::normalize($c_mingzi_chn);
            $c_mingzi = ucfirst(Pinyin::getPinyin($normalizedMingzi)) ?? '';
            $c_name = $c_mingzi;
            $data['c_surname_chn'] = '';
            $data['c_surname'] = '';
            $data['c_mingzi_chn'] = $c_mingzi_chn;
            $data['c_mingzi'] = $c_mingzi;
            $data['c_name'] = $c_name;
        }

        return $data;
    }
}
