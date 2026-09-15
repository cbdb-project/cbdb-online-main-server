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
 * /app/social-institution 機構實體列表：與裸表頁 feature parity（全欄位、排序、逐欄／布林
 * 篩選、公開讀、排序篩選登入門檻），以及 step 4 封寫（SOCIAL_INSTITUTION_* 三表唯讀）與側欄改指。
 */
class SocialInstitutionEntityIndexTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-social-institution-index';
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
        config(['codes.tables' => ['SOCIAL_INSTITUTION_CODES' => '社會機構代碼']]);

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
        Schema::create('SOCIAL_INSTITUTION_NAME_CODES', function (Blueprint $table) {
            $table->integer('c_inst_name_code')->primary();
            $table->string('c_inst_name_hz')->nullable();
            $table->string('c_inst_name_py')->nullable();
        });
        Schema::create('SOCIAL_INSTITUTION_CODES', function (Blueprint $table) {
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_code');
            $table->integer('c_inst_type_code')->nullable();
            $table->integer('c_inst_begin_year')->nullable();
            $table->integer('c_by_nianhao_code')->nullable();
            $table->integer('c_by_nianhao_year')->nullable();
            $table->integer('c_by_year_range')->nullable();
            $table->integer('c_inst_begin_dy')->nullable();
            $table->integer('c_inst_floruit_dy')->nullable();
            $table->integer('c_inst_first_known_year')->nullable();
            $table->integer('c_inst_end_year')->nullable();
            $table->integer('c_ey_nianhao_code')->nullable();
            $table->integer('c_ey_nianhao_year')->nullable();
            $table->integer('c_ey_year_range')->nullable();
            $table->integer('c_inst_end_dy')->nullable();
            $table->integer('c_inst_last_known_year')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->primary(['c_inst_code', 'c_inst_name_code']);
        });
        Schema::create('SOCIAL_INSTITUTION_ADDR', function (Blueprint $table) {
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_code');
            $table->integer('c_inst_addr_type_code');
            $table->integer('c_inst_addr_id');
            $table->double('inst_xcoord');
            $table->double('inst_ycoord');
        });
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });

        DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            ['c_inst_name_code' => 5, 'c_inst_name_hz' => '白鹿洞書院', 'c_inst_name_py' => 'bailudong'],
            ['c_inst_name_code' => 6, 'c_inst_name_hz' => '嶽麓書院', 'c_inst_name_py' => 'yuelu'],
        ]);
        DB::table('SOCIAL_INSTITUTION_CODES')->insert([
            ['c_inst_name_code' => 5, 'c_inst_code' => 1, 'c_inst_type_code' => 1, 'c_inst_begin_dy' => 15],
            ['c_inst_name_code' => 6, 'c_inst_code' => 2, 'c_inst_type_code' => 2, 'c_inst_begin_dy' => null],
        ]);
        DB::table('SOCIAL_INSTITUTION_ADDR')->insert([
            ['c_inst_name_code' => 5, 'c_inst_code' => 1, 'c_inst_addr_type_code' => 1, 'c_inst_addr_id' => 101, 'inst_xcoord' => 0, 'inst_ycoord' => 0],
            ['c_inst_name_code' => 5, 'c_inst_code' => 1, 'c_inst_addr_type_code' => 2, 'c_inst_addr_id' => 102, 'inst_xcoord' => 0, 'inst_ycoord' => 0],
        ]);
    }

    protected function tearDown(): void {
        foreach (['DYNASTIES', 'SOCIAL_INSTITUTION_ADDR', 'SOCIAL_INSTITUTION_CODES', 'SOCIAL_INSTITUTION_NAME_CODES', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function activeUser(int $id = 41): User {
        $user = new User(['name' => 'active', 'email' => "active$id@example.com", 'confirmation_token' => Str::random(32)]);
        $user->id = $id;
        $user->is_active = 1;

        return $user;
    }

    // ── 讀取公開 + 排序／篩選門檻 ──────────────────────────────

    #[Test]
    public function testGuestCanViewIndexWithComputedNameAndAddrCount() {
        $this->get(route('app.social-institution.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SocialInstitution/Index')
                ->where('can_write', false)
                ->where('key_columns', ['c_inst_code'])
                ->where('computed_columns', ['c_inst_name_hz', 'addr_count'])
                ->has('thead', 21)
                ->has('rows', 2)
                // 預設 inst_code 倒序；joined 名稱與地址數
                ->where('rows.0.c_inst_code', 2)
                ->where('rows.0.c_inst_name_hz', '嶽麓書院')
                ->where('rows.0.addr_count', 0)
                ->where('rows.1.c_inst_code', 1)
                ->where('rows.1.c_inst_name_hz', '白鹿洞書院')
                ->where('rows.1.addr_count', 2)
                ->where('dynasty_map.15', '宋'));
    }

    #[Test]
    public function testGuestWithSortByIsRedirectedToLogin() {
        $this->get(route('app.social-institution.index', ['sort_by' => 'c_inst_name_hz']))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function testInactiveUserWithFilterIsRedirectedBackToIndex() {
        $user = new User(['name' => 'inactive', 'email' => 'inactive42@example.com', 'confirmation_token' => Str::random(32)]);
        $user->id = 42;
        $user->is_active = 0;
        $this->actingAs($user);

        $this->get(route('app.social-institution.index', ['filters' => ['c_inst_name_hz' => '書院']]))
            ->assertRedirect(route('app.social-institution.index'));
    }

    // ── 排序／篩選 parity（含 joined 名稱欄） ──────────────────────────────

    #[Test]
    public function testActiveUserCanSortAndFilterByJoinedName() {
        $this->actingAs($this->activeUser());

        $this->get(route('app.social-institution.index', ['sort_by' => 'c_inst_name_hz', 'sort_dir' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.c_inst_name_hz', '嶽麓書院')
                ->where('rows.1.c_inst_name_hz', '白鹿洞書院'));

        $this->get(route('app.social-institution.index', ['filters' => ['c_inst_name_hz' => '白鹿']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.c_inst_code', 1));
    }

    #[Test]
    public function testActiveUserCanFilterByAddrCountExact() {
        $this->actingAs($this->activeUser());

        $this->get(route('app.social-institution.index', ['filters' => ['addr_count' => '2']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.c_inst_code', 1));
    }

    #[Test]
    public function testKeywordSearchHitsJoinedName() {
        $this->get(route('app.social-institution.index', ['q' => '嶽麓']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.c_inst_code', 2));
    }

    // ── step 4：三張裸表封寫 ──────────────────────────────

    #[Test]
    public function testCodesShowMarksSocialInstitutionTablesReadOnly() {
        config(['codes.tables' => [
            'SOCIAL_INSTITUTION_CODES' => '社會機構代碼',
            'SOCIAL_INSTITUTION_NAME_CODES' => '社會機構名稱代碼',
            'SOCIAL_INSTITUTION_ADDR' => '社會機構地址',
        ]]);
        $this->actingAs($this->activeUser());

        foreach (['SOCIAL_INSTITUTION_CODES', 'SOCIAL_INSTITUTION_NAME_CODES', 'SOCIAL_INSTITUTION_ADDR'] as $table) {
            $this->get(route('app.codes.show', ['table_name' => $table]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('is_read_only', true)
                    ->where('can_edit', false));
        }
    }

    // ── 側欄 ──────────────────────────────

    #[Test]
    public function testSidebarSocialInstitutionNodePointsToEntityPage() {
        $tree = \App\Support\Navigation::tree(null);
        $codes = collect($tree)->firstWhere('key', 'codes');
        $node = collect($codes['children'])->firstWhere('key', 'social-institution-codes');

        $this->assertSame(route('app.social-institution.index'), $node['href']);
        // ── 2026-09-15（Blade 下架環節 4d-2）───────────────────────────
        // 這一行原本是 `assertContains('SOCIAL_INSTITUTION_CODES', $node['active']['pages'])` ＋
        // `assertContains('app.social-institution.*', $node['active']['patterns'])`。`active` 這個節點欄位
        // 只服務 Blade sidebar，已隨 sidebar partial（環節 5a）一併移除；
        // `entity_aggregates.php` 裡只為餵 `patterns` 而存在的 `nav.pattern` 也一併刪了。
        //
        // 它們真正在守的是「這個節點仍然宣告它聚合的是 SOCIAL_INSTITUTION_CODES 這張裸表」——那個資訊
        // 現在只剩 suffix 帶著，所以改驗 suffix（實測會紅：把 entityNavItem() 的
        // $node['suffix'] 拿掉，這條就失敗）。
        //
        // 📌 **不需要再補「href 沒退回裸表 codes 頁」的反面斷言**：上面那條
        // `assertSame(route('app.social-institution.index'), $node['href'])` 已經涵蓋——config 查找落空時
        // `entityNavItem()` 退回 `codeItem()`，href 就不會等於實體入口，先在那裡紅。
        $this->assertSame('(SOCIAL_INSTITUTION_CODES)', $node['suffix']);
    }
}
