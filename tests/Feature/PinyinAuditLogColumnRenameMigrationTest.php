<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * database/migrations/2026_08_17_000000_rename_pinyin_columns_in_audit_log_snapshots.php
 *
 * 補正 audit_log 裡 pinyin 的欄名快照（lastname_chn → c_chn、lastname_pinyin → c_pinyin），
 * 讓 2026_07_10 改名後的歷史稽核列還查得到現況。只改欄名，不動任何值。
 */
class PinyinAuditLogColumnRenameMigrationTest extends TestCase {
    private const MIGRATION = 'database/migrations/2026_08_17_000000_rename_pinyin_columns_in_audit_log_snapshots.php';

    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        Schema::create('audit_log', function (Blueprint $table) {
            $table->increments('id');
            $table->string('operation_id')->nullable();
            $table->string('table_name');
            $table->string('operation');
            $table->longText('row_pk')->nullable();
            $table->string('row_pk_text', 512)->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('audit_log');

        parent::tearDown();
    }

    private function migration(): object {
        return require base_path(self::MIGRATION);
    }

    /** 生產實際資料（audit_log id 450）的複製品。 */
    private function insertLegacyPinyinAudit(): int {
        return DB::table('audit_log')->insertGetId([
            'operation_id' => '259047',
            'table_name' => 'pinyin',
            'operation' => 'INSERT',
            'row_pk' => '{"id":525,"lastname_chn":"瞿曇"}',
            'row_pk_text' => 'id=525&lastname_chn=%E7%9E%BF%E6%9B%87',
            'old_data' => null,
            'new_data' => '{"id":525,"lastname_chn":"瞿曇","lastname_pinyin":"Qutan"}',
        ]);
    }

    #[Test]
    public function test_up_renames_pinyin_columns_in_every_snapshot_column(): void {
        $id = $this->insertLegacyPinyinAudit();

        $this->migration()->up();

        $row = DB::table('audit_log')->find($id);
        $this->assertSame('{"id":525,"c_chn":"瞿曇"}', $row->row_pk);
        $this->assertSame('id=525&c_chn=%E7%9E%BF%E6%9B%87', $row->row_pk_text);
        $this->assertSame('{"id":525,"c_chn":"瞿曇","c_pinyin":"Qutan"}', $row->new_data);
        $this->assertNull($row->old_data, 'old_data 原本是 NULL，不該被寫成別的東西');
    }

    #[Test]
    public function test_up_preserves_values_and_key_order(): void {
        // 只改 key 名：值、key 順序、中文都不得變動（稽核事實必須完整保留）。
        $id = $this->insertLegacyPinyinAudit();

        $this->migration()->up();

        $row = DB::table('audit_log')->find($id);
        $this->assertSame(
            ['id' => 525, 'c_chn' => '瞿曇', 'c_pinyin' => 'Qutan'],
            json_decode($row->new_data, true),
            '值與 key 順序都應原樣保留，只有 key 名換掉'
        );
        $this->assertStringNotContainsString('\u', $row->new_data, '中文不可被轉成 \\uXXXX 轉義');
    }

    #[Test]
    public function test_up_is_idempotent(): void {
        $id = $this->insertLegacyPinyinAudit();

        $this->migration()->up();
        $afterFirst = DB::table('audit_log')->find($id);
        $this->migration()->up();
        $afterSecond = DB::table('audit_log')->find($id);

        $this->assertEquals($afterFirst, $afterSecond, '重複執行不應再改動任何內容');
    }

    #[Test]
    public function test_up_leaves_other_tables_untouched(): void {
        // 條件鎖在 table_name='pinyin'；別的表就算有同名欄位也不能碰。
        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '1',
            'table_name' => 'SOME_OTHER_TABLE',
            'operation' => 'UPDATE',
            'row_pk' => '{"id":1,"lastname_chn":"甲"}',
            'row_pk_text' => 'id=1&lastname_chn=%E7%94%B2',
            'old_data' => null,
            'new_data' => '{"lastname_pinyin":"Jia"}',
        ]);

