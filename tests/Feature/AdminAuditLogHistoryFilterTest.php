<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminAuditLogHistoryFilterTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-admin-audit-log-history';
        if (!is_dir($compiledViewPath)) {
            mkdir($compiledViewPath, 0777, true);
        }
        config(['view.compiled' => $compiledViewPath]);

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

    private function makeAdmin(): User {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'token',
            'is_active' => 1,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    #[Test]
    public function it_filters_audit_logs_by_basicinformation_history_context(): void {
        $admin = $this->makeAdmin();

        DB::table('audit_log')->insert([
            [
                'occurred_at' => now(),
                'created_at' => now(),
                'table_name' => 'ALTNAME_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $admin->id,
                'operation_id' => '01HISTORYALTNAME0000000001',
                'row_pk' => json_encode([
                    'c_alt_name_chn' => '測試別名',
                    'c_alt_name_type_code' => 1,
                ], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=1001&c_alt_name_chn=%E6%B8%AC%E8%A9%A6%E5%88%A5%E5%90%8D&c_alt_name_type_code=1',
                'old_data' => json_encode(['c_alt_name_chn' => '舊別名'], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_alt_name_chn' => '測試別名'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'occurred_at' => now(),
                'created_at' => now(),
                'table_name' => 'BIOG_TEXT_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $admin->id,
                'operation_id' => '01HISTORYTEXT000000000002',
                'row_pk' => json_encode(['c_personid' => 1001, 'c_textid' => 9, 'c_role_id' => 1], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=1001&c_textid=9&c_role_id=1',
                'old_data' => null,
                'new_data' => json_encode(['c_personid' => 1001], JSON_UNESCAPED_UNICODE),
            ],
            [
                'occurred_at' => now(),
                'created_at' => now(),
                'table_name' => 'ALTNAME_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $admin->id,
                'operation_id' => '01HISTORYALTNAME0000000003',
                'row_pk' => json_encode([
                    'c_alt_name_chn' => '別人別名',
                    'c_alt_name_type_code' => 1,
                ], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=2002&c_alt_name_chn=%E5%88%A5%E4%BA%BA%E5%88%A5%E5%90%8D&c_alt_name_type_code=1',
                'old_data' => null,
                'new_data' => json_encode(['c_alt_name_chn' => '別人別名'], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $response = $this->actingAs($admin)->get('/admin/audit-logs?c_personid=1001&history_page=altnames');

        $response->assertStatus(200);
        $response->assertSeeText('正在顯示人物 1001 的「別名」審計日誌。');
        $operationIds = $response->viewData('logs')->pluck('operation_id')->all();

        $this->assertSame(['01HISTORYALTNAME0000000001'], $operationIds);
    }
}
