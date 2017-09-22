<?php

namespace App\Http\Controllers;

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
use App\TextCode;
use Illuminate\Http\Request;
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

    public function searchText(Request $request){
        $data = TextCode::select(['c_textid', 'c_title_chn', 'c_title'])->where('c_title_chn', 'like', '%'.$request->q.'%')->orWhere('c_title', 'like', '%'.$request->q.'%')->orWhere('c_textid', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_textid;
            $item['text'] = $item->c_textid." ".$item->c_title." ".$item->c_title_chn;
        }
        return $data;
    }

    public function searchOffice(Request $request){
        $data = OfficeCode::select(['c_office_id', 'c_office_pinyin', 'c_office_chn'])->where('c_office_chn', 'like', '%'.$request->q.'%')->orWhere('c_office_pinyin', 'like', '%'.$request->q.'%')->orWhere('c_office_id', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_office_id;
            $item['text'] = $item->c_office_id." ".$item->c_office_pinyin." ".$item->c_office_chn;
        }
        return $data;
    }

    public function socialinst(Request $request)
    {
        $data = SocialInst::where('c_inst_name_hz', 'like', '%'.$request->q.'%')->orWhere('c_inst_name_py', 'like', '%'.$request->q.'%')->orWhere('c_inst_name_code', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_inst_name_code;
            $item['text'] = $item->c_inst_name_code." ".$item->c_inst_name_py." ".$item->c_inst_name_hz;
        }
        return $data;
    }
}
