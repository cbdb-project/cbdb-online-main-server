<?php

namespace Tests\Feature;

use App\Console\Commands\RebuildNameSearchIndex;
use App\Services\NameSearchIndexService;
use App\Support\TradSimpMap;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * 驗證人工補充映射（config/trad_simp_manual_overrides.php）與基礎資料
 * （third_party/opencc/TSCharacters.txt，由 cbdb:sync-opencc-trad-simp 更新）
 * 在兩個實際消費端（增量索引服務／全量重建指令）都正確疊加套用。
 */
class TradSimpManualOverridesIntegrationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        TradSimpMap::reset();

        config(['trad_simp_manual_overrides' => [
            '栢' => '柏',
        ]]);
    }

    protected function tearDown(): void {
        TradSimpMap::reset();

        parent::tearDown();
    }

    protected function invokeProtected(object $object, string $method, array $args = []) {
        $reflection = new \ReflectionClass($object);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($object, ...$args);
    }

    #[Test]
    public function rebuild_command_applies_manual_overrides_on_top_of_base_map(): void {
        $command = new RebuildNameSearchIndex();
        // 直接 new 出來的 Command 沒有經過 Artisan::run()，$this->info()/$this->warn() 依賴的
        // output 尚未初始化，呼叫會噴 null pointer；測試裡手動補上，實際執行（Artisan::call()／
        // 排程）時 Laravel 本來就會自動設定，不受影響。
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));
        $this->invokeProtected($command, 'loadTradSimpMap');

        $this->assertEquals('柏', $this->invokeProtected($command, 'convertToSimplified', ['栢']));
        // 乾→干 屬於 vendored 基礎資料（third_party/opencc/TSCharacters.txt），非人工映射。
        $this->assertEquals('干', $this->invokeProtected($command, 'convertToSimplified', ['乾']));
    }

    #[Test]
    public function incremental_index_service_applies_manual_overrides_on_top_of_base_map(): void {
        $service = new NameSearchIndexService();

        $this->assertEquals('柏', $this->invokeProtected($service, 'convertToSimplified', ['栢']));
        $this->assertEquals('干', $this->invokeProtected($service, 'convertToSimplified', ['乾']));
    }
}
