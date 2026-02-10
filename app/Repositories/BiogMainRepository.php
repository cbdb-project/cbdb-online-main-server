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
use App\Models\EventCode;
use App\Models\KinshipCode;
use App\Models\OfficeCode;
use App\Models\Pinyin;
use App\Models\SocialInst;
use App\Models\SocialInstAddr;
use App\Models\SocialInstCode;
use App\Models\StatusCode;
//20181112建安修改
use App\Models\TextCode;
use App\Repositories\Concerns\DetectsModelChanges;
//修改結束

//20210625建安修改
use App\Services\AuditLogService;
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
use Illuminate\Validation\ValidationException;

//修改結束

ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);

/**
 * Class BiogMainRepository
 * @package App\Repositories
 */
class BiogMainRepository {
    use DetectsModelChanges;

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
        $data['c_female'] = (int)($data['c_female']);
        $data['c_by_intercalary'] = (int)($data['c_by_intercalary']);
        $data['c_dy_intercalary'] = (int)($data['c_dy_intercalary']);

        $biogbasicinformation = BiogMain::find($id);
        $ori = $biogbasicinformation->toArray();

        // 移除 Laravel 框架欄位，避免誤判為有變更
        $dataForComparison = array_diff_key($data, array_flip(['_method', '_token', '_wysihtml5_mode']));

        // 檢查是否有實質變更
        $hasChanges = $this->hasMeaningfulChanges($dataForComparison, $ori, ['c_modified_by', 'c_modified_date', 'c_created_by', 'c_created_date']);

        if (!$hasChanges) {
            return [
                'no_changes' => true,
            ];
        }

        $data = (new ToolsRepository())->timestamp($data);

