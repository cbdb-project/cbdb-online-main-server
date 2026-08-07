<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TEXT_CODES 編輯頁的作者清單（app.codes.edit → text_authors prop）。
 *
 * 這個區塊原本存在於舊版 codes/edit.blade.php（2022-01 #186、2025-12 #655），
 * 2026-06-26 React/Inertia 上線（3f131d6）時漏移植而消失；此測試鎖住補回後的行為，
 * 包含舊版三個缺陷的修正：N+1、paginate(100) 靜默截斷、未詳哨兵也給連結。
 */
class CodesTextAuthorsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-codes-text-authors';
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
        config(['codes.tables' => ['TEXT_CODES' => '書名代碼', 'TEST_PLAIN_CODES' => '無作者的表']]);

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
            ['c_textid' => 12497, 'c_title_chn' => '雪篷夜話', 'c_title' => 'xue peng ye hua'],
            ['c_textid' => 777, 'c_title_chn' => '無作者書', 'c_title' => 'wu zuo zhe shu'],
            ['c_textid' => 888, 'c_title_chn' => '多角色書', 'c_title' => 'duo jue se shu'],
            // c_textid=0 是真實可編輯的「未知」書目列（正式庫確實存在，title=未知）
            ['c_textid' => 0, 'c_title_chn' => '未知', 'c_title' => 'Weizhi'],
        ]);

        Schema::create('TEST_PLAIN_CODES', function ($table) {
            $table->integer('code_id')->primary();
            $table->string('description')->nullable();
        });
        DB::table('TEST_PLAIN_CODES')->insert(['code_id' => 1, 'description' => 'x']);

        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
        });
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 0, 'c_name_chn' => '未詳', 'c_name' => 'Weixiang'],
            ['c_personid' => 47509, 'c_name_chn' => '陳善', 'c_name' => 'Chen Shan'],
            ['c_personid' => 68294, 'c_name_chn' => '胡纘宗', 'c_name' => 'Hu Zuanzong'],
        ]);

        Schema::create('TEXT_ROLE_CODES', function ($table) {
            $table->integer('c_role_id')->primary();
            $table->string('c_role_desc')->nullable();
            $table->string('c_role_desc_chn')->nullable();
        });
        DB::table('TEXT_ROLE_CODES')->insert([
            ['c_role_id' => 0, 'c_role_desc' => 'unknown', 'c_role_desc_chn' => '未詳'],
            ['c_role_id' => 1, 'c_role_desc' => 'author', 'c_role_desc_chn' => '撰著者'],
            ['c_role_id' => 3, 'c_role_desc' => 'compiler', 'c_role_desc_chn' => '編纂者'],
        ]);

        Schema::create('BIOG_TEXT_DATA', function ($table) {
            $table->integer('c_textid');
            $table->integer('c_personid');
            $table->integer('c_role_id');
            $table->primary(['c_textid', 'c_personid', 'c_role_id']);
        });
        DB::table('BIOG_TEXT_DATA')->insert([
            // 使用者回報的那本書：單一作者
            ['c_textid' => 12497, 'c_personid' => 47509, 'c_role_id' => 0],
            // 同一人在同一本書掛兩個角色（真實資料存在，如 textid 14790 / personid 68294）
            ['c_textid' => 888, 'c_personid' => 68294, 'c_role_id' => 0],
            ['c_textid' => 888, 'c_personid' => 68294, 'c_role_id' => 3],
            // 未詳哨兵作者（c_personid=0），真實資料有 2569 筆
            ['c_textid' => 888, 'c_personid' => 0, 'c_role_id' => 1],
            // c_textid=0（「未知」書目）下掛的關係人：不得洩漏到其他書，但編輯該列時要看得到
            ['c_textid' => 0, 'c_personid' => 47509, 'c_role_id' => 1],
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

    /** 同一個測試可能發多次請求（如 displayLimit()），故取用既有帳號而非每次新建。 */
    private function activeUser(): User {
        return User::firstOrCreate(
            ['email' => 'u@example.com'],
            [
                'name' => 'U', 'password' => bcrypt('x'),
                'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
            ]
        );
    }

    private function editTextCode(int $textId) {
        return $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'TEXT_CODES', 'id' => $textId]));
    }

    /** 顯示上限取自受測程式自己回報的值，避免測試把 200 這個數字寫死。 */
    private function displayLimit(): int {
        $limit = null;
        $this->editTextCode(12497)->assertInertia(function (Assert $page) use (&$limit) {
            $limit = $page->toArray()['props']['text_authors']['limit'];
        });

        return (int) $limit;
    }

    #[Test]
    public function text_codes_edit_lists_the_author_with_a_link_to_that_person(): void {
        $this->editTextCode(12497)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Edit')
                ->where('text_authors.total', 1)
                ->has('text_authors.items', 1)
                ->where('text_authors.items.0.c_personid', 47509)
                ->where('text_authors.items.0.name_chn', '陳善')
                ->where('text_authors.items.0.name', 'Chen Shan')
                ->where('text_authors.items.0.role_chn', '未詳')
                ->where('text_authors.items.0.role', 'unknown')
                // 「直接跳轉到那個作者」：連到該作者的著述分頁
                ->where('text_authors.items.0.url', '/app/basicinformation/47509?tab=texts'));
    }

    #[Test]
    public function author_list_keeps_one_row_per_role_for_the_same_person(): void {
        // BIOG_TEXT_DATA 主鍵含 c_role_id：同一人的多個角色是不同列，不可被去重掉。
        $this->editTextCode(888)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('text_authors.total', 3)
                ->has('text_authors.items', 3)
                // 排序：c_personid 再 c_role_id
                ->where('text_authors.items.0.c_personid', 0)
                ->where('text_authors.items.1.c_personid', 68294)
                ->where('text_authors.items.1.c_role_id', 0)
                ->where('text_authors.items.2.c_personid', 68294)
                ->where('text_authors.items.2.c_role_id', 3)
                ->where('text_authors.items.2.role_chn', '編纂者'));
    }

    #[Test]
    public function unknown_person_sentinel_gets_no_link(): void {
        // c_personid=0 是「未詳」哨兵，跳過去沒有意義；舊版對它也給連結，這裡刻意不給。
        $this->editTextCode(888)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('text_authors.items.0.c_personid', 0)
                ->where('text_authors.items.0.url', null)
                ->where('text_authors.items.1.url', '/app/basicinformation/68294?tab=texts'));
    }

    #[Test]
    public function unknown_book_row_still_lists_its_own_relations(): void {
        // c_textid=0（「未知」書目）是真實可編輯的列，其下的關係人是「著作不明」的真實資料，
        // 編目者要看得到才能重新歸屬——不可因為它是哨兵值就整區清空。
        $this->editTextCode(0)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('text_authors.total', 1)
                ->has('text_authors.items', 1)
                ->where('text_authors.items.0.c_personid', 47509));
    }

    #[Test]
    public function authors_do_not_leak_between_books(): void {
        // 12497 只有它自己的那一筆，不會撈到掛在 c_textid=0 底下的同一個人。
        $this->editTextCode(12497)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('text_authors.total', 1)
                ->where('text_authors.items.0.c_role_id', 0));
    }

    #[Test]
    public function book_without_authors_returns_empty_list(): void {
        $this->editTextCode(777)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('text_authors.total', 0)
                ->has('text_authors.items', 0));
    }

    #[Test]
    public function author_query_failure_degrades_instead_of_breaking_the_edit_page(): void {
        // appEdit 的 try/catch 在呼叫 textCodesAuthors() 之前就結束了，若不自行接住例外，
        // 這個唯讀參考區塊的 DB 失敗會把整個編輯頁打成 500、連編輯都做不了。
        // 舊版 AJAX 失敗時只是顯示紅字「載入失敗」、表單照樣可用，這裡必須維持同樣的爆炸半徑。
        Schema::drop('BIOG_TEXT_DATA');

        $this->editTextCode(12497)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Edit')
                ->where('text_authors.failed', true)
                ->has('text_authors.items', 0)
                // 表單本身仍完整可編輯
                ->where('values.c_textid', 12497)
                ->has('urls.update'));
    }

    #[Test]
    public function successful_author_query_is_not_marked_failed(): void {
        $this->editTextCode(12497)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('text_authors.failed', false)
                ->where('text_authors.truncated', false));
    }

    #[Test]
    public function list_exactly_at_the_limit_is_not_marked_truncated(): void {
        // 邊界：剛好等於上限不算截斷（多取一筆的判斷法不可 off-by-one）。
        $limit = $this->displayLimit();
        $rows = [];
        for ($i = 1; $i <= $limit - 1; $i++) { // 777 已有 0 筆；補到剛好 $limit
            $pid = 200000 + $i;
            DB::table('BIOG_MAIN')->insert(['c_personid' => $pid, 'c_name_chn' => 'Q' . $i, 'c_name' => 'Q' . $i]);
            $rows[] = ['c_textid' => 777, 'c_personid' => $pid, 'c_role_id' => 1];
        }
        DB::table('BIOG_MAIN')->insert(['c_personid' => 200000, 'c_name_chn' => 'Q0', 'c_name' => 'Q0']);
        $rows[] = ['c_textid' => 777, 'c_personid' => 200000, 'c_role_id' => 1];
        DB::table('BIOG_TEXT_DATA')->insert($rows);

        $this->editTextCode(777)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('text_authors.total', $limit)
                ->has('text_authors.items', $limit)
                ->where('text_authors.truncated', false));
    }

    #[Test]
    public function other_code_tables_get_no_author_prop(): void {
        $this->actingAs($this->activeUser())
            ->get(route('app.codes.edit', ['table_name' => 'TEST_PLAIN_CODES', 'id' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Edit')
                ->where('text_authors', null));
    }

    #[Test]
    public function author_list_is_capped_and_reports_the_true_total(): void {
        // 舊版端點 paginate(100) 會靜默截斷；改為回報真實總數 + 上限，讓前端能明示。
        // 上限值由 prop 自己回報（不硬寫 200），改常數時此測試會跟著走而非莫名轉紅。
        $limit = $this->displayLimit();
        $overflow = 5;

        // 刻意以「c_personid 遞減」的順序插入，讓「有排序」與「照插入順序」在結果上可區分：
        // 若少了 ORDER BY，SQLite 會回插入順序（大的 personid 在前），下面的邊界斷言就會失敗。
        $rows = [];
        for ($i = $limit + $overflow; $i >= 1; $i--) {
            $pid = 100000 + $i;
            DB::table('BIOG_MAIN')->insert(['c_personid' => $pid, 'c_name_chn' => 'P' . $i, 'c_name' => 'P' . $i]);
            $rows[] = ['c_textid' => 777, 'c_personid' => $pid, 'c_role_id' => 1];
        }
        DB::table('BIOG_TEXT_DATA')->insert($rows);

        $this->editTextCode(777)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('text_authors.total', $limit + $overflow)
                ->where('text_authors.limit', $limit)
                ->has('text_authors.items', $limit)
                // truncated 由後端多取一筆判斷，不是前端比較 total 與 items 長度（見 CodesController）
                ->where('text_authors.truncated', true)
                // 截斷取的必須是「排序後的前 N 筆」而非任意 N 筆：
                // 最小的 personid 在首、被切掉的是最大的那幾個。
                ->where('text_authors.items.0.c_personid', 100001)
                ->where('text_authors.items.' . ($limit - 1) . '.c_personid', 100000 + $limit));
    }

    #[Test]
    public function author_list_is_ordered_by_person_then_role(): void {
        // 排序是截斷可預測的前提，且不可依賴資料庫的插入順序。以亂序插入後斷言完整順序。
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 500, 'c_name_chn' => 'A', 'c_name' => 'A'],
            ['c_personid' => 100, 'c_name_chn' => 'B', 'c_name' => 'B'],
        ]);
        DB::table('BIOG_TEXT_DATA')->insert([
            ['c_textid' => 777, 'c_personid' => 500, 'c_role_id' => 1],
            ['c_textid' => 777, 'c_personid' => 100, 'c_role_id' => 3],
            ['c_textid' => 777, 'c_personid' => 500, 'c_role_id' => 0],
            ['c_textid' => 777, 'c_personid' => 100, 'c_role_id' => 1],
        ]);

        $this->editTextCode(777)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('text_authors.items', 4)
                ->where('text_authors.items.0.c_personid', 100)
                ->where('text_authors.items.0.c_role_id', 1)
                ->where('text_authors.items.1.c_personid', 100)
                ->where('text_authors.items.1.c_role_id', 3)
                ->where('text_authors.items.2.c_personid', 500)
                ->where('text_authors.items.2.c_role_id', 0)
                ->where('text_authors.items.3.c_personid', 500)
                ->where('text_authors.items.3.c_role_id', 1));
    }

    #[Test]
    public function person_missing_from_biog_main_still_renders_a_row(): void {
        // LEFT JOIN 落空（BIOG_MAIN 無此人）時不可整列消失——舊版會把 null 當物件用而印出殘缺文字。
        // 姓名回傳 null，由前端以 author_unknown_person 顯示。
        DB::table('BIOG_TEXT_DATA')->insert(['c_textid' => 777, 'c_personid' => 999111, 'c_role_id' => 1]);

        $this->editTextCode(777)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('text_authors.total', 1)
                ->has('text_authors.items', 1)
                ->where('text_authors.items.0.c_personid', 999111)
                ->where('text_authors.items.0.name_chn', null)
                ->where('text_authors.items.0.name', null)
                // 仍給連結：這是資料缺漏，人物頁本身可能存在（或讓使用者看到 404 也比整列消失好）
                ->where('text_authors.items.0.url', '/app/basicinformation/999111?tab=texts'));
    }

    #[Test]
    public function role_missing_from_role_codes_still_renders_a_row(): void {
        DB::table('BIOG_TEXT_DATA')->insert(['c_textid' => 777, 'c_personid' => 47509, 'c_role_id' => 4242]);

        $this->editTextCode(777)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('text_authors.items', 1)
                ->where('text_authors.items.0.c_role_id', 4242)
                ->where('text_authors.items.0.role_chn', null)
                ->where('text_authors.items.0.role', null));
    }
}
