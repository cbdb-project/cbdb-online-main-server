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

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

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