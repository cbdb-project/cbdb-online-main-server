<?php

namespace Tests\Unit;

use App\Support\TradSimpMap;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TradSimpMapTest extends TestCase {
    protected function createFixture(string $content): string {
        $path = tempnam(sys_get_temp_dir(), 'test_opencc_');
        file_put_contents($path, $content);

        return $path;
    }

    protected function tearDown(): void {
        TradSimpMap::reset();

        parent::tearDown();
    }

    #[Test]
    public function it_skips_comment_and_blank_lines(): void {
        $path = $this->createFixture(<<<'TXT'
# Open Chinese Convert (OpenCC) Dictionary
# Format: key	value(s) (values separated by spaces)

乾	干
幹	干

TXT);

        try {
            $this->assertEquals(['乾' => '干', '幹' => '干'], TradSimpMap::parseFile($path));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function it_handles_inline_comments(): void {
        $path = $this->createFixture("乾\t干 # 這是行內註釋\n幹\t干\t# 另一個註釋\n");

        try {
            $this->assertEquals(['乾' => '干', '幹' => '干'], TradSimpMap::parseFile($path));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function it_keeps_only_the_first_candidate_when_multiple_simplified_forms_exist(): void {
        $path = $this->createFixture("乾\t干 乾\n");

        try {
            $this->assertEquals(['乾' => '干'], TradSimpMap::parseFile($path));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function it_drops_identity_mappings(): void {
        // OpenCC 對「罕見簡化字缺字型（tofu risk）」的字保留原字作為第一候選，
        // 此時 trad === simp（同形），一律排除。
        $path = $this->createFixture("乾\t干\n㑮\t㑮 𫝈\n");

        try {
            $this->assertEquals(['乾' => '干'], TradSimpMap::parseFile($path));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function it_returns_empty_array_for_missing_file(): void {
        $this->assertEquals([], TradSimpMap::parseFile('/nonexistent/path/does-not-exist.txt'));
    }

    #[Test]
    public function base_map_reads_the_real_vendored_source_file(): void {
        TradSimpMap::reset();

        $map = TradSimpMap::baseMap();

        $this->assertGreaterThan(3000, count($map));
        $this->assertEquals('干', $map['乾'] ?? null);
        $this->assertArrayNotHasKey('㑮', $map);
    }

    #[Test]
    public function full_applies_manual_overrides_on_top_of_the_vendored_source(): void {
        TradSimpMap::reset();
        config(['trad_simp_manual_overrides' => ['栢' => '柏']]);

        $map = TradSimpMap::full();

        $this->assertEquals('柏', $map['栢'] ?? null);
        $this->assertEquals('干', $map['乾'] ?? null);
    }
}