        //20190531判別是否為眾包用戶
        if (Auth::user()->isCrowdsourcingUser()) {
            (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_MAIN', $biogbasicinformation->c_personid, $data, $ori, 2);
        } else {
            DB::transaction(function () use ($data, $id, $biogbasicinformation, $ori) {
                $biogbasicinformation->update($data);
                $operation = (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_MAIN', $biogbasicinformation->c_personid, $data, $ori);

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
        ];
    }

    /**
     * @param $request
     * @param $num
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public static function namesByQuery(Request $request, $num = 20) {
        //20220303增加addslashes()防禦查詢參數
        $request->q = addslashes($request->q);
        if ($temp = $request->num) {
            $num = addslashes($temp);
        }
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
                ->where('BIOG_MAIN.c_personid', '=', $request->q)
                ->groupBy('BIOG_MAIN.c_personid')
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
                ->whereIn('BIOG_MAIN.c_personid', $personIds)
                ->groupBy('BIOG_MAIN.c_personid');

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

    public function textUpdateById() {

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
        $data = $request->all();
        $_id = $data['_id'];
        $_postingid = $data['_postingid'];
        $_officeid = $data['_officeid']; //目前与officeid无关

        // 檢查地址欄位：
        // - c_addr 存在時：使用傳入的值
        // - c_addr 不存在但 c_addr_cleared == '1'：用戶清除了所有地址（視為空陣列）
        // - 兩者都不存在：用戶沒有修改地址（視為 null）
        $incomingAddr = array_key_exists('c_addr', $data)
            ? (array)$data['c_addr']
            : (($data['c_addr_cleared'] ?? '0') === '1' ? [] : null);

        $oriRecord = DB::table('POSTED_TO_OFFICE_DATA')->where([['c_office_id' , '=', $_officeid], ['c_posting_id' , '=', $_postingid]])->first();
        $ori = $oriRecord ? json_decode(json_encode($oriRecord), true) : [];

        $addressCollection = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $_id)
            ->where('c_posting_id', $_postingid)
            ->pluck('c_addr_id');

        $existingAddresses = $addressCollection ? $addressCollection->all() : [];
        // 地址變更檢測邏輯：
        // - $incomingAddr === null: 用戶沒有修改地址（表單未傳送 c_addr）
        // - $incomingAddr === []: 用戶明確清除所有地址（表單傳送空 c_addr）
        // - $incomingAddr 有值: 用戶修改了地址列表
        $hasAddressChange = $incomingAddr !== null
            && $this->selectionListHasChanges($incomingAddr, $existingAddresses, -999);

        $data = Arr::except($data, ['_method', '_token', 'c_addr', 'c_addr_cleared', '_id', '_postingid', '_officeid', 'ai_fill_log_id']);
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);
        $data['c_office_id'] = $data['c_office_id'] == -999 ? '0' : $data['c_office_id'];
        //$data['c_inst_code'] = $data['c_inst_code'] == -999 ? '0' : $data['c_inst_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];

        $hasPostingChange = $this->hasMeaningfulChanges($data, $ori, ['c_modified_by', 'c_modified_date']);

        if (!$hasPostingChange && !$hasAddressChange) {
            return [
                'id' => CompositePrimaryKey::buildStoredResourceId([
                    'c_office_id' => $_officeid,
                    'c_posting_id' => $_postingid,
                ]),
                'no_changes' => true,
            ];
        }

        return DB::transaction(function () use ($data, $ori, $c_personid, $_officeid, $_postingid, $hasPostingChange, $hasAddressChange, $incomingAddr, $_id, $existingAddresses) {
            $auditLog = new AuditLogService();
            $previousOfficeId = (int) ($ori['c_office_id'] ?? $_officeid);
            $currentOfficeId = $previousOfficeId;
            if ($hasPostingChange) {
                $timestamped = (new ToolsRepository())->timestamp($data);

                DB::table('POSTED_TO_OFFICE_DATA')
                    ->where([['c_office_id' , '=', $_officeid], ['c_posting_id' , '=', $_postingid]])
                    ->update($timestamped);

                $currentOfficeId = (int) ($timestamped['c_office_id'] ?? $currentOfficeId);

                $officeOperation = (new OperationRepository())->store(
                    Auth::id(),
                    $c_personid,
                    3,
                    'POSTED_TO_OFFICE_DATA',
                    CompositePrimaryKey::buildStoredResourceId([
                        'c_office_id' => $currentOfficeId,
                        'c_posting_id' => $_postingid,
                    ]),
                    $timestamped,
                    $ori
                );

                $updatedOfficeRow = DB::table('POSTED_TO_OFFICE_DATA')
                    ->where([['c_office_id' , '=', $currentOfficeId], ['c_posting_id' , '=', $_postingid]])
                    ->first();
                $updatedOfficeData = $auditLog->normalizeRow($updatedOfficeRow);
                $officeRowPk = $auditLog->buildRowPkFromData('POSTED_TO_OFFICE_DATA', $updatedOfficeData);

                $auditLog->logChange(
                    'POSTED_TO_OFFICE_DATA',
                    'UPDATE',
                    $officeRowPk,
                    $ori,
                    $updatedOfficeData,
                    $officeOperation ? (string) $officeOperation->id : null
                );
            }

            $shouldUpdateAddress = $hasAddressChange || $previousOfficeId !== $currentOfficeId;

            //20251204 issues-487，用戶在「修改」頁面保存時，若地名資訊（POSTED_TO_ADDR_DATA.c_addr_id）並沒有改變，用戶在保存的時候，則不會修改 POSTED_TO_ADDR_DATA 的 c_modified_by, c_modified_date 欄位。
            //有修改地名資訊時$shouldUpdateAddress = true
            //dd($shouldUpdateAddress);

            $c_created_by = Auth::user()->name;
            $c_created_date = Carbon::now();

            DB::table('POSTING_DATA')->where([
                    ['c_personid', '=', $_id],
                    ['c_posting_id', '=', $_postingid],
                ])->update(['c_modified_by' => $c_created_by, 'c_modified_date' => $c_created_date]);

            $addrBeforeAuditRows = [];
            if ($shouldUpdateAddress) {
                $addrBeforeAuditRows = DB::table('POSTED_TO_ADDR_DATA')
                    ->where('c_personid', $_id)
                    ->where('c_posting_id', $_postingid)
                    ->get();

                $beforeRows = DB::table('POSTED_TO_ADDR_DATA')
                    ->where('c_personid', $_id)
                    ->where('c_posting_id', $_postingid)
                    ->where('c_office_id', $previousOfficeId)
                    ->get()
                    ->map(function ($row) {
                        return [
                            'c_personid' => (int) $row->c_personid,
                            'c_posting_id' => (int) $row->c_posting_id,
                            'c_office_id' => (int) $row->c_office_id,
                            'c_addr_id' => (int) $row->c_addr_id,
                        ];
                    })
            ->all();

                //dd($previousOfficeId, $currentOfficeId, $beforeRows);

                // 先計算最終要保留的地址列表（用於衝突檢測和後續操作）
                // - $incomingAddr === null: 保留現有地址（用戶沒有修改）
                // - $incomingAddr 有值（包含空陣列）: 使用用戶指定的地址列表
                // 將 -999 正規化為 0（-999 是表單中「未選擇」的 sentinel 值，0 代表「未詳」地址）
                $sourceAddresses = $incomingAddr !== null ? $incomingAddr : $existingAddresses;
                $addressesForInsert = array_map(function ($v) {
                    $v = (int) $v;

                    return $v === -999 ? 0 : $v;
                }, $sourceAddresses);

                // 當 c_office_id 改變時，將現有地址記錄遷移到新的 office_id
                // 使用 UPDATE 而非 DELETE，避免地址記錄丟失
                if ($previousOfficeId !== $currentOfficeId && !empty($beforeRows)) {
                    // 檢查目標 office_id 下是否已存在「將保留的地址」記錄（主鍵衝突檢測）
                    // POSTED_TO_ADDR_DATA 主鍵為 (c_addr_id, c_office_id, c_posting_id)
                    // 只檢查「將保留/新增」的地址，允許用戶通過移除衝突地址來解決問題
                    // 這裡額外加上 c_personid 條件是為了縮小查詢範圍，確保只檢查同一人的記錄
                    // $addressesForInsert 已正規化（-999 → 0）
                    $addressesToKeep = $addressesForInsert;
                    if (!empty($addressesToKeep)) {
                        $conflictingRecords = DB::table('POSTED_TO_ADDR_DATA')
                            ->where('c_personid', $_id)
                            ->where('c_posting_id', $_postingid)
                            ->where('c_office_id', $currentOfficeId)
                            ->whereIn('c_addr_id', $addressesToKeep)
                            ->count();

                        if ($conflictingRecords > 0) {
                            throw ValidationException::withMessages([
                                'c_office_id' => "無法修改官名：目標官名（c_office_id={$currentOfficeId}）下已存在相同的地址記錄，" .
                                    '可能會導致數據衝突。請先檢查並處理 POSTED_TO_ADDR_DATA 表中的異常數據。',
                            ]);
                        }
                    }

                    // 遷移地址記錄到新的 office_id（只遷移將保留的地址）
                    // 將被移除的地址不需要遷移，會在後續的差異比對中被刪除
                    if (!empty($addressesToKeep)) {
                        DB::table('POSTED_TO_ADDR_DATA')
                            ->where('c_personid', $_id)
                            ->where('c_posting_id', $_postingid)
                            ->where('c_office_id', $previousOfficeId)
                            ->whereIn('c_addr_id', $addressesToKeep)
                            ->update([
                                'c_office_id' => $currentOfficeId,
                                'c_modified_by' => $c_created_by,
                                'c_modified_date' => $c_created_date,
                            ]);
                    }
                    // 更新 beforeRows 中已遷移地址的 c_office_id 以反映遷移後的狀態
                    $beforeRows = array_map(function ($row) use ($currentOfficeId, $addressesToKeep) {
                        if (in_array($row['c_addr_id'], $addressesToKeep)) {
                            $row['c_office_id'] = $currentOfficeId;
                        }

                        return $row;
                    }, $beforeRows);
                }

                //比對修改前後的Addr陣列，刪除更新時被移除的addr。
                $beforeAddressesForUpdate = [];
                $beforeRowsByAddrId = [];  // 用於記錄每個地址的 office_id
                foreach ($beforeRows as $addr_v) {
                    $beforeAddressesForUpdate[] = $addr_v['c_addr_id'];
                    $beforeRowsByAddrId[$addr_v['c_addr_id']] = $addr_v['c_office_id'];
                }
                //dd($beforeRows, $addressesForInsert);
                //dd($beforeAddressesForUpdate, $addressesForInsert);
                $oldHave_diff = array_diff($beforeAddressesForUpdate, $addressesForInsert);
                $newHave_diff = array_diff($addressesForInsert, $beforeAddressesForUpdate);
                //dd($oldHave_diff);
                //dd($newHave_diff);

                //比對結束，刪除更新時被移除的addr。
                // 使用 beforeRows 中記錄的 office_id，因為未遷移的地址仍在原 office_id 下
                foreach ($oldHave_diff as $addr_v) {
                    $addrOfficeId = $beforeRowsByAddrId[$addr_v] ?? $currentOfficeId;
                    DB::table('POSTED_TO_ADDR_DATA')
                        ->where('c_personid', $_id)
                        ->where('c_posting_id', $_postingid)
                        ->where('c_office_id', $addrOfficeId)
                        ->where('c_addr_id', $addr_v)
                        ->delete();
                }

                //比對結束，新增後來新加的addr。
                // 注意：使用 $currentOfficeId 確保新地址插入到正確的 office_id
                // $addr_v 已經正規化（-999 → 0），直接使用即可
                foreach ($newHave_diff as $addr_v) {
                    DB::table('POSTED_TO_ADDR_DATA')->insert([
                        'c_personid' => $_id,
                        'c_posting_id' => $_postingid,
                        'c_office_id' => $currentOfficeId,
                        'c_addr_id' => $addr_v,
                        'c_created_by' => $c_created_by,
                        'c_created_date' => $c_created_date,
                        'c_modified_by' => $c_created_by,
                        'c_modified_date' => $c_created_date,
                    ]);
                }


                $this->updateAddr($addressesForInsert, $_id, $_postingid, $currentOfficeId, $c_created_by, $c_created_date);

                $afterRows = DB::table('POSTED_TO_ADDR_DATA')
                    ->where('c_personid', $_id)
                    ->where('c_posting_id', $_postingid)
                    ->where('c_office_id', $currentOfficeId)
                    ->get()
                    ->map(function ($row) {
                        return [
                            'c_personid' => (int) $row->c_personid,
                            'c_posting_id' => (int) $row->c_posting_id,
                            'c_office_id' => (int) $row->c_office_id,
                            'c_addr_id' => (int) $row->c_addr_id,
                        ];
                    })
                    ->all();

                $addrResourceId = CompositePrimaryKey::buildStoredResourceId([
                    'c_office_id' => $currentOfficeId,
                    'c_posting_id' => $_postingid,
                ]);

                $addrOperation = (new OperationRepository())->store(
                    Auth::id(),
                    $c_personid,
                    3,
                    'POSTED_TO_ADDR_DATA',
                    $addrResourceId,
                    ['rows' => $afterRows],
                    ['rows' => $beforeRows]
                );

                $addrAfterAuditRows = DB::table('POSTED_TO_ADDR_DATA')
                    ->where('c_personid', $_id)
                    ->where('c_posting_id', $_postingid)
                    ->get();

                $beforeMap = [];
                foreach ($addrBeforeAuditRows as $row) {
                    $rowData = $auditLog->normalizeRow($row);
                    $rowPk = $auditLog->buildRowPkFromData('POSTED_TO_ADDR_DATA', $rowData);
                    $rowPkText = $auditLog->buildRowPkText('POSTED_TO_ADDR_DATA', $rowPk);
                    $beforeMap[$rowPkText] = ['pk' => $rowPk, 'row' => $rowData];
                }

                $afterMap = [];
                foreach ($addrAfterAuditRows as $row) {
                    $rowData = $auditLog->normalizeRow($row);
                    $rowPk = $auditLog->buildRowPkFromData('POSTED_TO_ADDR_DATA', $rowData);
                    $rowPkText = $auditLog->buildRowPkText('POSTED_TO_ADDR_DATA', $rowPk);
                    $afterMap[$rowPkText] = ['pk' => $rowPk, 'row' => $rowData];
                }

                $addrOperationId = $addrOperation ? (string) $addrOperation->id : null;
                $allKeys = array_unique(array_merge(array_keys($beforeMap), array_keys($afterMap)));
                foreach ($allKeys as $key) {
                    $beforeEntry = $beforeMap[$key] ?? null;
                    $afterEntry = $afterMap[$key] ?? null;

                    if ($beforeEntry && !$afterEntry) {
                        $auditLog->logChange(
                            'POSTED_TO_ADDR_DATA',
                            'DELETE',
                            $beforeEntry['pk'],
                            $beforeEntry['row'],
                            null,
                            $addrOperationId
                        );

                        continue;
                    }

                    if (!$beforeEntry && $afterEntry) {
                        $auditLog->logChange(
                            'POSTED_TO_ADDR_DATA',
                            'INSERT',
                            $afterEntry['pk'],
                            null,
                            $afterEntry['row'],
                            $addrOperationId
                        );

                        continue;
                    }

                    if ($beforeEntry && $afterEntry && $beforeEntry['row'] != $afterEntry['row']) {
                        $auditLog->logChange(
                            'POSTED_TO_ADDR_DATA',
                            'UPDATE',
                            $afterEntry['pk'],
                            $beforeEntry['row'],
                            $afterEntry['row'],
                            $addrOperationId
                        );
                    }
                }
            }

            $updateResourceId = CompositePrimaryKey::buildStoredResourceId([
                'c_office_id' => $currentOfficeId,
                'c_posting_id' => $_postingid,
            ]);

            return [
                'id' => $updateResourceId,
                'no_changes' => false,
            ];
        });
    }

    public function officeStoreById(Request $request, $id) {
        return DB::transaction(function () use ($request, $id) {
            $auditLog = new AuditLogService();
            $payload = $request->all();
            $c_addr = $payload['c_addr'] ?? [];
            $data = Arr::except($payload, ['_token', 'c_addr', 'c_addr_cleared', 'ai_fill_log_id']);

            $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
            $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);

            $lastPostingId = DB::table('POSTING_DATA')
                ->lockForUpdate()
                ->orderByDesc('c_posting_id')
                ->value('c_posting_id');
            $data['c_posting_id'] = ((int) $lastPostingId) + 1;
            $data['c_personid'] = $id;

            //將操作新增的使用者與當下時間紀錄為c_created_by與c_created_date。
            $c_created_by = Auth::user()->name;
            $c_created_date = Carbon::now();
            $data['c_created_by'] = $c_created_by;
            $data['c_created_date'] = $c_created_date;

            DB::table('POSTING_DATA')->insert([
                'c_personid' => $data['c_personid'],
                'c_posting_id' => $data['c_posting_id'],
                'c_created_by' => $data['c_created_by'],
                'c_created_date' => $data['c_created_date'],
            ]);

            $this->insertAddr($c_addr, $id, $data['c_posting_id'], $data['c_office_id'], $c_created_by, $c_created_date);

            $data = (new ToolsRepository())->timestamp($data, true);
            DB::table('POSTED_TO_OFFICE_DATA')->insert($data);

            $officeOperation = (new OperationRepository())->store(Auth::id(), $id, 1, 'POSTED_TO_OFFICE_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_office_id' => $data['c_office_id'],
                'c_posting_id' => $data['c_posting_id'],
            ]), $data);

            $officeRow = DB::table('POSTED_TO_OFFICE_DATA')->where([
                ['c_office_id', '=', $data['c_office_id']],
                ['c_posting_id', '=', $data['c_posting_id']],
            ])->first();
            $officeRowData = $auditLog->normalizeRow($officeRow);
            $officeRowPk = $auditLog->buildRowPkFromData('POSTED_TO_OFFICE_DATA', $officeRowData);
            $auditLog->logChange(
                'POSTED_TO_OFFICE_DATA',
                'INSERT',
                $officeRowPk,
                null,
                $officeRowData,
                $officeOperation ? (string) $officeOperation->id : null
            );

            $addressRows = DB::table('POSTED_TO_ADDR_DATA')
                ->where('c_personid', $id)
                ->where('c_posting_id', $data['c_posting_id'])
                ->where('c_office_id', $data['c_office_id'])
                ->get()
                ->map(function ($row) {
                    return [
                        'c_personid' => (int) $row->c_personid,
                        'c_posting_id' => (int) $row->c_posting_id,
                        'c_office_id' => (int) $row->c_office_id,
                        'c_addr_id' => (int) $row->c_addr_id,
                    ];
                })
                ->values()
                ->all();

            $officeResourceId = CompositePrimaryKey::buildStoredResourceId([
                'c_office_id' => $data['c_office_id'],
                'c_posting_id' => $data['c_posting_id'],
            ]);

            if (!empty($addressRows)) {
                $addrOperation = (new OperationRepository())->store(
                    Auth::id(),
                    $id,
                    1,
                    'POSTED_TO_ADDR_DATA',
                    $officeResourceId,
                    ['rows' => $addressRows]
                );

                $addrAuditRows = DB::table('POSTED_TO_ADDR_DATA')
                    ->where('c_personid', $id)
                    ->where('c_posting_id', $data['c_posting_id'])
                    ->where('c_office_id', $data['c_office_id'])
                    ->get();

                $addrOperationId = $addrOperation ? (string) $addrOperation->id : null;
                foreach ($addrAuditRows as $row) {
                    $rowData = $auditLog->normalizeRow($row);
                    $rowPk = $auditLog->buildRowPkFromData('POSTED_TO_ADDR_DATA', $rowData);
                    $auditLog->logChange(
                        'POSTED_TO_ADDR_DATA',
                        'INSERT',
                        $rowPk,
                        null,
                        $rowData,
                        $addrOperationId
                    );
                }
            }

            return $officeResourceId;
        });
    }

    public function officeDeleteById($id, $c_personid) {
        DB::transaction(function () use ($id, $c_personid) {
            $auditLog = new AuditLogService();
            $addr_l = explode('-', $id);
            $row = DB::table('POSTED_TO_OFFICE_DATA')
                ->where([['c_office_id' , '=', $addr_l[0]], ['c_posting_id' , '=', $addr_l[1]]])
                ->first();

            $addrRows = DB::table('POSTED_TO_ADDR_DATA')
                ->where('c_office_id', $addr_l[0])
                ->where('c_posting_id', $addr_l[1])
                ->get();

            DB::table('POSTED_TO_OFFICE_DATA')
                ->where([['c_office_id' , '=', $addr_l[0]], ['c_posting_id' , '=', $addr_l[1]]])
                ->delete();
            DB::table('POSTED_TO_ADDR_DATA')
                ->where('c_office_id', $addr_l[0])
                ->where('c_posting_id', $row->c_posting_id)
                ->delete();
            DB::table('POSTING_DATA')->where('c_posting_id', $row->c_posting_id)->delete();

            $officeOperation = (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'POSTED_TO_OFFICE_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_office_id' => $addr_l[0],
                'c_posting_id' => $addr_l[1],
            ]), $row);

            $officeRowData = $auditLog->normalizeRow($row);
            $officeRowPk = $auditLog->buildRowPkFromData('POSTED_TO_OFFICE_DATA', $officeRowData);
            $operationId = $officeOperation ? (string) $officeOperation->id : null;
            $auditLog->logChange(
                'POSTED_TO_OFFICE_DATA',
                'DELETE',
                $officeRowPk,
                $officeRowData,
                null,
                $operationId
            );

            foreach ($addrRows as $addrRow) {
                $addrRowData = $auditLog->normalizeRow($addrRow);
                $addrRowPk = $auditLog->buildRowPkFromData('POSTED_TO_ADDR_DATA', $addrRowData);
                $auditLog->logChange(
                    'POSTED_TO_ADDR_DATA',
                    'DELETE',
                    $addrRowPk,
                    $addrRowData,
                    null,
                    $operationId
                );
            }
        });
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
        $id = str_replace("--", "-minus", $id);
        $temp_l = explode("-", $id);
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus", "-", $value);
        }
        $row = DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->first();
        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        $statuse_str = null;
        if ($row->c_status_code || $row->c_status_code === 0) {
            $text_ = StatusCode::find($row->c_status_code);
            $statuse_str = $text_->c_status_code." ".$text_->c_status_desc_chn." ".$text_->c_status_desc;
        }

        return ['row' => $row, 'text_str' => $text_str, 'statuse_str' => $statuse_str];
    }

    public function statuseUpdateById(Request $request, $id, $c_personid) {
        $id = str_replace("--", "-minus", $id);
        $temp_l = explode("-", $id);
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus", "-", $value);
        }
        $row = DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->first();
        $data = $request->all();
        $data = Arr::except($data, ['_token', '_method']);
        $data['c_status_code'] = $data['c_status_code'] == -999 ? '0' : $data['c_status_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data);
        DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->update($data);
        (new OperationRepository())->store(Auth::id(), $c_personid, 3, 'STATUS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $c_personid,
            'c_sequence' => $data['c_sequence'],
            'c_status_code' => $data['c_status_code'],
        ]), $data, $row);

        return $data;
    }

    public function statuseStoreById(Request $request, $id) {
        $data = $request->all();
        $data = Arr::except($data, ['_token']);
        $data['c_personid'] = $id;
        $data['c_status_code'] = $data['c_status_code'] == -999 ? '0' : $data['c_status_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data, true);
        DB::table('STATUS_DATA')->insert($data);
        (new OperationRepository())->store(Auth::id(), $data['c_personid'], 1, 'STATUS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $data['c_personid'],
            'c_sequence' => $data['c_sequence'],
            'c_status_code' => $data['c_status_code'],
        ]), $data);

        return $data;
    }

    public function statuseDeleteById($id, $c_personid) {
        $id = str_replace("--", "-minus", $id);
        $temp_l = explode("-", $id);
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus", "-", $value);
        }
        $row = DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->first();
        DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->delete();
        (new OperationRepository())->store(Auth::id(), $id, 4, 'STATUS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $temp_l[0],
            'c_sequence' => $temp_l[1],
            'c_status_code' => $temp_l[2],
        ]), $row);
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
        $data = $request->all();
        $kin_pair = $data['c_kinship_pair'];
        $kin_id = $data['c_kin_id'];
        $c_autogen_notes = $row->c_autogen_notes;
        $old_kin_id = $row->c_kin_id;
        $old_kin_code = $row->c_kin_code;
        $data = Arr::except($data, ['_token', '_method', 'c_kinship_pair']);
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_kin_id'] = $data['c_kin_id'] == -999 ? '0' : $data['c_kin_id'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data);
        //dump($data);
        DB::table('KIN_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_kin_id', '=', $temp_l[1]],
            ['c_kin_code', '=', $temp_l[2]],
        ])->update($data);
        $ori_data = $data;
        (new OperationRepository())->store(Auth::id(), $id, 3, 'KIN_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $id,
            'c_kin_id' => $data['c_kin_id'],
            'c_kin_code' => $data['c_kin_code'],
        ]), $data, $row);
        $data['c_kin_code'] = $kin_pair;
        $data['c_personid'] = $kin_id;
        $data = Arr::except($data, ['c_kin_id']);
        //dump($data);
        //dd(DB::table('KIN_DATA')->where([['c_kin_id',$id], ['c_personid', $old_kin_id]])->first());
        #20240710修正對應親屬的查詢方式，依據KINSHIP_CODES的c_kin_pair1和c_kin_pair2查詢
        #20240710提前做一次查詢，檢查資料庫是否有0筆資料或多筆資料。
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
        if (count($sum) == 1) {
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

            $updateQuery->update($data);
        } else {
            DB::table('KIN_DATA')->where([['c_kin_id',$id], ['c_personid', $old_kin_id], ['c_autogen_notes', $c_autogen_notes]])->update($data);
        }
        $ori_data['err'] = count($sum) ?? 0;

        return $ori_data;
    }

    public function kinshipStoreById(Request $request, $id) {
        $data = $request->all();
        $kin_pair = $data['c_kinship_pair'];
        $data = Arr::except($data, ['_token', 'c_kinship_pair']);
        $data['c_personid'] = $id;
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_kin_id'] = $data['c_kin_id'] == -999 ? '0' : $data['c_kin_id'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data, true);
        DB::table('KIN_DATA')->insert($data);
        $ori_Data = $data;
        (new OperationRepository())->store(Auth::id(), $id, 1, 'KIN_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $data['c_personid'],
            'c_kin_id' => $data['c_kin_id'],
            'c_kin_code' => $data['c_kin_code'],
        ]), $data);
        $data['c_kin_code'] = $kin_pair;
        $data['c_personid'] = $data['c_kin_id'];
        $data['c_kin_id'] = $id;
        DB::table('KIN_DATA')->insert($data);

        //return $tts;
        return $ori_Data;
    }

    public function kinshipDeleteById($id, $id_) {
        $operationRepository = new OperationRepository();
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

        #20240710修正對應親屬的查詢方式，依據KINSHIP_CODES的c_kin_pair1和c_kin_pair2查詢
        $old_kin_code = $row->c_kin_code;
        $kin_code_pair = KinshipCode::find($old_kin_code);

        (new OperationRepository())->store(Auth::id(), $id, 4, 'KIN_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $temp_l[0],
            'c_kin_id' => $temp_l[1],
            'c_kin_code' => $temp_l[2],
        ]), $row);

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

            $deleteQuery->delete();
        }
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
        $this->insertAddrPo($data['c_addr_id'], $id_, $id);
        $data = Arr::except($data, ['_method', '_token', 'c_addr_id']);
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data);
        $ori = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id_)->first();
        DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id_)->update($data);
        (new OperationRepository())->store(Auth::id(), $id, 3, 'POSSESSION_DATA', $id_, $data, $ori);
    }

    public function possessionStoreById(Request $request, $id) {
        $data = $request->all();
        $data['c_possession_record_id'] = DB::table('POSSESSION_DATA')->max('c_possession_record_id') + 1;
        $data['c_personid'] = $id;
        //20210205因為資料表關聯欄位的設定，資料新增的流程需要往後移動。
        //$this->insertAddrPo($data['c_addr_id'], $data['c_possession_record_id'], $data['c_personid']);
        $addr = [];
        $addr = $data['c_addr_id'];
        //修改段落
        $data = Arr::except($data, ['_token', 'c_addr_id']);
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data, true);
        DB::table('POSSESSION_DATA')->insert($data);
        //移動到這裡
        $this->insertAddrPo($addr, $data['c_possession_record_id'], $data['c_personid']);
        //修改結束
        (new OperationRepository())->store(Auth::id(), $id, 1, 'POSSESSION_DATA', $data['c_possession_record_id'], $data);

        return $data['c_possession_record_id'];
    }

    public function possessionDeleteById($id, $c_personid) {
        $row = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id)->first();
        DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id)->delete();
        DB::table('POSSESSION_ADDR')->where('c_possession_record_id', $row->c_possession_record_id)->delete();
        (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'POSSESSION_DATA', $id, $row);
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
        $data = Arr::except($data, ['_token']);
        $data['c_source'] = ($data['c_source'] == -999) ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data, true);
        $tts = DB::table('BIOG_INST_DATA')->insertGetId($data);
        //新增的聯合主鍵 //20211022修改增加c_inst_code與c_inst_name_code
        $newid = CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $data['c_personid'],
            'c_inst_code' => $data['c_inst_code'],
            'c_inst_name_code' => $data['c_inst_name_code'],
            'c_bi_role_code' => $data['c_bi_role_code'],
        ]);
        (new OperationRepository())->store(Auth::id(), $id, 1, 'BIOG_INST_DATA', $newid, $data);

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

            if ($row) {
                DB::table('BIOG_INST_DATA')
                    ->where('c_personid', $addr_l[0])
                    ->where('c_inst_code', $addr_l[1])
                    ->where('c_inst_name_code', $addr_l[2])
                    ->where('c_bi_role_code', $addr_l[3])
                    ->delete();
            }
        }

        // 如果新格式找不到，嘗試舊版 2-field 格式（c_personid 和 c_bi_role_code）
        if (!$row && count($addr_l) >= 2) {
            $row = DB::table('BIOG_INST_DATA')
                ->where('c_personid', $addr_l[0])
                ->where('c_bi_role_code', $addr_l[1])
                ->first();

            if ($row) {
                DB::table('BIOG_INST_DATA')
                    ->where('c_personid', $addr_l[0])
                    ->where('c_bi_role_code', $addr_l[1])
                    ->delete();
            }
        }

        // 記錄操作（即使 $row 為 null 也記錄，以便追蹤失敗的刪除嘗試）
        $instResourceId = $row
            ? CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $row->c_personid,
                'c_inst_code' => $row->c_inst_code,
                'c_inst_name_code' => $row->c_inst_name_code,
                'c_bi_role_code' => $row->c_bi_role_code,
            ])
            : $id;
        (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'BIOG_INST_DATA', $instResourceId, $row);
    }

    public function eventById($id) {
        $id_arr = explode("-", $id);
        $query = DB::table('EVENTS_DATA')
            ->where('c_personid', $id_arr[0])
            ->where('c_sequence', $id_arr[1]);
        // 新格式包含 c_event_code（第 3 個字段），舊格式僅 c_personid-c_sequence
        if (isset($id_arr[2])) {
            $query->where('c_event_code', $id_arr[2]);
        }
        $row = $query->first();
        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }
        $addr_ = DB::table('EVENTS_ADDR')
            ->where('c_personid', $row->c_personid)
            ->where('c_sequence', $row->c_sequence)
            ->where('c_event_code', $row->c_event_code)
            ->get();
        $addr_str = [];
        foreach ($addr_ as $key => $value) {
            $id_ = $value->c_addr_id == 0 ? -999 : $value->c_addr_id;
            $item = [$id_, $this->addr_str($value->c_addr_id)];
            $addr_str[$key] = $item;
        }
        $event_str = null;
        if ($row->c_event_code || $row->c_event_code === 0) {
            $text_ = EventCode::find($row->c_event_code);
            $event_str = $text_->c_event_code." ".$text_->c_event_name_chn." ".$text_->c_event_name;
        }

        return ['row' => $row, 'text_str' => $text_str, 'addr_str' => $addr_str, 'event_str' => $event_str];
    }

    public function eventUpdateById(Request $request, $id, $id_) {
        // $id = c_personid, $id_ = "c_sequence-c_event_code"（新格式）或 "c_sequence"（舊格式）
        $parts = explode("-", (string) $id_);
        $c_sequence = $parts[0];
        $c_event_code = $parts[1] ?? null;

        $data = $request->all();
        $data = $this->formatSelect($data);

        // 先獲取原始記錄，用於刪除舊的 EVENTS_ADDR
        $oriQuery = DB::table('EVENTS_DATA')
            ->where('c_personid', $id)
            ->where('c_sequence', $c_sequence);
        if ($c_event_code !== null) {
            $oriQuery->where('c_event_code', $c_event_code);
        }
        $ori = $oriQuery->first();

        // 使用原始值刪除舊地址，再用新值插入新地址
        // 這樣即使用戶修改了 c_sequence 或 c_event_code，也不會留下孤兒記錄
        $this->updateAddrEvent(
            $data['c_addr_id'],
            $id,
            $ori->c_sequence,
            $ori->c_event_code,
            $data['c_sequence'],
            $data['c_event_code']
        );

        $data = Arr::except($data, ['_method', '_token', 'c_addr_id']);
        $data['c_intercalary'] = (int)($data['c_intercalary']);
        $data = (new ToolsRepository())->timestamp($data);
        $updateQuery = DB::table('EVENTS_DATA')
            ->where('c_personid', $id)
            ->where('c_sequence', $c_sequence);
        if ($c_event_code !== null) {
            $updateQuery->where('c_event_code', $c_event_code);
        }
        $updateQuery->update($data);

        (new OperationRepository())->store(Auth::id(), $id, 3, 'EVENTS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $id,
            'c_sequence' => $data['c_sequence'],
            'c_event_code' => $data['c_event_code'],
        ]), $data, $ori);

        return ['c_sequence' => $data['c_sequence'], 'c_event_code' => $data['c_event_code']];
    }

    public function eventStoreById(Request $request, $id) {
        $data = $request->all();
        $data = $this->formatSelect($data);
        $data['c_personid'] = $id;
        $this->insertAddrEvent($data['c_addr_id'], $id, $data['c_sequence'], $data['c_event_code']);
        $data = Arr::except($data, ['_token', 'c_addr_id']);
        $data['c_intercalary'] = (int)($data['c_intercalary']);
        $data = (new ToolsRepository())->timestamp($data, true);
        DB::table('EVENTS_DATA')->insert($data);

        (new OperationRepository())->store(Auth::id(), $id, 1, 'EVENTS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $id,
            'c_sequence' => $data['c_sequence'],
            'c_event_code' => $data['c_event_code'],
        ]), $data);

        return ['c_sequence' => $data['c_sequence'], 'c_event_code' => $data['c_event_code']];
    }

    public function eventDeleteById($id, $c_personid) {
        // $id = "c_sequence-c_event_code"（新格式）或 "c_sequence"（舊格式）
        $parts = explode("-", (string) $id);
        $c_sequence = $parts[0];
        $c_event_code = $parts[1] ?? null;

        $query = DB::table('EVENTS_DATA')
            ->where('c_personid', $c_personid)
            ->where('c_sequence', $c_sequence);
        if ($c_event_code !== null) {
            $query->where('c_event_code', $c_event_code);
        }
        $row = (clone $query)->first();
        $query->delete();

        DB::table('EVENTS_ADDR')
            ->where('c_personid', $row->c_personid)
            ->where('c_sequence', $row->c_sequence)
            ->where('c_event_code', $row->c_event_code)
            ->delete();

        (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'EVENTS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $c_personid,
            'c_sequence' => $row->c_sequence,
            'c_event_code' => $row->c_event_code,
        ]), $row);
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

    public function assocUpdateById(Request $request, $id, $c_personid) {
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
        $old_c_assocship_pair1 = $old_c_assocship_pair['c_assoc_pair'];
        $old_c_assocship_pair2 = $old_c_assocship_pair['c_assoc_pair2'];
        //20190118筆記 原程式移除c_assoc_id的值,當社會關係人修改時,資料就不能成對.
        //$data = Arr::except($data, ['_method', '_token', 'c_assocship_pair', 'c_assoc_id']);
        $data = Arr::except($data, ['_method', '_token', 'c_assocship_pair', 'c_kinship_pair', 'c_assoc_kinship_pair']);
        #20250411 ASSOC_DATA 表 c_assoc_year 欄位重構遮除c_assoc_intercalary(原本資料有null)
        #$data['c_assoc_intercalary'] = (int)($data['c_assoc_intercalary']);
        //20210204增加儲存c_inst_name_code
        //$data['c_inst_name_code'] = SocialInstCode::where('c_inst_code', $data['c_inst_code'])->first()->c_inst_name_code;
        //新增結束
        #20260126「出處」缺省值製作，若「出處」為空，則自動填充為[n/a]，避免複合主鍵 ID 中出現 -- 導致解析問題。
        if (empty($data['c_text_title'])) {
            $data['c_text_title'] = '[n/a]';
        }
        $data = (new ToolsRepository())->timestamp($data);
        DB::table('ASSOC_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_assoc_code', '=', $temp_l[1]],
            ['c_assoc_id', '=', $temp_l[2]],
            //20191028進行聯合主鍵的擴充修改
            ['c_kin_code', '=', $temp_l[3]],
            ['c_kin_id', '=', $temp_l[4]],
            ['c_assoc_kin_code', '=', $temp_l[5]],
            ['c_assoc_kin_id', '=', $temp_l[6]],
            ['c_text_title', '=', $c_text_title],
            ['c_assoc_first_year', '=', $c_assoc_first_year],
        ])->update($data);
        $ori_data = $data;
        $data['c_personid'] = $c_personid;
        // 修正：operation record ID 包含第 9 個欄位 c_assoc_first_year
        // 注意：c_assoc_first_year 可能包含負號（如 -9999），需要編碼為 (minus) 避免解析錯誤
        (new OperationRepository())->store(Auth::id(), $c_personid, 3, 'ASSOC_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $data['c_personid'],
            'c_assoc_code' => $data['c_assoc_code'],
            'c_assoc_id' => $data['c_assoc_id'],
            'c_kin_code' => $data['c_kin_code'],
            'c_kin_id' => $data['c_kin_id'],
            'c_assoc_kin_code' => $data['c_assoc_kin_code'],
            'c_assoc_kin_id' => $data['c_assoc_kin_id'],
            'c_text_title' => $data['c_text_title'] ?? '',
            'c_assoc_first_year' => $data['c_assoc_first_year'] ?? '-9999',
        ]), $data, $row);
        //20210702取得成對資料原本的c_kin_code
        $data['c_kin_code'] = $kin_pair;
        $data['c_assoc_kin_code'] = $assoc_kin_pair;
        //新增結束
        //20210708增加[親屬關係人]與[社會關係人親屬]的[姓名]欄位調整為對應關係
        $data['c_kin_id'] = $c_personid;
        $data['c_assoc_kin_id'] = $c_personid;
        //新增結束
        $data['c_assoc_code'] = $assoc_pair;
        $data['c_personid'] = $assoc_id;
        $data = Arr::except($data, ['c_assoc_id']);
        //20190118筆記 修改這邊的更新功能.
        //DB::table('ASSOC_DATA')->where([['c_assoc_id',$id], ['c_personid', $assoc_id]])->update($data);
        // 修正：更新配對記錄時需要包含 c_assoc_first_year，避免當存在相同 c_text_title 但不同 year 時更新錯誤的記錄
        DB::table('ASSOC_DATA')->where([
            ['c_assoc_id', '=', $c_personid],
            ['c_personid', '=', $old_assoc_id],
            ['c_text_title', '=', $old_c_text_title],
            ['c_assoc_first_year', '=', $old_c_assoc_first_year],
        ])
        ->where(function ($query) use ($old_c_assocship_pair1, $old_c_assocship_pair2) {
            $query->where('c_assoc_code', '=', $old_c_assocship_pair1)
                ->orWhere('c_assoc_code', '=', $old_c_assocship_pair2);
        })
        ->update($data);

        return $ori_data;
    }

    public function assocStoreById(Request $request, $id) {
        $data = $request->all();
        $data = $this->formatSelect($data);
        $assoc_pair = $data['c_assocship_pair'];
        $kin_pair = $data['c_kinship_pair'];
        $assoc_kin_pair = $data['c_assoc_kinship_pair'];
        $data['c_personid'] = $id;
        $data = Arr::except($data, ['_token', 'c_assocship_pair', 'c_kinship_pair', 'c_assoc_kinship_pair']);
        #20250411 ASSOC_DATA 表 c_assoc_year 欄位重構遮除c_assoc_intercalary(原本資料有null)
        #$data['c_assoc_intercalary'] = (int)($data['c_assoc_intercalary']);
        //20210204增加儲存c_inst_name_code
        //$data['c_inst_name_code'] = SocialInstCode::where('c_inst_code', $data['c_inst_code'])->first()->c_inst_name_code;
        //新增結束
        #20250417「社會關係始年」缺省值製作，若「社會關係始年」為空，則自動填充為-9999。
        if ($data['c_assoc_first_year'] == '') {  #這個判斷式只會將「社會關係始年」為空白時，填充為-9999，如果使用者填寫0，會維持0的值而不更動。
            $data['c_assoc_first_year'] = '-9999';
        }
        #20260126「出處」缺省值製作，若「出處」為空，則自動填充為[n/a]，避免複合主鍵 ID 中出現 -- 導致解析問題。
        if (empty($data['c_text_title'])) {
            $data['c_text_title'] = '[n/a]';
        }
        $data = (new ToolsRepository())->timestamp($data, true);
        DB::table('ASSOC_DATA')->insert($data);
        $ori_Data = $data;
        // 修正：operation record ID 包含第 9 個欄位 c_assoc_first_year
        // 注意：c_assoc_first_year 可能包含負號（如 -9999），需要編碼為 (minus) 避免解析錯誤
        (new OperationRepository())->store(Auth::id(), $id, 1, 'ASSOC_DATA', CompositePrimaryKey::buildStoredResourceId([
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
        $data['c_assoc_code'] = $assoc_pair;
        $data['c_personid'] = $data['c_assoc_id'];
        $data['c_assoc_id'] = $id;
        //20210702增加成對親屬關係
        $data['c_kin_code'] = $kin_pair;
        $data['c_assoc_kin_code'] = $assoc_kin_pair;
        //新增結束
        //20210708增加[親屬關係人]與[社會關係人親屬]的[姓名]欄位調整為對應關係
        $data['c_kin_id'] = $id;
        $data['c_assoc_kin_id'] = $id;
        //新增結束
        DB::table('ASSOC_DATA')->insert($data);

        return $ori_Data;
    }

    public function assocDeleteById($id, $c_personid) {
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
        // 修正：查找配對記錄時使用多層次策略
        // 1. 如果 c_kin_id 和 c_assoc_kin_id 都是 0，反向記錄也應該是 0（對稱情況）
        // 2. 否則使用 paired c_assoc_code 來精確匹配
        // 3. 如果沒有配對映射且非 0,0 情況，為避免誤刪不嘗試反向刪除
        $row2 = null;
        $reverseDeleteSkipReason = null;

        // 修正：必須檢查 $row 是否存在，記錄可能不存在（malformed ID 或已刪除）
        if ($row) {
            // 策略 1：如果 c_kin_id = 0 且 c_assoc_kin_id = 0，直接用這些值匹配反向記錄
            // 注意：此策略僅處理對稱情況，若原記錄非 0,0，則依賴策略 2 的配對代碼
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
                // 修正：AssocCode::first() 可能返回 null，需要先檢查
                $assocPair1 = $assocCodePair?->c_assoc_pair;
                $assocPair2 = $assocCodePair?->c_assoc_pair2;

                // 只有在有配對代碼時才嘗試查找反向記錄
                // 如果沒有配對映射，為避免誤刪錯誤的記錄，不執行反向刪除
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
                    // 記錄跳過原因：沒有配對代碼且非 0,0 情況
                    $reverseDeleteSkipReason = 'no_pair_mapping';
                }
            }
        } else {
            // 記錄跳過原因：原記錄不存在
            $reverseDeleteSkipReason = 'source_record_not_found';
        }
        DB::table('ASSOC_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_assoc_code', '=', $temp_l[1]],
            ['c_assoc_id', '=', $temp_l[2]],
            //20191028進行聯合主鍵的擴充修改
            ['c_kin_code', '=', $temp_l[3]],
            ['c_kin_id', '=', $temp_l[4]],
            ['c_assoc_kin_code', '=', $temp_l[5]],
            ['c_assoc_kin_id', '=', $temp_l[6]],
            ['c_text_title', '=', $c_text_title],
            ['c_assoc_first_year', '=', $c_assoc_first_year],
        ])->delete();
        $assocResourceId = $row
            ? CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $row->c_personid,
                'c_assoc_code' => $row->c_assoc_code,
                'c_assoc_id' => $row->c_assoc_id,
                'c_kin_code' => $row->c_kin_code,
                'c_kin_id' => $row->c_kin_id,
                'c_assoc_kin_code' => $row->c_assoc_kin_code,
                'c_assoc_kin_id' => $row->c_assoc_kin_id,
                'c_text_title' => $row->c_text_title ?? '',
                'c_assoc_first_year' => $row->c_assoc_first_year ?? '-9999',
            ])
            : $id;
        (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'ASSOC_DATA', $assocResourceId, $row);

        // 檢查$row2是否存在後再刪除反向關係
        // 修正：使用完整主鍵欄位進行刪除，避免誤刪其他記錄
        if ($row2 !== null) {
            DB::table('ASSOC_DATA')->where([
                ['c_personid', $row2->c_personid],
                ['c_assoc_id', $row2->c_assoc_id],
                ['c_assoc_code', $row2->c_assoc_code],
                ['c_kin_code', $row2->c_kin_code],
                ['c_kin_id', $row2->c_kin_id],
                ['c_assoc_kin_code', $row2->c_assoc_kin_code],
                ['c_assoc_kin_id', $row2->c_assoc_kin_id],
                ['c_text_title', $row2->c_text_title],
                ['c_assoc_first_year', $row2->c_assoc_first_year],
            ])->delete();
        } elseif ($reverseDeleteSkipReason !== null) {
            // 記錄跳過反向刪除的原因，便於審計和問題排查
            Log::info('[ASSOC_DATA] 跳過反向記錄刪除', [
                'reason' => $reverseDeleteSkipReason,
                'forward_id' => $id,
                'c_personid' => $temp_l[0] ?? null,
                'c_assoc_id' => $temp_l[2] ?? null,
                'c_assoc_code' => $temp_l[1] ?? null,
                'c_kin_id' => $row?->c_kin_id,
                'c_assoc_kin_id' => $row?->c_assoc_kin_id,
            ]);
        }
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
        $row = DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_pages', '=', $temp_l[2]],
        ])->first();
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
        $row = DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_pages', '=', $temp_l[2]],
        ])->first();
        $data = $request->all();
        $data = Arr::except($data, ['_method', '_token']);
        $data['c_personid'] = $id;
        // Select2 對 id=0 處理有問題，API 中 c_textid=0 會被轉換為 -999，需轉回 0
        $data['c_textid'] = $data['c_textid'] == -999 ? '0' : $data['c_textid'];
        $data['c_main_source'] = (int)$data['c_main_source'];
        $data['c_self_bio'] = (int)$data['c_self_bio'];
        $c_modified_by = Auth::user()->name;
        $c_modified_date = Carbon::now();
        $data['c_modified_by'] = $c_modified_by;
        $data['c_modified_date'] = $c_modified_date;
        DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_pages', '=', $temp_l[2]],
        ])->update($data);
        (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_SOURCE_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $data['c_personid'],
            'c_textid' => $data['c_textid'],
            'c_pages' => $data['c_pages'],
        ]), $data, $row);

        return $data;
    }

    public function sourceStoreById(Request $request, $id) {
        $data = $request->all();
        $data = Arr::except($data, ['_token']);
        $data['c_personid'] = $id;
        // Select2 對 id=0 處理有問題，API 中 c_textid=0 會被轉換為 -999，需轉回 0
        $data['c_textid'] = $data['c_textid'] == -999 ? '0' : $data['c_textid'];
        $data['c_main_source'] = (int)$data['c_main_source'];
        $data['c_self_bio'] = (int)$data['c_self_bio'];
        $c_created_by = Auth::user()->name;
        $c_created_date = Carbon::now();
        $data['c_created_by'] = $c_created_by;
        $data['c_created_date'] = $c_created_date;
        DB::table('BIOG_SOURCE_DATA')->insert($data);
        (new OperationRepository())->store(Auth::id(), $id, 1, 'BIOG_SOURCE_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $data['c_personid'],
            'c_textid' => $data['c_textid'],
            'c_pages' => $data['c_pages'],
        ]), $data);

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
        $row = DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_pages', '=', $temp_l[2]],
        ])->first();
        DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_pages', '=', $temp_l[2]],
        ])->delete();
        (new OperationRepository())->store(Auth::id(), $id, 4, 'BIOG_SOURCE_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $temp_l[0],
            'c_textid' => $temp_l[1],
            'c_pages' => $temp_l[2],
        ]), $row);
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

    protected function insertAddr(array $c_addr, $_id, $_postingid, $_officeid, $c_created_by = '', $c_created_date = '') {
        DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $_id)
            ->where('c_posting_id', $_postingid)
            ->where('c_office_id', $_officeid)
            ->delete();
        foreach ($c_addr as $item) {
            DB::table('POSTED_TO_ADDR_DATA')->insert(
                [
                    'c_personid' => $_id,
                    'c_posting_id' => $_postingid,
                    'c_office_id' => $_officeid,
            'c_addr_id' => $item == -999 ? 0 : $item,
            'c_created_by' => $c_created_by,
            'c_created_date' => $c_created_date,
                ]
            );
        }
    }

    protected function updateAddr(array $c_addr, $_id, $_postingid, $_officeid, $c_created_by = '', $c_created_date = '') {
        foreach ($c_addr as $item) {

            $addr = '';
            $addr = DB::table('POSTED_TO_ADDR_DATA')->where([
                ['c_personid', '=', $_id],
                ['c_posting_id', '=', $_postingid],
                ['c_office_id', '=', $_officeid],
                ['c_addr_id', '=', $item],
            ])->get();

            if (!empty($addr)) {
                //有資料就更新
                DB::table('POSTED_TO_ADDR_DATA')->where([
                            ['c_personid', '=', $_id],
                            ['c_posting_id', '=', $_postingid],
                            ['c_office_id', '=', $_officeid],
                            ['c_addr_id', '=', $item],
                            ])
                ->update(['c_modified_by' => $c_created_by, 'c_modified_date' => $c_created_date]);
            }
        }
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

    protected function insertAddrEvent(array $c_addr_id, $c_personid, $c_sequence, $c_event_code) {
        DB::table('EVENTS_ADDR')
            ->where('c_personid', $c_personid)
            ->where('c_sequence', $c_sequence)
            ->where('c_event_code', $c_event_code)
            ->delete();
        foreach ($c_addr_id as $item) {
            DB::table('EVENTS_ADDR')->insert(
                [
                    'c_personid' => $c_personid,
                    'c_sequence' => $c_sequence,
                    'c_event_code' => $c_event_code,
                    'c_addr_id' => $item == -999 ? 0 : $item,
                ]
            );
        }
    }

    /**
     * 更新事件地址：使用原始主鍵刪除舊記錄，使用新主鍵插入新記錄
     * 這樣即使用戶修改了 c_sequence 或 c_event_code，也不會留下孤兒記錄
     */
    protected function updateAddrEvent(
        array $c_addr_id,
        $c_personid,
        $old_sequence,
        $old_event_code,
        $new_sequence,
        $new_event_code
    ) {
        // 使用原始值刪除舊的地址記錄
        DB::table('EVENTS_ADDR')
            ->where('c_personid', $c_personid)
            ->where('c_sequence', $old_sequence)
            ->where('c_event_code', $old_event_code)
            ->delete();

        // 使用新值插入新的地址記錄
        foreach ($c_addr_id as $item) {
            DB::table('EVENTS_ADDR')->insert(
                [
                    'c_personid' => $c_personid,
                    'c_sequence' => $new_sequence,
                    'c_event_code' => $new_event_code,
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

    //20230628觸發「自動生成」功能
    public function auto_pinyin($data) {
        $c_surname_chn = $c_surname = $c_mingzi_chn = $c_mingzi = $c_name = '';
        $name = $data['c_name_chn'];
        $len = mb_strlen($name, 'utf-8');
        for ($i = $len; $i >= 1; $i--) {
            $str = mb_substr($name, 0, $i, 'utf-8');
            $pinyin = DB::table('pinyin')->select('lastname_pinyin')->where('lastname_chn', 'like', $str)->first();
            if (!empty($pinyin->lastname_pinyin) && $len - $i <= 2) { //[名]的字長必須是小於等於二
                $c_surname_chn = $str;
                $c_surname = $pinyin->lastname_pinyin;

                break;
            }
        }

        if ($c_surname_chn != '') {
            $c_mingzi_chn = str_replace($c_surname_chn, '', $name);
            // 標準化異體字（僅用於拼音轉換，不修改原始名字）
            $normalizedMingzi = VariantCharNormalizer::normalize($c_mingzi_chn);
            $c_mingzi = ucfirst(Pinyin::getPinyin($normalizedMingzi)) ?? '';
            $c_name = $c_surname.' '.$c_mingzi;
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
            $c_name = $c_surname.' '.$c_mingzi;
            $data['c_mingzi_chn'] = $c_mingzi_chn;
            $data['c_mingzi'] = $c_mingzi;
            $data['c_name'] = $c_name;
        }

        return $data;
    }
}
