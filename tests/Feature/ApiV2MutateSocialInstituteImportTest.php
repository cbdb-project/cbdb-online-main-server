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
 * 「新增社會機構」mutation（resource=social-institution → NAME_CODES + CODES + ADDR）回歸測試。
 *
 * 驗證 SocialInstituteImportHandler / SocialInstituteImportService 的複合存儲過程：
 * 三表原子寫入、名稱去重（同名複用 name_code、不重複建 NAME_CODES）、自動 inst_code、
 * 拼音派生、begin/floruit 朝代同碼、operations + audit_log，以及類型/朝代/地址/來源校驗。
 */
class ApiV2MutateSocialInstituteImportTest extends TestCase {
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
            $table->integer('c_inst_begin_dy')->nullable();
            $table->integer('c_inst_floruit_dy')->nullable();
            $table->integer('c_source')->nullable();
            $table->primary(['c_inst_code', 'c_inst_name_code']);
        });
        Schema::create('SOCIAL_INSTITUTION_ADDR', function (Blueprint $table) {
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_code');
            $table->integer('c_inst_addr_type_code');
            $table->integer('c_inst_addr_id');
            $table->float('inst_xcoord');
            $table->float('inst_ycoord');
            $table->integer('c_source')->nullable();
            $table->primary(['c_inst_addr_id', 'c_inst_addr_type_code', 'c_inst_code', 'c_inst_name_code', 'inst_xcoord', 'inst_ycoord']);
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
        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
        });
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
        });
        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn')->nullable();
            $table->string('c_pinyin')->nullable();
            $table->integer('c_lastname')->default(0);
        });

        DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);
        DB::table('SOCIAL_INSTITUTION_TYPES')->insert(['c_inst_type_code' => 3, 'c_inst_type_hz' => '書院', 'c_inst_type_py' => 'shuyuan']);
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 12345]);
        DB::table('TEXT_CODES')->insert(['c_textid' => 7596]);
    }

    protected function tearDown(): void {
        // char_variant_map 的清理放在這裡而不是各測試方法尾：斷言失敗時方法尾不會執行。
        Schema::dropIfExists('char_variant_map');
        foreach ([
            'pinyin', 'TEXT_CODES', 'ADDR_CODES', 'DYNASTIES', 'SOCIAL_INSTITUTION_TYPES',
            'SOCIAL_INSTITUTION_ADDR', 'SOCIAL_INSTITUTION_CODES', 'SOCIAL_INSTITUTION_NAME_CODES',
            'audit_log', 'operations', 'users',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'si@example.com'): User {
        return User::forceCreate([
            'name' => 'SocialInst Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function payload(array $overrides = []): array {
        return array_merge([
            'resource' => 'social-institution',
            'person_id' => 0,
            'target' => ['pk' => []],
            'changes' => [
                'name' => '嶽麓書院',
                'type_code' => 3,
                'dynasty_code' => 15,
                'addr_id' => 12345,
                'source_id' => 7596,
            ],
        ], $overrides);
    }

    #[Test]
    public function testCreateWritesThreeTablesAtomically(): void {
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert(['c_inst_name_code' => 100, 'c_inst_name_hz' => '既有名']);
        DB::table('SOCIAL_INSTITUTION_CODES')->insert(['c_inst_name_code' => 100, 'c_inst_code' => 200, 'c_inst_type_code' => 3]);
        $this->actingAs($this->makeUser(email: 'si-create@example.com'));

        $res = $this->postJson('/api/v2/create', $this->payload());

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'social-institution',
            'operation' => 'create',
            'result' => ['pk' => ['c_inst_code' => 201, 'c_inst_name_code' => 101], 'name_created' => true],
        ]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => 101, 'c_inst_name_hz' => '嶽麓書院']);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 201, 'c_inst_name_code' => 101, 'c_inst_type_code' => 3, 'c_inst_begin_dy' => 15, 'c_inst_floruit_dy' => 15, 'c_source' => 7596]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 201, 'c_inst_name_code' => 101, 'c_inst_addr_type_code' => 1, 'c_inst_addr_id' => 12345, 'inst_xcoord' => 0, 'inst_ycoord' => 0]);
        // operations + audit：三表各一
        $this->assertSame(1, DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_NAME_CODES')->count());
        $this->assertSame(1, DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_CODES')->count());
        $this->assertSame(1, DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ADDR')->count());
        $this->assertSame(3, DB::table('audit_log')->count());

        // resource_id 必須能被 CompositePrimaryKey 解析（表已登記 SCHEMAS），
        // 否則 /operations 實時比對與 restore 定位會靜默失效。
        $codeResourceId = (string) DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_CODES')->value('resource_id');
        $this->assertSame(
            ['c_inst_code' => '201', 'c_inst_name_code' => '101'],
            \App\Support\CompositePrimaryKey::parseStoredResourceId($codeResourceId, 'SOCIAL_INSTITUTION_CODES')
        );
        $addrResourceId = (string) DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ADDR')->value('resource_id');
        $parsedAddr = \App\Support\CompositePrimaryKey::parseStoredResourceId($addrResourceId, 'SOCIAL_INSTITUTION_ADDR');
        $this->assertNotNull($parsedAddr);
        $this->assertSame('12345', $parsedAddr['c_inst_addr_id']);
        $this->assertSame('201', $parsedAddr['c_inst_code']);
    }

    #[Test]
    public function testDuplicateNameReusesNameCodeWithoutNewNameRow(): void {
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert(['c_inst_name_code' => 50, 'c_inst_name_hz' => '嶽麓書院', 'c_inst_name_py' => 'yue lu shu yuan']);
        $this->actingAs($this->makeUser(email: 'si-dup@example.com'));

        $res = $this->postJson('/api/v2/create', $this->payload());

        $res->assertOk()->assertJson([
            'result' => ['pk' => ['c_inst_name_code' => 50], 'name_created' => false],
        ]);
        // 同名複用 code 50，不新增 NAME_CODES 列
        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->count());
        $this->assertSame(0, DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_NAME_CODES')->count());
        // CODES / ADDR 仍各寫一筆，且掛在複用的 name_code 50 下
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_name_code' => 50, 'c_inst_code' => 1]);
        $this->assertSame(2, DB::table('audit_log')->count());
    }

    #[Test]
    public function testMissingSourceReturns422AndWritesNothing(): void {
        $this->actingAs($this->makeUser(email: 'si-src@example.com'));

        $p = $this->payload();
        $p['changes']['source_id'] = 999999; // 不在 TEXT_CODES
        $this->postJson('/api/v2/create', $p)->assertStatus(422);
        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_CODES')->count());
        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->count());
    }

    #[Test]
    public function testMissingAddressReturns422(): void {
        $this->actingAs($this->makeUser(email: 'si-addr@example.com'));

        $p = $this->payload();
        $p['changes']['addr_id'] = 88888; // 不在 ADDR_CODES
        $this->postJson('/api/v2/create', $p)->assertStatus(422);
        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_CODES')->count());
    }

    #[Test]
    public function testTypeAndDynastyLabelsResolveToCodes(): void {
        $this->actingAs($this->makeUser(email: 'si-label@example.com'));

        $p = $this->payload();
        unset($p['changes']['type_code'], $p['changes']['dynasty_code']);
        $p['changes']['type_label'] = '書院';
        $p['changes']['dynasty_label'] = '宋';
        $this->postJson('/api/v2/create', $p)->assertOk();
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_type_code' => 3, 'c_inst_begin_dy' => 15]);
    }

    /**
     * 異體字（plan S4）：以**標籤**指定朝代／類型時，兩個方向都要命中——
     * 表格／payload 寫變體形而代碼表寫參考形，或反過來。
     *
     * 反方向（payload 參考形、代碼表變體形）只靠「歸一傳入標籤」是修不到的：
     * 輸入本來就是參考字、替換後不變，而既有代碼表列在 D6 之下永不歸一。
     * 必須連 map 的鍵一起歸一。
     */
    #[Test]
    public function testDynastyLabelMatchesAcrossVariantForms(): void {
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

        // 代碼表寫**變體形**，payload 送**參考形**。
        DB::table('DYNASTIES')->insert(['c_dy' => 40, 'c_dynasty_chn' => '淸']);
        $this->actingAs($this->makeUser(email: 'si-label-variant@example.com'));

        $p = $this->payload();
        unset($p['changes']['dynasty_code']);
        $p['changes']['dynasty_label'] = '清';
        $this->postJson('/api/v2/create', $p)->assertOk();
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_begin_dy' => 40]);

    }
}
