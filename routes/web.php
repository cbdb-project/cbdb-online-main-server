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

// email/verify/{token} 已於 P0-2 下架（連同 EmailController）。
// 它是一條不掛 auth、直接 Auth::login() 的無密碼登入端點：confirmation_token 永久有效、
// 會經 URL 路徑流入 access log／Referer／瀏覽器歷史，任何知道它的人都能取得該帳號 session；
// 被停用的帳號也能靠它繞過 is_active 複查重拿 session。而它連名義上的「啟用帳號」都沒做
// （$user->is_active = 2 早被註解），啟用信自 2021-08 起停發 → 沒有合法用途。
// 日後若要恢復啟用信，應另建一次性、有期限的 email_verifications 表，不得重用
// confirmation_token，且不得 Auth::login()。
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
Route::middleware(['auth.optional'])->post('api/v2/batch_mutate', 'Api\\MutationController@batchStore')->name('api.v2.batch-mutate.web');
Route::middleware(['auth.optional'])->post('api/v2/create', 'Api\\MutationController@create')->name('api.v2.create.web');
Route::middleware(['auth.optional'])->post('api/v2/delete', 'Api\\MutationController@delete')->name('api.v2.delete.web');
// 修改提案＝撤回舊提案＋同一條提交管線重發（見 MutationController::resubmit 註解）
Route::middleware(['auth.optional'])->post('api/v2/proposals/{operation}/resubmit', 'Api\\MutationController@resubmit')->name('api.v2.proposals.resubmit.web');
Route::middleware(['auth.optional'])->match(['get', 'post'], 'api/v2/get', 'Api\\MutationController@get')->name('api.v2.get.web');
// #79：社會關係／親屬「對面互逆鏡像」現況偵測（缺邊/多條），供編輯器行內提示用。
Route::middleware(['auth.optional'])->post('api/v2/relationship/opposite-edges', 'Api\\MutationController@oppositeEdges')->name('api.v2.relationship.opposite-edges.web');
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
]])->middleware('legacy.form:person');
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
// 這些 edit-v2 路由即為現行上線的 React 編輯器：legacy 表單被 LegacyBladeFormGate 攔截後 302 導向對應 *.editv2。
Route::get('app/basicinformation/{id}/edit-v2', 'BasicInformationController@appEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.editv2');
// 地址編輯器 V2（對齊 legacy addresses/_form）。
Route::get('app/basicinformation/{id}/addresses/edit-v2', 'BasicInformationController@appAddressEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.addresses.editv2');
// 著述編輯器 V2（對齊 legacy texts/_form）。
Route::get('app/basicinformation/{id}/texts/edit-v2', 'BasicInformationController@appTextEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.texts.editv2');
Route::get('app/basicinformation/{id}/altnames/edit-v2', 'BasicInformationController@appAltnameEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.altnames.editv2');
// 社會機構編輯器 V2（對齊 legacy socialinst/_form）。
Route::get('app/basicinformation/{id}/socialinst/edit-v2', 'BasicInformationController@appSocialinstEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.socialinst.editv2');
// 占有／財產編輯器 V2（對齊 legacy possession/_form）。
Route::get('app/basicinformation/{id}/possession/edit-v2', 'BasicInformationController@appPossessionEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.possession.editv2');
// 事件編輯器 V2（對齊 legacy events/_form）。
Route::get('app/basicinformation/{id}/events/edit-v2', 'BasicInformationController@appEventEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.events.editv2');
Route::get('app/basicinformation/{id}/entries/edit-v2', 'BasicInformationController@appEntriesEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.entries.editv2');
// 社會區分編輯器 V2（對齊 legacy statuses/_form，含 AI 智能識別社會區分類別代碼）。
Route::get('app/basicinformation/{id}/statuses/edit-v2', 'BasicInformationController@appStatusEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.statuses.editv2');
// 著述出處編輯器 V2（對齊 legacy sources/_form）。
Route::get('app/basicinformation/{id}/sources/edit-v2', 'BasicInformationController@appSourceEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.sources.editv2');
// 官名／任官編輯器 V2（對齊 legacy offices/_form，含多地址＋雙 era＋社會機構）。
Route::get('app/basicinformation/{id}/offices/edit-v2', 'BasicInformationController@appOfficeEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.offices.editv2');
// 社會關係編輯器 V2（對齊 legacy assoc/_form，9 段 PK＋雙 era＋多人物搜尋＋AI 代碼識別；pair 後端權威補齊）。
Route::get('app/basicinformation/{id}/assoc/edit-v2', 'BasicInformationController@appAssocEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.assoc.editv2');
// 親屬關係編輯器 V2（對齊 legacy kinship/_form，3 段 PK＋親屬碼/人物/出處搜尋；互逆配對碼後端權威補齊＋雙向 mirror）。
Route::get('app/basicinformation/{id}/kinship/edit-v2', 'BasicInformationController@appKinshipEditV2')
    ->where('id', '[0-9]+')
    ->middleware(['auth', 'inertia'])
    ->name('app.basicinformation.kinship.editv2');
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

