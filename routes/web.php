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


Route::get('/', 'WelcomeController@index');

Auth::routes();

Route::get('email/verify/{token}', ['as' => 'email.verify', 'uses' => 'EmailController@verify']);
Route::get('operations', ['as' => 'operations.index', 'uses' => 'OperationsController@index']);
Route::get('crowdsourcing', ['as' => 'crowdsourcing.index', 'uses' => 'CrowdsourcingController@index']);

Route::get('home', 'HomeController@index')->name('home');
Route::get('dashboard', 'DashboardController@index')->middleware('auth')->name('dashboard');
Route::get('cbdbapi/person.php', 'CbdbApiController@person')->name('cbdbapi.v1.person');
Route::get('cbdbapi/person', 'CbdbApiController@person');
Route::get('view', 'ViewTableController@index')->middleware('auth')->name('view.index');
Route::get('view/{key}', 'ViewTableController@show')->middleware('auth')->name('view.show');

Route::resource('basicinformation', 'BasicInformationController', ['name' => [
    'show' => 'basicinformation.show',
    'create' => 'basicinformation.create',
    'edit' => 'basicinformation.edit',
    'update' => 'basicinformation.update',
    'index' => 'basicinformation.index',
]]);
Route::get('basicinformation/{id}/saveas', 'BasicInformationController@saveas');
Route::get('basicinformation/{id}/Duplicate_Collateral_Info', 'BasicInformationController@Duplicate_Collateral_Info');
Route::get('basicinformation/{id}/offices/{cpk}/saveas', 'BasicInformationOfficesController@saveas');

Route::resource('basicinformation.addresses', 'BasicInformationAddressesController');
Route::resource('basicinformation.altnames', 'BasicInformationAltnamesController');
Route::resource('basicinformation.texts', 'BasicInformationTextsController');
Route::resource('basicinformation.offices', 'BasicInformationOfficesController');
Route::resource('basicinformation.assoc', 'BasicInformationAssocController');
Route::resource('basicinformation.entries', 'BasicInformationEntriesController');
Route::resource('basicinformation.events', 'BasicInformationEventsController');
Route::resource('basicinformation.kinship', 'BasicInformationKinshipController');
Route::resource('basicinformation.statuses', 'BasicInformationStatusesController');
Route::resource('basicinformation.possession', 'BasicInformationPossessionController');
Route::resource('basicinformation.socialinst', 'BasicInformationSocialInstController');
Route::resource('basicinformation.sources', 'BasicInformationSourcesController');

// BiogMain 提案路由
Route::post('basicinformation/{personid}/{resource}/proposal', 'BasicInformationProposalController@proposalStore')
    ->name('basicinformation.proposal.store');
Route::post('basicinformation/{personid}/{resource}/{id}/proposal', 'BasicInformationProposalController@proposalUpdate')
    ->name('basicinformation.proposal.update');

Route::get('codes', 'CodesController@index')->name('codes.index');
Route::get('codes/{table_name}', 'CodesController@show')->name('codes.show');
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

Route::match(['get', 'post'], 'merge-preview', 'MergePreviewController@index')->name('merge-preview.index');

Route::resource('operations', 'OperationsController', ['name' => [
    'show' => 'operations.show',
    'create' => 'operations.create',
    'edit' => 'operations.edit',
    'update' => 'operations.update',
]]);
Route::post('operations/{operation}/restore', 'OperationsController@restore')->name('operations.restore');

Route::resource('crowdsourcing', 'CrowdsourcingController', ['name' => [
    'show' => 'crowdsourcing.show',
    'create' => 'crowdsourcing.create',
    'edit' => 'crowdsourcing.edit',
    'update' => 'crowdsourcing.update',
]]);


Route::get('crowdsourcing/{id}/confirm', 'CrowdsourcingController@confirm');
Route::get('crowdsourcing/{id}/reject', 'CrowdsourcingController@reject');

Route::resource('modified', 'ModifiedController', ['name' => [
    'show' => 'modified.show',
    'create' => 'modified.create',
    'edit' => 'modified.edit',
    'update' => 'modified.update',
]]);

