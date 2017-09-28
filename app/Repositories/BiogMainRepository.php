<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/14
 * Time: 12:46
 */

namespace App\Repositories;


use App\AddressCode;
use App\BiogMain;
use App\NameList;
use App\OfficeCode;
use App\SocialInst;
use App\TextCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


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
        $basicinformation = BiogMain::withCount('sources', 'texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->find($id);
        return $basicinformation;
    }

    /**
     * @param $id
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function simpleByPersonId($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->find($id);
        return $basicinformation;
    }

    public function byIdWithAddr($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->with('addresses', 'addresses_type')->find($id);
        return $basicinformation;
    }

    public function byIdWithAlt($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->with('altnames')->find($id);
        return $basicinformation;
    }

    public function byIdWithText($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->with('texts', 'texts_role')->find($id);
        return $basicinformation;
    }

    public function byIdWithOff($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->with('offices', 'offices_addr')->find($id);
        return $basicinformation;
    }
    public function byIdWithEntries($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->with('entries')->find($id);
        return $basicinformation;
    }
    public function byIdWithStatuses($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->with('statuses')->find($id);
        return $basicinformation;
    }
    public function byIdWithAssoc($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->with('assoc', 'assoc_name')->find($id);
        return $basicinformation;
    }
    public function byIdWithKinship($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->with('kinship', 'kinship_name')->find($id);
        return $basicinformation;
    }
    public function byIdWithPossession($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->with('possession')->find($id);
        return $basicinformation;
    }

    public function byIdWithSocialInst($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc', 'possession', 'inst')->with('inst', 'inst_name')->find($id);
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
        $biogbasicinformation = BiogMain::find($id);
        $biogbasicinformation->update($data);
    }

    /**
     * @param $request
     * @param $num
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function namesByQuery(Request $request, $num=20)
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
        $row = DB::table('TEXT_DATA')->where('tts_sysno', $id_)->first();
        $text = null;
        if($row->c_textid || $row->c_textid === 0) {
            $text_ = TextCode::find($row->c_textid);
            $text = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }
        $text_str = null;
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        return ['row' => $row, 'text' => $text, 'text_str' => $text_str];
    }

    public function textUpdateById( )
    {

    }

    public function officeById($id)
    {
        $row = DB::table('POSTED_TO_OFFICE_DATA')->where('tts_sysno', $id)->first();
        $text_str = null;
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }
        $office_str = null;
        if($row->c_office_id || $row->c_office_id === 0) {
            $text_ = OfficeCode::find($row->c_office_id);
            $office_str = $text_->c_office_id." ".$text_->c_office_pinyin." ".$text_->c_office_chn;
        }
        $posting_str = null;
        if($row->c_posting_id || $row->c_posting_id === 0) {
            $text_ = SocialInst::find($row->c_office_id);
            $posting_str = $text_->c_inst_name_code." ".$text_->c_inst_name_py." ".$text_->c_inst_name_hz;
        }

        $addr_ = DB::table('POSTED_TO_ADDR_DATA')->where('c_personid', $row->c_personid)->where('c_posting_id', $row->c_posting_id)->get();
        $addr_str = [];
        foreach ($addr_ as $key=>$value) {
            $id_ = $value->c_addr_id == 0 ? -999 : $value->c_addr_id;
            $item = [$id_, $this->addr_str($value->c_addr_id)];
            $addr_str[$key] = $item;
        }
        return ['row' => $row, 'text_str' => $text_str, 'office_str' => $office_str, 'posting_str' => $posting_str, 'addr_str' => $addr_str];
    }

    public function officeUpdateById(Request $request, $id)
    {
        $data = $request->all();
//        dd($data);
        $_id = $data['_id'];
        $_postingid = $data['_postingid'];
        $c_addr = $data['c_addr'];
        $_officeid = $data['_officeid']; //目前与officeid无关

        $this->insertAddr($c_addr, $_id, $_postingid, $_officeid);
        $data = array_except($data, ['_method', '_token', 'c_addr', '_id', '_postingid', '_officeid']);
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);
        $data['c_office_id'] = $data['c_office_id'] == -999 ? '0' : $data['c_office_id'];
        $data['c_inst_code'] = $data['c_inst_code'] == -999 ? '0' : $data['c_inst_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        DB::table('POSTED_TO_OFFICE_DATA')->where('tts_sysno',$id)->update($data);
    }

    public function officeStoreById(Request $request, $id)
    {
        $data = $request->all();
//        dd($data);
        $c_addr = $data['c_addr'];
        $data = array_except($data, ['_token', 'c_addr']);
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);
        $data['tts_sysno'] = DB::table('POSTED_TO_OFFICE_DATA')->max('tts_sysno') + 1;
        $data['c_posting_id'] = DB::table('POSTED_TO_OFFICE_DATA')->max('c_posting_id') + 1;
        $data['c_personid'] = $id;
        $this->insertAddr($c_addr, $id, $data['c_posting_id'], $data['c_office_id']);
        DB::table('POSTED_TO_OFFICE_DATA')->insert($data);
        return $data['tts_sysno'];
    }

    public function officeDeleteById($id)
    {
        $row = DB::table('POSTED_TO_OFFICE_DATA')->where('tts_sysno', $id)->first();
//        dd($row);
        $op = [
            'op_type' => 4,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => $id,
            'resource_data' => json_encode((array)$row)
        ];
        $operationRepository = new OperationRepository();
        $operationRepository->store($op);
        DB::table('POSTED_TO_OFFICE_DATA')->where('tts_sysno', $id)->delete();
        DB::table('POSTED_TO_ADDR_DATA')->where('c_personid', $row->c_personid)->where('c_posting_id', $row->c_posting_id)->delete();
    }

    protected function addr_str($id)
    {
        $row = AddressCode::find($id);
        return $row->c_addr_id.' '.$row->c_name.' '.$row->c_name_chn;
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
}