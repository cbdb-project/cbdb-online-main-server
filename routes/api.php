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

Route::group(['middleware' => 'auth:api'], function () {
    Route::post('/name', function (Request $request)    {
        $biogmianrepository = new \App\Repositories\BiogMainRepository();
        return $biogmianrepository->namesByQuery($request);
    });

    Route::post('/addresscode', function (Request $request) {
        $addrcoderepository = new \App\Repositories\AddrCodeRepository();
        return $addrcoderepository->addrByQuery($request);
    });

    Route::post('/altnamecode', function (Request $request) {
        $altcoderepository = new \App\Repositories\AltCodeRepository();
        return $altcoderepository->altByQuery($request);
    });

    Route::post('/appointcode', function (Request $request) {
        $appcoderepository = new \App\Repositories\AppointCodeRepository();
        return $appcoderepository->appointByQuery($request);
    });
});

Route::group(['middleware' => 'auth:api', 'prefix' => 'select'], function (){
    Route::get('ethnicity', 'ApiController@ethnicity');
    Route::get('choronym', 'ApiController@choronym');
    Route::get('dynasty', 'ApiController@dynasty');
    Route::get('nianhao', 'ApiController@nianhao');
    Route::get('codes', 'ApiController@codes');
});
