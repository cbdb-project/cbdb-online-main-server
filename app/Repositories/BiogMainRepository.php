<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/14
 * Time: 12:46
 */

namespace App\Repositories;


use App\BiogMain;
use Illuminate\Http\Request;


/**
 * Class BiogMainRepository
 * @package App\Repositories
 */
class BiogMainRepository
{
    /**
     * @param $id
     * @return BiogMain
     */
    public function byPersonId($id)
    {
        $basicinformation = BiogMain::find($id);
        $this->normalizeBasicInfo($basicinformation);
        return $basicinformation;
    }

    /**
     * @param $request
     * @param $id
     */
    public function updateById($request, $id)
    {
        $biogbasicinformation = BiogMain::find($id);
        $biogbasicinformation->update($request->all());
    }

    /**
     * @param $request
     * @param $num
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function namesByQuery(Request $request, $num=20)
    {
        if ($temp = $request->query('num')){
            $num = $temp;
        }
        $names = \App\BiogMain::select(['c_personid', 'c_name', 'c_name_chn'])->where('c_name_chn', 'like', '%'.$request->query('q').'%')->paginate($num);
        $names->appends(['q' => $request->query('q'), 'num' => $num])->links();
        return $names;
    }

    /**
     * @param BiogMain $basicinformation
     */
    private function normalizeBasicInfo(BiogMain $basicinformation)
    {
        $basicinformation->dynasty;
        $basicinformation->birthYearNH;
        $basicinformation->deathYearNH;
        $basicinformation->choronym;
//        $basicinformation->ethnicity;
    }
}