<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ResetPasswordController extends Controller {
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    /**
     * Where to redirect users after resetting their password.
     *
     * @var string
     */
    protected $redirectTo = '/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct() {
        $this->middleware('guest');
        // Phase 6：重設密碼頁 React/Inertia 變體需 HandleInertiaRequests（共用 props/根模板）。
        // 僅作用於顯示表單的 GET 動作；POST 處理（reset）不掛，授權仍由 guest middleware 控制。
        $this->middleware('inertia')->only('showResetForm');
    }

    /**
     * 顯示重設密碼表單。token/email 比照 trait 從 route/query 取得。
     * flag=new → Inertia React 版（帶 token/email props）；否則維持原 auth.passwords.reset Blade。
     *
     * @return \Illuminate\Contracts\View\View|\Inertia\Response
     */
    public function showResetForm(Request $request) {
        $token = $request->route()->parameter('token');

        if (migration_flag_is_new('auth.passwords')) {
            return Inertia::render('Auth/ResetPassword', [
                'token' => $token,
                'email' => $request->email,
                'status' => session('status'),
            ]);
        }

        return view('auth.passwords.reset')->with(
            ['token' => $token, 'email' => $request->email]
        );
    }

    /**
     * 重設密碼成功後的回應。
     *
     * 同 LoginController：React 重設頁以 Inertia XHR 送出，成功後 trait 會登入使用者
     * 並導向 dashboard（仍是 Blade 頁）。對 Inertia 請求改用 Inertia::location 硬導向；
     * 非 Inertia 維持 trait 原行為（redirect + status flash）。
     */
    protected function sendResetResponse(Request $request, $response) {
        if ($request->header('X-Inertia')) {
            return Inertia::location($this->redirectPath());
        }

        return redirect($this->redirectPath())->with('status', trans($response));
    }
}
