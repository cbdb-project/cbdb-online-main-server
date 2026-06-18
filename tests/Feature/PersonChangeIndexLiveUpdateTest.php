<?php

namespace Tests\Feature;

use App\Services\AuditLogService;
use App\Services\PersonChangeIndexService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 即時路徑（AuditLogService::logChange → PersonChangeIndexService::recordChange）回歸測試。
 * 覆蓋：即時更新、單調不回退、DELETE 從 old_data 反查、非人物表忽略、表缺守衛不拋錯。
 */
class PersonChangeIndexLiveUpdateTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('audit_log', function (Blueprint $table) {
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

        Schema::create('person_change_index', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->dateTime('c_last_modified_date')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('person_change_index');
        Schema::dropIfExists('audit_log');
        parent::tearDown();
    }

    private function write(string $table, string $op, array $rowPk, ?array $old, ?array $new, string $occurredAt): void {
        (new AuditLogService())->write($table, $op, $rowPk, $old, $new, 'user', '1', null, Carbon::parse($occurredAt));
    }

    public function test_live_update_bumps_watermark_and_is_monotonic(): void {
        $this->write('ALTNAME_DATA', 'UPDATE', ['c_personid' => 7], null, ['c_personid' => 7], '2026-05-05 08:00:00');
        $this->assertSame(
            '2026-05-05 08:00:00',
            DB::table('person_change_index')->where('c_personid', 7)->value('c_last_modified_date')
        );

        // 較早的 out-of-order 事件不得使水位線回退
        $this->write('ALTNAME_DATA', 'UPDATE', ['c_personid' => 7], null, ['c_personid' => 7], '2020-01-01 00:00:00');
        $this->assertSame(
            '2026-05-05 08:00:00',
            DB::table('person_change_index')->where('c_personid', 7)->value('c_last_modified_date')
        );

        // 較新的事件前進
        $this->write('KIN_DATA', 'UPDATE', ['c_personid' => 7, 'c_kin_id' => 1, 'c_kin_code' => 1], null, ['c_personid' => 7], '2027-07-07 00:00:00');
        $this->assertSame(
            '2027-07-07 00:00:00',
            DB::table('person_change_index')->where('c_personid', 7)->value('c_last_modified_date')
        );

        // 即時路徑不寫 c_created_date（待 rebuild 從 BIOG_MAIN 回填）
        $this->assertNull(DB::table('person_change_index')->where('c_personid', 7)->value('c_created_date'));
    }

    public function test_delete_on_non_personid_pk_table_resolves_from_old_data(): void {
        // POSTED_TO_OFFICE_DATA 的 row_pk 不含 c_personid；DELETE 時 new_data 為 null，須從 old_data 解析
        $this->write(
            'POSTED_TO_OFFICE_DATA',
            'DELETE',
            ['c_office_id' => 9, 'c_posting_id' => 9],
            ['c_personid' => 8, 'c_office_id' => 9],
            null,
            '2026-06-06 00:00:00'
        );

        $this->assertSame(
            '2026-06-06 00:00:00',
            DB::table('person_change_index')->where('c_personid', 8)->value('c_last_modified_date')
        );
    }

    public function test_non_person_table_does_not_touch_index(): void {
        $this->write('SOME_CODE_TABLE', 'UPDATE', ['code' => 1], null, ['code' => 1], '2099-01-01 00:00:00');

        $this->assertSame(0, DB::table('person_change_index')->count());
        // 但 audit_log 仍寫入
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'SOME_CODE_TABLE')->count());
    }

    public function test_watermark_updates_after_transaction_commits(): void {
        DB::transaction(function () {
            $this->write('ALTNAME_DATA', 'UPDATE', ['c_personid' => 11], null, ['c_personid' => 11], '2026-08-08 00:00:00');
            // 交易尚未提交，afterCommit 尚未執行，此時水位線應仍未寫入
            $this->assertSame(0, DB::table('person_change_index')->where('c_personid', 11)->count());
        });

        // 交易提交後，afterCommit 觸發水位線更新
        $this->assertSame(
            '2026-08-08 00:00:00',
            DB::table('person_change_index')->where('c_personid', 11)->value('c_last_modified_date')
        );
    }

    public function test_watermark_not_updated_when_transaction_rolls_back(): void {
        try {
            DB::transaction(function () {
                $this->write('ALTNAME_DATA', 'UPDATE', ['c_personid' => 12], null, ['c_personid' => 12], '2026-09-09 00:00:00');

                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException $e) {
            // 預期
        }

        // 交易回滾：水位線不應為「未持久化的變更」跳動
        $this->assertSame(0, DB::table('person_change_index')->where('c_personid', 12)->count());
    }

    public function test_missing_index_table_does_not_break_audit_write(): void {
        Schema::dropIfExists('person_change_index');

        $this->write('ALTNAME_DATA', 'UPDATE', ['c_personid' => 7], null, ['c_personid' => 7], '2026-05-05 08:00:00');

        // audit_log 照常寫入，不因水位線副表缺失而拋錯
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'ALTNAME_DATA')->count());
    }

    public function test_after_commit_callback_failure_only_logs_warning_and_does_not_break_commit(): void {
        Log::spy();

        $failingService = new class () extends PersonChangeIndexService {
            public function recordChange(string $table, ?array $rowPk, ?array $newData, ?array $oldData, string $occurredAt): void {
                throw new \RuntimeException('simulated upsert failure');
            }
        };
        $this->app->instance(PersonChangeIndexService::class, $failingService);

        DB::transaction(function () {
            $this->write('ALTNAME_DATA', 'UPDATE', ['c_personid' => 13], null, ['c_personid' => 13], '2026-10-10 00:00:00');

            // 交易內只寫 audit_log；水位線 callback 等到 commit 後才執行
            $this->assertSame(1, DB::table('audit_log')->where('table_name', 'ALTNAME_DATA')->count());
            $this->assertSame(0, DB::table('person_change_index')->where('c_personid', 13)->count());
        });

        // callback 失敗不應回滾已提交的 audit / mutation 交易
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'ALTNAME_DATA')->count());
        $this->assertSame(0, DB::table('person_change_index')->where('c_personid', 13)->count());

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'person_change_index 即時更新失敗，將由 rebuild 補回'
                && ($context['table'] ?? null) === 'ALTNAME_DATA'
                && ($context['error'] ?? null) === 'simulated upsert failure';
        });
    }
}
