<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P2-2 codes/show Inertia 變體（app.codes.show）測試。
 */
class CodesShowInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-codes-show-inertia';
        if (!is_dir($compiledViewPath)) {
            mkdir($compiledViewPath, 0777, true);
        }
        config(['view.compiled' => $compiledViewPath]);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config(['codes.tables' => ['TEST_CODES' => '測試代碼']]);
        config(['codes.per_page' => 20]);

        Schema::create('TEST_CODES', function ($table) {
            $table->integer('code_id');
            $table->string('description')->nullable();
        });
        DB::table('TEST_CODES')->insert([
            ['code_id' => 1, 'description' => 'alpha'],
            ['code_id' => 2, 'description' => 'beta'],
            ['code_id' => 3, 'description' => 'gamma'],
        ]);
    }

    #[Test]
    public function it_renders_show_component_with_table_data(): void {
        $this->get(route('app.codes.show', ['table_name' => 'TEST_CODES']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Show')
                ->where('table', 'TEST_CODES')
                ->has('thead')
                ->has('rows', 3)
                ->where('use_cursor', false)
                ->has('meta', fn (Assert $meta) => $meta->where('total', 3)->etc())
                ->has('urls')
                ->has('page_translations.codes'));
    }

    #[Test]
    public function it_applies_search_filter(): void {
        $this->get(route('app.codes.show', ['table_name' => 'TEST_CODES', 'search' => 'alpha']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('search', 'alpha'));
    }

    #[Test]
    public function it_applies_column_filter(): void {
        // §CODES_SORT_FILTER_AUTH_GATE：帶 filters 需已激活登入使用者，見該計畫文件 M2。
        $this->actingAs($this->activeUser());

        $this->get(route('app.codes.show', ['table_name' => 'TEST_CODES', 'filters' => ['description' => 'beta']]))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1));
    }

    private function activeUser(string $name = 'active', int $id = 21): User {
        $user = new User(['name' => $name, 'email' => $name.'@example.com', 'confirmation_token' => Str::random(32)]);
        $user->id = $id;
        $user->is_active = 1;

        return $user;
    }

    #[Test]
    public function it_injects_kinship_codes_up_down_diff_step_computed_column(): void {
        config(['codes.tables' => ['TEST_CODES' => '測試代碼', 'KINSHIP_CODES' => '親屬關係代碼表']]);

        Schema::create('KINSHIP_CODES', function ($table) {
            $table->smallInteger('c_kincode')->primary();
            $table->smallInteger('c_pick_sorting')->nullable();
            $table->smallInteger('c_upstep')->nullable();
            $table->smallInteger('c_dwnstep')->nullable();
        });
        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 1, 'c_pick_sorting' => 1, 'c_upstep' => 3, 'c_dwnstep' => 1],
            ['c_kincode' => 2, 'c_pick_sorting' => 2, 'c_upstep' => null, 'c_dwnstep' => 2],
        ]);

        $this->get(route('app.codes.show', ['table_name' => 'KINSHIP_CODES']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Show')
                ->where('thead', function ($thead) {
                    $thead = $thead->all();
                    $diffIndex = array_search('c_up_down_diff_step', $thead, true);
                    $upstepIndex = array_search('c_upstep', $thead, true);

                    return $diffIndex !== false && $upstepIndex !== false && $diffIndex === $upstepIndex - 1;
                })
                ->where('computed_columns', ['c_up_down_diff_step'])
                ->where('rows', function ($rows) {
                    $rows = $rows->all();

                    return $rows[0]['c_up_down_diff_step'] === 2 && $rows[1]['c_up_down_diff_step'] === null;
                }));
    }

    private function seedKinshipCodesForSortFilter(): void {
        config(['codes.tables' => ['KINSHIP_CODES' => '親屬關係代碼表']]);

        Schema::create('KINSHIP_CODES', function ($table) {
            $table->smallInteger('c_kincode')->primary();
            $table->smallInteger('c_upstep')->nullable();
            $table->smallInteger('c_dwnstep')->nullable();
        });
        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 1, 'c_upstep' => 3, 'c_dwnstep' => 1],  // diff = 2
            ['c_kincode' => 2, 'c_upstep' => 1, 'c_dwnstep' => 3],  // diff = -2
            ['c_kincode' => 3, 'c_upstep' => 5, 'c_dwnstep' => 5],  // diff = 0
        ]);
    }

    #[Test]
    public function active_user_can_sort_by_kinship_codes_computed_column(): void {
        $this->seedKinshipCodesForSortFilter();
        $this->actingAs($this->activeUser());

        $this->get(route('app.codes.show', [
            'table_name' => 'KINSHIP_CODES',
            'sort_by' => 'c_up_down_diff_step',
            'sort_dir' => 'asc',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort_by', 'c_up_down_diff_step')
                ->where('rows', function ($rows) {
                    $rows = $rows->all();

                    return $rows[0]['c_kincode'] === 2 && $rows[1]['c_kincode'] === 3 && $rows[2]['c_kincode'] === 1;
                }));
    }

    #[Test]
    public function active_user_can_filter_by_kinship_codes_computed_column(): void {
        $this->seedKinshipCodesForSortFilter();
        $this->actingAs($this->activeUser());

        // 此計算欄位是數值型，match_mode 設為 exact（完全比對）而非 LIKE 子字串，所以篩
        // "2" 只命中 diff=2 的列（kincode 1），diff=-2 的列（kincode 2）不會被誤配對。
        $this->get(route('app.codes.show', [
            'table_name' => 'KINSHIP_CODES',
            'filters' => ['c_up_down_diff_step' => '2'],
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows', function ($rows) {
                    $ids = collect($rows->all())->pluck('c_kincode')->all();

                    return $ids === [1];
                }));
    }

    #[Test]
    public function kinship_codes_computed_column_exact_filter_can_match_negative_values(): void {
        $this->seedKinshipCodesForSortFilter();
        $this->actingAs($this->activeUser());

        $this->get(route('app.codes.show', [
            'table_name' => 'KINSHIP_CODES',
            'filters' => ['c_up_down_diff_step' => '-2'],
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows', function ($rows) {
                    $ids = collect($rows->all())->pluck('c_kincode')->all();

                    return $ids === [2];
                }));
    }

    #[Test]
    public function kinship_codes_computed_column_exact_filter_ignores_non_numeric_input(): void {
        $this->seedKinshipCodesForSortFilter();
        $this->actingAs($this->activeUser());

        // 非數字輸入不套用篩選（避免 MySQL 隱式轉型為 0 誤配對 diff=0 的列），回傳全部列。
        $this->get(route('app.codes.show', [
            'table_name' => 'KINSHIP_CODES',
            'filters' => ['c_up_down_diff_step' => 'abc'],
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows', function ($rows) {
                    return count($rows->all()) === 3;
                }));
    }

    #[Test]
    public function kinship_codes_computed_column_exact_filter_works_in_boolean_mode(): void {
        $this->seedKinshipCodesForSortFilter();
        $this->actingAs($this->activeUser());

        // 進階布林篩選模式（filter_bool=1）下，exact match_mode 仍應走 `=` 完全比對，
        // NOT 詞項對數字仍走 NULL-safe 排除；非數字詞項恆不相等（見 ColumnFilterExpression）。
        $this->get(route('app.codes.show', [
            'table_name' => 'KINSHIP_CODES',
            'filter_bool' => 1,
            'filters' => ['c_up_down_diff_step' => '!2'],
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows', function ($rows) {
                    $ids = collect($rows->all())->pluck('c_kincode')->all();
                    sort($ids);

                    return $ids === [2, 3];
                }));
    }

    #[Test]
    public function guest_sort_or_filter_on_kinship_codes_computed_column_requires_login(): void {
        // 與其他欄位一致，未登入使用者帶 sort_by/filters 一律導向登入頁（見
        // guardSortFilterRequiresAuth()），不因為是計算欄位而放寬，避免拖慢伺服器。
        $this->seedKinshipCodesForSortFilter();

        $this->get(route('app.codes.show', [
            'table_name' => 'KINSHIP_CODES',
            'sort_by' => 'c_up_down_diff_step',
        ]))->assertRedirect(route('login'));

        $this->get(route('app.codes.show', [
            'table_name' => 'KINSHIP_CODES',
            'filters' => ['c_up_down_diff_step' => '2'],
        ]))->assertRedirect(route('login'));
    }

    #[Test]
    public function unknown_table_404(): void {
        $this->get(route('app.codes.show', ['table_name' => 'NOPE_TABLE']))->assertNotFound();
    }

    #[Test]
    public function missing_physical_table_redirects_to_index(): void {
        // 在 allowlist 內但實體表不存在 → PDOException → 重導 app.codes.index。
        config(['codes.tables' => ['TEST_CODES' => '測試代碼', 'GHOST_TABLE' => '幽靈表']]);

        $this->get(route('app.codes.show', ['table_name' => 'GHOST_TABLE']))
            ->assertRedirect(route('app.codes.index'));
    }
}