Route::middleware('auth')->group(function () {
    Route::get('profile', 'UserProfileController@edit')->name('profile.edit');
    Route::patch('profile', 'UserProfileController@update')->name('profile.update');

    // API Token 管理
    Route::get('api-tokens', 'ApiTokenController@index')->name('api-tokens.index');
    Route::post('api-tokens', 'ApiTokenController@store')->name('api-tokens.store');
    Route::delete('api-tokens/{tokenId}', 'ApiTokenController@destroy')->name('api-tokens.destroy');
    Route::delete('api-tokens', 'ApiTokenController@destroyAll')->name('api-tokens.destroy-all');

    Route::get('admin/explainsql', 'AdminExplainSqlController@show')->name('admin.explainsql');
    Route::post('admin/explainsql', 'AdminExplainSqlController@explain');
    Route::get('admin/batch-load-book-titles', 'AdminBatchLoadBookTitlesController@showForm')->name('admin.batch-load-book-titles');
    Route::post('admin/batch-load-book-titles', 'AdminBatchLoadBookTitlesController@store')->name('admin.batch-load-book-titles.store');
    Route::get('admin/batch-load-social-institutes', 'AdminBatchLoadSocialInstitutesController@showForm')->name('admin.batch-load-social-institutes');
    Route::post('admin/batch-load-social-institutes', 'AdminBatchLoadSocialInstitutesController@store')->name('admin.batch-load-social-institutes.store');
    Route::get('admin/batch-load-offices', 'AdminBatchLoadOfficesController@showForm')->name('admin.batch-load-offices');
    Route::post('admin/batch-load-offices', 'AdminBatchLoadOfficesController@store')->name('admin.batch-load-offices.store');
    Route::get('admin/wiki-maintenance', 'WikiMaintenanceController@index')->name('admin.wiki-maintenance');
    Route::post('admin/wiki-maintenance/delete-all', 'WikiMaintenanceController@deleteAll')->name('admin.wiki-maintenance.delete-all');
    Route::post('admin/wiki-maintenance/reimport', 'WikiMaintenanceController@reimport')->name('admin.wiki-maintenance.reimport');
    Route::post('admin/wiki-maintenance/import-url', 'WikiMaintenanceController@importFromUrl')->name('admin.wiki-maintenance.import-url');
    Route::get('admin/wiki-maintenance/progress/{taskId}', 'WikiMaintenanceController@getImportProgress')->where('taskId', '[a-zA-Z0-9_]+')->name('admin.wiki-maintenance.progress');
    Route::post('admin/wiki-maintenance/cancel/{taskId}', 'WikiMaintenanceController@cancelImport')->where('taskId', '[a-zA-Z0-9_]+')->name('admin.wiki-maintenance.cancel');
    Route::get('admin/wiki-maintenance/test-progress', 'TestController@testProgress');
    Route::get('admin/cbdb-table-maintenance', 'CbdbTableMaintenanceController@index')->name('admin.cbdb-table-maintenance');
    Route::post('admin/cbdb-table-maintenance/rebuild', 'CbdbTableMaintenanceController@rebuild')->name('admin.cbdb-table-maintenance.rebuild');
    Route::get('admin/cbdb-table-maintenance/progress/{taskId}', 'CbdbTableMaintenanceController@getNameFtsProgress')
        ->where('taskId', '[a-zA-Z0-9_]+')
        ->name('admin.cbdb-table-maintenance.progress');
    Route::get('admin/unidirectional-relationship-repair', 'UnidirectionalRelationshipRepairController@index')->name('admin.unidirectional-relationship-repair');
    Route::post('admin/unidirectional-relationship-repair/kinship', 'UnidirectionalRelationshipRepairController@repairKinship')->name('admin.unidirectional-relationship-repair.kinship');
    // Query Playground
    Route::get('query-playground', 'QueryPlaygroundController@index')->name('query-playground.index');
    Route::post('query-playground/run', 'QueryPlaygroundController@run')->name('query-playground.run');

    Route::post('admin/unidirectional-relationship-repair/assoc', 'UnidirectionalRelationshipRepairController@repairAssoc')->name('admin.unidirectional-relationship-repair.assoc');
});
