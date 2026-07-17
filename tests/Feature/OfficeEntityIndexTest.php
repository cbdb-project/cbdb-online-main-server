<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * /app/office 官職實體列表：與 app/codes/OFFICE_CODES 裸表頁 feature parity
 * （全欄位、排序、逐欄／布林篩選、公開讀、排序篩選登入門檻），
 * 以及 step 4 封寫（OFFICE_CODES 進 CodesController::$readOnlyTables）。
 */
class OfficeEntityIndexTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-office-entity-index';
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
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        config(['codes.per_page' => 20]);
        config(['codes.tables' => ['OFFICE_CODES' => '官職代碼']]);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
        // 生產 OFFICE_CODES 全欄位（appIndex 關鍵字搜尋會掃全部實體欄位，schema 須齊全）。
        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->integer('c_dy')->nullable();
            $table->string('c_office_pinyin')->nullable();
            $table->string('c_office_chn')->nullable();
            $table->string('c_office_pinyin_alt')->nullable();
            $table->string('c_office_chn_alt')->nullable();
            $table->string('c_office_trans')->nullable();
            $table->string('c_office_trans_alt')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_category_1')->nullable();
            $table->string('c_category_2')->nullable();
            $table->string('c_category_3')->nullable();
            $table->string('c_category_4')->nullable();
            $table->integer('c_office_id_old')->nullable();
        });
        Schema::create('OFFICE_CODE_TYPE_REL', function (Blueprint $table) {
            $table->integer('c_office_id');
            $table->string('c_office_tree_id');
            $table->primary(['c_office_id', 'c_office_tree_id']);
        });
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });

        DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);
        DB::table('OFFICE_CODES')->insert([
            ['c_office_id' => 1, 'c_office_chn' => '知府', 'c_office_pinyin' => 'zhifu', 'c_dy' => 15],
            ['c_office_id' => 2, 'c_office_chn' => '尚書', 'c_office_pinyin' => 'shangshu', 'c_dy' => 15],
            ['c_office_id' => 3, 'c_office_chn' => '縣令', 'c_office_pinyin' => 'xianling', 'c_dy' => null],
        ]);
        DB::table('OFFICE_CODE_TYPE_REL')->insert([
            ['c_office_id' => 1, 'c_office_tree_id' => 'x01'],
            ['c_office_id' => 1, 'c_office_tree_id' => 'x02'],
            ['c_office_id' => 2, 'c_office_tree_id' => 'x01'],
        ]);
    }

    protected function tearDown(): void {
        foreach (['DYNASTIES', 'OFFICE_CODE_TYPE_REL', 'OFFICE_CODES', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function activeUser(int $id = 31): User {
        $user = new User(['name' => 'active', 'email' => "active$id@example.com", 'confirmation_token' => Str::random(32)]);
        $user->id = $id;
        $user->is_active = 1;

        return $user;
    }

    private function inactiveUser(int $id = 32): User {
        $user = new User(['name' => 'inactive', 'email' => "inactive$id@example.com", 'confirmation_token' => Str::random(32)]);
        $user->id = $id;
        $user->is_active = 0;

        return $user;
    }

    // ── 讀取公開 + 排序／篩選門檻 ──────────────────────────────

    #[Test]
    public function testGuestCanViewIndexWithFullTheadAndTypeCount() {
        $this->get(route('app.office.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Office/Index')
                ->where('can_write', false)
                ->where('key_columns', ['c_office_id'])
                ->where('computed_columns', ['type_count'])
                ->has('thead', 17)
                ->where('thead.0', 'c_office_id')
                ->where('thead.16', 'type_count')
                ->has('rows', 3)
                // 預設 ID 倒序；type_count 為 OFFICE_CODE_TYPE_REL 關聯數
                ->where('rows.0.c_office_id', 3)
                ->where('rows.0.type_count', 0)
                ->where('rows.2.c_office_id', 1)
                ->where('rows.2.type_count', 2)
                ->where('dynasty_map.15', '宋'));
    }

    #[Test]
    public function testGuestWithSortByIsRedirectedToLoginWithIntendedUrl() {
        $url = route('app.office.index', ['sort_by' => 'c_office_chn']);

        $this->get($url)->assertRedirect(route('login'));
        $this->assertSame($url, session('url.intended'));
    }

    #[Test]
    public function testGuestWithNonEmptyFilterIsRedirectedToLogin() {
        $this->get(route('app.office.index', ['filters' => ['c_office_chn' => '知']]))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function testInactiveUserWithSortIsRedirectedBackToIndex() {
        $this->actingAs($this->inactiveUser());

        $this->get(route('app.office.index', ['sort_by' => 'c_office_chn']))
            ->assertRedirect(route('app.office.index'));
    }

    // ── 排序／篩選 parity ──────────────────────────────

    #[Test]
    public function testActiveUserCanSortByColumnWithPkTieBreaker() {
        $this->actingAs($this->activeUser());

        $this->get(route('app.office.index', ['sort_by' => 'c_office_pinyin', 'sort_dir' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort_by', 'c_office_pinyin')
                ->where('sort_dir', 'asc')
                ->where('rows.0.c_office_pinyin', 'shangshu')
                ->where('rows.1.c_office_pinyin', 'xianling')
                ->where('rows.2.c_office_pinyin', 'zhifu'));
    }

    #[Test]
    public function testActiveUserCanFilterByColumnContains() {
        $this->actingAs($this->activeUser());

        $this->get(route('app.office.index', ['filters' => ['c_office_chn' => '知']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.c_office_chn', '知府'));
    }

    #[Test]
    public function testActiveUserCanSortAndFilterByComputedTypeCount() {
        $this->actingAs($this->activeUser());

        // exact 比對：type_count=1 只命中尚書
        $this->get(route('app.office.index', ['filters' => ['type_count' => '1']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.c_office_id', 2));

        $this->get(route('app.office.index', ['sort_by' => 'type_count', 'sort_dir' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rows.0.c_office_id', 1));
    }

    #[Test]
    public function testBooleanFilterOrAndParseError() {
        $this->actingAs($this->activeUser());

        $this->get(route('app.office.index', ['filter_bool' => 1, 'filters' => ['c_office_chn' => '知府|尚書']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('boolean_enabled', true)
                ->has('rows', 2)
                ->has('filter_descriptions.c_office_chn'));

        // 語法錯誤：記錄錯誤碼並略過該欄（不轉字面），列表不縮減
        $this->get(route('app.office.index', ['filter_bool' => 1, 'filters' => ['c_office_chn' => '知府|']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter_errors.c_office_chn', 'dangling_operator')
                ->has('rows', 3));
    }

    #[Test]
    public function testKeywordSearchScansEntityColumns() {
        $this->get(route('app.office.index', ['q' => 'shang']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.c_office_id', 2));
    }

    #[Test]
    public function testUnknownSortAndFilterColumnsAreIgnored() {
        $this->actingAs($this->activeUser());

        $this->get(route('app.office.index', ['sort_by' => 'no_such_col', 'filters' => ['no_such_col' => 'x']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort_by', '')
                ->has('rows', 3));
    }

    // ── step 4：OFFICE_CODES 裸表封寫 ──────────────────────────────

    #[Test]
    public function testCodesShowMarksOfficeCodesReadOnly() {
        $this->actingAs($this->activeUser());

        $this->get(route('app.codes.show', ['table_name' => 'OFFICE_CODES']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('is_read_only', true)
                ->where('can_edit', false));
    }

    #[Test]
    public function testCodesCreatePageIsBlockedForOfficeCodes() {
        $this->actingAs($this->activeUser());

        $this->get('/codes/OFFICE_CODES/create')->assertRedirect();
        $this->get(route('app.codes.create', ['table_name' => 'OFFICE_CODES']))->assertRedirect();
    }

    // ── 側欄 ──────────────────────────────

    #[Test]
    public function testSidebarOfficeCodesNodePointsToEntityPage() {
        $tree = \App\Support\Navigation::tree(null);
        $codes = collect($tree)->firstWhere('key', 'codes');
        $node = collect($codes['children'])->firstWhere('key', 'office-codes');

        $this->assertSame(route('app.office.index'), $node['href']);
        $this->assertContains('OFFICE_CODES', $node['active']['pages']);
        $this->assertContains('app.office.*', $node['active']['patterns']);
    }
}
