<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/14
 * Time: 12:46
 */

namespace App\Repositories;


use App\BiogMain;
use App\NameList;
use Illuminate\Http\Request;


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
        $basicinformation = BiogMain::withCount('sources', 'texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc')->find($id);
        return $basicinformation;
    }

    /**
     * @param $id
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function simpleByPersonId($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc')->find($id);
        return $basicinformation;
    }

    public function byIdWithAddr($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc')->with('addresses')->find($id);
        return $basicinformation;
    }

    public function byIdWithAlt($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc')->with('altnames')->find($id);
        return $basicinformation;
    }

    public function byIdWithText($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc')->with('texts')->find($id);
        return $basicinformation;
    }

    public function byIdWithOff($id)
    {
        $basicinformation = BiogMain::select(['c_personid', 'c_name_chn', 'c_name'])->withCount('sources','texts', 'addresses', 'altnames', 'offices', 'entries', 'statuses', 'kinship', 'assoc')->with('offices')->find($id);
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
     * @param BiogMain $basicinformation
     * return all of JSON
     */
    private function normalizeBasicInfo(BiogMain $basicinformation)
    {
        $basicinformation->dynasty;
        $basicinformation->birthYearNH;
        $basicinformation->deathYearNH;
        $basicinformation->choronym;
        $basicinformation->ethnicity;
    }

    /**
     * @param BiogMain $basicinformation
     * reduce size of JSON
     */
    private function simpleNormalizeBasinInfo(BiogMain $basicinformation)
    {
        $basicinformation->simpleDynasty;
        $basicinformation->simpleDirthYearNH;
        $basicinformation->simpleDeathYearNH;
        $basicinformation->choronym;
        $basicinformation->simpleEthnicity;
    }
}