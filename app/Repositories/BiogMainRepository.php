<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/14
 * Time: 12:46
 */

namespace App\Repositories;


use App\AddressCode;
use App\AssocCode;
use App\BiogMain;
use App\Dynasty;
use App\EntryCode;
use App\EventCode;
use App\KinshipCode;
use App\NameList;
use App\OfficeCode;
use App\SocialInst;
use App\StatusCode;
use App\TextCode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

//20181112建安修改
use App\SocialInstCode;
use App\SocialInstAddr;
//修改結束

//20210625建安修改
use App\AddrCode;
use App\BiogAddrCode;
//修改結束


/**
 * Class BiogMainRepository
 * @package App\Repositories
 */
class BiogMainRepository
{
    /**
     * @param $id
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model|null|static|static[]
     */
    public function byPersonId($id)
    {
        $basicinformation = BiogMain::withCount('sources', 'texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->find($id);

        //20201207新增index year推算欄位
        $c_index_year_type_code = $basicinformation->c_index_year_type_code;
        if(!empty($c_index_year_type_code)) {
            $simplify_type_code =  substr($c_index_year_type_code, 0, 2);
            $row = DB::table('INDEXYEAR_TYPE_CODES')->where([['c_index_year_type_code' , '=', $simplify_type_code]])->first();
            $ans_type_code = $c_index_year_type_code." ".$row->c_index_year_type_hz;
            $basicinformation->c_index_year_type_code = $ans_type_code;
        }
        else { }

        $c_index_year_source_id = $basicinformation->c_index_year_source_id;
        if(!empty($c_index_year_source_id)) {
            $name = $this->byPersonId($c_index_year_source_id);
            $ans_source_id = $c_index_year_source_id." ".$name->c_name_chn;
            $basicinformation->c_index_year_source_id = $ans_source_id;
        }
        else { }
        //新增結束
        //20210625新增指數地址(index_addr)與指數地址類型(index_addr_type)推算欄位
        $c_index_addr_id = $basicinformation->c_index_addr_id;
        if(!empty($c_index_addr_id)) {
            $addr_name = AddrCode::find($c_index_addr_id);
            if(!empty($addr_name)) {
                $ans_index_addr = $c_index_addr_id." ".$addr_name->c_name." ".$addr_name->c_name_chn;
                $basicinformation->c_index_addr_id = $ans_index_addr;
            }
        }
        else { }

        $c_index_addr_type_code = $basicinformation->c_index_addr_type_code;
        if(!empty($c_index_addr_type_code)) {
            $addr_type_name = BiogAddrCode::where('c_index_addr_default_rank', $c_index_addr_type_code)->first();
            if(!empty($addr_type_name)) {
                $ans_addr_type_name = $c_index_addr_type_code." ".$addr_type_name->c_addr_desc." ".$addr_type_name->c_addr_desc_chn;
                $basicinformation->c_index_addr_type_code = $ans_addr_type_name;
            }
        }
        else { }
        //新增結束
        
        return $basicinformation;
    }

    /**
     * @param $id
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function simpleByPersonId($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->find($id);
        return $basicinformation;
    }

    public function byIdWithAddr($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('biog_addresses')->find($id);
        return $basicinformation;
    }

    public function byIdWithAlt($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('altnames')->find($id);
        return $basicinformation;
    }

    public function byIdWithText($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('texts', 'texts_role')->find($id);
        return $basicinformation;
    }

    public function byIdWithOff($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('offices', 'offices_addr')->find($id);
        return $basicinformation;
    }
    public function byIdWithEntries($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('entries')->find($id);
        return $basicinformation;
    }
    public function byIdWithStatuses($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('statuses')->find($id);
        return $basicinformation;
    }
    public function byIdWithAssoc($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('assoc', 'assoc_name')->find($id);
        return $basicinformation;
    }
    public function byIdWithKinship($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('kinship', 'kinship_name')->find($id);
        return $basicinformation;
    }
    public function byIdWithPossession($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('possession')->find($id);
        return $basicinformation;
    }

    public function byIdWithSocialInst($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'biog_addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst', 'events')->with('inst', 'inst_name')->find($id);
        return $basicinformation;
    }

    public function byQuery($query)
    {
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
    public function updateById($request, $id)
    {
        $data = $request->all();
        $c_name_chn = $request->c_surname_chn.$request->c_mingzi_chn;
        $c_name = $request->c_surname.' '.$request->c_mingzi;
        $c_name_proper = $request->c_surname_proper.' '.$request->c_mingzi_proper;
        $c_name_rm = $request->c_surname_rm.' '.$request->c_mingzi_rm;
        $data['c_name_chn'] = $c_name_chn;
        $data['c_name'] = $c_name;
        $data['c_name_proper'] = $c_name_proper;
        $data['c_name_rm'] = $c_name_rm;
        $data['c_female'] = (int)($data['c_female']);
        $data['c_by_intercalary'] = (int)($data['c_by_intercalary']);
        $data['c_dy_intercalary'] = (int)($data['c_dy_intercalary']);
        $data = (new ToolsRepository)->timestamp($data);
        $biogbasicinformation = BiogMain::find($id);
        $ori = $this->byPersonId($id);
        //20190531判別是否為眾包用戶
        if (Auth::user()->is_admin == 2) {
            (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_MAIN', $biogbasicinformation->c_personid, $data, $ori, 2);
        }
        else {
            $biogbasicinformation->update($data);
            (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_MAIN', $biogbasicinformation->c_personid, $data, $ori);
        }
        //20190531修改結束
    }

    /**
     * @param $request
     * @param $num
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    static public function namesByQuery(Request $request, $num=20)
    {
        if ($temp = $request->num){
            $num = $temp;
        }
        if (!$request->q){
            return BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->paginate($num);
        }
        $names = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->orWhere('c_personid', $request->q)->paginate($num);
        $names->appends(['q' => $request->q])->links();
        return $names;
    }

    /**
     * @param $id_
     * @return array
     */
    public function textById($id_){
        $temp_l = explode("-", $id_);
        $row = DB::table('BIOG_TEXT_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_role_id', '=', $temp_l[2]],
        ])->first();
        $text = null;
        if($row->c_textid || $row->c_textid === 0) {
            $text_ = TextCode::find($row->c_textid);
            //進行查詢資訊的擴充
            $c_bibl_cat_code = $text_->c_bibl_cat_code;
            $x1 = DB::table('TEXT_BIBLCAT_CODE_TYPE_REL')->select('c_text_cat_type_id')->where('c_text_cat_code', $c_bibl_cat_code)->get();
            $ans1 = array();
            foreach($x1 as $object) {
                $ans1[0] = $object->c_text_cat_type_id;
            }
            //20201106這裡增加判斷式，因為TEXT_BIBLCAT_CODE_TYPE_REL的c_text_cat_code沒有完整對應TEXT_CODES資料表的c_bibl_cat_code。
            if(!empty($ans1[0])) {
                for($j=0; $j<=3; $j++) {
                    $x[$j] = $this->searchTextSub($ans1[$j]);
                    foreach($x[$j] as $object) {
                        $ans1[$j+1] = $object->c_text_cat_type_parent_id;
                        $ans2[$j+1] = $object->c_text_cat_type_desc_chn;
                    }
                }
                $word = $ans2[1]."/".$ans2[2]."/".$ans2[3];
            }
            else {
                $word = '';
            }
            $text = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn." ".$word;
            //$text = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }
        $text_str = null;
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        return ['row' => $row, 'text' => $text, 'text_str' => $text_str];
    }

