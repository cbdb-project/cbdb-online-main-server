<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

class WelcomeController extends Controller {
    public function __construct() {
        // Phase 6：入口頁 React/Inertia 變體需 HandleInertiaRequests（共用 props/根模板）。
        // 僅作用於顯示頁的 GET 動作；不改授權（入口頁本即公開）。
        $this->middleware('inertia')->only('index');
    }

    /**
     * Show the application welcome page.
     *
     * flag=new → Inertia React 版（landing）；否則維持原 welcome Blade。
     *
     * @return \Illuminate\View\View|\Inertia\Response
     */
    public function index() {
        if (migration_flag_is_new('welcome')) {
            return Inertia::render('Welcome', [
                'is_authenticated' => auth()->check(),
                'urls' => [
                    'home' => url('home'),
                    'login' => route('login', [], false),
                    // 註冊路由可被關閉（Auth::routes(['register' => false])）。這裡是**站台首頁**
                    // （welcome flag 預設 new，GET / 走這條），無保護會讓首頁直接 500——比原本
                    // 只壞掉 /login 更嚴重。null＝前端不渲染註冊入口。
                    'register' => Route::has('register') ? route('register', [], false) : null,
                    'name_api' => url('api/name'),
                    'person_show' => person_show_base_url(),
                    'person_index' => person_index_base_url(),
                ],
            ]);
        }

        return view('welcome');
    }
}
