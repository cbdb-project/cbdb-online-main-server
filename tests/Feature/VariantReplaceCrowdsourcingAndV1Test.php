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
 * 異體字落地替換：眾包回填與 v1 token API（plan S7）。
 *
 * 這兩條路的共同點是「payload 可能早於落地替換上線」，所以**提交端與回填端都要替換**
 * （依 D8 幂等）。v1 端點另有兩個必須守住的邊界：未知 resource 原樣存入（fail-closed）、
 * 壞 JSON 原樣存入（維持現況語義，不可變成字串 "null" 也不可當場拋錯）。
 */
class VariantReplaceCrowdsourcingAndV1Test extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
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
            $table->integer('rate')->default(0);
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

        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_surname_chn')->nullable();
            $table->string('c_mingzi_chn')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });

        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->string('c_office_chn')->nullable();
            $table->string('c_office_pinyin')->nullable();
            $table->text('c_notes')->nullable();
        });

        $this->seedCharVariantMap();
    }

    protected function tearDown(): void {
        foreach ($this->extraTables as $extra) {
            Schema::dropIfExists($extra);
        }

        foreach ([
            'char_variant_map', 'STATUS_DATA', 'BIOG_INST_DATA', 'ASSOC_DATA', 'KIN_DATA',
            'BIOG_SOURCE_DATA', 'BIOG_ADDR_DATA', 'OFFICE_CODES', 'BIOG_MAIN', 'audit_log', 'operations', 'users',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    /** 與 migration 同源的最小種子（「淸→清」）。 */
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

    protected function makeCrowdUser(string $token = 'LONG-LIVED-TOKEN'): User {
        return User::forceCreate([
            'name' => '眾包使用者',
            'email' => 'crowd-variant@example.com',
            'password' => bcrypt('secret'),
            'confirmation_token' => $token,
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_CROWDSOURCING,
        ]);
    }

    // ── v1 token API：提交端 ────────────────────────────────

    /** 提交端就替換：payload 存進 operations 時已是參考形。 */
    #[Test]
    public function testV1AddReplacesVariantsInSubmittedPayload(): void {
        $this->makeCrowdUser();

        $this->post('/api/operations/add', [
            'token' => 'LONG-LIVED-TOKEN',
            'resource' => 'BIOG_MAIN',
            'json' => json_encode(['c_name_chn' => '淸河', 'c_notes' => '淸人'], JSON_UNESCAPED_UNICODE),
        ]);

        $payload = json_decode((string) DB::table('operations')->value('resource_data'), true);
        $this->assertSame('清河', $payload['c_name_chn']);
        $this->assertSame('清人', $payload['c_notes']);
    }

    /** 未知 resource（客戶端可任意給、無白名單）⇒ fail-closed，原樣存入。 */
    #[Test]
    public function testV1AddLeavesUnknownResourcePayloadUntouched(): void {
        $this->makeCrowdUser();

        $this->post('/api/operations/add', [
            'token' => 'LONG-LIVED-TOKEN',
            'resource' => 'NOT_A_CBDB_TABLE',
            'json' => json_encode(['whatever' => '淸河'], JSON_UNESCAPED_UNICODE),
        ]);

        $payload = json_decode((string) DB::table('operations')->value('resource_data'), true);
        $this->assertSame('淸河', $payload['whatever'], '未知表一律不替換（fail-closed）');
    }

    /**
     * 壞 JSON ⇒ 原樣存入。
     *
     * 現況語義是「原樣存、到 confirm() 才爆」；不可因為加了替換就變成字串 "null"
     * （那會讓壞資料看起來像合法的 null payload），也不可在此當場拋錯改變 API 行為。
     */
    #[Test]
    public function testV1AddKeepsMalformedJsonAsIs(): void {
        $this->makeCrowdUser();
        $broken = '{"c_name_chn": "淸河"';

        $this->post('/api/operations/add', [
            'token' => 'LONG-LIVED-TOKEN',
            'resource' => 'BIOG_MAIN',
            'json' => $broken,
        ]);

        $this->assertSame($broken, DB::table('operations')->value('resource_data'));
    }

    /**
     * update 端：payload 替換，但 resource_original（歷史快照）**不動**。
     *
     * 用 OFFICE_CODES 而非 BIOG_MAIN：後者的 $ori 走 byPersonId()，會 withCount 十幾張
     * 關聯表，為了驗一件事而建那些表不成比例。OFFICE_CODES 走的是同一段 update_operations
     * 邏輯（只換 else 分支取 $ori 的方式），足以鎖住「payload 替換／快照不替換」。
     */
    #[Test]
    public function testV1UpdateReplacesPayloadButNotOriginalSnapshot(): void {
        $this->makeCrowdUser();
        DB::table('OFFICE_CODES')->insert(['c_office_id' => 8001, 'c_office_chn' => '淸吏司', 'c_notes' => '淸人']);

        $this->post('/api/operations/update', [
            'token' => 'LONG-LIVED-TOKEN',
            'resource' => 'OFFICE_CODES',
            'pId' => 8001,
            'json' => json_encode(['c_office_id' => 8001, 'c_office_chn' => '淸吏司改'], JSON_UNESCAPED_UNICODE),
        ]);

        $operation = DB::table('operations')->latest('id')->first();
        $this->assertNotNull($operation);
        $payload = json_decode((string) $operation->resource_data, true);
        $this->assertSame('清吏司改', $payload['c_office_chn'], 'payload 要替換');

        // 快照由 Eloquent 以預設 escaping 存（非 ASCII 會變成 \uXXXX 轉義），所以先解碼再比字形。
        $original = json_decode((string) $operation->resource_original, true);
        $this->assertSame('淸吏司', $original['c_office_chn'] ?? null, '歷史快照必須原樣保留（那是當時的事實）');
        $this->assertSame('淸人', $original['c_notes'] ?? null);
    }

    // ── 眾包回填（confirm）────────────────────────────────

    /** 回填端也替換：模擬**歷史遺留 payload**（提交早於落地替換上線）。 */
    #[Test]
    public function testConfirmReplacesVariantsInLegacyPayload(): void {
        $admin = User::forceCreate([
            'name' => '審核者',
            'email' => 'confirm-variant@example.com',
            'confirmation_token' => 'tok',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
        $this->actingAs($admin);

        $operationId = DB::table('operations')->insertGetId([
            'user_id' => 1,
            'c_personid' => 0,
            'op_type' => 1,
            'resource' => 'BIOG_MAIN',
            'resource_id' => '',
            // 未替換的歷史 payload
            'resource_data' => json_encode(['c_name_chn' => '淸河', 'c_notes' => '淸人'], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 2,
            'rate' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get("/crowdsourcing/{$operationId}/confirm");

        $row = DB::table('BIOG_MAIN')->first();
        $this->assertNotNull($row, '回填應建立人物列');
        $this->assertSame('清河', $row->c_name_chn, '回填時必須替換（雙保險的第二層）');
        $this->assertSame('清人', $row->c_notes);
    }

    /** 未知 resource 的回填同樣 fail-closed（不替換、也不該炸）。 */
    #[Test]
    public function testConfirmLeavesUnknownResourceUntouched(): void {
        $admin = User::forceCreate([
            'name' => '審核者',
            'email' => 'confirm-unknown@example.com',
            'confirmation_token' => 'tok2',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
        $this->actingAs($admin);

        $operationId = DB::table('operations')->insertGetId([
            'user_id' => 1,
            'c_personid' => 0,
            'op_type' => 1,
            'resource' => 'NOT_A_CBDB_TABLE',
            'resource_id' => '',
            'resource_data' => json_encode(['whatever' => '淸河'], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 2,
            'rate' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 斷言重導（而不是只看 DB）：未知 resource 若讓替換拋錯，Laravel 會渲染成 500，
        // 沒有這行的話後面的斷言照樣成立、測試變成空轉。
        $this->get("/crowdsourcing/{$operationId}/confirm")->assertRedirect();

        // 未知 resource 在 switch 裡沒有分支 ⇒ 不落庫；重點是不能因為替換而拋錯。
        $this->assertSame(0, DB::table('BIOG_MAIN')->count());
        $this->assertSame(
            '淸河',
            json_decode((string) DB::table('operations')->where('id', $operationId)->value('resource_data'), true)['whatever']
        );
    }
    // ── 複製工具（saveas／Duplicate_Collateral_Info）──────────

    /**
     * `saveas()` 複製出的是**新列**，要複製成正規化後的字形。
     *
     * 這兩條路由（`routes/web.php:161-162`）**沒有掛 `legacy.form`**，不受 migration flag 影響、
     * 現在就是活的，而且沒有 React 替代品——所以不能當成 legacy 略過。
     */
    #[Test]
    public function testSaveAsCopiesNormalizedGlyphs(): void {
        $editor = User::forceCreate([
            'name' => '編輯者',
            'email' => 'saveas-variant@example.com',
            'confirmation_token' => 'tok-saveas',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
        $this->actingAs($editor);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 9001,
            'c_name_chn' => '淸河',
            'c_surname_chn' => '淸',
            'c_mingzi_chn' => '河',
            'c_notes' => '淸人所記',
        ]);

        $this->get('/basicinformation/9001/saveas');

        $copy = DB::table('BIOG_MAIN')->where('c_personid', '!=', 9001)->first();
        $this->assertNotNull($copy, '應另存出一列新人物');
        $this->assertSame('清河', $copy->c_name_chn, '複製出的新列要用參考形');
        $this->assertSame('清', $copy->c_surname_chn);
        $this->assertSame('清人所記', $copy->c_notes);

        // 原列不動（D6：既有資料不做回溯校正）。
        $this->assertSame('淸河', DB::table('BIOG_MAIN')->where('c_personid', 9001)->value('c_name_chn'));
    }

    /**
     * `Duplicate_Collateral_Info()` 連帶複製 8 張子表，每一列都是**新列**，要用參考形。
     *
     * 這條測試特別鎖住**順序**：`BIOG_SOURCE_DATA.c_pages` 與 `ASSOC_DATA.c_text_title`
     * 都是文本型主鍵成員，替換必須早於 `operations.resource_id` 與 `audit_log.row_pk` 的組裝，
     * 三者才會看到同一個字形（同 S6 的教訓）。
     */
    #[Test]
    public function testDuplicateCollateralInfoCopiesNormalizedGlyphsIncludingTextPrimaryKeys(): void {
        $this->createCollateralTables();

        $editor = User::forceCreate([
            'name' => '編輯者',
            'email' => 'dup-variant@example.com',
            'confirmation_token' => 'tok-dup',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
        $this->actingAs($editor);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 9100, 'c_name_chn' => '淸河', 'c_notes' => '淸人']);
        // 同一人底下兩列**只差字形**：歸一後 PK 相同。沒有去重的話第二次 insert 撞唯一鍵，
        // 整個複製交易回滾 ⇒ 複製功能對這種人物永久失敗且沒有可行動訊息。
        DB::table('BIOG_SOURCE_DATA')->insert([
            ['c_personid' => 9100, 'c_textid' => 500, 'c_pages' => '淸一', 'c_notes' => '淸注'],
            ['c_personid' => 9100, 'c_textid' => 500, 'c_pages' => '清一', 'c_notes' => '參考形那列'],
        ]);
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => 9100, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_kin_code' => 0, 'c_kin_id' => 0,
            'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0, 'c_text_title' => '淸書', 'c_assoc_first_year' => 1060,
        ]);

        $this->get('/basicinformation/9100/Duplicate_Collateral_Info');

        $newId = (int) DB::table('BIOG_MAIN')->where('c_personid', '!=', 9100)->value('c_personid');
        $this->assertGreaterThan(0, $newId, '應複製出一列新人物');

        // 文本型主鍵成員要以參考形落庫，而且兩列歸一後撞同一 PK ⇒ 只保留第一列（不整批失敗）。
        $copiedSources = DB::table('BIOG_SOURCE_DATA')->where('c_personid', $newId)->get();
        $this->assertCount(1, $copiedSources, '歸一後重複的列應被跳過，而不是讓整個複製回滾');
        $this->assertSame('清一', $copiedSources[0]->c_pages);
        $this->assertSame('清注', $copiedSources[0]->c_notes, '保留的是第一列');
        $this->assertSame('清書', DB::table('ASSOC_DATA')->where('c_personid', $newId)->value('c_text_title'));

        // operations 的 resource_id 必須跟落庫值同字形（否則還原／稽核指向不存在的鍵）。
        $sourceOp = DB::table('operations')
            ->where('resource', 'BIOG_SOURCE_DATA')
            ->latest('id')
            ->first();
        $this->assertNotNull($sourceOp);
        $this->assertStringContainsString('清一', urldecode((string) $sourceOp->resource_id));
        $this->assertStringNotContainsString('淸一', urldecode((string) $sourceOp->resource_id));

        // audit_log 的 row_pk 同理。
        $sourceAudit = DB::table('audit_log')->where('table_name', 'BIOG_SOURCE_DATA')->latest('id')->first();
        $this->assertNotNull($sourceAudit);
        $this->assertStringContainsString('清一', (string) $sourceAudit->row_pk);
        $this->assertStringNotContainsString('淸一', (string) $sourceAudit->row_pk);

        // 原列不動（D6）：兩種字形都還在。
        $this->assertSame(2, DB::table('BIOG_SOURCE_DATA')->where('c_personid', 9100)->count());
        $this->assertTrue(DB::table('BIOG_SOURCE_DATA')->where('c_personid', 9100)->where('c_pages', '淸一')->exists());
    }

    /**
     * `confirm()` 的 update 分支會用 `BiogMainRepository::byPersonId()` 取 $ori，
     * 那個查詢對十幾張關聯表做 withCount，所以這些表必須存在（可為空、只要 join 欄）。
     */
    protected function createPersonCountDependencies(): void {
        $pairs = [
            'TEXT_CODES' => ['c_textid'],
            'BIOG_TEXT_DATA' => ['c_personid', 'c_textid'],
            'BIOG_ADDR_DATA' => ['c_personid'],
            'ALTNAME_CODES' => ['c_name_type_code'],
            'ALTNAME_DATA' => ['c_personid', 'c_alt_name_type_code'],
            'POSTED_TO_OFFICE_DATA' => ['c_personid', 'c_office_id'],
            'ENTRY_CODES' => ['c_entry_code'],
            'ENTRY_DATA' => ['c_personid', 'c_entry_code'],
            'STATUS_CODES' => ['c_status_code'],
            'STATUS_DATA' => ['c_personid', 'c_status_code'],
            'KINSHIP_CODES' => ['c_kincode'],
            'KIN_DATA' => ['c_personid', 'c_kin_code'],
            'ASSOC_CODES' => ['c_assoc_code'],
            'ASSOC_DATA' => ['c_personid', 'c_assoc_code'],
            'POSSESSION_ACT_CODES' => ['c_possession_act_code'],
            'POSSESSION_DATA' => ['c_personid', 'c_possession_act_code'],
            'BIOG_INST_CODES' => ['c_bi_role_code'],
            'BIOG_INST_DATA' => ['c_personid', 'c_bi_role_code'],
            'EVENT_CODES' => ['c_event_code'],
            'EVENTS_DATA' => ['c_personid', 'c_event_code'],
            'BIOG_SOURCE_DATA' => ['c_personid', 'c_textid'],
        ];

        foreach ($pairs as $table => $columns) {
            if (Schema::hasTable($table)) {
                continue;
            }
            Schema::create($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->integer($column)->default(0);
                }
            });
            $this->extraTables[] = $table;
        }
    }

    /** @var array<int,string> 由 createPersonCountDependencies() 建的表，tearDown 要清掉。 */
    protected array $extraTables = [];

    /** Duplicate_Collateral_Info() 會無條件查全部 8 張子表，所以要全部存在（可為空）。 */
    protected function createCollateralTables(): void {
        Schema::create('BIOG_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_addr_id')->default(0);
            $table->integer('c_addr_type')->default(0);
            $table->integer('c_sequence')->default(0);
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });
        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid')->default(0);
            $table->string('c_pages')->default('');
            $table->text('c_notes')->nullable();
            // **真實複合主鍵**：少了它，「歸一後兩列撞同一 PK」根本不會發生，
            // 去重機制的測試就變成空轉（review 抓到）。
            $table->primary(['c_personid', 'c_textid', 'c_pages']);
        });
        Schema::create('KIN_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });
        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_assoc_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_assoc_kin_code')->default(0);
            $table->integer('c_assoc_kin_id')->default(0);
            $table->string('c_text_title')->default('');
            $table->integer('c_assoc_first_year')->default(0);
            $table->text('c_notes')->nullable();
            // 第二個 ASSOC 迴圈（三方關係）會補這兩欄。
            $table->integer('c_tertiary_personid')->nullable();
            $table->text('c_tertiary_type_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });
        Schema::create('BIOG_INST_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->integer('c_bi_role_code')->default(0);
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });
        Schema::create('STATUS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_status_code')->default(0);
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });
    }
    // ── review round 2 補的覆蓋 ─────────────────────────────

    /**
     * confirm() 的 **update 分支**（九個寫入點裡最常走的一半）落庫字形。
     */
    #[Test]
    public function testConfirmUpdateBranchReplacesVariants(): void {
        $this->createPersonCountDependencies();
        $this->actingAs($this->makeApprover('confirm-update@example.com'));
        DB::table('BIOG_MAIN')->insert(['c_personid' => 7100, 'c_name_chn' => '舊名', 'c_notes' => '舊注']);

        $operationId = DB::table('operations')->insertGetId([
            'user_id' => 1,
            'c_personid' => 7100,
            'op_type' => 3,
            'resource' => 'BIOG_MAIN',
            'resource_id' => '7100',
            'resource_data' => json_encode(['c_personid' => 7100, 'c_name_chn' => '淸河', 'c_notes' => '淸人'], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 2,
            'rate' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get("/crowdsourcing/{$operationId}/confirm");

        $row = DB::table('BIOG_MAIN')->where('c_personid', 7100)->first();
        $this->assertSame('清河', $row->c_name_chn);
        $this->assertSame('清人', $row->c_notes);
    }

    /**
     * confirm() 的 **delete-only 分支不寫 $data**，卻仍把它存成 operations 快照。
     * 那份快照是 restoreDelete() 重建被刪列的依據 ⇒ **不可替換**，否則還原會產生一列
     * 從未存在過的字形（與「快照＝當時實際發生什麼」的原則相違）。
     */
    #[Test]
    public function testConfirmDeleteBranchKeepsSnapshotUnreplaced(): void {
        $this->actingAs($this->makeApprover('confirm-delete@example.com'));
        DB::table('OFFICE_CODES')->insert(['c_office_id' => 8100, 'c_office_chn' => '淸吏司']);

        $operationId = DB::table('operations')->insertGetId([
            'user_id' => 1,
            'c_personid' => 0,
            'op_type' => 4,
            'resource' => 'OFFICE_CODES',
            'resource_id' => '8100',
            'resource_data' => json_encode(['c_office_id' => 8100, 'c_office_chn' => '淸吏司'], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 2,
            'rate' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get("/crowdsourcing/{$operationId}/confirm");

        $this->assertSame(0, DB::table('OFFICE_CODES')->count(), '該列應被刪除');

        $deleteOp = DB::table('operations')->where('op_type', 4)->where('resource', 'OFFICE_CODES')->latest('id')->first();
        $this->assertNotNull($deleteOp);
        $snapshot = json_decode((string) $deleteOp->resource_data, true);
        $this->assertSame('淸吏司', $snapshot['c_office_chn'] ?? null, '刪除快照必須保留當時的字形');
    }

    /**
     * v1 的 payload 以 `JSON_UNESCAPED_UNICODE` re-encode（中文存原字元）。
     * 拿掉那個 flag 這條會紅——先前所有測試都先 json_decode 再比，測不到這件事。
     */
    #[Test]
    public function testV1PayloadIsStoredWithUnescapedUnicode(): void {
        $this->makeCrowdUser();

        $this->post('/api/operations/add', [
            'token' => 'LONG-LIVED-TOKEN',
            'resource' => 'BIOG_MAIN',
            'json' => json_encode(['c_name_chn' => '淸河'], JSON_UNESCAPED_UNICODE),
        ]);

        $raw = (string) DB::table('operations')->value('resource_data');
        $this->assertStringContainsString('清河', $raw, '應以原字元存（JSON_UNESCAPED_UNICODE）');
        $this->assertStringNotContainsString('\\u6e05', $raw);
    }

    /**
     * strict／lenient 在同一列並存：`c_surname_chn`／`c_mingzi_chn`／`c_name_chn` 是人名欄
     * （strict：`峯` 不替換），同列的 `c_notes` 走全量規則（`峯`→`峰`）。
     */
    #[Test]
    public function testV1AddAppliesStrictToNameColumnsAndLenientToNotes(): void {
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
        ]);
        CharVariantMapService::reset();
        $this->makeCrowdUser();

        $this->post('/api/operations/add', [
            'token' => 'LONG-LIVED-TOKEN',
            'resource' => 'BIOG_MAIN',
            'json' => json_encode([
                'c_surname_chn' => '峯',
                'c_name_chn' => '峯生',
                'c_notes' => '峯下人',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $payload = json_decode((string) DB::table('operations')->value('resource_data'), true);
        $this->assertSame('峯', $payload['c_surname_chn'], '人名欄用 strict：峯 不替換');
        $this->assertSame('峯生', $payload['c_name_chn']);
        $this->assertSame('峰下人', $payload['c_notes'], '一般文本欄用 lenient：峯→峰');
    }

    /**
     * 鬆散寫法的表名（前後空白／大小寫不同）**不替換**——刻意與下游分派對稱。
     *
     * `confirm()` 的 switch 與 `update_operations()` 的 `$y == "BIOG_MAIN"` 都是精確比對，
     * 所以 `" biog_main "` 根本不會被寫入任何表；若在提交端替換它，就會出現「payload 被
     * 改寫、卻永遠不會落庫」的不對稱，而且等於讓未驗證字串進到 schema 查詢（空結果會被
     * 快取住，讓該表在這個 process 之後都不替換）。
     */
    #[Test]
    public function testV1AddDoesNotReplaceWhenResourceNameIsNotExactRegistrySpelling(): void {
        $this->makeCrowdUser();

        $this->post('/api/operations/add', [
            'token' => 'LONG-LIVED-TOKEN',
            'resource' => ' biog_main ',
            'json' => json_encode(['c_name_chn' => '淸河'], JSON_UNESCAPED_UNICODE),
        ]);

        $payload = json_decode((string) DB::table('operations')->value('resource_data'), true);
        $this->assertSame('淸河', $payload['c_name_chn'], '非精確表名不替換（與分派對稱）');

        // 型別快取沒有被毒化：緊接著用**精確**表名提交仍正常替換。
        $this->post('/api/operations/add', [
            'token' => 'LONG-LIVED-TOKEN',
            'resource' => 'BIOG_MAIN',
            'json' => json_encode(['c_name_chn' => '淸河'], JSON_UNESCAPED_UNICODE),
        ]);
        $second = json_decode((string) DB::table('operations')->latest('id')->value('resource_data'), true);
        $this->assertSame('清河', $second['c_name_chn']);
    }

    protected function makeApprover(string $email): User {
        return User::forceCreate([
            'name' => '審核者',
            'email' => $email,
            'confirmation_token' => 'tok-'.md5($email),
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }
}
