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
    public function it_plans_biog_regenerate_with_oracle_and_drift(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '呂坤', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],   // 待遷移
            ['c_personid' => 2, 'c_name_chn' => '呂坤', 'c_surname' => 'Lü', 'c_mingzi' => 'Kun', 'c_name' => 'Lü Kun'],   // 已遷移
            ['c_personid' => 3, 'c_name_chn' => '王安', 'c_surname' => 'Wang', 'c_mingzi' => 'An', 'c_name' => 'Wang An'], // 漂移（現值既非 wrong 也非 correct）
            ['c_personid' => 4, 'c_name_chn' => '呂坤', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],   // oracle 不過
        ]);

        // 重生：呂坤→Lü Kun（正確）；person 4 用另一中文名，重生給錯值以觸發 oracle 不過。
        $this->regenMap['呂坤'] = ['c_surname' => 'Lü', 'c_mingzi' => 'Kun', 'c_name' => 'Lü Kun'];
        $this->regenMap['歪坤'] = ['c_surname' => 'Xx', 'c_mingzi' => 'Kun', 'c_name' => 'Xx Kun'];
        DB::table('BIOG_MAIN')->where('c_personid', 4)->update(['c_name_chn' => '歪坤']);

        $rows = [
            ['id' => 1, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 2, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 3, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 4, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 99, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'], // 查無此人
        ];

        $plan = $this->planner()->planBiogMain($rows);

        // person 1：預定變更，changes 只送 c_surname，值取自重生。
        $this->assertCount(1, $plan['mutations']);
        $mut = $plan['mutations'][0];
        $this->assertSame(['c_personid' => 1], $mut['pk']);
        $this->assertSame('basicinformation', $mut['resource']);
        $this->assertSame(['c_surname' => 'Lü'], $mut['changes']);
        $this->assertSame('Lü Kun', $mut['preview']['to']);

        // person 2：已遷移
        $this->assertSame([2], array_column($plan['alreadyDone'], 'id'));
        // person 3：漂移跳過
        $this->assertSame(3, $plan['skipped'][0]['id']);
        $this->assertSame('drift', $plan['skipped'][0]['reason']);
        // person 4 oracle 不過 + person 99 查無此人 → 兩個例外
        $reasons = array_column($plan['exceptions'], 'reason');
        $this->assertContains('regenerate-mismatch', $reasons);
        $this->assertContains('person-not-found', $reasons);
    }

    #[Test]
    public function it_sends_both_components_when_c_name_row_present_else_respects_scoping(): void {
        DB::table('BIOG_MAIN')->insert([
            // person 5：surname 列 + c_name 列、無 mingzi 列；mingzi 仍殘留 v → 必須兩分量一併送出。
            ['c_personid' => 5, 'c_name_chn' => '呂綠', 'c_surname' => 'Lv', 'c_mingzi' => 'Lv', 'c_name' => 'Lv Lv'],
            // person 6：只有 surname 列、無 c_name 列；mingzi 無 v → 只送 surname，尊重 scoping。
            ['c_personid' => 6, 'c_name_chn' => '呂坤', 'c_surname' => 'Lv', 'c_mingzi' => 'Kun', 'c_name' => 'Lv Kun'],
        ]);
        $this->regenMap['呂綠'] = ['c_surname' => 'Lü', 'c_mingzi' => 'Lü', 'c_name' => 'Lü Lü'];
        $this->regenMap['呂坤'] = ['c_surname' => 'Lü', 'c_mingzi' => 'Kun', 'c_name' => 'Lü Kun'];

        $plan = $this->planner()->planBiogMain([
            ['id' => 5, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
            ['id' => 5, 'field' => 'c_name', 'wrong_pinyin' => 'Lv Lv', 'correct_pinyin' => 'Lü Lü'],
            ['id' => 6, 'field' => 'c_surname', 'wrong_pinyin' => 'Lv', 'correct_pinyin' => 'Lü'],
        ]);

        $byId = [];
        foreach ($plan['mutations'] as $m) {
            $byId[$m['pk']['c_personid']] = $m;
        }

        // person 5：有 c_name 列 → 兩分量都送，重算 c_name 才會等於已驗證的 correct。
        $this->assertSame(['c_surname' => 'Lü', 'c_mingzi' => 'Lü'], $byId[5]['changes']);
        // person 6：無 c_name 列 → 只送標記的 surname。
        $this->assertSame(['c_surname' => 'Lü'], $byId[6]['changes']);
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
