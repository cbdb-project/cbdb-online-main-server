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
 * 著述出處（sources）create / update 回歸測試。
 *
 * 涵蓋 SourceMutationHandler（AbstractMutationHandler + BiogSourceRepository）的分歧形態：
 * - 複合主鍵 3 段 c_personid, c_textid, c_pages（c_pages 為 varchar 主鍵，哨兵 ''）。
 * - 真實可寫欄位 c_notes, c_main_source, c_self_bio（無 phantom 欄位；測試表結構對齊真實 DB）。
 * - update 模式可改鍵：c_textid/c_pages 為主鍵但編輯時可改鍵（對齊 altname/address、legacy；#116），
 *   改鍵碰撞（新主鍵已存在）→ 409；c_personid 仍不可改。
 */
class ApiV2MutateSourceTest extends TestCase {
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

        $this->createUsersTable();
        $this->createSanctumTables();
        $this->createOperationsTable();
        $this->createAuditLogTable();
        $this->createTextCodesTable();
        $this->createSourceTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_SOURCE_DATA');
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function createUsersTable(): void {
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
    }

    protected function createSanctumTables(): void {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    protected function createOperationsTable(): void {
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
    }

    protected function createAuditLogTable(): void {
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
    }

    protected function createTextCodesTable(): void {
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title')->nullable();
            $table->string('c_title_chn')->nullable();
        });
        // 哨兵 0=未詳 與兩個合法 textid。
        DB::table('TEXT_CODES')->insert([
            ['c_textid' => 0, 'c_title' => 'n/a', 'c_title_chn' => '未詳'],
            ['c_textid' => 500, 'c_title' => 'Book A', 'c_title_chn' => '甲書'],
            ['c_textid' => 700, 'c_title' => 'Book B', 'c_title_chn' => '乙書'],
        ]);
    }

    /**
     * 對齊真實 DB：c_personid/c_textid/c_pages 主鍵；c_notes/c_main_source/c_self_bio 可寫；audit 欄位。
     * 不含舊測試表的 phantom c_source 欄（真實 BIOG_SOURCE_DATA 無此欄）。
     */
    protected function createSourceTable(): void {
        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid')->default(0);
            $table->string('c_pages', 255)->default('');
            $table->longText('c_notes')->nullable();
            $table->smallInteger('c_main_source')->nullable();
            $table->smallInteger('c_self_bio')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary(['c_personid', 'c_textid', 'c_pages']);
        });
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'mutate-source@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function createPayload(array $overrides = []): array {
        return array_replace_recursive([
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']],
            'changes' => ['c_main_source' => '1', 'c_self_bio' => '0', 'c_notes' => '備註甲'],
        ], $overrides);
    }

    #[Test]
    public function testSourceBooleanFlagsSentinelFullyIdempotent(): void {
        // sources 無碼/FK 欄，可寫 c_main_source/c_self_bio 為布林旗標（(int) 規範化）：
        // 0/'0'/null/''/false → 0；1/'1'/true → 1；來回不翻。≥10 案例。
        $this->actingAs($this->makeUser(email: 'src-sentinel@example.com'));
        $pk = ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '15'];
        $seed = function (array $o = []) use ($pk): void {
            DB::table('BIOG_SOURCE_DATA')->delete();
            DB::table('BIOG_SOURCE_DATA')->insert(array_merge($pk, array_merge(['c_main_source' => 0, 'c_self_bio' => 0, 'c_notes' => 's'], $o)));
        };
        $patch = fn ($changes) => $this->postJson('/api/v2/mutate', [
            'resource' => 'sources', 'person_id' => 1000, 'mode' => 'direct', 'operation' => 'update',
            'target' => ['pk' => $pk], 'changes' => $changes,
        ]);
        $val = fn ($f) => DB::table('BIOG_SOURCE_DATA')->where($pk)->value($f);

        foreach (['c_main_source', 'c_self_bio'] as $f) {
            // 0-ish → 0（從 seed=1 確保真寫入）。
            foreach ([null, '', '0', 0, false] as $sent) {
                $seed([$f => 1]);
                $patch([$f => $sent, 'c_notes' => '改0'.$f.var_export($sent, true)])->assertOk();
                $this->assertSame(0, (int) $val($f), $f.' 送 '.var_export($sent, true).' 應→0');
                $this->assertNotNull($val($f), $f.' 不得寫成 null（堵 (int)null=0 假綠；codex 回饋）');
            }
            // 1-ish → 1（從 seed=0）。
            foreach ([1, '1', true] as $sent) {
                $seed([$f => 0]);
                $patch([$f => $sent, 'c_notes' => '改1'.$f.var_export($sent, true)])->assertOk();
                $this->assertSame(1, (int) $val($f), $f.' 送 '.var_export($sent, true).' 應→1');
            }
        }
    }

    #[Test]
    public function testDirectSourceCreateLandsAllFields(): void {
        $this->actingAs($this->makeUser(email: 'src-create@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload())
            ->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'sources', 'operation' => 'create']);

        $row = DB::table('BIOG_SOURCE_DATA')->where('c_personid', 1000)->where('c_textid', 500)->where('c_pages', '12-15')->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->c_main_source);
        $this->assertSame(0, (int) $row->c_self_bio);
        $this->assertSame('備註甲', $row->c_notes);
        $this->assertNotNull($row->c_created_by);
    }

    /**
     * 回歸：c_textid=0（未詳）為合法出處代碼，legacy 伺服器允許建立此類列，V2 不可阻擋。
     * （前端僅以 '' 表示「尚未選擇」並擋下；明確選 0=未詳 則放行。）
     */
    #[Test]
    public function testDirectSourceCreateAcceptsUnknownTextIdZero(): void {
        $this->actingAs($this->makeUser(email: 'src-text0@example.com'));

        $payload = $this->createPayload();
        $payload['target']['pk'] = ['c_personid' => 1000, 'c_textid' => 0, 'c_pages' => '5'];
        $payload['changes'] = ['c_main_source' => '0', 'c_self_bio' => '0', 'c_notes' => '未詳出處'];

        $this->postJson('/api/v2/create', $payload)->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 0, 'c_pages' => '5']);
    }

    #[Test]
    public function testDirectSourceCreateWithEmptyPagesSentinel(): void {
        $this->actingAs($this->makeUser(email: 'src-empty@example.com'));

        $payload = $this->createPayload();
        $payload['target']['pk'] = ['c_personid' => 1000, 'c_textid' => 700, 'c_pages' => ''];
        $payload['changes'] = ['c_main_source' => '0', 'c_self_bio' => '0'];

        $this->postJson('/api/v2/create', $payload)->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 700, 'c_pages' => '']);
    }

    #[Test]
    public function testDirectSourceCreateRejectsInvalidTextId(): void {
        $this->actingAs($this->makeUser(email: 'src-badtext@example.com'));

        $payload = $this->createPayload();
        $payload['target']['pk']['c_textid'] = 999999; // not in TEXT_CODES

        $this->postJson('/api/v2/create', $payload)
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['c_textid' => ['invalid']]]);
    }

    #[Test]
    public function testDirectSourceUpdateChangesMutableFieldsAndPreservesOthers(): void {
        $this->actingAs($this->makeUser(email: 'src-update@example.com'));
        $this->postJson('/api/v2/create', $this->createPayload())->assertOk();

        // 改 c_notes 與 c_self_bio；c_main_source 不送 → 應保留 =1。
        $payload = [
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']],
            'changes' => ['c_notes' => '備註乙', 'c_self_bio' => '1'],
        ];
        $this->postJson('/api/v2/mutate', $payload)->assertOk()->assertJson(['ok' => true, 'operation' => 'update']);

        $row = DB::table('BIOG_SOURCE_DATA')->where('c_personid', 1000)->where('c_textid', 500)->where('c_pages', '12-15')->first();
        $this->assertSame('備註乙', $row->c_notes);
        $this->assertSame(1, (int) $row->c_self_bio);
        $this->assertSame(1, (int) $row->c_main_source, 'c_main_source 未送變更時應保留原值（無漂移）');
    }

    #[Test]
    public function testDirectSourceUpdateAllowsReKey(): void {
        // #116：出處 c_textid / 頁碼 c_pages 為主鍵，但編輯時可改鍵（對齊 altname/address、legacy）。
        // 場景：頁碼原不明確（12-15）→ 改成明確頁碼（20），同時改著述。
        $this->actingAs($this->makeUser(email: 'src-rekey@example.com'));
        $this->postJson('/api/v2/create', $this->createPayload())->assertOk();

        $payload = [
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']],
            'changes' => ['c_textid' => 700, 'c_pages' => '20', 'c_notes' => 'x'],
        ];
        $this->postJson('/api/v2/mutate', $payload)
            ->assertOk()
            ->assertJson(['ok' => true, 'operation' => 'update', 'result' => ['pk' => ['c_textid' => 700, 'c_pages' => '20']]]);

        // 原主鍵列改鍵後消失；新主鍵列存在且帶上非主鍵變更（c_notes）。
        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']);
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 700, 'c_pages' => '20', 'c_notes' => 'x']);
    }

    #[Test]
    public function testDirectSourceUpdateReKeyCollisionRejected(): void {
        // #116：改鍵碰撞——新主鍵已被另一列佔用 → 409，不覆寫他列。
        $this->actingAs($this->makeUser(email: 'src-rekey-collide@example.com'));
        $this->postJson('/api/v2/create', $this->createPayload())->assertOk(); // (1000,500,'12-15')
        $this->postJson('/api/v2/create', $this->createPayload([
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 700, 'c_pages' => '88']],
        ]))->assertOk(); // 佔住目標 (1000,700,'88')

        $payload = [
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']],
            'changes' => ['c_textid' => 700, 'c_pages' => '88'],
        ];
        $this->postJson('/api/v2/mutate', $payload)
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['duplicate']]]);

        // 兩列皆原樣保留（未覆寫、未刪除）。
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']);
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 700, 'c_pages' => '88']);
    }

    #[Test]
    public function testDirectSourceUpdateClearingPagesDoesNotSelfCollide(): void {
        // #116 邊界：清空頁碼（送空字串）經 ConvertEmptyStringsToNull→null、`?? targetPk` 維持原頁碼，
        // 應為「無變更」(422)，不可誤判改鍵撞到自己（假 409），且絕不可改動/刪除原列。
        $this->actingAs($this->makeUser(email: 'src-clearpages@example.com'));
        $this->postJson('/api/v2/create', $this->createPayload())->assertOk();

        $payload = [
            'resource' => 'sources', 'person_id' => 1000, 'mode' => 'direct', 'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']],
            'changes' => ['c_pages' => ''],
        ];
        $res = $this->postJson('/api/v2/mutate', $payload);
        $this->assertNotSame(409, $res->getStatusCode(), '清空頁碼不可誤判為改鍵撞自己（假 409）');
        $res->assertStatus(422);
        // 原列維持不變（頁碼仍為 12-15）。
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']);
    }

    #[Test]
    public function testProposalSourceUpdateReKeyReturnsNewPk(): void {
        // #116 fix2：proposal 改鍵回傳「提案後新主鍵」（與其他可改鍵子資源契約一致），且不立即改動 DB。
        DB::table('BIOG_SOURCE_DATA')->insert(['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15', 'c_main_source' => 0, 'c_self_bio' => 0, 'c_notes' => null]);
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'src-prop-rekey@example.com'));

        $payload = [
            'resource' => 'sources', 'person_id' => 1000, 'mode' => 'proposal', 'operation' => 'update',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']],
            'changes' => ['c_textid' => 700, 'c_pages' => '20'],
            'meta' => ['comment' => '頁碼改明確'],
        ];
        $this->postJson('/api/v2/mutate', $payload)
            ->assertOk()
            ->assertJson(['ok' => true, 'mode' => 'proposal', 'operation' => 'update', 'result' => ['pk' => ['c_textid' => 700, 'c_pages' => '20']]]);

        // proposal 不立即改 DB：原列仍在、新主鍵尚未產生。
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']);
        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 700, 'c_pages' => '20']);
    }

    #[Test]
    public function testProposalSourceCreateWritesPendingProposal(): void {
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'src-proposal@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'proposal', 'meta' => ['comment' => '請審']]))
            ->assertOk()
            ->assertJson(['ok' => true, 'mode' => 'proposal', 'operation' => 'create']);

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_SOURCE_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
        ]);
        // 提案不應實際寫入資料列。
        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'src-crowd@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'direct']))->assertStatus(403);
    }

    #[Test]
    public function testDirectSourceDeleteRemovesRow(): void {
        $this->actingAs($this->makeUser(email: 'src-del@example.com'));
        $this->postJson('/api/v2/create', $this->createPayload())->assertOk();
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']);

        $this->postJson('/api/v2/delete', [
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'delete',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']],
        ])->assertOk()->assertJson(['ok' => true, 'operation' => 'delete']);

        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']);
    }

    /**
     * 回歸：c_pages 空字串哨兵的刪除 round-trip。create 以 '' 建列，delete 省略 c_pages 時
     * SourceDeleteHandler::normalizeTargetPk 須把缺省 c_pages canonical 為 ''（而非 whereNull），
     * 否則會漏刪剛建立的記錄。
     */
    #[Test]
    public function testDirectSourceDeleteWithEmptyPagesSentinel(): void {
        $this->actingAs($this->makeUser(email: 'src-del-empty@example.com'));

        $payload = $this->createPayload();
        $payload['target']['pk'] = ['c_personid' => 1000, 'c_textid' => 700, 'c_pages' => ''];
        $payload['changes'] = ['c_main_source' => '0', 'c_self_bio' => '0'];
        $this->postJson('/api/v2/create', $payload)->assertOk();
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 700, 'c_pages' => '']);

        // delete 時省略 c_pages：normalizeTargetPk 須 canonical 為 ''，命中剛建立的列。
        $this->postJson('/api/v2/delete', [
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'delete',
            'target' => ['pk' => ['c_personid' => 1000, 'c_textid' => 700]],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 700]);
    }
}
