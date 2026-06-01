<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApiV2OperationListTest extends TestCase {
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

        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->default(0);
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id')->default('');
            $table->longText('resource_data');
            $table->longText('resource_original')->nullable();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
            $table->timestamps();
        });

        DB::table('BIOG_MAIN')->insert(['c_personid' => 1, 'c_name_chn' => '甲']);
    }

    protected function tearDown(): void {
        Schema::dropIfExists('operations');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeUser(string $email = 'tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    protected function insertOperation(array $overrides = []): void {
        DB::table('operations')->insert(array_merge([
            'user_id' => 1,
            'c_personid' => 1,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'BIOG_MAIN',
            'resource_id' => 'c_personid=1',
            'resource_data' => json_encode(['c_name_chn' => '甲']),
            'resource_original' => null,
            'crowdsourcing_status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_accessible_without_authentication(): void {
        $response = $this->getJson('/api/v2/operations');

        $response->assertOk()->assertJson(['ok' => true]);
    }

    public function test_returns_paginated_response(): void {
        $user = $this->makeUser();
        $this->insertOperation();

        $response = $this->actingAs($user)->getJson('/api/v2/operations');

        $response->assertOk()->assertJsonStructure([
            'ok',
            'data' => [['id', 'user_id', 'c_personid', 'op_type', 'resource', 'resource_id',
                'resource_data', 'resource_original', 'crowdsourcing_status', 'created_at', 'updated_at']],
            'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
        ]);

        $response->assertJson(['ok' => true]);
        $this->assertEquals(1, $response->json('pagination.total'));
    }

    public function test_resource_data_is_decoded_as_json_object(): void {
        $user = $this->makeUser();
        $this->insertOperation(['resource_data' => json_encode(['c_name_chn' => '甲'])]);

        $response = $this->actingAs($user)->getJson('/api/v2/operations');

        $response->assertOk();
        $resourceData = $response->json('data.0.resource_data');
        $this->assertIsArray($resourceData);
        $this->assertEquals('甲', $resourceData['c_name_chn']);
    }

    public function test_excludes_proposal_create_and_update_by_default(): void {
        $user = $this->makeUser();
        $this->insertOperation(['op_type' => Operation::TYPE_UPDATE]);
        $this->insertOperation(['op_type' => Operation::TYPE_PROPOSAL_CREATE]);
        $this->insertOperation(['op_type' => Operation::TYPE_PROPOSAL_UPDATE]);

        $response = $this->actingAs($user)->getJson('/api/v2/operations');

        $response->assertOk();
        $this->assertEquals(1, $response->json('pagination.total'));
        $this->assertEquals(Operation::TYPE_UPDATE, $response->json('data.0.op_type'));
    }

    public function test_proposal_delete_appears_in_normal_list_matching_blade_ui(): void {
        $user = $this->makeUser();
        $this->insertOperation(['op_type' => Operation::TYPE_UPDATE]);
        $this->insertOperation(['op_type' => Operation::TYPE_PROPOSAL_DELETE]);

        $response = $this->actingAs($user)->getJson('/api/v2/operations');

        $response->assertOk();
        $this->assertEquals(2, $response->json('pagination.total'));
        $opTypes = array_column($response->json('data'), 'op_type');
        $this->assertContains(Operation::TYPE_UPDATE, $opTypes);
        $this->assertContains(Operation::TYPE_PROPOSAL_DELETE, $opTypes);
    }

    public function test_proposals_only_returns_only_proposal_types(): void {
        $user = $this->makeUser();
        $this->insertOperation(['op_type' => Operation::TYPE_UPDATE]);
        $this->insertOperation(['op_type' => Operation::TYPE_PROPOSAL_CREATE]);
        $this->insertOperation(['op_type' => Operation::TYPE_PROPOSAL_UPDATE]);

        $response = $this->actingAs($user)->getJson('/api/v2/operations?proposals_only=true');

        $response->assertOk();
        $this->assertEquals(2, $response->json('pagination.total'));
        $opTypes = array_column($response->json('data'), 'op_type');
        $this->assertContains(Operation::TYPE_PROPOSAL_CREATE, $opTypes);
        $this->assertContains(Operation::TYPE_PROPOSAL_UPDATE, $opTypes);
        $this->assertNotContains(Operation::TYPE_UPDATE, $opTypes);
    }

    public function test_op_type_filter_in_normal_mode(): void {
        $user = $this->makeUser();
        $this->insertOperation(['op_type' => Operation::TYPE_CREATE]);
        $this->insertOperation(['op_type' => Operation::TYPE_UPDATE]);
        $this->insertOperation(['op_type' => Operation::TYPE_DELETE]);

        $response = $this->actingAs($user)->getJson('/api/v2/operations?op_type[]=' . Operation::TYPE_CREATE);

        $response->assertOk();
        $this->assertEquals(1, $response->json('pagination.total'));
        $this->assertEquals(Operation::TYPE_CREATE, $response->json('data.0.op_type'));
    }

    public function test_per_page_parameter_respected(): void {
        $user = $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->insertOperation();
        }

        $response = $this->actingAs($user)->getJson('/api/v2/operations?per_page=2');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(5, $response->json('pagination.total'));
        $this->assertEquals(2, $response->json('pagination.per_page'));
        $this->assertEquals(3, $response->json('pagination.last_page'));
    }

    public function test_per_page_capped_at_100(): void {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->getJson('/api/v2/operations?per_page=9999');

        $response->assertOk();
        $this->assertEquals(100, $response->json('pagination.per_page'));
    }

    public function test_excludes_crowdsourcing_submissions(): void {
        $user = $this->makeUser();
        $this->insertOperation(['crowdsourcing_status' => 0]);
        $this->insertOperation(['crowdsourcing_status' => 2]);

        $response = $this->actingAs($user)->getJson('/api/v2/operations');

        $response->assertOk();
        $this->assertEquals(1, $response->json('pagination.total'));
    }

    public function test_empty_result_has_zero_pagination(): void {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->getJson('/api/v2/operations');

        $response->assertOk()->assertJson(['ok' => true, 'data' => []]);
        $this->assertEquals(0, $response->json('pagination.total'));
        $this->assertEquals(0, $response->json('pagination.from'));
        $this->assertEquals(0, $response->json('pagination.to'));
    }
}
