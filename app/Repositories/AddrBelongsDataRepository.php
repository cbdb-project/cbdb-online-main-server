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
use App\AddrBelongsData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class AddrCodeRepository
 * @package App\Repositories
 */
class AddrBelongsDataRepository
{

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function all()
    {
        return AddrBelongsData::paginate(200);
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
            return AddrBelongsData::select(['c_addr_id', 'c_firstyear', 'c_lastyear', 'c_belongs_to'])->paginate($num);
        }
        $names = AddrBelongsData::select(['c_addr_id', 'c_firstyear', 'c_lastyear', 'c_belongs_to'])->where('c_addr_id', 'like', '%'.$request->q.'%')->orWhere('c_firstyear', 'like', '%'.$request->q.'%')->orWhere('c_lastyear', $request->q)->paginate($num);
        $names->appends(['q' => $request->q])->links();
        return $names;
    }

    public function byId($id)
    {
        //return AddrBelongsData::find($id);
        $id_l = explode("-", $id);
        $row = AddrBelongsData::where([
            ['c_addr_id', '=', $id_l[0]],
            ['c_belongs_to', '=', $id_l[1]],
            ['c_firstyear', '=', $id_l[2]],
            ['c_lastyear', '=', $id_l[3]],
        ])->first();
        return $row;
    }

    public function updateById($request, $id)
    {
        $id_l = explode("-", $id);
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
        $table_name = "ADDR_BELONGS_DATA";
        $row = DB::table($table_name)->where([
            ['c_addr_id', '=', $id_l[0]],
            ['c_belongs_to', '=', $id_l[1]],
            ['c_firstyear', '=', $id_l[2]],
            ['c_lastyear', '=', $id_l[3]],
        ])->update($data);
    }

    public function searchAddr(Request $request)
    {
        $data = AddrCode::where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->orWhere('c_addr_id', $request->q)->paginate(20);
        $data->appends(['q' => $request->q])->links();
        foreach($data as $item){
            $item['id'] = $item->c_addr_id;
            if($item['id'] === 0) $item['id'] = -999;
            $belongs = "";
            $item['text'] = $item->c_addr_id." ".$item->c_name." ".$item->c_name_chn." ".trim($belongs)." ".$item->c_firstyear."~".$item->c_lastyear;
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
