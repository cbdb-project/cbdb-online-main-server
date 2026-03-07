<?php

namespace Tests\Feature;

use App\Http\Controllers\BasicInformationProposalController;
use App\Models\Operation;
use App\Models\User;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BasicInformationProposalTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // Mock BiogMainRepository
        $this->app->instance(BiogMainRepository::class, \Mockery::mock(BiogMainRepository::class, function ($mock) {
            $mock->shouldReceive('unionPKDef')->andReturnUsing(function ($value) {
                if ($value === null || $value === '') {
                    return 'NULL';
                }

                return str_replace('-', 'minus', (string) $value);
            });
            $mock->shouldReceive('unionPKDef_decode')->andReturnUsing(function ($value) {
                // Decode composite ID string back to its original form
                return str_replace('minus', '-', (string) $value);
            });
        }));

        // Mock OperationRepository
        $this->app->instance(OperationRepository::class, \Mockery::mock(OperationRepository::class, function ($mock) {
            $mock->shouldReceive('store')->andReturnUsing(function ($userId, $personId, $opType, $resource, $resourceId, $resourceData, $original = '', $crowdsourcingStatus = 0) {
                $operation = new Operation();
                $operation->user_id = $userId;
                $operation->c_personid = $personId;
                $operation->op_type = $opType;
                $operation->resource = $resource;
                $operation->resource_id = $resourceId;
                $operation->resource_data = json_encode($resourceData, JSON_UNESCAPED_UNICODE);
                if (!empty($original)) {
                    $operation->resource_original = json_encode($original, JSON_UNESCAPED_UNICODE);
                }
                if ($crowdsourcingStatus !== 0) {
                    $operation->crowdsourcing_status = $crowdsourcingStatus;
                }
                $operation->save();

                return $operation;
            });
            $mock->shouldReceive('getArrDiff')->andReturnUsing(function ($new, $old, $original) {
                return (new OperationRepository())->getArrDiff($new, $old, $original);
            });
        }));

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
            $table->increments('id');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->string('table_name');
            $table->string('operation');
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('operation_id')->nullable();
            $table->longText('row_pk')->nullable();
            $table->string('row_pk_text')->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
        });

        Schema::dropIfExists('ALTNAME_DATA');
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->nullable();
            $table->string('c_alt_name_chn');
            $table->string('c_alt_name')->nullable();
            $table->integer('c_alt_name_type_code');
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::dropIfExists('POSSESSION_DATA');
        Schema::create('POSSESSION_DATA', function (Blueprint $table) {
            $table->integer('c_personid')->nullable();
            $table->integer('c_possession_record_id')->nullable();
            $table->integer('c_sequence')->nullable();
            $table->integer('c_possession_act_code')->nullable();
            $table->string('c_possession_desc')->nullable();
            $table->string('c_possession_desc_chn')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('EVENTS_ADDR');
        Schema::dropIfExists('EVENTS_DATA');
        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('POSTING_DATA');
        Schema::dropIfExists('ASSOC_DATA');
        Schema::dropIfExists('KIN_DATA');
        Schema::dropIfExists('KINSHIP_CODES');
        Schema::dropIfExists('POSSESSION_DATA');
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeActiveUser(): User {
        $user = User::factory()->create([
            'name' => 'activeuser',
            'email' => 'active@example.com',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => 0,
        ]);

        return $user;
    }

    protected function makeAdmin(): User {
        $user = User::factory()->create([
            'name' => 'admin',
            'email' => 'admin@example.com',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => 1,
        ]);

        return $user;
    }

    protected function makeInactiveUser(): User {
        $user = User::factory()->create([
            'name' => 'inactive',
            'email' => 'inactive@example.com',
            'is_active' => 0,
            'is_admin' => 0,
        ]);

        return $user;
    }

    protected function createKinshipTables(): void {
        Schema::dropIfExists('KIN_DATA');
        Schema::dropIfExists('KINSHIP_CODES');

        Schema::create('KINSHIP_CODES', function (Blueprint $table) {
            $table->integer('c_kincode')->primary();
            $table->integer('c_kin_pair1')->nullable();
            $table->integer('c_kin_pair2')->nullable();
        });

        Schema::create('KIN_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id');
            $table->integer('c_kin_code');
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_autogen_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });
    }

    protected function createAssocTables(): void {
        Schema::dropIfExists('ASSOC_DATA');

        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code');
            $table->integer('c_assoc_id');
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_assoc_kin_code')->default(0);
            $table->integer('c_assoc_kin_id')->default(0);
            $table->string('c_text_title')->default('');
            $table->string('c_assoc_first_year')->default('-9999');
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });
    }

    protected function createOfficeTables(): void {
        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('POSTING_DATA');

        Schema::create('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });

        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id');
            $table->integer('c_sequence')->default(1);
            $table->integer('c_source')->default(0);
            $table->string('c_pages')->nullable();
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_ly_intercalary')->default(0);
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });

        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id');
            $table->integer('c_addr_id');
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });
    }

    protected function createEventTables(): void {
        Schema::dropIfExists('EVENTS_ADDR');
        Schema::dropIfExists('EVENTS_DATA');

        Schema::create('EVENTS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence');
            $table->integer('c_event_code');
            $table->integer('c_source')->default(0);
            $table->integer('c_intercalary')->default(0);
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });

        Schema::create('EVENTS_ADDR', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence');
            $table->integer('c_event_code');
            $table->integer('c_addr_id');
        });
    }

    #[Test]
    public function testProposalStoreRequiresAuthentication() {
        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'altnames',
        ]), [
            'c_sequence' => 1,
            'c_alt_name_chn' => '測試別名',
            'c_alt_name_type_code' => 1,
            '__proposal_comment' => '新增測試',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function testProposalStoreRequiresActiveUser() {
        $user = $this->makeInactiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'altnames',
        ]), [
            'c_sequence' => 1,
            'c_alt_name_chn' => '測試別名',
            'c_alt_name_type_code' => 1,
            '__proposal_comment' => '新增測試',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function testProposalStoreCreatesNewProposal() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'altnames',
        ]), [
            'c_sequence' => 1,
            'c_alt_name_chn' => '測試別名',
            'c_alt_name' => 'Test Name',
            'c_alt_name_type_code' => 1,
            'c_source' => 100,
            'c_pages' => '第1頁',
            'c_notes' => '這是測試筆記',
            '__proposal_comment' => '新增測試別名',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('已提交新增提案', $flash[0]['message'] ?? '');

        $this->assertDatabaseHas('operations', [
            'user_id' => $user->id,
            'c_personid' => 1,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'ALTNAME_DATA',
        ]);

        $operation = Operation::where('resource', 'ALTNAME_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
            ->first();

        $this->assertNotNull($operation);
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('測試別名', $payload['c_alt_name_chn']);
        $this->assertSame('pending', $payload['__review_status']);
        $this->assertSame('新增測試別名', $payload['__proposal_meta']['comment']);
        $this->assertSame(['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'], $payload['__key_columns']);
    }

    #[Test]
    public function testPossessionProposalStoreAssignsRecordIdAndUsesSingleKeyColumn() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        DB::table('POSSESSION_DATA')->insert([
            'c_personid' => 99,
            'c_possession_record_id' => 2,
            'c_sequence' => 1,
            'c_possession_act_code' => 1,
            'c_possession_desc' => 'existing',
            'c_possession_desc_chn' => '既有',
        ]);

        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'possessions',
        ]), [
            'c_sequence' => 1,
            'c_possession_act_code' => 0,
            'c_possession_desc' => '提案財產',
            'c_possession_desc_chn' => '提案財產中文',
            '__proposal_comment' => '新增所有物',
        ]);

        $response->assertRedirect();

        $operation = Operation::where('resource', 'POSSESSION_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame(3, $payload['c_possession_record_id']);
        $this->assertSame(['c_possession_record_id'], $payload['__key_columns']);
    }

    #[Test]
    public function testProposalResourceConfigUsesExpectedPrimaryKeys() {
        $config = (new \ReflectionClass(BasicInformationProposalController::class))
            ->getDefaultProperties()['resourceConfigs'];

        $this->assertSame(
            ['c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence'],
            $config['addresses']['key_columns']
        );
        $this->assertSame(
            ['c_personid', 'c_textid', 'c_role_id'],
            $config['texts']['key_columns']
        );
        $this->assertSame(
            ['c_possession_record_id'],
            $config['possessions']['key_columns']
        );
    }

    #[Test]
    public function testProposalStoreRejectsDuplicateData() {
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '已存在別名',
            'c_alt_name_type_code' => 1,
        ]);

        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'altnames',
        ]), [
            'c_sequence' => 1,
            'c_alt_name_chn' => '已存在別名',
            'c_alt_name_type_code' => 1,
            '__proposal_comment' => '嘗試重複新增',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('資料已存在', $flash[0]['message'] ?? '');
    }

    #[Test]
    public function testProposalStoreDetectsConflictingProposal() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        // 先創建一個待審核的新增提案（query-string 格式）
        $operation = new Operation();
        $operation->user_id = $user->id;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = \App\Support\CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => 1,
            'c_alt_name_chn' => '衝突別名',
            'c_alt_name_type_code' => 1,
        ]);
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '衝突別名',
            'c_alt_name_type_code' => 1,
            '__review_status' => 'pending',
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        // 嘗試提交相同主鍵的新增提案
        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'altnames',
        ]), [
            'c_sequence' => 1,
            'c_alt_name_chn' => '衝突別名',
            'c_alt_name_type_code' => 1,
            '__proposal_comment' => '嘗試衝突提案',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('已有其他新增提案', $flash[0]['message'] ?? '');
    }

    #[Test]
    public function testProposalUpdateCreatesUpdateProposal() {
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '原始別名',
            'c_alt_name' => 'Original Name',
            'c_alt_name_type_code' => 1,
            'c_source' => 100,
            'c_pages' => '第1頁',
            'c_notes' => '原始筆記',
        ]);

        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.proposal.update', [
            'personid' => 1,
            'resource' => 'altnames',
            'id' => '1-原始別名-1',
        ]), [
            'c_sequence' => 1,
            'c_alt_name_chn' => '原始別名',
            'c_alt_name' => 'Updated Name',
            'c_alt_name_type_code' => 1,
            'c_source' => 200,
            'c_pages' => '第2頁',
            'c_notes' => '更新後筆記',
            '__proposal_comment' => '修改別名資訊',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('已提交修改提案', $flash[0]['message'] ?? '');

        $this->assertDatabaseHas('operations', [
            'user_id' => $user->id,
            'c_personid' => 1,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'ALTNAME_DATA',
        ]);

        $operation = Operation::where('resource', 'ALTNAME_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)
            ->first();

        $this->assertNotNull($operation);
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('Updated Name', $payload['c_alt_name']);
        $this->assertSame('第2頁', $payload['c_pages']);
        $this->assertSame('pending', $payload['__review_status']);
    }

    #[Test]
    public function testProposalUpdateRejectsNoChanges() {
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '無變更別名',
            'c_alt_name_type_code' => 1,
        ]);

        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.proposal.update', [
            'personid' => 1,
            'resource' => 'altnames',
            'id' => '1-無變更別名-1',
        ]), [
            'c_sequence' => 1,
            'c_alt_name_chn' => '無變更別名',
            'c_alt_name_type_code' => 1,
            '__proposal_comment' => '嘗試無變更提交',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('未偵測到任何修改', $flash[0]['message'] ?? '');
    }

    #[Test]
    public function testKinshipProposalUpdateStoresAuxiliaryFieldsInProposalMeta() {
        $this->createKinshipTables();

        DB::table('KIN_DATA')->insert([
            'c_personid' => 1,
            'c_kin_id' => 2,
            'c_kin_code' => 111,
            'c_source' => 100,
            'c_pages' => '原頁碼',
            'c_notes' => '原註記',
            'c_autogen_notes' => 'mirror-note',
        ]);

        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.proposal.update', [
            'personid' => 1,
            'resource' => 'kinship',
            'id' => '1-2-111',
        ]), [
            'c_kin_id' => 2,
            'c_kin_code' => 75,
            'c_source' => 65006,
            'c_pages' => 'lgid=192832',
            'c_notes' => '更新後註記',
            'c_autogen_notes' => 'mirror-note',
            'c_kinship_pair' => 176,
            '__proposal_comment' => '修改親屬',
        ]);

        $response->assertRedirect();

        $operation = Operation::where('resource', 'KIN_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame(75, $payload['c_kin_code']);
        $this->assertArrayNotHasKey('c_kinship_pair', $payload);
        $this->assertSame(176, $payload['__proposal_aux']['c_kinship_pair'] ?? null);
    }

    #[Test]
    public function testApproveCreateProposalInsertsRow() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '核准別名',
            'c_alt_name' => 'Approved Name',
            'c_alt_name_type_code' => 1,
            'c_source' => 100,
            'c_pages' => '第1頁',
            'c_notes' => '核准的別名',
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['submitted_by' => 'tester', 'submitted_at' => Carbon::now()->format('Y-m-d H:i:s')],
        ];

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = '1-1-核准別名-1';
        $operation->resource_data = json_encode($resourceData, JSON_UNESCAPED_UNICODE);
        $operation->resource_original = json_encode([], JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => '核准通過',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('已核准', $flash[0]['message'] ?? '');

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '核准別名',
            'c_alt_name' => 'Approved Name',
            'c_alt_name_type_code' => 1,
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
        $this->assertSame('核准通過', $payload['__review_comment']);
        $this->assertSame($admin->name, $payload['__reviewed_by']);

        // 驗證正式操作記錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'ALTNAME_DATA',
            'op_type' => Operation::TYPE_CREATE,
            'c_personid' => 1,
        ]);
    }

    #[Test]
    public function testApproveUpdateProposalUpdatesRow() {
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '修改前別名',
            'c_alt_name' => 'Before',
            'c_alt_name_type_code' => 1,
            'c_notes' => '修改前筆記',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '修改前別名',
            'c_alt_name' => 'After',
            'c_alt_name_type_code' => 1,
            'c_notes' => '修改後筆記',
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
            '__review_status' => 'pending',
        ];

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_UPDATE;
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = '1-1-修改前別名-1';
        $operation->resource_data = json_encode($resourceData, JSON_UNESCAPED_UNICODE);
        $operation->resource_original = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '修改前別名',
            'c_alt_name' => 'Before',
            'c_alt_name_type_code' => 1,
            'c_notes' => '修改前筆記',
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => '修改合理',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '修改前別名',
            'c_alt_name' => 'After',
            'c_notes' => '修改後筆記',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
        $this->assertSame('修改合理', $payload['__review_comment']);

        // 驗證正式操作記錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'ALTNAME_DATA',
            'op_type' => Operation::TYPE_UPDATE,
            'c_personid' => 1,
        ]);
    }

    #[Test]
    public function testApproveKinshipUpdateProposalUsesDirectWorkflowAndUpdatesMirrorRow() {
        $this->createKinshipTables();
        $this->app->instance(BiogMainRepository::class, new BiogMainRepository());

        DB::table('KINSHIP_CODES')->insert([
            'c_kincode' => 111,
            'c_kin_pair1' => 301,
            'c_kin_pair2' => null,
        ]);

        DB::table('KIN_DATA')->insert([
            'c_personid' => 1,
            'c_kin_id' => 2,
            'c_kin_code' => 111,
            'c_source' => 100,
            'c_pages' => '原頁碼',
            'c_notes' => '原註記',
            'c_autogen_notes' => 'mirror-note',
            'c_created_by' => 'seed',
            'c_created_date' => '2025-01-01 00:00:00',
        ]);

        DB::table('KIN_DATA')->insert([
            'c_personid' => 2,
            'c_kin_id' => 1,
            'c_kin_code' => 301,
            'c_source' => 100,
            'c_pages' => '原頁碼',
            'c_notes' => '原註記',
            'c_autogen_notes' => 'mirror-note',
            'c_created_by' => 'seed',
            'c_created_date' => '2025-01-01 00:00:00',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_UPDATE;
        $operation->resource = 'KIN_DATA';
        $operation->resource_id = '1-2-111';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_kin_id' => 2,
            'c_kin_code' => 75,
            'c_source' => 65006,
            'c_pages' => 'lgid=192832',
            'c_notes' => null,
            'c_autogen_notes' => 'mirror-note',
            'c_kinship_pair' => 176,
            '__key_columns' => ['c_personid', 'c_kin_id', 'c_kin_code'],
            '__review_status' => 'pending',
        ], JSON_UNESCAPED_UNICODE);
        $operation->resource_original = json_encode([
            'c_personid' => 1,
            'c_kin_id' => 2,
            'c_kin_code' => 111,
            'c_source' => 100,
            'c_pages' => '原頁碼',
            'c_notes' => '原註記',
            'c_autogen_notes' => 'mirror-note',
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => '同意修改親屬',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('已核准', $flash[0]['message'] ?? '');

        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 1,
            'c_kin_id' => 2,
            'c_kin_code' => 75,
            'c_source' => 65006,
            'c_pages' => 'lgid=192832',
        ]);

        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 2,
            'c_kin_id' => 1,
            'c_kin_code' => 176,
            'c_source' => 65006,
            'c_pages' => 'lgid=192832',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
        $this->assertSame('同意修改親屬', $payload['__review_comment']);

        $this->assertDatabaseHas('operations', [
            'resource' => 'KIN_DATA',
            'op_type' => Operation::TYPE_UPDATE,
            'c_personid' => 1,
        ]);
    }

    #[Test]
    public function testApproveAssocCreateProposalUsesDirectWorkflowAndCreatesMirrorRow() {
        $this->createAssocTables();
        $this->app->instance(BiogMainRepository::class, new BiogMainRepository());

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'ASSOC_DATA';
        $operation->resource_id = 'pending-assoc';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_assoc_code' => 10,
            'c_assoc_id' => 2,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '',
            'c_assoc_first_year' => '-9999',
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            'c_notes' => '社會關係提案',
            'c_assocship_pair' => 20,
            'c_kinship_pair' => 301,
            'c_assoc_kinship_pair' => 302,
            '__key_columns' => ['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'],
            '__review_status' => 'pending',
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => '同意建立社會關係',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1,
            'c_assoc_code' => 10,
            'c_assoc_id' => 2,
            'c_notes' => '社會關係提案',
        ]);

        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 2,
            'c_assoc_code' => 20,
            'c_assoc_id' => 1,
            'c_kin_code' => 301,
            'c_assoc_kin_code' => 302,
        ]);
    }

    #[Test]
    public function testApproveOfficeCreateProposalUsesDirectWorkflowAndCreatesAddressRows() {
        $this->createOfficeTables();
        $this->app->instance(BiogMainRepository::class, new BiogMainRepository());

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        DB::table('POSTING_DATA')->insert([
            'c_personid' => 99,
            'c_posting_id' => 7,
            'c_created_by' => 'seed',
            'c_created_date' => '2025-01-01 00:00:00',
        ]);

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'POSTED_TO_OFFICE_DATA';
        $operation->resource_id = 'pending-office';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_office_id' => 888,
            'c_sequence' => 1,
            'c_source' => 123,
            'c_pages' => 'p.1',
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            'c_notes' => '官名提案',
            'c_addr' => [10, 11],
            '__key_columns' => ['c_office_id', 'c_posting_id'],
            '__review_status' => 'pending',
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => '同意建立官名',
        ]);

        $response->assertRedirect();

        $officeRow = DB::table('POSTED_TO_OFFICE_DATA')
            ->where('c_personid', 1)
            ->where('c_office_id', 888)
            ->first();

        $this->assertNotNull($officeRow);
        $this->assertSame('官名提案', $officeRow->c_notes);

        $this->assertDatabaseHas('POSTING_DATA', [
            'c_personid' => 1,
            'c_posting_id' => $officeRow->c_posting_id,
        ]);

        $this->assertDatabaseHas('POSTED_TO_ADDR_DATA', [
            'c_personid' => 1,
            'c_posting_id' => $officeRow->c_posting_id,
            'c_office_id' => 888,
            'c_addr_id' => 10,
        ]);

        $this->assertDatabaseHas('POSTED_TO_ADDR_DATA', [
            'c_personid' => 1,
            'c_posting_id' => $officeRow->c_posting_id,
            'c_office_id' => 888,
            'c_addr_id' => 11,
        ]);
    }

    #[Test]
    public function testApproveEventCreateProposalUsesDirectWorkflowAndCreatesEventAddrRows() {
        $this->createEventTables();
        $this->app->instance(BiogMainRepository::class, new BiogMainRepository());

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'EVENTS_DATA';
        $operation->resource_id = 'pending-event';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_sequence' => 5,
            'c_event_code' => 99,
            'c_source' => 123,
            'c_intercalary' => 0,
            'c_notes' => '事件提案',
            'c_addr_id' => [20, 21],
            '__key_columns' => ['c_personid', 'c_sequence', 'c_event_code'],
            '__review_status' => 'pending',
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => '同意建立事件',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('EVENTS_DATA', [
            'c_personid' => 1,
            'c_sequence' => 5,
            'c_event_code' => 99,
            'c_notes' => '事件提案',
        ]);

        $this->assertDatabaseHas('EVENTS_ADDR', [
            'c_personid' => 1,
            'c_sequence' => 5,
            'c_event_code' => 99,
            'c_addr_id' => 20,
        ]);

        $this->assertDatabaseHas('EVENTS_ADDR', [
            'c_personid' => 1,
            'c_sequence' => 5,
            'c_event_code' => 99,
            'c_addr_id' => 21,
        ]);
    }

    #[Test]
    public function testRejectProposalUpdatesStatus() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '退回別名',
            'c_alt_name_type_code' => 1,
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
            '__review_status' => 'pending',
        ];

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = '1-1-退回別名-1';
        $operation->resource_data = json_encode($resourceData, JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.reject', $operation), [
            'review_comment' => '資料不完整',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('已退回', $flash[0]['message'] ?? '');

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('rejected', $payload['__review_status']);
        $this->assertSame('資料不完整', $payload['__review_comment']);

        // 驗證沒有插入實際資料
        $this->assertDatabaseMissing('ALTNAME_DATA', [
            'c_alt_name_chn' => '退回別名',
        ]);
    }

    #[Test]
    public function testApproveRequiresReviewerPermission() {
        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = '1-1-無權-1';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '無權',
            'c_alt_name_type_code' => 1,
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
            '__review_status' => 'pending',
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertStatus(403);
    }

    #[Test]
    public function testApproveRejectsNonProposalOperation() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_CREATE;
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = '1-1-非提案-1';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '非提案',
            'c_alt_name_type_code' => 1,
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertStatus(404);
    }

    #[Test]
    public function testApproveFailsWhenKeyColumnsMissing() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = '1-1-缺主鍵-1';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '缺主鍵',
            'c_alt_name_type_code' => 1,
            '__review_status' => 'pending',
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('提案缺少主鍵資訊', $flash[0]['message'] ?? '');
    }

    #[Test]
    public function testApproveCreateFailsWhenRowAlreadyExists() {
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '已存在',
            'c_alt_name_type_code' => 1,
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = '1-1-已存在-1';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '已存在',
            'c_alt_name_type_code' => 1,
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
            '__review_status' => 'pending',
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertRedirect();

        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('資料已存在', $flash[0]['message'] ?? '');
        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $payload['__review_status']);
        $this->assertSame(1, Operation::count());
    }

    #[Test]
    public function testApproveUpdateFailsWithoutOriginalData() {
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '缺少原始',
            'c_alt_name_type_code' => 1,
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_UPDATE;
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = '1-1-缺少原始-1';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '缺少原始',
            'c_alt_name_type_code' => 1,
            'c_notes' => '新的備註',
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
            '__review_status' => 'pending',
        ], JSON_UNESCAPED_UNICODE);
        $operation->resource_original = json_encode([]);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertRedirect();

        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('缺少原始資料', $flash[0]['message'] ?? '');

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '缺少原始',
            'c_notes' => null,
        ]);
    }

    #[Test]
    public function testApproveUpdateAllowsPrimaryKeyChange() {
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '不可改主鍵',
            'c_alt_name_type_code' => 1,
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $operation = new Operation();
        $operation->user_id = 100;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_UPDATE;
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = '1-1-不可改主鍵-1';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '新主鍵值',
            'c_alt_name_type_code' => 1,
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
            '__review_status' => 'pending',
        ], JSON_UNESCAPED_UNICODE);
        $operation->resource_original = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '不可改主鍵',
            'c_alt_name_type_code' => 1,
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('提案已核准並套用至資料表', $flash[0]['message'] ?? '');

        $this->assertDatabaseMissing('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '不可改主鍵',
        ]);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '新主鍵值',
        ]);
    }

    #[Test]
    public function testProposalStoreFailsWhenPrimaryKeyMissing() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        // 3-key 中缺少 c_alt_name_type_code
        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'altnames',
        ]), [
            'c_alt_name_chn' => '缺少主鍵欄位',
            '__proposal_comment' => '主鍵缺失',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('主鍵欄位已填寫完整', $flash[0]['message'] ?? '');
        $this->assertSame(0, Operation::count());
    }

    #[Test]
    public function testProposalUpdateFailsWhenRowMissing() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.proposal.update', [
            'personid' => 1,
            'resource' => 'altnames',
            'id' => '1-不存在-1',
        ]), [
            'c_sequence' => 1,
            'c_alt_name_chn' => '不存在',
            'c_alt_name_type_code' => 1,
            '__proposal_comment' => '不存在的資料列',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('找不到對應的資料列', $flash[0]['message'] ?? '');
        $this->assertSame(0, Operation::count());
    }

    #[Test]
    public function testUnknownResourceTypeReturnsNotFound() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'unknown',
        ]), [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '未知',
            'c_alt_name_type_code' => 1,
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function testCompositeIdEncodesHyphenInResourceId() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'altnames',
        ]), [
            'c_sequence' => 1,
            'c_alt_name_chn' => '名-字',
            'c_alt_name_type_code' => 1,
            '__proposal_comment' => '連字號測試',
        ]);

        $response->assertRedirect();

        $operation = Operation::first();
        $this->assertNotNull($operation);
        // query-string 格式：連字符透過 URL 編碼處理，不再使用 bare minus
        $expected = \App\Support\CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => 1,
            'c_alt_name_chn' => '名-字',
            'c_alt_name_type_code' => 1,
        ]);
        $this->assertSame($expected, $operation->resource_id);
        // 確認 resource_id 中不含 bare 'minus' 編碼
        $this->assertStringNotContainsString('minus', $operation->resource_id);
    }

    #[Test]
    public function testConflictDetectionMatchesLegacyDashFormatPendingProposal() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        // 模擬歷史舊格式（dash + bare minus）的 pending 提案
        $operation = new Operation();
        $operation->user_id = $user->id;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'ALTNAME_DATA';
        // 舊格式：dash 分隔 + bare minus 編碼
        $operation->resource_id = '1-舊別名-1';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '舊別名',
            'c_alt_name_type_code' => 1,
            '__review_status' => 'pending',
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        // 用新格式提交相同主鍵的新增提案，應偵測到衝突
        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'altnames',
        ]), [
            'c_sequence' => 1,
            'c_alt_name_chn' => '舊別名',
            'c_alt_name_type_code' => 1,
            '__proposal_comment' => '嘗試與舊格式衝突',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('已有其他新增提案', $flash[0]['message'] ?? '');
    }

    #[Test]
    public function testConflictDetectionMatchesLegacyDashFormatWithHyphenInName() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        // 舊格式中含連字符的名字用 bare minus 編碼
        $operation = new Operation();
        $operation->user_id = $user->id;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'ALTNAME_DATA';
        // 舊格式：名-字 → 名minus字
        $operation->resource_id = '1-名minus字-1';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '名-字',
            'c_alt_name_type_code' => 1,
            '__review_status' => 'pending',
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
        ], JSON_UNESCAPED_UNICODE);
        $operation->save();

        // 用新格式提交相同主鍵，應偵測到衝突
        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'altnames',
        ]), [
            'c_sequence' => 1,
            'c_alt_name_chn' => '名-字',
            'c_alt_name_type_code' => 1,
            '__proposal_comment' => '嘗試與含連字符舊格式衝突',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('已有其他新增提案', $flash[0]['message'] ?? '');
    }
}
