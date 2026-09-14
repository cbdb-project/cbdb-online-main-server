<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CharVariantMapService;
use App\Support\VariantReplaceScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsPinyinDictionary;
use Tests\TestCase;

/**
 * ⚠️ **環節 4b-1 收斂**：`listRouteName()` 原本依 `$request->is('app/*')` 二選一，現在**一律**
 * 回傳 `app.admin.*`。理由：環節 3 把 legacy GET 封成 302 之後，legacy POST 完成 → redirect 到
 * legacy 列表 → 再被封路 302 ⇒ 多一跳，而 `laracasts/flash` 只活一個請求，所以匯入結果的
 * 成功／失敗提示會被 session 老化掉、**靜默消失**。
 *
 * 所以本檔的 `assertRedirect(route('admin.batch-load-offices'))` 都改成 `app.admin.batch-load-offices`
 * ——那是收斂**刻意造成**的行為變化，不是回歸。
 *
 * ── 2026-09-15（Blade 下架環節 4b-3）─────────────────────────────
 * 本檔**全部改打 React 端**（`/app/admin/batch-load-offices`），`#[Group('legacy-parity')]`
 * 與 `useLegacyBladePages()` 一併移除。
 *
 * 寫入端（store／undo／update-pinyin）legacy 與 app 兩條路由**指向同一個 controller 方法**，
 * 所以那些測試只是 route name 換前綴、斷言一字未改。
 *
 * 需要改斷言的是**結果頁**：原本 Blade 渲染、用 `assertSee` 掃 HTML；React 版把同一份
 * 資料放進 `results`／`batch_errors`／`batch_id` props。
 * ⚠️ **結果頁不可用 assertSee**：Inertia 把 props JSON 化時會把非 ASCII escape 成
 * `\\uXXXX`，中文一個都掃不到（本檔既有註解早就踩過一次）。
 */
class AdminBatchLoadOfficesTest extends TestCase {
    use SeedsPinyinDictionary;

    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->string('avatar')->nullable();
            $table->json('settings')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->integer('c_dy')->nullable();
            $table->string('c_office_pinyin')->nullable();
            $table->string('c_office_trans')->nullable();
            $table->string('c_office_chn')->nullable();
            $table->string('c_office_pinyin_alt')->nullable();
            $table->string('c_office_chn_alt')->nullable();
            $table->string('c_office_trans_alt')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::create('OFFICE_CODE_TYPE_REL', function (Blueprint $table) {
            $table->integer('c_office_id');
            $table->string('c_office_tree_id');
        });

