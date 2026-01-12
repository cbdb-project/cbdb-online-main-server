<?php


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

Route::middleware('auth:sanctum')->get('/user', 'Api\UserController@show');

Route::group([], function () {
    Route::match(['get', 'post'], 'name', 'Api\NameController@index');
});

// Select APIs with optional authentication
// Allows both authenticated users and guests to access
// - Authenticated users: Rate limited per user (300 req/min)
// - Guest users: Rate limited per IP address (300 req/min)
Route::group([
    'prefix' => 'select',
    'middleware' => ['auth.optional', 'throttle:300,1'],
], function () {
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

    // Search endpoints
    Route::get('search/addr', 'ApiController@searchAddr');
    Route::get('search/officeaddr', 'ApiController@searchOfficeAddr');
    Route::get('search/text', 'ApiController@searchText');
    Route::get('search/textperson', 'ApiController@searchTextPerson');
    Route::get('search/textauthor', 'ApiController@searchTextAuthor');
    Route::get('search/office', 'ApiController@searchOffice');
    Route::get('search/socialinst', 'ApiController@socialinst');
    Route::get('search/socialinstaddr', 'ApiController@socialinstaddr');
    Route::get('search/socialinstcode', 'ApiController@socialinstcode');
    Route::get('search/entry', 'ApiController@searchEntry');
    Route::get('search/kincode', 'ApiController@searchKincode');
    Route::get('search/assoccode', 'ApiController@searchAssoccode');
    Route::get('search/status', 'ApiController@searchStatuscode');
    Route::get('search/biog', 'ApiController@searchBiog');
    Route::get('search/event', 'ApiController@searchEvent');
    Route::get('search/kinpair', 'ApiController@searchKinPair');
    Route::get('search/assocpair', 'ApiController@searchAssocPair');
    Route::get('search/pinyin', 'ApiController@searchPinyin');
});

Route::group(['prefix' => 'code'], function () {
    Route::get('addr', 'ApiController@codeAddr');
});

Route::middleware('guest')->post('v1/user/login', 'Api\LoginController@login');

//20181105建安新增
Route::group(['prefix' => 'v1'], function () {
    Route::get('biog', 'ApiController@searchC_presonid');
    Route::get('add', 'ApiController@addC_presonid');
    Route::get('update', 'ApiController@updateC_presonid');
    Route::get('delete', 'ApiController@deleteC_presonid');
    Route::get('user', 'ApiController@userC_presonid');
});

Route::group(['prefix' => 'operations'], function () {
    Route::match(['get', 'post'], 'token', 'Api\OperationsController@token');
    Route::post('add', 'Api\OperationsController@add');
    Route::post('update', 'Api\OperationsController@update');
    Route::post('delete', 'Api\OperationsController@del');
});

Route::group(['prefix' => 'OFFICE_CODES'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController@OFFICE_CODES');
});
Route::group(['prefix' => 'OFFICE_CODE_TYPE_REL'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController@OFFICE_CODE_TYPE_REL');
});
Route::group(['prefix' => 'OFFICE_TYPE_TREE'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController@OFFICE_TYPE_TREE');
});

Route::group(['prefix' => '/post_list'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController@post_list');
});
Route::group(['prefix' => '/entry_list'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController@entry_list');
});
Route::group(['prefix' => '/place_list'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController@place_list');
});
Route::group(['prefix' => '/place_belongs_to'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController@place_belongs_to');
});
Route::group(['prefix' => '/office_list_by_name'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController@office_list_by_name');
});
Route::group(['prefix' => '/entry_list_by_name'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController@entry_list_by_name');
});
Route::group(['prefix' => '/query_office_postings'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController2@query_office_postings');
});
Route::group(['prefix' => '/query_entry_postings'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController3@query_entry_postings');
});
Route::group(['prefix' => '/query_relatives'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController4@query_relatives');
});
Route::group(['prefix' => '/query_relatives_1'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController4_1@query_relatives');
});
Route::group(['prefix' => '/query_relatives_2'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController4_2@query_relatives');
});
Route::group(['prefix' => '/get_assoc'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController5@get_assoc');
});
Route::group(['prefix' => '/find_assoc'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController5@find_assoc');
});
Route::group(['prefix' => '/query_associates'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController5@query_associates');
});
Route::group(['prefix' => '/query_place'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController6@query_place');
});
Route::group(['prefix' => '/query_assoc_network'], function () {
    Route::match(['get', 'post'], '/', 'Api\ApiController7@query_assoc_network');
});
