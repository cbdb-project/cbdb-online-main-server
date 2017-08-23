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

Route::middleware('auth:api')->post(/**
 * @param Request $request
 * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
 */
    '/name', function (Request $request) {
    $biogmianrepository = new \App\Repositories\BiogMainRepository();
    return $biogmianrepository->namesByQuery($request);
});

Route::group(['middleware' => 'auth:api', 'prefix' => 'select'], function (){
    Route::get('ethnicity', 'ApiController@ethnicity');
    Route::get('choronym', 'ApiController@choronym');
    Route::get('dynasty', 'ApiController@dynasty');
    Route::get('nianhao', 'ApiController@nianhao');
});
