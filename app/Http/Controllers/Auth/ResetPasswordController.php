<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SecurityAuditLogger;
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

    // resetPassword 在下面被覆寫以加上審計；trait 版本改名保留供其呼叫
    // （它來自 trait 而不是父類，所以不能用 parent::）。
    use ResetsPasswords {
        resetPassword as baseResetPassword;
    }

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
    public function __construct(private SecurityAuditLogger $securityAudit) {
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
     * 密碼經「忘記密碼」連結重設。
     *
     * 密碼有兩條寫入路徑：`/profile`（已在 UserProfileController 記錄）與這一條。少了這裡，
     * 攻擊鏈的後半段就沒有紀錄——先改走 email（會留下 email_changed），再用重設連結換密碼，
     * audit_log 只看得到 email 變更，看不到密碼何時被誰從哪個 IP 換掉。反過來也一樣：
     * 真正的使用者自助重設不留紀錄，事後就無法區分「本人重設」與「攻擊者用外洩的重設連結」。
     *
     * actor 刻意留空（actorIsUnknown）：trait 會在寫入密碼後 `Auth::login($user)`，所以審計時
     * 已經「登入成功」，若照常歸因就會把持有重設連結的人記成受影響帳號**本人**——攻擊者用
     * 竊得的 reset token 改掉密碼，事後 audit_log 卻顯示「使用者自己改了密碼」，比不記更糟。
     * 真正可用的線索是 IP／UA，受影響帳號由 rowPk 標明。一律不記密碼雜湊或明文。
     *
     * @param  \App\Models\User  $user
     * @param  string  $password
     */
    protected function resetPassword($user, $password) {
        $this->baseResetPassword($user, $password);

        $this->securityAudit->record(
            table: 'users',
            operation: 'UPDATE',
            rowPk: ['id' => (int) $user->id],
            event: 'password_reset_via_email',
            after: ['password_changed' => true, 'email' => $user->email],
            actorIsUnknown: true
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
