<?php

namespace Tests\Feature;

use App\Services\Pinyin\PinyinMigrationPlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 人名拼音 v→ü 遷移規劃器：重生 + Sheet oracle + 寫入前漂移檢查（§D-3/§D-5）。
 *
 * 規劃器只計算不寫入；regenerator 以 stub 注入，避免依賴 pinyin 表／拼音庫。
 */
class PinyinMigrationPlannerTest extends TestCase {
    /** @var array<string, array{c_surname:string,c_mingzi:string,c_name:string}> */
    private array $regenMap = [];

    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        Schema::create('BIOG_MAIN', function ($t) {
            $t->integer('c_personid')->primary();
            $t->string('c_name_chn')->nullable();
            $t->string('c_surname')->nullable();
            $t->string('c_mingzi')->nullable();
            $t->string('c_name')->nullable();
        });
        Schema::create('ALTNAME_DATA', function ($t) {
            $t->integer('c_personid');
            $t->string('c_alt_name_chn')->nullable();
            $t->integer('c_alt_name_type_code')->default(0);
            $t->string('c_alt_name')->nullable();
        });
    }

    private function planner(): PinyinMigrationPlanner {
        return new PinyinMigrationPlanner(function (string $chn): array {
            return $this->regenMap[$chn] ?? ['c_surname' => '', 'c_mingzi' => '', 'c_name' => ''];
        });
    }

    #[Test]
    public function it_uses_sheet_correct_with_drift_and_confidence(): void {
        DB::table('BIOG_MAIN')->insert([
            // person 1：surname 行 + c_name 行 → 由完整名推導 mingzi；regen 一致 → high。
            ['c_personid' => 1, 'c_name_chn' => '呂坤', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],
            // person 2：已遷移
            ['c_personid' => 2, 'c_name_chn' => '呂坤', 'c_surname' => 'Lü', 'c_mingzi' => 'Kun', 'c_name' => 'Lü Kun'],
            // person 3：漂移（現值既非 wrong 也非 correct）
            ['c_personid' => 3, 'c_name_chn' => '王安', 'c_surname' => 'Wang', 'c_mingzi' => 'An', 'c_name' => 'Wang An'],
            // person 4：生僻字 regen 對不上 Sheet → 仍寫 Sheet 值、confidence=low。
            ['c_personid' => 4, 'c_name_chn' => '呂搢', 'c_surname' => 'Lv', 'c_mingzi' => 'Jin', 'c_name' => 'Lv Jin'],
        ]);
        $this->regenMap['呂坤'] = ['c_surname' => 'Lü', 'c_mingzi' => 'Kun', 'c_name' => 'Lü Kun'];
        $this->regenMap['呂搢'] = ['c_surname' => 'Lü', 'c_mingzi' => '搢', 'c_name' => 'Lü 搢']; // auto_pinyin 轉不出生僻字

        $rows = [
            ['id' => 1, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 1, 'field' => 'c_name', 'wrong_pinyin' => 'Lv Kun', 'correct_pinyin' => 'Lü Kun'],
            ['id' => 2, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 3, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 4, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 4, 'field' => 'c_name', 'wrong_pinyin' => 'Lv Jin', 'correct_pinyin' => 'Lü Jin'],
            ['id' => 99, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'], // 查無此人
        ];

        $plan = $this->planner()->planBiogMain($rows);
        $byId = [];
        foreach ($plan['mutations'] as $m) {
            $byId[$m['pk']['c_personid']] = $m;
        }

        // person 1：Sheet surname 'Lü' + 由完整名 'Lü Kun' 推導 mingzi 'Kun'；regen 一致 → high。
        $this->assertSame(['c_surname' => 'Lü', 'c_mingzi' => 'Kun'], $byId[1]['changes']);
        $this->assertSame('high', $byId[1]['confidence']);
        // person 4：regen('Lü 搢') != 最終('Lü Jin') → 仍寫 Sheet 值、low。
        $this->assertSame(['c_surname' => 'Lü', 'c_mingzi' => 'Jin'], $byId[4]['changes']);
        $this->assertSame('low', $byId[4]['confidence']);

        // person 2 已遷移、person 3 漂移、person 99 查無此人
        $this->assertSame([2], array_column($plan['alreadyDone'], 'id'));
        $this->assertSame('drift', $plan['skipped'][0]['reason']);
        $this->assertSame('person-not-found', $plan['exceptions'][0]['reason']);
    }

    #[Test]
    public function it_derives_missing_component_and_flags_orphan(): void {
        DB::table('BIOG_MAIN')->insert([
            // person 5：mingzi 行 + c_name 行（「之妻」注釋類）→ 由完整名扣尾推導 surname 'Lü'。
            ['c_personid' => 5, 'c_name_chn' => '呂氏', 'c_surname' => 'Lv', 'c_mingzi' => 'Shi(x)', 'c_name' => 'Lv Shi(x)'],
            // person 6：兩分量行都有 → 直接採用。
            ['c_personid' => 6, 'c_name_chn' => '呂坤', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],
            // person 7：只有 surname 行、無 c_name → 只改 surname，mingzi 維持現值。
            ['c_personid' => 7, 'c_name_chn' => '呂坤', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],
            // person 8：孤兒（只有 c_name 行）→ 例外。
            ['c_personid' => 8, 'c_name_chn' => '呂坤', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],
        ]);
        $this->regenMap['呂氏'] = ['c_surname' => 'Lü', 'c_mingzi' => 'Shi', 'c_name' => 'Lü Shi'];
        $this->regenMap['呂坤'] = ['c_surname' => 'Lü', 'c_mingzi' => 'Kun', 'c_name' => 'Lü Kun'];

        $plan = $this->planner()->planBiogMain([
            ['id' => 5, 'field' => 'c_mingzi', 'wrong_pinyin' => 'Shi(x)', 'correct_pinyin' => 'Shi (Wife of X )'],
            ['id' => 5, 'field' => 'c_name', 'wrong_pinyin' => 'Lv Shi(x)', 'correct_pinyin' => 'Lü Shi (Wife of X )'],
            ['id' => 6, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 6, 'field' => 'c_mingzi', 'wrong_pinyin' => 'Kun', 'correct_pinyin' => 'Kun'],
            ['id' => 7, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 8, 'field' => 'c_name', 'wrong_pinyin' => 'Lv Kun', 'correct_pinyin' => 'Lü Kun'],
        ]);
        $byId = [];
        foreach ($plan['mutations'] as $m) {
            $byId[$m['pk']['c_personid']] = $m;
        }

        // person 5：由 'Lü Shi (Wife of X )' 扣尾 mingzi → surname 'Lü'，兩分量送出。
        $this->assertSame(['c_surname' => 'Lü', 'c_mingzi' => 'Shi (Wife of X )'], $byId[5]['changes']);
        // person 6：兩分量行 → 直接採用。
        $this->assertSame(['c_surname' => 'Lü', 'c_mingzi' => 'Kun'], $byId[6]['changes']);
        // person 7：只 surname 行 → 只改 surname。
        $this->assertSame(['c_surname' => 'Lü'], $byId[7]['changes']);
        // person 8：孤兒（單姓）→ 用現庫姓的詞數自動拆（原 §D-4 approach A）。
        $this->assertSame(['c_surname' => 'Lü', 'c_mingzi' => 'Kun'], $byId[8]['changes']);
    }

    #[Test]
    public function it_splits_orphan_by_sheet_first_space(): void {
        DB::table('BIOG_MAIN')->insert([
            // 30：現庫姓髒（Lu9＝Lü）、c_name 也髒 → 姓取自 Sheet correct（第一空格前），不信現庫拼寫
            ['c_personid' => 30, 'c_name_chn' => '呂建中', 'c_surname' => 'Lu9', 'c_mingzi' => 'Jianzhong', 'c_name' => 'Lv Jianzhong'],
            // 31：現庫無姓（描述性）→ 整名為 mingzi
            ['c_personid' => 31, 'c_name_chn' => '女子', 'c_surname' => '', 'c_mingzi' => 'nv zi', 'c_name' => 'nv zi'],
            // 32：完整名含括號（消歧）→ 保守交人工
            ['c_personid' => 32, 'c_name_chn' => '呂洵', 'c_surname' => 'Lv', 'c_mingzi' => 'Xun', 'c_name' => 'Lv Xun'],
            // 33：現庫姓含空格（帶空格複姓/描述性）→ 第一空格拆不可靠、交人工
            ['c_personid' => 33, 'c_name_chn' => '桃花石女', 'c_surname' => 'Tao hua', 'c_mingzi' => 'shi nv', 'c_name' => 'Tao hua shi nv'],
        ]);
        $this->regenMap['呂建中'] = ['c_surname' => 'Lü', 'c_mingzi' => 'Jianzhong', 'c_name' => 'Lü Jianzhong'];

        $plan = $this->planner()->planBiogMain([
            ['id' => 30, 'field' => 'c_name', 'wrong_pinyin' => 'Lv Jianzhong', 'correct_pinyin' => 'Lü Jianzhong'],
            ['id' => 31, 'field' => 'c_name', 'wrong_pinyin' => 'nv zi', 'correct_pinyin' => 'nü zi'],
            ['id' => 32, 'field' => 'c_name', 'wrong_pinyin' => 'Lv Xun', 'correct_pinyin' => 'Lü Xun (2)'],
            ['id' => 33, 'field' => 'c_name', 'wrong_pinyin' => 'Tao hua shi nv', 'correct_pinyin' => 'Tao hua shi nü'],
        ]);
        $byId = [];
        foreach ($plan['mutations'] as $m) {
            $byId[$m['pk']['c_personid']] = $m;
        }
        $reasons = [];
        foreach ($plan['exceptions'] as $e) {
            $reasons[$e['id']] = $e['reason'];
        }

        // 30：現庫髒姓也拆對——姓取自 Sheet correct 'Lü Jianzhong' 第一空格前
        $this->assertSame(['c_surname' => 'Lü', 'c_mingzi' => 'Jianzhong'], $byId[30]['changes']);
        // 31：無姓 → 只改 mingzi 為整名
        $this->assertSame(['c_mingzi' => 'nü zi'], $byId[31]['changes']);
        // 32：含括號 → 交人工（orphan-cname）
        $this->assertSame('orphan-cname', $reasons[32]);
        // 33：現庫姓含空格 → 交人工
        $this->assertSame('orphan-cname', $reasons[33]);
    }

    #[Test]
    public function it_flags_derived_empty_instead_of_blanking(): void {
        // trim(c_name) 後完整名其實等於 surname，但現庫仍有 mingzi 殘值 → 應視為 blocking 矛盾，不得靜默清空。
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 20, 'c_name_chn' => '呂', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],
        ]);
        $this->regenMap['呂'] = ['c_surname' => 'Lü', 'c_mingzi' => '', 'c_name' => 'Lü'];

        $plan = $this->planner()->planBiogMain([
            ['id' => 20, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 20, 'field' => 'c_name', 'wrong_pinyin' => 'Lv Kun', 'correct_pinyin' => 'Lü '],
        ]);

        $this->assertSame([], $plan['mutations'], '推導出空分量不得產生變更');
        $this->assertSame('name-is-surname-but-mingzi-present', $plan['exceptions'][0]['reason']);
    }

    #[Test]
    public function it_trims_c_name_before_name_matching_and_split(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 24, 'c_name_chn' => '呂坤', 'c_surname' => 'Lv', 'c_mingzi' => '', 'c_name' => 'Lv'],
            ['c_personid' => 25, 'c_name_chn' => '呂坤', 'c_surname' => '', 'c_mingzi' => 'Kun', 'c_name' => 'Kun'],
        ]);
        $this->regenMap['呂坤'] = ['c_surname' => 'Lü', 'c_mingzi' => 'Kun', 'c_name' => 'Lü Kun'];

        $plan = $this->planner()->planBiogMain([
            ['id' => 24, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 24, 'field' => 'c_name', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => '  Lü  '],
            ['id' => 25, 'field' => 'c_mingzi', 'wrong_pinyin' => 'Kun', 'correct_pinyin' => 'Kun'],
            ['id' => 25, 'field' => 'c_name', 'wrong_pinyin' => 'Kun', 'correct_pinyin' => '  Kun  '],
        ]);

        $byId = [];
        foreach ($plan['mutations'] as $m) {
            $byId[$m['pk']['c_personid']] = $m;
        }

        $this->assertSame(['c_surname' => 'Lü'], $byId[24]['changes']);
        $this->assertSame(['c_mingzi' => 'Kun'], $byId[25]['changes']);
    }

    #[Test]
    public function it_flags_conflicts_and_inconsistencies(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 21, 'c_name_chn' => '呂坤', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],
            ['c_personid' => 22, 'c_name_chn' => '呂', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],
            ['c_personid' => 23, 'c_name_chn' => '呂坤', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],
            ['c_personid' => 24, 'c_name_chn' => '呂坤', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],
        ]);
        $this->regenMap['呂坤'] = ['c_surname' => 'Lü', 'c_mingzi' => 'Kun', 'c_name' => 'Lü Kun'];
        $this->regenMap['呂'] = ['c_surname' => 'Lü', 'c_mingzi' => '', 'c_name' => 'Lü'];

        $plan = $this->planner()->planBiogMain([
            // 21：同一欄重複且值不同 → duplicate-field
            ['id' => 21, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 21, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lu'],
            // 22：完整名即姓、但現庫 mingzi 非空 → 矛盾
            ['id' => 22, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 22, 'field' => 'c_name', 'wrong_pinyin' => 'Lv Kun', 'correct_pinyin' => 'Lü'],
            // 23：兩分量+完整名，但 surname+' '+mingzi != 完整名 → sheet-inconsistent
            ['id' => 23, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 23, 'field' => 'c_mingzi', 'wrong_pinyin' => 'Kun', 'correct_pinyin' => 'Kun'],
            ['id' => 23, 'field' => 'c_name', 'wrong_pinyin' => 'Lv Kun', 'correct_pinyin' => 'Lü Wrong'],
            // 24：同一欄位 correct 相同但 wrong 不同，也要視為 duplicate-field
            ['id' => 24, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 24, 'field' => 'c_surname', 'wrong_pinyin' => 'Lyu', 'correct_pinyin' => 'Lü'],
        ]);

        $reasons = [];
        foreach ($plan['exceptions'] as $e) {
            $reasons[$e['id']] = $e['reason'];
        }
        $this->assertSame('duplicate-field', $reasons[21]);
        $this->assertSame('name-is-surname-but-mingzi-present', $reasons[22]);
        $this->assertSame('sheet-inconsistent', $reasons[23]);
        $this->assertSame('duplicate-field', $reasons[24]);
        $this->assertSame([], $plan['mutations'], '衝突/不一致不得產生變更');
    }

    #[Test]
    public function it_resolves_altname_pk_and_flags_ambiguity(): void {
        DB::table('ALTNAME_DATA')->insert([
            ['c_personid' => 10, 'c_alt_name_chn' => '呂胤', 'c_alt_name_type_code' => 4, 'c_alt_name' => 'Lv Yin'],   // 可定位
            ['c_personid' => 11, 'c_alt_name_chn' => '甲', 'c_alt_name_type_code' => 4, 'c_alt_name' => 'Lv X'],       // 歧義（同 personid+值 2 筆）
            ['c_personid' => 11, 'c_alt_name_chn' => '乙', 'c_alt_name_type_code' => 5, 'c_alt_name' => 'Lv X'],
            ['c_personid' => 12, 'c_alt_name_chn' => '丙', 'c_alt_name_type_code' => 4, 'c_alt_name' => 'Lü Z'],       // 已遷移
        ]);

        $rows = [
            ['id' => 10, 'wrong_pinyin' => 'Lv Yin', 'correct_pinyin' => 'Lü Yin'],
            ['id' => 11, 'wrong_pinyin' => 'Lv X', 'correct_pinyin' => 'Lü X'],
            ['id' => 12, 'wrong_pinyin' => 'Lv Z', 'correct_pinyin' => 'Lü Z'],   // 定位落空、但 correct 已存在
            ['id' => 13, 'wrong_pinyin' => 'Lv Q', 'correct_pinyin' => 'Lü Q'],   // 完全查無
        ];

        $plan = $this->planner()->planAltname($rows);

        // person 10：完整 3-key PK + changes
        $this->assertCount(1, $plan['mutations']);
        $mut = $plan['mutations'][0];
        $this->assertSame([
            'c_personid' => 10,
            'c_alt_name_chn' => '呂胤',
            'c_alt_name_type_code' => 4,
        ], $mut['pk']);
        $this->assertSame(['c_alt_name' => 'Lü Yin'], $mut['changes']);

        // person 11：歧義例外
        $this->assertSame('ambiguous', $plan['exceptions'][0]['reason']);
        $this->assertSame(11, $plan['exceptions'][0]['id']);
        // person 12：已遷移
        $this->assertSame([12], array_column($plan['alreadyDone'], 'id'));
        // person 13：落空跳過
        $this->assertSame(13, $plan['skipped'][0]['id']);
        $this->assertSame('not-found', $plan['skipped'][0]['reason']);
    }
}