        $this->migration()->up();

        $row = DB::table('audit_log')->find($id);
        $this->assertSame('{"id":1,"lastname_chn":"甲"}', $row->row_pk);
        $this->assertSame('id=1&lastname_chn=%E7%94%B2', $row->row_pk_text);
        $this->assertSame('{"lastname_pinyin":"Jia"}', $row->new_data);
    }

    #[Test]
    public function test_up_leaves_already_renamed_pinyin_rows_untouched(): void {
        // 改名之後寫入的 pinyin 稽核列（已是新欄名）不該被動到。
        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '353965',
            'table_name' => 'pinyin',
            'operation' => 'UPDATE',
            'row_pk' => '{"id":3682}',
            'row_pk_text' => 'id=3682',
            'old_data' => '{"c_pinyin":"Ou"}',
            'new_data' => '{"c_pinyin":"Qu"}',
        ]);

        $this->migration()->up();

        $row = DB::table('audit_log')->find($id);
        $this->assertSame('{"id":3682}', $row->row_pk);
        $this->assertSame('{"c_pinyin":"Ou"}', $row->old_data);
        $this->assertSame('{"c_pinyin":"Qu"}', $row->new_data);
    }

    #[Test]
    public function test_down_is_deliberately_a_no_op(): void {
        // down() 故意留空：migrate:rollback 只回滾最後一批，pinyin 表改名是更早的批次，
        // 反向改名會把「含新欄名」的**所有** pinyin 稽核列寫回舊欄名，
        // 等於把要修的欄名漂移從 4 列擴散到全部。
        $legacyId = $this->insertLegacyPinyinAudit();
        $modernId = DB::table('audit_log')->insertGetId([
            'operation_id' => '353965',
            'table_name' => 'pinyin',
            'operation' => 'UPDATE',
            'row_pk' => '{"id":3682,"c_chn":"甲"}',
            'row_pk_text' => 'id=3682&c_chn=%E7%94%B2',
            'old_data' => null,
            'new_data' => '{"c_pinyin":"Qu"}',
        ]);

        $this->migration()->up();
        $afterUp = DB::table('audit_log')->orderBy('id')->get();

        $this->migration()->down();

        $this->assertEquals($afterUp, DB::table('audit_log')->orderBy('id')->get(), 'down() 不該改動任何內容');

        // 特別確認改名後才寫入的那列從頭到尾沒被碰過。
        $modern = DB::table('audit_log')->find($modernId);
        $this->assertSame('{"id":3682,"c_chn":"甲"}', $modern->row_pk);
        $this->assertSame('id=3682&c_chn=%E7%94%B2', $modern->row_pk_text);
        // up() 仍正常改掉舊欄名那列。
        $this->assertSame('{"id":525,"c_chn":"瞿曇"}', DB::table('audit_log')->find($legacyId)->row_pk);
    }

    #[Test]
    public function test_up_skips_snapshots_with_duplicate_json_keys_instead_of_losing_a_value(): void {
        // json_decode() 對重複 key 只留最後一個，直接重新編碼會把前一個值永久丟掉。
        // 稽核資料上這是不可接受的損失，寧可整欄不動。
        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '1',
            'table_name' => 'pinyin',
            'operation' => 'INSERT',
            'row_pk' => '{"id":1}',
            'row_pk_text' => 'id=1',
            'old_data' => null,
            'new_data' => '{"lastname_chn":"first","lastname_chn":"second"}',
        ]);

        $this->migration()->up();

        $this->assertSame(
            '{"lastname_chn":"first","lastname_chn":"second"}',
            DB::table('audit_log')->find($id)->new_data,
            '含重複 key 的快照應原樣保留，不得因重新編碼而丟掉其中一個值'
        );
    }

    #[Test]
    public function test_up_skips_the_whole_row_when_only_one_column_cannot_be_reencoded(): void {
        // row_pk／row_pk_text 本身是標準編碼、只有 new_data 不是：若逐欄決定，
        // 前兩者會被改成新欄名而 new_data 留在舊欄名，同一列的快照就自相矛盾。
        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '1',
            'table_name' => 'pinyin',
            'operation' => 'INSERT',
            'row_pk' => '{"id":525,"lastname_chn":"瞿曇"}',
            'row_pk_text' => 'id=525&lastname_chn=%E7%9E%BF%E6%9B%87',
            'old_data' => null,
            // 有空白排版，重編不是無損的
            'new_data' => '{ "lastname_chn":"瞿曇","lastname_pinyin":"Qutan" }',
        ]);
        $original = DB::table('audit_log')->find($id);

        $this->migration()->up();

        $this->assertEquals(
            $original,
            DB::table('audit_log')->find($id),
            '任一欄不安全就應整列原樣保留，不可只改安全的那幾欄'
        );
    }

    #[Test]
    public function test_up_skips_the_whole_row_when_a_snapshot_is_unparseable_but_mentions_an_old_name(): void {
        // 解不開的快照無從得知它的 key，若字面上有舊欄名就不能保證它不需要改名；
        // 此時改別的欄會留下半套狀態（row_pk 已是 c_chn、new_data 還是 lastname_pinyin）。
        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '1',
            'table_name' => 'pinyin',
            'operation' => 'INSERT',
            'row_pk' => '{"id":525,"lastname_chn":"瞿曇"}',
            'row_pk_text' => 'id=525&lastname_chn=%E7%9E%BF%E6%9B%87',
            'old_data' => null,
            // JSON 被截斷，解不開
            'new_data' => '{"lastname_pinyin":"Qutan"',
        ]);
        $original = DB::table('audit_log')->find($id);

        $this->migration()->up();

        $this->assertEquals(
            $original,
            DB::table('audit_log')->find($id),
            '解不開卻含舊欄名的快照應讓整列原樣保留'
        );
    }

    #[Test]
    public function test_up_skips_the_whole_row_when_a_snapshot_has_a_unicode_escaped_legacy_key(): void {
        // 判定刻意不靠字串比對：key 可以寫成 \uXXXX 轉義，字面上看不到 lastname_pinyin。
        // 只要那一欄不是合法 JSON 就整列不動，這種轉義花招也就無所謂了。
        // key 裡的 'n' 寫成 n（chr(92) 就是反斜線），字面上搜不到 lastname_pinyin；
        // 尾逗號讓整串不是合法 JSON。
        $escapedKey = 'lastname_pi' . chr(92) . 'u006eyin';

        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '1',
            'table_name' => 'pinyin',
            'operation' => 'INSERT',
            'row_pk' => '{"id":525,"lastname_chn":"瞿曇"}',
            'row_pk_text' => 'id=525&lastname_chn=%E7%9E%BF%E6%9B%87',
            'old_data' => null,
            'new_data' => '{"' . $escapedKey . '":"Qutan",}',
        ]);
        $original = DB::table('audit_log')->find($id);

        // 先確認這個 fixture 真的躲得過字串比對，否則測不到它要測的東西。
        $this->assertStringNotContainsString('lastname_pinyin', $original->new_data);
        $this->assertNull(json_decode($original->new_data, true), 'fixture 必須是不合法的 JSON');

        $this->migration()->up();

        $this->assertEquals(
            $original,
            DB::table('audit_log')->find($id),
            '任一快照欄不是合法 JSON 就應整列原樣保留'
        );
    }

    #[Test]
    public function test_up_skips_valid_json_written_with_escaped_unicode(): void {
        // 合法 JSON、key 也確實需要改名，但內容是用**預設旗標**寫出的（中文成了 \uXXXX），
        // 與 AuditLogService::encodeJson() 的 JSON_UNESCAPED_UNICODE 不同。
        // 重新編碼不會是 byte-identical，無損守衛因此擋下，整列不動——
        // 不然改名會順手把轉義形式一起改掉，那已經不只是「改欄名」了。
        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '1',
            'table_name' => 'pinyin',
            'operation' => 'INSERT',
            'row_pk' => '{"id":525,"lastname_chn":"瞿曇"}',
            'row_pk_text' => 'id=525&lastname_chn=%E7%9E%BF%E6%9B%87',
            'old_data' => null,
            // 預設旗標：中文被轉義成 \uXXXX
            'new_data' => json_encode(['lastname_chn' => '瞿曇']),
        ]);
        $original = DB::table('audit_log')->find($id);

        // fixture 前提：確實是合法 JSON、key 確實需要改名、且確實不是 canonical 編碼。
        $this->assertSame(['lastname_chn' => '瞿曇'], json_decode($original->new_data, true));
        $this->assertStringContainsString(chr(92) . 'u', $original->new_data);

        $this->migration()->up();

        $this->assertEquals(
            $original,
            DB::table('audit_log')->find($id),
            '非 canonical 編碼的快照重新編碼不是無損的，應整列原樣保留'
        );
    }

    #[Test]
    public function test_up_renames_a_key_only_segment_in_row_pk_text(): void {
        // 沒有 '=' 的片段整段當 key 看待，與並存判定同一套解讀；
        // 否則它會被留在舊欄名，而 JSON 欄卻已改成新欄名。
        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '1',
            'table_name' => 'pinyin',
            'operation' => 'INSERT',
            'row_pk' => '{"id":525,"lastname_chn":"瞿曇"}',
            'row_pk_text' => 'id=525&lastname_chn',
            'old_data' => null,
            'new_data' => null,
        ]);

        $this->migration()->up();

        $row = DB::table('audit_log')->find($id);
        $this->assertSame('{"id":525,"c_chn":"瞿曇"}', $row->row_pk);
        $this->assertSame('id=525&c_chn', $row->row_pk_text, '只有 key 的片段也要一起改名');
    }

    #[Test]
    public function test_up_still_renames_when_an_untouched_column_is_non_canonical(): void {
        // 反面：不需要改名的欄即使不是標準編碼也不影響——反正不會被寫入，
        // 不該因此擋掉整列（否則守衛就過度保守了）。
        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '1',
            'table_name' => 'pinyin',
            'operation' => 'UPDATE',
            'row_pk' => '{"id":525,"lastname_chn":"瞿曇"}',
            'row_pk_text' => 'id=525&lastname_chn=%E7%9E%BF%E6%9B%87',
            // 排版不標準，但完全沒有要改名的欄位
            'old_data' => '{ "c_lastname": 1 }',
            'new_data' => '{"lastname_pinyin":"Qutan"}',
        ]);

        $this->migration()->up();

        $row = DB::table('audit_log')->find($id);
        $this->assertSame('{"id":525,"c_chn":"瞿曇"}', $row->row_pk);
        $this->assertSame('id=525&c_chn=%E7%9E%BF%E6%9B%87', $row->row_pk_text);
        $this->assertSame('{"c_pinyin":"Qutan"}', $row->new_data);
        $this->assertSame('{ "c_lastname": 1 }', $row->old_data, '沒有要改名的欄應原樣保留');
    }

    #[Test]
    public function test_up_skips_snapshots_that_cannot_be_reencoded_losslessly(): void {
        // 同一道守衛也擋掉排版／轉義與 encodeJson() 不同的快照——
        // 那種情況重新編碼會順手改掉不該改的位元組。
        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '1',
            'table_name' => 'pinyin',
            'operation' => 'INSERT',
            'row_pk' => '{"id":1}',
            'row_pk_text' => 'id=1',
            'old_data' => null,
            // 有空白排版，且中文以 \u 轉義
            'new_data' => '{ "lastname_chn": "瞿曇" }',
        ]);

        $this->migration()->up();

        $this->assertSame(
            '{ "lastname_chn": "瞿曇" }',
            DB::table('audit_log')->find($id)->new_data,
            '無法無損重編的快照應原樣保留'
        );
    }

    #[Test]
    public function test_up_skips_the_whole_row_when_only_one_snapshot_column_has_a_collision(): void {
        // 逐欄各自決定會產生「row_pk 沒改、new_data 改了」的半套狀態，
        // 讓同一列的各個快照互相矛盾。整列跳過才對。
        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '1',
            'table_name' => 'pinyin',
            'operation' => 'UPDATE',
            // 只有 row_pk 新舊並存
            'row_pk' => '{"id":1,"lastname_chn":"OLD","c_chn":"NEW"}',
            'row_pk_text' => 'id=1',
            'old_data' => null,
            // 這欄自己沒有衝突，若逐欄決定就會被改成 c_pinyin
            'new_data' => '{"lastname_pinyin":"Qutan"}',
        ]);
        $original = DB::table('audit_log')->find($id);

        $this->migration()->up();

        $this->assertEquals(
            $original,
            DB::table('audit_log')->find($id),
            '任一快照欄有衝突就應整列原樣保留，不可只改沒衝突的那欄'
        );
    }

    #[Test]
    public function test_up_skips_rows_where_old_and_new_column_names_coexist(): void {
        // 兩者同時存在時哪個才權威無從判斷，硬改會覆蓋掉其中一個值。
        // 稽核資料上寧可原樣留著（頁面顯示「未取得」，程式端已能承受），也不要靜默丟值。
        $id = DB::table('audit_log')->insertGetId([
            'operation_id' => '1',
            'table_name' => 'pinyin',
            'operation' => 'UPDATE',
            'row_pk' => '{"id":1,"lastname_chn":"OLD","c_chn":"NEW"}',
            'row_pk_text' => 'id=1&lastname_chn=OLD&c_chn=NEW',
            'old_data' => null,
            'new_data' => '{"lastname_pinyin":"Jiu","c_pinyin":"Xin"}',
        ]);
        $original = DB::table('audit_log')->find($id);

        $this->migration()->up();

        $this->assertEquals($original, DB::table('audit_log')->find($id), '新舊欄名並存時應整列原樣保留，不得覆蓋任何值');
    }

    #[Test]
    public function test_migration_is_a_no_op_on_an_empty_audit_log(): void {
        // 絕大多數機器（新裝、本機、CI）根本沒有這 4 筆歷史列，跑起來必須是安靜的 no-op。
        $this->assertSame(0, DB::table('audit_log')->count());

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(0, DB::table('audit_log')->count());
    }

    #[Test]
    public function test_migration_tolerates_null_empty_and_malformed_snapshots(): void {
        // audit_log 裡的快照欄位歷來什麼形狀都有；篩得到卻解不開時必須略過，不能中斷 migration。
        $ids = [];
        foreach ([
            ['row_pk' => null, 'row_pk_text' => null, 'old_data' => null, 'new_data' => null],
            ['row_pk' => '', 'row_pk_text' => '', 'old_data' => '', 'new_data' => ''],
            // 含舊欄名（會被 LIKE 篩到）但不是合法 JSON 物件
            ['row_pk' => 'lastname_chn', 'row_pk_text' => 'lastname_chn', 'old_data' => '["lastname_chn"]', 'new_data' => '{lastname_chn'],
        ] as $snapshot) {
            $ids[] = DB::table('audit_log')->insertGetId(array_merge([
                'operation_id' => '1',
                'table_name' => 'pinyin',
                'operation' => 'UPDATE',
            ], $snapshot));
        }

        $before = DB::table('audit_log')->whereIn('id', $ids)->orderBy('id')->get();

        $this->migration()->up();

        $this->assertEquals(
            $before,
            DB::table('audit_log')->whereIn('id', $ids)->orderBy('id')->get(),
            '解不開的快照應原樣保留'
        );
    }

    #[Test]
    public function test_migration_is_a_no_op_when_audit_log_table_is_absent(): void {
        Schema::dropIfExists('audit_log');

        $this->migration()->up();
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('audit_log'));
    }
}
