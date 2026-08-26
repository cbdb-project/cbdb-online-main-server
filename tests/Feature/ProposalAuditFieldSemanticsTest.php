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
 * 稽核欄語義（2026-08-05 定案）回歸測試：
 *
 * 1. 提案 payload 是「快照」、可能夾帶稽核欄（legacy 提案入口無白名單；update 提案 data＝
 *    original∪changes 天然含）——核准重放前必須剔除，否則被 v2 handler 白名單擋成 422，
 *    整筆核准失敗（2026-08-05 別名 create 提案實案）。
 * 2. 核准＝一次實際寫入：稽核欄一律蓋核准當下，署名採雙人名「審核人 (Proposed by: 提案人)」。
 * 3. 還原（restore）也是寫入：c_modified_* 蓋還原人＋還原時刻，不回填快照舊值；
 *    c_created_*（建檔事實）維持快照值。
 */
class ProposalAuditFieldSemanticsTest extends TestCase {
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

    protected function actingAsReviewer(): User {
        $user = User::forceCreate([
            'name' => 'reviewer-a',
            'email' => 'reviewer-a@example.com',
            'confirmation_token' => 'token-123',
            'is_active' => 1,
            'is_admin' => 1,
        ]);
        $this->actingAs($user);

        return $user;
    }

    protected function makeProposal(array $attributes): Operation {
        $operation = new Operation();
        $operation->user_id = 50;
        $operation->c_personid = $attributes['c_personid'] ?? 0;
        $operation->op_type = $attributes['op_type'];
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = $attributes['resource_id'];
        $operation->resource_data = json_encode($attributes['resource_data'], JSON_UNESCAPED_UNICODE);
        $operation->resource_original = json_encode($attributes['resource_original'] ?? [], JSON_UNESCAPED_UNICODE);
        $operation->save();

        return $operation;
    }

    #[Test]
    public function testApproveCreateProposalWithAuditFieldsInPayloadSucceedsAndStampsDualName(): void {
        $this->actingAsReviewer();

        // 模擬 legacy 無白名單入口存下的髒 payload：夾帶四個稽核欄（值為提案人時代的舊值）。
        $operation = $this->makeProposal([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'c_personid' => 1000,
            'resource_id' => 'c_personid=1000&c_alt_name_chn=%E5%AD%90%E7%BE%8E&c_alt_name_type_code=4',
            'resource_data' => [
                'c_personid' => 1000,
                'c_alt_name_chn' => '子美',
                'c_alt_name_type_code' => 4,
                'c_alt_name' => 'Zimei',
                'c_sequence' => 1,
                'c_created_by' => 'proposer-b',
                'c_created_date' => '2020-01-01 00:00:00',
                'c_modified_by' => 'proposer-b',
                'c_modified_date' => '2020-01-01 00:00:00',
                '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
                '__review_status' => 'pending',
                '__proposal_meta' => [
                    'action' => 'create',
                    'submitted_by' => 'proposer-b',
                    'submitted_at' => '2026-08-05 11:10:11',
                ],
            ],
        ]);

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status'] ?? null, '夾帶稽核欄的提案應可核准（重放前剔除）');

        $row = DB::table('ALTNAME_DATA')->where([
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => 4,
        ])->first();
        $this->assertNotNull($row);
        // 稽核欄不沿用 payload 舊值：建檔署名＝雙人名、時間＝核准當下。
        $this->assertSame('reviewer-a (Proposed by: proposer-b)', $row->c_created_by);
        $this->assertNotSame('2020-01-01 00:00:00', (string) $row->c_created_date);

        // 「比較」按鈕依賴：核准後提案 payload 須記下實際落庫的 direct operation id，
        // 且 audit_log 掛在該 id 上（operations 列表據此把 audit 認領回提案列）。
        $appliedId = $payload['__applied_operation_id'] ?? null;
        $this->assertNotNull($appliedId);
        $this->assertNotSame((string) $operation->id, (string) $appliedId);
        $this->assertTrue(
            DB::table('audit_log')->where('operation_id', (string) $appliedId)->exists(),
            'audit_log 應掛在套用的 direct operation id 上'
        );
    }

