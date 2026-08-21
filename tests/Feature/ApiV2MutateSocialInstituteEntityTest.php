<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CharVariantMapService;
use App\Support\VariantReplaceScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 「社會機構實體」update／delete mutation（resource=social-institution）回歸測試。
 *
 * 驗證 SocialInstituteUpdateHandler / SocialInstituteDeleteHandler / SocialInstituteImportService
 * 的聚合語義：實體識別＝c_inst_code 單鍵、名稱去重解析、改名護欄（被引用回 409）、
 * ADDR 集合對賬、刪除護欄（四張人物表引用計數）、名碼不回收。
 */
class ApiV2MutateSocialInstituteEntityTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        $this->app['env'] = 'testing';
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

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
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->integer('c_personid')->default(0);
            $table->integer('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->longText('resource_data')->nullable();
            $table->longText('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });
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
            $table->integer('c_inst_addr_begin_year')->nullable();
            $table->integer('c_inst_addr_end_year')->nullable();
            $table->integer('c_inst_addr_id');
            $table->double('inst_xcoord');
            $table->double('inst_ycoord');
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });
        Schema::create('SOCIAL_INSTITUTION_TYPES', function (Blueprint $table) {
            $table->integer('c_inst_type_code')->primary();
            $table->string('c_inst_type_hz')->nullable();
            $table->string('c_inst_type_py')->nullable();
        });
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
        });
        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
        });
        Schema::create('NIAN_HAO', function (Blueprint $table) {
            $table->integer('c_nianhao_id')->primary();
        });
        Schema::create('YEAR_RANGE_CODES', function (Blueprint $table) {
            $table->integer('c_range_code')->primary();
        });
        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn')->nullable();
            $table->string('c_pinyin')->nullable();
            $table->integer('c_lastname')->default(0);
        });
        // 刪除／改名護欄：referenceCount() 數這四張人物表。
        foreach (['BIOG_INST_DATA', 'ENTRY_DATA', 'ASSOC_DATA', 'POSTED_TO_OFFICE_DATA'] as $t) {
            Schema::create($t, function (Blueprint $table) {
                $table->integer('c_personid');
                $table->integer('c_inst_code');
                $table->integer('c_inst_name_code');
            });
        }

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 15, 'c_dynasty_chn' => '宋'],
            ['c_dy' => 19, 'c_dynasty_chn' => '明'],
        ]);
        DB::table('SOCIAL_INSTITUTION_TYPES')->insert([
            ['c_inst_type_code' => 1, 'c_inst_type_hz' => '書院', 'c_inst_type_py' => 'shuyuan'],
            ['c_inst_type_code' => 2, 'c_inst_type_hz' => '寺廟', 'c_inst_type_py' => 'simiao'],
        ]);
        DB::table('TEXT_CODES')->insert([['c_textid' => 7596], ['c_textid' => 8000]]);
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 101, 'c_name_chn' => '杭州'],
            ['c_addr_id' => 102, 'c_name_chn' => '蘇州'],
        ]);

        // 既有機構：inst_code=10、名碼=5（白鹿洞書院），一列地址。
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            ['c_inst_name_code' => 5, 'c_inst_name_hz' => '白鹿洞書院', 'c_inst_name_py' => 'bailudong shuyuan'],
            ['c_inst_name_code' => 6, 'c_inst_name_hz' => '嶽麓書院', 'c_inst_name_py' => 'yuelu shuyuan'],
        ]);
        DB::table('SOCIAL_INSTITUTION_CODES')->insert([
            'c_inst_name_code' => 5, 'c_inst_code' => 10, 'c_inst_type_code' => 1,
            'c_inst_begin_dy' => 15, 'c_inst_floruit_dy' => 15, 'c_source' => 7596,
        ]);
        DB::table('SOCIAL_INSTITUTION_ADDR')->insert([
            'c_inst_name_code' => 5, 'c_inst_code' => 10, 'c_inst_addr_type_code' => 1,
            'c_inst_addr_id' => 101, 'inst_xcoord' => 0, 'inst_ycoord' => 0, 'c_source' => 7596,
        ]);
    }

    protected function tearDown(): void {
        // char_variant_map 的清理放在這裡而不是各測試方法尾：斷言失敗時方法尾不會執行。
        Schema::dropIfExists('char_variant_map');
        foreach ([
            'POSTED_TO_OFFICE_DATA', 'ASSOC_DATA', 'ENTRY_DATA', 'BIOG_INST_DATA', 'pinyin',
            'YEAR_RANGE_CODES', 'NIAN_HAO', 'ADDR_CODES', 'TEXT_CODES', 'DYNASTIES',
            'SOCIAL_INSTITUTION_TYPES', 'SOCIAL_INSTITUTION_ADDR', 'SOCIAL_INSTITUTION_CODES',
            'SOCIAL_INSTITUTION_NAME_CODES', 'audit_log', 'operations', 'users',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    protected function makeUser(string $email = 'si@example.com'): User {
        return User::forceCreate([
            'name' => 'SI Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    protected function updatePayload(array $changes = []): array {
        return [
            'resource' => 'social-institution',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_inst_code' => 10]],
            'changes' => array_merge([
                'name' => '白鹿洞書院',
                'type_code' => 1,
                'dynasty_code' => 15,
                'source_id' => 7596,
                'addresses' => [['addr_id' => 101]],
            ], $changes),
        ];
    }

    // ── update ──────────────────────────────

    #[Test]
    public function testUpdateOverwritesColumnsAndKeepsNameCode(): void {
        $this->actingAs($this->makeUser(email: 'si-upd@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'type_code' => 2,
            'begin_year' => 940,
            'end_dy' => 19,
            'notes' => '南唐建',
        ]));

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'social-institution',
            'operation' => 'update',
            'result' => ['pk' => ['c_inst_code' => 10], 'status' => 'updated', 'name_changed' => false],
        ]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', [
            'c_inst_code' => 10, 'c_inst_name_code' => 5, 'c_inst_type_code' => 2,
            'c_inst_begin_year' => 940, 'c_inst_end_dy' => 19, 'c_notes' => '南唐建',
        ]);
    }

    #[Test]
    public function testUpdateReconcilesAddressRows(): void {
        $this->actingAs($this->makeUser(email: 'si-addr@example.com'));

        // 101 同鍵改值（補起始年）、新增 102、無其他列 → 對賬結果兩列。
        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'addresses' => [
                ['addr_id' => 101, 'begin_year' => 940],
                ['addr_id' => 102, 'addr_type_code' => 1],
            ],
        ]));

        $res->assertOk()->assertJson(['result' => ['addr_added' => 1, 'addr_removed' => 0]]);
        $this->assertSame(2, DB::table('SOCIAL_INSTITUTION_ADDR')->where('c_inst_code', 10)->count());
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10, 'c_inst_addr_id' => 101, 'c_inst_addr_begin_year' => 940]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10, 'c_inst_addr_id' => 102]);
    }

    #[Test]
    public function testRenameUnreferencedReusesExistingNameCodeAndSyncsAddr(): void {
        $this->actingAs($this->makeUser(email: 'si-rename@example.com'));

        // 改名為既有名「嶽麓書院」→ 複用名碼 6（去重）、不新增 NAME_CODES；ADDR 名碼同步。
        $res = $this->postJson('/api/v2/mutate', $this->updatePayload(['name' => '嶽麓書院']));

        $res->assertOk()->assertJson(['result' => ['name_changed' => true, 'row' => ['c_inst_name_code' => 6]]]);
        $this->assertSame(2, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->count());
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_name_code' => 6]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10, 'c_inst_name_code' => 6]);
        // 舊名碼不回收。
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => 5]);
    }

    #[Test]
    public function testRenameToNewNameCreatesNameCode(): void {
        $this->actingAs($this->makeUser(email: 'si-rename-new@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload(['name' => '石鼓書院']));

        $res->assertOk()->assertJson(['result' => ['name_changed' => true, 'row' => ['c_inst_name_code' => 7]]]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => 7, 'c_inst_name_hz' => '石鼓書院']);
    }

    #[Test]
    public function testRenameBlockedWhileReferenced(): void {
        DB::table('BIOG_INST_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser(email: 'si-rename-blocked@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload(['name' => '嶽麓書院']));

        $res->assertStatus(409);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_name_code' => 5]);
    }

    #[Test]
    public function testUpdateOtherFieldsAllowedWhileReferenced(): void {
        DB::table('ENTRY_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser(email: 'si-upd-ref@example.com'));

        // 同名（名碼不變）僅改其他欄位 → 不受改名護欄影響。
        $this->postJson('/api/v2/mutate', $this->updatePayload(['type_code' => 2]))
            ->assertOk()
            ->assertJson(['result' => ['name_changed' => false]]);
    }

    #[Test]
    public function testUpdateValidation(): void {
        $this->actingAs($this->makeUser(email: 'si-upd-422@example.com'));

        // 缺地址列
        $this->postJson('/api/v2/mutate', $this->updatePayload(['addresses' => []]))->assertStatus(422);
        // 不存在的地址
        $this->postJson('/api/v2/mutate', $this->updatePayload(['addresses' => [['addr_id' => 999]]]))->assertStatus(422);
        // 不存在的年號碼
        $this->postJson('/api/v2/mutate', $this->updatePayload(['by_nianhao_code' => 424242]))->assertStatus(422);
        // 不存在的機構
        $payload = $this->updatePayload();
        $payload['target']['pk']['c_inst_code'] = 999;
        $this->postJson('/api/v2/mutate', $payload)->assertStatus(404);
    }

    // ── delete ──────────────────────────────

    #[Test]
    public function testDeleteRemovesCodesAndAddrButKeepsNameCode(): void {
        $this->actingAs($this->makeUser(email: 'si-del@example.com'));

        $res = $this->postJson('/api/v2/delete', [
            'resource' => 'social-institution',
            'person_id' => 0,
            'target' => ['pk' => ['c_inst_code' => 10]],
        ]);

        $res->assertOk()->assertJson([
            'ok' => true,
            'result' => ['pk' => ['c_inst_code' => 10], 'status' => 'deleted', 'addr_deleted' => 1],
        ]);
        $this->assertDatabaseMissing('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10]);
        $this->assertDatabaseMissing('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10]);
        // 名碼不回收。
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => 5]);
    }

    #[Test]
    public function testDeleteBlockedWhileReferencedByAnyOfFourTables(): void {
        DB::table('POSTED_TO_OFFICE_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser(email: 'si-del-blocked@example.com'));

        $this->postJson('/api/v2/delete', [
            'resource' => 'social-institution',
            'person_id' => 0,
            'target' => ['pk' => ['c_inst_code' => 10]],
        ])->assertStatus(409);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10]);
    }
    // ── 異體字：標籤歸一與代碼白名單（plan S4）──────────────

    /**
     * 最小 char_variant_map 種子（「淸→清」）。其餘測試不建這張表，走
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

    /**
     * (c) 代碼表同時有兩形（「淸」40 與「清」41）時，標籤歸一會讓兩列的鍵塌成一個
     * ——但**兩個代碼都必須仍然可用**。
     *
     * 這是把白名單從 `in_array(..., $map)` 改成 `in_array(..., $service->dynastyCodes())`
     * 的理由：拿 map 的值當白名單時，被碰撞吃掉的 41 會開始被判 dynasty invalid，
     * 而它是一個完全合法的 c_dy。
     */
    #[Test]
    public function testBothCodesStayValidWhenTwoDynastyLabelsNormalizeToTheSameKey(): void {
        $this->seedCharVariantMap();
        DB::table('DYNASTIES')->insert([
            ['c_dy' => 40, 'c_dynasty_chn' => '淸'],
            ['c_dy' => 41, 'c_dynasty_chn' => '清'],
        ]);
        $this->actingAs($this->makeUser('si-variant-whitelist@example.com'));

        // 被碰撞「吃掉」的那個碼（41，因為 map 只留最小的 40）仍須被接受。
        $this->postJson('/api/v2/mutate', $this->updatePayload(['dynasty_code' => 41]))
            ->assertOk();
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_begin_dy' => 41]);

        // 另一個（40）當然也要能用。
        $this->postJson('/api/v2/mutate', $this->updatePayload(['dynasty_code' => 40]))
            ->assertOk();
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_begin_dy' => 40]);

    }

    /**
     * 機構層與**地址列**的文本欄都要替換，回應回落庫值並帶 notices。
     *
     * 地址列的 c_pages／c_notes 是 review 抓到的缺口：同一次 update 裡機構層的 c_notes
     * 被歸一、地址列的 c_notes 原樣入庫（SOCIAL_INSTITUTION_ADDR 同樣是已知表、兩欄都是
     * 文本型，本來就在替換範圍內）。
     */
    #[Test]
    public function testUpdateReplacesVariantsInInstitutionAndAddressTextColumns(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser('si-variant-text@example.com'));

        $response = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'notes' => '淸代重修',
            'pages' => '淸卷一',
            'addresses' => [[
                'addr_id' => 101,
                'notes' => '淸址備註',
                'pages' => '淸址頁',
            ]],
        ]))->assertOk();

        $code = DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', 10)->first();
        $this->assertSame('清代重修', $code->c_notes);
        $this->assertSame('清卷一', $code->c_pages);

        $addr = DB::table('SOCIAL_INSTITUTION_ADDR')->where('c_inst_code', 10)->first();
        $this->assertSame('清址備註', $addr->c_notes, '地址列的備註也必須歸一');
        $this->assertSame('清址頁', $addr->c_pages);

        $this->assertNotEmpty($response->json('notices'), '回應必須帶異體字通知');
    }

    /**
     * 「只換了字形」的改名在 resolveNameCode() 兩形都探之下其實是 no-op，
     * 不得被改名護欄誤報成 409（該機構仍被人物資料引用）。
     */
    #[Test]
    public function testVariantOnlyRenameIsNotBlockedByReferenceGuard(): void {
        $this->seedCharVariantMap();
        // 既有名稱是參考形，且被人物資料引用。
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_code', 5)
            ->update(['c_inst_name_hz' => '清溪書院']);
        DB::table('BIOG_INST_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser('si-variant-rename@example.com'));

        $response = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'name' => '淸溪書院', // 只是字形不同 ⇒ 解析到同一個 name_code
            'notes' => '改備註',
        ]));

        $this->assertSame(200, $response->getStatusCode(), '只換字形不算改名，不該被引用護欄擋下');
        $this->assertSame(5, (int) DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', 10)->value('c_inst_name_code'));
        $this->assertSame('清溪書院', DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_code', 5)->value('c_inst_name_hz'), '既有列不歸一也不被改寫');
        $this->assertSame('清溪書院', $response->json('result.row.c_inst_name_hz'), '回應要回實際生效的名稱');
    }

    /**
     * codex：`typeCodes()` 必須是 hz／py 兩份的**聯集**。schema 允許 c_inst_type_hz 為
     * null（只有拼音名），舊 typeMap() 也是任一有值就收；只取 hz 那份會讓這種列的
     * 合法 type_code 在白名單驗證被錯判 invalid（422）。
     */
    #[Test]
    public function testTypeCodeWithOnlyPinyinLabelStaysValid(): void {
        DB::table('SOCIAL_INSTITUTION_TYPES')->insert([
            'c_inst_type_code' => 9, 'c_inst_type_hz' => null, 'c_inst_type_py' => 'shuyuan-only',
        ]);
        $this->actingAs($this->makeUser('si-py-only@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload(['type_code' => 9]))->assertOk();
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_type_code' => 9]);
    }

    /**
     * 去重是**單向**的，這條把邊界釘死：輸入參考形而既有列是**另一個**變體形時，
     * 精確比對命中不了（反方向需要列舉輸入的所有前像，plan 明確否決），
     * 所以會像 S4 之前一樣新建一個名稱碼。
     *
     * 這不是本步造成的回歸（S4 之前同樣新建），但也沒被本步修好——寫成測試是為了讓
     * 這個已知缺口有明文、不會被誤以為已解決。真正的修法是加一個歸一後的影子欄或
     * 做一次性資料合併，屬獨立工作。
     */
    #[Test]
    public function testReferenceFormInputDoesNotMergeIntoExistingVariantRow(): void {
        $this->seedCharVariantMap();
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            'c_inst_name_code' => 7, 'c_inst_name_hz' => '淸溪書院', 'c_inst_name_py' => 'qing xi shu yuan',
        ]);
        $this->actingAs($this->makeUser('si-reverse-direction@example.com'));

        $response = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'name' => '清溪書院', // 參考形；既有列是變體形
        ]))->assertOk();

        $newCode = (int) DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', 10)->value('c_inst_name_code');
        $this->assertNotSame(7, $newCode, '反方向不會併入既有變體形列（已知邊界）');
        $this->assertSame('清溪書院', DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_code', $newCode)->value('c_inst_name_hz'));
        // 沒有發生字元替換（輸入本來就是參考形）⇒ 不該有異體字通知。
        $this->assertNull($response->json('notices'));
    }

    /**
     * codex round 2：改名護欄要問「這次儲存會不會真的換掉 c_inst_name_code」，
     * 不能只比歸一後的字串。
     *
     * 反方向（輸入參考形、既有列是另一個變體形）歸一後兩邊字串看起來相同，但
     * resolveNameCode() 會**新建**一個 code ⇒ 對被引用的機構就是既存引用失配，
     * 必須照樣回 409。
     */
    #[Test]
    public function testReferenceFormInputIsStillBlockedWhenInstitutionIsReferenced(): void {
        $this->seedCharVariantMap();
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_code', 5)
            ->update(['c_inst_name_hz' => '淸溪書院']); // 既有名稱是變體形
        DB::table('BIOG_INST_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser('si-reverse-guard@example.com'));

        $response = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'name' => '清溪書院', // 參考形：歸一後與既有列「看起來相同」，但會新建 code
        ]));

        $this->assertSame(409, $response->getStatusCode(), '會換掉 name_code ⇒ 仍須被引用護欄擋下');
        $this->assertSame(5, (int) DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', 10)->value('c_inst_name_code'));
        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_hz', '淸溪書院')->count());
        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_hz', '清溪書院')->count(), '不得新建名稱列');
    }
}
