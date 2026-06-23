<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Prometheus metrics endpoint
Route::get('metrics', 'MetricsController@index')->name('metrics');

Route::get('/', 'WelcomeController@index');

Auth::routes();

Route::get('email/verify/{token}', ['as' => 'email.verify', 'uses' => 'EmailController@verify']);
Route::get('operations', ['as' => 'operations.index', 'uses' => 'OperationsController@index']);
Route::get('app/operations', ['as' => 'app.operations.index', 'uses' => 'OperationsController@appIndex'])->middleware('inertia');
Route::post('locale', 'LocaleController@switch')->name('locale.switch')->middleware('throttle:20,1');

Route::get('home', 'HomeController@index')->name('home');
Route::get('dashboard', 'DashboardController@index')->middleware('auth')->name('dashboard');
Route::get('app/dashboard', 'DashboardController@appIndex')
    ->middleware(['auth', 'inertia'])
    ->name('app.dashboard');
Route::get('cbdbapi/person.php', 'CbdbApiController@person')->name('cbdbapi.v1.person');
Route::get('cbdbapi/person', 'CbdbApiController@person');
Route::get('openapi.yaml', function () {
    return response()->file(base_path('docs/openapi/openapi.yaml'), [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
})->name('openapi.yaml');
Route::middleware(['auth.optional'])->post('api/v2/mutate', 'Api\\MutationController@store')->name('api.v2.mutate.web');
Route::middleware(['auth.optional'])->post('api/v2/create', 'Api\\MutationController@create')->name('api.v2.create.web');
Route::middleware(['auth.optional'])->post('api/v2/delete', 'Api\\MutationController@delete')->name('api.v2.delete.web');
Route::middleware(['auth.optional'])->match(['get', 'post'], 'api/v2/get', 'Api\\MutationController@get')->name('api.v2.get.web');
Route::get('view', 'ViewTableController@index')->middleware('auth')->name('view.index');
Route::get('view/{key}', 'ViewTableController@show')->middleware('auth')->name('view.show');

// CHGIS 地圖：底圖圖磚與下載狀態（與地址/官職列表頁同等公開）
Route::get('chgis-map/tiles/{z}/{x}/{y}', 'ChgisMapController@tile')
    ->where(['z' => '[0-9]+', 'x' => '[0-9]+', 'y' => '[0-9]+'])
    ->name('chgis-map.tile');
Route::get('chgis-map/status', 'ChgisMapController@status')->name('chgis-map.status');
Route::get('basicinformation/{id}/map-points', 'ChgisMapController@personPoints')
    ->where('id', '[0-9]+')
    ->name('basicinformation.map-points');

Route::resource('basicinformation', 'BasicInformationController', ['name' => [
    'show' => 'basicinformation.show',
    'create' => 'basicinformation.create',
    'edit' => 'basicinformation.edit',
    'update' => 'basicinformation.update',
    'index' => 'basicinformation.index',
]]);
// Inertia + React 版（人物列表，public，與舊 index 同）
Route::get('app/basicinformation', 'BasicInformationController@appIndex')
    ->middleware('inertia')
    ->name('app.basicinformation.index');
// Inertia + React 版（人物主檔 create/edit/show）。
// create 須排在 {id}/edit、{id} 之前，避免被泛用路由攔截。
Route::get('app/basicinformation/create', 'BasicInformationController@appCreate')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.create');
Route::get('app/basicinformation/{id}/edit', 'BasicInformationController@appEdit')
    ->where('id', '[0-9]+')
    ->middleware('inertia')
    ->name('app.basicinformation.edit');
// Task 27 重做：對齊 legacy /edit 的 React 基本資料編輯器（BasicInfoEditor）。
// 獨立路由供逐步重做/驗證；不受 flag 影響、不上線（驗證通過並全面重做後再整合）。
Route::get('app/basicinformation/{id}/edit-v2', 'BasicInformationController@appEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.editv2');
// PersonEditor 資料端點（JSON，編輯者/訪客可用，非 superadmin-only）。額外路徑段，
// 不會被下方 {id} 泛用路由攔截；不掛 inertia（純 JSON）。
Route::get('app/basicinformation/{id}/summary', 'BasicInformationController@summary')
    ->where('id', '[0-9]+')
    ->name('app.basicinformation.summary');
Route::get('app/basicinformation/{id}/tabs/{tabKey}', 'BasicInformationController@tab')
    ->where('id', '[0-9]+')
    ->name('app.basicinformation.tab');
Route::get('app/basicinformation/{id}', 'BasicInformationController@appShow')
    ->where('id', '[0-9]+')
    ->middleware('inertia')
    ->name('app.basicinformation.show');
Route::get('basicinformation/{id}/saveas', 'BasicInformationController@saveas');
Route::get('basicinformation/{id}/Duplicate_Collateral_Info', 'BasicInformationController@Duplicate_Collateral_Info');
Route::get('basicinformation/{id}/offices/{cpk}/saveas', 'BasicInformationOfficesController@saveas');

// ===================================================================
// 查詢參數模式路由（推薦使用，與舊的 path-based 路由並存）
// 使用 HTTP 查詢參數傳遞複合主鍵，避免自定義編碼邏輯
// 參考：docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md
// 重要：這些路由必須放在對應的 resource 路由之前，否則會被 resource 的
//       show 路由攔截（例如 /altnames/edit 會匹配 /altnames/{altname}）
// ===================================================================

// ALTNAME_DATA
Route::get('basicinformation/{id}/altnames/edit', 'BasicInformationAltnamesController@editQuery')
    ->name('basicinformation.altnames.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/altnames/update', 'BasicInformationAltnamesController@updateQuery')
    ->name('basicinformation.altnames.update.query');
Route::delete('basicinformation/{id}/altnames/delete', 'BasicInformationAltnamesController@destroyQuery')
    ->name('basicinformation.altnames.destroy.query');

// BIOG_ADDR_DATA
Route::get('basicinformation/{id}/addresses/edit', 'BasicInformationAddressesController@editQuery')
    ->name('basicinformation.addresses.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/addresses/update', 'BasicInformationAddressesController@updateQuery')
    ->name('basicinformation.addresses.update.query');
Route::delete('basicinformation/{id}/addresses/delete', 'BasicInformationAddressesController@destroyQuery')
    ->name('basicinformation.addresses.destroy.query');

// TEXT_DATA
Route::get('basicinformation/{id}/texts/edit', 'BasicInformationTextsController@editQuery')
    ->name('basicinformation.texts.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/texts/update', 'BasicInformationTextsController@updateQuery')
    ->name('basicinformation.texts.update.query');
Route::delete('basicinformation/{id}/texts/delete', 'BasicInformationTextsController@destroyQuery')
    ->name('basicinformation.texts.destroy.query');

// BIOG_SOURCE_DATA
Route::get('basicinformation/{id}/sources/edit', 'BasicInformationSourcesController@editQuery')
    ->name('basicinformation.sources.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/sources/update', 'BasicInformationSourcesController@updateQuery')
    ->name('basicinformation.sources.update.query');
Route::delete('basicinformation/{id}/sources/delete', 'BasicInformationSourcesController@destroyQuery')
    ->name('basicinformation.sources.destroy.query');

// ASSOC_DATA
Route::get('basicinformation/{id}/assoc/edit', 'BasicInformationAssocController@editQuery')
    ->name('basicinformation.assoc.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/assoc/update', 'BasicInformationAssocController@updateQuery')
    ->name('basicinformation.assoc.update.query');
Route::delete('basicinformation/{id}/assoc/delete', 'BasicInformationAssocController@destroyQuery')
    ->name('basicinformation.assoc.destroy.query');

// KIN_DATA
Route::get('basicinformation/{id}/kinship/edit', 'BasicInformationKinshipController@editQuery')
    ->name('basicinformation.kinship.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/kinship/update', 'BasicInformationKinshipController@updateQuery')
    ->name('basicinformation.kinship.update.query');
Route::delete('basicinformation/{id}/kinship/delete', 'BasicInformationKinshipController@destroyQuery')
    ->name('basicinformation.kinship.destroy.query');

// STATUS_DATA
Route::get('basicinformation/{id}/statuses/edit', 'BasicInformationStatusesController@editQuery')
    ->name('basicinformation.statuses.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/statuses/update', 'BasicInformationStatusesController@updateQuery')
    ->name('basicinformation.statuses.update.query');
Route::delete('basicinformation/{id}/statuses/delete', 'BasicInformationStatusesController@destroyQuery')
    ->name('basicinformation.statuses.destroy.query');

// ENTRY_DATA
Route::get('basicinformation/{id}/entries/edit', 'BasicInformationEntriesController@editQuery')
    ->name('basicinformation.entries.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/entries/update', 'BasicInformationEntriesController@updateQuery')
    ->name('basicinformation.entries.update.query');
Route::delete('basicinformation/{id}/entries/delete', 'BasicInformationEntriesController@destroyQuery')
    ->name('basicinformation.entries.destroy.query');

// EVENTS_DATA
Route::get('basicinformation/{id}/events/edit', 'BasicInformationEventsController@editQuery')
    ->name('basicinformation.events.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/events/update', 'BasicInformationEventsController@updateQuery')
    ->name('basicinformation.events.update.query');
Route::delete('basicinformation/{id}/events/delete', 'BasicInformationEventsController@destroyQuery')
    ->name('basicinformation.events.destroy.query');

// BIOG_INST_DATA
Route::get('basicinformation/{id}/socialinst/edit', 'BasicInformationSocialInstController@editQuery')
    ->name('basicinformation.socialinst.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/socialinst/update', 'BasicInformationSocialInstController@updateQuery')
    ->name('basicinformation.socialinst.update.query');
Route::delete('basicinformation/{id}/socialinst/delete', 'BasicInformationSocialInstController@destroyQuery')
    ->name('basicinformation.socialinst.destroy.query');

// POSSESSION_DATA
Route::get('basicinformation/{id}/possession/edit', 'BasicInformationPossessionController@editQuery')
    ->name('basicinformation.possession.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/possession/update', 'BasicInformationPossessionController@updateQuery')
    ->name('basicinformation.possession.update.query');
Route::delete('basicinformation/{id}/possession/delete', 'BasicInformationPossessionController@destroyQuery')
    ->name('basicinformation.possession.destroy.query');

// POSTED_TO_OFFICE_DATA（官名）
Route::get('basicinformation/{id}/offices/edit', 'BasicInformationOfficesController@editQuery')
    ->name('basicinformation.offices.edit.query');
Route::match(['put', 'patch'], 'basicinformation/{id}/offices/update', 'BasicInformationOfficesController@updateQuery')
    ->name('basicinformation.offices.update.query');
Route::delete('basicinformation/{id}/offices/delete', 'BasicInformationOfficesController@destroyQuery')
    ->name('basicinformation.offices.destroy.query');

// 資源路由（放在查詢參數路由之後，作為後備）
Route::resource('basicinformation.addresses', 'BasicInformationAddressesController')->except(['show', 'destroy']);
Route::resource('basicinformation.altnames', 'BasicInformationAltnamesController')->except(['show', 'destroy']);
Route::resource('basicinformation.texts', 'BasicInformationTextsController')->except(['show', 'destroy']);
Route::resource('basicinformation.offices', 'BasicInformationOfficesController')->except(['show', 'destroy']);
Route::resource('basicinformation.assoc', 'BasicInformationAssocController')->except(['show', 'destroy']);
Route::resource('basicinformation.entries', 'BasicInformationEntriesController')->except(['show', 'destroy']);
Route::resource('basicinformation.events', 'BasicInformationEventsController')->except(['show', 'destroy']);
Route::resource('basicinformation.kinship', 'BasicInformationKinshipController')->except(['show', 'destroy']);
Route::resource('basicinformation.statuses', 'BasicInformationStatusesController')->except(['show', 'destroy']);
Route::resource('basicinformation.possession', 'BasicInformationPossessionController')->except(['show', 'destroy']);
Route::resource('basicinformation.socialinst', 'BasicInformationSocialInstController')->except(['show', 'destroy']);
Route::resource('basicinformation.sources', 'BasicInformationSourcesController')->except(['show', 'destroy']);

// BiogMain 提案路由
Route::post('basicinformation/{personid}/{resource}/proposal', 'BasicInformationProposalController@proposalStore')
    ->name('basicinformation.proposal.store');
Route::post('basicinformation/{personid}/{resource}/{id}/proposal', 'BasicInformationProposalController@proposalUpdate')
    ->name('basicinformation.proposal.update');

Route::get('codes', 'CodesController@index')->name('codes.index');
// Inertia + React 版（代碼表總覽）
Route::get('app/codes', 'CodesController@appIndex')
    ->middleware('inertia')
    ->name('app.codes.index');
// 全量導出：route 泛用，但範圍由 config('codes.export_columns') 白名單收斂（本輪僅 OFFICE_CODES）。
// 直連 live 生產庫，故加 throttle 防爬蟲爆量。設計見 docs/OFFICE_CODES_EXPORT_SYNC.md。
Route::get('codes/{table_name}/export', 'CodesController@export')->name('codes.export')->middleware('throttle:6,1');
Route::get('codes/{table_name}', 'CodesController@show')->name('codes.show');
// Inertia + React 版（單表資料檢視 + 新增流程）
Route::get('app/codes/{table_name}/create', 'CodesController@appCreate')
    ->middleware('inertia')
    ->name('app.codes.create');
Route::post('app/codes/{table_name}/proposal', 'CodesController@appProposeStore')
    ->middleware('inertia')
    ->name('app.codes.propose.store');
Route::post('app/codes/{table_name}', 'CodesController@appStore')
    ->middleware('inertia')
    ->name('app.codes.store');
// 提案調整（須排在 {id} 泛用路由之前，否則 id='.*' 會吞掉 proposals/{operation}）
Route::get('app/codes/{table_name}/proposals/{operation}/edit', 'CodesController@appProposalEdit')
    ->middleware('inertia')->name('app.codes.proposals.edit');
Route::patch('app/codes/{table_name}/proposals/{operation}', 'CodesController@proposalUpdateExisting')
    ->middleware('inertia')->name('app.codes.proposals.update');
Route::delete('app/codes/{table_name}/proposals/{operation}', 'CodesController@proposalCancel')
    ->middleware('inertia')->name('app.codes.proposals.cancel');
Route::get('app/codes/{table_name}/{id}/edit', 'CodesController@appEdit')
    ->middleware('inertia')->name('app.codes.edit')->where('id', '.*');
Route::match(['post', 'patch'], 'app/codes/{table_name}/{id}/proposal', 'CodesController@appProposalUpdate')
    ->middleware('inertia')->name('app.codes.propose.update')->where('id', '.*');
Route::match(['put', 'patch'], 'app/codes/{table_name}/{id}', 'CodesController@appUpdate')
    ->middleware('inertia')->name('app.codes.update')->where('id', '.*');
Route::delete('app/codes/{table_name}/{id}', 'CodesController@appDestroy')
    ->middleware('inertia')->name('app.codes.destroy')->where('id', '.*');
Route::get('app/codes/{table_name}', 'CodesController@appShow')
    ->middleware('inertia')
    ->name('app.codes.show');
Route::get('codes/{table_name}/create', 'CodesController@create')->name('codes.create');
Route::post('codes/{table_name}/proposal', 'CodesController@proposalStore')->name('codes.propose.store');
Route::get('codes/{table_name}/proposals/{operation}/edit', 'CodesController@proposalEdit')->name('codes.proposals.edit');
Route::patch('codes/{table_name}/proposals/{operation}', 'CodesController@proposalUpdateExisting')->name('codes.proposals.update');
Route::delete('codes/{table_name}/proposals/{operation}', 'CodesController@proposalCancel')->name('codes.proposals.cancel');
Route::match(['post', 'patch'], 'codes/{table_name}/{id}/proposal', 'CodesController@proposalUpdate')->name('codes.propose.update')->where('id', '.*');
Route::get('codes/{table_name}/{id}/edit', 'CodesController@edit')->name('codes.edit')->where('id', '.*');
Route::match(['put', 'patch'], 'codes/{table_name}/{id}', 'CodesController@update')->name('codes.update')->where('id', '.*');
Route::post('codes/{table_name}', 'CodesController@store')->name('codes.store');
Route::delete('codes/{table_name}/{id}', 'CodesController@destroy')->name('codes.destroy')->where('id', '.*');

Route::post('operations/{operation}/approve', 'OperationsProposalController@approve')->name('operations.proposals.approve');
Route::post('operations/{operation}/reject', 'OperationsProposalController@reject')->name('operations.proposals.reject');

Route::resource('manage', 'ManagementController', ['name' => [
    'show' => 'manage.show',
    'create' => 'manage.create',
    'edit' => 'manage.edit',
    'update' => 'manage.update',
]]);
// Inertia + React 版（使用者管理列表 + 編輯）
Route::get('app/manage', 'ManagementController@appIndex')
    ->middleware(['auth', 'inertia'])
    ->name('app.manage.index');
Route::get('app/manage/{manage}/edit', 'ManagementController@appEdit')
    ->middleware(['auth', 'inertia'])
    ->name('app.manage.edit');
Route::match(['put', 'patch'], 'app/manage/{manage}', 'ManagementController@appUpdate')
    ->middleware(['auth', 'inertia'])
    ->name('app.manage.update');

Route::match(['get', 'post'], 'merge-preview', 'MergePreviewController@index')->name('merge-preview.index');
Route::get('app/merge-preview', 'MergePreviewController@appIndex')->name('app.merge-preview.index')->middleware('inertia');

Route::resource('operations', 'OperationsController', ['name' => [
    'show' => 'operations.show',
    'create' => 'operations.create',
    'edit' => 'operations.edit',
    'update' => 'operations.update',
]]);
Route::post('operations/{operation}/restore', 'OperationsController@restore')->name('operations.restore');

Route::middleware('auth')->group(function () {
    Route::get('profile', 'UserProfileController@edit')->name('profile.edit');
    Route::patch('profile', 'UserProfileController@update')->name('profile.update');
    // Inertia + React 版
    Route::get('app/profile', 'UserProfileController@appEdit')
        ->middleware('inertia')
        ->name('app.profile.edit');
    Route::patch('app/profile', 'UserProfileController@appUpdate')
        ->middleware('inertia')
        ->name('app.profile.update');

    // API Token 管理
    Route::get('api-tokens', 'ApiTokenController@index')->name('api-tokens.index');
    Route::post('api-tokens', 'ApiTokenController@store')->name('api-tokens.store');
    Route::delete('api-tokens/{tokenId}', 'ApiTokenController@destroy')->name('api-tokens.destroy');
    Route::delete('api-tokens', 'ApiTokenController@destroyAll')->name('api-tokens.destroy-all');

    // 檢視表（Inertia + React）
    Route::get('app/view', 'ViewTableController@appIndex')
        ->middleware('inertia')
        ->name('app.view.index');
    Route::get('app/view/{key}', 'ViewTableController@appShow')
        ->middleware('inertia')
        ->name('app.view.show');

    // ── 暫不公開：僅管理員可訪問 ──────────────────────────────────────
    Route::middleware('superadmin')->group(function () {
        // 最近眾包錄入記錄
        Route::get('crowdsourcing', ['as' => 'crowdsourcing.index', 'uses' => 'CrowdsourcingController@index']);
        Route::get('app/crowdsourcing', ['as' => 'app.crowdsourcing.index', 'uses' => 'CrowdsourcingController@appIndex'])->middleware('inertia');
        Route::resource('crowdsourcing', 'CrowdsourcingController', ['name' => [
            'show' => 'crowdsourcing.show',
            'create' => 'crowdsourcing.create',
            'edit' => 'crowdsourcing.edit',
            'update' => 'crowdsourcing.update',
        ]]);
        Route::get('crowdsourcing/{id}/confirm', 'CrowdsourcingController@confirm');
        Route::get('crowdsourcing/{id}/reject', 'CrowdsourcingController@reject');

        // 人物瀏覽工作台（Inertia + React）
        Route::get('app/person-browser', 'PersonBrowserController@index')
            ->middleware('inertia')
            ->name('app.person-browser.index');
        Route::get('app/person-browser/search', 'PersonBrowserController@search')
            ->name('app.person-browser.search');
        Route::get('app/person-browser/people/{personId}/summary', 'PersonBrowserController@summary')
            ->where('personId', '[0-9]+')
            ->name('app.person-browser.summary');
        Route::get('app/person-browser/people/{personId}/tabs/{tabKey}', 'PersonBrowserController@tab')
            ->where('personId', '[0-9]+')
            ->name('app.person-browser.tab');

        // 按入仕查詢（Inertia + React）
        Route::get('app/search-by/entry', 'SearchByEntryController@index')
            ->middleware('inertia')
            ->name('app.search-by.entry.index');
        Route::get('app/search-by/entry/types', 'SearchByEntryController@getEntryTypes')->name('app.search-by.entry.types');
        Route::get('app/search-by/entry/codes', 'SearchByEntryController@getEntryCodes')->name('app.search-by.entry.codes');
        Route::get('app/search-by/entry/places', 'SearchByEntryController@getPlaces')->name('app.search-by.entry.places');
        Route::get('app/search-by/entry/query', 'SearchByEntryController@query')->name('app.search-by.entry.query');

        // 歷史地圖（需登入且為 superadmin）
        Route::get('app/maps', 'HistoricalMapsController@index')
            ->name('app.maps.index');
    });
    // ─────────────────────────────────────────────────────────────────

    // Legacy maps 重定向（需登入，目標 /app/maps 本身已有 superadmin 保護）
    Route::get('maps', 'HistoricalMapsController@legacyRedirect')->name('maps.index');
    Route::get('maps/index.html', 'HistoricalMapsController@legacyRedirect');
    Route::get('maps/tang', 'HistoricalMapsController@legacyRedirect');
    Route::get('maps/tang/{path?}', 'HistoricalMapsController@legacyRedirect')->where('path', '.*');

    Route::get('admin/explainsql', 'AdminExplainSqlController@show')->name('admin.explainsql');
    Route::post('admin/explainsql', 'AdminExplainSqlController@explain');
    // Inertia + React 版（表單頁；GET 顯示、POST 跑 EXPLAIN 後重新 render）
    Route::get('app/admin/explainsql', 'AdminExplainSqlController@appShow')
        ->middleware('inertia')
        ->name('app.admin.explainsql');
    Route::post('app/admin/explainsql', 'AdminExplainSqlController@appExplain')
        ->middleware('inertia')
        ->name('app.admin.explainsql.explain');
    Route::get('admin/batch-load-book-titles', 'AdminBatchLoadBookTitlesController@showForm')->name('admin.batch-load-book-titles');
    Route::post('admin/batch-load-book-titles', 'AdminBatchLoadBookTitlesController@store')->name('admin.batch-load-book-titles.store');
    Route::post('admin/batch-load-book-titles/undo', 'AdminBatchLoadBookTitlesController@undo')->name('admin.batch-load-book-titles.undo');
    Route::post('admin/batch-load-book-titles/update-pinyin', 'AdminBatchLoadBookTitlesController@updatePinyin')->name('admin.batch-load-book-titles.update-pinyin');
    // Inertia + React 版（store/undo 重用既有方法，依請求路徑決定重導）
    Route::get('app/admin/batch-load-book-titles', 'AdminBatchLoadBookTitlesController@appShowForm')
        ->middleware('inertia')->name('app.admin.batch-load-book-titles');
    Route::post('app/admin/batch-load-book-titles', 'AdminBatchLoadBookTitlesController@store')
        ->middleware('inertia')->name('app.admin.batch-load-book-titles.store');
    Route::post('app/admin/batch-load-book-titles/undo', 'AdminBatchLoadBookTitlesController@undo')
        ->middleware('inertia')->name('app.admin.batch-load-book-titles.undo');
    Route::get('admin/batch-load-social-institutes', 'AdminBatchLoadSocialInstitutesController@showForm')->name('admin.batch-load-social-institutes');
    Route::post('admin/batch-load-social-institutes', 'AdminBatchLoadSocialInstitutesController@store')->name('admin.batch-load-social-institutes.store');
    // Inertia + React 版（store 重用，依請求路徑重導）
    Route::get('app/admin/batch-load-social-institutes', 'AdminBatchLoadSocialInstitutesController@appShowForm')
        ->middleware('inertia')->name('app.admin.batch-load-social-institutes');
    Route::post('app/admin/batch-load-social-institutes', 'AdminBatchLoadSocialInstitutesController@store')
        ->middleware('inertia')->name('app.admin.batch-load-social-institutes.store');
    Route::get('admin/batch-load-offices', 'AdminBatchLoadOfficesController@showForm')->name('admin.batch-load-offices');
    Route::post('admin/batch-load-offices', 'AdminBatchLoadOfficesController@store')->name('admin.batch-load-offices.store');
    // Inertia + React 版（store 重用，依請求路徑重導）
    Route::get('app/admin/batch-load-offices', 'AdminBatchLoadOfficesController@appShowForm')
        ->middleware('inertia')->name('app.admin.batch-load-offices');
    Route::post('app/admin/batch-load-offices', 'AdminBatchLoadOfficesController@store')
        ->middleware('inertia')->name('app.admin.batch-load-offices.store');
    Route::get('admin/wiki-maintenance', 'WikiMaintenanceController@index')->name('admin.wiki-maintenance');
    Route::get('app/admin/wiki-maintenance', 'WikiMaintenanceController@appIndex')->name('app.admin.wiki-maintenance')->middleware('inertia');
    Route::post('admin/wiki-maintenance/delete-all', 'WikiMaintenanceController@deleteAll')->name('admin.wiki-maintenance.delete-all');
    Route::post('admin/wiki-maintenance/reimport', 'WikiMaintenanceController@reimport')->name('admin.wiki-maintenance.reimport');
    Route::post('admin/wiki-maintenance/import-url', 'WikiMaintenanceController@importFromUrl')->name('admin.wiki-maintenance.import-url');
    Route::get('admin/wiki-maintenance/progress/{taskId}', 'WikiMaintenanceController@getImportProgress')->where('taskId', '[a-zA-Z0-9_]+')->name('admin.wiki-maintenance.progress');
    Route::post('admin/wiki-maintenance/cancel/{taskId}', 'WikiMaintenanceController@cancelImport')->where('taskId', '[a-zA-Z0-9_]+')->name('admin.wiki-maintenance.cancel');
    Route::get('admin/wiki-maintenance/test-progress', 'TestController@testProgress');
    Route::get('admin/cbdb-table-maintenance', 'CbdbTableMaintenanceController@index')->name('admin.cbdb-table-maintenance');
    Route::get('app/admin/cbdb-table-maintenance', 'CbdbTableMaintenanceController@appIndex')->name('app.admin.cbdb-table-maintenance')->middleware('inertia');
    Route::post('admin/cbdb-table-maintenance/rebuild', 'CbdbTableMaintenanceController@rebuild')->name('admin.cbdb-table-maintenance.rebuild');
    Route::get('admin/cbdb-table-maintenance/progress/{taskId}', 'CbdbTableMaintenanceController@getNameFtsProgress')
        ->where('taskId', '[a-zA-Z0-9_]+')
        ->name('admin.cbdb-table-maintenance.progress');
    Route::get('admin/unidirectional-relationship-repair', 'UnidirectionalRelationshipRepairController@index')->name('admin.unidirectional-relationship-repair');
    Route::get('app/admin/unidirectional-relationship-repair', 'UnidirectionalRelationshipRepairController@appIndex')->name('app.admin.unidirectional-relationship-repair')->middleware('inertia');
    Route::post('admin/unidirectional-relationship-repair/kinship', 'UnidirectionalRelationshipRepairController@repairKinship')->name('admin.unidirectional-relationship-repair.kinship');
    // Query Playground
    Route::get('query-playground', 'QueryPlaygroundController@index')->name('query-playground.index');
    Route::post('query-playground/run', 'QueryPlaygroundController@run')->name('query-playground.run');
    Route::post('query-playground/schema', 'QueryPlaygroundController@qbeSchema')->name('query-playground.schema');
    Route::post('query-playground/generate-from-nl', 'QueryPlaygroundController@generateFromNL')->name('query-playground.generate-from-nl');
    Route::post('query-playground/generate-from-nl-stream', 'QueryPlaygroundController@generateFromNLStream')->name('query-playground.generate-from-nl-stream');
    Route::post('query-playground/answer-from-nl', 'QueryPlaygroundController@answerFromNL')->name('query-playground.answer-from-nl');
    Route::post('query-playground/answer-from-nl-stream', 'QueryPlaygroundController@answerFromNLStream')->name('query-playground.answer-from-nl-stream');
    Route::get('query-playground/nl-query-logs', 'QueryPlaygroundController@nlQueryLogs')->name('query-playground.nl-query-logs');
    Route::get('app/query-playground/nl-query-logs', 'QueryPlaygroundController@appNlQueryLogs')
        ->middleware('inertia')
        ->name('app.query-playground.nl-query-logs');

    // Query Playground（Inertia + React）
    Route::get('app/query-playground', 'QueryPlaygroundController@appIndex')
        ->middleware('inertia')
        ->name('app.query-playground.index');

    // AI 智能填充任官信息
    Route::post('api/ai/posting/extract', 'AiPostingAutofillController@extract')->name('ai.posting.extract');

    // AI 智能識別代碼（社會關係 / 社會區分）
    Route::post('api/ai/code-lookup/suggest', 'CodeLookupController@suggest')->name('ai.code-lookup.suggest');

    // AI 填充日誌（管理員工具）
    Route::get('admin/ai-fill-logs', 'AiFillLogController@index')->name('admin.ai-fill-logs');
    Route::get('app/admin/ai-fill-logs', 'AiFillLogController@appIndex')
        ->middleware('inertia')
        ->name('app.admin.ai-fill-logs');
    Route::get('admin/audit-logs', 'AdminAuditLogController@index')->name('admin.audit-logs');
    // Inertia + React 版（與舊 Blade 版並存；側邊欄指向由 migration flag 控制）
    Route::get('app/admin/audit-logs', 'AdminAuditLogController@appIndex')
        ->middleware('inertia')
        ->name('app.admin.audit-logs');

    Route::post('admin/unidirectional-relationship-repair/assoc', 'UnidirectionalRelationshipRepairController@repairAssoc')->name('admin.unidirectional-relationship-repair.assoc');
});
