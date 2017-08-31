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

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::get('/redirect', function () {
    $query = http_build_query([
        'client_id' => 3,
        'redirect_uri' => 'http://cbdb-online-main-server.dev/callback',
        'response_type' => 'code',
        'scope' => '',
    ]);

    return redirect('http://cbdb-online-main-server.dev/oauth/authorize?'.$query);
});

Route::get('/callback', function (Request $request) {
    $http = new GuzzleHttp\Client;

    $response = $http->post('http://cbdb-online-main-server.dev/oauth/token', [
        'form_params' => [
            'grant_type' => 'authorization_code',
            'client_id' => 3,
            'client_secret' => 'gQHLMyG3qh1iDxwTmrLqKxtqVqcGNSHGtV3ixprA',
            'redirect_uri' => 'http://cbdb-online-main-server.dev/callback',
            'code' => $request->code,
        ],
    ]);

    return json_decode((string) $response->getBody(), true);
});

Route::get('/home', 'HomeController@index')->name('home');

Route::resource('informations', 'InformationsController', ['names' => [
    'create' => 'information.create'
]]);

Route::resource('basicinformation', 'BasicInformationController', ['name' => [
    'show' => 'basicinformation.show',
    'create' => 'basicinformation.create',
    'edit' => 'basicinformation.edit',
    'update' => 'basicinformation.update'
]]);

Route::resource('addresses', 'AddressesController', ['name' => [
    'show' => 'address.show',
    'create' => 'address.create',
    'edit' => 'address.edit',
    'update' => 'address.update'
]]);

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

Route::resource('texts', 'TextsController', ['name' => [
    'show' => 'text.show',
    'create' => 'text.create',
    'edit' => 'text.edit',
    'update' => 'text.update'
]]);

Route::resource('altnames', 'AltnamesController', ['name' => [
    'show' => 'altname.show',
    'create' => 'altname.create',
    'edit' => 'altname.edit',
    'update' => 'altname.update'
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

Route::resource('offices', 'OfficesController', ['name' => [
    'show' => 'office.show',
    'create' => 'office.create',
    'edit' => 'office.edit',
    'update' => 'office.update'
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

Route::get('admin', function (){
    return view('admin-template');
});