<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LoginController extends Controller {
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
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
        $this->middleware('guest')->except('logout');
    }

    public function showLoginForm(Request $request) {
        $this->storeIntendedRedirect($request);

        return view('auth.login');
    }

    public function login(Request $request) {
        $this->storeIntendedRedirect($request);
        $this->validateLogin($request);

        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendLockoutResponse($request);
        }

        if ($this->attemptLogin($request)) {
            flash('Login successful', 'success');

            return $this->sendLoginResponse($request);
        }

        // If the login attempt was unsuccessful we will increment the number of attempts
        // to login and redirect the user back to the login form. Of course, when this
        // user surpasses their maximum number of attempts they will get locked out.
        $this->incrementLoginAttempts($request);

        return $this->sendFailedLoginResponse($request);
    }

    /**
     * The user has been authenticated.
     */
    protected function authenticated(Request $request, $user) {
        $settings = $user->settings ?? [];
        unset($settings['last_login_at'], $settings['registration_at']);
        $settings['last_login_ip'] = $request->ip();

        $user->settings = $settings;
        $user->save();
    }

    /**
     * Get the post-login redirect path.
     *
     * @return string
     */
    public function redirectPath() {
        // 使用 intended() 方法，如果 session 中有原始 URL，則重定向到該 URL
        // 否則重定向到默認的 /home
        return redirect()->intended($this->redirectTo)->getTargetUrl();
    }

    protected function storeIntendedRedirect(Request $request): void {
        $redirect = $request->input('redirect');

        if (!is_string($redirect)) {
            return;
        }

        $redirect = trim($redirect);

        if ($redirect === '' || !$this->isSafeRedirectPath($redirect)) {
            return;
        }

        $request->session()->put('url.intended', $redirect);
    }

    protected function isSafeRedirectPath(string $redirect): bool {
        if (!Str::startsWith($redirect, '/') || Str::startsWith($redirect, '//')) {
            return false;
        }

        $path = parse_url($redirect, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return false;
        }

        foreach (['/login', '/register', '/password'] as $prefix) {
            if ($path === $prefix || Str::startsWith($path, $prefix . '/')) {
                return false;
            }
        }

        return true;
    }
}
