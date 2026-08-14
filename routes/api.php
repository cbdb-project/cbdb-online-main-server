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

Route::group([
    'prefix' => 'v2',
    'middleware' => ['auth.optional'],
], function () {
    Route::get('texts', 'Api\TextLookupController@index');
    Route::get('texts/{textId}', 'Api\TextLookupController@show')->where('textId', '[0-9]+');
    Route::get('persons', 'Api\PersonListController@index');
    Route::get('operations', 'Api\OperationListController@index');
});

// Select APIs with optional authentication
// Allows both authenticated users and guests to access
Route::group([
    'prefix' => 'select',
    'middleware' => ['auth.optional'],
], function () {
    Route::get('ethnicity', 'ApiController@ethnicity');
    Route::get('choronym', 'ApiController@choronym');
    Route::get('dynasty', 'ApiController@dynasty');
    Route::get('nianhao', 'ApiController@nianhao');
    // `select/codes` 已移除（#1250）：ApiController 從來沒有 codes() 方法，這條路由
    // 命中時只會由基底 Controller::__call 拋 BadMethodCallException（HTTP 500）。
    // 全庫沒有呼叫端，僅是外部掃描打進來時的錯誤日誌噪音來源。
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
    Route::get('search/text', 'ApiController@searchText');
    Route::get('search/textperson', 'ApiController@searchTextPerson');
    Route::get('search/textauthor', 'ApiController@searchTextAuthor');
    Route::get('search/office', 'ApiController@searchOffice');
    Route::get('search/officetype', 'ApiController@searchOfficeType');
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

// 遺留 /api/v1 群組（biog／add／update／delete／user）與其實作 App\v1 已整組刪除。
//
// 四個端點在刪除前**全部都是 500 的死碼**：App\v1 位於 namespace App，內部用無限定的
// `BiogMain`，解析成不存在的 `App\BiogMain`（真正的類別是 App\Models\BiogMain）。
// 實測四條都拋 `Class "App\BiogMain" not found`，這也是 BIOG_MAIN 裡
// c_created_by='Api' 為零筆的原因。
//
// 之所以刪掉而不是修好 import：
//  - add／update／delete 是無認證、無授權、不寫 operations／audit_log、連 c_modified_by
//    都不蓋的 BIOG_MAIN 寫入設計。就算修好也不該存在——程式化寫入的正當管線是
//    /api/v2/*（Api\MutationController），那條有認證、授權、operations、audit_log 與提案流程。
//  - 唯讀的 biog 同樣壞著，修好 import 等於「新開一個實際上從未運作過的公開搜尋端點」，
//    比刪除風險高，而且沒有任何客戶端在用（它一直回 500）。
//  - App\v1::info() 直接 return phpinfo()（路徑／擴充／環境變數洩漏）。它沒有路由指向，
//    但留在檔案裡就是等有人接上去。
//  - App\v1::token() 用密碼換 confirmation_token，既不查 isActive() 也不查眾包身分，
//    是 Api\OperationsController@token 那道閘門的直通後門（已於 P0-2 先行下架）。

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
