<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2018/6/1
 * Time: 15:40
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\BiogMain;
use App\OfficeCode;
use App\OfficeCodeTypeRel;
use App\OfficeTypeTree;
use App\Operation;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

ini_set('memory_limit','512M');

class ApiController extends Controller
{
    use AuthenticatesUsers;

    public function __construct()
    {
        $this->middleware('guest');
    }

    protected function OFFICE_CODES(Request $request){
        if($end = $request['end']) {
            if($start = $request['start']) {
                $diffNum = $end - $start + 1;
                $biog = OfficeCode::all()->slice($start, $diffNum);
            }
            else {
                $biog = OfficeCode::all()->take($end);
            }
        }
        elseif($start = $request['start'] && $list = $request['list']) {
            $biog = OfficeCode::all()->slice($start, $list);
        }
        elseif($list = $request['list']) {
            $biog = OfficeCode::all()->take($list); 
        }
        elseif($pId = $request['pId']) {
            $biog = OfficeCode::where('c_office_id', $pId)->get();
        }
        else {
            $biog = OfficeCode::all();
        }
        return $biog;
    }

    protected function OFFICE_CODE_TYPE_REL(Request $request){
        if($end = $request['end']) {
            if($start = $request['start']) {
                $diffNum = $end - $start + 1;
                $biog = OfficeCodeTypeRel::all()->slice($start, $diffNum);
            }
            else {
                $biog = OfficeCodeTypeRel::all()->take($end);
            }
        }
        elseif($start = $request['start'] && $list = $request['list']) {
            $biog = OfficeCodeTypeRel::all()->slice($start, $list);
        }
        elseif($list = $request['list']) {
            $biog = OfficeCodeTypeRel::all()->take($list);
        }
        elseif($pId = $request['pId']) {
            $temp_l = explode("-", $pId);
            $biog = OfficeCodeTypeRel::where('c_office_id', $temp_l[0])->where('c_office_tree_id', $temp_l[1])->first();
        }
        else {
            $biog = OfficeCodeTypeRel::all();
        }
        return $biog;
    }

    protected function OFFICE_TYPE_TREE(Request $request){
        if($end = $request['end']) {
            if($start = $request['start']) {
                $diffNum = $end - $start + 1;
                $biog = OfficeTypeTree::all()->slice($start, $diffNum);
            }
            else {
                $biog = OfficeTypeTree::all()->take($end);
            }
        }
        elseif($start = $request['start'] && $list = $request['list']) {
            $biog = OfficeTypeTree::all()->slice($start, $list);
        }
        elseif($list = $request['list']) {
            $biog = OfficeTypeTree::all()->take($list);
        }
        elseif($pId = $request['pId']) {
            $biog = OfficeTypeTree::where('c_office_type_node_id', $pId)->get();
        }
        else {
            $biog = OfficeTypeTree::all();
        }
        return $biog;
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
