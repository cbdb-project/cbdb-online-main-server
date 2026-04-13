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

    public function kinshipUpdateById(Request $request, $id, $id_) {
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

        DB::transaction(function () use (&$ori_data, &$sumCount, $id, $id_, $temp_l, $row, $data, $kin_pair, $kin_id, $c_autogen_notes, $old_kin_id, $old_kin_code, $auditLog) {
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
            $kin_code_pair = KinshipCode::find($old_kin_code);
            $sumQuery = DB::table('KIN_DATA')->where(function ($query) use ($id, $old_kin_id, $c_autogen_notes, $kin_code_pair) {
                $query->where('c_kin_id', $id)
                    ->where('c_personid', $old_kin_id)
                    ->where('c_autogen_notes', $c_autogen_notes)
                    ->where('c_kin_code', $kin_code_pair->c_kin_pair1);
            });

            if (!empty($kin_code_pair->c_kin_pair2)) {
                $sumQuery->orWhere(function ($query) use ($id, $old_kin_id, $c_autogen_notes, $kin_code_pair) {
                    $query->where('c_kin_id', $id)
                        ->where('c_personid', $old_kin_id)
                        ->where('c_autogen_notes', $c_autogen_notes)
                        ->where('c_kin_code', $kin_code_pair->c_kin_pair2);
                });
            }

            $sum = $sumQuery->get();
            $sumCount = count($sum);
            if ($sumCount == 1) {
                $updateQuery = DB::table('KIN_DATA')->where(function ($query) use ($id, $old_kin_id, $c_autogen_notes, $kin_code_pair) {
                    $query->where('c_kin_id', $id)
                        ->where('c_personid', $old_kin_id)
                        ->where('c_autogen_notes', $c_autogen_notes)
                        ->where('c_kin_code', $kin_code_pair->c_kin_pair1);
                });

                if (!empty($kin_code_pair->c_kin_pair2)) {
                    $updateQuery->orWhere(function ($query) use ($id, $old_kin_id, $c_autogen_notes, $kin_code_pair) {
                        $query->where('c_kin_id', $id)
                            ->where('c_personid', $old_kin_id)
                            ->where('c_autogen_notes', $c_autogen_notes)
                            ->where('c_kin_code', $kin_code_pair->c_kin_pair2);
                    });
                }

                $mirroredRows = (clone $updateQuery)->get();
                $updateQuery->update($data_mirror);
            } else {
                $updateQuery = DB::table('KIN_DATA')->where([['c_kin_id',$id], ['c_personid', $old_kin_id], ['c_autogen_notes', $c_autogen_notes]]);
                $mirroredRows = (clone $updateQuery)->get();
                $updateQuery->update($data_mirror);
            }

            foreach ($mirroredRows as $mirroredRow) {
                $oldMirroredData = $auditLog->normalizeRow($mirroredRow);
                $newMirroredData = array_merge($oldMirroredData, $data_mirror);

                $auditLog->write(
                    'KIN_DATA',
                    'UPDATE',
                    $auditLog->buildRowPkFromData('KIN_DATA', $newMirroredData),
                    $oldMirroredData,
                    $newMirroredData,
                    'user',
                    (string) Auth::id(),
                    $operationId
                );
            }
        });

        $ori_data['err'] = $sumCount;

        return $ori_data;
    }

    public function kinshipStoreById(Request $request, $id) {
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

        DB::transaction(function () use ($id, $data, $kin_pair, $auditLog, &$ori_Data) {
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
            #20240710修正對應親屬的查詢方式，依據KINSHIP_CODES的c_kin_pair1和c_kin_pair2查詢
            $old_kin_code = $row->c_kin_code;
            $kin_code_pair = KinshipCode::find($old_kin_code);

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

            $row2Query = DB::table('KIN_DATA')->where(function ($query) use ($row, $kin_code_pair) {
                $query->where('c_kin_id', $row->c_personid)
                    ->where('c_personid', $row->c_kin_id)
                    ->where('c_autogen_notes', $row->c_autogen_notes)
                    ->where('c_kin_code', $kin_code_pair->c_kin_pair1);
            });

            if (!empty($kin_code_pair->c_kin_pair2)) {
                $row2Query->orWhere(function ($query) use ($row, $kin_code_pair) {
                    $query->where('c_kin_id', $row->c_personid)
                        ->where('c_personid', $row->c_kin_id)
                        ->where('c_autogen_notes', $row->c_autogen_notes)
                        ->where('c_kin_code', $kin_code_pair->c_kin_pair2);
                });
            }

            $row2 = $row2Query->first();

            DB::table('KIN_DATA')->where([
                ['c_personid', '=', $temp_l[0]],
                ['c_kin_id', '=', $temp_l[1]],
                ['c_kin_code', '=', $temp_l[2]],
            ])->delete();

            //先檢查$row2是否存在，再檢查$row2->c_modified_date是否為null，依照c_kin_id, c_personid, c_source, c_created_date, c_modified_date查詢後進行刪除反向關係。
            if ($row2 !== null && is_null($row2->c_modified_date)) {
                $deleteQuery = DB::table('KIN_DATA')->where(function ($query) use ($row2, $kin_code_pair) {
                    $query->where('c_kin_id', $row2->c_kin_id)
                        ->where('c_personid', $row2->c_personid)
                        ->where('c_source', $row2->c_source)
                        ->where('c_autogen_notes', $row2->c_autogen_notes)
                        ->where('c_kin_code', $kin_code_pair->c_kin_pair1);
                });

                if (!empty($kin_code_pair->c_kin_pair2)) {
                    $deleteQuery->orWhere(function ($query) use ($row2, $kin_code_pair) {
                        $query->where('c_kin_id', $row2->c_kin_id)
                            ->where('c_personid', $row2->c_personid)
                            ->where('c_source', $row2->c_source)
                            ->where('c_autogen_notes', $row2->c_autogen_notes)
                            ->where('c_kin_code', $kin_code_pair->c_kin_pair2);
                    });
                }

                $mirroredRows = (clone $deleteQuery)->get();
                $deleteQuery->delete();
            } elseif ($row2 !== null) {
                $deleteQuery = DB::table('KIN_DATA')->where(function ($query) use ($row2, $kin_code_pair) {
                    $query->where('c_kin_id', $row2->c_kin_id)
                        ->where('c_personid', $row2->c_personid)
                        ->where('c_source', $row2->c_source)
                        ->where('c_autogen_notes', $row2->c_autogen_notes)
                        ->where('c_kin_code', $kin_code_pair->c_kin_pair1)
                        ->where('c_modified_date', $row2->c_modified_date);
                });

                if (!empty($kin_code_pair->c_kin_pair2)) {
                    $deleteQuery->orWhere(function ($query) use ($row2, $kin_code_pair) {
                        $query->where('c_kin_id', $row2->c_kin_id)
                            ->where('c_personid', $row2->c_personid)
                            ->where('c_source', $row2->c_source)
                            ->where('c_autogen_notes', $row2->c_autogen_notes)
                            ->where('c_kin_code', $kin_code_pair->c_kin_pair2)
                            ->where('c_modified_date', $row2->c_modified_date);
                    });
                }

                $mirroredRows = (clone $deleteQuery)->get();
                $deleteQuery->delete();
            } else {
                $mirroredRows = collect();
            }

            foreach ($mirroredRows as $mirroredRow) {
                $mirroredRowData = $auditLog->normalizeRow($mirroredRow);
                $auditLog->write(
                    'KIN_DATA',
                    'DELETE',
                    $auditLog->buildRowPkFromData('KIN_DATA', $mirroredRowData),
                    $mirroredRowData,
                    null,
                    'user',
                    (string) Auth::id(),
                    $operationId
                );
            }
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
        $c_addr_id = $data['c_addr_id'];
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment', 'c_addr_id']);
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data);
        $ori = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id_)->first();
        if (!$ori) {
            return;
        }

        DB::transaction(function () use ($id, $id_, $data, $c_addr_id, $ori) {
            $this->insertAddrPo($c_addr_id, $id_, $id);
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
        $data['c_possession_record_id'] = DB::table('POSSESSION_DATA')->max('c_possession_record_id') + 1;
        $data['c_personid'] = $id;
        $addr = $data['c_addr_id'];
        $data = Arr::except($data, ['_token', 'action', '__proposal_comment', 'c_addr_id']);
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data, true);

        DB::transaction(function () use ($id, $data, $addr) {
            DB::table('POSSESSION_DATA')->insert($data);
            $this->insertAddrPo($addr, $data['c_possession_record_id'], $data['c_personid']);
            $operation = (new OperationRepository())->store(Auth::id(), $id, 1, 'POSSESSION_DATA', $data['c_possession_record_id'], $data);
            (new AuditLogService())->write(
                'POSSESSION_DATA',
                'INSERT',
                ['c_possession_record_id' => $data['c_possession_record_id']],
                null,
                $data,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });

        return $data['c_possession_record_id'];
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

    public function assocUpdateById(Request $request, $id, $c_personid) {
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

        return $this->assocPerformUpdate($request, $whereConditions, $row, $c_personid);
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
    private function assocPerformUpdate(Request $request, array $whereConditions, $row, $c_personid) {
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

        DB::transaction(function () use (&$ori_data, $c_personid, $whereConditions, $row, $data, $kin_pair, $assoc_kin_pair, $assoc_pair, $assoc_id, $old_assoc_id, $old_c_text_title, $old_c_assoc_first_year, $old_c_assocship_pair1, $old_c_assocship_pair2, $auditLog) {
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
            $data_mirror = Arr::except($data_mirror, ['c_assoc_id']);

            $mirrorQuery = DB::table('ASSOC_DATA')->where([
                ['c_assoc_id', '=', $c_personid],
                ['c_personid', '=', $old_assoc_id],
                ['c_text_title', '=', $old_c_text_title],
                ['c_assoc_first_year', '=', $old_c_assoc_first_year],
            ])
            ->where(function ($query) use ($old_c_assocship_pair1, $old_c_assocship_pair2) {
                if ($old_c_assocship_pair1 !== null) {
                    $query->where('c_assoc_code', '=', $old_c_assocship_pair1);
                }
                if ($old_c_assocship_pair2 !== null) {
                    $query->orWhere('c_assoc_code', '=', $old_c_assocship_pair2);
                }
            });

            $mirroredRows = (clone $mirrorQuery)->get();
            $mirrorQuery->update($data_mirror);

            foreach ($mirroredRows as $mirroredRow) {
                $oldMirroredData = $auditLog->normalizeRow($mirroredRow);
                $newMirroredData = array_merge($oldMirroredData, $data_mirror);

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
        });

        return $ori_data;
    }

    public function assocStoreById(Request $request, $id) {
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

        DB::transaction(function () use ($id, $data, $assoc_pair, $kin_pair, $assoc_kin_pair, &$ori_Data, $auditLog) {
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
            // 修正：查找配對記錄時使用多層次策略
            // 1. 如果 c_kin_id 和 c_assoc_kin_id 都是 0，反向記錄也應該是 0（對稱情況）
            // 2. 否則使用 paired c_assoc_code 來精確匹配
            // 3. 如果沒有配對映射且非 0,0 情況，為避免誤刪不嘗試反向刪除
            $row2 = null;
            $reverseDeleteSkipReason = null;

            // 策略 1：如果 c_kin_id = 0 且 c_assoc_kin_id = 0，直接用這些值匹配反向記錄
            if ($row->c_kin_id == 0 && $row->c_assoc_kin_id == 0) {
                $row2 = DB::table('ASSOC_DATA')->where([
                    ['c_personid', $row->c_assoc_id],
                    ['c_assoc_id', $row->c_personid],
                    ['c_kin_id', 0],
                    ['c_assoc_kin_id', 0],
                    ['c_text_title', $row->c_text_title],
                    ['c_assoc_first_year', $row->c_assoc_first_year],
                ])->first();
            }

            // 策略 2：如果策略 1 沒找到，使用 paired c_assoc_code 匹配
            if (!$row2) {
                $assocCodePair = AssocCode::where('c_assoc_code', '=', $row->c_assoc_code)->first();
                $assocPair1 = $assocCodePair?->c_assoc_pair;
                $assocPair2 = $assocCodePair?->c_assoc_pair2;

                if ($assocPair1 !== null || $assocPair2 !== null) {
                    $row2Query = DB::table('ASSOC_DATA')->where([
                        ['c_personid', $row->c_assoc_id],
                        ['c_assoc_id', $row->c_personid],
                        ['c_text_title', $row->c_text_title],
                        ['c_assoc_first_year', $row->c_assoc_first_year],
                    ]);
                    $row2Query->where(function ($query) use ($assocPair1, $assocPair2) {
                        if ($assocPair1 !== null) {
                            $query->where('c_assoc_code', '=', $assocPair1);
                        }
                        if ($assocPair2 !== null) {
                            $query->orWhere('c_assoc_code', '=', $assocPair2);
                        }
                    });
                    $row2 = $row2Query->first();
                } else {
                    $reverseDeleteSkipReason = 'no_pair_mapping';
                }
            }

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

            // 檢查$row2是否存在後再刪除反向關係
            if ($row2 !== null) {
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
            } elseif ($reverseDeleteSkipReason !== null) {
                Log::info('[ASSOC_DATA] 跳過反向記錄刪除', [
                    'reason' => $reverseDeleteSkipReason,
                    'c_personid' => $row->c_personid,
                    'c_assoc_id' => $row->c_assoc_id,
                    'c_assoc_code' => $row->c_assoc_code,
                    'c_kin_id' => $row->c_kin_id,
                    'c_assoc_kin_id' => $row->c_assoc_kin_id,
                ]);
            }
        });
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
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment', 'c_personid', 'c_addr_id']);
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
            $newAddrType = $data['c_addr_type'] ?? $ori->c_addr_type;
            $newSequence = $data['c_sequence'] ?? $ori->c_sequence;
            $pkChanged = (string) $newAddrType !== (string) $ori->c_addr_type
                      || (string) $newSequence !== (string) $ori->c_sequence;

            if ($pkChanged) {
                $conflict = DB::table('BIOG_ADDR_DATA')->where([
                    ['c_personid', '=', $addr_l[0]],
                    ['c_addr_id', '=', $addr_l[1]],
                    ['c_addr_type', '=', $newAddrType],
                    ['c_sequence', '=', $newSequence],
                ])->exists();

                if ($conflict) {
                    throw new \InvalidArgumentException(
                        '目標地址類別與遷徙次序的組合已存在，請使用不同的值。'
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
