<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsProposalControllerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->boolean('is_active')->default(0);
            $table->boolean('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::dropIfExists('operations');
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

        Schema::dropIfExists('audit_log');
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

        Schema::dropIfExists('TEST_CODES');
        Schema::create('TEST_CODES', function (Blueprint $table) {
            $table->string('code_id');
            $table->string('code_sub');
            $table->string('description')->nullable();
        });

        Schema::dropIfExists('TEST_SINGLE');
        Schema::create('TEST_SINGLE', function (Blueprint $table) {
            $table->integer('id');
            $table->string('description')->nullable();
        });

        Schema::dropIfExists('BIOG_SOURCE_DATA');
        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->string('c_pages');
            $table->text('c_notes')->nullable();
            $table->integer('c_main_source')->default(0);
            $table->integer('c_self_bio')->default(0);
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });

        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
            $table->text('c_notes')->nullable();
        });

        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id');
            $table->integer('c_addr_id')->default(0);
        });

        Schema::dropIfExists('POSTING_DATA');
        Schema::create('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_posting_id')->primary();
            $table->integer('c_personid')->default(0);
        });

        Schema::dropIfExists('ENTRY_DATA');
        Schema::create('ENTRY_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_entry_code');
            $table->integer('c_sequence');
            $table->integer('c_kin_code');
            $table->integer('c_assoc_code');
            $table->integer('c_kin_id');
            $table->integer('c_year');
            $table->integer('c_assoc_id');
            $table->integer('c_inst_code');
            $table->integer('c_inst_name_code');
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });

        Schema::dropIfExists('ASSOC_DATA');
        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_assoc_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_assoc_kin_code')->default(0);
            $table->integer('c_assoc_kin_id')->default(0);
            $table->string('c_text_title', 255)->default('');
            $table->integer('c_assoc_first_year')->default(-9999);
            $table->integer('c_source')->default(0);
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary([
                'c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id',
                'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year',
            ]);
        });

        Schema::dropIfExists('ASSOC_CODES');
        Schema::create('ASSOC_CODES', function (Blueprint $table) {
            $table->integer('c_assoc_code')->primary();
            $table->integer('c_assoc_pair')->nullable();
            $table->integer('c_assoc_pair2')->nullable();
        });
        DB::table('ASSOC_CODES')->insert([
            ['c_assoc_code' => 100, 'c_assoc_pair' => 101, 'c_assoc_pair2' => null],
            ['c_assoc_code' => 101, 'c_assoc_pair' => 100, 'c_assoc_pair2' => null],
        ]);
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ASSOC_CODES');
        Schema::dropIfExists('ASSOC_DATA');
        Schema::dropIfExists('POSTING_DATA');
        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('ENTRY_DATA');
        Schema::dropIfExists('BIOG_SOURCE_DATA');
        Schema::dropIfExists('TEST_SINGLE');
        Schema::dropIfExists('TEST_CODES');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeAdmin(): User {
        $user = new User([
            'name' => 'admin',
            'email' => 'admin@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 100;
        $user->is_active = 1;
        $user->is_admin = 1;
        $user->save();

        return $user;
    }

    protected function proposalOperation(array $attributes = []): Operation {
        $operation = new Operation();
        $operation->user_id = $attributes['user_id'] ?? 100;
        $operation->c_personid = 0;
        $operation->op_type = $attributes['op_type'] ?? Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = $attributes['resource'] ?? 'TEST_CODES';
        $operation->resource_id = $attributes['resource_id'] ?? 'TEST';
        $operation->resource_data = json_encode($attributes['resource_data'], JSON_UNESCAPED_UNICODE);
        $operation->resource_original = json_encode($attributes['resource_original'] ?? [], JSON_UNESCAPED_UNICODE);
        $operation->save();

        return $operation;
    }

    #[Test]
    public function testApproveCreateProposalInsertsRow() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'code_id' => 'AP',
            'code_sub' => '01',
            'description' => 'Approved create',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['submitted_by' => 'tester', 'submitted_at' => Carbon::now()->format('Y-m-d H:i:s')],
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_id' => 'AP_._01',
            'resource_data' => $resourceData,
        ]);

        $storedPayload = json_decode($operation->resource_data, true);
        $this->assertSame('AP', $storedPayload['code_id']);
        $this->assertSame(['code_id', 'code_sub'], $storedPayload['__key_columns']);

        $response = $this->post(route('operations.proposals.approve', $operation));

        $response->assertRedirect();

        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('已核准', $flash[0]['message'] ?? '');

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status'] ?? null);

        $this->assertDatabaseHas('TEST_CODES', [
            'code_id' => 'AP',
            'code_sub' => '01',
            'description' => 'Approved create',
        ]);

        $this->assertSame($admin->name, $payload['__reviewed_by']);

        $this->assertDatabaseHas('operations', [
            'resource' => 'TEST_CODES',
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testApproveCreateProposalReassignsSingleNumericKey() {
        DB::table('TEST_SINGLE')->insert([
            'id' => 5,
            'description' => 'Existing',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'id' => 5,
            'description' => 'Approved create',
            '__key_columns' => ['id'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['submitted_by' => 'tester', 'submitted_at' => Carbon::now()->format('Y-m-d H:i:s')],
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'TEST_SINGLE',
            'resource_id' => '5',
            'resource_data' => $resourceData,
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertRedirect();

        $this->assertDatabaseHas('TEST_SINGLE', [
            'id' => 6,
            'description' => 'Approved create',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
        $this->assertSame(6, $payload['id']);
        $this->assertSame('6', $payload['__proposal_meta']['approved_resource_id'] ?? null);
        $this->assertSame('6', $operation->resource_id);
    }

    #[Test]
    public function testApproveUpdateProposalUpdatesRow() {
        \DB::table('TEST_CODES')->insert([
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'Before',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'After',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource_id' => 'UP_._02',
            'resource_data' => $resourceData,
            'resource_original' => [
                'code_id' => 'UP',
                'code_sub' => '02',
                'description' => 'Before',
            ],
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => 'Looks good',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('TEST_CODES', [
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'After',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
        $this->assertSame('Looks good', $payload['__review_comment']);

        $this->assertDatabaseHas('operations', [
            'resource' => 'TEST_CODES',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testApproveCreateProposalAllowsEmptySourcePages() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '',
            'c_notes' => 'Approved source',
            'c_main_source' => 1,
            'c_self_bio' => 0,
            '__key_columns' => ['c_personid', 'c_textid', 'c_pages'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['submitted_by' => 'tester', 'submitted_at' => Carbon::now()->format('Y-m-d H:i:s')],
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'BIOG_SOURCE_DATA',
            'resource_id' => 'c_personid=138841&c_textid=99999&c_pages=',
            'resource_data' => $resourceData,
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation));

        $response->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status'] ?? null);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '',
            'c_notes' => 'Approved source',
            'c_main_source' => 1,
        ]);

        $row = DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', 138841],
            ['c_textid', '=', 99999],
            ['c_pages', '=', ''],
        ])->first();
        $this->assertSame('admin', $row->c_created_by);
        $this->assertNotNull($row->c_created_date);
    }

    #[Test]
    public function testApproveCreateEntryProposalSetsCreatedAuditFields() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'c_personid' => 138841,
            'c_entry_code' => 39,
            'c_sequence' => 1,
            'c_kin_code' => 0,
            'c_assoc_code' => 0,
            'c_kin_id' => 0,
            'c_year' => 1351,
            'c_assoc_id' => 0,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            '__key_columns' => ['c_personid', 'c_entry_code', 'c_sequence', 'c_kin_code', 'c_assoc_code', 'c_kin_id', 'c_year', 'c_assoc_id', 'c_inst_code', 'c_inst_name_code'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['submitted_by' => 'tester', 'submitted_at' => Carbon::now()->format('Y-m-d H:i:s')],
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'ENTRY_DATA',
            'resource_id' => 'entry-proposal',
            'resource_data' => $resourceData,
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation));

        $response->assertRedirect();

        $row = DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', 138841],
            ['c_entry_code', '=', 39],
            ['c_sequence', '=', 1],
            ['c_kin_code', '=', 0],
            ['c_assoc_code', '=', 0],
            ['c_kin_id', '=', 0],
            ['c_year', '=', 1351],
            ['c_assoc_id', '=', 0],
            ['c_inst_code', '=', 0],
            ['c_inst_name_code', '=', 0],
        ])->first();

        $this->assertNotNull($row);
        $this->assertSame('admin', $row->c_created_by);
        $this->assertNotNull($row->c_created_date);
    }

    #[Test]
    public function testApproveUpdateProposalAllowsCompositePrimaryKeyChange(): void {
        DB::table('TEST_CODES')->insert([
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'Before',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'code_id' => 'UP',
            'code_sub' => '03',
            'description' => 'After PK changed',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource_id' => 'UP_._02',
            'resource_data' => $resourceData,
            'resource_original' => [
                'code_id' => 'UP',
                'code_sub' => '02',
                'description' => 'Before',
            ],
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => '允許修改主鍵欄位',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseMissing('TEST_CODES', [
            'code_id' => 'UP',
            'code_sub' => '02',
        ]);
        $this->assertDatabaseHas('TEST_CODES', [
            'code_id' => 'UP',
            'code_sub' => '03',
            'description' => 'After PK changed',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
    }

    #[Test]
    public function testApproveUpdateProposalReadbackUsesUnchangedOriginalKeyRepresentation(): void {
        DB::table('TEST_CODES')->insert([
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'Before',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'code_id' => 'UP',
            'code_sub' => '2',
            'description' => 'After normalize-equal key',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource_id' => 'UP_._02',
            'resource_data' => $resourceData,
            'resource_original' => [
                'code_id' => 'UP',
                'code_sub' => '02',
                'description' => 'Before',
            ],
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertRedirect();

        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('提案已核准並套用至資料表', $flash[0]['message'] ?? '');

        $this->assertDatabaseHas('TEST_CODES', [
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'After normalize-equal key',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
    }

    #[Test]
    public function testRejectProposalUpdatesStatus() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'code_id' => 'RJ',
            'code_sub' => '03',
            'description' => 'Reject me',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_id' => 'RJ_._03',
            'resource_data' => $resourceData,
        ]);

        $response = $this->post(route('operations.proposals.reject', $operation), [
            'review_comment' => 'Not acceptable',
        ]);

        $response->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('rejected', $payload['__review_status']);
        $this->assertSame('Not acceptable', $payload['__review_comment']);
    }

    #[Test]
    public function testApproveDeleteProposalRemovesRow(): void {
        DB::table('TEST_CODES')->insert([
            'code_id' => 'DL',
            'code_sub' => '01',
            'description' => 'Delete me',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $original = [
            'code_id' => 'DL',
            'code_sub' => '01',
            'description' => 'Delete me',
        ];

        $resourceData = array_merge($original, [
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'delete', 'submitted_by' => 'tester'],
        ]);

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
            'resource_id' => 'DL_._01',
            'resource_data' => $resourceData,
            'resource_original' => $original,
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => '同意刪除',
        ]);
        $response->assertRedirect();

        // 目標列確實被刪
        $this->assertDatabaseMissing('TEST_CODES', ['code_id' => 'DL', 'code_sub' => '01']);

        // __review_status=approved
        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
        $this->assertSame('同意刪除', $payload['__review_comment']);

        // 寫入 TYPE_DELETE final operation
        $this->assertDatabaseHas('operations', [
            'resource' => 'TEST_CODES',
            'op_type' => Operation::TYPE_DELETE,
        ]);

        // audit DELETE 寫入：old=original, new=null
        $audit = DB::table('audit_log')->where('table_name', 'TEST_CODES')->where('operation', 'DELETE')->first();
        $this->assertNotNull($audit);
        $this->assertNotNull($audit->old_data);
        $this->assertNull($audit->new_data);
    }

    #[Test]
    public function testApproveDeleteAssocProposalRemovesReciprocalMirror(): void {
        // SEVERE 修復：核准社會關係刪除提案時，反向鏡像列須同步刪除（避免留下單向孤兒）。
        $assocPk = [
            'c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000,
            'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
            'c_text_title' => '史記', 'c_assoc_first_year' => 1080,
        ];
        DB::table('ASSOC_DATA')->insert(array_merge($assocPk, ['c_source' => 10]));
        // 反向鏡像（對方 2000 擁有、反向碼 101、對稱 0,0 → 策略 1 可定位）。
        DB::table('ASSOC_DATA')->insert(array_merge($assocPk, [
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000, 'c_source' => 10,
        ]));

        $this->actingAs($this->makeAdmin());

        $resourceData = array_merge($assocPk, [
            '__key_columns' => array_keys($assocPk),
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'delete', 'submitted_by' => 'tester'],
        ]);
        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
            'resource' => 'ASSOC_DATA',
            'resource_id' => '1000-100-2000-0-0-0-0-史記-1080',
            'resource_data' => $resourceData,
            'resource_original' => $assocPk,
        ]);

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '同意刪除'])
            ->assertRedirect();

        // 正向已刪。
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);
        // 反向鏡像同步刪除（雙向，修復前會殘留）。
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000]);

        // 審計鏈完整：反向鏡像 DELETE audit 掛 final delete operation id（與正向一致，非 null）。
        $finalOp = DB::table('operations')->where('resource', 'ASSOC_DATA')
            ->where('op_type', Operation::TYPE_DELETE)->latest('id')->first();
        $this->assertNotNull($finalOp);
        $mirrorAudit = DB::table('audit_log')->where('table_name', 'ASSOC_DATA')->where('operation', 'DELETE')
            ->where('old_data', 'like', '%"c_personid":2000%')->first();
        $this->assertNotNull($mirrorAudit);
        $this->assertSame((string) $finalOp->id, (string) $mirrorAudit->operation_id);
    }

    #[Test]
    public function testApproveDeleteProposalRemovesOfficeAndAuxiliaryTables(): void {
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 1000,
            'c_office_id' => 50,
            'c_posting_id' => 7,
            'c_notes' => 'office',
        ]);
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            ['c_personid' => 1000, 'c_posting_id' => 7, 'c_office_id' => 50, 'c_addr_id' => 130],
            ['c_personid' => 1000, 'c_posting_id' => 7, 'c_office_id' => 50, 'c_addr_id' => 200],
        ]);
        DB::table('POSTING_DATA')->insert(['c_posting_id' => 7, 'c_personid' => 1000]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $original = [
            'c_personid' => 1000,
            'c_office_id' => 50,
            'c_posting_id' => 7,
            'c_notes' => 'office',
        ];

        $resourceData = array_merge($original, [
            '__key_columns' => ['c_office_id', 'c_posting_id'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'delete'],
        ]);

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => '50_._7',
            'resource_data' => $resourceData,
            'resource_original' => $original,
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertRedirect();

        // 主表與副表一併刪除
        $this->assertDatabaseMissing('POSTED_TO_OFFICE_DATA', ['c_office_id' => 50, 'c_posting_id' => 7]);
        $this->assertSame(0, DB::table('POSTED_TO_ADDR_DATA')->where('c_posting_id', 7)->count());
        $this->assertSame(0, DB::table('POSTING_DATA')->where('c_posting_id', 7)->count());

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'op_type' => Operation::TYPE_DELETE,
        ]);
    }

    #[Test]
    public function testRejectDeleteProposalKeepsRow(): void {
        DB::table('TEST_CODES')->insert([
            'code_id' => 'RK',
            'code_sub' => '02',
            'description' => 'Keep me',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $original = [
            'code_id' => 'RK',
            'code_sub' => '02',
            'description' => 'Keep me',
        ];

        $resourceData = array_merge($original, [
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'delete'],
        ]);

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
            'resource_id' => 'RK_._02',
            'resource_data' => $resourceData,
            'resource_original' => $original,
        ]);

        $response = $this->post(route('operations.proposals.reject', $operation), [
            'review_comment' => '不同意刪除',
        ]);
        $response->assertRedirect();

        // 目標列保留
        $this->assertDatabaseHas('TEST_CODES', ['code_id' => 'RK', 'code_sub' => '02', 'description' => 'Keep me']);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('rejected', $payload['__review_status']);
        $this->assertSame('不同意刪除', $payload['__review_comment']);

        // 無 final DELETE operation、無 audit DELETE
        $this->assertDatabaseMissing('operations', ['resource' => 'TEST_CODES', 'op_type' => Operation::TYPE_DELETE]);
        $this->assertSame(0, DB::table('audit_log')->where('operation', 'DELETE')->count());
    }
}
