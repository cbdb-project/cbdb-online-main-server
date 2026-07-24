<?php

namespace Tests\Feature;

use App\Http\Controllers\MergePreviewController;
use App\Repositories\BiogMainRepository;
use App\Repositories\OfficePostingRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 去級聯 Phase 1「應用層顯式級聯」回歸測試。
 *
 * 背景：詞表入邊已全數翻成 ON DELETE RESTRICT（批次 1–4），剩下的 28 條 CASCADE
 * （BIOG_MAIN 25／POSTING_DATA 2／POSSESSION_DATA 1）在翻轉前，須先讓應用層自己
 * 把「連帶刪除」做對——先子後父、且父列僅在無剩餘引用時才刪。
 * 本檔鎖定翻轉**之前**就能觀察到的行為，作為上線觀察期的回歸護欄。
 *
 * 註：測試環境為 SQLite、無外鍵（AGENTS.md §1），因此**驗不到 1451 本身**；
 * 1451 相關行為在 MariaDB 上驗（docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md §7）。
 * 這裡驗的是與外鍵無關、但翻轉後正確性所依賴的刪除語義。
 */
class ExplicitCascadeDeleteTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_posting_id')->primary();
            $table->integer('c_personid')->default(0);
        });
        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid')->default(0);
            $table->integer('c_office_id')->default(0);
            $table->integer('c_posting_id')->default(0);
        });
        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid')->default(0);
            $table->integer('c_office_id')->default(0);
            $table->integer('c_posting_id')->default(0);
            $table->integer('c_addr_id')->default(0);
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('POSTING_DATA');
        Schema::dropIfExists('POSSESSION_ADDR');
        Schema::dropIfExists('POSSESSION_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        parent::tearDown();
    }

    // ---------------------------------------------------------------- POSTING_DATA

    #[Test]
    public function posting_parent_is_kept_while_another_office_row_still_references_it(): void {
        // prod 實測有 31 個 c_posting_id 被多列 POSTED_TO_OFFICE_DATA 共用；翻轉前逕刪父列
        // 會被 CASCADE 靜默連坐刪掉兄弟列，翻轉後則是 1451。兩種都不可接受——父列必須留著。
        DB::table('POSTING_DATA')->insert(['c_posting_id' => 900, 'c_personid' => 1]);
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            ['c_personid' => 1, 'c_office_id' => 10, 'c_posting_id' => 900],
            ['c_personid' => 1, 'c_office_id' => 11, 'c_posting_id' => 900],
        ]);

        // 模擬「其中一列已被刪除」後的收尾
        DB::table('POSTED_TO_OFFICE_DATA')->where('c_office_id', 10)->delete();
        OfficePostingRepository::deletePostingIfUnreferenced(900);

        $this->assertDatabaseHas('POSTING_DATA', ['c_posting_id' => 900]);
        $this->assertSame(1, DB::table('POSTED_TO_OFFICE_DATA')->where('c_posting_id', 900)->count());
    }

    #[Test]
    public function posting_parent_is_removed_once_the_last_reference_is_gone(): void {
        DB::table('POSTING_DATA')->insert(['c_posting_id' => 901, 'c_personid' => 1]);
        DB::table('POSTED_TO_OFFICE_DATA')->insert(['c_personid' => 1, 'c_office_id' => 10, 'c_posting_id' => 901]);

        DB::table('POSTED_TO_OFFICE_DATA')->where('c_posting_id', 901)->delete();
        OfficePostingRepository::deletePostingIfUnreferenced(901);

        $this->assertDatabaseMissing('POSTING_DATA', ['c_posting_id' => 901]);
    }

    #[Test]
    public function posting_parent_is_kept_while_only_an_address_row_still_references_it(): void {
        // 地址子列同樣是 POSTING_DATA 的引用者（POSTED_TO_ADDR_DATA_ibfk_4）。
        DB::table('POSTING_DATA')->insert(['c_posting_id' => 902, 'c_personid' => 1]);
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 1, 'c_office_id' => 10, 'c_posting_id' => 902, 'c_addr_id' => 5,
        ]);

        OfficePostingRepository::deletePostingIfUnreferenced(902);

        $this->assertDatabaseHas('POSTING_DATA', ['c_posting_id' => 902]);
    }

    #[Test]
    public function deleting_an_unreferenced_posting_id_is_a_no_op_when_null(): void {
        DB::table('POSTING_DATA')->insert(['c_posting_id' => 903, 'c_personid' => 1]);

        OfficePostingRepository::deletePostingIfUnreferenced(null);

        $this->assertDatabaseHas('POSTING_DATA', ['c_posting_id' => 903]);
    }

    // ------------------------------------------------------------- POSSESSION_DATA

    #[Test]
    public function every_cascade_deleted_row_lands_in_operations_and_audit_log(): void {
        // 核心要求：連帶刪除的每一列都要留下 operations 痕跡。DB 級聯之所以危險就是被它刪掉
        // 的列對應用層不可見（§3.3）；搬到應用層後若只記父列，盲區等於原樣搬過來。
        $this->createOperationsAndAuditTables();

        DB::table('POSTING_DATA')->insert(['c_posting_id' => 910, 'c_personid' => 7]);
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            ['c_personid' => 7, 'c_office_id' => 10, 'c_posting_id' => 910, 'c_addr_id' => 41],
            ['c_personid' => 7, 'c_office_id' => 10, 'c_posting_id' => 910, 'c_addr_id' => 42],
        ]);

        $addrRows = DB::table('POSTED_TO_ADDR_DATA')->where('c_posting_id', 910)->get();
        DB::table('POSTED_TO_ADDR_DATA')->where('c_posting_id', 910)->delete();

        $logger = new \App\Services\ExplicitCascadeLogger();
        $groupId = $logger->logDeletedRows('POSTED_TO_ADDR_DATA', 'c_office_id=10&c_posting_id=910', $addrRows, 7);

        $deletedPosting = OfficePostingRepository::deletePostingIfUnreferenced(910);
        $this->assertNotNull($deletedPosting, '最後一個引用消失後應刪除 POSTING_DATA 父列');
        $logger->logDeletedRows('POSTING_DATA', '910', [$deletedPosting], 7, $groupId);

        // operations：兩張表各一筆刪除紀錄，且被刪列的內容都在 payload 裡
        $addrOperation = DB::table('operations')->where('resource', 'POSTED_TO_ADDR_DATA')->first();
        $this->assertNotNull($addrOperation, 'POSTED_TO_ADDR_DATA 的連帶刪除未寫入 operations');
        $this->assertSame(4, (int) $addrOperation->op_type);
        $this->assertSame(7, (int) $addrOperation->c_personid);
        $addrPayload = json_decode($addrOperation->resource_original, true);
        $this->assertCount(2, $addrPayload['rows'], '被刪的兩列地址都要在 operations payload 中');
        $this->assertSame([41, 42], array_column($addrPayload['rows'], 'c_addr_id'));

        $postingOperation = DB::table('operations')->where('resource', 'POSTING_DATA')->first();
        $this->assertNotNull($postingOperation, 'POSTING_DATA 父列的連帶刪除未寫入 operations');
        $this->assertSame(4, (int) $postingOperation->op_type);

        // audit_log：逐列 before-image，且整組共用同一 operation_id（可整組回退）
        $auditRows = DB::table('audit_log')->where('operation', 'DELETE')->get();
        $this->assertCount(3, $auditRows, '2 列地址 + 1 列 posting，每列都要有 audit before-image');
        $this->assertCount(1, array_unique($auditRows->pluck('operation_id')->all()), '整組應共用同一 operation_id');
        foreach ($auditRows as $auditRow) {
            $this->assertNotNull($auditRow->old_data);
            $this->assertNull($auditRow->new_data);
        }
    }

    #[Test]
    public function logging_a_cascade_delete_with_no_child_rows_writes_nothing(): void {
        $this->createOperationsAndAuditTables();

        $groupId = (new \App\Services\ExplicitCascadeLogger())
            ->logDeletedRows('POSTED_TO_ADDR_DATA', 'c_office_id=1&c_posting_id=2', [], 7, 'group-1');

        $this->assertSame('group-1', $groupId);
        $this->assertSame(0, DB::table('operations')->count());
        $this->assertSame(0, DB::table('audit_log')->count());
    }

    protected function createOperationsAndAuditTables(): void {
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
    }

    #[Test]
    public function possession_delete_removes_child_address_rows_as_well(): void {
        // 顯式級聯改為「先子後父」後，兩張表都必須清乾淨（翻轉前靠 CASCADE 代勞，
        // 翻轉後若順序寫反會 1451）。
        $this->createOperationsAndAuditTables();

        Schema::create('POSSESSION_DATA', function (Blueprint $table) {
            $table->integer('c_possession_record_id')->primary();
            $table->integer('c_personid')->default(0);
        });
        Schema::create('POSSESSION_ADDR', function (Blueprint $table) {
            $table->integer('c_personid')->default(0);
            $table->integer('c_possession_record_id')->default(0);
            $table->integer('c_addr_id')->default(0);
        });

        DB::table('POSSESSION_DATA')->insert(['c_possession_record_id' => 77, 'c_personid' => 3]);
        DB::table('POSSESSION_ADDR')->insert([
            ['c_personid' => 3, 'c_possession_record_id' => 77, 'c_addr_id' => 1],
            ['c_personid' => 3, 'c_possession_record_id' => 77, 'c_addr_id' => 2],
        ]);

        (new BiogMainRepository())->possessionDeleteById(77, 3);

        $this->assertDatabaseMissing('POSSESSION_DATA', ['c_possession_record_id' => 77]);
        $this->assertSame(0, DB::table('POSSESSION_ADDR')->where('c_possession_record_id', 77)->count());
    }

    // ------------------------------------------------------- MergePreview SQL 腳本

    #[Test]
    public function merge_script_repoints_every_column_that_references_biog_main(): void {
        // 腳本末尾的 DELETE FROM BIOG_MAIN 之所以今天「能跑通」，是因為漏列的引用會被
        // CASCADE 靜默刪掉；翻成 RESTRICT 後漏一欄就是 1451。故 map 必須涵蓋全部 25 條入邊。
        $controller = new MergePreviewController();
        $method = new \ReflectionMethod($controller, 'buildSqlPreview');
        $method->setAccessible(true);

        $statements = $method->invoke($controller, 100, 200, [], false, null);
        $sql = implode("\n", $statements);

        $expected = [
            'ALTNAME_DATA' => ['c_personid'],
            'ASSOC_DATA' => ['c_personid', 'c_kin_id', 'c_assoc_id', 'c_assoc_kin_id', 'c_tertiary_personid', 'c_assoc_claimer_id'],
            'BIOG_ADDR_DATA' => ['c_personid'],
            'BIOG_INST_DATA' => ['c_personid'],
            'BIOG_SOURCE_DATA' => ['c_personid'],
            'BIOG_TEXT_DATA' => ['c_personid'],
            'ENTRY_DATA' => ['c_personid', 'c_assoc_id', 'c_kin_id'],
            'EVENTS_ADDR' => ['c_personid'],
            'EVENTS_DATA' => ['c_personid'],
            'KIN_DATA' => ['c_personid', 'c_kin_id'],
            'POSSESSION_ADDR' => ['c_personid'],
            'POSSESSION_DATA' => ['c_personid'],
            'POSTED_TO_ADDR_DATA' => ['c_personid'],
            'POSTED_TO_OFFICE_DATA' => ['c_personid'],
            'POSTING_DATA' => ['c_personid'],
            'STATUS_DATA' => ['c_personid'],
            'operations' => ['c_personid'],
        ];

        foreach ($expected as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertStringContainsString(
                    sprintf('UPDATE %s SET %s = 100 WHERE %s = 200;', $table, $column, $column),
                    $sql,
                    "合併腳本未把 {$table}.{$column} 重新指向存活人物；翻 RESTRICT 後 DELETE FROM BIOG_MAIN 會 1451"
                );
                $this->assertStringContainsString(
                    sprintf('FROM %s WHERE %s = 200;', $table, $column),
                    $sql,
                    "合併腳本的「確認為 0」檢查漏掉 {$table}.{$column}"
                );
            }
        }

        $this->assertStringContainsString('DELETE FROM BIOG_MAIN WHERE c_personid = 200;', $sql);
    }
}
