<?php

namespace Tests\Unit;

use App\Repositories\BiogMainRepository;
use App\Services\PinyinDictionary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 測試 BiogMainRepository::auto_pinyin() 的姓名拼音自動生成邏輯。
 *
 * 覆蓋場景：
 *   1. 有姓氏：姓＋名正確拆分，c_name 以空格連結
 *   2. 無姓氏：c_name 不應包含前導空格，c_surname / c_surname_chn 應為空字串
 *   3. 單字名（即姓佔整個名字，名為空）
 */
class AutoPinyinTest extends TestCase {
    private BiogMainRepository $repository;

    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 建立最小化的 pinyin 表供姓氏查詢
        Schema::dropIfExists('pinyin');
        Schema::create('pinyin', function (Blueprint $table) {
            $table->string('c_chn');
            $table->string('c_pinyin');
            $table->tinyInteger('c_lastname')->default(0);
            $table->unique(['c_chn', 'c_lastname']);
        });

        // 插入測試姓氏（c_lastname=1）。名字部分的一般轉換若查無 c_lastname=0
        // 資料，會退回同一字的 c_lastname=1 讀音（見 PinyinDictionary 的優先序設計），
        // 這也是 testRepeatedCharacterNameKeepsGivenNameAfterSurnameSplit 能通過的原因。
        DB::table('pinyin')->insert([
            ['c_chn' => '王', 'c_pinyin' => 'Wang', 'c_lastname' => 1],
            ['c_chn' => '歐陽', 'c_pinyin' => 'Ouyang', 'c_lastname' => 1],
            ['c_chn' => '林', 'c_pinyin' => 'Lin', 'c_lastname' => 1],
            ['c_chn' => '於', 'c_pinyin' => 'Yu', 'c_lastname' => 1],
        ]);

        PinyinDictionary::reset();

        $this->repository = new BiogMainRepository();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('pinyin');
        PinyinDictionary::reset();
        parent::tearDown();
    }

    #[Test]
    public function testWithSurnameSplitsCorrectly(): void {
        $result = $this->repository->auto_pinyin(['c_name_chn' => '王安石']);

        $this->assertSame('王', $result['c_surname_chn']);
        $this->assertSame('Wang', $result['c_surname']);
        $this->assertSame('安石', $result['c_mingzi_chn']);
        $this->assertNotEmpty($result['c_mingzi']);
        $this->assertSame('Wang ' . $result['c_mingzi'], $result['c_name']);
    }

    #[Test]
    public function testWithoutSurnameNoLeadingSpace(): void {
        // 使用一個不在 pinyin 表中的名字，確保走「無姓氏」分支
        $result = $this->repository->auto_pinyin(['c_name_chn' => '某僧']);

        // 核心斷言：c_name 不應有前導空格
        $this->assertStringNotContainsString(' ', $result['c_name'], 'c_name 不應包含空格');
        $this->assertSame($result['c_mingzi'], $result['c_name']);
        $this->assertSame('', $result['c_surname_chn']);
        $this->assertSame('', $result['c_surname']);
        $this->assertSame('某僧', $result['c_mingzi_chn']);
    }

    #[Test]
    public function testCompoundSurname(): void {
        $result = $this->repository->auto_pinyin(['c_name_chn' => '歐陽修']);

        $this->assertSame('歐陽', $result['c_surname_chn']);
        $this->assertSame('Ouyang', $result['c_surname']);
        $this->assertSame('修', $result['c_mingzi_chn']);
        $this->assertSame('Ouyang ' . $result['c_mingzi'], $result['c_name']);
    }

    #[Test]
    public function testSingleCharNameMatchesSurname(): void {
        // 單字名恰好是姓氏 → 姓氏匹配成功，名為空字串
        $result = $this->repository->auto_pinyin(['c_name_chn' => '王']);

        $this->assertSame('王', $result['c_surname_chn']);
        $this->assertSame('Wang', $result['c_surname']);
        $this->assertSame('', $result['c_mingzi_chn']);
        $this->assertSame('Wang', $result['c_name']);
    }

    #[Test]
    public function testGivenNameGetsApostropheBeforeAOESyllable(): void {
        // 名字連寫依正詞法插隔音符：長安 → Chang'an（讀音來自 opencc-pinyin 靜態字典）。
        $result = $this->repository->auto_pinyin(['c_name_chn' => '王長安']);

        $this->assertSame('王', $result['c_surname_chn']);
        $this->assertSame('長安', $result['c_mingzi_chn']);
        $this->assertSame("Chang'an", $result['c_mingzi']);
        $this->assertSame("Wang Chang'an", $result['c_name']);
    }

    #[Test]
    public function testKnownSurnameStillMatchesWhenGivenNameLongerThanTwoChars(): void {
        $result = $this->repository->auto_pinyin(['c_name_chn' => '王安石傳']);

        $this->assertSame('王', $result['c_surname_chn']);
        $this->assertSame('Wang', $result['c_surname']);
        $this->assertSame('安石傳', $result['c_mingzi_chn']);
        $this->assertSame('Wang ' . $result['c_mingzi'], $result['c_name']);
    }

    #[Test]
    public function testRepeatedCharacterNameKeepsGivenNameAfterSurnameSplit(): void {
        $result = $this->repository->auto_pinyin(['c_name_chn' => '林林']);

        $this->assertSame('林', $result['c_surname_chn']);
        $this->assertSame('Lin', $result['c_surname']);
        $this->assertSame('林', $result['c_mingzi_chn']);
        $this->assertSame('Lin Lin', $result['c_name']);
    }

    #[Test]
    public function testVariantSurnameCanMatchNormalizedKnownSurname(): void {
        $result = $this->repository->auto_pinyin(['c_name_chn' => '于慎行']);

        $this->assertSame('于', $result['c_surname_chn']);
        $this->assertSame('Yu', $result['c_surname']);
        $this->assertSame('慎行', $result['c_mingzi_chn']);
        $this->assertStringStartsWith('Yu ', $result['c_name']);
    }
}
