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

Route::group([], function () {
    Route::post('name', function (Request $request)    {
        return \App\Repositories\BiogMainRepository::namesByQuery($request);
    });

    Route::post('addresscode', function (Request $request) {
        return \App\Repositories\AddrCodeRepository::addrByQuery($request);
    });

    Route::post('altnamecode', function (Request $request) {
        $altcoderepository = new \App\Repositories\AltCodeRepository();
        return $altcoderepository->altByQuery($request);
    });

    Route::post('appointcode', function (Request $request) {
        $appcoderepository = new \App\Repositories\AppointCodeRepository();
        return $appcoderepository->appointByQuery($request);
    });
    //20181105建安新增
    Route::post('textcode', function (Request $request) {
        $textcoderepository = new \App\Repositories\TextCodeRepository();
        return $textcoderepository->textByQuery($request);
    });
    //20201111建安新增
    Route::post('textinstancedata', function (Request $request) {
        $textinstancedatarepository = new \App\Repositories\TextInstanceDataRepository();
        return $textinstancedatarepository->textByQuery($request);
    });
    Route::post('addrbelongsdata', function (Request $request) {
        $addrbelongsdatarepository = new \App\Repositories\AddrBelongsDataRepository();
        return $addrbelongsdatarepository->AddrByQuery($request);
    });
    Route::post('addrcode', function (Request $request) {
        $addrcoderepository = new \App\Repositories\AddrCode2Repository();
        return $addrcoderepository->addrByQuery($request);
    });
    Route::post('officecode', function (Request $request) {
        $officecoderepository = new \App\Repositories\OfficeCodeRepository();
        return $officecoderepository->officeByQuery($request);
    });
    Route::post('socialinstitutioncode', function (Request $request) {
        $socialinstitutioncoderepository = new \App\Repositories\SocialInstitutionCodeRepository();
        return $socialinstitutioncoderepository->socialinstitutionByQuery($request);
    });
});

Route::group(['prefix' => 'select'], function (){
    Route::get('ethnicity', 'ApiController@ethnicity');
    Route::get('choronym', 'ApiController@choronym');
    Route::get('dynasty', 'ApiController@dynasty');
    Route::get('nianhao', 'ApiController@nianhao');
    Route::get('codes', 'ApiController@codes');
    Route::get('biogaddr', 'ApiController@biogaddr');
    Route::get('altcode', 'ApiController@altcode');
    Route::get('role', 'ApiController@role');
    Route::get('range', 'ApiController@range');
    Route::get('ganzhi', 'ApiController@ganzhi');
    Route::get('household', 'ApiController@household');
    Route::get('appttype', 'ApiController@appttype');
    Route::get('assumeoffice', 'ApiController@assumeoffice');
    Route::get('officecate', 'ApiController@officecate');
    Route::get('parentstatus', 'ApiController@parentstatus');
    Route::get('measure', 'ApiController@measure');
    Route::get('possact', 'ApiController@possact');
    Route::get('birole', 'ApiController@birole');
    Route::get('topic', 'ApiController@topic');
    Route::get('occasion', 'ApiController@occasion');
});

Route::group(['prefix' => 'select'], function (){
    Route::get('search/addr', 'ApiController@searchAddr');
    Route::get('search/officeaddr', 'ApiController@searchOfficeAddr');
    Route::get('search/text', 'ApiController@searchText');
    Route::get('search/office', 'ApiController@searchOffice');
    Route::get('search/socialinst', 'ApiController@socialinst');
    Route::get('search/socialinstaddr', 'ApiController@socialinstaddr');
    Route::get('search/entry', 'ApiController@searchEntry');
    Route::get('search/kincode', 'ApiController@searchKincode');
    Route::get('search/assoccode', 'ApiController@searchAssoccode');
    Route::get('search/status', 'ApiController@searchStatuscode');
    Route::get('search/biog', 'ApiController@searchBiog');
    Route::get('search/event', 'ApiController@searchEvent');
    Route::get('search/kinpair', 'ApiController@searchKinPair');
    Route::get('search/assocpair', 'ApiController@searchAssocPair');
});

Route::group(['prefix' => 'code'], function (){
    Route::get('addr', 'ApiController@codeAddr');
});

Route::middleware('guest')->post('v1/user/login', 'Api\LoginController@login');
Route::group(['prefix' => 'v1', 'middleware' => ['auth:api']], function (){
    Route::resource('biog', 'Api\BiogMainController');
    Route::resource('biog.addr', 'Api\BiogAddressController');
});

//20181105建安新增
Route::group(['prefix' => 'v1'], function (){
    Route::get('biog', 'ApiController@searchC_presonid');
    Route::get('add', 'ApiController@addC_presonid');
    Route::get('update', 'ApiController@updateC_presonid');
    Route::get('delete', 'ApiController@deleteC_presonid');
    Route::get('user', 'ApiController@userC_presonid');
});

Route::group(['prefix' => 'operations'], function (){
    Route::match(['get', 'post'], 'token', 'Api\OperationsController@token');
    Route::post('add', 'Api\OperationsController@add');
    Route::post('update', 'Api\OperationsController@update');
    Route::post('delete', 'Api\OperationsController@del');
});

Route::group(['prefix' => 'OFFICE_CODES'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController@OFFICE_CODES');
});
Route::group(['prefix' => 'OFFICE_CODE_TYPE_REL'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController@OFFICE_CODE_TYPE_REL');
});
Route::group(['prefix' => 'OFFICE_TYPE_TREE'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController@OFFICE_TYPE_TREE');
});

Route::group(['prefix' => '/post_list'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController@post_list');
});
Route::group(['prefix' => '/entry_list'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController@entry_list');
});
Route::group(['prefix' => '/place_list'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController@place_list');
});
Route::group(['prefix' => '/place_belongs_to'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController@place_belongs_to');
});
Route::group(['prefix' => '/office_list_by_name'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController@office_list_by_name');
});
Route::group(['prefix' => '/entry_list_by_name'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController@entry_list_by_name');
});
Route::group(['prefix' => '/query_office_postings'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController2@query_office_postings');
});
Route::group(['prefix' => '/query_entry_postings'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController3@query_entry_postings');
});
Route::group(['prefix' => '/query_relatives'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController4@query_relatives');
});
Route::group(['prefix' => '/get_assoc'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController5@get_assoc');
});
Route::group(['prefix' => '/find_assoc'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController5@find_assoc');
});
Route::group(['prefix' => '/query_associates'], function (){
    Route::match(['get', 'post'], '/', 'Api\ApiController5@query_associates');
});
