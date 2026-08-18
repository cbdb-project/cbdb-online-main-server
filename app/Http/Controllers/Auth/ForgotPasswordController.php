<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ForgotPasswordController extends Controller {
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    use SendsPasswordResetEmails;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct() {
        $this->middleware('guest');
        // Phase 6：忘記密碼頁 React/Inertia 變體需 HandleInertiaRequests（共用 props/根模板）。
        // 僅作用於顯示表單的 GET 動作；POST 處理（sendResetLinkEmail）不掛，授權仍由 guest middleware 控制。
        $this->middleware('inertia')->only('showLinkRequestForm');
        // #1264：這條原本是無上限的信件放大器——每次請求都會查 users、重寫 password_resets、
        // 並在請求執行緒內同步連 SMTP 寄一封信（QUEUE_CONNECTION=sync）。
        // 這道閘按 IP 封頂；config/auth.php 的 passwords.users.throttle 另外按 email 擋重發。
        $this->middleware('throttle.guest:password-email')->only('sendResetLinkEmail');
    }

    /**
     * 顯示「寄出重設連結」表單。
     * flag=new → Inertia React 版；否則維持 trait 預設的 auth.passwords.email Blade。
     *
     * @return \Illuminate\Contracts\View\View|\Inertia\Response
     */
    public function showLinkRequestForm(Request $request) {
        if (migration_flag_is_new('auth.passwords')) {
            return Inertia::render('Auth/ForgotPassword', [
                'status' => session('status'),
            ]);
        }

        return view('auth.passwords.email');
    }
}
