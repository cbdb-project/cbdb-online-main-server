<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2018/6/1
 * Time: 15:40
 */

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    use AuthenticatesUsers;

    public function __construct()
    {
        $this->middleware('guest');
    }

    protected function authenticateClient(Request $request){
        $credentials = $this->credentials($request);

        $request->request->add([
            'grant_type' => $request->grant_type,
            'client_id' => $request->client_id,
            'client_secret' => $request->client_secret,
            'username' => $credentials['email'],
            'password' => $credentials['password'],
        ]);

        $proxy = Request::create('oauth/token', 'POST');

        $reponse = \Route::dispatch($proxy);
        return $reponse;
    }

    protected function authenticated(Request $request)
    {
        return $this->authenticateClient($request);
    }

    protected function sendLoginResponse(Request $request)
    {
        $this->clearLoginAttempts($request);
        return $this->authenticated($request);
    }

    protected function sendFailedLoginResponse(Request $request)
    {
        $msg = $request['errors'];
        $code = $request['code'];
        return $this->failed($msg, $code);
    }
}