        Schema::create('OFFICE_TYPE_TREE', function (Blueprint $table) {
            $table->string('c_office_type_node_id')->primary();
            $table->string('c_office_type_desc_chn')->nullable();
        });

        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });

        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->default(0);
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->longText('resource_data');
            $table->longText('resource_original')->nullable();
            $table->timestamps();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
        });

        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn');
            $table->string('c_pinyin')->nullable();
            $table->tinyInteger('c_lastname')->default(0);
            $table->unique(['c_chn', 'c_lastname']);
        });

        // OfficeImportService 經共用 recordOp 寫 operations + audit_log。
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->string('operation_id', 64);
            $table->text('row_pk');
            $table->string('row_pk_text', 512)->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
        });

        // 職官名逐字轉拼音走一般轉換路徑，需要真實字典資料才能跟現行
        // Pinyin::$dic 行為一致（見 docs/PINYIN_TABLE_CONSOLIDATION_PLAN.md 步驟4）。
        $this->seedPinyinDictionary();
    }

    protected function tearDown(): void {
        // char_variant_map 的清理放在這裡而不是各測試方法尾：斷言失敗時方法尾不會執行。
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('pinyin');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('DYNASTIES');
        Schema::dropIfExists('OFFICE_CODE_TYPE_REL');
        Schema::dropIfExists('OFFICE_CODES');
        Schema::dropIfExists('OFFICE_TYPE_TREE');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    protected function makeUser(array $attributes = []): User {
        $user = new User([
            'name' => 'Batch Admin',
            'email' => uniqid('admin', true).'@example.com',
            'password' => bcrypt('secret'),
            'avatar' => 'avatar0.png',
            'confirmation_token' => Str::random(10),
        ]);

        foreach ($attributes as $key => $value) {
            $user->{$key} = $value;
        }

        if (!isset($attributes['is_active'])) {
            $user->is_active = 1;
        }

        if (!isset($attributes['is_admin'])) {
            $user->is_admin = 1;
        }

        $user->save();

        return $user;
    }

    // ── Blade 下架環節 4b-3 的移植輔助 ──────────────────────────────
    //
    // 匯入結果頁原本是 Blade 渲染，所以測試用 assertSee 掃 HTML。React 版把同一份資料
    // 放進 `results` prop（來源是 `->with('batch_results', …)` 的 flash）。
    //
    // ⚠️ **不要對結果頁用 assertSee**：Inertia 把 props JSON 化時會把非 ASCII escape 成
    // `\uXXXX`，「宗人府供事」這種字串一個都掃不到（本檔既有註解已經踩過一次）。

    /** 打結果頁並取回 `results` prop。 */
    private function resultsProp(): array {
        $results = [];

        $this->get(route('app.admin.batch-load-offices'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$results) {
                // 也釘住元件：少了這行，結果頁被換成別的 Inertia 元件時這批測試會照樣
                // 讀 props 而不報錯（review 指出）。
                $page->component('Admin/BatchLoadOffices/Index');
                $results = array_map(fn ($row) => (array) $row, $page->toArray()['props']['results']);
            });

        return $results;
    }

    #[Test]
    public function test_admin_can_view_form(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        // 原本 assertSee 頁面標題（Blade 直出中文）。React 版標題由前端翻譯鍵渲染，
        // 伺服器端能負責的是「這個路由回的是那個 Inertia 頁、而且帶齊表單要用的 props」。
        $this->get(route('app.admin.batch-load-offices'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/BatchLoadOffices/Index')
                ->has('urls.store')
                ->has('urls.reset')
                ->where('results', [])
                ->where('batch_errors', []));
    }

    #[Test]
    public function test_admin_can_upload_office(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('DYNASTIES')->insert([
            'c_dy' => 20,
            'c_dynasty_chn' => '清',
        ]);

        DB::table('OFFICE_TYPE_TREE')->insert([
            'c_office_type_node_id' => '200501',
            'c_office_type_desc_chn' => '宗人府',
        ]);

        DB::table('TEXT_CODES')->insert([
            'c_textid' => 4763,
        ]);

        $response = $this->post(route('app.admin.batch-load-offices.store'), [
            'entries' => "宗人府供事\tClerk in the Imperial Clan Court\t清\t200501\t宗人府\t4763",
        ]);

        $response->assertRedirect(route('app.admin.batch-load-offices'));

        $record = DB::table('OFFICE_CODES')->first();
        $this->assertNotNull($record);
        $this->assertSame(1, (int) $record->c_office_id);
        $this->assertSame('宗人府供事', $record->c_office_chn);
        $this->assertSame('Clerk in the Imperial Clan Court', $record->c_office_trans);
        $this->assertSame('zong ren fu gong shi', $record->c_office_pinyin);
        $this->assertSame(4763, (int) $record->c_source);

        $this->assertDatabaseHas('OFFICE_CODE_TYPE_REL', [
            'c_office_id' => 1,
            'c_office_tree_id' => '200501',
        ]);

        $this->assertSame(2, DB::table('operations')->count());

        // 結果頁：原本逐字 assertSee，改成斷言 `results` prop 的那一列。
        // 比原本強——`assertSee('200501')` 只要那串數字出現在頁面任何地方就綠，
        // 這裡指名「`type_id` 這一欄就是 200501」。
        $results = $this->resultsProp();
        $this->assertCount(1, $results);
        $this->assertSame('宗人府供事', $results[0]['name']);
        $this->assertSame('Clerk in the Imperial Clan Court', $results[0]['translation']);
        $this->assertSame('zong ren fu gong shi', $results[0]['pinyin']);
        // 原本的 `assertSee('清 / 20')` 是 Blade 把兩欄拼成一格顯示；props 裡它們是兩欄。
        $this->assertSame('清', $results[0]['dynasty_label']);
        $this->assertSame(20, (int) $results[0]['dynasty_code']);
        $this->assertSame('200501', (string) $results[0]['type_id']);
        $this->assertSame('4763', (string) $results[0]['source_id']);
    }

    #[Test]
    public function test_unknown_type_is_rejected(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('DYNASTIES')->insert([
            'c_dy' => 20,
            'c_dynasty_chn' => '清',
        ]);

        DB::table('TEXT_CODES')->insert([
            'c_textid' => 4763,
        ]);

        $response = $this->post(route('app.admin.batch-load-offices.store'), [
            'entries' => "宗人府供事\tClerk in the Imperial Clan Court\t清\t999999\t宗人府\t4763",
        ]);

        $response->assertRedirect(route('app.admin.batch-load-offices'));
        $response->assertSessionHas('batch_errors');

        $this->assertSame(0, DB::table('OFFICE_CODES')->count());
    }
    // ── 異體字落地替換（plan S4）────────────────────────────

    /**
     * 與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php 同源的
     * 最小種子（只要「淸→清」）。其餘測試不建這張表，所以它們走
     * CharVariantMapService 的「表不存在就降級」路徑、行為不變。
     */
    protected function seedCharVariantMap(): void {
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();
            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ]);
        CharVariantMapService::reset();
        VariantReplaceScope::reset();
    }

    protected function seedOfficeLookups(string $dynastyLabel = '清'): void {
        DB::table('DYNASTIES')->insert(['c_dy' => 20, 'c_dynasty_chn' => $dynastyLabel]);
        DB::table('OFFICE_TYPE_TREE')->insert([
            'c_office_type_node_id' => '200501', 'c_office_type_desc_chn' => '宗人府',
        ]);
        DB::table('TEXT_CODES')->insert(['c_textid' => 4763]);
    }

    /** (a) 表格寫變體形「淸」、代碼表寫參考形「清」→ 應成功。 */
    #[Test]
    public function test_dynasty_label_in_variant_form_matches_reference_form_code_row(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser());
        $this->seedOfficeLookups('清');

        $this->post(route('app.admin.batch-load-offices.store'), [
            'entries' => "宗人府供事\tClerk\t淸\t200501\t宗人府\t4763",
        ])->assertRedirect(route('app.admin.batch-load-offices'));

        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => 1, 'c_dy' => 20]);
    }

    /**
     * (b) 表格寫參考形「清」、代碼表寫變體形「淸」→ 也要成功。
     * 只歸一傳入標籤修不到這個方向（輸入本來就是參考字），必須連 map 的鍵一起歸一。
     */
    #[Test]
    public function test_dynasty_label_in_reference_form_matches_variant_form_code_row(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser());
        $this->seedOfficeLookups('淸');

        $this->post(route('app.admin.batch-load-offices.store'), [
            'entries' => "宗人府供事\tClerk\t清\t200501\t宗人府\t4763",
        ])->assertRedirect(route('app.admin.batch-load-offices'));

        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => 1, 'c_dy' => 20]);
    }

    /**
     * 職名本身的異體字要落地替換，而且**必須早於拼音派生**——拼音是逐字查
     * `pinyin.c_chn`（該表被排除在替換範圍外、異體字保有自己讀音），先替換才會拿到
     * 參考字的讀音，中文欄與拼音欄才不會各說各話。結果頁另外顯示替換明細。
     */
    #[Test]
    public function test_office_name_variant_is_replaced_before_pinyin_derivation(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser());
        $this->seedOfficeLookups('清');

        $this->post(route('app.admin.batch-load-offices.store'), [
            'entries' => "淸吏司\tClerk\t清\t200501\t宗人府\t4763",
        ])->assertRedirect(route('app.admin.batch-load-offices'));

        $record = DB::table('OFFICE_CODES')->first();
        $this->assertSame('清吏司', $record->c_office_chn, '職名應以參考形落庫');
        $this->assertSame('qing li si', $record->c_office_pinyin, '拼音應由參考形派生');

        // 結果頁的 props 帶落庫後的職名與替換明細（比照書名匯入）。斷言直接讀 flash 資料
        // 而不是 assertSee：Inertia 把 props JSON 化時會把非 ASCII escape 成 \\uXXXX，
        // assertSee 對「淸」這種字元會落空。
        $results = session('batch_results');
        $this->assertIsArray($results);
        $this->assertSame('清吏司', $results[0]['name']);
        $this->assertSame([['from' => '淸', 'to' => '清']], $results[0]['variant_replacements']);
    }
}
