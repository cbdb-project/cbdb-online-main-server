<?php

namespace App\Http\Controllers;

use App\Models\User;
use Auth;
use Carbon\Carbon;
use Illuminate\Support\Str;

class EmailController extends Controller {
    public function verify($token) {
        $user = User::where('confirmation_token', $token)->first();
        if (is_null($user)) {
            flash('用戶啟用失敗，請重新發送啟用郵件 '.Carbon::now(), 'error');

            return route('/');
        }
        //        $user->is_active = 2;
        $user->confirmation_token = Str::random(40);
        $user->save();
        Auth::login($user);
        flash('用戶啟用成功 '.Carbon::now(), 'success');

        return redirect('/home');
    }
}
