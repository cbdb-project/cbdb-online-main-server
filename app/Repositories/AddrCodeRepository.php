<?php

/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/30
 * Time: 14:43
 */

namespace App\Repositories;

use App\Models\AddrBelong;
use App\Models\AddrCode;
use App\Models\AddressCode;
use App\Support\ExactCodeMatchGuard;
use Illuminate\Http\Request;

/**
 * Class AddrCodeRepository
 * @package App\Repositories
 */
class AddrCodeRepository {
    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function all() {
        return AddressCode::paginate(200);
    }

    /**
     * @param $request
     * @param $num
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public static function addrByQuery(Request $request, $num = 20) {
        if ($temp = $request->num) {
            $num = $temp;
        }
        if (!$request->q) {
            return AddressCode::select(['c_addr_id', 'c_name_chn', 'c_name'])->paginate($num);
        }
        $names = AddressCode::select(['c_addr_id', 'c_name_chn', 'c_name'])->where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->when(ExactCodeMatchGuard::isNumeric($request->q), fn ($q) => $q->orWhere('c_addr_id', $request->q))->paginate($num);
        $names->appends(['q' => $request->q])->links();

        return $names;
    }

    public function byId($id) {
        return AddressCode::find($id);
    }

    public function updateById($request, $id) {
        $data = $request->all();
        $addrcode = AddressCode::find($id);
        $addrcode->update($data);
    }
    // public function searchAddr(Request $request)
    // {
    //     $data = AddrCode::where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->orWhere('c_addr_id', $request->q)->paginate(20);
    //     $data->appends(['q' => $request->q])->links();
    //     foreach($data as $item){
    //         $item['id'] = $item->c_addr_id;
    //         if($item['id'] === 0) $item['id'] = -999;
    //         $belongs = "";
    //         //20190115修改,地址查詢的時候希望組出來地址和上層行政單位
    //         //$item['text'] = $item->c_addr_id." ".$item->c_name." ".$item->c_name_chn." ".trim($belongs)." ".$item->c_firstyear."~".$item->c_lastyear;
    //         $originalText = $item->c_addr_id." ".$item->c_name." ".$item->c_name_chn." ".trim($belongs)." ".$item->c_firstyear."~".$item->c_lastyear;
    //         $add = "";
    //         $dy = AddrBelong::where('c_addr_id', $item['id'])->value('c_belongs_to');
    //         $dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
    //         if($dy == null) {
    //             $dy = 0;
    //             $add = "";
    //         }
    //         else {
    //             $dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
    //             $add = "[[".$dy." ".$dy2."]]";
    //         }
    //         $item['text'] = $originalText." ".$add;
    //     }
    //     return $data;
    // }

    public function searchAddr(Request $request) {
        $query = AddrCode::where(function ($q) use ($request) {
            $q->where('c_name_chn', 'like', '%'.$request->q.'%')
                ->orWhere('c_name', 'like', '%'.$request->q.'%')
                ->when(ExactCodeMatchGuard::isNumeric($request->q), fn ($q2) => $q2->orWhere('c_addr_id', $request->q));
        });

        // 根據朝代起止年過濾地址：地址的 c_firstyear~c_lastyear 必須與朝代起止年有交集
        // 若用戶輸入純數字（以 ID 搜尋），則不加朝代限制
        $isNumericSearch = ctype_digit((string) $request->q);
        $dyStart = is_numeric($request->dy_start) ? (int) $request->dy_start : null;
        $dyEnd = is_numeric($request->dy_end) ? (int) $request->dy_end : null;
        if ($dyStart !== null && $dyEnd !== null && !$isNumericSearch) {
            $query->where(function ($q) use ($dyStart, $dyEnd) {
                $q->where(function ($inner) use ($dyStart, $dyEnd) {
                    // 時間交集條件：addr.firstyear <= dy.end AND addr.lastyear >= dy.start
                    $inner->where('c_firstyear', '<=', $dyEnd)
                        ->where('c_lastyear', '>=', $dyStart);
                })
                // 年份為 NULL 或 0 的地址不過濾（無法判斷時期）
                ->orWhereNull('c_firstyear')
                ->orWhereNull('c_lastyear')
                ->orWhere('c_firstyear', 0)
                ->orWhere('c_lastyear', 0)
                // 「未詳」地址始終顯示
                ->orWhere('c_addr_id', 0);
            });
        }

        $data = $query->paginate(20);
        $data->appends(['q' => $request->q, 'dy_start' => $request->dy_start, 'dy_end' => $request->dy_end])->links();
        foreach ($data as $item) {
            $item['id'] = $item->c_addr_id;
            if ($item['id'] === 0) {
                $item['id'] = -999;
            }
            $belongs = "";
            $originalText = $item->c_addr_id." ".$item->c_name." ".$item->c_name_chn." ".trim($belongs)." ".$item->c_firstyear."~".$item->c_lastyear;
            $add = [];
            $dy = AddrBelong::where('c_addr_id', '=', $item->c_addr_id)->get();
            if ($dy->isEmpty()) {
                $add[] = "";
            } else {
                foreach ($dy as $d) {
                    //找出上一層資料
                    $dy2 = AddrCode::where('c_addr_id', '=', $d->c_belongs_to)->first();
                    if (!$dy2->empty) {
                        $add_str = "[[".$dy2->c_addr_id." ".$dy2->c_name_chn." ".$dy2->c_firstyear."~".$dy2->c_lastyear."]]";
                        $add[] = $add_str;
                    } else {
                        $add[] = "";
                    }
                }
            }
            $item['text'] = trim($originalText." ".$add[0]);
            if (count($add) > 1) {
                for ($i = 1; $i < count($add); $i++) {
                    $append_item = $item->replicate();
                    $append_item['text'] = trim($originalText." ".$add[$i]);
                    $append_item['c_addr_id'] = $item->c_addr_id;
                    $data->push($append_item);
                }
            }
        }

        $sortedResult = $data->getCollection()->sortBy('id')->values();
        $data->setCollection($sortedResult);

        return $data;
    }
}
