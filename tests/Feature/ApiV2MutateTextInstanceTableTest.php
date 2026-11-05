<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TEXT_INSTANCE_DATA（文獻版本表）的 v2 create／整列 update。
 *
 * 原本只有 update 端、白名單只開 c_instance_title／c_pub_year／c_publisher 三欄，沒有新增入口。
 * 三欄複合主鍵全由呼叫端給定（沒有 auto_assign）。刪除仍全面停用（403）。
 */
class ApiV2MutateTextInstanceTableTest extends TestCase {
    private const PK = ['c_textid' => 4452, 'c_text_edition_id' => 1, 'c_text_instance_id' => 2];

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
        Schema::create('TEXT_INSTANCE_DATA', function (Blueprint $table) {
            $table->integer('c_textid');
            $table->smallInteger('c_text_edition_id');
            $table->smallInteger('c_text_instance_id');
            $table->string('c_instance_title_chn')->nullable();
            $table->string('c_instance_title')->nullable();
            $table->string('c_instance_title_trans')->nullable();
            $table->integer('c_part_of_instance')->nullable();
            $table->string('c_part_of_instance_notes')->nullable();
            $table->smallInteger('c_pub_country')->nullable();
            $table->smallInteger('c_pub_dy')->nullable();
            $table->smallInteger('c_pub_year')->nullable();
            $table->smallInteger('c_pub_nh_code')->nullable();
            $table->smallInteger('c_pub_nh_year')->nullable();
            $table->smallInteger('c_pub_range_code')->nullable();
            $table->string('c_pub_loc')->nullable();
            $table->string('c_publisher')->nullable();
            $table->string('c_print')->nullable();
            $table->string('c_pub_notes')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->smallInteger('c_extant')->nullable();
            $table->string('c_url_api')->nullable();
            $table->string('c_url_homepage')->nullable();
            $table->longText('c_notes')->nullable();
            $table->string('c_number')->nullable();
            $table->string('c_counter')->nullable();
            $table->string('c_title_alt_chn')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary(['c_textid', 'c_text_edition_id', 'c_text_instance_id']);
        });
    }

    protected function tearDown(): void {
        foreach (['TEXT_INSTANCE_DATA', 'audit_log', 'operations', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function makeUser(int $status = User::STATUS_ACTIVE, string $email = 'ti-tester@example.com'): User {
        return User::forceCreate([
            'name' => 'ti tester',
            'email' => $email,
            'confirmation_token' => 'token-ti',
            'is_active' => $status,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    private function create(array $pk, array $changes, string $resource = 'text-instance-data') {
        return $this->postJson('/api/v2/create', [
            'resource' => $resource,
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => $pk],
            'changes' => $changes,
        ]);
    }

    #[Test]
    public function testCreateWritesFullRowStampsAuditAndRecordsOperation(): void {
        $this->actingAs($this->makeUser());

        $this->create(self::PK, [
            'c_instance_title' => 'Shi Ji',
            'c_instance_title_chn' => '史記',
            'c_pub_year' => 1200,
            'c_publisher' => '某書坊',
            'c_pub_loc' => '建陽',
            'c_extant' => 1,
            'c_notes' => str_repeat('長', 300),
        ])->assertOk()->assertJson([
            'resource' => 'text-instance-data',
            'result' => ['pk' => self::PK, 'status' => 'created'],
        ]);

        $row = (array) DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 4452)->first();
        $this->assertSame('史記', $row['c_instance_title_chn']);
        $this->assertSame(1200, (int) $row['c_pub_year']);
        $this->assertSame(300, mb_strlen($row['c_notes']), 'c_notes 是 longtext，不套 255 上限');
        $this->assertNotEmpty($row['c_created_by']);
        $this->assertNotEmpty($row['c_created_date']);
        $this->assertSame(1, Operation::where('resource', 'TEXT_INSTANCE_DATA')->count());
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'TEXT_INSTANCE_DATA')->count());
    }

    #[Test]
    public function testCreateAndUpdateApplyVariantReplacementToChineseColumns(): void {
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();
        });
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ]);
        \App\Services\CharVariantMapService::reset();
        \App\Support\VariantReplaceScope::reset();

        try {
            $this->actingAs($this->makeUser(email: 'ti-variant@example.com'));

            $this->create(self::PK, ['c_publisher' => '淸華書局', 'c_pub_loc' => '淸江'])->assertOk();
            $row = DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 4452)->first();
            $this->assertSame('清華書局', $row->c_publisher);
            $this->assertSame('清江', $row->c_pub_loc);

            $this->postJson('/api/v2/mutate', [
                'resource' => 'text_instance_data',
                'person_id' => 0,
                'mode' => 'direct',
                'operation' => 'update',
                'target' => ['pk' => self::PK],
                'changes' => ['c_print' => '淸刻本'],
            ])->assertOk();
            $this->assertSame('清刻本', DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 4452)->value('c_print'));
        } finally {
            Schema::dropIfExists('char_variant_map');
            \App\Services\CharVariantMapService::reset();
            \App\Support\VariantReplaceScope::reset();
        }
    }

    #[Test]
    public function testCreateWorksUnderUpdateStyleAlias(): void {
        $this->actingAs($this->makeUser(email: 'ti-alias@example.com'));

        $this->create(self::PK, ['c_pages' => '3'], 'text_instance')->assertOk();
        $this->assertDatabaseHas('TEXT_INSTANCE_DATA', ['c_textid' => 4452, 'c_pages' => '3']);
    }

    #[Test]
    public function testCreateRequiresEveryKeyColumn(): void {
        $this->actingAs($this->makeUser(email: 'ti-missing-key@example.com'));

        $this->create(['c_textid' => 4452, 'c_text_edition_id' => 1], ['c_pages' => '3'])
            ->assertStatus(422)
            ->assertJsonFragment(['target.pk.c_text_instance_id' => ['required']]);

        $this->assertSame(0, DB::table('TEXT_INSTANCE_DATA')->count());
    }

    #[Test]
    public function testCreateRejectsNonNumericKeyAndWrongTypes(): void {
        $this->actingAs($this->makeUser(email: 'ti-badtypes@example.com'));

        $this->create(['c_textid' => 4452, 'c_text_edition_id' => 'abc', 'c_text_instance_id' => 2], ['c_pages' => '1'])
            ->assertStatus(422)
            ->assertJsonFragment(['target.pk.c_text_edition_id' => ['numeric']]);

        $this->create(self::PK, ['c_pub_year' => 'not-a-year'])->assertStatus(422);
        $this->create(self::PK, ['c_notes_typo' => 'x'])->assertStatus(422);

        $this->assertSame(0, DB::table('TEXT_INSTANCE_DATA')->count());
    }

    #[Test]
    public function testCreateRejectsDuplicateKeyWith409(): void {
        $this->actingAs($this->makeUser(email: 'ti-dup@example.com'));
        DB::table('TEXT_INSTANCE_DATA')->insert(self::PK);

        $this->create(self::PK, ['c_pages' => '1'])->assertStatus(409);
        $this->assertSame(1, DB::table('TEXT_INSTANCE_DATA')->count());
    }

    #[Test]
    public function testCreateForbiddenForInactiveUser(): void {
        $this->actingAs($this->makeUser(User::STATUS_INACTIVE, 'ti-inactive@example.com'));

        $this->create(self::PK, ['c_pages' => '1'])->assertStatus(403);
        $this->assertSame(0, DB::table('TEXT_INSTANCE_DATA')->count());
    }

    #[Test]
    public function testUpdateAcceptsEveryCreatableField(): void {
        $this->actingAs($this->makeUser(email: 'ti-update@example.com'));
        DB::table('TEXT_INSTANCE_DATA')->insert(self::PK);

        $changes = [
            'c_instance_title_chn' => '史記集解', 'c_instance_title' => 'Shi Ji Jijie',
            'c_instance_title_trans' => 'Records', 'c_part_of_instance' => 7,
            'c_part_of_instance_notes' => 'n', 'c_pub_country' => 3, 'c_pub_dy' => 15,
            'c_pub_year' => 1195, 'c_pub_nh_code' => 20, 'c_pub_nh_year' => 4,
            'c_pub_range_code' => 5, 'c_pub_loc' => '建陽', 'c_publisher' => '書坊',
            'c_print' => '刻本', 'c_pub_notes' => 'pn', 'c_source' => 99, 'c_pages' => '1-2',
            'c_extant' => 1, 'c_url_api' => 'http://a', 'c_url_homepage' => 'http://h',
            'c_notes' => str_repeat('註', 300), 'c_number' => '12', 'c_counter' => '卷',
            'c_title_alt_chn' => '別名',
        ];

        $this->postJson('/api/v2/mutate', [
            'resource' => 'text_instance_data',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => self::PK],
            'changes' => $changes,
        ])->assertOk();

        $row = (array) DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 4452)->first();
        foreach ($changes as $col => $val) {
            $this->assertEquals($val, $row[$col], "$col 未寫入");
        }
        $this->assertNotEmpty($row['c_modified_by']);
    }

    #[Test]
    public function testUpdateStillRejectsPrimaryKeyChangeAndClearsViaEmptyString(): void {
        $this->actingAs($this->makeUser(email: 'ti-pk@example.com'));
        DB::table('TEXT_INSTANCE_DATA')->insert(self::PK + ['c_pub_year' => 1200]);

        $mutate = fn (array $changes) => $this->postJson('/api/v2/mutate', [
            'resource' => 'text_instance_data',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => self::PK],
            'changes' => $changes,
        ]);

        $mutate(['c_text_instance_id' => 9])->assertStatus(422);
        $mutate(['c_pub_year' => ''])->assertOk();
        $this->assertNull(DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 4452)->value('c_pub_year'));
    }

    #[Test]
    public function testUpdateOnUnknownPkReturns404(): void {
        $this->actingAs($this->makeUser(email: 'ti-404@example.com'));

        $this->postJson('/api/v2/mutate', [
            'resource' => 'text_instance_data',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => self::PK],
            'changes' => ['c_pages' => 'x'],
        ])->assertStatus(404);
    }

    #[Test]
    public function testDeleteRemainsDisabled(): void {
        $this->actingAs($this->makeUser(email: 'ti-delete@example.com'));
        DB::table('TEXT_INSTANCE_DATA')->insert(self::PK);

        $this->postJson('/api/v2/delete', [
            'resource' => 'text-instance-data',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => self::PK],
        ])->assertStatus(403);

        $this->assertSame(1, DB::table('TEXT_INSTANCE_DATA')->count());
    }
}
