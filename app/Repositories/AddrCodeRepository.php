<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/30
 * Time: 14:43
 */
namespace App\Repositories;
use App\AddrCode;
use App\AddressCode;
use App\AddrBelong;
use Illuminate\Http\Request;
/**
 * Class AddrCodeRepository
 * @package App\Repositories
 */
class AddrCodeRepository
{
    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function all()
    {
        return AddressCode::paginate(200);
    }
    /**
     * @param $request
     * @param $num
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    static public function addrByQuery(Request $request, $num=20)
    {
        if ($temp = $request->num){
            $num = $temp;
        }
        if (!$request->q){
            return AddressCode::select(['c_addr_id', 'c_name_chn', 'c_name'])->paginate($num);
        }
        $names = AddressCode::select(['c_addr_id', 'c_name_chn', 'c_name'])->where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->orWhere('c_addr_id', $request->q)->paginate($num);
        $names->appends(['q' => $request->q])->links();
        return $names;
    }
    public function byId($id)
    {
        return AddressCode::find($id);
    }
    public function updateById($request, $id)
    {
        $data = $request->all();
        $addrcode = AddressCode::find($id);
        $addrcode->update($data);
    }
    public function searchAddr(Request $request)
    {
        $data = AddrCode::where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->orWhere('c_addr_id', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_addr_id;
            if($item['id'] === 0) $item['id'] = -999;
            $belongs = "";
            //20190115修改,地址查詢的時候希望組出來地址和上層行政單位
            //$item['text'] = $item->c_addr_id." ".$item->c_name." ".$item->c_name_chn." ".trim($belongs)." ".$item->c_firstyear."~".$item->c_lastyear;
            $originalText = $item->c_addr_id." ".$item->c_name." ".$item->c_name_chn." ".trim($belongs)." ".$item->c_firstyear."~".$item->c_lastyear;
            $add = "";
            $dy = AddrBelong::where('c_addr_id', $item['id'])->value('c_belongs_to');
            $dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
            if($dy == null) { 
                $dy = 0; $add = ""; 
            }
            else {
                $dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
                $add = "[[".$dy." ".$dy2."]]"; 
            }
            $item['text'] = $originalText." ".$add;
        }
        return $data;
    }
    public function searchOfficeAddr(Request $request)
    {
        $data = AddressCode::where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->orWhere('c_addr_id', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_addr_id == 0 ? -999 : $item->c_addr_id;
            $belongs = $item->belongs1_ID." ".$item->belongs1_Name." ".$item->belongs2_ID." ".$item->belongs2_Name." ".$item->belongs3_ID." ".$item->belongs3_Name." ".$item->belongs4_ID." ".$item->belongs4_Name." ".$item->belongs5_ID." ".$item->belongs5_Name;
            $item['text'] = $item->c_addr_id." ".$item->c_name." ".$item->c_name_chn." ".trim($belongs);
        }
        return $data;
    }
}
