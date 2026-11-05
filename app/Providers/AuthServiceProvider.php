<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider {
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        'App\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * 讓 Passport 的 `/oauth/*` 路由保持**關閉**（OIDC Provider 計畫，docs/OIDC_PROVIDER_PLAN.md）。
     *
     * Passport 一裝上就會自動註冊 authorize／token 等端點。計畫是分步落地——安裝（本步）、授權端、
     * id_token／discovery、refresh 與停用帳號——在授權端的 `isActive()` 把關與登入回跳測試完成之前，
     * 這些端點不可以出現在任何被部署的環境。要放行時，在對應步驟**有意地**拿掉這一行並補測試。
     *
     * 必須在 register() 而不是 boot()：PassportServiceProvider 在自己的 boot() 裡讀這個旗標。
     * 護欄測試：tests/Feature/OidcProviderDormantTest.php。
     *
     * @return void
     */
    public function register() {
        parent::register();

        Passport::ignoreRoutes();
    }

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot() {
        $this->registerPolicies();
    }
}
