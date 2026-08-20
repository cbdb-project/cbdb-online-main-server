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
 * /app/text 文獻實體列表：與裸表頁 feature parity（全欄位、排序、逐欄篩選、公開讀、
 * 排序篩選登入門檻）＋聚合特有計算欄（版本數 instance_count、子文獻數 child_count），
 * 以及 step 4 封寫（TEXT_CODES／TEXT_INSTANCE_DATA 唯讀）與側欄改指。
 */
class TextEntityIndexTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-text-entity-index';
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
        config(['codes.tables' => ['TEXT_CODES' => '文獻代碼表']]);

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
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title_chn')->nullable();
            $table->string('c_title')->nullable();
            $table->string('c_title_trans')->nullable();
            $table->string('c_text_type_id')->nullable();
            $table->integer('c_text_year')->nullable();
            $table->integer('c_text_nh_code')->nullable();
            $table->integer('c_text_nh_year')->nullable();
            $table->integer('c_text_range_code')->nullable();
            $table->integer('c_bibl_cat_code')->nullable();
            $table->integer('c_extant')->nullable();
            $table->integer('c_text_country')->nullable();
            $table->integer('c_text_dy')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->string('c_url_api')->nullable();
            $table->string('c_url_api_coda')->nullable();
            $table->string('c_url_homepage')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_title_alt_chn')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
        Schema::create('TEXT_INSTANCE_DATA', function (Blueprint $table) {
            $table->integer('c_textid');
            $table->integer('c_text_edition_id');
            $table->integer('c_text_instance_id');
            $table->string('c_instance_title_chn')->nullable();
        });
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });

        DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);
        // 1（來源根，被 2 引用）與 2（有兩列版本）。
        DB::table('TEXT_CODES')->insert(['c_textid' => 1, 'c_title_chn' => '總目提要', 'c_title' => 'zongmu tiyao', 'c_text_dy' => 15]);
        DB::table('TEXT_CODES')->insert(['c_textid' => 2, 'c_title_chn' => '文集甲', 'c_title' => 'wenji jia', 'c_source' => 1]);
        DB::table('TEXT_INSTANCE_DATA')->insert([
            ['c_textid' => 2, 'c_text_edition_id' => 1, 'c_text_instance_id' => 1, 'c_instance_title_chn' => '初刻本'],
            ['c_textid' => 2, 'c_text_edition_id' => 1, 'c_text_instance_id' => 2, 'c_instance_title_chn' => '重印本'],
        ]);
    }

    protected function tearDown(): void {
        foreach (['DYNASTIES', 'TEXT_INSTANCE_DATA', 'TEXT_CODES', 'users'] as $t) {
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
    public function testGuestCanViewIndexWithComputedCounts() {
        $this->get(route('app.text.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Text/Index')
                ->where('can_write', false)
                ->where('key_columns', ['c_textid'])
                ->where('computed_columns', ['instance_count', 'child_count'])
                ->has('thead', 26)
                ->has('rows', 2)
                // 預設 textid 倒序；版本數與子文獻數計算欄。
                ->where('rows.0.c_textid', 2)
                ->where('rows.0.instance_count', 2)
                ->where('rows.0.child_count', 0)
                ->where('rows.1.c_textid', 1)
                ->where('rows.1.instance_count', 0)
                ->where('rows.1.child_count', 1)
                ->where('dynasty_map.15', '宋'));
    }

    #[Test]
    public function testGuestWithSortByIsRedirectedToLogin() {
        $this->get(route('app.text.index', ['sort_by' => 'c_title_chn']))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function testInactiveUserWithFilterIsRedirectedBackToIndex() {
        $user = new User(['name' => 'inactive', 'email' => 'inactive42@example.com', 'confirmation_token' => Str::random(32)]);
        $user->id = 42;
        $user->is_active = 0;
        $this->actingAs($user);

        $this->get(route('app.text.index', ['filters' => ['c_title_chn' => '文集']]))
            ->assertRedirect(route('app.text.index'));
    }

    // ── 排序／篩選 parity（含計算欄） ──────────────────────────────

    #[Test]
    public function testActiveUserCanSortAndFilter() {
        $this->actingAs($this->activeUser());

        $this->get(route('app.text.index', ['sort_by' => 'c_title', 'sort_dir' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.c_textid', 2)
                ->where('rows.1.c_textid', 1));

        $this->get(route('app.text.index', ['filters' => ['c_title_chn' => '文集']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.c_textid', 2));
    }

    #[Test]
    public function testActiveUserCanFilterByInstanceCountExact() {
        $this->actingAs($this->activeUser());

        $this->get(route('app.text.index', ['filters' => ['instance_count' => '2']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.c_textid', 2));
    }

    #[Test]
    public function testKeywordSearchHitsTitle() {
        $this->get(route('app.text.index', ['q' => '總目']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.c_textid', 1));
    }

    // ── step 4（codes UI 封寫）暫緩：裸表頁仍可寫 ──────────────────────────────

    #[Test]
    public function testCodesShowKeepsTextTablesWritableWhileParityPending() {
        // 有意的過渡狀態（config/entity_aggregates.php 的 closed_code_tables 註解）：
        // 裸表編輯頁有實體頁尚未對齊的功能（作者列表面板、instance 載入動作等），
        // parity 補齊前不封寫。此測試固化「暫不封寫」的決策，封寫時應改斷言 is_read_only。
        config(['codes.tables' => [
            'TEXT_CODES' => '文獻代碼表',
            'TEXT_INSTANCE_DATA' => '文獻版本資料表',
        ]]);
        $this->actingAs($this->activeUser());

        foreach (['TEXT_CODES', 'TEXT_INSTANCE_DATA'] as $table) {
            $this->get(route('app.codes.show', ['table_name' => $table]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('is_read_only', false));
        }
    }

    // ── 側欄 ──────────────────────────────

    #[Test]
    public function testSidebarTextCodesNodePointsToEntityPage() {
        $tree = \App\Support\Navigation::tree(null);
        $codes = collect($tree)->firstWhere('key', 'codes');
        $node = collect($codes['children'])->firstWhere('key', 'text-codes');

        $this->assertSame(route('app.text.index'), $node['href']);
        $this->assertContains('TEXT_CODES', $node['active']['pages']);
        $this->assertContains('app.text.*', $node['active']['patterns']);
    }
}
