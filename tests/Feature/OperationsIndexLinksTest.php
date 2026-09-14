<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Support\EntityAggregateRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ── 2026-09-14（Blade 下架環節 4a-2）─────────────────────────────
 *
 * 本檔 HEAD 有 28 條，其中 **17 條本次完全沒動**——它們透過 `firstResourceLink()`／`firstRow()`
 * 打 `/app/operations` 並讀 Inertia props，本來就是 React 測試，只是住在這個 legacy 檔、
 * 被 setUp 的 `useLegacyBladePages()` 連坐。整檔刪掉會毀掉它們，這是本次分流最大的陷阱。
 *
 * 動到的是 11 條：9 條原本打 legacy `/operations` 並用 `assertSee('href="..."')` 驗 HTML，
 * 已全部改成打 `/app/operations` 並對 `resource_link`／`people[]`／`audit_logs[]`／`user_name`
 * 做精確比對——比原本的 HTML 子字串斷言更強（`assertSee('/codes/...')` 會被
 * `/app/codes/...` 同時命中，等於什麼都沒釘住）。
 *
 * 加上 1 條只加了一行、1 條被刪（同義反覆，見下方註解）。
 *
 * 唯一還需要 kill switch 的是 `test_legacy_codes_edit_page_resolves_the_right_composite_row`
 * ——它從原本那條的後半段拆出來，要開 legacy codes 編輯頁確認 id 解析，而那個頁面屬
 * **環節 4b**。它是本檔唯一仍掛 `#[Group('legacy-parity')]` 的測試。
 */
class OperationsIndexLinksTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        $this->app['env'] = 'testing';
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');

        // 使用 SQLite 内存数据库
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 创建测试所需的最小化表结构
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

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->nullable();
            $table->tinyInteger('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->tinyInteger('crowdsourcing_status')->default(0);
            $table->timestamps();
        });

        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->timestamps();
        });

        Schema::create('POSTED_TO_OFFICE_DATA', function ($table) {
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
            $table->integer('c_personid')->nullable();
            $table->integer('c_fy_intercalary')->nullable();
        });

        Schema::create('NIAN_HAO', function ($table) {
            $table->integer('c_nianhao_id')->primary();
            $table->string('c_nianhao_chn')->nullable();
        });

        // 複合主鍵的代碼表：resource_id 以 query-string 格式儲存，而 codes 編輯頁的 path id
        // 只認 '_._'。用來釘住「操作紀錄的查閱連結必須真的打得開」。
        Schema::create('MERGED_PERSON_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_merged_from_personid');
            $table->text('c_notes')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->primary(['c_personid', 'c_merged_from_personid']);
        });

        Schema::create('KIN_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id');
            $table->integer('c_kin_code');
        });

        Schema::create('ASSOC_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code');
            $table->integer('c_assoc_id');
            $table->integer('c_kin_code');
            $table->integer('c_kin_id');
            $table->integer('c_assoc_kin_code');
            $table->integer('c_assoc_kin_id');
            $table->string('c_text_title')->nullable();
            $table->integer('c_assoc_first_year')->nullable();
        });

        // 被實體聚合封寫的下層表：泛用 codes 編輯頁對它們是死路，連結必須改指實體頁。
        // 建到「實體編輯頁真的能渲染」的程度，才能斷言連結不只形狀對、而是真的打得開。
        Schema::create('OFFICE_CODES', function ($table) {
            $table->integer('c_office_id')->primary();
            $table->string('c_office_chn')->nullable();
            $table->string('c_office_chn_alt')->nullable();
            $table->string('c_office_trans')->nullable();
            $table->string('c_office_trans_alt')->nullable();
            $table->string('c_office_pinyin')->nullable();
            $table->string('c_office_pinyin_alt')->nullable();
            $table->integer('c_dy')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::create('OFFICE_CODE_TYPE_REL', function ($table) {
            $table->integer('c_office_id');
            $table->integer('c_office_tree_id');
            $table->primary(['c_office_id', 'c_office_tree_id']);
        });

        Schema::create('OFFICE_TYPE_TREE', function ($table) {
            $table->integer('c_office_type_node_id')->primary();
            $table->string('c_office_type_desc_chn')->nullable();
        });

        Schema::create('DYNASTIES', function ($table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });

        Schema::create('TEXT_CODES', function ($table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title')->nullable();
        });

        Schema::create('SOCIAL_INSTITUTION_CODES', function ($table) {
            $table->integer('c_inst_code');
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_type_code')->nullable();
            $table->primary(['c_inst_code', 'c_inst_name_code']);
        });

        Schema::create('SOCIAL_INSTITUTION_NAME_CODES', function ($table) {
            $table->integer('c_inst_name_code')->primary();
            $table->string('c_inst_name_hz')->nullable();
            $table->string('c_inst_name_py')->nullable();
        });

        Schema::create('SOCIAL_INSTITUTION_ADDR', function ($table) {
            $table->integer('c_inst_addr_id');
            $table->integer('c_inst_addr_type_code');
            $table->integer('c_inst_code');
            $table->integer('c_inst_name_code');
            $table->float('inst_xcoord')->nullable();
            $table->float('inst_ycoord')->nullable();
        });

        Schema::create('audit_log', function ($table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->char('operation_id', 26);
            $table->json('row_pk');
            $table->string('row_pk_text', 512);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });
    }

    #[Test]
    public function test_operations_index_generates_links_for_non_person_code_resources() {
        // 連結形狀依 codes flag 而定，明確釘住，別讓別人翻 flag 就變紅。
        config(['migration_flags.pages.codes' => 'new']);

        // 创建测试用戶
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 创建不涉及人物的 TEXT_CODES 操作记录
        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE_FULL, // 整體覆寫
            'resource' => 'TEXT_CODES',
            'resource_id' => '68942',
            'resource_data' => json_encode(['c_textid' => 68942, 'c_text_name' => 'Test Text']),
            'resource_original' => json_encode(['c_textid' => 68942, 'c_text_name' => 'Old Text']),
            'crowdsourcing_status' => 0,
        ]);

        // codes flag=new → resource_link 指 React 版（含 /app 前綴）。
        // 原本用 assertSee('href="/app/codes/..."') 驗 HTML；改成精確比對 prop 更強——
        // assertSee('/codes/...') 本來會被 '/app/codes/...' 同時命中，等於什麼都沒釘住。
        $row = $this->firstRow($user);
        $this->assertSame('/app/codes/TEXT_CODES/68942/edit', $row['resource_link']);
        // 這筆修改不涉及人物：people[] 是單一「佔位項」（id 為 null），
        // 對應 Blade 原本的文案「(本修改不涉及人物)」。
        $people = $row['people'] ?? [];
        $this->assertCount(1, $people);
        $this->assertNull($people[0]['id'], '不涉及人物的操作，people 佔位項的 id 應為 null');
        $this->assertNull($people[0]['person_edit_url']);
    }

    /**
     * codes 編輯頁連結的 flag-aware 行為。
     *
     * ⚠️ 這條測試原本拿 `OFFICE_CODES` 當樣本，把 `/app/codes/OFFICE_CODES/803819/edit`
     * 釘成期望值——但那張表早在 #1174 就被封寫，那個 URL 點進去只會吃到「該代碼表為只讀」
     * 再被彈回列表。也就是說，測試在替一條死連結背書，而且因為它只斷言 helper 產出的**字串**、
     * 從不驗證那個 URL 打不打得開，所以修了 controller 也不會變紅。這正是那個 bug 活了兩個月
     * 沒被發現的原因之一。
     *
     * 現在改用**未封寫**的表測 flag-aware（那才是這條測試真正的主題）；封寫表的連結行為
     * 由下面的 test_closed_* 系列負責，其中 office 兩條是真的把連結打開來驗。
     */
    #[Test]
    public function test_code_resource_link_generation_logic() {
        // 测试 codes 表配置是否正确
        $codeTableKeys = array_keys(config('codes.tables', []));
        $codeTables = array_map('strtoupper', $codeTableKeys);

        // 验证常见的代码表都在配置中
        $this->assertTrue(in_array('TEXT_CODES', $codeTables));
        $this->assertTrue(in_array('OFFICE_CODES', $codeTables));
        $this->assertTrue(in_array('OFFICE_CODE_TYPE_REL', $codeTables));
        $this->assertTrue(in_array('ADDR_CODES', $codeTables));
        $this->assertTrue(in_array('ALTNAME_DATA', $codeTables));

        // 验证链接生成逻辑（用未封寫的 ADDR_CODES，封寫表不該再走這條路徑）
        $resourceId = '803819';
        $resource = 'ADDR_CODES';
        $isCodeResource = in_array(strtoupper($resource), $codeTables);

        $this->assertTrue($isCodeResource);
        $this->assertFalse(
            EntityAggregateRegistry::isClosedByEntity($resource),
            '樣本表被封寫了：這條測試會再次替一條死連結背書，請換一張未封寫的表'
        );

        config(['migration_flags.pages.codes' => 'new']);
        $this->assertEquals(
            '/app/codes/ADDR_CODES/803819/edit',
            code_table_edit_url($resource, $resourceId)
        );

        config(['migration_flags.pages.codes' => 'old']);
        $this->assertEquals(
            '/codes/ADDR_CODES/803819/edit',
            code_table_edit_url($resource, $resourceId)
        );
    }

    #[Test]
    public function test_operations_index_normalizes_single_key_query_string_resource_id_for_code_links(): void {
        config(['migration_flags.pages.codes' => 'new']);

        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'nianhao-link@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'NIAN_HAO',
            'resource_id' => 'c_nianhao_id=464',
            'resource_data' => json_encode(['c_nianhao_id' => 464, 'c_nianhao_chn' => '測試年號']),
            'resource_original' => json_encode(['c_nianhao_id' => 464, 'c_nianhao_chn' => '舊年號']),
            'crowdsourcing_status' => 0,
        ]);

        \DB::table('NIAN_HAO')->insert([
            'c_nianhao_id' => 464,
            'c_nianhao_chn' => '測試年號',
        ]);

        // 單主鍵的 query-string resource_id（c_nianhao_id=464）要被正規化成 '464'
        // 才能組進 codes path，否則連出來的是 /codes/NIAN_HAO/c_nianhao_id=464/edit。
        $this->assertSame(
            '/app/codes/NIAN_HAO/464/edit',
            $this->firstResourceLink($user)
        );
    }

    /**
     * 複合主鍵代碼表（MERGED_PERSON_DATA）的查閱連結必須真的打得開。
     *
     * mutation handler 一律把 resource_id 存成 query-string 格式
     * （c_personid=108625&c_merged_from_personid=404794，見 CompositePrimaryKey::buildStoredResourceId），
     * 而 codes 編輯頁的 path id 歷來只認 '_._'。單主鍵的表已有
     * normalizeSingleKeyResourceIdForCodeRoute() 兜住，複合主鍵則整段字串被當成第一個主鍵欄的值，
     * 於是點「查閱」只會得到「找不到該筆資料」。
     */
    #[Test]
    public function test_composite_key_code_resource_view_link_actually_opens_the_row(): void {
        // 明確釘住 flag：codes flag 仍可被翻成 old（環節 3 封路後它只改變**連結指向**、
        // 不再讓 Blade 頁可開啟——而本測試驗的正是連結指向），若靠預設值，別人一翻 flag
        // 這條就變紅，而且連 id 解析的保證也一起失效。
        config(['migration_flags.pages.codes' => 'new']);

        $user = User::forceCreate([
            'name' => 'Hongsu Wang',
            'email' => 'merged-person-link@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        \DB::table('BIOG_MAIN')->insert([
            'c_personid' => 108625, 'c_name_chn' => '曾源友', 'c_name' => 'Zeng Yuanyou',
        ]);
        // 兩列共用同一個 c_personid，且「不是目標」的那一列先插入：只有真的用上第二段主鍵才會
        // 開出 404794。否則（例如只以 c_personid 查詢）會撈到 500001，測試就會當場失敗而不是
        // 靜默地變成一個什麼都沒驗到的 200。
        \DB::table('MERGED_PERSON_DATA')->insert([
            ['c_personid' => 108625, 'c_merged_from_personid' => 500001],
            ['c_personid' => 108625, 'c_merged_from_personid' => 404794],
        ]);

        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 108625,
            'op_type' => Operation::TYPE_CREATE,
            'resource' => 'MERGED_PERSON_DATA',
            'resource_id' => 'c_personid=108625&c_merged_from_personid=404794',
            'resource_data' => json_encode(['c_personid' => 108625, 'c_merged_from_personid' => 404794]),
            'crowdsourcing_status' => 0,
        ]);

        $link = null;
        $this->actingAs($user)
            ->get('/app/operations')
            ->assertOk()
            ->assertInertia(function ($page) use (&$link) {
                $link = $page->toArray()['props']['lists'][0]['resource_link'];
            });

        // 連結本身就是 bug 的一半：resource_id 原樣進了 path（單主鍵才會被正規化）。
        // 且 codes flag=new，連結要指向 React 版編輯頁，不該從 React 操作紀錄掉回舊 Blade 頁。
        $this->assertSame(
            '/app/codes/MERGED_PERSON_DATA/c_personid=108625&c_merged_from_personid=404794/edit',
            $link
        );

        // 另一半：那個 path id 必須真的能開出對應的列，而不是被重導回上一頁並
        // flash「找不到該筆資料」。同時確認用上了第二段主鍵（開出 404794、不是 500001）。
        $this->actingAs($user)->get($link)
            ->assertOk()
            ->assertSessionMissing('flash_notification')
            ->assertInertia(fn ($page) => $page
                ->component('Codes/Edit')
                ->where('values.c_personid', 108625)
                ->where('values.c_merged_from_personid', 404794));
    }

    #[Test]
    public function test_code_resource_view_link_falls_back_to_blade_when_codes_flag_is_old(): void {
        // codes flag 翻回 old 時，查閱連結要跟著回到 Blade 編輯頁。
        // 📌 本測試驗的是**連結指向**，不是「Blade 頁還能開」——環節 3 之後那個 Blade
        // 編輯頁會被 302 掉（見 LegacyBladePageRetirementTest）。這條斷言仍然有效
        // （flag 確實還控制連結指向），但理由不再是「否則回退不完整」。
        config(['migration_flags.pages.codes' => 'old']);

        $user = User::forceCreate([
            'name' => 'Hongsu Wang',
            'email' => 'merged-person-oldflag@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_CREATE,
            'resource' => 'MERGED_PERSON_DATA',
            'resource_id' => 'c_personid=108625&c_merged_from_personid=404794',
            'resource_data' => json_encode(['c_personid' => 108625]),
            'crowdsourcing_status' => 0,
        ]);

        \DB::table('MERGED_PERSON_DATA')->insert([
            ['c_personid' => 108625, 'c_merged_from_personid' => 500001],
            ['c_personid' => 108625, 'c_merged_from_personid' => 404794],
        ]);

        $link = null;
        $this->actingAs($user)
            ->get('/app/operations')
            ->assertOk()
            ->assertInertia(function ($page) use (&$link) {
                $link = $page->toArray()['props']['lists'][0]['resource_link'];
            });

        $this->assertSame(
            '/codes/MERGED_PERSON_DATA/c_personid=108625&c_merged_from_personid=404794/edit',
            $link
        );

    }

    /**
     * 上一條的後半段：legacy Blade codes 編輯頁要真的開出 404794 那一列（不是 500001）。
     *
     * ⚠️ **本檔唯一還耦合 legacy 頁的測試**，所以單獨掛 `#[Group('legacy-parity')]`——
     * 那個群組是專案文件化的「legacy 耦合測試一鍵清單」（計畫 §三第 15 欄）。
     * 若只在註解寫「環節 4b 要一併移除」，沒有任何機械化手段找得出它，
     * 屆時它會以「不在任何清單上的紅」出現，正是分流機制要避免的情況。
     *
     * legacy codes 編輯頁屬**環節 4b**（表單／寫入頁），本環節 4a 不刪它，故 per-test
     * 局部關閉封路。4b 刪該頁時，**整條測試**移除即可——連結指向的斷言已在上一條，
     * 這裡只驗「那個頁面對 id 的解析」。
     */
    #[Test]
    #[Group('legacy-parity')]
    public function test_legacy_codes_edit_page_resolves_the_right_composite_row(): void {
        config(['migration_flags.pages.codes' => 'old']);

        $user = User::forceCreate([
            'name' => 'Hongsu Wang',
            'email' => 'merged-person-legacy-edit@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        \DB::table('MERGED_PERSON_DATA')->insert([
            ['c_personid' => 108625, 'c_merged_from_personid' => 500001],
            ['c_personid' => 108625, 'c_merged_from_personid' => 404794],
        ]);

        $link = '/codes/MERGED_PERSON_DATA/c_personid=108625&c_merged_from_personid=404794/edit';

        $this->useLegacyBladePages();
        $this->actingAs($user)->get($link)
            ->assertOk()
            ->assertSessionMissing('flash_notification')
            ->assertSee('404794')
            ->assertDontSee('500001');
    }

    // 原本這裡有 test_person_specific_link_priority_logic：它自己用 if/else 組出
    // $resourceLink 再斷言自己的組法（同義反覆），唯一碰到生產碼的是 config('codes.tables')
    // 與一個永遠走不到的 route('codes.edit') 分支；而它斷言的 URL
    // `/basicinformation/{id}/altnames/{pk}/edit` 是**環節 2 就已刪除的路由**。
    // 連結優先序的真正覆蓋在同檔的 test_operations_index_renders_person_specific_resource_links_*
    // 三條（驗 people[].resource_link 的實際值），故隨環節 4a-2 移除。

    #[Test]
    public function test_operations_index_does_not_generate_links_for_deleted_operations() {
        // 创建测试用戶
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 创建删除类型的操作记录 (op_type = 4)
        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => 4, // 刪除
            'resource' => 'TEXT_CODES',
            'resource_id' => '68942',
            'resource_data' => json_encode(['c_textid' => 68942, 'c_text_name' => 'Deleted Text']),
            'resource_original' => null,
            'crowdsourcing_status' => 0,
        ]);

        // 刪除操作（op_type=4）不得產編輯連結——資源已經不在了。
        $row = $this->firstRow($user);
        $this->assertNull($row['resource_link'], 'op_type=4 不該有 resource_link');
        $this->assertFalse((bool) ($row['can_compare'] ?? false));
        // 但資源識別本身仍要傳下去（使用者要知道刪掉的是哪一筆）。
        // 列上沒有 resource_id prop；對外顯示的是 resource_description
        // （`$formatResourceDescription($resourceName, $rawResourceId)`）。
        $this->assertStringContainsString('68942', (string) ($row['resource_description'] ?? ''));
    }

    #[Test]
    public function test_operations_index_hides_editor_full_name_for_guests(): void {
        $user = User::forceCreate([
            'name' => 'Hidden Editor',
            'email' => 'guest-hidden@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'guest-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'TEXT_CODES',
            'resource_id' => '68942',
            'resource_data' => json_encode(['c_textid' => 68942, 'c_text_name' => 'Guest Visible']),
            'resource_original' => json_encode(['c_textid' => 68942, 'c_text_name' => 'Old Text']),
            'crowdsourcing_status' => 0,
        ]);

        // 訪客只看到 'User {id}'，看不到編輯者真名（隱私不變量，
        // OperationsController 的 `Auth::check() ? name : 'User '.id`）。
        // firstRow(null) 會先 Auth::logout()，確保這次真的是訪客請求。
        $row = $this->firstRow(null);
        $this->assertSame('User ' . $user->id, $row['user_name']);
        $this->assertStringNotContainsString('Hidden Editor', json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function test_operations_index_shows_multiple_audit_records_for_one_operation() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'audit@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => 'c_office_id=10&c_posting_id=1',
            'resource_data' => json_encode(['c_office_id' => 10, 'c_posting_id' => 1, 'c_fy_intercalary' => 1]),
            'resource_original' => json_encode(['c_office_id' => 10, 'c_posting_id' => 1, 'c_fy_intercalary' => 0]),
            'crowdsourcing_status' => 0,
        ]);

        \Illuminate\Support\Facades\DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_office_id' => 10,
            'c_posting_id' => 1,
            'c_personid' => 123,
            'c_fy_intercalary' => 1,
        ]);

        $operationId = (string) $operation->id;
        $now = now()->format('Y-m-d H:i:s');

        \Illuminate\Support\Facades\DB::table('audit_log')->insert([
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'POSTED_TO_OFFICE_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => $operationId,
                'row_pk' => json_encode(['c_office_id' => 10, 'c_posting_id' => 1], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_office_id=10&c_posting_id=1',
                'old_data' => json_encode(['c_fy_intercalary' => 0], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_fy_intercalary' => 1], JSON_UNESCAPED_UNICODE),
            ],
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'POSTED_TO_ADDR_DATA',
                'operation' => 'INSERT',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => $operationId,
                'row_pk' => json_encode(['c_personid' => 123, 'c_posting_id' => 1, 'c_office_id' => 10, 'c_addr_id' => 99], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=123&c_posting_id=1&c_office_id=10&c_addr_id=99',
                'old_data' => null,
                'new_data' => json_encode(['c_personid' => 123, 'c_posting_id' => 1, 'c_office_id' => 10, 'c_addr_id' => 99], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        // 一筆 operation 連動兩表時，兩筆 audit 都要列出（原本驗 Blade 文案「審計記錄（2 筆）」；
        // 筆數與表名才是不變量）。
        $row = $this->firstRow($user);
        $auditLogs = $row['audit_logs'] ?? [];
        $this->assertCount(2, $auditLogs);
        $this->assertSame(
            ['POSTED_TO_ADDR_DATA', 'POSTED_TO_OFFICE_DATA'],
            collect($auditLogs)->pluck('table_name')->sort()->values()->all()
        );
        $this->assertTrue((bool) ($row['has_audit_logs'] ?? false));
    }

    #[Test]
    public function test_operations_index_renders_composite_rows_for_multi_person_operation(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'multi-person@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 101, 'c_name_chn' => '甲', 'c_name' => 'Jia'],
            ['c_personid' => 202, 'c_name_chn' => '乙', 'c_name' => 'Yi'],
        ]);

        \Illuminate\Support\Facades\DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_office_id' => 10,
            'c_posting_id' => 1,
            'c_personid' => 101,
            'c_fy_intercalary' => 0,
        ]);

        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 101,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => 'c_office_id=10&c_posting_id=1',
            'resource_data' => json_encode(['c_office_id' => 10, 'c_posting_id' => 1, 'c_fy_intercalary' => 1], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode(['c_office_id' => 10, 'c_posting_id' => 1, 'c_fy_intercalary' => 0], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);

        $now = now()->format('Y-m-d H:i:s');
        \Illuminate\Support\Facades\DB::table('audit_log')->insert([
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=101&c_kin_id=202&c_kin_code=1',
                'old_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 2], JSON_UNESCAPED_UNICODE),
            ],
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=202&c_kin_id=101&c_kin_code=3',
                'old_data' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 4], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        // 一筆操作連動兩人時，兩人都要出現在 people[] 且各有人物編輯連結，並且恰有一個是主操作
        // （原本的 rowspan="2"／「主操作」／「連動」是 Blade 排版文案，不是不變量）。
        $people = $this->firstRow($user)['people'] ?? [];
        $this->assertSame([101, 202], collect($people)->pluck('id')->map('intval')->sort()->values()->all());
        foreach ($people as $person) {
            $this->assertNotNull($person['person_edit_url'] ?? null, '每個受影響人物都要有編輯連結');
        }
        $this->assertCount(1, array_filter($people, fn ($p) => (bool) ($p['is_primary'] ?? false)));
    }

    #[Test]
    public function test_operations_index_renders_person_specific_resource_links_for_kin_data(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'kin-links@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 101, 'c_name_chn' => '甲', 'c_name' => 'Jia'],
            ['c_personid' => 202, 'c_name_chn' => '乙', 'c_name' => 'Yi'],
        ]);

        \Illuminate\Support\Facades\DB::table('KIN_DATA')->insert([
            ['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1],
            ['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3],
        ]);

        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 101,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'KIN_DATA',
            'resource_id' => 'c_personid=101&c_kin_id=202&c_kin_code=1',
            'resource_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 2], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);

        $now = now()->format('Y-m-d H:i:s');
        \Illuminate\Support\Facades\DB::table('audit_log')->insert([
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=101&c_kin_id=202&c_kin_code=1',
                'old_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 2], JSON_UNESCAPED_UNICODE),
            ],
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=202&c_kin_id=101&c_kin_code=3',
                'old_data' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 4], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        // 兩側鏡像列各自連到**自己那一邊**的 edit-v2（帶完整 PK query）。
        // 原本驗 HTML（連 &amp; 實體都寫進斷言）；改成比對 people[].resource_link 的原始值——
        // 更精確，也不會因為 HTML 轉義方式改變而假紅／假綠。
        $people = $this->firstRow($user)['people'] ?? [];
        $this->assertSame([
            '/app/basicinformation/101/kinship/edit-v2?c_personid=101&c_kin_id=202&c_kin_code=1',
            '/app/basicinformation/202/kinship/edit-v2?c_personid=202&c_kin_id=101&c_kin_code=3',
        ], collect($people)->pluck('resource_link')->sort()->values()->all());

        // 資源描述要逐欄列出（使用者靠它分辨兩側是哪一列）。
        $descriptions = implode(' ', collect($people)->pluck('resource_description')->all());
        $this->assertStringContainsString('c_personid：101', $descriptions);
        $this->assertStringContainsString('c_kin_code：3', $descriptions);
    }

    #[Test]
    public function test_operations_index_renders_person_specific_resource_links_for_assoc_data(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'assoc-links@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 101, 'c_name_chn' => '甲', 'c_name' => 'Jia'],
            ['c_personid' => 202, 'c_name_chn' => '乙', 'c_name' => 'Yi'],
        ]);

        \Illuminate\Support\Facades\DB::table('ASSOC_DATA')->insert([
            [
                'c_personid' => 101,
                'c_assoc_code' => 301,
                'c_assoc_id' => 202,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => '測試文獻',
                'c_assoc_first_year' => 1100,
            ],
            [
                'c_personid' => 202,
                'c_assoc_code' => 302,
                'c_assoc_id' => 101,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => '測試文獻',
                'c_assoc_first_year' => 1100,
            ],
        ]);

        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 101,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ASSOC_DATA',
            'resource_id' => 'c_personid=101&c_assoc_code=301&c_assoc_id=202&c_kin_code=0&c_kin_id=0&c_assoc_kin_code=0&c_assoc_kin_id=0&c_text_title=%E6%B8%AC%E8%A9%A6%E6%96%87%E7%8D%BB&c_assoc_first_year=1100',
            'resource_data' => json_encode([
                'c_personid' => 101,
                'c_assoc_code' => 301,
                'c_assoc_id' => 202,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => '測試文獻',
                'c_assoc_first_year' => 1101,
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 101,
                'c_assoc_code' => 301,
                'c_assoc_id' => 202,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => '測試文獻',
                'c_assoc_first_year' => 1100,
            ], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);

        $now = now()->format('Y-m-d H:i:s');
        \Illuminate\Support\Facades\DB::table('audit_log')->insert([
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'ASSOC_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode([
                    'c_personid' => 101,
                    'c_assoc_code' => 301,
                    'c_assoc_id' => 202,
                    'c_kin_code' => 0,
                    'c_kin_id' => 0,
                    'c_assoc_kin_code' => 0,
                    'c_assoc_kin_id' => 0,
                    'c_text_title' => '測試文獻',
                    'c_assoc_first_year' => 1100,
                ], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=101&c_assoc_code=301&c_assoc_id=202&c_kin_code=0&c_kin_id=0&c_assoc_kin_code=0&c_assoc_kin_id=0&c_text_title=%E6%B8%AC%E8%A9%A6%E6%96%87%E7%8D%BB&c_assoc_first_year=1100',
                'old_data' => json_encode(['c_assoc_first_year' => 1100], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_assoc_first_year' => 1101], JSON_UNESCAPED_UNICODE),
            ],
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'ASSOC_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode([
                    'c_personid' => 202,
                    'c_assoc_code' => 302,
                    'c_assoc_id' => 101,
                    'c_kin_code' => 0,
                    'c_kin_id' => 0,
                    'c_assoc_kin_code' => 0,
                    'c_assoc_kin_id' => 0,
                    'c_text_title' => '測試文獻',
                    'c_assoc_first_year' => 1100,
                ], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=202&c_assoc_code=302&c_assoc_id=101&c_kin_code=0&c_kin_id=0&c_assoc_kin_code=0&c_assoc_kin_id=0&c_text_title=%E6%B8%AC%E8%A9%A6%E6%96%87%E7%8D%BB&c_assoc_first_year=1100',
                'old_data' => json_encode(['c_assoc_first_year' => 1100], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_assoc_first_year' => 1101], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        // ASSOC 的 9 欄 PK 版本，同 kinship 的形狀。
        $people = $this->firstRow($user)['people'] ?? [];
        // ⚠️ 原本的 legacy 斷言 `assertSee('...&c_assoc_id=202', false)` 只是**前綴**比對，
        // 實際 URL 帶完整 9 欄 PK。改成精確比對整串——順手揭露了原斷言過寬。
        $this->assertSame([
            '/app/basicinformation/101/assoc/edit-v2?c_personid=101&c_assoc_code=301&c_assoc_id=202'
                .'&c_kin_code=0&c_kin_id=0&c_assoc_kin_code=0&c_assoc_kin_id=0'
                .'&c_text_title=%E6%B8%AC%E8%A9%A6%E6%96%87%E7%8D%BB&c_assoc_first_year=1100',
            '/app/basicinformation/202/assoc/edit-v2?c_personid=202&c_assoc_code=302&c_assoc_id=101'
                .'&c_kin_code=0&c_kin_id=0&c_assoc_kin_code=0&c_assoc_kin_id=0'
                .'&c_text_title=%E6%B8%AC%E8%A9%A6%E6%96%87%E7%8D%BB&c_assoc_first_year=1100',
        ], collect($people)->pluck('resource_link')->sort()->values()->all());

        $descriptions = implode(' ', collect($people)->pluck('resource_description')->all());
        $this->assertStringContainsString('c_assoc_code：301', $descriptions);
        $this->assertStringContainsString('c_assoc_code：302', $descriptions);
    }

    #[Test]
    public function test_operations_index_does_not_render_person_specific_resource_links_for_deleted_kin_data(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'deleted-kin-links@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 101, 'c_name_chn' => '甲', 'c_name' => 'Jia'],
            ['c_personid' => 202, 'c_name_chn' => '乙', 'c_name' => 'Yi'],
        ]);

        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 101,
            'op_type' => Operation::TYPE_DELETE,
            'resource' => 'KIN_DATA',
            'resource_id' => 'c_personid=101&c_kin_id=202&c_kin_code=1',
            'resource_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
            'resource_original' => null,
            'crowdsourcing_status' => 0,
        ]);

        $now = now()->format('Y-m-d H:i:s');
        \Illuminate\Support\Facades\DB::table('audit_log')->insert([
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'DELETE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=101&c_kin_id=202&c_kin_code=1',
                'old_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'new_data' => null,
            ],
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'DELETE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=202&c_kin_id=101&c_kin_code=3',
                'old_data' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'new_data' => null,
            ],
        ]);

        // 刪除操作連 per-person 連結也不得產出（上一條的反面分支）。
        $row = $this->firstRow($user);
        $links = array_values(array_filter(collect($row['people'] ?? [])->pluck('resource_link')->all()));
        $this->assertSame([], $links, '刪除操作不該有任何 per-person 資源連結');
        $this->assertNull($row['resource_link'], '整列的 resource_link 也不該有');
    }

    // =====================================================================
    // 被實體聚合封寫的下層表：連結必須改指實體編輯頁
    //
    // #1174／ca601b0d 把 OFFICE_CODES、SOCIAL_INSTITUTION_* 在泛用 /codes 介面封寫，
    // 寫入收歸實體頁，但沒有動這裡的連結出口，於是每一條「查閱」都變成
    // 「點進去吃唯讀警告、再被彈回列表」。
    //
    // 兩條 office 案例**把連結打開來驗**（拿到 resource_link 後再 GET 它，斷言渲染出
    // Office/Edit）——只斷言字串形狀正是當初沒抓到這個 bug 的原因。其餘案例驗的是解析與
    // 授權結果本身（機構要靠 payload 補、名稱碼不得亂猜、提案要指向現存的列、訪客不得拿到
    // 必然 403 的連結）；`test_unclosed_code_tables_keep_using_the_generic_codes_editor`
    // 是**回歸護欄**——它在修復前後都該是綠的，作用是擋住「改指改過頭」。
    // =====================================================================

    /**
     * 已啟用帳號。$role 用 User::ROLE_*——`canWriteDirectly()` 判的是 is_admin 是否等於
     * ROLE_CROWDSOURCING(2)，寫成 0／1 的布林旗標只會得到「一般使用者」而測不到眾包帳號。
     *
     * @return \App\Models\User
     */
    private function activeUser(string $email, int $role = User::ROLE_EXPERT) {
        return User::forceCreate([
            'name' => 'Test User',
            'email' => $email,
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => $role,
        ]);
    }

    /**
     * 讀 /app/operations 第一列的 resource_link（提案要帶 proposals_only=1 才會列出）。
     *
     * $user 為 null 代表**訪客**：必須顯式登出，否則同一測試裡先前的 actingAs() 會延續下來，
     * 「訪客」那次請求會靜默變成第二次登入請求，斷言就什麼都沒驗到。
     */
    private function firstResourceLink($user = null, string $query = ''): ?string {
        return $this->firstRow($user, $query)['resource_link'];
    }

    /**
     * 讀 /app/operations 第一列序列化後的整列（resource_link／urls／can_compare…）。
     *
     * @return array<string, mixed>
     */
    private function firstRow($user = null, string $query = ''): array {
        $row = null;
        if ($user === null) {
            Auth::logout();
        }
        $request = $user ? $this->actingAs($user) : $this;
        $request->get('/app/operations' . $query)
            ->assertOk()
            ->assertInertia(function ($page) use (&$row) {
                $row = $page->toArray()['props']['lists'][0];
            });

        return $row;
    }

    /**
     * 一筆實體級提案（AbstractEntityAggregateHandler::storeProposal() 的 payload 形狀）。
     */
    private function entityProposal(User $user, string $resource, string $operation, ?int $pk, array $changes = [], array $extra = []): Operation {
        $opType = match ($operation) {
            'create' => Operation::TYPE_PROPOSAL_CREATE,
            'delete' => Operation::TYPE_PROPOSAL_DELETE,
            default => Operation::TYPE_PROPOSAL_UPDATE,
        };

        return Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => $opType,
            'resource' => $resource,
            'resource_id' => $pk !== null ? (string) $pk : '',
            'resource_data' => json_encode(array_merge([
                '__entity_aggregate' => true,
                '__entity_resource' => $resource,
                '__entity_operation' => $operation,
                '__entity_pk' => $pk,
                'changes' => $changes,
                '__proposal_meta' => ['submitted_by' => $user->name, 'submitted_by_id' => $user->id, 'submitted_at' => '2026-09-01 00:00:00'],
                '__review_status' => 'pending',
            ], $extra), JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);
    }

    #[Test]
    public function test_closed_office_code_link_opens_the_entity_editor_instead_of_the_read_only_codes_page(): void {
        config(['migration_flags.pages.codes' => 'new']);
        $user = $this->activeUser('office-entity-link@example.com');

        \DB::table('OFFICE_CODES')->insert([
            'c_office_id' => 12304, 'c_office_chn' => '知州', 'c_office_pinyin' => 'zhi zhou',
            'c_dy' => 15, 'c_source' => null,
        ]);
        \DB::table('OFFICE_CODE_TYPE_REL')->insert(['c_office_id' => 12304, 'c_office_tree_id' => 7]);
        \DB::table('OFFICE_TYPE_TREE')->insert(['c_office_type_node_id' => 7, 'c_office_type_desc_chn' => '地方官']);
        \DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);

        // 封寫前 codes UI 寫進去的裸值格式——線上壞掉的正是這批。
        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'OFFICE_CODES',
            'resource_id' => '12304',
            'resource_data' => json_encode(['c_office_id' => 12304, 'c_office_chn' => '知州']),
            'resource_original' => json_encode(['c_office_id' => 12304, 'c_office_chn' => '知洲']),
            'crowdsourcing_status' => 0,
        ]);

        $link = $this->firstResourceLink($user);

        $this->assertSame('/app/office/12304/edit', $link);
        $this->assertStringNotContainsString(
            '/codes/OFFICE_CODES/',
            (string) $link,
            '仍指向已封寫的泛用 codes 編輯頁——那條路徑點進去只會吃到唯讀警告'
        );

        // 連結的另一半：它必須真的打得開，而不是被重導並 flash 唯讀警告。
        $this->actingAs($user)->get($link)
            ->assertOk()
            ->assertSessionMissing('flash_notification')
            ->assertInertia(fn ($page) => $page
                ->component('Office/Edit')
                ->where('office.office_id', 12304));
    }

    #[Test]
    public function test_closed_office_code_link_handles_the_legacy_two_segment_resource_id(): void {
        // getKeyColumns() 取不到 PK 時會回退成「前兩欄」，寫出 `{c_office_id}_._{c_dy}`。
        // 這個形狀在生產 operations 裡確實存在，不能漏。
        config(['migration_flags.pages.codes' => 'new']);
        $user = $this->activeUser('office-legacy-id@example.com');

        \DB::table('OFFICE_CODES')->insert([
            'c_office_id' => 2318, 'c_office_chn' => '通判', 'c_office_pinyin' => 'tong pan', 'c_dy' => 15,
        ]);
        \DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);

        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'OFFICE_CODES',
            'resource_id' => '2318_._15',
            'resource_data' => json_encode(['c_office_chn' => '通判']),
            'crowdsourcing_status' => 0,
        ]);

        $link = $this->firstResourceLink($user);
        $this->assertSame('/app/office/2318/edit', $link);
        // 同樣把連結打開來驗：形狀對不代表打得開。
        $this->actingAs($user)->get($link)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Office/Edit')->where('office.office_id', 2318));
    }

    #[Test]
    public function test_pending_update_proposal_links_to_the_row_that_exists_now_not_the_proposed_key(): void {
        // resolveLinkResourceId() 對未核准的更新提案刻意回「原列」的主鍵——提案還沒套用，
        // 新鍵在現實中不存在。payload fallback 若照一般順序先讀 resource_data，就會拿到
        // 提案「想改成」的識別鍵，連結指向一個不存在的機構（404），與上游定位語義自相矛盾。
        config(['migration_flags.pages.codes' => 'new']);
        $user = $this->activeUser('inst-pending-proposal@example.com');

        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'SOCIAL_INSTITUTION_CODES',
            'resource_id' => '5000',
            'resource_data' => json_encode([
                'c_inst_code' => 5000, 'c_inst_name_code' => 5348,
                '__key_columns' => ['c_inst_code'], '__review_status' => 'pending',
            ]),
            'resource_original' => json_encode(['c_inst_code' => 3983, 'c_inst_name_code' => 5348]),
            'crowdsourcing_status' => 0,
        ]);

        $this->assertSame('/app/social-institution/3983/edit', $this->firstResourceLink($user, '?proposals_only=1'));

        // 核准後（提案已套用）才該指向新鍵。
        Operation::whereKey($operation->id)->update([
            'resource_data' => json_encode([
                'c_inst_code' => 5000, 'c_inst_name_code' => 5348,
                '__key_columns' => ['c_inst_code'], '__review_status' => 'approved',
            ]),
        ]);
        $this->assertSame('/app/social-institution/5000/edit', $this->firstResourceLink($user, '?proposals_only=1'));
    }

    #[Test]
    public function test_closed_social_institution_link_falls_back_to_the_operation_payload(): void {
        // SOCIAL_INSTITUTION_CODES 的 resource_id 是 codes UI 寫的裸值（欄序來自
        // $tablePrimaryKeyOverrides，與 CompositePrimaryKey::SCHEMAS 不同源），
        // 單靠它解不出 c_inst_code——必須從 operation 快照補，否則這批列會靜默沒有連結。
        config(['migration_flags.pages.codes' => 'new']);
        $user = $this->activeUser('inst-entity-link@example.com');

        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'SOCIAL_INSTITUTION_CODES',
            'resource_id' => '3983',
            // update 的 resource_data 只有被改的欄、未必含主鍵；c_inst_code 在 original 裡。
            'resource_data' => json_encode(['c_inst_type_code' => 4]),
            'resource_original' => json_encode(['c_inst_code' => 3983, 'c_inst_name_code' => 5348, 'c_inst_type_code' => 3]),
            'crowdsourcing_status' => 0,
        ]);

        $this->assertSame('/app/social-institution/3983/edit', $this->firstResourceLink($user));
    }

    #[Test]
    public function test_closed_social_institution_addr_link_falls_back_to_the_operation_payload(): void {
        config(['migration_flags.pages.codes' => 'new']);
        $user = $this->activeUser('inst-addr-link@example.com');

        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_CREATE,
            'resource' => 'SOCIAL_INSTITUTION_ADDR',
            // 生產中的形狀是 `-` 連接的 {c_inst_code}-{c_inst_addr_id}，六欄 schema 解不開。
            'resource_id' => '3983-5348',
            'resource_data' => json_encode([
                'c_inst_addr_id' => 5348, 'c_inst_addr_type_code' => 1, 'c_inst_code' => 3983,
                'c_inst_name_code' => 5348, 'inst_xcoord' => 120.5, 'inst_ycoord' => 30.25,
            ]),
            'crowdsourcing_status' => 0,
        ]);

        $this->assertSame('/app/social-institution/3983/edit', $this->firstResourceLink($user));
    }

    #[Test]
    public function test_name_codes_operation_gets_no_link_because_the_name_code_is_shared(): void {
        // 名稱碼跨機構共用（resolveNameCode() 同名複用），resource_id 與 payload 都沒有
        // c_inst_code——猜一個機構送使用者過去，比不出連結糟得多。
        config(['migration_flags.pages.codes' => 'new']);
        $user = $this->activeUser('inst-name-link@example.com');

        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_CREATE,
            'resource' => 'SOCIAL_INSTITUTION_NAME_CODES',
            'resource_id' => '2619',
            'resource_data' => json_encode([
                'c_inst_name_code' => 2619, 'c_inst_name_hz' => '佑聖寺', 'c_inst_name_py' => 'you sheng si',
            ]),
            'crowdsourcing_status' => 0,
        ]);

        $this->assertNull($this->firstResourceLink($user));
    }

    #[Test]
    public function test_closed_code_link_is_withheld_from_viewers_who_cannot_open_the_entity_form(): void {
        // /operations 是公開頁，但實體編輯頁一律 abort(403)（泛用 codes 編輯頁沒有閘門）。
        // 不看權限就改指，等於發一條必然 403 的連結給訪客——按鈕文案還是「查閱」。
        config(['migration_flags.pages.codes' => 'new']);
        $user = $this->activeUser('office-guest-link@example.com');

        \DB::table('OFFICE_CODES')->insert([
            'c_office_id' => 12304, 'c_office_chn' => '知州', 'c_office_pinyin' => 'zhi zhou', 'c_dy' => 15,
        ]);
        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'OFFICE_CODES',
            'resource_id' => '12304',
            'resource_data' => json_encode(['c_office_id' => 12304]),
            'crowdsourcing_status' => 0,
        ]);

        // 訪客：不出連結。
        $this->assertNull($this->firstResourceLink());
        // 且確認前提為真——訪客直接開實體編輯頁確實會 403。
        $this->get('/app/office/12304/edit')->assertForbidden();

        // 已啟用帳號：出連結。
        $this->assertSame('/app/office/12304/edit', $this->firstResourceLink($user));
    }

    #[Test]
    public function test_link_authorization_follows_each_entity_declared_form_capability(): void {
        // 表單頁守衛（EntityFormController::ensureCanReachForm()）與連結解析都由 config 的
        // form_capability 推導。眾包帳號（canPropose 真、canWriteDirectly 假）是唯一能分辨
        // propose／write 的身分：翻一下 config，連結與 403 必須**一起**翻——沒有這條，
        // 「守衛與連結同源」就只是註解裡的一句話。
        config(['migration_flags.pages.codes' => 'new']);
        $crowdsourcer = $this->activeUser('capability-crowdsourcing@example.com', User::ROLE_CROWDSOURCING);
        $this->assertTrue($crowdsourcer->canPropose());
        $this->assertFalse($crowdsourcer->canWriteDirectly());

        \DB::table('OFFICE_CODES')->insert([
            'c_office_id' => 12304, 'c_office_chn' => '知州', 'c_office_pinyin' => 'zhi zhou', 'c_dy' => 15,
        ]);
        Operation::create([
            'user_id' => $crowdsourcer->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'OFFICE_CODES',
            'resource_id' => '12304',
            'resource_data' => json_encode(['c_office_id' => 12304]),
            'crowdsourcing_status' => 0,
        ]);

        // 註冊表宣告 propose ⇒ 眾包帳號拿得到連結，且真的打得開。
        $link = $this->firstResourceLink($crowdsourcer);
        $this->assertSame('/app/office/12304/edit', $link);
        $this->actingAs($crowdsourcer)->get($link)->assertOk();

        // 同一個帳號、同一筆 operation，只把 office 的 form_capability 翻成 write：
        // 連結消失，而且同一條 URL 現在確實是 403——兩邊都跟著 config 走，不會只翻一半。
        $entities = config('entity_aggregates.entities');
        foreach ($entities as &$entity) {
            if (($entity['resource'] ?? null) === 'office') {
                $entity['form_capability'] = 'write';
            }
        }
        unset($entity);
        config(['entity_aggregates.entities' => $entities]);

        $this->assertNull($this->firstResourceLink($crowdsourcer));
        $this->actingAs($crowdsourcer)->get('/app/office/12304/edit')->assertForbidden();
    }

    // =====================================================================
    // 實體級提案（resource＝聚合名、payload 存聚合意圖，§4.5）：三條連結都不能走表名路徑。
    // 修復前：「修改提案」與「撤回」都指向 codes.proposals.*，guardTable('office') 必 404；
    // 「資源」則因 'office' 不是 codes 表而永遠沒有連結。
    // =====================================================================

    #[Test]
    public function test_pending_entity_create_proposal_links_to_the_entity_create_page_for_resubmit(): void {
        $proposer = $this->activeUser('entity-create-proposal@example.com', User::ROLE_CROWDSOURCING);
        \DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);
        $proposal = $this->entityProposal($proposer, 'office', 'create', null, [
            'name' => '知府', 'dynasty_code' => 15, 'type_ids' => ['x01'], 'source_id' => 68942,
        ]);

        $row = $this->firstRow($proposer, '?proposals_only=1');

        // 核准前實體不存在，沒有「資源」可指；修改提案指新增頁並帶 ?proposal 預填；撤回走與資源無關的端點。
        $this->assertNull($row['resource_link']);
        $this->assertSame("/app/office/create?proposal={$proposal->id}", $row['urls']['edit_proposal']);
        $this->assertSame("/operations/{$proposal->id}/cancel", $row['urls']['cancel_proposal']);
        $this->assertTrue($row['can_edit_proposal']);

        // 連結要真的打得開、而且預填的是這筆提案：斷言字串形狀正是當初漏掉死連結的原因。
        $this->actingAs($proposer)->get($row['urls']['edit_proposal'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Office/Create')
                ->where('proposal_overlay.name', '知府')
                ->where('proposal_overlay.type_ids', ['x01'])
                ->where('initial_labels.dynasty', '宋')
                ->where('resubmit.resubmit_proposal_id', $proposal->id)
                ->where('resubmit.resubmit_endpoint', "/api/v2/proposals/{$proposal->id}/resubmit"));
    }

    #[Test]
    public function test_pending_entity_update_proposal_links_to_the_existing_entity_and_its_edit_page(): void {
        $proposer = $this->activeUser('entity-update-proposal@example.com', User::ROLE_CROWDSOURCING);
        \DB::table('OFFICE_CODES')->insert(['c_office_id' => 12304, 'c_office_chn' => '知州', 'c_office_pinyin' => 'zhi zhou', 'c_dy' => 15]);
        \DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);
        $proposal = $this->entityProposal($proposer, 'office', 'update', 12304, ['name' => '知州事', 'dynasty_code' => 15]);

        $row = $this->firstRow($proposer, '?proposals_only=1');

        $this->assertSame('/app/office/12304/edit', $row['resource_link']);
        $this->assertSame("/app/office/12304/edit?proposal={$proposal->id}", $row['urls']['edit_proposal']);

        $this->actingAs($proposer)->get($row['urls']['edit_proposal'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Office/Edit')
                ->where('office.office_id', 12304)
                ->where('proposal_overlay.name', '知州事')
                ->where('resubmit.resubmit_proposal_id', $proposal->id));

        // 訪客：實體編輯頁對它是 403，所以不出「資源」連結（同封寫表的「查閱」）。
        $this->assertNull($this->firstResourceLink(null, '?proposals_only=1'));
    }

    #[Test]
    public function test_approved_entity_create_proposal_claims_the_applied_audit_and_links_to_the_new_entity(): void {
        // 核准端把 handler 落庫的 direct operation id 記進 __applied_operation_id、配發的識別鍵記進
        // payload；列表據此把 audit 認領回提案列（「比較」可用）並指向新建的實體。
        $proposer = $this->activeUser('entity-approved-create@example.com', User::ROLE_CROWDSOURCING);
        \DB::table('OFFICE_CODES')->insert(['c_office_id' => 500, 'c_office_chn' => '知府', 'c_office_pinyin' => 'zhi fu']);
        $proposal = $this->entityProposal($proposer, 'office', 'create', null, ['name' => '知府'], [
            '__review_status' => 'approved',
            '__applied_operation_id' => '777',
            'c_office_id' => 500,
        ]);
        \DB::table('audit_log')->insert([
            'occurred_at' => now(), 'created_at' => now(),
            'table_name' => 'OFFICE_CODES', 'operation' => 'INSERT',
            'actor_type' => 'user', 'actor_id' => (string) $proposer->id,
            'operation_id' => '777',
            'row_pk' => json_encode(['c_office_id' => 500]), 'row_pk_text' => 'c_office_id=500',
            'old_data' => null, 'new_data' => json_encode(['c_office_id' => 500, 'c_office_chn' => '知府']),
        ]);

        $row = $this->firstRow($proposer, '?proposals_only=1');

        $this->assertSame($proposal->id, $row['id']);
        $this->assertTrue($row['can_compare'], '套用列的 audit 沒被認領回提案列，「比較」會灰掉');
        $this->assertSame('OFFICE_CODES', $row['audit_logs'][0]['table_name']);
        $this->assertSame('/app/office/500/edit', $row['resource_link']);
    }

    #[Test]
    public function test_entity_delete_proposal_links_to_the_entity_only_while_it_still_exists(): void {
        $proposer = $this->activeUser('entity-delete-proposal@example.com', User::ROLE_CROWDSOURCING);
        \DB::table('OFFICE_CODES')->insert(['c_office_id' => 12304, 'c_office_chn' => '知州', 'c_office_pinyin' => 'zhi zhou']);
        $pending = $this->entityProposal($proposer, 'office', 'delete', 12304);

        // 刪除提案（op_type 10）不列在 proposals_only 裡，直接看全列表。
        $row = $this->firstRow($proposer);
        $this->assertSame('/app/office/12304/edit', $row['resource_link']);
        // 刪除提案沒有可修改的內容：不出「修改提案」（否則只能指向必 404 的 codes 頁），撤回照常。
        $this->assertNull($row['urls']['edit_proposal']);
        $this->assertSame("/operations/{$pending->id}/cancel", $row['urls']['cancel_proposal']);

        $payload = json_decode($pending->resource_data, true);
        $payload['__review_status'] = 'approved';
        $pending->resource_data = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $pending->save();

        $this->assertNull($this->firstResourceLink($proposer), '核准後實體已刪除，不得再出連結');
    }

    #[Test]
    public function test_entity_proposal_links_are_withheld_when_the_target_entity_no_longer_exists(): void {
        // 提案送出後實體可能被別的操作刪掉：光憑意圖裡的 __entity_pk 會發出必然 404 的「資源」與
        // 「修改提案」連結（編輯頁以實體為宿主）。實體不在就兩條都不出，撤回照常。
        $proposer = $this->activeUser('entity-stale-proposal@example.com', User::ROLE_CROWDSOURCING);
        $proposal = $this->entityProposal($proposer, 'office', 'update', 999999, ['name' => '不存在的官']);

        $row = $this->firstRow($proposer, '?proposals_only=1');

        $this->assertNull($row['resource_link']);
        $this->assertNull($row['urls']['edit_proposal']);
        $this->assertSame("/operations/{$proposal->id}/cancel", $row['urls']['cancel_proposal']);
        // 前提為真：那個實體的編輯頁確實是 404。
        $this->actingAs($proposer)->get('/app/office/999999/edit')->assertNotFound();
    }

    #[Test]
    public function test_entity_proposal_links_resolve_the_entity_by_resource_name_not_by_table(): void {
        // resource 是聚合名（社會機構的下層有三張表），連結要靠註冊表的 resource 查、不能靠表名。
        $proposer = $this->activeUser('entity-si-proposal@example.com', User::ROLE_CROWDSOURCING);
        $proposal = $this->entityProposal($proposer, 'social-institution', 'create', null, ['name' => '新書院']);

        $row = $this->firstRow($proposer, '?proposals_only=1');

        $this->assertSame("/app/social-institution/create?proposal={$proposal->id}", $row['urls']['edit_proposal']);
        $this->assertStringNotContainsString('/codes/', (string) $row['urls']['cancel_proposal']);
    }

    #[Test]
    public function test_unclosed_code_tables_keep_using_the_generic_codes_editor(): void {
        // 只有被封寫的表改指實體頁；其餘一律維持原本 flag-aware 的 codes 連結。
        config(['migration_flags.pages.codes' => 'new']);
        $user = $this->activeUser('nianhao-unclosed@example.com');

        \DB::table('NIAN_HAO')->insert(['c_nianhao_id' => 464, 'c_nianhao_chn' => '測試年號']);
        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'NIAN_HAO',
            'resource_id' => 'c_nianhao_id=464',
            'resource_data' => json_encode(['c_nianhao_id' => 464, 'c_nianhao_chn' => '測試年號']),
            'crowdsourcing_status' => 0,
        ]);

        $this->assertSame('/app/codes/NIAN_HAO/464/edit', $this->firstResourceLink($user));
    }
}
