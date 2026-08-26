<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Services\CharVariantMapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 修改提案（resubmit）回歸測試：POST api/v2/proposals/{operation}/resubmit。
 *
 * 語義：修改提案＝單一交易內撤回舊提案＋以完全相同的 /api/v2 提交管線重發
 * （見 MutationController::resubmit）。取代 codes 通用全欄表單編輯提案的舊流程
 * （該流程按 Schema 全欄回寫、會把稽核欄灌進 payload——op 351725 事故根因）。
 */
class ProposalResubmitTest extends TestCase {
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

        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->string('c_alt_name_chn', 255)->default('');
            $table->integer('c_alt_name_type_code')->default(0);
            $table->string('c_alt_name', 255)->nullable();
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_sequence')->default(0);
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code']);
        });

        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();
        });
        CharVariantMapService::reset();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeUser(string $name, string $email, int $role = User::ROLE_REGULAR): User {
        return User::forceCreate([
            'name' => $name,
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => 1,
            'is_admin' => $role,
        ]);
    }

    /** 以 v2 管線建立一筆乾淨的 pending create 提案（模擬正常提交）。 */
    protected function seedCreateProposal(User $proposer): Operation {
        $this->actingAs($proposer);
        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'proposal',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '子美', 'c_alt_name_type_code' => 4]],
            'changes' => ['c_alt_name' => 'Zimei', 'c_sequence' => 1],
            'meta' => ['comment' => '原始提案說明'],
        ])->assertOk();

        return Operation::findOrFail($response->json('result.operation_id'));
    }

    #[Test]
    public function testResubmitReplacesPendingProposalInOneTransaction(): void {
        $proposer = $this->makeUser('proposer-b', 'proposer@example.com');
        $old = $this->seedCreateProposal($proposer);

        // 同主鍵重發（改了 c_alt_name 與說明）：若無「先撤回舊提案」，會被
        // 「相同主鍵已有待審核的新增提案」護欄 409 擋下——本測試同時驗證護欄放行。
        $response = $this->postJson("/api/v2/proposals/{$old->id}/resubmit", [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'proposal',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '子美', 'c_alt_name_type_code' => 4]],
            'changes' => ['c_alt_name' => 'Zimei (rev)', 'c_sequence' => 2],
            'meta' => ['comment' => '更正拼音'],
        ])->assertOk();

        $newId = $response->json('result.operation_id');
        $this->assertNotNull($newId);
        $this->assertNotSame($old->id, $newId);

        // 舊提案：cancelled ＋ superseded_by 回鏈。
        $old->refresh();
        $oldPayload = json_decode($old->resource_data, true);
        $this->assertSame('cancelled', $oldPayload['__review_status']);
        $this->assertSame($newId, $oldPayload['__proposal_meta']['superseded_by']);

        // 新提案：pending、resubmit_of 回鏈、payload 經白名單（不含稽核欄鍵）。
        $new = Operation::findOrFail($newId);
        $newPayload = json_decode($new->resource_data, true);
        $this->assertSame('pending', $newPayload['__review_status']);
        $this->assertSame($old->id, $newPayload['__proposal_meta']['resubmit_of']);
        $this->assertSame('Zimei (rev)', $newPayload['c_alt_name']);
        $this->assertSame('更正拼音', $newPayload['__proposal_meta']['comment']);
        foreach (['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'] as $auditColumn) {
            $this->assertArrayNotHasKey($auditColumn, $newPayload, "resubmit payload 不得含稽核欄 {$auditColumn}");
        }
    }

    #[Test]
    public function testResubmitRollsBackWhenHandlerRejects(): void {
        $proposer = $this->makeUser('proposer-b', 'proposer@example.com');
        $old = $this->seedCreateProposal($proposer);

        // 目標主鍵撞既有 DB 列 → handler 409 → 整筆回滾，舊提案必須回到 pending。
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1000, 'c_alt_name_chn' => '既有', 'c_alt_name_type_code' => 4,
            'c_alt_name' => 'Existing', 'c_sequence' => 1,
        ]);

        $this->postJson("/api/v2/proposals/{$old->id}/resubmit", [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'proposal',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '既有', 'c_alt_name_type_code' => 4]],
            'changes' => ['c_alt_name' => 'Existing', 'c_sequence' => 1],
        ])->assertStatus(409);

        $old->refresh();
        $oldPayload = json_decode($old->resource_data, true);
        $this->assertSame('pending', $oldPayload['__review_status'], 'handler 拒絕時舊提案不得被撤回');
        $this->assertArrayNotHasKey('superseded_by', $oldPayload['__proposal_meta']);
        $this->assertSame(1, Operation::where('op_type', Operation::TYPE_PROPOSAL_CREATE)->count(), '不得殘留新提案');
    }

    #[Test]
    public function testResubmitForbiddenForUnrelatedCrowdsourcingUser(): void {
        // 權限模型（3619c572）：活躍非眾包用戶皆可審核（含修改他人提案）；
        // 眾包用戶既非提案人也無審核權 → 403。
        $proposer = $this->makeUser('proposer-b', 'proposer@example.com');
        $old = $this->seedCreateProposal($proposer);

        $other = $this->makeUser('other-user', 'other@example.com', User::ROLE_CROWDSOURCING);
        $this->actingAs($other);

        $this->postJson("/api/v2/proposals/{$old->id}/resubmit", [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'proposal',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '子美', 'c_alt_name_type_code' => 4]],
            'changes' => ['c_alt_name' => 'Zimei', 'c_sequence' => 1],
        ])->assertStatus(403);

        $old->refresh();
        $this->assertSame('pending', json_decode($old->resource_data, true)['__review_status']);
    }

    /** 直接調用 BasicInformationController::proposalResubmitProps（避免整頁渲染的 schema 依賴）。 */
    protected function invokeResubmitProps(int $proposalId, string $table = 'ALTNAME_DATA'): array {
        $controller = app(\App\Http\Controllers\BasicInformationController::class);
        $method = new \ReflectionMethod($controller, 'proposalResubmitProps');
        $request = \Illuminate\Http\Request::create('/x', 'GET', ['proposal' => $proposalId]);

        return $method->invoke($controller, $request, $table);
    }

    #[Test]
    public function testEditorPrefillStripsControlAndAuditKeys(): void {
        $proposer = $this->makeUser('proposer-b', 'proposer@example.com');
        $old = $this->seedCreateProposal($proposer);

        // 模擬髒 payload（歷史提案可能含稽核欄鍵）——預填必須剔除。
        $payload = json_decode($old->resource_data, true);
        $payload['c_created_by'] = null;
        $payload['c_modified_by'] = 'someone';
        $old->resource_data = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $old->save();

        [$overlay, $props] = $this->invokeResubmitProps($old->id);

        $this->assertSame('子美', $overlay['c_alt_name_chn']);
        $this->assertSame('Zimei', $overlay['c_alt_name']);
        foreach (array_keys($overlay) as $key) {
            $this->assertFalse(str_starts_with($key, '__'), "overlay 不得含控制鍵 {$key}");
        }
        foreach (['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'] as $auditColumn) {
            $this->assertArrayNotHasKey($auditColumn, $overlay);
        }

        $this->assertSame($old->id, $props['resubmit_proposal_id']);
        $this->assertSame('原始提案說明', $props['initial_comment']);
        $this->assertStringContainsString("/api/v2/proposals/{$old->id}/resubmit", $props['resubmit_endpoint']);
    }

    #[Test]
    public function testEditorPrefillRejectsTableMismatchAndSettledProposal(): void {
        $proposer = $this->makeUser('proposer-b', 'proposer@example.com');
        $old = $this->seedCreateProposal($proposer);

        try {
            $this->invokeResubmitProps($old->id, 'BIOG_ADDR_DATA');
            $this->fail('表名不符應 404');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $payload = json_decode($old->resource_data, true);
        $payload['__review_status'] = 'approved';
        $old->resource_data = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $old->save();

        try {
            $this->invokeResubmitProps($old->id);
            $this->fail('已審結提案應 409');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    #[Test]
    public function testResubmitRejectsApprovedProposal(): void {
        $proposer = $this->makeUser('proposer-b', 'proposer@example.com');
        $old = $this->seedCreateProposal($proposer);

        $payload = json_decode($old->resource_data, true);
        $payload['__review_status'] = 'approved';
        $old->resource_data = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $old->save();

        $this->postJson("/api/v2/proposals/{$old->id}/resubmit", [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'proposal',
            'operation' => 'create',
            'target' => ['pk' => ['c_personid' => 1000, 'c_alt_name_chn' => '子美', 'c_alt_name_type_code' => 4]],
            'changes' => ['c_alt_name' => 'Zimei', 'c_sequence' => 1],
        ])->assertStatus(422);
    }
}
