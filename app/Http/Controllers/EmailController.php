<?php

namespace App\Http\Controllers;

use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Auth;

class EmailController extends Controller
{
    public function verify($token)
    {
        $user = User::where('confirmation_token', $token)->first();
        if(is_null($user)) {
            flash('用户激活失败，请重新发送激活邮件 '.Carbon::now(), 'error');
            return route('/');
        }
//        $user->is_active = 2;
        $user->confirmation_token = str_random(40);
        $user->save();
        Auth::login($user);
        flash('用户激活成功 '.Carbon::now(), 'success');
        return redirect('/home');
    }
}
