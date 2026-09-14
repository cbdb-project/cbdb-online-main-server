<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * React/Inertia 根模板注入的 CHGIS 地圖設定（window.chgisMapConfig）。
 *
 * resources/views/inertia.blade.php 會 @include('partials.chgis-map-assets')，也就是
 * **每一個 React 頁都會執行這個 partial**——它壞掉就是全站 Inertia 500。在 Blade 下架
 * 計畫環節 1.5 的分流中發現：這段注入唯一的覆蓋是 BasicInformationPagesLoadTest（legacy
 * Blade 頁面測試，環節 2 會刪），刪掉之後就完全沒人守了。故補這一份。
 *
 * 同時鎖住環節 2 commit 1 做的改動：`pointsUrlTemplate` 取代原本的 `pointsUrlBase`。
 * 舊版傳的是 legacy 路由 `basicinformation.index` 的 URL 當 base，由前端自行接上
 * `/{id}/map-points`——那讓每個 React 頁隱含相依於一條即將被改成導向的 legacy 路由名，
 * 名字一動就是全站 500。改為直接產生 `basicinformation.map-points` 的 URL 模板。
 */
class InertiaChgisMapAssetsTest extends TestCase {
    /** 取一個最輕量、確定會走 Inertia 根模板的頁面。 */
    private function renderAnInertiaPage(): string {
        return $this->get('/')->assertOk()->getContent();
    }

    public function testInertiaRootTemplateInjectsChgisMapConfig(): void {
        $html = $this->renderAnInertiaPage();

        $this->assertStringContainsString('window.chgisMapConfig', $html, 'React 根模板必須注入 CHGIS 設定');
        $this->assertStringContainsString('tileUrlTemplate', $html);
        $this->assertStringContainsString('pointsUrlTemplate', $html);
    }

    /**
     * points URL 必須是 map-points 自己的路由，且帶 {id} 佔位符。
     *
     * 這條斷言就是「不要再相依於 basicinformation.index」的護欄：若有人把
     * `pointsUrlTemplate` 改回傳 base（`/basicinformation`），這裡會紅。
     */
    public function testPointsUrlTemplateUsesMapPointsRouteWithIdPlaceholder(): void {
        $html = $this->renderAnInertiaPage();

        // Js::from() 會把 / 轉義成 \/
        $expected = str_replace('/', '\\/', route('basicinformation.map-points', ['id' => '__ID__'], false));
        $expected = str_replace('__ID__', '{id}', $expected);

        $this->assertStringContainsString(
            "pointsUrlTemplate: '" . $expected . "'",
            $html,
            'pointsUrlTemplate 必須是 basicinformation.map-points 的相對 URL 且帶 {id} 佔位符'
        );
        $this->assertStringNotContainsString(
            'pointsUrlBase',
            $html,
            '不得再傳舊的 pointsUrlBase——那會讓每個 React 頁相依於 legacy 路由 basicinformation.index'
        );
    }

    /** tile 模板須為相對路徑並帶 ?v= 底圖版本號（cache-busting，與 tile ETag 同源）。 */
    public function testTileUrlTemplateIsRelativeAndCacheBusted(): void {
        $html = $this->renderAnInertiaPage();

        $chgisManager = app(\App\Services\ChgisMapManager::class);
        $version = $chgisManager->isReady() ? (@filemtime($chgisManager->path()) ?: 0) : 0;

        $this->assertStringContainsString(
            '\\/chgis-map\\/tiles\\/{z}\\/{x}\\/{y}?v=' . $version,
            $html
        );
    }

    /** i18n 鍵要一併注入，否則 modal 會顯示 raw key。 */
    public function testInjectsI18nKeys(): void {
        $html = $this->renderAnInertiaPage();

        $this->assertStringContainsString('count_unit', $html);
        $this->assertStringContainsString('legend_count_hint', $html);
    }
}
