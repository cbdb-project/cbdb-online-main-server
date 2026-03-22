<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsIndexFilterTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('confirmation_token')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_admin')->default(0);
            $table->timestamps();
        });

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->nullable();
            $table->tinyInteger('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->tinyInteger('crowdsourcing_status')->default(0);
            $table->timestamps();
        });

        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->timestamps();
        });

        Schema::create('ALTNAME_DATA', function ($table) {
            $table->integer('c_personid');
            $table->string('c_alt_name_chn')->nullable();
            $table->integer('c_alt_name_type_code')->nullable();
        });

        Schema::create('KIN_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id');
            $table->integer('c_kin_code');
        });

        Schema::create('audit_log', function ($table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->char('operation_id', 26);
            $table->json('row_pk');
            $table->string('row_pk_text', 512);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });
    }

    private function makeUser(string $name, string $email, array $overrides = []): User {
        return User::create(array_merge([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'confirmation_token' => 'token',
            'is_active' => 1,
            'is_admin' => 1,
        ], $overrides));
    }

    private function makeOperation(User $user, int $opType, array $overrides = []): Operation {
        return Operation::create(array_merge([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => $opType,
            'resource' => 'BIOG_MAIN',
            'resource_id' => '1',
            'resource_data' => json_encode(['c_name_chn' => '測試'], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode(['c_name_chn' => '原始'], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ], $overrides));
    }

    /** 取出 viewData('lists') 中所有 user_id */
    private function listUserIds($response): array {
        return $response->viewData('lists')->pluck('user_id')->all();
    }

    /** 取出 viewData('lists') 中所有 op_type */
    private function listOpTypes($response): array {
        return $response->viewData('lists')->pluck('op_type')->map(fn ($v) => (int) $v)->all();
    }

    /** 取出 viewData('lists') 中所有 operation id */
    private function listOperationIds($response): array {
        return $response->viewData('lists')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    // ── editor 篩選 ──

    #[Test]
    public function editor_numeric_input_filters_by_user_id(): void {
        $alice = $this->makeUser('Alice', 'alice@example.com');
        $bob = $this->makeUser('Bob', 'bob@example.com');

        $this->makeOperation($alice, Operation::TYPE_CREATE);
        $this->makeOperation($bob, Operation::TYPE_CREATE);

        $response = $this->actingAs($alice)->get('/operations?editor=' . $bob->id);

        $response->assertStatus(200);
        $userIds = $this->listUserIds($response);
        $this->assertSame([$bob->id], $userIds);
    }

    #[Test]
    public function editor_text_input_filters_by_name_like(): void {
        $alice = $this->makeUser('Alice Wang', 'alice@example.com');
        $bob = $this->makeUser('Bob Chen', 'bob@example.com');

        $this->makeOperation($alice, Operation::TYPE_CREATE);
        $this->makeOperation($bob, Operation::TYPE_CREATE);

        $response = $this->actingAs($alice)->get('/operations?editor=Wang');

        $response->assertStatus(200);
        $userIds = $this->listUserIds($response);
        $this->assertSame([$alice->id], $userIds);
    }

    #[Test]
    public function editor_empty_string_shows_all_results(): void {
        $alice = $this->makeUser('Alice', 'alice@example.com');
        $bob = $this->makeUser('Bob', 'bob@example.com');

        $this->makeOperation($alice, Operation::TYPE_CREATE);
        $this->makeOperation($bob, Operation::TYPE_UPDATE);

        $response = $this->actingAs($alice)->get('/operations?editor=');

        $response->assertStatus(200);
        $userIds = $this->listUserIds($response);
        $this->assertCount(2, $userIds);
        $this->assertContains($alice->id, $userIds);
        $this->assertContains($bob->id, $userIds);
    }

    // ── op_type 篩選 ──

    #[Test]
    public function op_type_filters_by_selected_types(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $this->makeOperation($user, Operation::TYPE_CREATE);
        $this->makeOperation($user, Operation::TYPE_UPDATE);
        $this->makeOperation($user, Operation::TYPE_DELETE);

        // 只篩選「新增」和「刪除」
        $response = $this->actingAs($user)->get('/operations?op_type[]=1&op_type[]=4');

        $response->assertStatus(200);
        $opTypes = $this->listOpTypes($response);
        $this->assertCount(2, $opTypes);
        $this->assertContains(Operation::TYPE_CREATE, $opTypes);
        $this->assertContains(Operation::TYPE_DELETE, $opTypes);
        $this->assertNotContains(Operation::TYPE_UPDATE, $opTypes);
    }

    #[Test]
    public function op_type_rejects_invalid_values(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $this->makeOperation($user, Operation::TYPE_CREATE);
        $this->makeOperation($user, Operation::TYPE_UPDATE);

        // 傳入非法值 99，應被過濾掉，等同無篩選
        $response = $this->actingAs($user)->get('/operations?op_type[]=99');

        $response->assertStatus(200);
        $opTypes = $this->listOpTypes($response);
        $this->assertCount(2, $opTypes);
    }

    #[Test]
    public function op_type_filter_ignored_in_proposals_mode(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $this->makeOperation($user, Operation::TYPE_PROPOSAL_CREATE, [
            'resource_data' => json_encode([
                'c_name_chn' => '提案',
                '__review_status' => 'pending',
                '__proposal_meta' => ['submitted_by' => 'Admin', 'submitted_at' => now()->format('Y-m-d H:i:s')],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // proposals 模式下傳入 op_type=1 應被忽略，提案仍然顯示
        $response = $this->actingAs($user)->get('/operations?proposals_only=1&op_type[]=1');

        $response->assertStatus(200);
        $opTypes = $this->listOpTypes($response);
        $this->assertCount(1, $opTypes);
        $this->assertSame(Operation::TYPE_PROPOSAL_CREATE, $opTypes[0]);
    }

    #[Test]
    public function history_filter_matches_current_person_page_by_resource_and_person(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $matching = $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 1001,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => 'c_personid=1001&c_alt_name_chn=%E6%B8%AC%E8%A9%A6&c_alt_name_type_code=1',
        ]);
        $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 1001,
            'resource' => 'BIOG_TEXT_DATA',
        ]);
        $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 2002,
            'resource' => 'ALTNAME_DATA',
        ]);

        $response = $this->actingAs($user)->get('/operations?c_personid=1001&history_page=altnames');

        $response->assertStatus(200);
        $this->assertSame([$matching->id], $this->listOperationIds($response));
    }

    #[Test]
    public function history_filter_matches_mirrored_person_changes_via_audit_log(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $matching = $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 2002,
            'resource' => 'KIN_DATA',
            'resource_id' => 'c_personid=2002&c_kin_id=1001&c_kin_code=300',
        ]);
        $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 3003,
            'resource' => 'KIN_DATA',
            'resource_id' => 'c_personid=3003&c_kin_id=4004&c_kin_code=300',
        ]);

        DB::table('audit_log')->insert([
            'occurred_at' => now(),
            'created_at' => now(),
            'table_name' => 'KIN_DATA',
            'operation' => 'UPDATE',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'operation_id' => (string) $matching->id,
            'row_pk' => json_encode([
                'c_kin_id' => 2002,
                'c_kin_code' => 300,
            ], JSON_UNESCAPED_UNICODE),
            'row_pk_text' => 'c_personid=1001&c_kin_id=2002&c_kin_code=300',
            'old_data' => json_encode([
                'c_kin_id' => 2002,
                'c_kin_code' => 300,
            ], JSON_UNESCAPED_UNICODE),
            'new_data' => json_encode([
                'c_kin_id' => 2002,
                'c_kin_code' => 301,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $response = $this->actingAs($user)->get('/operations?c_personid=1001&history_page=kinship');

        $response->assertStatus(200);
        $this->assertSame([$matching->id], $this->listOperationIds($response));
    }

    #[Test]
    public function default_operations_index_hides_proposals(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $this->makeOperation($user, Operation::TYPE_CREATE, [
            'resource_data' => json_encode(['c_name_chn' => '一般操作'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->makeOperation($user, Operation::TYPE_PROPOSAL_CREATE, [
            'resource_data' => json_encode([
                'c_name_chn' => '提案操作',
                '__review_status' => 'pending',
                '__proposal_meta' => ['submitted_by' => 'Admin', 'submitted_at' => now()->format('Y-m-d H:i:s')],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $response = $this->actingAs($user)->get('/operations');

        $response->assertStatus(200);
        $opTypes = $this->listOpTypes($response);
        $this->assertCount(1, $opTypes);
        $this->assertSame(Operation::TYPE_CREATE, $opTypes[0]);
    }

    // ── proposals 模式 status + editor 組合 ──

    #[Test]
    public function proposals_status_and_editor_filter_work_together(): void {
        $alice = $this->makeUser('Alice', 'alice@example.com');
        $bob = $this->makeUser('Bob', 'bob@example.com');

        // Alice 的 pending 提案
        $this->makeOperation($alice, Operation::TYPE_PROPOSAL_CREATE, [
            'resource_data' => json_encode([
                'c_name_chn' => 'Alice提案',
                '__review_status' => 'pending',
                '__proposal_meta' => ['submitted_by' => 'Alice', 'submitted_at' => now()->format('Y-m-d H:i:s')],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // Bob 的 pending 提案
        $this->makeOperation($bob, Operation::TYPE_PROPOSAL_UPDATE, [
            'resource_data' => json_encode([
                'c_name_chn' => 'Bob提案',
                '__review_status' => 'pending',
                '__proposal_meta' => ['submitted_by' => 'Bob', 'submitted_at' => now()->format('Y-m-d H:i:s')],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // Alice 的 approved 提案
        $this->makeOperation($alice, Operation::TYPE_PROPOSAL_CREATE, [
            'resource_data' => json_encode([
                'c_name_chn' => 'Alice已核准',
                '__review_status' => 'approved',
                '__proposal_meta' => ['submitted_by' => 'Alice', 'submitted_at' => now()->format('Y-m-d H:i:s')],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // 篩選 Alice 的 pending 提案
        $response = $this->actingAs($alice)->get('/operations?proposals_only=1&status[]=pending&editor=Alice');

        $response->assertStatus(200);

        $lists = $response->viewData('lists');
        $this->assertCount(1, $lists);
        $this->assertSame($alice->id, $lists[0]->user_id);

        $data = json_decode($lists[0]->resource_data, true);
        $this->assertSame('pending', $data['__review_status']);
    }
}
