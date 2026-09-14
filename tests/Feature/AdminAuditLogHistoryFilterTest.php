<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 審計日誌的「人物頁歷史」過濾（`?c_personid=&history_page=`）。
 *
 * 這條過濾的實作（`resolveHistoryContext()` + `applyHistoryFilter()` +
 * `appendAuditPersonIdLike()`，約 80 行的 LIKE 拼裝，含 `row_pk_text` 的 4 種邊界與 JSON 的
 * 8 種空白／引號變形）是**唯一**支撐人物詳情頁「本頁歷史」的東西，而 `AdminAuditLogInertiaTest`
 * 完全沒碰它。原本本檔打 legacy Blade 頁，2026-09-14（Blade 下架環節 4a-1）改測 React 版。
 *
 * 四列 fixture 的**形狀**就是這條測試的價值：
 *  ① 同人同表（要被留下，`row_pk_text` 走 `c_personid=` **前綴形**）；
 *  ② 同人不同表（要被濾掉）；
 *  ③ 不同人同表（要被濾掉）；
 *  ④ 同人同表但 `row_pk_text` 走 `&c_personid=` **中段形**（`appendAuditPersonIdLike()` 的
 *    四個 pattern 裡最容易寫錯的一種）。
 *
 * ⚠️ 第 ④ 列是 review 揪出來補的：原本只有前綴形，所以把中段形的兩個 pattern 整個刪掉
 * 測試照綠——docblock 聲稱覆蓋了中段形，實際沒有。簡化 fixture 等於廢掉這條測試。
 */
class AdminAuditLogHistoryFilterTest extends TestCase {
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
        return User::forceCreate([
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
            // ④ 同人同表，但 c_personid 出現在 row_pk_text 的**中段**（不是開頭）。
            // 這一列專門守 appendAuditPersonIdLike() 的 '%&c_personid=N&%' pattern——
            // 少了它，把中段形的兩個 pattern 刪掉測試照樣綠（review 實測確認）。
            [
                'occurred_at' => now(),
                'created_at' => now(),
                'table_name' => 'ALTNAME_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $admin->id,
                'operation_id' => '01HISTORYALTNAME0000000004',
                'row_pk' => json_encode([
                    'c_alt_name_chn' => '中段形別名',
                    'c_alt_name_type_code' => 2,
                ], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_alt_name_type_code=2&c_personid=1001&c_alt_name_chn=%E4%B8%AD%E6%AE%B5%E5%BD%A2%E5%88%A5%E5%90%8D',
                'old_data' => null,
                'new_data' => json_encode(['c_alt_name_chn' => '中段形別名'], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $operationIds = null;
        $historyContext = null;

        $this->actingAs($admin)
            ->get(route('app.admin.audit-logs', ['c_personid' => 1001, 'history_page' => 'altnames']))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$operationIds, &$historyContext) {
                $props = $page->component('Admin/AuditLogs/Index')->toArray()['props'];
                $operationIds = array_column($props['logs']['data'], 'operation_id');
                $historyContext = $props['history_context'];
            });

        // 只留「該人 + 該 history 表群」的列：同人不同表（BIOG_TEXT_DATA）與
        // 不同人同表（personid 2002）都必須被濾掉；前綴形與中段形兩列都必須被留下。
        sort($operationIds);
        $this->assertSame(
            ['01HISTORYALTNAME0000000001', '01HISTORYALTNAME0000000004'],
            $operationIds
        );

        // history_context 要如實回吐，React 頁面靠它顯示「正在顯示人物 N 的『別名』審計日誌」
        // （`Pages/Admin/AuditLogs/Index.tsx` 直接用 history_context.label）。
        // 原本 legacy 版的 assertSeeText 那句中文文案**同時**釘住了 label，所以 label 一定要驗——
        // 少了它，label 錯了或空了都不會紅，頁面會顯示「正在顯示人物 1001 的『』審計日誌」。
        $this->assertSame(1001, (int) ($historyContext['person_id'] ?? 0));
        $this->assertSame('altnames', $historyContext['page'] ?? null);
        $this->assertSame('別名', $historyContext['label'] ?? null);
    }
}