// ===================================================================
// 查詢參數模式路由（推薦使用，與舊的 path-based 路由並存）
// 使用 HTTP 查詢參數傳遞複合主鍵，避免自定義編碼邏輯
// 參考：docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md
// 重要：這些路由必須放在對應的 resource 路由之前，否則會被 resource 的
//       show 路由攔截（例如 /altnames/edit 會匹配 /altnames/{altname}）
// ===================================================================

// ALTNAME_DATA
Route::get('basicinformation/{id}/altnames/edit', 'BasicInformationAltnamesController@editQuery')
    ->name('basicinformation.altnames.edit.query')->middleware('legacy.form:altnames');
Route::match(['put', 'patch'], 'basicinformation/{id}/altnames/update', 'BasicInformationAltnamesController@updateQuery')
    ->name('basicinformation.altnames.update.query')->middleware('legacy.form:altnames');
Route::delete('basicinformation/{id}/altnames/delete', 'BasicInformationAltnamesController@destroyQuery')
    ->name('basicinformation.altnames.destroy.query')->middleware('legacy.form:altnames');

// BIOG_ADDR_DATA
Route::get('basicinformation/{id}/addresses/edit', 'BasicInformationAddressesController@editQuery')
    ->name('basicinformation.addresses.edit.query')->middleware('legacy.form:addresses');
Route::match(['put', 'patch'], 'basicinformation/{id}/addresses/update', 'BasicInformationAddressesController@updateQuery')
    ->name('basicinformation.addresses.update.query')->middleware('legacy.form:addresses');
Route::delete('basicinformation/{id}/addresses/delete', 'BasicInformationAddressesController@destroyQuery')
    ->name('basicinformation.addresses.destroy.query')->middleware('legacy.form:addresses');

// TEXT_DATA
Route::get('basicinformation/{id}/texts/edit', 'BasicInformationTextsController@editQuery')
    ->name('basicinformation.texts.edit.query')->middleware('legacy.form:texts');
Route::match(['put', 'patch'], 'basicinformation/{id}/texts/update', 'BasicInformationTextsController@updateQuery')
    ->name('basicinformation.texts.update.query')->middleware('legacy.form:texts');
Route::delete('basicinformation/{id}/texts/delete', 'BasicInformationTextsController@destroyQuery')
    ->name('basicinformation.texts.destroy.query')->middleware('legacy.form:texts');

// BIOG_SOURCE_DATA
Route::get('basicinformation/{id}/sources/edit', 'BasicInformationSourcesController@editQuery')
    ->name('basicinformation.sources.edit.query')->middleware('legacy.form:sources');
Route::match(['put', 'patch'], 'basicinformation/{id}/sources/update', 'BasicInformationSourcesController@updateQuery')
    ->name('basicinformation.sources.update.query')->middleware('legacy.form:sources');
Route::delete('basicinformation/{id}/sources/delete', 'BasicInformationSourcesController@destroyQuery')
    ->name('basicinformation.sources.destroy.query')->middleware('legacy.form:sources');

// ASSOC_DATA
Route::get('basicinformation/{id}/assoc/edit', 'BasicInformationAssocController@editQuery')
    ->name('basicinformation.assoc.edit.query')->middleware('legacy.form:assoc');
Route::match(['put', 'patch'], 'basicinformation/{id}/assoc/update', 'BasicInformationAssocController@updateQuery')
    ->name('basicinformation.assoc.update.query')->middleware('legacy.form:assoc');
Route::delete('basicinformation/{id}/assoc/delete', 'BasicInformationAssocController@destroyQuery')
    ->name('basicinformation.assoc.destroy.query')->middleware('legacy.form:assoc');

