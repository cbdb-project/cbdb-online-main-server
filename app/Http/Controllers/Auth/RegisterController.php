<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Mail;
use Naux\Mail\SendCloudTemplate;

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
            'institution' => $data['institution'],
            'avatar' => 'avatar0.png',
            'confirmation_token' => Str::random(40),
            'settings' => [
                'registration_ip' => $ip,
                'last_login_ip' => $ip,
            ],
            'password' => bcrypt($data['password']),
        ]);

        //20210804遮除，禁止發送帳戶激活郵件。
        //$this->sendVerifyEmailTo($user);
        return $user;
    }

    private function sendVerifyEmailTo($user) {
        // 模板变量
        $bind_data = [
            'url' => route('email.verify', ['token' => $user->confirmation_token]),
            'name' => $user->name,
        ];
        $template = new SendCloudTemplate('cbdb_register', $bind_data);

        Mail::raw($template, function ($message) use ($user) {
            $message->from('cbdb.harvard@gmail.com', 'CBDB');

            $message->to($user->email);
        });
    }
}
