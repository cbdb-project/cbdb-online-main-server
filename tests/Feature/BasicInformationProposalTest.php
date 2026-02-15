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
        Schema::dropIfExists('POSSESSION_DATA');
        Schema::dropIfExists('ALTNAME_DATA');
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
        $this->assertSame(['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'], $payload['__key_columns']);
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

        // 先創建一個待審核的新增提案
        $operation = new Operation();
        $operation->user_id = $user->id;
        $operation->c_personid = 1;
        $operation->op_type = Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = '1-1-衝突別名-1';
        $operation->resource_data = json_encode([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '衝突別名',
            'c_alt_name_type_code' => 1,
            '__review_status' => 'pending',
            '__key_columns' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
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
            'id' => '1-1-原始別名-1',
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
            'id' => '1-1-無變更別名-1',
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
            '__key_columns' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
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
            '__key_columns' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
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
    public function testRejectProposalUpdatesStatus() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '退回別名',
            'c_alt_name_type_code' => 1,
            '__key_columns' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
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
            '__key_columns' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
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
            '__key_columns' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
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
            '__key_columns' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
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
            '__key_columns' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
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
    public function testApproveUpdateRejectsPrimaryKeyChange() {
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
            '__key_columns' => ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'],
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
        $this->assertStringContainsString('提案不可修改主鍵欄位', $flash[0]['message'] ?? '');

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '不可改主鍵',
        ]);
    }

    #[Test]
    public function testProposalStoreFailsWhenPrimaryKeyMissing() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 1,
            'resource' => 'altnames',
        ]), [
            'c_alt_name_chn' => '缺少主鍵欄位',
            'c_alt_name_type_code' => 1,
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
            'id' => '1-1-不存在-1',
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
        $this->assertSame('1-1-名minus字-1', $operation->resource_id);
    }
}
