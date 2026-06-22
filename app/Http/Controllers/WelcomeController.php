<?php

namespace App\Http\Controllers;

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
                    'register' => route('register', [], false),
                    'name_api' => url('api/name'),
                    'basicinformation' => url('basicinformation'),
                ],
            ]);
        }

        return view('welcome');
    }
}
