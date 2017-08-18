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

Route::resource('tests', 'TestController', ['name' => [
    'show' => 'test.show',
    'create' => 'test.create',
    'edit' => 'test.edit',
    'update' => 'test.update'
]]);