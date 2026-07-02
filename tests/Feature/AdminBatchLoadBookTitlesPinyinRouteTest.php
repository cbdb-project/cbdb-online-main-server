<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 迴歸：React 版 /app/admin/batch-load-book-titles 曾漏掉舊 Blade 版的「直接編輯拼音」
 * 功能——app 命名空間下缺少 update-pinyin 路由，前端也無法呼叫 updatePinyin。
 * 此處鎖定 app 版 update-pinyin 路由存在且指向既有的 updatePinyin 方法。
 */
class AdminBatchLoadBookTitlesPinyinRouteTest extends TestCase {
    #[Test]
    public function app_update_pinyin_route_is_registered_and_maps_to_controller() {
        $this->assertTrue(
            Route::has('app.admin.batch-load-book-titles.update-pinyin'),
            'app 版 update-pinyin 路由必須存在，否則新界面無法直接編輯拼音'
        );

        $route = Route::getRoutes()->getByName('app.admin.batch-load-book-titles.update-pinyin');
        $this->assertSame('app/admin/batch-load-book-titles/update-pinyin', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertSame(
            'App\Http\Controllers\AdminBatchLoadBookTitlesController@updatePinyin',
            $route->getActionName()
        );
    }
}
