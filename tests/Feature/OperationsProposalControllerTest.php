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
    }

    protected function tearDown(): void {
        Schema::dropIfExists('TEST_SINGLE');
        Schema::dropIfExists('TEST_CODES');
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
}
