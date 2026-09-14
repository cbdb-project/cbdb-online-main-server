<?php

namespace App\Http\Controllers;

class HomeController extends Controller {
    /**
     * `/home` 入口：一律導向人物列表。
     *
     * 本方法**現行**不渲染視圖——歷史上曾 `return view('home', …)`，後改為
     * redirect 並把原行註解掉；該行指向的 resources/views/home.blade.php
     * 已於 Blade 下架計畫環節 1 刪除（死碼），註解一併移除。
     *
     * 但 `/home` 路由本身仍在服役，**不可下架**：
     *   - RedirectIfAuthenticated 硬寫 `redirect('/home')`；
     *   - Login／Register／ResetPassword 三個 Auth controller 的 `$redirectTo` 都是 '/home'；
     *   - React 端 Layouts/AuthLayout 與 Pages/Profile/Edit 也連到它。
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function index() {
        return redirect('/basicinformation');
    }
}
