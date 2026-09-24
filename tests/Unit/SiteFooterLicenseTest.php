<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 頁尾授權聲明須與專案官網（cbdb.hsites.harvard.edu）一致：CBDB License → CBDB Data Licensing Terms。
 *
 * 只檢查頁尾區塊：API 說明頁正文的授權摘要仍會合法地提到 CC BY-NC-SA（單機版範圍內的資料）。
 */
class SiteFooterLicenseTest extends TestCase {
    private const TERMS_URL = 'https://cbdb.hsites.harvard.edu/cbdb-data-licensing-terms';

    public static function footers(): array {
        return [
            'React DashboardLayout' => ['resources/js/inertia/Layouts/DashboardLayout.tsx', '/<footer\b.*?<\/footer>/s'],
            'v1 API 人物頁' => ['resources/views/cbdbapi/person.blade.php', '/<div class="footer-section">.*?<\/div>/s'],
            '靜態 API 說明頁' => ['public/cbdbapi/index.html', '/<div class="footer-section">.*?<\/div>/s'],
        ];
    }

    #[Test]
    #[DataProvider('footers')]
    public function footer_links_to_the_cbdb_license(string $path, string $footerPattern): void {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertSame(1, preg_match($footerPattern, $source, $m), "{$path} 找不到頁尾區塊");
        $footer = $m[0];

        $this->assertStringContainsString('Except where otherwise noted, content on this site is licensed under a', $footer);
        $this->assertStringContainsString('href="'.self::TERMS_URL.'"', $footer);
        $this->assertMatchesRegularExpression('/>\s*CBDB License\s*<\/a>/', $footer);
        $this->assertStringNotContainsString('creativecommons.org', $footer);
    }
}
