<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/14
 * Time: 12:46
 */

namespace App\Repositories;


use App\BiogMain;


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

    private function normalizeBasicInfo(BiogMain $basicinformation)
    {
        $basicinformation->dynasty;
        $basicinformation->birthYearNH;
        $basicinformation->deathYearNH;
        $basicinformation->choronym;
    }
}