// KIN_DATA
Route::get('basicinformation/{id}/kinship/edit', 'BasicInformationKinshipController@editQuery')
    ->name('basicinformation.kinship.edit.query')->middleware('legacy.form:kinship');
Route::match(['put', 'patch'], 'basicinformation/{id}/kinship/update', 'BasicInformationKinshipController@updateQuery')
    ->name('basicinformation.kinship.update.query')->middleware('legacy.form:kinship');
Route::delete('basicinformation/{id}/kinship/delete', 'BasicInformationKinshipController@destroyQuery')
    ->name('basicinformation.kinship.destroy.query')->middleware('legacy.form:kinship');

// STATUS_DATA
Route::get('basicinformation/{id}/statuses/edit', 'BasicInformationStatusesController@editQuery')
    ->name('basicinformation.statuses.edit.query')->middleware('legacy.form:statuses');
Route::match(['put', 'patch'], 'basicinformation/{id}/statuses/update', 'BasicInformationStatusesController@updateQuery')
    ->name('basicinformation.statuses.update.query')->middleware('legacy.form:statuses');
Route::delete('basicinformation/{id}/statuses/delete', 'BasicInformationStatusesController@destroyQuery')
    ->name('basicinformation.statuses.destroy.query')->middleware('legacy.form:statuses');

// ENTRY_DATA
Route::get('basicinformation/{id}/entries/edit', 'BasicInformationEntriesController@editQuery')
    ->name('basicinformation.entries.edit.query')->middleware('legacy.form:entries');
Route::match(['put', 'patch'], 'basicinformation/{id}/entries/update', 'BasicInformationEntriesController@updateQuery')
    ->name('basicinformation.entries.update.query')->middleware('legacy.form:entries');
Route::delete('basicinformation/{id}/entries/delete', 'BasicInformationEntriesController@destroyQuery')
    ->name('basicinformation.entries.destroy.query')->middleware('legacy.form:entries');

// EVENTS_DATA
Route::get('basicinformation/{id}/events/edit', 'BasicInformationEventsController@editQuery')
    ->name('basicinformation.events.edit.query')->middleware('legacy.form:events');
Route::match(['put', 'patch'], 'basicinformation/{id}/events/update', 'BasicInformationEventsController@updateQuery')
    ->name('basicinformation.events.update.query')->middleware('legacy.form:events');
Route::delete('basicinformation/{id}/events/delete', 'BasicInformationEventsController@destroyQuery')
    ->name('basicinformation.events.destroy.query')->middleware('legacy.form:events');

// BIOG_INST_DATA
Route::get('basicinformation/{id}/socialinst/edit', 'BasicInformationSocialInstController@editQuery')
    ->name('basicinformation.socialinst.edit.query')->middleware('legacy.form:socialinst');
Route::match(['put', 'patch'], 'basicinformation/{id}/socialinst/update', 'BasicInformationSocialInstController@updateQuery')
    ->name('basicinformation.socialinst.update.query')->middleware('legacy.form:socialinst');
Route::delete('basicinformation/{id}/socialinst/delete', 'BasicInformationSocialInstController@destroyQuery')
    ->name('basicinformation.socialinst.destroy.query')->middleware('legacy.form:socialinst');

// POSSESSION_DATA
Route::get('basicinformation/{id}/possession/edit', 'BasicInformationPossessionController@editQuery')
    ->name('basicinformation.possession.edit.query')->middleware('legacy.form:possession');
Route::match(['put', 'patch'], 'basicinformation/{id}/possession/update', 'BasicInformationPossessionController@updateQuery')
    ->name('basicinformation.possession.update.query')->middleware('legacy.form:possession');
Route::delete('basicinformation/{id}/possession/delete', 'BasicInformationPossessionController@destroyQuery')
    ->name('basicinformation.possession.destroy.query')->middleware('legacy.form:possession');

// POSTED_TO_OFFICE_DATA（官名）
Route::get('basicinformation/{id}/offices/edit', 'BasicInformationOfficesController@editQuery')
    ->name('basicinformation.offices.edit.query')->middleware('legacy.form:offices');
Route::match(['put', 'patch'], 'basicinformation/{id}/offices/update', 'BasicInformationOfficesController@updateQuery')
    ->name('basicinformation.offices.update.query')->middleware('legacy.form:offices');
