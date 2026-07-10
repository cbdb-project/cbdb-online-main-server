<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsPinyinDictionary;
use Tests\TestCase;

class ApiSearchPinyinTest extends TestCase {
    use SeedsPinyinDictionary;

    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::dropIfExists('pinyin');
        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn');
            $table->string('c_pinyin')->nullable();
            $table->tinyInteger('c_lastname')->default(0);
            $table->unique(['c_chn', 'c_lastname']);
        });

        // 一般轉換路徑（split=0、姓氏拆分後的名字部分）需要真實字典資料，
        // 才能跟現行 Pinyin::$dic 的行為一致（見 docs/PINYIN_TABLE_CONSOLIDATION_PLAN.md 步驟4）。
        $this->seedPinyinDictionary();

        // 親屬關係守衛（person_id）測試用最小表：KIN_DATA + BIOG_MAIN（僅需 c_name_chn 供姓名比對）。
        Schema::dropIfExists('KIN_DATA');
        Schema::create('KIN_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id');
            $table->smallInteger('c_kin_code')->default(0);
        });
        Schema::dropIfExists('BIOG_MAIN');
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('KIN_DATA');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('pinyin');
        parent::tearDown();
    }

    #[Test]
    public function search_pinyin_converts_fullwidth_parentheses_and_keeps_space_before_parenthesis(): void {
        $response = $this->get('/api/select/search/pinyin?q=宗氏（李白妻）');

        $response->assertOk();
        $content = trim($response->getContent());

        $this->assertStringContainsString('(Wife of', $content);
        $this->assertStringNotContainsString('（', $content);
        $this->assertStringNotContainsString('）', $content);
        $this->assertStringContainsString(' (', $content);
    }

    #[Test]
    public function search_pinyin_converts_wife_pattern_to_wife_of_english_phrase(): void {
        DB::table('pinyin')->insert([
            'c_chn' => '李',
            'c_pinyin' => 'Li', 'c_lastname' => 1,
        ]);

        $response = $this->get('/api/select/search/pinyin?q=（李白妻）');

        $response->assertOk();
        $content = trim($response->getContent());
        $this->assertStringStartsWith('(Wife of ', $content);
        $this->assertStringEndsWith(')', $content);
        $this->assertStringNotContainsString('妻', $content);
    }

    #[Test]
    public function search_pinyin_matches_known_surname_using_same_logic_as_auto_pinyin(): void {
        DB::table('pinyin')->insert([
            'c_chn' => '王',
            'c_pinyin' => 'Wang', 'c_lastname' => 1,
        ]);

        $response = $this->get('/api/select/search/pinyin?q=王安石傳');

        $response->assertOk();
        $content = trim($response->getContent());
        $this->assertStringStartsWith('Wang ', $content);
    }

    #[Test]
    public function search_pinyin_converts_supported_relationship_patterns_to_english_phrases(): void {
        DB::table('pinyin')->insert([
            ['c_chn' => '李', 'c_pinyin' => 'Li', 'c_lastname' => 1],
            ['c_chn' => '王', 'c_pinyin' => 'Wang', 'c_lastname' => 1],
        ]);

        $cases = [
            '（李白母）' => 'Mother of Li Bai',
            '（王安石女）' => 'Daughter of Wang Anshi',
            '（李白妾）' => 'Concubine of Li Bai',
            '（王安石媳）' => 'Daughter-in-law of Wang Anshi',
            '（李白妹）' => 'Younger Sister of Li Bai',
            '（王安石姐）' => 'Elder Sister of Wang Anshi',
            '（李白姊）' => 'Elder Sister of Li Bai',
        ];

        foreach ($cases as $query => $expectedPhrase) {
            $response = $this->get('/api/select/search/pinyin?q='.urlencode($query));

            $response->assertOk();
            $content = trim($response->getContent());

            $this->assertSame('('.$expectedPhrase.')', $content);
        }
    }

    #[Test]
    public function search_pinyin_converts_expanded_relationship_patterns_to_english_phrases(): void {
        DB::table('pinyin')->insert([
            ['c_chn' => '李', 'c_pinyin' => 'Li', 'c_lastname' => 1],
            ['c_chn' => '王', 'c_pinyin' => 'Wang', 'c_lastname' => 1],
        ]);

        $cases = [
            '（李白夫）' => 'Husband of Li Bai',
            '（李白父）' => 'Father of Li Bai',
            '（王安石兄）' => 'Elder Brother of Wang Anshi',
            '（李白弟）' => 'Younger Brother of Li Bai',
            '（李白婿）' => 'Son-in-law of Li Bai',
            '（王安石嫂）' => 'Sister-in-law of Wang Anshi',
        ];

        foreach ($cases as $query => $expectedPhrase) {
            $response = $this->get('/api/select/search/pinyin?q='.urlencode($query));
            $response->assertOk();
            $this->assertSame('('.$expectedPhrase.')', trim($response->getContent()));
        }
    }

    #[Test]
    public function search_pinyin_prefers_multi_char_titles_over_single_char_suffix(): void {
        DB::table('pinyin')->insert([
            ['c_chn' => '李', 'c_pinyin' => 'Li', 'c_lastname' => 1],
            ['c_chn' => '王', 'c_pinyin' => 'Wang', 'c_lastname' => 1],
            ['c_chn' => '宗', 'c_pinyin' => 'Zong', 'c_lastname' => 1],
        ]);

        // 多字稱謂不可被其單字後綴（母/父/女）誤切：祖母≠母、祖父≠父、孫女≠女。
        $cases = [
            '（李白祖母）' => '(Grandmother of Li Bai)',
            '（王安石祖父）' => '(Grandfather of Wang Anshi)',
            '（李白孫女）' => '(Granddaughter of Li Bai)',
            // 前綴形（特例 2）同樣須正確消歧
            '宗氏（李白祖母）' => 'Zong Shi (Grandmother of Li Bai)',
        ];

        foreach ($cases as $query => $expected) {
            $response = $this->get('/api/select/search/pinyin?q='.urlencode($query));
            $response->assertOk();
            $this->assertSame($expected, trim($response->getContent()));
        }
    }

    #[Test]
    public function search_pinyin_does_not_treat_non_relationship_parenthetical_as_relationship(): void {
        DB::table('pinyin')->insert([
            ['c_chn' => '李', 'c_pinyin' => 'Li', 'c_lastname' => 1],
            ['c_chn' => '公', 'c_pinyin' => 'Gong', 'c_lastname' => 1],
        ]);

        // 「子」「孫」非稱謂（刻意未收）：「（李子）」「（公孫）」這類括號別名/詞不應被誤判為關係稱謂。
        foreach (['（李子）', '（公孫）'] as $query) {
            $response = $this->get('/api/select/search/pinyin?q='.urlencode($query));
            $response->assertOk();
            $content = trim($response->getContent());
            $this->assertStringNotContainsString(' of ', $content);
        }
    }

    #[Test]
    public function search_pinyin_with_split_zero_does_not_split_by_surname(): void {
        DB::table('pinyin')->insert([
            'c_chn' => '安',
            'c_pinyin' => 'An', 'c_lastname' => 1,
        ]);

        // 預設 split=1 會在已知姓氏後插入空格
        $response = $this->get('/api/select/search/pinyin?q='.urlencode('安石'));
        $response->assertOk();
        $this->assertSame('An Shi', trim($response->getContent()));

        // split=0 不拆分姓氏，整體視為一個詞轉換
        $response = $this->get('/api/select/search/pinyin?q='.urlencode('安石').'&split=0');
        $response->assertOk();
        $this->assertSame('Anshi', trim($response->getContent()));
    }

    #[Test]
    public function search_pinyin_capitalizes_first_letter_after_left_parenthesis(): void {
        // 人名含括號（非關係稱謂，如別名/字號）時，左括號後首字母應大寫，
        // 與人名各段首字母大寫慣例一致。split=0 模擬基本資料編輯器「名」欄的生成拼音。
        $response = $this->get('/api/select/search/pinyin?q='.urlencode('李白（青蓮）').'&split=0');

        $response->assertOk();
        $content = trim($response->getContent());

        $this->assertSame('Libai (Qinglian)', $content);
        $this->assertStringNotContainsString('（', $content);
        $this->assertStringNotContainsString('）', $content);
    }

    #[Test]
    public function search_pinyin_keeps_relationship_phrase_capitalization_unchanged(): void {
        DB::table('pinyin')->insert([
            'c_chn' => '李',
            'c_pinyin' => 'Li', 'c_lastname' => 1,
        ]);

        // 關係片語括號內已為大寫英文（(Wife of ...)），大寫化不得誤傷或重覆處理。
        $response = $this->get('/api/select/search/pinyin?q='.urlencode('（李白妻）'));

        $response->assertOk();
        $this->assertSame('(Wife of Li Bai)', trim($response->getContent()));
    }

    #[Test]
    public function search_pinyin_converts_prefixed_relationship_patterns_to_english_phrases(): void {
        DB::table('pinyin')->insert([
            ['c_chn' => '宗', 'c_pinyin' => 'Zong', 'c_lastname' => 1],
            ['c_chn' => '李', 'c_pinyin' => 'Li', 'c_lastname' => 1],
        ]);

        $response = $this->get('/api/select/search/pinyin?q='.urlencode('宗氏（李白母）'));

        $response->assertOk();
        $content = trim($response->getContent());

        $this->assertSame('Zong Shi (Mother of Li Bai)', $content);
    }

    #[Test]
    public function search_pinyin_applies_relationship_when_target_is_a_kin(): void {
        DB::table('pinyin')->insert(['c_chn' => '李', 'c_pinyin' => 'Li', 'c_lastname' => 1]);
        // 100 的親屬名單中確有「李白」（id 200）→ 關係守衛通過，維持關係轉換。
        DB::table('BIOG_MAIN')->insert(['c_personid' => 200, 'c_name_chn' => '李白']);
        DB::table('KIN_DATA')->insert(['c_personid' => 100, 'c_kin_id' => 200, 'c_kin_code' => 1]);

        $response = $this->get('/api/select/search/pinyin?q='.urlencode('（李白妻）').'&person_id=100');

        $response->assertOk();
        $this->assertSame('(Wife of Li Bai)', trim($response->getContent()));
        $response->assertHeaderMissing('X-Pinyin-Kinship-Unmatched');
    }

    #[Test]
    public function search_pinyin_uses_matched_kin_stored_english_name_instead_of_blind_pinyin(): void {
        // 「劉」易被姓氏偵測誤判，若對「劉汝彬」盲轉拼音會產生「Liurubin」（未拆分姓名）。
        // 親屬守衛已確認括號內之人是本人親屬，且該親屬存檔中已有正確英文姓名「Liu Rubin」，
        // 應直接沿用，而非重新盲轉拼音。
        DB::table('BIOG_MAIN')->insert(['c_personid' => 200, 'c_name_chn' => '劉汝彬', 'c_name' => 'Liu Rubin']);
        DB::table('KIN_DATA')->insert(['c_personid' => 100, 'c_kin_id' => 200, 'c_kin_code' => 1]);

        $response = $this->get('/api/select/search/pinyin?q='.urlencode('氏（劉汝彬妻）').'&person_id=100');

        $response->assertOk();
        $content = trim($response->getContent());

        $this->assertStringContainsString('Wife of Liu Rubin', $content);
        $this->assertStringNotContainsString('Liurubin', $content);
        $response->assertHeaderMissing('X-Pinyin-Kinship-Unmatched');
    }

    #[Test]
    public function search_pinyin_falls_back_to_pinyin_when_multiple_kin_share_name_with_different_c_name(): void {
        // 同一人親屬名單中有兩筆中文姓名皆為「劉汝彬」但存檔英文姓名不同 → 無法確定該用哪一筆，
        // 應退回一般拼音轉換（deterministic），而非任意挑選其中一筆（依資料庫回傳順序不穩定）。
        DB::table('BIOG_MAIN')->insert(['c_personid' => 200, 'c_name_chn' => '劉汝彬', 'c_name' => 'Liu Rubin']);
        DB::table('BIOG_MAIN')->insert(['c_personid' => 201, 'c_name_chn' => '劉汝彬', 'c_name' => 'Liu Rubin (II)']);
        DB::table('KIN_DATA')->insert(['c_personid' => 100, 'c_kin_id' => 200, 'c_kin_code' => 1]);
        DB::table('KIN_DATA')->insert(['c_personid' => 100, 'c_kin_id' => 201, 'c_kin_code' => 1]);

        $response = $this->get('/api/select/search/pinyin?q='.urlencode('氏（劉汝彬妻）').'&person_id=100');

        $response->assertOk();
        $content = trim($response->getContent());

        $this->assertStringNotContainsString('Liu Rubin', $content);
        $response->assertHeaderMissing('X-Pinyin-Kinship-Unmatched');
    }

    #[Test]
    public function search_pinyin_falls_back_to_pinyin_when_one_of_multiple_same_name_kin_has_no_c_name(): void {
        // 同一人親屬名單中有兩筆中文姓名皆為「劉汝彬」，其中一筆有存檔英文姓名、另一筆為空 →
        // 仍屬「不確定該用哪一筆」的歧義情境，不可誤用那筆非空值，應退回一般拼音轉換。
        DB::table('BIOG_MAIN')->insert(['c_personid' => 200, 'c_name_chn' => '劉汝彬', 'c_name' => 'Liu Rubin']);
        DB::table('BIOG_MAIN')->insert(['c_personid' => 201, 'c_name_chn' => '劉汝彬', 'c_name' => null]);
        DB::table('KIN_DATA')->insert(['c_personid' => 100, 'c_kin_id' => 200, 'c_kin_code' => 1]);
        DB::table('KIN_DATA')->insert(['c_personid' => 100, 'c_kin_id' => 201, 'c_kin_code' => 1]);

        $response = $this->get('/api/select/search/pinyin?q='.urlencode('氏（劉汝彬妻）').'&person_id=100');

        $response->assertOk();
        $content = trim($response->getContent());

        $this->assertStringNotContainsString('Liu Rubin', $content);
        $response->assertHeaderMissing('X-Pinyin-Kinship-Unmatched');
    }

    #[Test]
    public function search_pinyin_falls_back_to_general_when_target_not_a_kin(): void {
        // 100 的親屬只有「李白」；括號內「靖江」並非其親屬 → 判為別名，
        // 退回一般拼音轉換（不出現「Daughter of」），並帶非阻塞提示標頭。
        DB::table('BIOG_MAIN')->insert(['c_personid' => 200, 'c_name_chn' => '李白']);
        DB::table('KIN_DATA')->insert(['c_personid' => 100, 'c_kin_id' => 200, 'c_kin_code' => 1]);

        $response = $this->get('/api/select/search/pinyin?q='.urlencode('張氏（靖江女）').'&person_id=100');

        $response->assertOk();
        $content = trim($response->getContent());
        $this->assertStringNotContainsString(' of ', $content);          // 未套用「Daughter of」
        $this->assertStringNotContainsString('女', $content);            // 已轉為拼音
        $response->assertHeader('X-Pinyin-Kinship-Unmatched', '1');
    }

    #[Test]
    public function search_pinyin_without_person_id_keeps_relationship_conversion(): void {
        // 向後相容：未帶 person_id 時不做親屬守衛，維持既有關係轉換、且不帶提示標頭。
        DB::table('pinyin')->insert(['c_chn' => '李', 'c_pinyin' => 'Li', 'c_lastname' => 1]);

        $response = $this->get('/api/select/search/pinyin?q='.urlencode('（李白妻）'));

        $response->assertOk();
        $this->assertSame('(Wife of Li Bai)', trim($response->getContent()));
        $response->assertHeaderMissing('X-Pinyin-Kinship-Unmatched');
    }
}
