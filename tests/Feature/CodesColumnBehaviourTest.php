<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 泛用 codes 表單的逐欄行為（column_behaviour prop）與 Load Data 端點。
 *
 * 這些是舊版 codes/edit.blade.php 有、React 遷移（3f131d6）漏移植的行為：稽核欄不可編輯、
 * 欄位提示、TEXT_INSTANCE_DATA 依 c_textid 帶入書名。
 */
class CodesColumnBehaviourTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-codes-column-behaviour';
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
        config(['codes.tables' => [
            'TEXT_INSTANCE_DATA' => '書目版本',
            'ADDR_BELONGS_DATA' => '地址歸屬',
            'TEST_PLAIN_CODES' => '無特殊行為的表',
        ]]);

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('confirmation_token')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_admin')->default(0);
            $table->timestamps();
        });

        Schema::create('TEXT_CODES', function ($table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title_chn')->nullable();
            $table->string('c_title')->nullable();
        });
        DB::table('TEXT_CODES')->insert([
            ['c_textid' => 500, 'c_title_chn' => '雪篷夜話', 'c_title' => 'xue peng ye hua'],
            // 標題裡剛好含「500」：舊版用 LIKE 查 c_textid 時可能撈到這一本（本次刻意不重現）
            ['c_textid' => 900, 'c_title_chn' => '雜記五百篇 500 卷', 'c_title' => 'za ji 500'],
            // c_textid=0（「未知」書目）：真實庫存在此列。非數字 textId 若沒被路由擋下，
            // (int)'abc' === 0 會撈到它——下面的測試靠這個可辨識的書名才驗得出守衛有效。
            ['c_textid' => 0, 'c_title_chn' => '未知書目哨兵', 'c_title' => 'unknown sentinel'],
            // 兩個書名欄皆空的書目：驗「書目存在但沒有書名可帶入」要如實說明，不可誤報成「已有值」
            ['c_textid' => 700, 'c_title_chn' => null, 'c_title' => null],
        ]);

        Schema::create('TEXT_INSTANCE_DATA', function ($table) {
            $table->integer('c_textid');
            $table->integer('c_instance_id')->default(0);
            $table->string('c_instance_title_chn')->nullable();
            $table->string('c_instance_title')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary(['c_textid', 'c_instance_id']);
        });
        DB::table('TEXT_INSTANCE_DATA')->insert([
            'c_textid' => 500, 'c_instance_id' => 1,
            'c_instance_title_chn' => null, 'c_instance_title' => null,
            'c_created_by' => '原始建立者', 'c_created_date' => '2019-01-01 00:00:00',
        ]);

        Schema::create('ADDR_BELONGS_DATA', function ($table) {
            $table->integer('c_addr_id');
            $table->integer('c_belongs_to')->default(0);
            $table->primary(['c_addr_id', 'c_belongs_to']);
        });
        DB::table('ADDR_BELONGS_DATA')->insert(['c_addr_id' => 7, 'c_belongs_to' => 8]);

        Schema::create('TEST_PLAIN_CODES', function ($table) {
            $table->integer('code_id')->primary();
            $table->string('description')->nullable();
        });
        DB::table('TEST_PLAIN_CODES')->insert(['code_id' => 1, 'description' => 'x']);

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('c_personid')->default(0);
            $table->string('resource')->nullable();
            $table->text('resource_id')->nullable();
            $table->string('op_type')->nullable();
            $table->longText('resource_data')->nullable();
            $table->longText('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });
    }

    private function activeUser(): User {
        // is_active／is_admin 是提權欄位、刻意不在 User::$fillable，測試 fixture 需顯式解除守衛。
        return User::unguarded(fn () => User::firstOrCreate(
            ['email' => 'u@example.com'],
            [
                'name' => '張三', 'password' => bcrypt('x'),
                'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
            ]
        ));
    }

    // ───────── 稽核欄：任何模式都灰底唯讀（不開放編輯） ─────────

    #[Test]
    public function edit_marks_audit_columns_readonly(): void {
        // 用 readonly 而非 disabled：這四欄的用途就是被讀，readOnly 仍可選取複製（舊版也是 readonly）。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'TEXT_INSTANCE_DATA', 'id' => '500_._1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('column_behaviour.c_created_by.readonly', true)
                ->where('column_behaviour.c_created_date.readonly', true)
                ->where('column_behaviour.c_modified_by.readonly', true)
                ->where('column_behaviour.c_modified_date.readonly', true));
    }

    #[Test]
    public function edit_previews_only_the_modified_audit_values(): void {
        // c_modified_* 會被改寫成當下值 → 給「提交後會被替換為 X」預覽；
        // c_created_* 是沿用原值、不會被改寫 → 不該給這個預覽。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'TEXT_INSTANCE_DATA', 'id' => '500_._1']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                $this->assertArrayNotHasKey('hint', $b['c_created_by'], 'c_created_* 不應有替換預覽');
                $this->assertArrayNotHasKey('hint', $b['c_created_date']);
                $this->assertStringContainsString('張三', $b['c_modified_by']['hint']['text']);
                $this->assertMatchesRegularExpression(
                    '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',
                    $b['c_modified_date']['hint']['text']
                );
            });
    }

    #[Test]
    public function guest_gets_readonly_audit_columns_without_the_preview(): void {
        // 未登入者看不到「會被替換為誰」（沒有署名可預覽），但欄位一樣不可編輯。
        $this->get(route('app.codes.edit', ['table_name' => 'TEXT_INSTANCE_DATA', 'id' => '500_._1']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                $this->assertTrue($b['c_modified_by']['readonly']);
                $this->assertArrayNotHasKey('hint', $b['c_modified_by']);
            });
    }

    #[Test]
    public function create_marks_audit_columns_readonly_and_keeps_them_in_the_columns_prop(): void {
        // 新增與編輯同一條規則：稽核欄不開放編輯（灰底唯讀）。
        // 斷言的是 columns prop 而非實際 HTTP payload——兩頁的 form.data 都是由 columns 建出來的
        // （Create.tsx／Edit.tsx 的 `columns.forEach` 初始化），所以這是「送出內容不變」在伺服器端
        // 能拿到的最貼近代理。曾有一版把稽核欄從 columns 移除、因而改動 payload，已撤回；此斷言即防它復發。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.create', ['table_name' => 'TEXT_INSTANCE_DATA']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $props = $page->toArray()['props'];
                foreach (['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'] as $col) {
                    $this->assertContains($col, $props['columns'], "{$col} 仍應在欄位清單（送出內容不變）");
                    $this->assertTrue($props['column_behaviour'][$col]['readonly'], "{$col} 應唯讀");
                }
            });
    }

    #[Test]
    public function create_does_not_show_the_replacement_preview(): void {
        // 「提交後會被替換為 X」只在編輯有意義：新增時這些欄位還沒有值可談替換。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.create', ['table_name' => 'TEXT_INSTANCE_DATA']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                foreach (['c_created_by', 'c_modified_by', 'c_modified_date'] as $col) {
                    $this->assertArrayNotHasKey('hint', $b[$col]);
                }
            });
    }

    #[Test]
    public function create_still_stamps_audit_fields(): void {
        // performStore → enforceAuditFieldsForCreate 蓋上建立者與時間（行為與先前相同）。
        $this->actingAs($this->activeUser())
            ->post(route('app.codes.store', ['table_name' => 'TEXT_INSTANCE_DATA']), [
                'c_textid' => 500, 'c_instance_id' => 2, 'c_instance_title_chn' => '新版本',
                // 停用欄位的值仍在 Inertia payload 裡（送的是 form.data 不是 DOM 表單）
                'c_created_by' => '冒名者', 'c_created_date' => '1999-01-01 00:00:00',
            ])
            ->assertRedirect();

        $row = DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 500)->where('c_instance_id', 2)->first();
        $this->assertNotNull($row);
        $this->assertSame('張三', $row->c_created_by, '建立者必須是實際操作者，不能被送出的值左右');
        $this->assertStringStartsWith(date('Y'), (string) $row->c_created_date);
    }

    #[Test]
    public function edit_cannot_overwrite_audit_columns_even_if_submitted(): void {
        // 欄位停用只是 UI；真正的保證在後端。直接送出偽造的稽核值也不得生效。
        $this->actingAs($this->activeUser())
            ->patch(route('app.codes.update', ['table_name' => 'TEXT_INSTANCE_DATA', 'id' => '500_._1']), [
                'c_textid' => 500, 'c_instance_id' => 1, 'c_instance_title_chn' => '改過',
                'c_created_by' => '冒名者', 'c_created_date' => '1999-01-01 00:00:00',
                'c_modified_by' => '冒名者',
            ])
            ->assertRedirect();

        $row = DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 500)->where('c_instance_id', 1)->first();
        $this->assertSame('原始建立者', $row->c_created_by, 'c_created_by 必須沿用原值');
        $this->assertStringStartsWith('2019-01-01', (string) $row->c_created_date);
        $this->assertSame('張三', $row->c_modified_by, 'c_modified_by 必須是當下操作者');
    }

    // ───────── 欄位提示與動作 ─────────

    #[Test]
    public function text_instance_textid_gets_hint_and_load_action(): void {
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'TEXT_INSTANCE_DATA', 'id' => '500_._1']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour']['c_textid'];
                $this->assertSame('load_text_title', $b['action']);
                // 提示文字帶 :link 佔位，前端就地換成真正的 <a>（保留舊版「連結在句中」的讀法）。
                $this->assertStringContainsString(':link', $b['hint']['text']);
                // 提示文字不得含 HTML：連結另以結構化資料傳出，前端不用 dangerouslySetInnerHTML。
                $this->assertStringNotContainsString('<a', $b['hint']['text']);
                $this->assertSame('TEXT_CODES', $b['hint']['link']['label']);
                // flag-aware：codes flag=new 時必須指向 React 版（先前 Create.tsx 硬寫 /codes/TEXT_CODES）
                $this->assertSame('/app/codes/TEXT_CODES', $b['hint']['link']['href']);
            });
    }

    #[Test]
    public function addr_belongs_columns_get_the_copy_hint(): void {
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'ADDR_BELONGS_DATA', 'id' => '7_._8']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                foreach (['c_addr_id', 'c_belongs_to'] as $col) {
                    $this->assertStringContainsString(':link', $b[$col]['hint']['text']);
                    $this->assertStringNotContainsString('<a', $b[$col]['hint']['text']);
                    $this->assertSame('ADDR_CODES', $b[$col]['hint']['link']['label']);
                    $this->assertSame('/app/codes/ADDR_CODES', $b[$col]['hint']['link']['href']);
                    $this->assertArrayNotHasKey('action', $b[$col]);
                }
            });
    }

    #[Test]
    public function plain_table_has_no_special_behaviour(): void {
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'TEST_PLAIN_CODES', 'id' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('column_behaviour', []));
    }

    // ───────── Load Data 端點 ─────────

    #[Test]
    public function text_title_endpoint_returns_the_exact_book(): void {
        $this->getJson(route('app.codes.text-title', ['textId' => 500]))
            ->assertOk()
            ->assertJson(['found' => true, 'c_textid' => 500, 'c_title_chn' => '雪篷夜話', 'c_title' => 'xue peng ye hua']);
    }

    #[Test]
    public function text_title_endpoint_does_not_fall_back_to_a_title_match(): void {
        // 舊版按鈕打 /api/select/search/text（c_title_chn LIKE %q% OR c_textid = q）再取 data[0]，
        // 查 c_textid=500 時可能回傳「雜記五百篇 500 卷」。此端點必須是主鍵精確查詢。
        $this->getJson(route('app.codes.text-title', ['textId' => 500]))
            ->assertOk()
            ->assertJsonPath('c_title_chn', '雪篷夜話');

        // 不存在的 ID 不得因為標題含該數字就命中
        $this->getJson(route('app.codes.text-title', ['textId' => 5001]))
            ->assertStatus(404)
            ->assertJson(['found' => false]);
    }

    #[Test]
    public function text_title_endpoint_rejects_non_numeric_ids(): void {
        // 非數字必須被路由的 where('[0-9]+') 擋掉：否則會落到控制器做 (int)'abc' === 0，
        // 撈到 c_textid=0 的「未知」書目（fixture 裡刻意放了可辨識的書名）。
        // 狀態碼釘 405 而非 404：該路徑同時符合泛用的 PATCH/PUT/DELETE app/codes/{table_name}/{id}，
        // Laravel 對「路徑存在但方法不符」固定回 405——若守衛被移除，這裡會變成 200。
        $response = $this->getJson('/app/codes/text-title/abc');
        $response->assertStatus(405);
        // 關鍵斷言：不得回傳 c_textid=0 那一列的內容。移除路由守衛時此行會失敗。
        $this->assertStringNotContainsString('未知書目哨兵', $response->getContent());
    }

    #[Test]
    public function text_title_endpoint_can_still_look_up_the_unknown_book_row(): void {
        // c_textid=0 是真實可編輯的列，明確用 0 查詢時應該查得到（不是被當成無效輸入）。
        $this->getJson(route('app.codes.text-title', ['textId' => 0]))
            ->assertOk()
            ->assertJson(['found' => true, 'c_textid' => 0, 'c_title_chn' => '未知書目哨兵']);
    }

    #[Test]
    public function text_title_endpoint_reports_a_book_with_no_titles(): void {
        // 前端據此顯示「該書沒有書名可帶入」，而不是誤報成「兩欄皆已有值」。
        $this->getJson(route('app.codes.text-title', ['textId' => 700]))
            ->assertOk()
            ->assertJson(['found' => true, 'c_textid' => 700])
            ->assertJsonPath('c_title_chn', null)
            ->assertJsonPath('c_title', null);
    }

    #[Test]
    public function proposal_edit_also_marks_audit_columns_readonly_without_the_preview(): void {
        // 三個碼表表單一致：提案調整頁也不開放編輯稽核欄。
        // 但不給「提交後會被替換為 X」——替換發生在核准當下、由審核人蓋章，現在預告會誤導。
        $opId = DB::table('operations')->insertGetId([
            'user_id' => $this->activeUser()->id,
            'c_personid' => 0,
            'resource' => 'TEXT_INSTANCE_DATA',
            'resource_id' => '500_._1',
            'op_type' => \App\Models\Operation::TYPE_PROPOSAL_UPDATE,
            'resource_data' => json_encode([
                'c_textid' => 500, 'c_instance_id' => 1, 'c_instance_title_chn' => '提案改名',
                '__key_columns' => ['c_textid', 'c_instance_id'],
                '__proposal_meta' => ['action' => 'update', 'table' => 'TEXT_INSTANCE_DATA'],
                '__review_status' => 'pending',
            ]),
            'resource_original' => json_encode(['c_textid' => 500, 'c_instance_id' => 1]),
            'crowdsourcing_status' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->activeUser())
            ->get(route('app.codes.proposals.edit', ['table_name' => 'TEXT_INSTANCE_DATA', 'operation' => $opId]))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                foreach (['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'] as $col) {
                    $this->assertTrue($b[$col]['readonly'], "{$col} 應唯讀");
                    $this->assertArrayNotHasKey('hint', $b[$col], "{$col} 不應有替換預覽");
                }
            });
    }

    #[Test]
    public function create_page_also_gets_the_column_hints(): void {
        // 這些提示原本是 Create.tsx 的硬編碼中文（英文語境漏字），現由後端供給；不可只有 edit 有。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.create', ['table_name' => 'TEXT_INSTANCE_DATA']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour']['c_textid'];
                $this->assertStringContainsString(':link', $b['hint']['text']);
                $this->assertStringNotContainsString('<a', $b['hint']['text']);
                $this->assertSame('TEXT_CODES', $b['hint']['link']['label']);
                $this->assertSame('load_text_title', $b['action']);
            });
    }

    #[Test]
    public function column_hints_are_translated_in_the_en_locale(): void {
        // 修復動機之一就是「英文語境漏字」——鎖住 en 真的拿到英文而非中文。
        $this->actingAs($this->activeUser())
            ->withSession(['locale' => 'en'])
            ->get(route('app.codes.edit', ['table_name' => 'TEXT_INSTANCE_DATA', 'id' => '500_._1']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                $this->assertStringContainsString('Make sure', $b['c_textid']['hint']['text']);
                $this->assertStringContainsString('replaced', $b['c_modified_by']['hint']['text']);
            });
    }
}
