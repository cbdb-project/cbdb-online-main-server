<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 「新增官職」mutation（resource=office → OFFICE_CODES + OFFICE_CODE_TYPE_REL）回歸測試。
 *
 * 驗證 OfficeImportHandler / OfficeImportService 的複合存儲過程：兩表原子寫入、自動 office_id、
 * 拼音/朝代碼派生、operations + audit_log、以及來源/類型/朝代校驗。
 */
class ApiV2MutateOfficeImportTest extends TestCase {
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
        // 生產 OFFICE_CODES 無 c_created_by/date 審計欄，schema 須如實反映（服務直接 plain insert）。
        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->integer('c_dy')->nullable();
            $table->string('c_office_pinyin')->nullable();
            $table->string('c_office_chn')->nullable();
            $table->string('c_office_pinyin_alt')->nullable();
            $table->string('c_office_chn_alt')->nullable();
            $table->string('c_office_trans')->nullable();
            $table->string('c_office_trans_alt')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });
        Schema::create('OFFICE_CODE_TYPE_REL', function (Blueprint $table) {
            $table->integer('c_office_id');
            $table->string('c_office_tree_id');
            $table->primary(['c_office_id', 'c_office_tree_id']);
        });
        Schema::create('OFFICE_TYPE_TREE', function (Blueprint $table) {
            $table->string('c_office_type_node_id')->primary();
        });
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
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
        // 刪除護欄：referenceCount() 查此表是否仍有人物任官引用該官職。
        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
        });

        DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);
        DB::table('OFFICE_TYPE_TREE')->insert([['c_office_type_node_id' => 'x01'], ['c_office_type_node_id' => 'x02']]);
        DB::table('TEXT_CODES')->insert([['c_textid' => 7596], ['c_textid' => 8000]]);
    }

    protected function tearDown(): void {
        foreach (['POSTED_TO_OFFICE_DATA', 'pinyin', 'TEXT_CODES', 'DYNASTIES', 'OFFICE_TYPE_TREE', 'OFFICE_CODE_TYPE_REL', 'OFFICE_CODES', 'audit_log', 'operations', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'of@example.com'): User {
        return User::create([
            'name' => 'Office Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function payload(array $overrides = []): array {
        return array_merge([
            'resource' => 'office',
            'person_id' => 0,
            'target' => ['pk' => []],
            'changes' => [
                'name' => '知府',
                'translation' => 'Prefect',
                'dynasty_code' => 15,
                'type_id' => 'x01',
                'source_id' => 7596,
            ],
        ], $overrides);
    }

    #[Test]
    public function testCreateWritesBothTablesAtomically(): void {
        DB::table('OFFICE_CODES')->insert(['c_office_id' => 100, 'c_office_chn' => '既有']);
        $this->actingAs($this->makeUser(email: 'of-create@example.com'));

        $res = $this->postJson('/api/v2/create', $this->payload());

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'office',
            'operation' => 'create',
            'result' => ['pk' => ['c_office_id' => 101]],
        ]);
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => 101, 'c_office_chn' => '知府', 'c_dy' => 15, 'c_source' => 7596]);
        $this->assertDatabaseHas('OFFICE_CODE_TYPE_REL', ['c_office_id' => 101, 'c_office_tree_id' => 'x01']);
        // operations + audit：OFFICE_CODES 與 OFFICE_CODE_TYPE_REL 各一
        $this->assertSame(1, DB::table('operations')->where('resource', 'OFFICE_CODES')->count());
        $this->assertSame(1, DB::table('operations')->where('resource', 'OFFICE_CODE_TYPE_REL')->count());
        $this->assertSame(2, DB::table('audit_log')->count());
    }

    #[Test]
    public function testMissingSourceReturns422AndWritesNothing(): void {
        $this->actingAs($this->makeUser(email: 'of-src@example.com'));

        $p = $this->payload();
        $p['changes']['source_id'] = 999999; // 不在 TEXT_CODES
        $this->postJson('/api/v2/create', $p)->assertStatus(422);
        $this->assertSame(0, DB::table('OFFICE_CODES')->count());
    }

    #[Test]
    public function testMissingOfficeTypeReturns422(): void {
        $this->actingAs($this->makeUser(email: 'of-type@example.com'));

        $p = $this->payload();
        $p['changes']['type_id'] = 'nope'; // 不在 OFFICE_TYPE_TREE
        $this->postJson('/api/v2/create', $p)->assertStatus(422);
        $this->assertSame(0, DB::table('OFFICE_CODES')->count());
    }

    #[Test]
    public function testNonScalarTypeIdReturns422(): void {
        $this->actingAs($this->makeUser(email: 'of-arr@example.com'));

        $p = $this->payload();
        $p['changes']['type_id'] = ['x01']; // 非純量（JSON 陣列）須回 422，不可流入 whereIn/insert 造成 500
        $this->postJson('/api/v2/create', $p)->assertStatus(422);
        $this->assertSame(0, DB::table('OFFICE_CODES')->count());
    }

    #[Test]
    public function testDynastyLabelResolvesToCode(): void {
        $this->actingAs($this->makeUser(email: 'of-dyn@example.com'));

        $p = $this->payload();
        unset($p['changes']['dynasty_code']);
        $p['changes']['dynasty_label'] = '宋';
        $this->postJson('/api/v2/create', $p)->assertOk();
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_chn' => '知府', 'c_dy' => 15]);
    }

    #[Test]
    public function testUpdateOverwritesFieldsAndReconcilesTypes(): void {
        $this->actingAs($this->makeUser(email: 'of-upd@example.com'));
        // 先建一個 type=x01 的官職。
        $this->postJson('/api/v2/create', $this->payload())->assertOk();
        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');

        // 更新：改名/來源，類型集合改為 [x01, x02]（x01 保留、x02 新增）。
        $res = $this->postJson('/api/v2/mutate', [
            'resource' => 'office',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
            'changes' => [
                'name' => '知州',
                'translation' => 'Prefect II',
                'dynasty_code' => 15,
                'type_ids' => ['x01', 'x02'],
                'source_id' => 8000,
            ],
        ]);
        $res->assertOk()->assertJson(['operation' => 'update', 'result' => ['status' => 'updated']]);
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => $officeId, 'c_office_chn' => '知州', 'c_source' => 8000]);
        $this->assertSame(2, DB::table('OFFICE_CODE_TYPE_REL')->where('c_office_id', $officeId)->count());

        // 再更新：類型集合縮為 [x02]（x01 應被對賬刪除，非整批重寫多刪）。
        $this->postJson('/api/v2/mutate', [
            'resource' => 'office',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
            'changes' => [
                'name' => '知州',
                'dynasty_code' => 15,
                'type_ids' => ['x02'],
                'source_id' => 8000,
            ],
        ])->assertOk();
        $this->assertDatabaseMissing('OFFICE_CODE_TYPE_REL', ['c_office_id' => $officeId, 'c_office_tree_id' => 'x01']);
        $this->assertDatabaseHas('OFFICE_CODE_TYPE_REL', ['c_office_id' => $officeId, 'c_office_tree_id' => 'x02']);
    }

    #[Test]
    public function testFullFieldsRoundTripAndPinyinAutoWhenBlank(): void {
        $this->actingAs($this->makeUser(email: 'of-full@example.com'));

        // create：帶齊選填欄 + 手動拼音（手動值須逐字採用、不派生）。
        $p = $this->payload();
        $p['changes'] = array_merge($p['changes'], [
            'name_alt' => '知府別名',
            'translation_alt' => 'Prefect alt',
            'pinyin' => 'zhi fu manual',
            'pinyin_alt' => 'zhi fu bie ming',
            'pages' => 'p.12',
            'notes' => '測試備註',
        ]);
        $this->postJson('/api/v2/create', $p)->assertOk();
        $this->assertDatabaseHas('OFFICE_CODES', [
            'c_office_chn' => '知府',
            'c_office_chn_alt' => '知府別名',
            'c_office_pinyin' => 'zhi fu manual',
            'c_office_pinyin_alt' => 'zhi fu bie ming',
            'c_office_trans_alt' => 'Prefect alt',
            'c_pages' => 'p.12',
            'c_notes' => '測試備註',
        ]);
        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');

        // update：留空 pinyin → 依名稱自動派生；不帶 name_alt → 折成 null；改 notes。
        $this->postJson('/api/v2/mutate', [
            'resource' => 'office',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
            'changes' => [
                'name' => '知州',
                'dynasty_code' => 15,
                'type_ids' => ['x01'],
                'source_id' => 7596,
                'pinyin' => '',
                'notes' => '改後備註',
            ],
        ])->assertOk();

        $row = DB::table('OFFICE_CODES')->where('c_office_id', $officeId)->first();
        $this->assertSame('改後備註', $row->c_notes);
        $this->assertNotSame('', (string) $row->c_office_pinyin); // 留空後派生出非空拼音
        $this->assertNull($row->c_office_chn_alt); // update 未帶 name_alt → null
        $this->assertNull($row->c_pages); // update 未帶 pages → null
    }

    #[Test]
    public function testUpdateMissingOfficeReturns404(): void {
        $this->actingAs($this->makeUser(email: 'of-u404@example.com'));
        $this->postJson('/api/v2/mutate', [
            'resource' => 'office',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => 999999]],
            'changes' => ['name' => '知府', 'dynasty_code' => 15, 'type_ids' => ['x01'], 'source_id' => 7596],
        ])->assertStatus(404);
    }

    #[Test]
    public function testDeleteRemovesAggregateWhenUnreferenced(): void {
        $this->actingAs($this->makeUser(email: 'of-del@example.com'));
        $this->postJson('/api/v2/create', $this->payload())->assertOk();
        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');

        $this->postJson('/api/v2/delete', [
            'resource' => 'office',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
        ])->assertOk()->assertJson(['operation' => 'delete', 'result' => ['status' => 'deleted']]);

        $this->assertDatabaseMissing('OFFICE_CODES', ['c_office_id' => $officeId]);
        $this->assertSame(0, DB::table('OFFICE_CODE_TYPE_REL')->where('c_office_id', $officeId)->count());
    }

    #[Test]
    public function testDeleteBlockedWhenReferencedByPostings(): void {
        $this->actingAs($this->makeUser(email: 'of-delref@example.com'));
        $this->postJson('/api/v2/create', $this->payload())->assertOk();
        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');
        DB::table('POSTED_TO_OFFICE_DATA')->insert(['c_personid' => 7, 'c_office_id' => $officeId, 'c_posting_id' => 1]);

        $this->postJson('/api/v2/delete', [
            'resource' => 'office',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
        ])->assertStatus(409);

        // 未刪：仍在。
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => $officeId]);
    }
}
