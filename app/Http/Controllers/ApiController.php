<?php

namespace App\Http\Controllers;

use App\AddressCode;
use App\AssocCode;
use App\BiogMain;
use App\Dynasty;
use App\EntryCode;
use App\EventCode;
use App\KinshipCode;
use App\OfficeCode;
use App\Repositories\AddrCodeRepository;
use App\Repositories\AltCodeRepository;
use App\Repositories\BiogAddrCodeRepository;
use App\Repositories\BiogMainRepository;
use App\Repositories\ChoronymRepository;
use App\Repositories\DynastyRepository;
use App\Repositories\EthnicityRepository;
use App\Repositories\NianHaoRepository;
use App\Repositories\YearRangeRepository;
use App\SocialInst;
use App\StatusCode;
use App\TextCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class ApiController extends Controller
{
    public function ethnicity()
    {
        $ethnicityRepository = new EthnicityRepository();
        return $ethnicityRepository->ethnicities();
    }

    public function choronym()
    {
        $choronymRepository = new ChoronymRepository();
        return $choronymRepository->choronyms();
    }

    public function dynasty()
    {
        $dynastyRepository = new DynastyRepository();
        return $dynastyRepository->dynasties();
    }

    public function nianhao()
    {
        $nianhaoRepository = new NianHaoRepository();
        return $nianhaoRepository->nianhaos();
    }

    public function biogaddr()
    {
        $biogaddrRepository = new BiogAddrCodeRepository();
        return $biogaddrRepository->biogaddr();
    }

    public function altcode()
    {
        $altcodeRepository = new AltCodeRepository();
        return $altcodeRepository->altcode();
    }

    public function role()
    {
        return DB::table('TEXT_ROLE_CODES')->select(['c_role_id', 'c_role_desc', 'c_role_desc_chn'])->get();
    }

    public function searchAddr(Request $request)
    {
        $addrcodeRepository = new AddrCodeRepository();
        $data = $addrcodeRepository->searchAddr($request);
        return $data;
    }
    public function searchOfficeAddr(Request $request)
    {
        $addrcodeRepository = new AddrCodeRepository();
        $data = $addrcodeRepository->searchOfficeAddr($request);
        return $data;
    }

    public function range()
    {
        $yearrangeRepository = new YearRangeRepository();
        return $yearrangeRepository->yearRange();
    }

    public function ganzhi()
    {
        return DB::table('GANZHI_CODES')->get();
    }

    public function household()
    {
        return DB::table('HOUSEHOLD_STATUS_CODES')->get();
    }

    public function appttype()
    {
        return DB::table('APPOINTMENT_TYPE_CODES')->get();
    }

    public function assumeoffice()
    {
        return DB::table('ASSUME_OFFICE_CODES')->get();
    }

    public function officecate()
    {
        return DB::table('OFFICE_CATEGORIES')->get();
    }

    public function parentstatus()
    {
        return DB::table('PARENTAL_STATUS_CODES')->get();
    }

    public function measure()
    {
        return DB::table('MEASURE_CODES')->get();
    }

    public function possact()
    {
        return DB::table('POSSESSION_ACT_CODES')->get();
    }

    public function birole()
    {
        return DB::table('BIOG_INST_CODES')->get();
    }

    public function topic()
    {
        return DB::table('SCHOLARLYTOPIC_CODES')->select('c_topic_code', 'c_topic_desc', 'c_topic_desc_chn', 'c_topic_type_desc', 'c_topic_type_desc_chn')->get();
    }

    public function occasion()
    {
        return DB::table('OCCASION_CODES')->get();
    }

    public function searchText(Request $request){
        $data = TextCode::where('c_title_chn', 'like', '%'.$request->q.'%')->orWhere('c_title', 'like', '%'.$request->q.'%')->orWhere('c_textid', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_textid;
            if($item['id'] === 0) $item['id'] = -999;
            $item['text'] = $item->c_textid." ".$item->c_title." ".$item->c_title_chn;
        }
        return $data;
    }

    public function searchOffice(Request $request){
        $data = OfficeCode::where('c_office_chn', 'like', '%'.$request->q.'%')->orWhere('c_office_pinyin', 'like', '%'.$request->q.'%')->orWhere('c_office_id', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_office_id;
            if($item['id'] === 0) $item['id'] = -999;
            $dy = Dynasty::where('c_dy', $item->c_dy)->first()->c_dynasty_chn;
            $item['text'] = $item->c_office_id." ".$item->c_office_pinyin." ".$item->c_office_chn." ".$dy;
        }
        return $data;
    }

    public function socialinst(Request $request)
    {
        $data = SocialInst::where('c_inst_name_hz', 'like', '%'.$request->q.'%')->orWhere('c_inst_name_py', 'like', '%'.$request->q.'%')->orWhere('c_inst_name_code', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_inst_name_code;
            if($item['id'] === 0) $item['id'] = -999;
            $item['text'] = $item->c_inst_name_code." ".$item->c_inst_name_py." ".$item->c_inst_name_hz;
        }
        return $data;
    }

    public function searchEntry(Request $request)
    {
        $data = EntryCode::where('c_entry_desc_chn', 'like', '%'.$request->q.'%')->orWhere('c_entry_desc', 'like', '%'.$request->q.'%')->orWhere('c_entry_code', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_entry_code;
            if($item['id'] === 0) $item['id'] = -999;
            $item['text'] = $item->c_entry_code." ".$item->c_entry_desc_chn." ".$item->c_entry_desc;
        }
        return $data;
    }

    public function searchKincode(Request $request)
    {
        $data = KinshipCode::where('c_kinrel_chn', 'like', '%'.$request->q.'%')->orWhere('c_kinrel', 'like', '%'.$request->q.'%')->orWhere('c_kincode', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_kincode;
            if($item['id'] === 0) $item['id'] = -999;
            $item['text'] = $item->c_kincode." ".$item->c_kinrel_chn." ".$item->c_kinrel;
        }
        return $data;
    }

    public function searchAssoccode(Request $request)
    {
        $data = AssocCode::where('c_assoc_desc', 'like', '%'.$request->q.'%')->orWhere('c_assoc_desc_chn', 'like', '%'.$request->q.'%')->orWhere('c_assoc_code', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_assoc_code;
            if($item['id'] === 0) $item['id'] = -999;
            $item['text'] = $item->c_assoc_code." ".$item->c_assoc_desc_chn." ".$item->c_assoc_desc;
        }
        return $data;
    }

    public function searchStatuscode(Request $request)
    {
        $data = StatusCode::where('c_status_desc', 'like', '%'.$request->q.'%')->orWhere('c_status_desc_chn', 'like', '%'.$request->q.'%')->orWhere('c_status_code', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_status_code;
            if($item['id'] === 0) $item['id'] = -999;
            $item['text'] = $item->c_status_code." ".$item->c_status_desc_chn." ".$item->c_status_desc;
        }
        return $data;
    }

    public function searchBiog(Request $request)
    {
        $data = BiogMain::select(['c_personid', 'c_name_chn', 'c_name', 'c_index_year', 'c_dy'])->where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->orWhere('c_personid', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_personid;
            if($item['id'] === 0) $item['id'] = -999;
            $dy = Dynasty::where('c_dy', $item->c_dy)->first()->c_dynasty_chn;
            $item['text'] = $item->c_personid." ".$item->c_name_chn." ".$item->c_name." ".$dy." index_year:".$item->c_index_year;
        }
        return $data;
    }

    public function searchEvent(Request $request)
    {
        $data = EventCode::where('c_event_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_event_name', 'like', '%'.$request->q.'%')->orWhere('c_event_code', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_event_code;
            if($item['id'] === 0) $item['id'] = -999;
            $item['text'] = $item->c_event_code." ".$item->c_event_name_chn." ".$item->c_event_name;
        }
        return $data;
    }

    public function codeAddr(Request $request)
    {
        $num = is_null($request->num) ? 20 : $request->num;
        $data = AddressCode::where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->orWhere('c_addr_id', $request->q)->paginate($num);
        $data->appends(['q' => $request->q])->links();
        return $data;
    }
}
