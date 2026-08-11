<?php

namespace App;

use Auth;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class v1 extends Model {
    public function info() {
        return phpinfo();
    }

    // token() 已於 P0-2 下架（連同 GET /api/v1/user 路由與 ApiController@userC_presonid）：
    // 它用密碼換 confirmation_token，卻既不查 isActive() 也不查眾包身分，是
    // Api\OperationsController@token 那道閘門的直通後門。取得 token 請改用
    // POST /api/operations/token（該處已具備完整閘門）。

    public function search(Request $request) {
        // #85 + §D-8：$request->q 主要形式，$qForms 為綁定用的 v／ü 展開集。
        $rawQ = $request->q ?? '';
        $request->q = \App\Support\PinyinSearchNormalizer::umlautToV($rawQ);
        $qForms = \App\Support\PinyinSearchNormalizer::expand($rawQ);
        $data = BiogMain::where(function ($sub) use ($request, $qForms) {
            $sub->where('c_name_chn', 'like', $request->q)
                ->when(\App\Support\ExactCodeMatchGuard::isNumeric($request->q), fn ($q) => $q->orWhere('c_personid', $request->q));
            foreach ($qForms as $form) {
                $sub->orWhere('c_name', 'like', $form);
            }
        })->paginate(10);
        $data->appends(['q' => $request->q])->links();

        return $data;
    }

    public function addC(Request $request) {
        $c_name_chn = $request->c_name_chn;
        $c_name = $request->c_name;
        $temp_id = BiogMain::max('c_personid') + 1;
        $c_created_date = Carbon::now()->format('Ymd');
        $biog = BiogMain::create(['c_personid' => $temp_id,'c_name_chn' => $c_name_chn,'c_name' => $c_name,'c_created_by' => 'Api','c_created_date' => $c_created_date]);

        return "已經新增此筆資料 \n $biog";
    }

    public function updateC(Request $request) {
        $id = $request->q;
        $c_name_chn = $request->c_name_chn;
        $c_name = $request->c_name;
        $biog = BiogMain::find($id);
        if (empty($biog)) {
            return "沒有此筆資料";
        }
        if (!empty($c_name_chn)) {
            $biog->c_name_chn = $c_name_chn;
        }
        if (!empty($c_name)) {
            $biog->c_name = $c_name;
        }
        $biog->save();

        return "已經更新此筆資料 \n $biog";
    }

    public function deleteC(Request $request) {
        $id = $request->q;
        $biog = BiogMain::find($id);
        $biog->c_name_chn = '<待删除>';
        $biog->save();

        //這行是重要的,要把紀錄寫到operation
        //$this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_MAIN', $id, []);
        return "已經將此筆資料設定為刪除";
    }
}
