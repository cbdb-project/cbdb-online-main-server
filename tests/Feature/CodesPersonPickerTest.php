<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 泛用 codes 表單的人物選擇器（column_behaviour[col]['picker']）。
 *
 * 判準是**外鍵實際指向 BIOG_MAIN**，而非舊版按欄名硬編碼 c_personid／c_kin_id。
 * 這是刻意的決定：以 schema 宣告為唯一權威，規則只有一條、隨 schema 自動跟上。
 * 其取捨（納入語義可疑但有外鍵的欄、排除無外鍵的人物欄）亦由測試明文鎖住。
 */
class CodesPersonPickerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-codes-person-picker';
        if (!is_dir($compiledViewPath)) {
            mkdir($compiledViewPath, 0777, true);
        }
        config(['view.compiled' => $compiledViewPath]);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // Schema::getForeignKeys() 在 SQLite 走 PRAGMA foreign_key_list，
            // 只要建表時宣告了外鍵就讀得到（不需開啟 foreign_key_constraints 強制檢查）。
        ]);
        config(['codes.tables' => [
            'ASSOC_DATA' => '社會關係',
            'MERGED_PERSON_DATA' => '合併記錄',
            'TEST_PLAIN_CODES' => '無人物欄的表',
            'TEST_PERSON_AUDIT' => '同時有人物欄與稽核欄的表',
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

        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
        });
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 0, 'c_name_chn' => '未詳', 'c_name' => 'Weixiang'],
            ['c_personid' => 11, 'c_name_chn' => '晁公武', 'c_name' => 'Chao Gongwu'],
            ['c_personid' => 47509, 'c_name_chn' => '陳善', 'c_name' => 'Chen Shan'],
            ['c_personid' => 68294, 'c_name_chn' => null, 'c_name' => null],
        ]);

        // ASSOC_DATA：六個欄位都有外鍵指向 BIOG_MAIN。其中 c_assoc_id 等四欄是舊版按欄名
        // 硬編碼會漏掉的（社會關係「對方是誰」）——本測試的重點之一。
        Schema::create('ASSOC_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_id');
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_kin_id')->nullable();
            $table->integer('c_assoc_kin_id')->nullable();
            $table->integer('c_tertiary_personid')->nullable();
            $table->integer('c_assoc_claimer_id')->nullable();
            // 非人物欄，用來驗「-999 只在人物欄被歸一化」
            $table->integer('c_year')->nullable();
            $table->string('c_notes')->nullable();
            $table->primary(['c_personid', 'c_assoc_id', 'c_assoc_code']);
            $table->foreign('c_personid')->references('c_personid')->on('BIOG_MAIN');
            $table->foreign('c_assoc_id')->references('c_personid')->on('BIOG_MAIN');
            $table->foreign('c_kin_id')->references('c_personid')->on('BIOG_MAIN');
            $table->foreign('c_assoc_kin_id')->references('c_personid')->on('BIOG_MAIN');
            $table->foreign('c_tertiary_personid')->references('c_personid')->on('BIOG_MAIN');
            $table->foreign('c_assoc_claimer_id')->references('c_personid')->on('BIOG_MAIN');
        });
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => 11, 'c_assoc_id' => 47509, 'c_assoc_code' => 1,
            'c_kin_id' => 0, 'c_assoc_kin_id' => null, 'c_tertiary_personid' => 68294,
            'c_assoc_claimer_id' => null, 'c_year' => null, 'c_notes' => 'x',
        ]);

        // MERGED_PERSON_DATA：欄名叫 c_personid 但**沒有外鍵**（被合併的人可能已不存在）。
        // 依規則 B 刻意不給選擇器。
        Schema::create('MERGED_PERSON_DATA', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_note')->nullable();
        });
        DB::table('MERGED_PERSON_DATA')->insert(['c_personid' => 11, 'c_note' => 'merged']);

        Schema::create('TEST_PLAIN_CODES', function ($table) {
            $table->integer('code_id')->primary();
            $table->string('description')->nullable();
        });
        DB::table('TEST_PLAIN_CODES')->insert(['code_id' => 1, 'description' => 'x']);

        // 同時具備人物欄（有外鍵）與四個稽核欄，用來驗兩段行為互不覆寫。
        Schema::create('TEST_PERSON_AUDIT', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_label')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->foreign('c_personid')->references('c_personid')->on('BIOG_MAIN');
        });
        DB::table('TEST_PERSON_AUDIT')->insert([
            'c_personid' => 11, 'c_label' => 'x', 'c_created_by' => '原始建立者',
        ]);

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

    private function editAssoc() {
        return $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'ASSOC_DATA', 'id' => '11_._47509_._1']));
    }

    #[Test]
    public function every_column_with_a_foreign_key_to_biog_main_gets_a_person_picker(): void {
        $expected = [
            'c_personid', 'c_assoc_id', 'c_kin_id',
            'c_assoc_kin_id', 'c_tertiary_personid', 'c_assoc_claimer_id',
        ];

        $this->editAssoc()
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($expected) {
                $b = $page->toArray()['props']['column_behaviour'];
                foreach ($expected as $col) {
                    $this->assertArrayHasKey($col, $b, "{$col} 應有逐欄行為");
                    $this->assertSame('person', $b[$col]['picker']['kind'], "{$col} 應為人物選擇器");
                    $this->assertSame('/api/select/search/biog', $b[$col]['picker']['endpoint']);
                }
            });
    }

    #[Test]
    public function picker_covers_the_columns_the_legacy_name_based_rule_missed(): void {
        // 舊版只認欄名 c_personid／c_kin_id，於是社會關係的「對方是誰」等四欄只能填數字。
        $missedByLegacy = ['c_assoc_id', 'c_assoc_kin_id', 'c_tertiary_personid', 'c_assoc_claimer_id'];

        $this->editAssoc()
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($missedByLegacy) {
                $b = $page->toArray()['props']['column_behaviour'];
                foreach ($missedByLegacy as $col) {
                    $this->assertSame('person', $b[$col]['picker']['kind'], "{$col} 是舊版漏掉的人物欄");
                }
            });
    }

    #[Test]
    public function non_person_columns_get_no_picker(): void {
        $this->editAssoc()
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                foreach (['c_assoc_code', 'c_notes'] as $col) {
                    $this->assertArrayNotHasKey($col, $b, "{$col} 不該有人物選擇器");
                }
            });
    }

    #[Test]
    public function picker_carries_the_current_persons_display_name(): void {
        // 否則使用者只看到一個數字，等同沒修。
        $this->editAssoc()
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                // 標籤帶 ID：改成選擇器後欄位不再顯示數字，而編目者是以 ID 工作的
                $this->assertSame('11 晁公武 / Chao Gongwu', $b['c_personid']['picker']['label']);
                $this->assertSame('47509 陳善 / Chen Shan', $b['c_assoc_id']['picker']['label']);
                // person 0 是「未詳」哨兵，BIOG_MAIN 確實有這一列，照樣解析出名稱
                $this->assertSame('0 未詳 / Weixiang', $b['c_kin_id']['picker']['label']);
            });
    }

    #[Test]
    public function picker_label_is_null_only_when_the_column_has_no_value(): void {
        $this->editAssoc()
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                // 欄位值為 null（未填）→ 沒有東西可顯示
                $this->assertNull($b['c_assoc_kin_id']['picker']['label']);
                // 人物存在但姓名兩欄皆空 → 退回顯示 ID，不可回傳空字串讓畫面看起來像沒選
                $this->assertSame('68294', $b['c_tertiary_personid']['picker']['label']);
            });
    }

    #[Test]
    public function picker_label_falls_back_to_the_id_when_the_person_is_missing(): void {
        // 有值就一定要有可顯示文字。若查不到人物卻回 null，CodeAutocomplete 會顯示空白，
        // 而 form.data 裡仍藏著那個值——使用者以為沒填、送出後撞外鍵，錯誤訊息還指錯方向。
        // 提案調整頁尤其會遇到（resource_data 裡的人物可能在送審後被合併掉）。
        DB::table('ASSOC_DATA')->where('c_personid', 11)->update(['c_assoc_kin_id' => 999111]);

        $this->editAssoc()
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                $this->assertSame('999111', $b['c_assoc_kin_id']['picker']['label']);
            });
    }

    // ───────── 「前往人物基本資料」連結（picker.exists／edit_url_template） ─────────

    #[Test]
    public function picker_reports_whether_the_referenced_person_exists(): void {
        // 前端據此決定要不要給跳轉連結、能不能把 label 當姓名用。查不到人物時 label 是退回的
        // 原始 ID，若照樣當姓名顯示就是 ID 假扮人名，連結也會開到 404。
        DB::table('ASSOC_DATA')->where('c_personid', 11)->update(['c_assoc_kin_id' => 999111]);

        $this->editAssoc()
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                $this->assertTrue($b['c_personid']['picker']['exists'], '真實人物 → 可跳轉');
                $this->assertFalse($b['c_assoc_kin_id']['picker']['exists'], '查不到的殘留 ID → 不可跳轉');
            });
    }

    #[Test]
    public function picker_exists_is_null_when_the_column_has_no_value(): void {
        // 沒有值就沒有「存在與否」可談；不可回 false 讓它讀起來像「這個人不存在」。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.create', ['table_name' => 'ASSOC_DATA']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                $this->assertNull($b['c_assoc_id']['picker']['exists']);
            });
    }

    #[Test]
    public function picker_ships_a_person_edit_url_template(): void {
        // URL 由後端產生、前端只換 __ID__；元件不寫死 /app/ 路徑。
        // 斷言字面值而非再呼叫 person_page_url()——拿同一個 helper 比對自己等於什麼都沒鎖。
        config(['migration_flags.pages.basicinformation.editor' => 'new']);

        $this->editAssoc()
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $template = $page->toArray()['props']['column_behaviour']['c_personid']['picker']['edit_url_template'];
                $this->assertSame('/app/basicinformation/__ID__/edit', $template);
            });
    }

    // ───────── 列表頁（app.codes.show）：人物欄的 ID 本身可跳轉 ─────────

    #[Test]
    public function listing_page_marks_the_person_columns_and_ships_the_url_template(): void {
        // 沒有這兩個 prop，列表就只能把 c_personid 印成一整欄數字（要查人得先點「編輯」）。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.show', ['table_name' => 'ASSOC_DATA']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $props = $page->toArray()['props'];
                // 順序跟著 thead（畫面欄序），不受外鍵反射順序影響
                $this->assertSame(
                    ['c_personid', 'c_assoc_id', 'c_kin_id', 'c_assoc_kin_id', 'c_tertiary_personid', 'c_assoc_claimer_id'],
                    $props['person_fk_columns'],
                );
                $this->assertSame(
                    array_values(array_intersect($props['thead'], $props['person_fk_columns'])),
                    $props['person_fk_columns'],
                );
                $this->assertSame('/app/basicinformation/__ID__/edit', $props['person_edit_url_template']);
            });
    }

    #[Test]
    public function listing_page_marks_no_person_columns_for_a_table_without_them(): void {
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.show', ['table_name' => 'TEST_PLAIN_CODES']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('person_fk_columns', []));
    }

    #[Test]
    public function listing_page_does_not_mark_a_person_named_column_without_a_foreign_key(): void {
        // 與表單頁同一條判準：沒有外鍵就不是人物欄（MERGED_PERSON_DATA 的人物可能已不存在）。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.show', ['table_name' => 'MERGED_PERSON_DATA']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('person_fk_columns', []));
    }

    #[Test]
    public function person_edit_url_template_follows_the_editor_migration_flag(): void {
        // flag 翻回 old 時，碼表的人物連結必須跟著回到 legacy 編輯頁，
        // 不能只有這裡還指著 React 版（這正是不在元件裡寫死路徑的原因）。
        config(['migration_flags.pages.basicinformation.editor' => 'old']);

        $this->editAssoc()
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $template = $page->toArray()['props']['column_behaviour']['c_personid']['picker']['edit_url_template'];
                $this->assertSame('/basicinformation/__ID__/edit', $template);
            });
    }

    #[Test]
    public function create_page_does_not_prefill_a_person_primary_key_with_a_guessed_id(): void {
        // guessNextKeyValue 對人物欄毫無意義（max(c_personid)+1），而 CBDB 人物 ID 很密集，
        // 猜測值往往真的存在 → 選擇器會顯示一位真實人物姓名，看起來像「已選好某人」，
        // 使用者填完其他欄一存就把資料歸到隨機的人身上。必須留空。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.create', ['table_name' => 'ASSOC_DATA']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $props = $page->toArray()['props'];
                $defaults = (array) $props['defaults'];
                $this->assertArrayNotHasKey('c_personid', $defaults, '人物主鍵欄不可預填猜測值');
                // 沒有預填 → 沒有東西可解析 → label 為 null（欄位如實顯示為空）
                $this->assertNull($props['column_behaviour']['c_personid']['picker']['label']);
            });
    }

    #[Test]
    public function column_named_like_a_person_but_without_a_foreign_key_gets_no_picker(): void {
        // MERGED_PERSON_DATA.c_personid 沒有外鍵 → 依規則 B 刻意不給（使用者已確認此取捨符合設計）。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'MERGED_PERSON_DATA', 'id' => 11]))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                $this->assertArrayNotHasKey('c_personid', $b, '無外鍵的人物欄不給選擇器');
            });
    }

    #[Test]
    public function table_without_person_columns_has_no_picker(): void {
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'TEST_PLAIN_CODES', 'id' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('column_behaviour', []));
    }

    #[Test]
    public function create_page_also_gets_pickers_without_a_resolved_label(): void {
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.create', ['table_name' => 'ASSOC_DATA']))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];
                $this->assertSame('person', $b['c_assoc_id']['picker']['kind']);
                // 新增頁尚無值 → 不該憑空生出一個人名（測試名稱講的正是這件事）
                $this->assertNull($b['c_assoc_id']['picker']['label']);
            });
    }

    // ───────── 「未詳」哨兵：/api/select/search/biog 把 person 0 編成 -999 ─────────

    #[Test]
    public function selecting_the_unknown_person_is_stored_as_zero_not_minus_999(): void {
        // 人物搜尋端點刻意把 person 0（未詳）的 option value 編成 -999（ApiController::searchBiog）。
        // 那是前端「未設定」哨兵、不是人物 ID；BIOG_MAIN 沒有 -999，直接落庫會撞外鍵。
        // 「未詳」在 CBDB 極常見，這條路徑必須通。
        $this->actingAs($this->activeUser())
            ->patch(route('app.codes.update', ['table_name' => 'ASSOC_DATA', 'id' => '11_._47509_._1']), [
                'c_personid' => 11, 'c_assoc_id' => 47509, 'c_assoc_code' => 1,
                'c_kin_id' => '-999', 'c_tertiary_personid' => '-999', 'c_notes' => 'x',
            ])
            ->assertRedirect();

        $row = DB::table('ASSOC_DATA')->where('c_personid', 11)->where('c_assoc_id', 47509)->first();
        $this->assertSame(0, (int) $row->c_kin_id, 'c_kin_id 應還原為 person 0（未詳）');
        $this->assertSame(0, (int) $row->c_tertiary_personid);
    }

    #[Test]
    public function minus_999_is_left_alone_when_it_is_a_real_person(): void {
        // c_personid 是有號 int、無 UNSIGNED／CHECK 限制，schema 允許負值（現行資料無）。
        // 若真有 person -999，把它改寫成 0 會把關係靜默改指到別人身上——寧可停止改寫。
        DB::table('BIOG_MAIN')->insert(['c_personid' => -999, 'c_name_chn' => '負號人物', 'c_name' => 'Negative']);

        $this->actingAs($this->activeUser())
            ->patch(route('app.codes.update', ['table_name' => 'ASSOC_DATA', 'id' => '11_._47509_._1']), [
                'c_personid' => 11, 'c_assoc_id' => 47509, 'c_assoc_code' => 1,
                'c_tertiary_personid' => '-999', 'c_notes' => 'x',
            ])
            ->assertRedirect();

        $row = DB::table('ASSOC_DATA')->where('c_personid', 11)->where('c_assoc_id', 47509)->first();
        $this->assertSame(-999, (int) $row->c_tertiary_personid, '-999 是真實人物時不可被改寫成 0');
    }

    #[Test]
    public function minus_999_is_not_normalized_on_non_person_columns(): void {
        // 只歸一化人物欄。-999 在其他欄位可能是合法值（如年份哨兵），不可一律改寫。
        $this->actingAs($this->activeUser())
            ->patch(route('app.codes.update', ['table_name' => 'ASSOC_DATA', 'id' => '11_._47509_._1']), [
                'c_personid' => 11, 'c_assoc_id' => 47509, 'c_assoc_code' => 1,
                'c_year' => '-999', 'c_notes' => 'x',
            ])
            ->assertRedirect();

        $row = DB::table('ASSOC_DATA')->where('c_personid', 11)->where('c_assoc_id', 47509)->first();
        $this->assertSame(-999, (int) $row->c_year, '非人物欄的 -999 應原樣保留');
    }

    #[Test]
    public function proposal_records_zero_instead_of_minus_999(): void {
        // 提案路徑不碰資料表，若不歸一化就會把 -999 存進 resource_data：
        // 審核人看到 -999，核准時才爆掉。
        // 用 c_tertiary_personid（fixture 為 68294）而非 c_kin_id（本來就是 0）——
        // 後者歸一化後與原值相同，會被正確判定為「沒有變更」而不產生提案。
        $this->actingAs($this->activeUser())
            ->post(route('app.codes.propose.update', ['table_name' => 'ASSOC_DATA', 'id' => '11_._47509_._1']), [
                'c_personid' => 11, 'c_assoc_id' => 47509, 'c_assoc_code' => 1,
                'c_kin_id' => 0, 'c_tertiary_personid' => '-999', 'c_notes' => 'x',
            ])
            ->assertRedirect();

        $op = DB::table('operations')->where('resource', 'ASSOC_DATA')->orderByDesc('id')->first();
        $this->assertNotNull($op, '應記下提案');
        $payload = json_decode($op->resource_data, true);
        $this->assertSame('0', (string) $payload['c_tertiary_personid'], '提案內容不該留下 -999');

        // 資料表本身不該被動到（提案不是直接寫入）
        $this->assertSame(
            68294,
            (int) DB::table('ASSOC_DATA')->where('c_personid', 11)->value('c_tertiary_personid')
        );
    }

    #[Test]
    public function person_picker_and_readonly_audit_columns_do_not_clobber_each_other(): void {
        // 兩段行為（人物選擇器、稽核欄唯讀）寫進同一份 behaviour 陣列。若其中一段用覆寫而非
        // merge，後跑的那段會把前一段的結果吃掉。用同時具備兩類欄位的表把這條性質釘住。
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'TEST_PERSON_AUDIT', 'id' => 11]))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $b = $page->toArray()['props']['column_behaviour'];

                // 人物欄：有選擇器、且不該被誤標唯讀
                $this->assertSame('person', $b['c_personid']['picker']['kind']);
                $this->assertArrayNotHasKey('readonly', $b['c_personid']);

                // 稽核欄：唯讀、且不該被誤給選擇器
                foreach (['c_created_by', 'c_modified_by'] as $col) {
                    $this->assertTrue($b[$col]['readonly']);
                    $this->assertArrayNotHasKey('picker', $b[$col]);
                }
                // 稽核欄的替換預覽仍在（沒被人物欄那段蓋掉）
                $this->assertStringContainsString('張三', $b['c_modified_by']['hint']['text']);
            });
    }
}
