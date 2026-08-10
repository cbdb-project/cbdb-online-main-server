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
        // 未啟用（含被停用）帳號不得經此連結取得 session——此端點不再負責啟用
        // （is_active 的設定早已停用，見下方註解），故它只能登入「已啟用」帳號，
        // 不可成為繞過 is_active 檢查的登入後門。
        if (!$user->isActive()) {
            flash('帳號尚未啟用，請聯繫管理員。 '.Carbon::now(), 'error');

            return redirect()->route('login');
        }

        //        $user->is_active = 2;
        $user->confirmation_token = Str::random(40);
        $user->save();
        Auth::login($user);
        flash('用戶啟用成功 '.Carbon::now(), 'success');

        return redirect('/home');
    }
}
