<?php

namespace App\Http\Controllers;

use App\Models\AddressCode;
use App\Models\AssocCode;
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
use App\Models\TextCode;
use App\Repositories\AddrCodeRepository;
use App\Repositories\AltCodeRepository;
use App\Repositories\BiogAddrCodeRepository;
use App\Repositories\BiogMainRepository;
use App\Repositories\ChoronymRepository;
use App\Repositories\DynastyRepository;
use App\Repositories\EthnicityRepository;
use App\Repositories\NianHaoRepository;
use App\Repositories\YearRangeRepository;
use App\Services\VariantCharNormalizer;
use App\Support\PinyinUmlaut;
use App\v1;
use Illuminate\Http\Request;
//20181017建安新增
use Illuminate\Support\Facades\DB;

//end

class ApiController extends Controller {
    //20180815建安寫在最上面
    public function searchC_presonid(Request $request) {
        $data = new v1();

        return $data->search($request);
    }

    public function addC_presonid(Request $request) {
        $data = new v1();

        return $data->addC($request);
    }

    public function updateC_presonid(Request $request) {
        $data = new v1();

        return $data->updateC($request);
    }

    public function deleteC_presonid(Request $request) {
        $data = new v1();

        return $data->deleteC($request);
    }

    public function userC_presonid(Request $request) {
        $data = new v1();

        return $data->token($request);
    }
    //end

    public function ethnicity() {
        $ethnicityRepository = new EthnicityRepository();

        return $ethnicityRepository->ethnicities();
    }

    public function choronym() {
        $choronymRepository = new ChoronymRepository();

        return $choronymRepository->choronyms();
    }

    public function dynasty() {
        $dynastyRepository = new DynastyRepository();

        return $dynastyRepository->dynasties();
    }

    public function nianhao() {
        $nianhaoRepository = new NianHaoRepository();

        return $nianhaoRepository->nianhaos();
    }

    public function biogaddr() {
        $biogaddrRepository = new BiogAddrCodeRepository();

        return $biogaddrRepository->biogaddr();
    }

    public function altcode() {
        $altcodeRepository = new AltCodeRepository();

        return $altcodeRepository->altcode();
    }

    public function role() {
        return DB::table('TEXT_ROLE_CODES')->select(['c_role_id', 'c_role_desc', 'c_role_desc_chn'])->get();
    }

    public function searchAddr(Request $request) {
        $addrcodeRepository = new AddrCodeRepository();
        $data = $addrcodeRepository->searchAddr($request);

        return $data;
    }

    public function range() {
        $yearrangeRepository = new YearRangeRepository();

        return $yearrangeRepository->yearRange();
    }

    public function ganzhi() {
        return DB::table('GANZHI_CODES')->get();
    }

    public function household() {
        return DB::table('HOUSEHOLD_STATUS_CODES')->get();
    }

    public function appttype() {
        #20250321依據Appointment表重構，修改為存取APPOINTMENT_CODES表
        #return DB::table('APPOINTMENT_TYPE_CODES')->get();
        return DB::table('APPOINTMENT_CODES')->select(['c_appt_code', 'c_appt_desc_chn', 'c_appt_desc', 'c_appt_desc_chn_alt', 'c_appt_desc_alt'])->get();
    }

    public function assumeoffice() {
        return DB::table('ASSUME_OFFICE_CODES')->get();
    }

    public function officecate() {
        return DB::table('OFFICE_CATEGORIES')->get();
    }

    public function parentstatus() {
        return DB::table('PARENTAL_STATUS_CODES')->get();
    }

    public function measure() {
        return DB::table('MEASURE_CODES')->get();
    }

    public function possact() {
        return DB::table('POSSESSION_ACT_CODES')->get();
    }

    public function birole() {
        return DB::table('BIOG_INST_CODES')->select(['c_bi_role_code', 'c_bi_role_chn', 'c_bi_role_desc'])->get();
    }

    public function topic() {
        return DB::table('SCHOLARLYTOPIC_CODES')->select('c_topic_code', 'c_topic_desc', 'c_topic_desc_chn', 'c_topic_type_desc', 'c_topic_type_desc_chn')->get();
    }

