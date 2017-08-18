<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get(/**
 * @param Request $request
 * @return mixed
 */
    '/user', function (Request $request) {
    return $request->user();
});

//Route::get('/altnames', function (Request $request) {
//    $altnames = \App\AltnameCode::select(['c_name_type_code', 'c_name_type_desc_chn'])->where('c_name_type_desc_chn', 'like', '%'.$request->query('q').'%')->get();
//    return $altnames;
//});

Route::middleware('auth:api')->get(/**
 * @param Request $request
 * @return mixed
 */
    '/testaauth', function (Request $request) {
    return ['test' => 'Oauth'];
});

Route::middleware('api')->post(/**
 * @param Request $request
 * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
 */
    '/name', function (Request $request) {
    $biogmianrepository = new \App\Repositories\BiogMainRepository();
    return $biogmianrepository->namesByQuery($request);
});
