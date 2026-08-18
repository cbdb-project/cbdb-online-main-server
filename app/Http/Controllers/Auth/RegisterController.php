<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;

class RegisterController extends Controller {
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
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
        // Phase 6：註冊頁 React/Inertia 變體需 HandleInertiaRequests（共用 props/根模板）。
        // 僅作用於顯示表單的 GET 動作；POST 處理（register）不掛，授權仍由 guest middleware 控制。
        $this->middleware('inertia')->only('showRegistrationForm');
        // #1264：註冊端點原本完全沒有限流（web 群組沒有 throttle，Auth::routes() 也不在任何群組裡），
        // 每次請求至少一次 unique:users 查詢、成功還會建一列待管理員啟用的帳號。
        // 掛在建構子而非覆寫 Auth::routes() 生出的路由：POST /register 沒有路由名稱，而「同 URI 後
        // 註冊覆蓋」的寫法會留下殘影路由（McpEndpointMiddlewareTest 已把它列為壞味道）。
        $this->middleware('throttle.guest:register')->only('register');
    }

    /**
     * Show the application registration form.
     *
     * @return \Illuminate\Http\Response
     */
    public function showRegistrationForm() {
        //        return '内测阶段，暂不开放注册';
        if (migration_flag_is_new('auth.register')) {
            return Inertia::render('Auth/Register', [
                'status' => session('status'),
            ]);
        }

        return view('auth.register');
    }

    /**
     * 註冊成功後的回應。
     *
     * 同 LoginController：React 註冊頁以 Inertia XHR 送出，成功後導向的 dashboard
     * 仍是 Blade 頁。對 Inertia 請求改用 Inertia::location 硬導向；非 Inertia 則回傳
     * null，沿用 RegistersUsers trait 預設的 redirect($this->redirectPath())。
     *
     * @param  \App\Models\User  $user
     */
    protected function registered(Request $request, $user) {
        // 新帳號預設未啟用（is_active=0）：不保留 RegistersUsers 自動建立的登入 session，
        // 否則未啟用帳號可帶著 session 觸及 auth-only 端點（例如 POST /api-tokens 建立 API token）。
        // 待管理員啟用後再由使用者自行登入。維持既有導向（Inertia location / 預設 redirect）不變。
        $this->guard()->logout();
        session()->flash('status', '註冊成功，帳號待管理員啟用後即可登入。');

        if ($request->header('X-Inertia')) {
            return Inertia::location($this->redirectPath());
        }

        return null;
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data) {
        return Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            // #1264 順手修：institution 原本完全不在規則裡，但 create() 直接讀 $data['institution']，
            // 於是任何沒帶這個欄位的 POST（例如腳本或 curl）都會炸 ErrorException「Undefined array key」
            // ＝HTTP 500，而不是一個驗證錯誤。用 nullable 而非 required 是刻意的：users.institution
            // 本來就可為 null，React 註冊頁（目前的上線路徑）也沒把它標成必填——改成 required 會讓
            // 原本能註冊的人突然被擋。（Blade 版表單有 HTML required，兩個版本本來就不一致。）
            'institution' => 'nullable|string|max:255',
            'password' => 'required|string|min:6|confirmed',
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return \App\Models\User
     */
    protected function create(array $data) {
        $ip = request()->ip();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            // nullable 規則不會補上缺少的鍵，所以這裡仍要 ?? null（欄位本身可為 null）。
            'institution' => $data['institution'] ?? null,
            'avatar' => 'avatar0.png',
            'confirmation_token' => Str::random(40),
            'settings' => [
                'registration_ip' => $ip,
                'last_login_ip' => $ip,
            ],
            'password' => bcrypt($data['password']),
        ]);

        // 帳戶激活郵件自 2021-08 起停發，帳號改由管理員手動啟用（/manage 或
        // php artisan cbdb:manage-user）。原本的 sendVerifyEmailTo() 已隨 email/verify
        // 端點一併刪除——它引用 route('email.verify')，留著會在有人恢復呼叫時直接拋
        // RouteNotFoundException（下架原因見 routes/web.php 的註解）。
        return $user;
    }
}