    public function searchTextSub($request){
        $data = DB::table('TEXT_BIBLCAT_TYPES')->select('c_text_cat_type_parent_id', 'c_text_cat_type_desc_chn')->where('c_text_cat_type_id', $request)->get();
        return $data;
    }

    public function textUpdateById( )
    {

    }

    public function officeById($id)
    {
        $temp_l = explode("-", $id);
        $row = DB::table('POSTED_TO_OFFICE_DATA')->where([
            ['c_office_id', '=', $temp_l[0]],
            ['c_posting_id', '=', $temp_l[1]],
        ])->first();
        $text_str = null;
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }
        $office_str = null;
        if($row->c_office_id || $row->c_office_id === 0) {
            $text_ = OfficeCode::find($row->c_office_id);
            $dy = Dynasty::where('c_dy', $text_->c_dy)->first()->c_dynasty_chn;
            $office_str = $text_->c_office_id." ".$text_->c_office_pinyin." ".$text_->c_office_chn." ".$dy;
        }
        $posting_str = null;
//        dd($row->c_inst_name_code);
        if($row->c_inst_code || $row->c_inst_code === 0) {
            $text_ = SocialInst::find($row->c_inst_code);
            $posting_str = $text_->c_inst_name_code." ".$text_->c_inst_name_py." ".$text_->c_inst_name_hz;
        }
//        dd($posting_str);
        $addr_ = DB::table('POSTED_TO_ADDR_DATA')->where('c_personid', $row->c_personid)->where('c_posting_id', $row->c_posting_id)->get();
        $addr_str = [];
        foreach ($addr_ as $key=>$value) {
            $id_ = $value->c_addr_id == 0 ? -999 : $value->c_addr_id;
            $item = [$id_, $this->addr_str($value->c_addr_id)];
            $addr_str[$key] = $item;
        }
        return ['row' => $row, 'text_str' => $text_str, 'office_str' => $office_str, 'posting_str' => $posting_str, 'addr_str' => $addr_str];
    }

    public function officeUpdateById(Request $request, $id, $c_personid)
    {
        $data = $request->all();
        $_id = $data['_id'];
        $_postingid = $data['_postingid'];
        $_officeid = $data['_officeid']; //目前与officeid无关

        if (!empty($data['c_addr'])){
            $this->insertAddr($data['c_addr'], $_id, $_postingid, $_officeid);
        }
        $data = array_except($data, ['_method', '_token', 'c_addr', '_id', '_postingid', '_officeid']);
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);
        $data['c_office_id'] = $data['c_office_id'] == -999 ? '0' : $data['c_office_id'];
        $data['c_inst_code'] = $data['c_inst_code'] == -999 ? '0' : $data['c_inst_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository)->timestamp($data);
        DB::table('POSTED_TO_OFFICE_DATA')->where([['c_office_id' , '=', $_officeid], ['c_posting_id' , '=', $_postingid]])->update($data);
        (new OperationRepository())->store(Auth::id(), $c_personid, 3, 'POSTED_TO_OFFICE_DATA', $data['c_office_id']."-".$_postingid, $data);
        return $data['c_office_id']."-".$_postingid;
    }

    public function officeStoreById(Request $request, $id)
    {
        $data = $request->all();
        $c_addr = $data['c_addr'];
        $data = array_except($data, ['_token', 'c_addr']);
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);
        $data['c_posting_id'] = DB::table('POSTED_TO_OFFICE_DATA')->max('c_posting_id') + 1;
        $data['c_personid'] = $id;
        DB::table('POSTING_DATA')->insert(['c_personid' => $data['c_personid'], 'c_posting_id' => $data['c_posting_id']]);
        $this->insertAddr($c_addr, $id, $data['c_posting_id'], $data['c_office_id']);
        $data = (new ToolsRepository)->timestamp($data, True);
        DB::table('POSTED_TO_OFFICE_DATA')->insert($data);
        (new OperationRepository())->store(Auth::id(), $id, 1, 'POSTED_TO_OFFICE_DATA', $data['c_office_id']."-".$data['c_posting_id'], $data);
        return $data['c_office_id']."-".$data['c_posting_id'];
    }

    public function officeDeleteById($id, $c_personid)
    {
        $addr_l = explode("-", $id);
        $row = DB::table('POSTED_TO_OFFICE_DATA')->where([['c_office_id' , '=', $addr_l[0]], ['c_posting_id' , '=', $addr_l[1]]])->first();
        DB::table('POSTED_TO_OFFICE_DATA')->where([['c_office_id' , '=', $addr_l[0]], ['c_posting_id' , '=', $addr_l[1]]])->delete();
        DB::table('POSTED_TO_ADDR_DATA')->where('c_posting_id', $row->c_posting_id)->delete();
        DB::table('POSTING_DATA')->where('c_posting_id', $row->c_posting_id)->delete();
        (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'POSTED_TO_OFFICE_DATA', $id, $row);
    }

    public function entryById($id)
    {
        //建安修改20181109
        //$row = DB::table('ENTRY_DATA')->where('tts_sysno', $id)->first();
        $id = str_replace("--","-minus",$id);
        $addr_a = explode("-", $id);
        foreach($addr_a as $key => $value) {
            $addr_a[$key] = str_replace("minus","-",$value);
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
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        $entry_str = null;
        if($row->c_entry_code || $row->c_entry_code === 0) {
            $text_ = EntryCode::find($row->c_entry_code);
            $entry_str = $text_->c_entry_code." ".$text_->c_entry_desc_chn." ".$text_->c_entry_desc;
        }
        $addr_str = null;
        if($row->c_entry_addr_id || $row->c_entry_addr_id === 0) {
            $text_ = AddressCode::find($row->c_entry_addr_id);
            $addr_str = $text_->c_addr_id." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $kin_str = null;
        if($row->c_kin_code || $row->c_kin_code === 0) {
            $text_ = KinshipCode::find($row->c_kin_code);
            $kin_str = $text_->c_kin_code." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
        }
        //20181112建安修改
        $biog_str = null;
        if($row->c_kin_id || $row->c_kin_id === 0) {
            $text_ = BiogMain::find($row->c_kin_id);
            $biog_str = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $biog_str2 = null;
        if($row->c_assoc_id || $row->c_assoc_id === 0) {
            $text_ = BiogMain::find($row->c_assoc_id);
            $biog_str2 = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        //修改結束
        $assoc_str = null;
        if($row->c_assoc_code || $row->c_assoc_code === 0) {
            $text_ = AssocCode::find($row->c_assoc_code);
            $assoc_str = $text_->c_assoc_code." ".$text_->c_assoc_desc_chn." ".$text_->c_assoc_desc;
        }
        //20181112建安修改
        $inst_str_new = null;
        if($row->c_inst_code || $row->c_inst_code === 0) {
            $text = SocialInstAddr::find($row->c_inst_code);
            $addr = AddressCode::where('c_addr_id', $text->c_inst_addr_id)->first()->c_name_chn;
            $dy = SocialInstCode::where('c_inst_code', $row->c_inst_code)->first()->c_inst_begin_year;
            $dy2 = SocialInstCode::where('c_inst_code', $row->c_inst_code)->first()->c_inst_floruit_dy;
            $dy3 = SocialInstCode::where('c_inst_code', $row->c_inst_code)->first()->c_inst_end_year;
            $dy4 = SocialInstCode::where('c_inst_code', $row->c_inst_code)->first()->c_inst_last_known_year;
            if($dy == null) $dy = "未詳";
            if($dy2 == null) $dy2 = "未詳";
            if($dy3 == null) $dy3 = "未詳";
            if($dy4 == null) $dy4 = "未詳";
            $inst_str_new = $row->c_inst_code." ".$addr."(地址)-".$dy."(起年)-".$dy2."(最早見諸文獻年)-".$dy3."(訖年)-".$dy4."(最晚見諸文獻年)";
        }
        
        $inst_str = null;
        if($row->c_inst_name_code || $row->c_inst_name_code === 0) {
            $text_ = SocialInst::find($row->c_inst_name_code);
            $inst_str = $text_->c_inst_name_code." ".$text_->c_inst_name_py." ".$text_->c_inst_name_hz;
        }
        
        //修改結束
        return ['row' => $row, 'text_str' => $text_str, 'entry_str' => $entry_str, 'addr_str' => $addr_str, 'kin_str' => $kin_str, 'assoc_str' => $assoc_str, 'inst_str' => $inst_str, 'inst_str_new' => $inst_str_new, 'biog_str' => $biog_str, 'biog_str2' => $biog_str2];
    }

    public function entryUpdateById(Request $request, $id, $c_personid)
    {
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
        $data['c_entry_code'] = $data['c_entry_code'] == -999 ? '0' : $data['c_entry_code'];
        $data['c_entry_addr_id'] = $data['c_entry_addr_id'] == -999 ? '0' : $data['c_entry_addr_id'];
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_assoc_code'] = $data['c_assoc_code'] == -999 ? '0' : $data['c_assoc_code'];
        $data['c_inst_code'] = $data['c_inst_code'] == -999 ? '0' : $data['c_inst_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository)->timestamp($data);
        DB::table('ENTRY_DATA')->where('tts_sysno',$id)->update($data);
        (new OperationRepository())->store(Auth::id(), $c_personid, 3, 'ENTRY_DATA', $id, $data);
    }

    public function entryStoreById(Request $request, $id)
    {
        //建安修改20181109
        $data = $request->all();
        $data = array_except($data, ['_token']);
        $data['tts_sysno'] = DB::table('ENTRY_DATA')->max('tts_sysno') + 1;
        $data['c_personid'] = $id;
        $data['c_entry_code'] = $data['c_entry_code'] == -999 ? '0' : $data['c_entry_code'];
        //$data['c_entry_addr_id'] = $data['c_entry_addr_id'] == -999 ? '0' : $data['c_entry_addr_id'];
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_assoc_code'] = $data['c_assoc_code'] == -999 ? '0' : $data['c_assoc_code'];
        $data['c_inst_code'] = $data['c_inst_code'] == -999 ? '0' : $data['c_inst_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository)->timestamp($data, True);
        //dd($data);
        DB::table('ENTRY_DATA')->insert($data);
        (new OperationRepository())->store(Auth::id(), $id, 1, 'ENTRY_DATA', $data['tts_sysno'], $data);
        //新增的聯合主鍵
        $newid = $data['c_personid']."-".$data['c_entry_code']."-".$data['c_sequence'];
        //return $data['tts_sysno'];
        return $newid;
    }

    public function entryDeleteById($id, $c_personid)
    {
        $row = DB::table('ENTRY_DATA')->where('tts_sysno', $id)->first();
        DB::table('ENTRY_DATA')->where('tts_sysno', $id)->delete();
        (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'ENTRY_DATA', $id, $row);
    }

    public function statuseById($id)
    {
        $id = str_replace("--","-minus",$id);
        $temp_l = explode("-", $id);
        foreach($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus","-",$value);
        }
        $row = DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->first();
        //$row = DB::table('STATUS_DATA')->where('tts_sysno', $id)->first();
        $text_str = null;
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        $statuse_str = null;
        if($row->c_status_code || $row->c_status_code === 0) {
            $text_ = StatusCode::find($row->c_status_code);
            $statuse_str = $text_->c_status_code." ".$text_->c_status_desc_chn." ".$text_->c_status_desc;
        }
        return ['row' => $row, 'text_str' => $text_str, 'statuse_str' => $statuse_str];
    }

    public function statuseUpdateById(Request $request, $id, $c_personid)
    {
        $id = str_replace("--","-minus",$id);
        $temp_l = explode("-", $id);
        foreach($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus","-",$value);
        }
        $row = DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->first();
        $data = $request->all();
        $data = array_except($data, ['_token', '_method']);
        $data['c_status_code'] = $data['c_status_code'] == -999 ? '0' : $data['c_status_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository)->timestamp($data);
        DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->update($data);
        $new_id = $c_personid."-".$data['c_sequence']."-".$data['c_status_code'];
        (new OperationRepository())->store(Auth::id(), $c_personid, 3, 'STATUS_DATA', $new_id, $data);
        return $data;
    }

    public function statuseStoreById(Request $request, $id)
    {
        $data = $request->all();
        $data = array_except($data, ['_token']);
        $data['tts_sysno'] = DB::table('STATUS_DATA')->max('tts_sysno') + 1;
        $data['c_personid'] = $id;
        $data['c_status_code'] = $data['c_status_code'] == -999 ? '0' : $data['c_status_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository)->timestamp($data, True);
        DB::table('STATUS_DATA')->insert($data);
        (new OperationRepository())->store(Auth::id(), $data['c_personid'], 1, 'STATUS_DATA', $data['c_personid'].'-'.$data['c_sequence'].'-'.$data['c_status_code'], $data);
        return $data;
    }

    public function statuseDeleteById($id, $c_personid)
    {
        $id = str_replace("--","-minus",$id);
        $temp_l = explode("-", $id);
        foreach($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus","-",$value);
        }
        $row = DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->first(); 
        //$row = DB::table('STATUS_DATA')->where('tts_sysno', $id)->first();
        DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->delete();
        (new OperationRepository())->store(Auth::id(), $id, 4, 'STATUS_DATA', $id, $row);
    }

    public function kinshipById($id)
    {
        $id = str_replace("--","-minus",$id);
        $temp_l = explode("-", $id);
        foreach($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus","-",$value);
        }

        $row = DB::table('KIN_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_kin_id', '=', $temp_l[1]],
            ['c_kin_code', '=', $temp_l[2]],
        ])->first();
        //$row = DB::table('KIN_DATA')->where('tts_sysno', $id)->first();
        $text_str = null;
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        $kin_str = null;
        if($row->c_kin_code || $row->c_kin_code === 0) {
            $text_ = KinshipCode::find($row->c_kin_code);
            //20201026修改，提供給前端c_kincode可以更新親屬關係。
            //$kin_str = $text_->c_status_code." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
            $kin_str = $text_->c_kincode." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
        }
        $biog_str = null;
        $kinpair_str = null;
        if($row->c_kin_id || $row->c_kin_id === 0) {
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

    public function kinshipUpdateById(Request $request, $id, $id_)
    {
        $id_ = str_replace("--","-minus",$id_);
        $temp_l = explode("-", $id_);
        foreach($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus","-",$value);
        }

        $row = DB::table('KIN_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_kin_id', '=', $temp_l[1]],
            ['c_kin_code', '=', $temp_l[2]],
        ])->first();
        $data = $request->all();
        $kin_pair = $data['c_kinship_pair'];
        $kin_id = $data['c_kin_id'];
        $c_created_date = $row->c_created_date;
        //$old_kin_id = DB::table('KIN_DATA')->where('tts_sysno',$id_)->first()->c_kin_id;
        $old_kin_id = $row->c_kin_id;
        $data = array_except($data, ['_token', '_method', 'c_kinship_pair']);
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_kin_id'] = $data['c_kin_id'] == -999 ? '0' : $data['c_kin_id'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository)->timestamp($data);
//        dump($data);
        //DB::table('KIN_DATA')->where('tts_sysno',$id_)->update($data);
        DB::table('KIN_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_kin_id', '=', $temp_l[1]],
            ['c_kin_code', '=', $temp_l[2]],
        ])->update($data);
        $ori_data = $data;
        $new_id_ = $id."-".$data['c_kin_id']."-".$data['c_kin_code'];
        (new OperationRepository())->store(Auth::id(), $id, 3, 'KIN_DATA', $new_id_, $data);
        $data['c_kin_code'] = $kin_pair;
        $data['c_personid'] = $kin_id;
        $data = array_except($data, ['c_kin_id']);
//        dump($data);
//        dd(DB::table('KIN_DATA')->where([['c_kin_id',$id], ['c_personid', $old_kin_id]])->first());
        DB::table('KIN_DATA')->where([['c_kin_id',$id], ['c_personid', $old_kin_id], ['c_created_date', $c_created_date]])->update($data);
        return $ori_data;
    }

    public function kinshipStoreById(Request $request, $id)
    {
        $data = $request->all();
        $kin_pair = $data['c_kinship_pair'];
        $data = array_except($data, ['_token', 'c_kinship_pair']);
        $data['tts_sysno'] = DB::table('KIN_DATA')->max('tts_sysno') + 1;
        $tts = $data['tts_sysno'];
        $data['c_personid'] = $id;
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_kin_id'] = $data['c_kin_id'] == -999 ? '0' : $data['c_kin_id'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository)->timestamp($data, True);
        DB::table('KIN_DATA')->insert($data);
        $ori_Data = $data;
        (new OperationRepository())->store(Auth::id(), $id, 1, 'KIN_DATA', $data['c_personid']."-".$data['c_kin_id']."-".$data['c_kin_code'], $data);
        $data['tts_sysno'] += 1;
        $data['c_kin_code'] = $kin_pair;
        $data['c_personid'] = $data['c_kin_id'];
        $data['c_kin_id'] = $id;
        DB::table('KIN_DATA')->insert($data);
        //return $tts;
        return $ori_Data;
    }

    public function kinshipDeleteById($id, $id_)
    {
        $operationRepository = new OperationRepository();
        $id = str_replace("--","-minus",$id);
        $temp_l = explode("-", $id);
        foreach($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus","-",$value);
        }

        $row = DB::table('KIN_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_kin_id', '=', $temp_l[1]],
            ['c_kin_code', '=', $temp_l[2]],
        ])->first();
        (new OperationRepository())->store(Auth::id(), $id, 4, 'KIN_DATA', $id, $row);
        $row2 = DB::table('KIN_DATA')->where([
            ['c_kin_id',$row->c_personid], 
            ['c_personid', $row->c_kin_id],
            ['c_source', $row->c_source],
            ['c_created_date', $row->c_created_date],
        ])->first();
        DB::table('KIN_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_kin_id', '=', $temp_l[1]],
            ['c_kin_code', '=', $temp_l[2]],
        ])->delete();
        //先檢查$row2->c_modified_date是否為null，依照c_kin_id, c_personid, c_source, c_created_date, c_modified_date查詢後進行刪除反向關係。
        if(is_null($row2->c_modified_date)) {
            DB::table('KIN_DATA')->where([
                ['c_kin_id',$row2->c_kin_id], 
                ['c_personid', $row2->c_personid], 
                ['c_source', $row2->c_source],
                ['c_created_date', $row2->c_created_date],
            ])->delete();
        }
        else {
            DB::table('KIN_DATA')->where([
                ['c_kin_id',$row2->c_kin_id], 
                ['c_personid', $row2->c_personid], 
                ['c_source', $row2->c_source],
                ['c_created_date', $row2->c_created_date],
                ['c_modified_date', $row2->c_modified_date],
            ])->delete();
        }
    }

    public function possessionById($id)
    {
        $row = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id)->first();
        $text_str = null;
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }

        $addr_ = DB::table('POSSESSION_ADDR')->where('c_possession_record_id', $id)->get();
