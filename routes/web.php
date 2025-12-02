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

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

Route::get('/', 'WelcomeController@index');

Auth::routes();

Route::get('email/verify/{token}', ['as' => 'email.verify', 'uses' => 'EmailController@verify']);
Route::get('operations', ['as' => 'operations.index', 'uses' => 'OperationsController@index']);
Route::get('crowdsourcing', ['as' => 'crowdsourcing.index', 'uses' => 'CrowdsourcingController@index']);

Route::get('home', 'HomeController@index')->name('home');
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

Route::get('codes', 'CodesController@index')->name('codes.index');
Route::get('codes/{table_name}', 'CodesController@show')->name('codes.show');
Route::get('codes/{table_name}/{id}/edit', 'CodesController@edit')->name('codes.edit');
Route::match(['put', 'patch'], 'codes/{table_name}/{id}', 'CodesController@update')->name('codes.update');
Route::get('codes/{table_name}/create', 'CodesController@create')->name('codes.create');
Route::post('codes/{table_name}/proposal', 'CodesController@proposalStore')->name('codes.propose.store');
Route::match(['post', 'patch'], 'codes/{table_name}/{id}/proposal', 'CodesController@proposalUpdate')->name('codes.propose.update');
Route::get('codes/{table_name}/proposals/{operation}/edit', 'CodesController@proposalEdit')->name('codes.proposals.edit');
Route::patch('codes/{table_name}/proposals/{operation}', 'CodesController@proposalUpdateExisting')->name('codes.proposals.update');
Route::delete('codes/{table_name}/proposals/{operation}', 'CodesController@proposalCancel')->name('codes.proposals.cancel');
Route::post('codes/{table_name}', 'CodesController@store')->name('codes.store');
Route::delete('codes/{table_name}/{id}', 'CodesController@destroy')->name('codes.destroy');

Route::post('operations/{operation}/approve', 'OperationsProposalController@approve')->name('operations.proposals.approve');
Route::post('operations/{operation}/reject', 'OperationsProposalController@reject')->name('operations.proposals.reject');

Route::resource('addresscodes', 'AddressCodesController', ['name' => [
    'show' => 'addresscode.show',
    'create' => 'addresscode.create',
    'edit' => 'addresscode.edit',
    'update' => 'addresscode.update'
]]);

Route::resource('sources', 'SourcesController', ['name' => [
    'show' => 'source.show',
    'create' => 'source.create',
    'edit' => 'source.edit',
    'update' => 'source.update'
]]);

Route::resource('manage', 'ManagementController', ['name' => [
    'show' => 'manage.show',
    'create' => 'manage.create',
    'edit' => 'manage.edit',
    'update' => 'manage.update'
]]);

Route::match(['get', 'post'], 'merge-preview', 'MergePreviewController@index')->name('merge-preview.index');

Route::resource('operations', 'OperationsController', ['name' => [
    'show' => 'operations.show',
    'create' => 'operations.create',
    'edit' => 'operations.edit',
    'update' => 'operations.update'
]]);
Route::post('operations/{operation}/restore', 'OperationsController@restore')->name('operations.restore');

Route::resource('crowdsourcing', 'CrowdsourcingController', ['name' => [
    'show' => 'crowdsourcing.show',
    'create' => 'crowdsourcing.create',
    'edit' => 'crowdsourcing.edit',
    'update' => 'crowdsourcing.update'
]]);


Route::get('crowdsourcing/{id}/confirm', 'CrowdsourcingController@confirm');
Route::get('crowdsourcing/{id}/reject', 'CrowdsourcingController@reject');

Route::resource('modified', 'ModifiedController', ['name' => [
    'show' => 'modified.show',
    'create' => 'modified.create',
    'edit' => 'modified.edit',
    'update' => 'modified.update'
]]);

Route::get('test', 'TestController@index');

Route::middleware('auth')->group(function () {
    Route::get('profile', 'UserProfileController@edit')->name('profile.edit');
    Route::patch('profile', 'UserProfileController@update')->name('profile.update');

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
});