    public function occasion() {
        return DB::table('OCCASION_CODES')->get();
    }

    public function searchText(Request $request) {
        //20190708依據需求修改輸出內容
        $data = TextCode::where('c_title_chn', 'like', '%'.$request->q.'%')->orWhere('c_title', 'like', '%'.$request->q.'%')->orWhere('c_textid', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach ($data as $item) {
            $item['id'] = $item->c_textid;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            //進行查詢資訊的擴充
            $c_bibl_cat_code = $item['c_bibl_cat_code'];
            $x1 = DB::table('TEXT_BIBLCAT_CODE_TYPE_REL')->select('c_text_cat_type_id')->where('c_text_cat_code', $c_bibl_cat_code)->get();
            $ans1 = [];
            foreach ($x1 as $object) {
                $ans1[0] = $object->c_text_cat_type_id;
            }
            //20260114增加判斷式，因為TEXT_BIBLCAT_CODE_TYPE_REL的c_text_cat_code沒有完整對應TEXT_CODES資料表的c_bibl_cat_code（可能為NULL或無對應記錄）
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
            $item['text'] = $item->c_textid." ".$item->c_title." ".$item->c_title_chn." ".$item->c_period." ".$word;
        }

        return $data;
    }

    public function searchTextPerson(Request $request) {
        //20211213新增人物[出處]的資訊自動帶入
        $data = DB::table('BIOG_SOURCE_DATA')->where('c_personid', '=', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach ($data as $item) {
            $TextCode = TextCode::where('c_textid', $item->c_textid)->first();
            //進行查詢資訊的擴充
            $item->text = $item->c_textid." ".$TextCode->c_title." ".$TextCode->c_title_chn." - ".$item->c_pages;
            $item->value = $item->c_textid."&and&".$item->c_pages;
        }

        return $data;
    }

    //20220121新增著作編碼表添加作者資訊作為錄入參考
    public function searchTextAuthor(Request $request) {
        $data = DB::table('BIOG_TEXT_DATA')->where('c_textid', '=', $request->q)->paginate(100);
        $data->appends(['q' => $request->q])->links();
        foreach ($data as $item) {
            $person = $role = '';
            $person = BiogMain::where('c_personid', $item->c_personid)->first();
            $role = DB::table('TEXT_ROLE_CODES')->select(['c_role_id', 'c_role_desc', 'c_role_desc_chn'])->where('c_role_id', $item->c_role_id)->first();
            //進行查詢資訊的擴充
            $item->text = $item->c_personid." - ".$person->c_name_chn." - ".$person->c_name." - ".$role->c_role_desc_chn;
            $item->value = $item->c_personid;
        }

        return $data;
    }

    public function searchTextSub($request) {
        $data = DB::table('TEXT_BIBLCAT_TYPES')->select('c_text_cat_type_parent_id', 'c_text_cat_type_desc_chn')->where('c_text_cat_type_id', $request)->get();

        return $data;
    }

    public function searchOffice(Request $request) {
        $baseQuery = OfficeCode::where(function ($q) use ($request) {
            $q->where('c_office_chn', 'like', '%'.$request->q.'%')
                ->orWhere('c_office_pinyin', 'like', '%'.$request->q.'%')
                ->orWhere('c_office_id', $request->q);
        });

        if ((int) $request->c_dy > 0) {
            $data = (clone $baseQuery)->where('c_dy', (int) $request->c_dy)->paginate(20);
            if ($data->total() === 0) {
                $data = $baseQuery->paginate(20);
            }
        } else {
            $data = $baseQuery->paginate(20);
        }

        $appends = ['q' => $request->q];
        if ((int) $request->c_dy > 0) {
            $appends['c_dy'] = $request->c_dy;
        }
        $data->appends($appends)->links();
        foreach ($data as $item) {
            $item['id'] = $item->c_office_id;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            $dy = Dynasty::where('c_dy', $item->c_dy)->first()->c_dynasty_chn;
            $trans = !empty($item->c_office_trans) ? " ".e($item->c_office_trans) : '';
            $item['text'] = $item->c_office_id." ".$item->c_office_pinyin." ".$item->c_office_chn." ".$dy.$trans;
        }

        return $data;
    }

    public function socialinst(Request $request) {
        $data = SocialInst::where('c_inst_name_hz', 'like', '%'.$request->q.'%')->orWhere('c_inst_name_py', 'like', '%'.$request->q.'%')->orWhere('c_inst_name_code', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach ($data as $item) {
            $item['id'] = $item->c_inst_name_code;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            $item['text'] = $item->c_inst_name_code." ".$item->c_inst_name_py." ".$item->c_inst_name_hz;
        }

        return $data;
    }

    public function socialinstaddr(Request $request) {
        $data = SocialInstAddr::where('c_inst_code', 'like', '%'.$request->q.'%')->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach ($data as $item) {
            $item['id'] = $item->c_inst_code;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            $addr = AddressCode::where('c_addr_id', $item->c_inst_addr_id)->first()->c_name_chn;
            $dy = SocialInstCode::where('c_inst_code', $item->c_inst_code)->first()->c_inst_begin_year;
            $dy2 = SocialInstCode::where('c_inst_code', $item->c_inst_code)->first()->c_inst_floruit_dy;
            $dy3 = SocialInstCode::where('c_inst_code', $item->c_inst_code)->first()->c_inst_end_year;
            $dy4 = SocialInstCode::where('c_inst_code', $item->c_inst_code)->first()->c_inst_last_known_year;
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
            $item['text'] = $item->c_inst_code." ".$addr."(地址)-".$dy."(起年)-".$dy2."(最早見諸文獻年)-".$dy3."(訖年)-".$dy4."(最晚見諸文獻年)";
        }

        return $data;
    }

    /*20210205新增的API，提供社交機構(social_institution)查詢，20210309修改。*/
    /*20210315新增漢字與英文對SOCIAL_INSTITUTION_NAME_CODES的檢索。*/
    public function socialinstcode(Request $request) {
        $temp = explode("-", $request->q);
        $c_inst_code = $temp[0];
        if (!empty($temp[1])) {
            $c_inst_name_code = $temp[1];
        } else {
            $c_inst_name_code = '';
        }

        if ($c_inst_name_code != '') {
            $data = SocialInstCode::where([
                ['c_inst_code', '=', $c_inst_code],
                ['c_inst_name_code', '=', $c_inst_name_code],
            ])->paginate(20);
        } elseif (is_string($c_inst_code) && !is_numeric($c_inst_code) && !empty($c_inst_code)) {
            $data_ori = DB::table('SOCIAL_INSTITUTION_NAME_CODES')->select('c_inst_name_code')->where('c_inst_name_hz', 'like', '%'.$c_inst_code.'%')->orWhere('c_inst_name_py', 'like', '%'.$c_inst_code.'%')->get();
            $data_arr = [];
            foreach ($data_ori as $value) {
                array_push($data_arr, $value->c_inst_name_code);
            }
            $data = SocialInstCode::whereIn('c_inst_name_code', $data_arr)->paginate(20);
        } else {
            $data = SocialInstCode::where('c_inst_code', 'like', '%'.$c_inst_code.'%')->paginate(20);
        }

        $data->appends(['q' => $c_inst_code])->links();
        foreach ($data as $item) {
            //修改$item['id']，改變這組API回傳的值。
            $item['id'] = $item->c_inst_code .'-'. $item->c_inst_name_code;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            $name_hz = SocialInst::where('c_inst_name_code', $item->c_inst_name_code)->first()->c_inst_name_hz;
            $name_py = SocialInst::where('c_inst_name_code', $item->c_inst_name_code)->first()->c_inst_name_py;
            $res = SocialInstAddr::where('c_inst_code', $item->c_inst_code)->first();
            if (count((array)$res) == 0) {
                $addr = "未詳";
            } else {
                $addr = AddressCode::where('c_addr_id', $res->c_inst_addr_id)->first()->c_name_chn;
            }
            $dy = $item->c_inst_begin_year;
            $dy2 = $item->c_inst_floruit_dy;
            $dy3 = $item->c_inst_end_year;
            $dy4 = $item->c_inst_last_known_year;
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
            $item['text'] = $item->c_inst_code." (社交機構代碼)-".$name_hz." ".$name_py."(社交機構名稱)-".$item->c_inst_name_code."(社交機構名稱代碼)-".$addr."(地址)-".$dy."(起年)-".$dy2."(最早見諸文獻年)-".$dy3."(訖年)-".$dy4."(最晚見諸文獻年)";
        }

        return $data;
    }

    public function searchEntry(Request $request) {
        $data = EntryCode::where('c_entry_desc_chn', 'like', '%'.$request->q.'%')->orWhere('c_entry_desc', 'like', '%'.$request->q.'%')->orWhere('c_entry_code', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach ($data as $item) {
            $item['id'] = $item->c_entry_code;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            $item['text'] = $item->c_entry_code." ".$item->c_entry_desc_chn." ".$item->c_entry_desc;
        }

        return $data;
    }

    public function searchKincode(Request $request) {
        $data = KinshipCode::where('c_kinrel_chn', 'like', '%'.$request->q.'%')->orWhere('c_kinrel', 'like', '%'.$request->q.'%')->orWhere('c_kincode', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach ($data as $item) {
            $item['id'] = $item->c_kincode;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            $item['text'] = $item->c_kincode." ".$item->c_kinrel_chn." ".$item->c_kinrel;
        }

        return $data;
    }

    public function searchAssoccode(Request $request) {
        $data = AssocCode::where('c_assoc_desc', 'like', '%'.$request->q.'%')->orWhere('c_assoc_desc_chn', 'like', '%'.$request->q.'%')->orWhere('c_assoc_code', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach ($data as $item) {
            $item['id'] = $item->c_assoc_code;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            $item['text'] = $item->c_assoc_code." ".$item->c_assoc_desc_chn." ".$item->c_assoc_desc;
        }

        return $data;
    }

    public function searchStatuscode(Request $request) {
        $data = StatusCode::where('c_status_desc', 'like', '%'.$request->q.'%')->orWhere('c_status_desc_chn', 'like', '%'.$request->q.'%')->orWhere('c_status_code', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach ($data as $item) {
            $item['id'] = $item->c_status_code;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            $item['text'] = $item->c_status_code." ".$item->c_status_desc_chn." ".$item->c_status_desc;
        }

        return $data;
    }

    public function searchBiog(Request $request) {
        // 20251218性能優化：使用與 /api/name 相同的 FTS 索引查詢邏輯
        // #85 + §D-8：$request->q 為主要形式（數字／FTS／orderByRaw），$qForms 為綁定用的 v／ü 展開集。
        $rawQ = $request->q ?? '';
        $request->q = addslashes(\App\Support\PinyinSearchNormalizer::umlautToV($rawQ));
        $qForms = \App\Support\PinyinSearchNormalizer::expand($rawQ);
        $num = 20;

        // 優化1：當輸入為純數字時，直接按 c_personid 精確查詢
        if (ctype_digit($request->q)) {
            $data = BiogMain::where('c_personid', '=', $request->q)->paginate($num);
        } else {
            // 優化2：使用 CBDB__NAME_FTS 倒排索引表進行高效前綴匹配
            $personIds = DB::table('CBDB__NAME_FTS')
                ->where('search_term', 'LIKE', $request->q . '%')
                ->orderByRaw('LENGTH(search_term) ASC')  // 優先精確匹配
                ->limit(500)  // 限制最多 500 個候選人
                ->pluck('c_personid')
                ->unique()
                ->toArray();

            if (!empty($personIds)) {
                // 使用 FIELD() 排序保持匹配質量順序
                $driver = DB::connection()->getDriverName();
                $query = BiogMain::whereIn('c_personid', $personIds);

                if ($driver === 'mysql') {
                    $query->orderByRaw('FIELD(c_personid, ' . implode(',', $personIds) . ')');
                } else {
                    // SQLite 回退方案
                    $caseClauses = [];
                    foreach ($personIds as $index => $personId) {
                        $caseClauses[] = "WHEN c_personid = {$personId} THEN {$index}";
                    }
                    $caseStatement = 'CASE ' . implode(' ', $caseClauses) . ' ELSE 999999 END';
                    $query->orderByRaw($caseStatement);
                }

                $data = $query->paginate($num);
            } else {
                // 回退方案：FTS 未找到結果時，使用原有的 LIKE 查詢（§D-8：c_name 以展開集 OR 同查 v／ü 形）
                $data = BiogMain::where(function ($sub) use ($request, $qForms) {
                    $sub->where('c_name_chn', 'like', '%'.$request->q.'%')
                        ->orWhere('c_personid', $request->q);
                    foreach ($qForms as $form) {
                        $sub->orWhere('c_name', 'like', '%'.$form.'%');
                    }
                })->paginate($num);
            }
        }

        $data->appends(['q' => $request->q])->links();

        foreach ($data as $item) {
            $item['id'] = $item->c_personid;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            $item['text'] = $item->c_personid." ".$item->c_name_chn." ".$item->c_name." index_year:".$item->c_index_year;
        }

        return $data;
    }

    public function searchEvent(Request $request) {
        $data = EventCode::where('c_event_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_event_name', 'like', '%'.$request->q.'%')->orWhere('c_event_code', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach ($data as $item) {
            $item['id'] = $item->c_event_code;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            $item['text'] = $item->c_event_code." ".$item->c_event_name_chn." ".$item->c_event_name;
        }

        return $data;
    }

    public function codeAddr(Request $request) {
        $num = is_null($request->num) ? 20 : $request->num;
        $data = AddressCode::where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->orWhere('c_addr_id', $request->q)->paginate($num);
        $data->appends(['q' => $request->q])->links();

        return $data;
    }

    public function searchKinPair(Request $request) {
        $kin_code = $request->kin_code;
        $person_id = $request->person_id;

        //20201026修改成對親屬關係的選項
        // 首先查找所有 c_kin_pair1 或 c_kin_pair2 等于选中的 kin_code 的记录
        $res = KinshipCode::where('c_kin_pair1', '=', $kin_code)
            ->orWhere('c_kin_pair2', '=', $kin_code)
            ->orderBy('c_kincode', 'asc')  // 按 kincode 升序排列
            ->get();

        // 如果没有找到，尝试查找该 kin_code 自身的 pair1 和 pair2
        if ($res->isEmpty()) {
            $data = KinshipCode::find($kin_code);
            if ($data) {
                // 收集非空的 pair 值
                $pair_codes = array_filter([$data->c_kin_pair1, $data->c_kin_pair2]);
                if (!empty($pair_codes)) {
                    $res = KinshipCode::whereIn('c_kincode', $pair_codes)
                        ->orderBy('c_kincode', 'asc')  // 按 kincode 升序排列
                        ->get();
                }
            }
        }

        return $res;
    }

    public function searchAssocPair(Request $request) {
        $assoc_code = $request->assoc_code;
        $person_id = $request->person_id;
        $data = AssocCode::find($assoc_code);

        if ($data) {
            // 收集非空的 pair 值
            $pair_codes = array_filter([$data->c_assoc_pair, $data->c_assoc_pair2]);
            if (!empty($pair_codes)) {
                $res = AssocCode::whereIn('c_assoc_code', $pair_codes)
                    ->orderBy('c_assoc_code', 'asc')  // 按 assoc_code 升序排列
                    ->get();

                return $res;
            }
        }

        return collect();  // 返回空集合
    }

    public function searchPinyin(Request $request) {
        $word = trim((string) $request->q);
        $split = (int) $request->input('split', 1);
        if (empty($word)) {
            return '';
        }

        // 可選 person_id：提供時啟用「親屬關係守衛」——偵測到關係稱謂（如「（靖江女）」），
        // 但括號內之人不在此人親屬名單中時，改走一般拼音轉換，並帶提示標頭供前端非阻塞提示。
        $personId = ($request->filled('person_id') && is_numeric($request->input('person_id')))
            ? (int) $request->input('person_id')
            : null;

        $kinshipUnmatched = false;
        $res = $this->buildRelationshipPinyin($word, $split, $personId, $kinshipUnmatched)
            ?? $this->buildPinyinWord($word, $split);

        // 全角括號轉半角，並確保括號前與文字之間有一個空格
        $res = str_replace(['（', '）'], ['(', ')'], $res);
        $res = preg_replace('/\s*\(/u', ' (', $res);
        $res = preg_replace('/^\s+\(/u', '(', $res);
        $res = trim($res);

        // 左括號後第一個字母大寫（與人名各段首字母大寫慣例一致）。
        // 僅針對小寫字母，故已為大寫的關係片語（如 "(Wife of ...)"）不受影響。
        $res = preg_replace_callback('/\((\p{Ll})/u', static function (array $m): string {
            return '('.mb_strtoupper($m[1], 'UTF-8');
        }, $res);

        // 偵測到關係稱謂但親屬名單查無此人時：本體仍回傳純文字拼音（向後相容 r.text()），
        // 另帶一個 ASCII 標頭讓前端顯示非阻塞小提示。
        if ($kinshipUnmatched) {
            return response($res)->header('X-Pinyin-Kinship-Unmatched', '1');
        }

        return $res;
    }

    private function buildPinyinWord(string $word, int $split = 1): string {
        $word = trim($word);
        if ($word === '') {
            return '';
        }

        if ($split) {
            $repository = new BiogMainRepository();
            $result = $repository->auto_pinyin(['c_name_chn' => $word]);

            return trim((string) ($result['c_name'] ?? ''));
        }

        // 不拆分姓氏，僅做純拼音轉換
        $normalized = VariantCharNormalizer::normalize($word);

        // 止血：生成後把殘留的 v 代寫正規化為 ü。
        return PinyinUmlaut::normalize(ucfirst(Pinyin::getPinyin($normalized)));
    }

    private function buildRelationshipPinyin(string $word, int $split = 1, ?int $personId = null, bool &$kinshipUnmatched = false): ?string {
        $titlesPattern = implode('|', array_map('preg_quote', array_keys($this->relationshipPhrases())));

        // 特例 1：例如「（李白妻）」→「(Wife of Li Bai)」
        if (preg_match('/^\s*[（(]\s*(.+?)\s*('.$titlesPattern.')\s*[）)]\s*$/u', $word, $matches) === 1) {
            $targetChn = $matches[1] ?? '';
            // 親屬守衛：括號內之人不在此人親屬名單中 → 判為別名，退回一般轉換並標記提示。
            $kinMatch = $this->resolveKinMatch($personId, $targetChn);
            if (!$kinMatch['matched']) {
                $kinshipUnmatched = true;

                return null;
            }
            $target = $kinMatch['c_name'] ?? $this->buildPinyinWord($targetChn, $split);
            $phrase = $this->relationshipPhrases()[$matches[2]] ?? null;

            return $phrase ? '('.$phrase.' '.trim($target).')' : null;
        }

        // 特例 2：例如「宗氏（李白妻）」→「Zong Shi (Wife of Li Bai)」
        if (preg_match('/^(.*?)[（(]\s*(.+?)\s*('.$titlesPattern.')\s*[）)]\s*$/u', $word, $matches) === 1) {
            $targetChn = $matches[2] ?? '';
            $kinMatch = $this->resolveKinMatch($personId, $targetChn);
            if (!$kinMatch['matched']) {
                $kinshipUnmatched = true;

                return null;
            }
            $prefix = $this->buildPinyinWord($matches[1] ?? '', $split);
            $target = $kinMatch['c_name'] ?? $this->buildPinyinWord($targetChn, $split);
            $phrase = $this->relationshipPhrases()[$matches[3]] ?? null;

            return $phrase ? trim($prefix).' ('.$phrase.' '.trim($target).')' : null;
        }

        return null;
    }

    /**
     * 判斷此人（$personId）的親屬名單中，是否有「中文姓名等於 $targetNameChn」的親屬，
     * 若有，一併取回該親屬存檔的英文姓名（BIOG_MAIN.c_name）。
     * 供關係拼音守衛使用：偵測到關係稱謂（如「（靖江女）」）時，若括號內之人不在此人親屬中，
     * 多半是別名而非真實親屬關係，應改走一般拼音轉換。
     *
     * 無 person 上下文（$personId 為 null）或空目標時視為「已匹配但無姓名」（不做守衛，維持既有行為、向後相容）。
     *
     * 限制（刻意的「快速匹配」）：僅比對親屬中文姓名是否相等，不驗證關係方向／稱謂互逆
     * （如「（靖江女）」代表本人為靖江之女，靖江在本人親屬中應記為「父」）。因此：
     *   - 偽陰性（真親屬但存檔姓名寫法不同）→ 退回一般轉換並提示，屬保守可接受；
     *   - 偽陽性（同名親屬但實際關係不符，或同名多人）→ 仍會套用關係轉換。
     * 這符合使用者「親屬名單中查無此人才不套用」的訴求；若日後需更精準，可加入互逆稱謂比對。
     *
     * @return array{matched: bool, c_name: ?string} matched 為 false 時 c_name 恆為 null；
     *                                                matched 為 true 時，若同名親屬存檔的非空 c_name
     *                                                恰好只有一種取值，直接沿用（避免對「劉汝彬」這類
     *                                                姓氏偵測易誤判的姓名重新盲轉拼音、產生「Liurubin」
     *                                                等未拆分姓名）；若同名親屬有多筆且 c_name 取值不一致
     *                                                （無法確定該用哪一筆），或皆為空，則回傳 null 交由
     *                                                呼叫端退回一般拼音轉換，避免任意挑選造成不穩定輸出。
     */
    private function resolveKinMatch(?int $personId, string $targetNameChn): array {
        $target = trim($targetNameChn);
        if ($personId === null || $target === '') {
            return ['matched' => true, 'c_name' => null];
        }

        $rows = DB::table('KIN_DATA')
            ->join('BIOG_MAIN', 'BIOG_MAIN.c_personid', '=', 'KIN_DATA.c_kin_id')
            ->where('KIN_DATA.c_personid', $personId)
            ->where('BIOG_MAIN.c_name_chn', $target)
            ->pluck('BIOG_MAIN.c_name');

        if ($rows->isEmpty()) {
            return ['matched' => false, 'c_name' => null];
        }

        // 注意：去重前不可先濾掉空值——若同名親屬中一筆有 c_name、另一筆為空，
        // 仍代表「不確定該用哪一筆姓名」，須視為歧義並回傳 null（而非誤用那筆非空值）。
        $distinctNames = $rows->map(static fn ($name) => trim((string) ($name ?? '')))->unique();
        $resolvedName = $distinctNames->count() === 1 ? $distinctNames->first() : '';

        return ['matched' => true, 'c_name' => $resolvedName !== '' ? $resolvedName : null];
    }

    /**
     * 稱謂 → 英文片語映射。供「（X母）」「宗氏（X妻）」等女性/親屬以關係命名者一鍵轉寫拼音。
     *
     * ⚠️ 順序很重要：多字稱謂（祖母/祖父/孫女）必須排在其單字後綴（母/父/女）之前，
     * 確保正則 alternation 先嘗試較長者（雖然結尾的「）」錨點通常已能消歧，longest-first 更穩妥）。
     */
    private function relationshipPhrases(): array {
        return [
            // 多字稱謂（longest-first）
            '祖母' => 'Grandmother of',
            '祖父' => 'Grandfather of',
            '孫女' => 'Granddaughter of',
            // 既有女性/親屬稱謂
            '妻' => 'Wife of',
            '母' => 'Mother of',
            '女' => 'Daughter of',
            '妾' => 'Concubine of',
            '媳' => 'Daughter-in-law of',
            '妹' => 'Younger Sister of',
            '姐' => 'Elder Sister of',
            '姊' => 'Elder Sister of',
            '嫂' => 'Sister-in-law of',
            // 擴充：男性與其餘常見親屬。
            // 註：刻意不收單字「子」「孫」——兩者皆為極常見的名字/詞用字（如「公孫」「王孫」本身可為姓氏/詞），
            // 且 CBDB 中以「某之子／某之孫」為名者罕見（多有本名），收錄會把「（李子）」「（公孫）」這類
            // 括號別名誤判為關係稱謂，得不償失。需要孫輩女性時仍可用多字「孫女」（Granddaughter，無歧義）。
            '夫' => 'Husband of',
            '父' => 'Father of',
            '兄' => 'Elder Brother of',
            '弟' => 'Younger Brother of',
            '婿' => 'Son-in-law of',
        ];
    }
}