//        dd($addr_);
        $addr_str = [];
        foreach ($addr_ as $key=>$value) {
            $id_ = $value->c_addr_id == 0 ? -999 : $value->c_addr_id;
            $item = [$id_, $this->addr_str($value->c_addr_id)];
            $addr_str[$key] = $item;
        }

        return ['row' => $row, 'text_str' => $text_str, 'addr_str' => $addr_str];
    }

    public function possessionUpdateById(Request $request, $id, $id_)
    {
        $data = $request->all();
        $this->insertAddrPo($data['c_addr_id'], $id_, $id);
        $data = array_except($data, ['_method', '_token', 'c_addr_id']);
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository)->timestamp($data);
        DB::table('POSSESSION_DATA')->where('c_possession_record_id',$id_)->update($data);
        (new OperationRepository())->store(Auth::id(), $id, 3, 'POSSESSION_DATA', $id_, $data);
    }

    public function possessionStoreById(Request $request, $id)
    {
        $data = $request->all();
        $data['c_possession_record_id'] = DB::table('POSSESSION_DATA')->max('c_possession_record_id') + 1;
        $data['c_personid'] = $id;
        //20210205因為資料表關聯欄位的設定，資料新增的流程需要往後移動。
        //$this->insertAddrPo($data['c_addr_id'], $data['c_possession_record_id'], $data['c_personid']);
        $addr = array();
        $addr = $data['c_addr_id'];
        //修改段落
        $data = array_except($data, ['_token', 'c_addr_id']);
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository)->timestamp($data, True);
        DB::table('POSSESSION_DATA')->insert($data);
        //移動到這裡
        $this->insertAddrPo($addr, $data['c_possession_record_id'], $data['c_personid']);
        //修改結束
        (new OperationRepository())->store(Auth::id(), $id, 1, 'POSSESSION_DATA', $data['c_possession_record_id'], $data);
        return $data['c_possession_record_id'];
    }

    public function possessionDeleteById($id, $c_personid)
    {
        $row = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id)->first();
        DB::table('POSSESSION_DATA')->where('c_possession_record_id', $id)->delete();
        DB::table('POSSESSION_ADDR')->where('c_possession_record_id', $row->c_possession_record_id)->delete();
        (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'POSSESSION_DATA', $id, $row);
    }

    public function socialInstById($id)
    {
        //建安修改20181113
        //$row = DB::table('BIOG_INST_DATA')->where('tts_sysno', $id)->first();
        $addr_l = explode("-", $id);
        $row = DB::table('BIOG_INST_DATA')->where('c_personid', $addr_l[0])->where('c_bi_role_code', $addr_l[1])->first();
        $text_str = null;
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }
        $inst_str = null;
        if($row->c_inst_name_code || $row->c_inst_name_code === 0) {
            $text_ = SocialInst::find($row->c_inst_name_code);
//            dd($text_);
            $inst_str = $text_->c_inst_name_code." ".$text_->c_inst_name_hz." ".$text_->c_inst_name_py;

        }
        return ['row' => $row, 'text_str' => $text_str, 'inst_str' => $inst_str];
    }

    public function socialInstUpdateById(Request $request, $id_, $c_personid)
    {
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository)->timestamp($data);
        DB::table('BIOG_INST_DATA')->where('tts_sysno',$id_)->update($data);
        (new OperationRepository())->store(Auth::id(), $c_personid, 3, 'BIOG_INST_DATA', $id_, $data);
    }

    public function socialInstStoreById(Request $request, $id)
    {
        $data = $request->all();
        $data['c_personid'] = $id;
        $data = array_except($data, ['_token']);
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository)->timestamp($data, True);
        $tts = DB::table('BIOG_INST_DATA')->insertGetId($data);
        //新增的聯合主鍵
        $newid = $data['c_personid']."-".$data['c_bi_role_code'];
        (new OperationRepository())->store(Auth::id(), $id, 1, 'BIOG_INST_DATA', $newid, $data);
        return $newid;
    }

    public function socialInstDeleteById($id, $c_personid)
    {
        $addr_l = explode("-", $id);
        $row = DB::table('BIOG_INST_DATA')->where('c_personid', $addr_l[0])->where('c_bi_role_code', $addr_l[1])->first();
        DB::table('BIOG_INST_DATA')->where('c_personid', $addr_l[0])->where('c_bi_role_code', $addr_l[1])->delete();
        (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'BIOG_INST_DATA', $id, $row);
    }

    public function eventById($id)
    {
        $row = DB::table('EVENTS_DATA')->where('tts_sysno', $id)->first();
        $text_str = null;
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }
        $addr_ = DB::table('EVENTS_ADDR')->where('c_event_record_id', $row->c_event_record_id)->get();
        $addr_str = [];
        foreach ($addr_ as $key=>$value) {
            $id_ = $value->c_addr_id == 0 ? -999 : $value->c_addr_id;
            $item = [$id_, $this->addr_str($value->c_addr_id)];
            $addr_str[$key] = $item;
        }
        $event_str = null;
        if($row->c_event_code || $row->c_event_code === 0) {
            $text_ = EventCode::find($row->c_event_code);
            $event_str = $text_->c_event_code." ".$text_->c_event_name_chn." ".$text_->c_event_name;
        }
        return ['row' => $row, 'text_str' => $text_str, 'addr_str' => $addr_str, 'event_str' => $event_str];
    }

    public function eventUpdateById(Request $request, $id, $id_)
    {
        $data = $request->all();
        $data = $this->formatSelect($data);
        $this->insertAddrEvent($data['c_addr_id'], $data['c_event_record_id'], $id);
        $data = array_except($data, ['_method', '_token', 'c_addr_id']);
        $data['c_intercalary'] = (int)($data['c_intercalary']);
        $data = (new ToolsRepository)->timestamp($data);
        DB::table('EVENTS_DATA')->where('tts_sysno',$id_)->update($data);
        (new OperationRepository())->store(Auth::id(), $id, 3, 'EVENTS_DATA', $id_, $data);
    }

    public function eventStoreById(Request $request, $id)
    {
        $data = $request->all();
        $data = $this->formatSelect($data);
        $data['c_personid'] = $id;
        $data['c_event_record_id'] = DB::table('EVENTS_DATA')->max('c_event_record_id') + 1;
        $this->insertAddrEvent($data['c_addr_id'], $data['c_event_record_id'], $id);
        $data = array_except($data, ['_token', 'c_addr_id']);
        $data['tts_sysno'] = DB::table('EVENTS_DATA')->max('tts_sysno') + 1;
        $data['c_intercalary'] = (int)($data['c_intercalary']);
        $data = (new ToolsRepository)->timestamp($data, True);
        DB::table('EVENTS_DATA')->insert($data);
        (new OperationRepository())->store(Auth::id(), $id, 1, 'EVENTS_DATA', $data['tts_sysno'], $data);
        return $data['tts_sysno'];
    }

    public function eventDeleteById($id, $c_personid)
    {
        $row = DB::table('EVENTS_DATA')->where('tts_sysno', $id)->first();
        DB::table('EVENTS_DATA')->where('tts_sysno', $id)->delete();
        DB::table('EVENTS_ADDR')->where('c_event_record_id', $row->c_event_record_id)->delete();
        (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'EVENTS_DATA', $id, $row);
    }

    public function assocById($id)
    {
        $id = str_replace("--","-minus",$id);
        //20200709聯合主鍵保留字弱點防禦函式，解析保留字。
        $id = $this->unionPKDef_decode($id);
        $temp_l = explode("-", $id);
        foreach($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus","-",$value);
        }
        //20191028防止c_text_title欄位內含負號所做的字串重組
        $new_c_text_title = '';
        if(!empty($temp_l[8])) {
            for($i=7; $i<count($temp_l); $i++) {
                if(empty($new_c_text_title)) { $new_c_text_title .= $temp_l[$i]; }
                else { $new_c_text_title .= "-".$temp_l[$i]; }
            }
            $temp_l[7] = $new_c_text_title;
        }
        
        $row = DB::table('ASSOC_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_assoc_code', '=', $temp_l[1]],
            ['c_assoc_id', '=', $temp_l[2]],
            //20191028進行聯合主鍵的擴充修改
            ['c_kin_code', '=', $temp_l[3]],
            ['c_kin_id', '=', $temp_l[4]],
            ['c_assoc_kin_code', '=', $temp_l[5]],
            ['c_assoc_kin_id', '=', $temp_l[6]],
            ['c_text_title', '=', $temp_l[7]],
        ])->first();
        //$row = DB::table('ASSOC_DATA')->where('tts_sysno', $id)->first();
        $text_str = null;
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        $kin_code = null;
        if($row->c_kin_code || $row->c_kin_code === 0) {
            $text_ = KinshipCode::find($row->c_kin_code);
            $kin_code = $text_->c_status_code." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
        }
        $kin_id = null;
        if($row->c_kin_id || $row->c_kin_id === 0) {
            $text_ = BiogMain::find($row->c_kin_id);
            $kin_id = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $assoc_code = null;
        if($row->c_assoc_code || $row->c_assoc_code === 0) {
            $text_ = AssocCode::find($row->c_assoc_code);
            $assoc_code = $text_->c_assoc_code." ".$text_->c_assoc_desc_chn." ".$text_->c_assoc_desc;
        }
        $assoc_id = null;
        if($row->c_assoc_id || $row->c_assoc_id === 0) {
            $text_ = BiogMain::find($row->c_assoc_id);
            $assoc_id = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $assoc_kin_code = null;
        if($row->c_assoc_kin_code || $row->c_assoc_kin_code === 0) {
            $text_ = KinshipCode::find($row->c_assoc_kin_code);
            $assoc_kin_code = $text_->c_status_code." ".$text_->c_kinrel_chn." ".$text_->c_kinrel;
        }
        $assoc_kin_id = null;
        if($row->c_assoc_kin_id || $row->c_assoc_kin_id === 0) {
            $text_ = BiogMain::find($row->c_assoc_kin_id);
            $assoc_kin_id = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $tertiary_personid = null;
        if($row->c_tertiary_personid || $row->c_tertiary_personid === 0) {
            $text_ = BiogMain::find($row->c_tertiary_personid);
            $tertiary_personid = $text_->c_personid." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $addr_id = null;
        if($row->c_addr_id || $row->c_addr_id === 0) {
            $text_ = AddressCode::find($row->c_addr_id);
            if(!$text_) { $text_ = AddressCode::find(0); }
            $addr_id = $text_->c_addr_id." ".$text_->c_name_chn." ".$text_->c_name;
        }
        $inst_code = null;
        if($row->c_inst_code || $row->c_inst_code === 0) {
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
            if(count((array)$res) == 0 ) $addr = "未詳";
            else {
                $addr = AddressCode::where('c_addr_id', $res->c_inst_addr_id)->first()->c_name_chn;
            }
            $dy = $text_->c_inst_begin_year;
            $dy2 = $text_->c_inst_floruit_dy;
            $dy3 = $text_->c_inst_end_year;
            $dy4 = $text_->c_inst_last_known_year;
            if($name_hz == null) $name_hz = "未詳";
            if($name_py == null) $name_py = "未詳";
            if($addr == null) $addr = "未詳";
            if($dy == null) $dy = "未詳";
            if($dy2 == null) $dy2 = "未詳";
            if($dy3 == null) $dy3 = "未詳";
            if($dy4 == null) $dy4 = "未詳";
            $inst_code = $text_->c_inst_code." (社交機構代碼)-".$name_hz." ".$name_py."(社交機構名稱)-".$text_->c_inst_name_code."(社交機構名稱代碼)-".$addr."(地址)-".$dy."(起年)-".$dy2."(最早見諸文獻年)-".$dy3."(訖年)-".$dy4."(最晚見諸文獻年)";
            //修改結束
        }
        return ['row' => $row, 'text_str' => $text_str, 'kin_code' => $kin_code, 'kin_id' => $kin_id,
            'assoc_code' => $assoc_code, 'assoc_id' => $assoc_id, 'assoc_kin_code' => $assoc_kin_code, 'assoc_kin_id' => $assoc_kin_id,
            'tertiary_personid' => $tertiary_personid, 'addr_id' => $addr_id, 'inst_code' => $inst_code];
    }

    public function assocUpdateById(Request $request, $id, $c_personid)
    {
        $id = str_replace("--","-minus",$id);
        //20200709聯合主鍵保留字弱點防禦函式，解析保留字。
        $id = $this->unionPKDef_decode($id); 
        $temp_l = explode("-", $id);
        foreach($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus","-",$value);
        }
        //20191028防止c_text_title欄位內含負號所做的字串重組
        $new_c_text_title = '';
        if(!empty($temp_l[8])) {
            for($i=7; $i<count($temp_l); $i++) {
                if(empty($new_c_text_title)) { $new_c_text_title .= $temp_l[$i]; }
                else { $new_c_text_title .= "-".$temp_l[$i]; }
            }
            $temp_l[7] = $new_c_text_title;
        }
        //end

        $row = DB::table('ASSOC_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_assoc_code', '=', $temp_l[1]],
            ['c_assoc_id', '=', $temp_l[2]],
            //20191028進行聯合主鍵的擴充修改
            ['c_kin_code', '=', $temp_l[3]],
            ['c_kin_id', '=', $temp_l[4]],
            ['c_assoc_kin_code', '=', $temp_l[5]],
            ['c_assoc_kin_id', '=', $temp_l[6]],
            ['c_text_title', '=', $temp_l[7]],
        ])->first();
        $data = $request->all();
        $data = $this->formatSelect($data);
        $assoc_pair = $data['c_assocship_pair'];
        $assoc_id = $data['c_assoc_id'];
        $old_assoc_id = $row->c_assoc_id;
        //20190118筆記 原程式移除c_assoc_id的值,當社會關係人修改時,資料就不能成對.
        //$data = array_except($data, ['_method', '_token', 'c_assocship_pair', 'c_assoc_id']);
        $data = array_except($data, ['_method', '_token', 'c_assocship_pair']);
        $data['c_assoc_intercalary'] = (int)($data['c_assoc_intercalary']);
        //20210204增加儲存c_inst_name_code
        //$data['c_inst_name_code'] = SocialInstCode::where('c_inst_code', $data['c_inst_code'])->first()->c_inst_name_code;
        //新增結束
        $data = (new ToolsRepository)->timestamp($data);
        DB::table('ASSOC_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_assoc_code', '=', $temp_l[1]],
            ['c_assoc_id', '=', $temp_l[2]],
            //20191028進行聯合主鍵的擴充修改
            ['c_kin_code', '=', $temp_l[3]],
            ['c_kin_id', '=', $temp_l[4]],
            ['c_assoc_kin_code', '=', $temp_l[5]],
            ['c_assoc_kin_id', '=', $temp_l[6]],
            ['c_text_title', '=', $temp_l[7]],
        ])->update($data);
        $ori_data = $data;
        $data['c_personid'] = $c_personid;
        (new OperationRepository())->store(Auth::id(), $c_personid, 3, 'ASSOC_DATA', $data['c_personid']."-".$data['c_assoc_code']."-".$data['c_assoc_id']."-".$data['c_kin_code']."-".$data['c_kin_id']."-".$data['c_assoc_kin_code']."-".$data['c_assoc_kin_id']."-".$data['c_text_title'], $data);
        $data['c_assoc_code'] = $assoc_pair;
        $data['c_personid'] = $assoc_id;
        $data = array_except($data, ['c_assoc_id']);
        //20190118筆記 修改這邊的更新功能.
        //DB::table('ASSOC_DATA')->where([['c_assoc_id',$id], ['c_personid', $assoc_id]])->update($data);
        DB::table('ASSOC_DATA')->where([
            ['c_assoc_id', '=', $id], 
            ['c_personid', '=', $old_assoc_id],
        ])->update($data);
        return $ori_data;
    }

    public function assocStoreById(Request $request, $id)
    {
        $data = $request->all();
        $data = $this->formatSelect($data);
        $assoc_pair = $data['c_assocship_pair'];
        $data['c_personid'] = $id;
        $data = array_except($data, ['_token', 'c_assocship_pair']);
        $data['tts_sysno'] = DB::table('ASSOC_DATA')->max('tts_sysno') + 1;
        $data['c_assoc_intercalary'] = (int)($data['c_assoc_intercalary']);
        //20210204增加儲存c_inst_name_code
        //$data['c_inst_name_code'] = SocialInstCode::where('c_inst_code', $data['c_inst_code'])->first()->c_inst_name_code;
        //新增結束
        $data = (new ToolsRepository)->timestamp($data, True);
        DB::table('ASSOC_DATA')->insert($data);
        $ori_Data = $data;
        (new OperationRepository())->store(Auth::id(), $id, 1, 'ASSOC_DATA', $data['c_personid']."-".$data['c_assoc_code']."-".$data['c_assoc_id']."-".$data['c_kin_code']."-".$data['c_kin_id']."-".$data['c_assoc_kin_code']."-".$data['c_assoc_kin_id']."-".$data['c_text_title'], $data);
        $data['tts_sysno'] += 1;
        $data['c_assoc_code'] = $assoc_pair;
        $data['c_personid'] = $data['c_assoc_id'];
        $data['c_assoc_id'] = $id;
        DB::table('ASSOC_DATA')->insert($data);
        //return $data['tts_sysno'];
        return $ori_Data;
    }

    public function assocDeleteById($id, $c_personid)
    {
        //20190118筆記, 修改這邊的刪除功能.
        //$row = DB::table('ASSOC_DATA')->where('tts_sysno', $id)->first();
        //DB::table('ASSOC_DATA')->where('tts_sysno', $row->tts_sysno)->delete();
        $id = str_replace("--","-minus",$id);
        //20200709聯合主鍵保留字弱點防禦函式，解析保留字。
        $id = $this->unionPKDef_decode($id); 
        $temp_l = explode("-", $id);
        foreach($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus","-",$value);
        }        
        //20191028防止c_text_title欄位內含負號所做的字串重組
        $new_c_text_title = '';
        if(!empty($temp_l[8])) {
            for($i=7; $i<count($temp_l); $i++) {
                if(empty($new_c_text_title)) { $new_c_text_title .= $temp_l[$i]; }
                else { $new_c_text_title .= "-".$temp_l[$i]; }
            }
            $temp_l[7] = $new_c_text_title;
        }

        $row = DB::table('ASSOC_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_assoc_code', '=', $temp_l[1]],
            ['c_assoc_id', '=', $temp_l[2]],
            //20191028進行聯合主鍵的擴充修改
            ['c_kin_code', '=', $temp_l[3]],
            ['c_kin_id', '=', $temp_l[4]],
            ['c_assoc_kin_code', '=', $temp_l[5]],
            ['c_assoc_kin_id', '=', $temp_l[6]],
            ['c_text_title', '=', $temp_l[7]],
        ])->first();
        $row2 = DB::table('ASSOC_DATA')->where([
            ['c_personid',$row->c_assoc_id],
            ['c_assoc_id', $row->c_personid],
            ['c_source', $row->c_source],
        ])->first();
        DB::table('ASSOC_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_assoc_code', '=', $temp_l[1]],
            ['c_assoc_id', '=', $temp_l[2]],
            //20191028進行聯合主鍵的擴充修改
            ['c_kin_code', '=', $temp_l[3]],
            ['c_kin_id', '=', $temp_l[4]],
            ['c_assoc_kin_code', '=', $temp_l[5]],
            ['c_assoc_kin_id', '=', $temp_l[6]],
            ['c_text_title', '=', $temp_l[7]],
        ])->delete();
        (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'ASSOC_DATA', $id, $row);
        DB::table('ASSOC_DATA')->where([
            ['c_personid',$row2->c_personid],
            ['c_assoc_id', $row2->c_assoc_id],
            ['c_source', $row2->c_source],
        ])->delete();
    }

    public function sourceById($id, $_id)
    {
        //20200715聯合主鍵保留字弱點防禦函式，解析保留字。
        $_id = str_replace("--","-minus",$_id);
        $_id = $this->unionPKDef_decode($_id);
        $temp_l = explode("-", $_id);
        //20200121防止c_pages欄位內含負號所做的字串重組
        $new_c_pages = '';
        if(!empty($temp_l[3])) {
            for($i=2; $i<count($temp_l); $i++) {
                if(empty($new_c_pages)) { $new_c_pages .= $temp_l[$i]; }
                else { $new_c_pages .= "-".$temp_l[$i]; }
            }
            $temp_l[2] = $new_c_pages;
        }
        //20200121修改結束
        $row = DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_pages', '=', $temp_l[2]],
        ])->first();
        //$row = DB::table('BIOG_SOURCE_DATA')->where([['c_personid', $id], ['c_textid', $text_id]])->first();
        $text_str = null;
        if($row->c_textid || $row->c_textid === 0) {
            $text_ = TextCode::find($row->c_textid);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        return ['row' => $row, 'text_str' => $text_str];
    }

    public function sourceUpdateById(Request $request, $id, $id_)
    {
        //20200715聯合主鍵保留字弱點防禦函式，解析保留字。
        $id_ = str_replace("--","-minus",$id_);
        $id_ = $this->unionPKDef_decode($id_);
        $temp_l = explode("-", $id_);
        //20200121防止c_pages欄位內含負號所做的字串重組
        $new_c_pages = '';
        if(!empty($temp_l[3])) {
            for($i=2; $i<count($temp_l); $i++) {
                if(empty($new_c_pages)) { $new_c_pages .= $temp_l[$i]; }
                else { $new_c_pages .= "-".$temp_l[$i]; }
            }
            $temp_l[2] = $new_c_pages;
        }
        //20200121修改結束
        $row = DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_pages', '=', $temp_l[2]],
        ])->first();
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
        $data['c_personid'] = $id;
        $data['c_main_source'] = (int)$data['c_main_source'];
        $data['c_self_bio'] = (int)$data['c_self_bio'];
        DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_pages', '=', $temp_l[2]],
        ])->update($data);
        (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_SOURCE_DATA', $data['c_personid']."-".$data['c_textid']."-".$data['c_pages'], $data);
        return $data;
    }

    public function sourceStoreById(Request $request, $id)
    {
        $data = $request->all();
        $data = array_except($data, ['_token']);
        $data['c_personid'] = $id;
        $data['c_main_source'] = (int)$data['c_main_source'];
        $data['c_self_bio'] = (int)$data['c_self_bio'];
        DB::table('BIOG_SOURCE_DATA')->insert($data);
        (new OperationRepository())->store(Auth::id(), $id, 1, 'BIOG_SOURCE_DATA', $data['c_personid']."-".$data['c_textid']."-".$data['c_pages'], $data);
        return $data;
    }

    public function sourceDeleteById($id, $id_)
    {
        //20200715聯合主鍵保留字弱點防禦函式，解析保留字。
        $id_ = str_replace("--","-minus",$id_);
        $id_ = $this->unionPKDef_decode($id_);
        $temp_l = explode("-", $id_);
        //20200121防止c_pages欄位內含負號所做的字串重組
        $new_c_pages = '';
        if(!empty($temp_l[3])) {
            for($i=2; $i<count($temp_l); $i++) {
                if(empty($new_c_pages)) { $new_c_pages .= $temp_l[$i]; }
                else { $new_c_pages .= "-".$temp_l[$i]; }
            }
            $temp_l[2] = $new_c_pages;
        }
        //20200121修改結束
        //$row = DB::table('BIOG_SOURCE_DATA')->where([['c_personid', $id], ['c_textid', $id_]])->first();
        //DB::table('BIOG_SOURCE_DATA')->where([['c_personid', $id], ['c_textid', $id_]])->delete();
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
        (new OperationRepository())->store(Auth::id(), $id, 4, 'BIOG_SOURCE_DATA', $id, $row);
    }

    protected function addr_str($id)
    {
        $row = AddressCode::find($id);
        $belongs = $row->belongs1_Name." ".$row->belongs2_Name." ".$row->belongs3_Name." ".$row->belongs4_Name." ".$row->belongs5_Name;
        return $row->c_addr_id.' '.$row->c_name.' '.$row->c_name_chn.' '.trim($belongs);
    }

    protected function insertAddr(Array $c_addr, $_id, $_postingid, $_officeid)
    {
        DB::table('POSTED_TO_ADDR_DATA')->where('c_personid', $_id)->where('c_posting_id', $_postingid)->delete();
        foreach ($c_addr as $item) {
            DB::table('POSTED_TO_ADDR_DATA')->insert(
                [
                    'c_personid' => $_id,
                    'c_posting_id' => $_postingid,
                    'c_office_id' => $_officeid,
                    'c_addr_id' => $item == -999 ? 0 : $item
                ]
            );
        }
    }

    protected function insertAddrPo(Array $c_addr_id, $c_possession_record_id, $c_personid)
    {
        DB::table('POSSESSION_ADDR')->where('c_possession_record_id', $c_possession_record_id)->delete();
        foreach ($c_addr_id as $item) {
            DB::table('POSSESSION_ADDR')->insert(
                [
                    'c_personid' => $c_personid,
                    'c_possession_record_id' => $c_possession_record_id,
                    'c_addr_id' => $item == -999 ? 0 : $item
                ]
            );
        }
    }

    protected function insertAddrEvent(Array $c_addr_id, $c_event_record_id, $c_personid)
    {
        DB::table('EVENTS_ADDR')->where('c_event_record_id', $c_event_record_id)->delete();
        foreach ($c_addr_id as $item) {
            DB::table('EVENTS_ADDR')->insert(
                [
                    'c_personid' => $c_personid,
                    'c_event_record_id' => $c_event_record_id,
                    'c_addr_id' => $item == -999 ? 0 : $item
                ]
            );
        }
    }

    protected function formatSelect(Array $array)
    {
        foreach ($array as $key => $value){
            if ($value == -999) $array[$key] = 0;
        }
        return $array;
    }

    //20200709聯合主鍵保留字弱點防禦函式
    public function unionPKDef($key)
    {
        $key = str_replace("/","(slash)",$key);
        //因為反斜線在php有用途, 兩個反斜線代表一個反斜線.
        $key = str_replace("\\","(backslash)",$key);
        $key = str_replace("{","(brackets)",$key);
        $key = str_replace("}","(brackets_r)",$key);
        $result = $key;
        return $result;
    }

    //20200709欄位值解析保留字
    function unionPKDef_decode($key)
    {
        $key = str_replace("(slash)","/",$key);
        $key = str_replace("(backslash)","\\",$key);
        $key = str_replace("(brackets)","{",$key);
        $key = str_replace("(brackets_r)","}",$key);
        $result = $key;
        return $result;
    }

}