Route::delete('basicinformation/{id}/offices/delete', 'BasicInformationOfficesController@destroyQuery')
    ->name('basicinformation.offices.destroy.query')->middleware('legacy.form:offices');

// 資源路由（放在查詢參數路由之後，作為後備）
Route::resource('basicinformation.addresses', 'BasicInformationAddressesController')->except(['show', 'destroy'])->middleware('legacy.form:addresses');
Route::resource('basicinformation.altnames', 'BasicInformationAltnamesController')->except(['show', 'destroy'])->middleware('legacy.form:altnames');
Route::resource('basicinformation.texts', 'BasicInformationTextsController')->except(['show', 'destroy'])->middleware('legacy.form:texts');
Route::resource('basicinformation.offices', 'BasicInformationOfficesController')->except(['show', 'destroy'])->middleware('legacy.form:offices');
Route::resource('basicinformation.assoc', 'BasicInformationAssocController')->except(['show', 'destroy'])->middleware('legacy.form:assoc');
Route::resource('basicinformation.entries', 'BasicInformationEntriesController')->except(['show', 'destroy'])->middleware('legacy.form:entries');
Route::resource('basicinformation.events', 'BasicInformationEventsController')->except(['show', 'destroy'])->middleware('legacy.form:events');
Route::resource('basicinformation.kinship', 'BasicInformationKinshipController')->except(['show', 'destroy'])->middleware('legacy.form:kinship');
Route::resource('basicinformation.statuses', 'BasicInformationStatusesController')->except(['show', 'destroy'])->middleware('legacy.form:statuses');
Route::resource('basicinformation.possession', 'BasicInformationPossessionController')->except(['show', 'destroy'])->middleware('legacy.form:possession');
Route::resource('basicinformation.socialinst', 'BasicInformationSocialInstController')->except(['show', 'destroy'])->middleware('legacy.form:socialinst');
Route::resource('basicinformation.sources', 'BasicInformationSourcesController')->except(['show', 'destroy'])->middleware('legacy.form:sources');

// BiogMain 提案路由
Route::post('basicinformation/{personid}/{resource}/proposal', 'BasicInformationProposalController@proposalStore')
    ->name('basicinformation.proposal.store')->middleware('legacy.form:proposal');
Route::post('basicinformation/{personid}/{resource}/{id}/proposal', 'BasicInformationProposalController@proposalUpdate')
    ->name('basicinformation.proposal.update')->middleware('legacy.form:proposal');

Route::get('codes', 'CodesController@index')->name('codes.index');
// Inertia + React 版（代碼表總覽）
Route::get('app/codes', 'CodesController@appIndex')
    ->middleware('inertia')
    ->name('app.codes.index');
// 全量導出：route 泛用，但範圍由 config('codes.export_columns') 白名單收斂（本輪僅 OFFICE_CODES）。
// 直連 live 生產庫，故加 throttle 防爬蟲爆量。設計見 docs/OFFICE_CODES_EXPORT_SYNC.md。
Route::get('codes/{table_name}/export', 'CodesController@export')->name('codes.export')->middleware('throttle:6,1');
Route::get('codes/{table_name}', 'CodesController@show')->name('codes.show');
// TEXT_INSTANCE_DATA 的「Load Data」用：依 c_textid 精確取回書名（JSON，不掛 inertia）。
// 額外路徑段，置於下方 {table_name} 泛用路由之前，避免被攔截。
// 直連 live 生產庫、且與 codes 讀取面一樣無登入門檻，故加 throttle（同 codes.export 的理由）。
Route::get('app/codes/text-title/{textId}', 'CodesController@textTitle')
    ->where('textId', '[0-9]+')
    ->middleware('throttle:60,1')
    ->name('app.codes.text-title');
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

// 官職實體聚合 CRUD（/app/office/*）——上層聚合入口，寫入走 mutation API（resource=office）。
// create 須排在 {id}/edit 之前；{id} 限數字避免吞掉 create。
Route::get('app/office', 'OfficeEntityController@appIndex')
    ->middleware('inertia')->name('app.office.index');
Route::get('app/office/create', 'OfficeEntityController@appCreate')
    ->middleware('inertia')->name('app.office.create');
Route::get('app/office/{id}/edit', 'OfficeEntityController@appEdit')
    ->middleware('inertia')->name('app.office.edit')->whereNumber('id');

