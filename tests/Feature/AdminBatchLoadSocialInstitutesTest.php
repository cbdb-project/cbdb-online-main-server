<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CharVariantMapService;
use App\Support\VariantReplaceScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsPinyinDictionary;
use Tests\TestCase;

class AdminBatchLoadSocialInstitutesTest extends TestCase {
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

        Schema::create('SOCIAL_INSTITUTION_NAME_CODES', function (Blueprint $table) {
            $table->integer('c_inst_name_code')->primary();
            $table->string('c_inst_name_hz')->nullable();
            $table->string('c_inst_name_py')->nullable();
        });

        Schema::create('SOCIAL_INSTITUTION_CODES', function (Blueprint $table) {
            $table->integer('c_inst_code')->primary();
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_type_code')->nullable();
            $table->integer('c_inst_begin_dy')->nullable();
            $table->integer('c_inst_floruit_dy')->nullable();
            $table->integer('c_source')->nullable();
        });

        Schema::create('SOCIAL_INSTITUTION_ADDR', function (Blueprint $table) {
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_code');
            $table->integer('c_inst_addr_type_code');
            $table->integer('c_inst_addr_id');
            $table->double('inst_xcoord');
            $table->double('inst_ycoord');
            $table->integer('c_source')->nullable();
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

        // SocialInstituteImportService 經共用 recordOp 寫 operations + audit_log。
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

        // 機構名逐字轉拼音走一般轉換路徑，需要真實字典資料才能跟現行
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
        Schema::dropIfExists('ADDR_CODES');
        Schema::dropIfExists('DYNASTIES');
        Schema::dropIfExists('SOCIAL_INSTITUTION_TYPES');
        Schema::dropIfExists('SOCIAL_INSTITUTION_ADDR');
        Schema::dropIfExists('SOCIAL_INSTITUTION_CODES');
        Schema::dropIfExists('SOCIAL_INSTITUTION_NAME_CODES');
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

    #[Test]
    public function test_admin_can_view_form(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->get(route('admin.batch-load-social-institutes'));
        $response->assertStatus(200)->assertSee('批次匯入社會機構');
    }

    #[Test]
    public function test_admin_can_upload_new_institution(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('SOCIAL_INSTITUTION_TYPES')->insert([
            'c_inst_type_code' => 10,
            'c_inst_type_hz' => '書院',
            'c_inst_type_py' => 'shuyuan',
        ]);

        DB::table('DYNASTIES')->insert([
            'c_dy' => 40,
            'c_dynasty_chn' => '清',
        ]);

        DB::table('ADDR_CODES')->insert([
            'c_addr_id' => 7793,
        ]);

        DB::table('TEXT_CODES')->insert([
            'c_textid' => 4763,
        ]);

        $response = $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "南浦書院\t書院\t清\t浦城\t7793\t4763",
        ]);

        $response->assertRedirect(route('admin.batch-load-social-institutes'));

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', [
            'c_inst_name_code' => 1,
            'c_inst_name_hz' => '南浦書院',
            'c_inst_name_py' => 'nan pu shu yuan',
        ]);

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', [
            'c_inst_code' => 1,
            'c_inst_name_code' => 1,
            'c_inst_type_code' => 10,
            'c_inst_begin_dy' => 40,
            'c_inst_floruit_dy' => 40,
            'c_source' => 4763,
        ]);

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', [
            'c_inst_name_code' => 1,
            'c_inst_code' => 1,
            'c_inst_addr_type_code' => 1,
            'c_inst_addr_id' => 7793,
            'inst_xcoord' => 0,
            'inst_ycoord' => 0,
            'c_source' => 4763,
        ]);

        $this->assertSame(3, DB::table('operations')->count());

        $followUp = $this->get(route('admin.batch-load-social-institutes'));
        $followUp->assertSee('南浦書院')
            ->assertSee('nan pu shu yuan')
            ->assertSee('書院 / 10')
            ->assertSee('清 / 40')
            ->assertSee('是');
    }

    #[Test]
    public function test_existing_name_reuses_code(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('SOCIAL_INSTITUTION_TYPES')->insert([
            'c_inst_type_code' => 10,
            'c_inst_type_hz' => '書院',
            'c_inst_type_py' => 'shuyuan',
        ]);

        DB::table('DYNASTIES')->insert([
            'c_dy' => 40,
            'c_dynasty_chn' => '清',
        ]);

        DB::table('ADDR_CODES')->insert([
            'c_addr_id' => 7793,
        ]);

        DB::table('TEXT_CODES')->insert([
            'c_textid' => 4763,
        ]);

        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            'c_inst_name_code' => 99,
            'c_inst_name_hz' => '南浦書院',
            'c_inst_name_py' => 'nan pu shu yuan',
        ]);

        $response = $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "南浦書院\t書院\t清\t浦城\t7793\t4763",
        ]);

        $response->assertRedirect(route('admin.batch-load-social-institutes'));

        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->count());
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', [
            'c_inst_name_code' => 99,
        ]);

        $followUp = $this->get(route('admin.batch-load-social-institutes'));
        $followUp->assertSee('否');
    }

    #[Test]
    public function test_invalid_type_results_in_error(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('DYNASTIES')->insert([
            'c_dy' => 40,
            'c_dynasty_chn' => '清',
        ]);

        DB::table('ADDR_CODES')->insert([
            'c_addr_id' => 7793,
        ]);

        DB::table('TEXT_CODES')->insert([
            'c_textid' => 4763,
        ]);

        $response = $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "南浦書院\t未知類型\t清\t浦城\t7793\t4763",
        ]);

        $response->assertRedirect(route('admin.batch-load-social-institutes'));
        $response->assertSessionHas('batch_errors');

        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_CODES')->count());
    }
    // ── 異體字落地替換（plan S4）────────────────────────────

    /**
     * 與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php 同源的
     * 最小種子（只要「淸→清」這一組）。本測試檔其餘測試不建這張表，所以那些測試在
     * CharVariantMapService 的「表不存在就降級」路徑下完全不做替換、行為不變。
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

    protected function seedInstituteLookups(string $dynastyLabel = '清'): void {
        DB::table('SOCIAL_INSTITUTION_TYPES')->insert([
            'c_inst_type_code' => 10, 'c_inst_type_hz' => '書院', 'c_inst_type_py' => 'shuyuan',
        ]);
        DB::table('DYNASTIES')->insert(['c_dy' => 40, 'c_dynasty_chn' => $dynastyLabel]);
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 7793]);
        DB::table('TEXT_CODES')->insert(['c_textid' => 4763]);
    }

    /** (a) 表格寫變體形「淸」、代碼表寫參考形「清」→ 應成功。 */
    #[Test]
    public function test_dynasty_label_in_variant_form_matches_reference_form_code_row(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser());
        $this->seedInstituteLookups('清');

        $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "南浦書院\t書院\t淸\t浦城\t7793\t4763",
        ])->assertRedirect(route('admin.batch-load-social-institutes'));

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 1, 'c_inst_begin_dy' => 40]);
    }

    /**
     * (b) 表格寫參考形「清」、代碼表寫變體形「淸」→ 也要成功。
     *
     * 這個方向只靠「歸一傳入標籤」是修不到的：使用者輸入本來就是參考字、替換後不變，
     * 而既有代碼表列在 D6 之下永不歸一。必須連 map 的鍵一起歸一。
     */
    #[Test]
    public function test_dynasty_label_in_reference_form_matches_variant_form_code_row(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser());
        $this->seedInstituteLookups('淸');

        $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "南浦書院\t書院\t清\t浦城\t7793\t4763",
        ])->assertRedirect(route('admin.batch-load-social-institutes'));

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 1, 'c_inst_begin_dy' => 40]);
    }

    /**
     * (d) 既有 name code 是變體形「淸…」、匯入同樣的「淸…」→ 複用既有碼、不新建，
     * 且該列的名稱文本**保持變體形**。
     *
     * 這鎖住 plan S4 明文記錄的刻意不對稱：為了不製造重複碼而複用既有列，
     * 代價是該列永不歸一（與 D7「觸碰即歸一」相反）。
     */
    #[Test]
    public function test_existing_variant_form_name_code_is_reused_and_not_normalized(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser());
        $this->seedInstituteLookups('清');
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            'c_inst_name_code' => 500, 'c_inst_name_hz' => '淸溪書院', 'c_inst_name_py' => 'qing xi shu yuan',
        ]);

        $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "淸溪書院\t書院\t清\t浦城\t7793\t4763",
        ])->assertRedirect(route('admin.batch-load-social-institutes'));

        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->count(), '不得新建第二個 name code');
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', [
            'c_inst_name_code' => 500,
            'c_inst_name_hz' => '淸溪書院', // 刻意不歸一
        ]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_name_code' => 500]);
    }

    /**
     * (d′) 既有 name code 是參考形、匯入變體形 → 同樣複用，不新建。
     * 這是「只替換傳入值」會漏的反方向。
     */
    #[Test]
    public function test_variant_form_import_reuses_existing_reference_form_name_code(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser());
        $this->seedInstituteLookups('清');
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            'c_inst_name_code' => 500, 'c_inst_name_hz' => '清溪書院', 'c_inst_name_py' => 'qing xi shu yuan',
        ]);

        $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "淸溪書院\t書院\t清\t浦城\t7793\t4763",
        ])->assertRedirect(route('admin.batch-load-social-institutes'));

        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->count());
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_name_code' => 500]);
    }

    /** (e) 同一批裡兩列分別寫「淸」「清」的同一機構名 → 收斂到同一個 name code。 */
    #[Test]
    public function test_two_rows_with_different_variant_forms_converge_to_one_name_code(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser());
        $this->seedInstituteLookups('清');

        $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "淸溪書院\t書院\t清\t浦城\t7793\t4763\n清溪書院\t書院\t清\t浦城\t7793\t4763",
        ])->assertRedirect(route('admin.batch-load-social-institutes'));

        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->count(), '兩列應收斂到同一個 name code');
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_hz' => '清溪書院']);
        $this->assertSame(2, DB::table('SOCIAL_INSTITUTION_CODES')->count(), '機構列仍是兩筆');
    }

    /**
     * 替換紀錄不得跨列殘留：批次匯入是同一個 service 實例逐列呼叫，而
     * lastVariantReplaced 是以 merge 累積的。第一列有替換、第二列沒有時，
     * 第二列的結果頁不能顯示第一列的替換。
     */
    #[Test]
    public function test_variant_replacements_do_not_leak_between_rows(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser());
        $this->seedInstituteLookups('清');

        $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "淸溪書院\t書院\t清\t浦城\t7793\t4763\n白鹿洞書院\t書院\t清\t浦城\t7793\t4763",
        ])->assertRedirect(route('admin.batch-load-social-institutes'));

        $results = session('batch_results');
        $this->assertIsArray($results);
        $this->assertSame([['from' => '淸', 'to' => '清']], $results[0]['variant_replacements']);
        $this->assertSame([], $results[1]['variant_replacements'], '第二列不得帶到第一列的替換紀錄');
    }
}
