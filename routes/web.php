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

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::get('email/verify/{token}', ['as' => 'email.verify', 'uses' => 'EmailController@verify']);
Route::get('operations', ['as' => 'operations.index', 'uses' => 'OperationsController@index']);
Route::get('crowdsourcing', ['as' => 'crowdsourcing.index', 'uses' => 'CrowdsourcingController@index']);

Route::get('home', 'HomeController@index')->name('home');

Route::resource('informations', 'InformationsController', ['names' => [
    'create' => 'information.create'
]]);

Route::resource('basicinformation', 'BasicInformationController', ['name' => [
    'show' => 'basicinformation.show',
    'create' => 'basicinformation.create',
    'edit' => 'basicinformation.edit',
    'update' => 'basicinformation.update',
    'index' => 'basicinformation.index',
]]);
Route::get('basicinformation/{id}/saveas', 'BasicInformationController@saveas');
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
Route::post('codes/{table_name}', 'CodesController@store')->name('codes.store');
Route::delete('codes/{table_name}/{id}', 'CodesController@destroy')->name('codes.destroy');

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

Route::resource('altnamecodes', 'AltnameCodesController', ['name' => [
    'show' => 'altnamecode.show',
    'create' => 'altnamecode.create',
    'edit' => 'altnamecode.edit',
    'update' => 'altnamecode.update'
]]);

Route::resource('appointcodes', 'AppointCodesController', ['name' => [
    'show' => 'appointcode.show',
    'create' => 'appointcode.create',
    'edit' => 'appointcode.edit',
    'update' => 'appointcode.update'
]]);

Route::resource('entries', 'EntriesController', ['name' => [
    'show' => 'entry.show',
    'create' => 'entry.create',
    'edit' => 'entry.edit',
    'update' => 'entry.update'
]]);

Route::resource('statuses', 'StatusesController', ['name' => [
    'show' => 'status.show',
    'create' => 'status.create',
    'edit' => 'status.edit',
    'update' => 'status.update'
]]);

Route::resource('events', 'EventsController', ['name' => [
    'show' => 'event.show',
    'create' => 'event.create',
    'edit' => 'event.edit',
    'update' => 'event.update'
]]);

Route::resource('kinship', 'KinshipController', ['name' => [
    'show' => 'kinship.show',
    'create' => 'kinship.create',
    'edit' => 'kinship.edit',
    'update' => 'kinship.update'
]]);

Route::resource('assoc', 'AssocController', ['name' => [
    'show' => 'assoc.show',
    'create' => 'assoc.create',
    'edit' => 'assoc.edit',
    'update' => 'assoc.update'
]]);

Route::resource('manage', 'ManagementController', ['name' => [
    'show' => 'manage.show',
    'create' => 'manage.create',
    'edit' => 'manage.edit',
    'update' => 'manage.update'
]]);

Route::resource('operations', 'OperationsController', ['name' => [
    'show' => 'operations.show',
    'create' => 'operations.create',
    'edit' => 'operations.edit',
    'update' => 'operations.update'
]]);

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

Route::get('test', function (Request $request){
    $data = \App\TextCode::find(2031);
    $data->type;
    return $data;
});

Route::resource('textcodes', 'TextCodesController', ['name' => [
    'show' => 'textcode.show',
    'create' => 'textcode.create',
    'edit' => 'textcode.edit',
    'update' => 'textcode.update'
]]);

Route::get('textcodes/{id}/delete', 'TextCodesController@destroy');

Route::resource('textinstancedata', 'TextInstanceDataController', ['name' => [
    'show' => 'textinstancedata.show',
    'create' => 'textinstancedata.create',
    'edit' => 'textinstancedata.edit',
    'update' => 'textinstancedata.update'
]]);

Route::get('textinstancedata/{id}/delete', 'TextInstanceDataController@destroy');

Route::resource('addrbelongsdata', 'AddrBelongsDataController', ['name' => [
    'show' => 'addrbelongsdata.show',
    'create' => 'addrbelongsdata.create',
    'edit' => 'addrbelongsdata.edit',
    'update' => 'addrbelongsdata.update'
]]);

Route::get('addrbelongsdata/{id}/delete', 'AddrBelongsDataController@destroy');

Route::resource('addrcodes', 'AddrCodesController', ['name' => [
    'show' => 'addrcode.show',
    'create' => 'addrcode.create',
    'edit' => 'addrcode.edit',
    'update' => 'addrcode.update'
]]);

Route::resource('officecodes', 'OfficeCodesController', ['name' => [
    'show' => 'officecode.show',
    'create' => 'officecode.create',
    'edit' => 'officecode.edit',
    'update' => 'officecode.update'
]]);

Route::resource('socialinstitutioncodes', 'SocialInstitutionCodesController', ['name' => [
    'show' => 'socialinstitutioncode.show',
    'create' => 'socialinstitutioncode.create',
    'edit' => 'socialinstitutioncode.edit',
    'update' => 'socialinstitutioncode.update'
]]);