// 社會機構實體聚合 CRUD（/app/social-institution/*）——上層聚合入口，
// 寫入走 mutation API（resource=social-institution）。同官職：create 先於 {id}/edit。
Route::get('app/social-institution', 'SocialInstitutionEntityController@appIndex')
    ->middleware('inertia')->name('app.social-institution.index');
Route::get('app/social-institution/create', 'SocialInstitutionEntityController@appCreate')
    ->middleware('inertia')->name('app.social-institution.create');
Route::get('app/social-institution/{id}/edit', 'SocialInstitutionEntityController@appEdit')
    ->middleware('inertia')->name('app.social-institution.edit')->whereNumber('id');

// 文獻實體聚合 CRUD（/app/text/*）——上層聚合入口（TEXT_CODES ＋ TEXT_INSTANCE_DATA 版本層級），
// 寫入走 mutation API（resource=text-entity）。同官職：create 先於 {id}/edit。
Route::get('app/text', 'TextEntityController@appIndex')
    ->middleware('inertia')->name('app.text.index');
Route::get('app/text/create', 'TextEntityController@appCreate')
    ->middleware('inertia')->name('app.text.create');
Route::get('app/text/{id}/edit', 'TextEntityController@appEdit')
    ->middleware('inertia')->name('app.text.edit')->whereNumber('id');
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

// 原本這裡是 `Route::resource('operations', ...)`，但 OperationsController 只實作 index()
// 與一個空的 store()；resource 因此生出 create／show／edit／update／destroy 五條指向不存在
// 方法的路由，命中即 500（#1250）。index 已在本檔開頭以顯式路由宣告（operations.index），
// 空的 store 沒有任何呼叫端，故整段移除；確認過全庫沒有引用被拿掉的那些路由名稱。
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
        // 同 operations（#1250）：CrowdsourcingController 只有 index()／appIndex()／
        // confirm()／reject() 與一個空的 store()，resource 生出的 create／show／edit／
        // update／destroy 全是 500。index 已於上方顯式宣告（同在 superadmin 群組內），
        // 空的 store 無呼叫端，整段移除。
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
    // 逐列直接編輯拼音（回傳 JSON，React 以 fetch 呼叫並就地更新該列；重用既有 updatePinyin）
    Route::post('app/admin/batch-load-book-titles/update-pinyin', 'AdminBatchLoadBookTitlesController@updatePinyin')
        ->name('app.admin.batch-load-book-titles.update-pinyin');
    // 罕見字檢測（回傳 JSON）：只查 pinyin 表，列出表未收的漢字與行號，匯入前先行檢查。
    Route::post('app/admin/batch-load-book-titles/check-rare-chars', 'AdminBatchLoadBookTitlesController@checkRareChars')
        ->name('app.admin.batch-load-book-titles.check-rare-chars');
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
    // 外部資料庫引用瀏覽器：已開放活躍帳號，路徑不再帶 admin 前綴（controller 沿用 WikiMaintenanceController 名稱）。
    Route::get('external-db-link', 'WikiMaintenanceController@index')->name('external-db-link');
    Route::get('app/external-db-link', 'WikiMaintenanceController@appIndex')->name('app.external-db-link')->middleware('inertia');
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
    // QA 模式多輪追問每輪都會呼叫一次 LLM API，屬有實際成本的操作；比照 routes/ai.php 既有
    // throttle 慣例，依登入使用者 ID 限流（見 docs/QUERY_PLAYGROUND_QA_MULTITURN_PLAN.md 第 6.4 節）。
    // 用具名 limiter（定義於 RouteServiceProvider::boot()）而非直接把數字內插進字串：
    // 內插字串在路由註冊當下就把上限值定死，之後改 config 不會生效；具名 limiter 的 callback
    // 於每次請求時才讀取 config，才能真正做到「config 驅動」且可在測試中動態調整。
    Route::post('query-playground/answer-from-nl', 'QueryPlaygroundController@answerFromNL')
        ->middleware('throttle:qa-answer')
        ->name('query-playground.answer-from-nl');
    Route::post('query-playground/answer-from-nl-stream', 'QueryPlaygroundController@answerFromNLStream')
        ->middleware('throttle:qa-answer')
        ->name('query-playground.answer-from-nl-stream');
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
