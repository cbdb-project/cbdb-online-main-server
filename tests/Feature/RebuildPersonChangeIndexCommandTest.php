<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RebuildPersonChangeIndexCommandTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->dateTime('c_created_date')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });

        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('c_personid')->nullable()->index();
            $table->dateTime('c_created_date')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });

        Schema::create('person_change_index', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->dateTime('c_last_modified_date')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

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
    }

    protected function tearDown(): void {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('person_change_index');
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('BIOG_MAIN');

        parent::tearDown();
    }

    public function test_rebuild_uses_audit_log_keyset_without_skipping_same_occurred_at_rows(): void {
        $people = [];
        $logs = [];
        $occurredAt = '2026-06-18 10:00:00';

        for ($personId = 1; $personId <= 2001; $personId++) {
            $people[] = [
                'c_personid' => $personId,
                'c_created_date' => '2020-01-01 00:00:00',
                'c_modified_date' => '2020-01-01 00:00:00',
            ];

            $logs[] = [
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'table_name' => 'ALTNAME_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'system',
                'actor_id' => 'system',
                'operation_id' => str_pad((string) $personId, 26, '0', STR_PAD_LEFT),
                'row_pk' => json_encode(['c_personid' => $personId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'row_pk_text' => 'c_personid=' . $personId,
                'old_data' => null,
                'new_data' => json_encode(['c_personid' => $personId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        DB::table('BIOG_MAIN')->insert($people);
        DB::table('audit_log')->insert($logs);

        $this->artisan('cbdb:rebuild-person-change-index')
            ->assertExitCode(0);

        $this->assertSame(2001, DB::table('person_change_index')->count());
        $this->assertSame(
            $occurredAt,
            DB::table('person_change_index')->where('c_personid', 2001)->value('c_last_modified_date')
        );
    }

    public function test_rebuild_sets_watermark_from_created_date_when_never_modified(): void {
        // 只有建檔時間、c_modified_date 為 NULL（從未被改）的人物：
        // 水位線應 = 建檔時間（建檔本身是一次異動），而非 NULL，否則會被 modified_since 漏掉。
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 50, 'c_created_date' => '2026-02-02 00:00:00', 'c_modified_date' => null],
        ]);

        $this->artisan('cbdb:rebuild-person-change-index')->assertExitCode(0);

        $this->assertSame(
            '2026-02-02 00:00:00',
            DB::table('person_change_index')->where('c_personid', 50)->value('c_last_modified_date')
        );
        $this->assertSame(
            '2026-02-02 00:00:00',
            DB::table('person_change_index')->where('c_personid', 50)->value('c_created_date')
        );
    }

    public function test_rebuild_since_and_prune_respect_audit_window_and_delete_orphans(): void {
        DB::table('BIOG_MAIN')->insert([
            [
                'c_personid' => 10,
                'c_created_date' => '2020-01-01 00:00:00',
                'c_modified_date' => '2020-01-02 00:00:00',
            ],
            [
                'c_personid' => 20,
                'c_created_date' => '2020-02-01 00:00:00',
                'c_modified_date' => '2020-02-02 00:00:00',
            ],
        ]);

        DB::table('person_change_index')->insert([
            [
                'c_personid' => 999,
                'c_last_modified_date' => '2026-01-01 00:00:00',
                'c_created_date' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ],
        ]);

        DB::table('audit_log')->insert([
            [
                'occurred_at' => '2026-06-09 09:00:00',
                'created_at' => '2026-06-09 09:00:00',
                'table_name' => 'POSTED_TO_OFFICE_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'system',
                'actor_id' => 'system',
                'operation_id' => '00000000000000000000000010',
                'row_pk' => json_encode(['c_office_id' => 1, 'c_posting_id' => 1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'row_pk_text' => 'c_office_id=1&c_posting_id=1',
                'old_data' => null,
                'new_data' => json_encode(['c_personid' => 10], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            [
                'occurred_at' => '2026-06-17 12:00:00',
                'created_at' => '2026-06-17 12:00:00',
                'table_name' => 'POSTED_TO_OFFICE_DATA',
                'operation' => 'DELETE',
                'actor_type' => 'system',
                'actor_id' => 'system',
                'operation_id' => '00000000000000000000000020',
                'row_pk' => json_encode(['c_office_id' => 2, 'c_posting_id' => 2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'row_pk_text' => 'c_office_id=2&c_posting_id=2',
                'old_data' => json_encode(['c_personid' => 20], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'new_data' => null,
            ],
        ]);

        $this->artisan('cbdb:rebuild-person-change-index', [
            '--since' => '2026-06-10 00:00:00',
            '--prune' => true,
        ])->assertExitCode(0);

        $this->assertNull(DB::table('person_change_index')->where('c_personid', 999)->first());

        $this->assertSame(
            '2020-01-02 00:00:00',
            DB::table('person_change_index')->where('c_personid', 10)->value('c_last_modified_date')
        );
        $this->assertSame(
            '2026-06-17 12:00:00',
            DB::table('person_change_index')->where('c_personid', 20)->value('c_last_modified_date')
        );
        $this->assertSame(
            '2020-02-01 00:00:00',
            DB::table('person_change_index')->where('c_personid', 20)->value('c_created_date')
        );
    }

    public function test_invalid_since_is_ignored_to_avoid_under_fetch(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 10, 'c_created_date' => '2020-01-01 00:00:00', 'c_modified_date' => '2020-01-02 00:00:00'],
            ['c_personid' => 20, 'c_created_date' => '2020-02-01 00:00:00', 'c_modified_date' => '2020-02-02 00:00:00'],
        ]);

        DB::table('audit_log')->insert([
            [
                'occurred_at' => '2026-06-09 09:00:00',
                'created_at' => '2026-06-09 09:00:00',
                'table_name' => 'POSTED_TO_OFFICE_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'system',
                'actor_id' => 'system',
                'operation_id' => '00000000000000000000000010',
                'row_pk' => json_encode(['c_office_id' => 1, 'c_posting_id' => 1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'row_pk_text' => 'c_office_id=1&c_posting_id=1',
                'old_data' => null,
                'new_data' => json_encode(['c_personid' => 10], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            [
                'occurred_at' => '2026-06-17 12:00:00',
                'created_at' => '2026-06-17 12:00:00',
                'table_name' => 'POSTED_TO_OFFICE_DATA',
                'operation' => 'DELETE',
                'actor_type' => 'system',
                'actor_id' => 'system',
                'operation_id' => '00000000000000000000000020',
                'row_pk' => json_encode(['c_office_id' => 2, 'c_posting_id' => 2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'row_pk_text' => 'c_office_id=2&c_posting_id=2',
                'old_data' => json_encode(['c_personid' => 20], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'new_data' => null,
            ],
        ]);

        foreach (['2026-02-31', '2026-06-15 24:00:00', '2026-13-01', '2026-06-15 12:60:00'] as $bad) {
            DB::table('person_change_index')->delete();

            $this->artisan('cbdb:rebuild-person-change-index', [
                '--since' => $bad,
            ])->expectsOutputToContain('--since 無法辨識')
                ->assertExitCode(0);

            $this->assertSame(
                '2026-06-09 09:00:00',
                DB::table('person_change_index')->where('c_personid', 10)->value('c_last_modified_date'),
                "invalid --since {$bad} 應退回全量重建，不能漏掉較早的 person 10"
            );
            $this->assertSame(
                '2026-06-17 12:00:00',
                DB::table('person_change_index')->where('c_personid', 20)->value('c_last_modified_date'),
                "invalid --since {$bad} 應退回全量重建，不能漏掉較晚的 person 20"
            );
        }
    }
}