    #[Test]
    public function testApproveUpdateProposalWithDriftedAuditValuesSucceedsAndStampsDualName(): void {
        $this->actingAsReviewer();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => 4,
            'c_alt_name' => 'Zimei',
            'c_sequence' => 1,
            'c_created_by' => 'orig-author',
            'c_created_date' => '2019-05-05 00:00:00',
        ]);

        $original = [
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => 4,
            'c_alt_name' => 'Zimei',
            'c_sequence' => 1,
            'c_created_by' => 'orig-author',
            'c_created_date' => '2019-05-05 00:00:00',
            'c_modified_by' => null,
            'c_modified_date' => null,
        ];

        // update 提案 data＝original∪changes；稽核欄序列化漂移（此處刻意與 original 不同）
        // 過去會漏進 diff、撞 handler 白名單——現一律剔除。
        $operation = $this->makeProposal([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'c_personid' => 1000,
            'resource_id' => 'c_personid=1000&c_alt_name_chn=%E5%AD%90%E7%BE%8E&c_alt_name_type_code=4',
            'resource_data' => array_merge($original, [
                'c_sequence' => 2,
                'c_created_date' => '2019-05-05T00:00:00', // 格式漂移
                'c_modified_by' => 'proposer-b',
                'c_modified_date' => '2026-08-05 11:10:11',
                '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
                '__review_status' => 'pending',
                '__proposal_meta' => [
                    'action' => 'update',
                    'submitted_by' => 'proposer-b',
                    'submitted_at' => '2026-08-05 11:10:11',
                ],
            ]),
            'resource_original' => $original,
        ]);

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status'] ?? null);

        $row = DB::table('ALTNAME_DATA')->where([
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => 4,
        ])->first();
        $this->assertSame(2, (int) $row->c_sequence);
        // modified＝最後一次寫入（核准），雙人名署名；created 維持建檔事實。
        $this->assertSame('reviewer-a (Proposed by: proposer-b)', $row->c_modified_by);
        $this->assertSame('orig-author', $row->c_created_by);
        $this->assertSame('2019-05-05 00:00:00', (string) $row->c_created_date);
    }

    #[Test]
    public function testRestoreUpdateStampsRestorerAsModifiedByInsteadOfSnapshotValue(): void {
        $this->actingAsReviewer();

        // 現況列（被某次修改覆寫過）
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => 4,
            'c_alt_name' => 'Wrong',
            'c_sequence' => 9,
            'c_created_by' => 'orig-author',
            'c_created_date' => '2019-05-05 00:00:00',
            'c_modified_by' => 'editor-x',
            'c_modified_date' => '2026-01-01 00:00:00',
        ]);

        $snapshotBefore = [
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => 4,
            'c_alt_name' => 'Zimei',
            'c_sequence' => 1,
            'c_created_by' => 'orig-author',
            'c_created_date' => '2019-05-05 00:00:00',
            'c_modified_by' => 'old-editor',
            'c_modified_date' => '2020-02-02 00:00:00',
        ];

        $operation = new Operation();
        $operation->user_id = 50;
        $operation->c_personid = 1000;
        $operation->op_type = 3; // UPDATE
        $operation->resource = 'ALTNAME_DATA';
        $operation->resource_id = 'c_personid=1000&c_alt_name_chn=%E5%AD%90%E7%BE%8E&c_alt_name_type_code=4';
        $operation->resource_data = json_encode(array_merge($snapshotBefore, ['c_alt_name' => 'Wrong', 'c_sequence' => 9]), JSON_UNESCAPED_UNICODE);
        $operation->resource_original = json_encode($snapshotBefore, JSON_UNESCAPED_UNICODE);
        $operation->save();

        $this->post(route('operations.restore', $operation))->assertRedirect();

        $row = DB::table('ALTNAME_DATA')->where([
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => 4,
        ])->first();
        // 內容欄回到快照
        $this->assertSame('Zimei', $row->c_alt_name);
        $this->assertSame(1, (int) $row->c_sequence);
        // modified＝還原這一次寫入：署名還原人、時間非快照舊值；created 維持建檔事實。
        $this->assertSame('reviewer-a', $row->c_modified_by);
        $this->assertNotSame('2020-02-02 00:00:00', (string) $row->c_modified_date);
        $this->assertSame('orig-author', $row->c_created_by);
    }
}
