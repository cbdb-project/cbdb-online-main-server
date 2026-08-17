<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * /operations 與 /app/operations 的「現況比對」在遇到歷史遺留資料時不得整頁 500。
 *
 * 生產事故（2026-08-17）：核准一筆 POSTED_TO_OFFICE_DATA 提案後，
 * /app/operations?proposals_only=1 全頁 500，兩個成因都在「查現況」這段：
 *  1. POSTED_TO_OFFICE_DATA 的 switch/case 只認 '-' 分隔符，
 *     碰到 '_._' 格式的 resource_id（61211_._2108722）時 $temp_l[1] 未定義，
 *     Laravel 把 warning 轉成 ErrorException。
 *  2. audit_log.row_pk 是寫入當下的欄名快照，pinyin.lastname_chn 於
 *     2026_07_10 migration 改名為 c_chn，舊 row_pk 拿去組 WHERE 直接 1054。
 */
class OperationsIndexResilienceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        $this->stubOperationsViews();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->default(0);
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->longText('resource_data');
            $table->longText('resource_original')->nullable();
            $table->timestamps();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
        });

        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
            $table->integer('c_sequence')->nullable();
        });

        // React 版（appIndex → serializeOperationRow）會替受影響人物撈姓名，需要這張表。
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('pinyin');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');

        // 每個測試方法都會建兩個暫存目錄，不清掉會隨測試次數累積。
        foreach ($this->tempDirs as $dir) {
            $this->deleteDirectory($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    /** @var string[] 本測試建立的暫存目錄，tearDown 時刪除。 */
    private array $tempDirs = [];

    private function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** 用最小 Blade stub 取代真實 operations 視圖，讓測試專注在 controller 的取資料邏輯。 */
    protected function stubOperationsViews(): void {
        $tempBase = sys_get_temp_dir() . '/laravel-test-views-' . uniqid();
        mkdir($tempBase . '/layouts', 0777, true);
        mkdir($tempBase . '/operations', 0777, true);
        $this->tempDirs[] = $tempBase;

        file_put_contents(
            $tempBase . '/layouts/dashboard.blade.php',
            "<!doctype html>\n<html lang=\"zh-Hant\"><head><meta charset=\"utf-8\"></head><body>@yield('content')</body></html>\n"
        );
        file_put_contents(
            $tempBase . '/operations/index.blade.php',
            "@extends('layouts.dashboard')\n\n@section('content')\n    <h1>最近編輯列表</h1>\n@endsection\n"
        );

        $compiledPath = sys_get_temp_dir() . '/laravel-views-' . uniqid();
        mkdir($compiledPath, 0777, true);
        $this->tempDirs[] = $compiledPath;

        // 暫存目錄排在真實 views 之前：operations.index 用輕量 stub（真實視圖依賴 AdminLTE），
        // 其餘（例如 Inertia 的 root view）仍要解析得到真實檔案，否則 /app/operations 會 500。
        $paths = [$tempBase, resource_path('views')];
        config()->set('view.paths', $paths);
        config()->set('view.compiled', $compiledPath);
        app('view')->setFinder(new \Illuminate\View\FileViewFinder(app('files'), $paths));
    }

    protected function actingAsAdmin(): User {
        $user = User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        $this->actingAs($user);

        return $user;
    }

    /** audit_log 的最小結構，欄位對齊 loadAuditLogsByOperation() 實際 select 的清單。 */
    protected function createAuditLogTable(): void {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->increments('id');
            $table->string('operation_id')->nullable();
            $table->string('table_name');
            $table->string('operation');
            $table->longText('row_pk')->nullable();
            $table->string('row_pk_text')->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
        });
    }

    protected function createPostedToOfficeOperation(string $resourceId): Operation {
        return Operation::create([
            'user_id' => 1,
            'c_personid' => 101895,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => $resourceId,
            'resource_data' => json_encode([
                'c_personid' => 101895,
                'c_office_id' => 61211,
                'c_posting_id' => 2108722,
                'c_sequence' => 2,
                '__review_status' => 'approved',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 101895,
                'c_office_id' => 61211,
                'c_posting_id' => 2108722,
                'c_sequence' => 1,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    #[Test]
    public function test_proposals_list_resolves_posted_to_office_data_with_dot_separator(): void {
        // 生產事故主因：'_._' 分隔的 resource_id 讓 $temp_l[1] 未定義而整頁 500。
        // 這裡不只要 200，還要真的用 '_._' 兩段當 c_office_id / c_posting_id 撈到現況列。
        $this->actingAsAdmin();

        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 101895,
            'c_office_id' => 61211,
            'c_posting_id' => 2108722,
            'c_sequence' => 2,
        ]);

        $this->createPostedToOfficeOperation('61211_._2108722');

        $response = $this->get('/operations?proposals_only=1');
        $response->assertStatus(200);

        $lists = $response->viewData('lists');
        $diff = $lists[0]->getAttribute('resource_diff');
        $this->assertNotNull($diff, "'_._' 格式應能查到現況列並產生差異比對");

        $sequenceRow = collect($diff['rows'])->firstWhere('field', 'c_sequence');
        $this->assertNotNull($sequenceRow);
        $this->assertSame('2', $sequenceRow['current'], "現況欄應來自 POSTED_TO_OFFICE_DATA 實際資料列");
        $this->assertTrue($sequenceRow['matches_current']);

        // 出事的網址是 flag 已上線的 React 版；它與 Blade 版共用 buildOperationsListing()，
        // 但既然回報的是這條路徑，就直接把它釘住。
        $this->get('/app/operations?proposals_only=1')->assertStatus(200);
    }

    #[Test]
    public function test_proposals_list_resolves_posted_to_office_data_with_dash_separator(): void {
        // 新增 '_._' 分支時不能把原本的 '-' 格式弄壞（把條件寫反也要抓得到）。
        $this->actingAsAdmin();

        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 101895,
            'c_office_id' => 61211,
            'c_posting_id' => 2108722,
            'c_sequence' => 2,
        ]);

        $this->createPostedToOfficeOperation('61211-2108722');

        $response = $this->get('/operations?proposals_only=1');
        $response->assertStatus(200);

        $lists = $response->viewData('lists');
        $diff = $lists[0]->getAttribute('resource_diff');
        $sequenceRow = collect($diff['rows'])->firstWhere('field', 'c_sequence');
        $this->assertSame('2', $sequenceRow['current'], "'-' 格式應繼續查得到現況列");
        $this->assertTrue($sequenceRow['matches_current']);
    }

    #[Test]
    public function test_proposals_list_survives_posted_to_office_data_id_without_separator(): void {
        // resource_id 只有一段（分隔符不明／資料損壞）時要降級成「查不到現況」，不可 500。
        $this->actingAsAdmin();

        $this->createPostedToOfficeOperation('61211');

        $response = $this->get('/operations?proposals_only=1');
        $response->assertStatus(200);

        $lists = $response->viewData('lists');
        $diff = $lists[0]->getAttribute('resource_diff');
        $this->assertNotNull($diff, '提案的前後值仍應比對得出差異');

        $sequenceRow = collect($diff['rows'])->firstWhere('field', 'c_sequence');
        $this->assertSame('(未取得)', $sequenceRow['current'], '查不到現況列時現況欄應標為未取得');
    }

    #[Test]
    public function test_audit_diff_skips_row_pk_columns_dropped_by_migration(): void {
        // pinyin.lastname_chn 已被 2026_07_10 migration 改名為 c_chn，
        // 舊 audit_log.row_pk 仍記著舊欄名；不可拿去組 WHERE（SQLSTATE 42S22）。
        $this->actingAsAdmin();
        $this->createAuditLogTable();

        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn');
            $table->string('c_pinyin')->nullable();
            $table->tinyInteger('c_lastname')->default(0);
        });
        DB::table('pinyin')->insert([
            'id' => 525,
            'c_chn' => '瞿曇',
            'c_pinyin' => 'Qutan',
            'c_lastname' => 1,
        ]);

        $operation = Operation::create([
            'user_id' => 1,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'pinyin',
            'resource_id' => '525_._瞿曇',
            'resource_data' => json_encode([
                'c_chn' => '瞿曇',
                '__review_status' => 'approved',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table('audit_log')->insert([
            'operation_id' => (string) $operation->id,
            'table_name' => 'pinyin',
            'operation' => 'INSERT',
            // 舊欄名 lastname_chn 現行 schema 已不存在
            'row_pk' => json_encode(['id' => 525, 'lastname_chn' => '瞿曇'], JSON_UNESCAPED_UNICODE),
            'row_pk_text' => 'id=525&lastname_chn=%E7%9E%BF%E6%9B%87',
            'old_data' => null,
            'new_data' => json_encode(['id' => 525, 'c_pinyin' => 'Qutan'], JSON_UNESCAPED_UNICODE),
        ]);

        // 斷言「機制」而非只看輸出：MariaDB 上這條 WHERE 會噴 SQLSTATE[42S22]，
        // 但 SQLite 會把無法解析的雙引號識別字當成字串常量，查詢照跑、只是查不到列，
        // 現況欄同樣顯示「未取得」。若只斷言輸出，這個測試在修好前後都會過（等於沒測到）。
        // 因此直接證明那句 SQL 從未發出。
        $executedSql = [];
        DB::listen(function ($event) use (&$executedSql) {
            $executedSql[] = $event->sql;
        });

        $response = $this->get('/operations?proposals_only=1');
        $response->assertStatus(200);

        $this->assertNotEmpty($executedSql, 'DB::listen 應攔到查詢，否則下面的斷言不成立');
        foreach ($executedSql as $sql) {
            $this->assertStringNotContainsString(
                'lastname_chn',
                $sql,
                '欄名對不上現行 schema 時，不該把它組進 WHERE（MariaDB 上會 SQLSTATE[42S22] 讓整頁 500）'
            );
        }

        $lists = $response->viewData('lists');
        $auditLogs = $lists[0]->getAttribute('audit_logs');
        $this->assertCount(1, $auditLogs);

        $pinyinRow = collect($auditLogs[0]['diff']['rows'])->firstWhere('field', 'c_pinyin');
        $this->assertNotNull($pinyinRow);
        $this->assertSame('(未取得)', $pinyinRow['current'], '欄名對不上現行 schema 時應放棄查現況而非報錯');
    }

    #[Test]
    public function test_audit_diff_still_resolves_current_row_when_row_pk_columns_exist(): void {
        // 上一個測試的反面：欄名都在時，現況查詢照舊要生效（別把功能一起關掉）。
        $this->actingAsAdmin();
        $this->createAuditLogTable();

        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn');
            $table->string('c_pinyin')->nullable();
            $table->tinyInteger('c_lastname')->default(0);
        });
        DB::table('pinyin')->insert([
            'id' => 525,
            'c_chn' => '瞿曇',
            'c_pinyin' => 'Qutan',
            'c_lastname' => 1,
        ]);

        $operation = Operation::create([
            'user_id' => 1,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'pinyin',
            'resource_id' => '525_._瞿曇',
            'resource_data' => json_encode([
                'c_chn' => '瞿曇',
                '__review_status' => 'approved',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table('audit_log')->insert([
            'operation_id' => (string) $operation->id,
            'table_name' => 'pinyin',
            'operation' => 'INSERT',
            'row_pk' => json_encode(['id' => 525, 'c_chn' => '瞿曇'], JSON_UNESCAPED_UNICODE),
            'row_pk_text' => 'id=525&c_chn=%E7%9E%BF%E6%9B%87',
            'old_data' => null,
            'new_data' => json_encode(['id' => 525, 'c_pinyin' => 'Qutan'], JSON_UNESCAPED_UNICODE),
        ]);

        $response = $this->get('/operations?proposals_only=1');
        $response->assertStatus(200);

        $lists = $response->viewData('lists');
        $auditLogs = $lists[0]->getAttribute('audit_logs');
        $pinyinRow = collect($auditLogs[0]['diff']['rows'])->firstWhere('field', 'c_pinyin');
        $this->assertSame('Qutan', $pinyinRow['current'], '欄名都在時應正常查到現況列');
        $this->assertTrue($pinyinRow['matches_current']);
    }

    #[Test]
    public function test_proposals_list_survives_biog_main_person_not_yet_created(): void {
        // 新增人物的提案在核准前 BIOG_MAIN 還沒有那一列（表在 setUp 已建但無此人），find() 會是 null。
        $this->actingAsAdmin();

        Operation::create([
            'user_id' => 1,
            'c_personid' => 231694,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'BIOG_MAIN',
            'resource_id' => 'c_personid=231694',
            'resource_data' => json_encode([
                'c_personid' => 231694,
                'c_name_chn' => '新人物',
                '__review_status' => 'pending',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 231694,
                'c_name_chn' => '舊名',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->get('/operations?proposals_only=1');
        $response->assertStatus(200);

        $lists = $response->viewData('lists');
        $diff = $lists[0]->getAttribute('resource_diff');
        $nameRow = collect($diff['rows'])->firstWhere('field', 'c_name_chn');
        $this->assertSame('(未取得)', $nameRow['current'], '人物尚未建立時現況欄應標為未取得');
    }

    #[Test]
    public function test_proposals_list_survives_assoc_data_title_containing_the_dot_separator(): void {
        // 分隔符是照原始 id 嗅探的，而 c_text_title 是自由文字：標題含 '_._' 時
        // dash 格式的 id 會誤走 '_._' 分支，只切出 3 段，$assoc_1[3] 未定義 → 整頁 500。
        $this->actingAsAdmin();

        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code');
            $table->integer('c_assoc_id');
            $table->integer('c_kin_code')->nullable();
            $table->integer('c_kin_id')->nullable();
            $table->integer('c_assoc_kin_code')->nullable();
            $table->integer('c_assoc_kin_id')->nullable();
            $table->string('c_text_title')->nullable();
            $table->string('c_assoc_first_year')->nullable();
        });

        try {
            Operation::create([
                'user_id' => 1,
                'c_personid' => 0,
                'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
                'resource' => 'ASSOC_DATA',
                'resource_id' => '1-100-2-0-0-0-0-書名_._卷一-1200',
                'resource_data' => json_encode([
                    'c_personid' => 1,
                    'c_text_title' => '書名_._卷一',
                    '__review_status' => 'approved',
                ], JSON_UNESCAPED_UNICODE),
                'resource_original' => json_encode([
                    'c_personid' => 1,
                    'c_text_title' => '舊書名',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $response = $this->get('/operations?proposals_only=1');
            $response->assertStatus(200);
        } finally {
            Schema::dropIfExists('ASSOC_DATA');
        }
    }

    #[Test]
    public function test_proposals_list_survives_altname_data_legacy_id_when_table_is_absent(): void {
        // ALTNAME_DATA 的 '_._'／dash 格式走的是舊格式 switch/case，繞過新格式路徑那道把關；
        // 該表的主鍵組合本身變動過（4-key → 3-key，#834），所以這條也得擋。
        $this->actingAsAdmin();

        Operation::create([
            'user_id' => 1,
            'c_personid' => 338289,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '338289_._王艮_._3',
            'resource_data' => json_encode([
                'c_personid' => 338289,
                'c_alt_name_chn' => '王艮',
                '__review_status' => 'approved',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 338289,
                'c_alt_name_chn' => '舊名',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->assertFalse(Schema::hasTable('ALTNAME_DATA'));

        $this->get('/operations?proposals_only=1')->assertStatus(200);
    }

    #[Test]
    public function test_proposals_list_survives_posted_to_addr_data_with_renamed_column(): void {
        // rows 映射讀的 4 個欄位不在 resource_id 的主鍵組合裡，主鍵欄檢查蓋不到；
        // 少了 c_addr_id 就會是 undefined property → ErrorException → 整頁 500。
        $this->actingAsAdmin();

        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
            // c_addr_id 刻意缺席，模擬欄位被改名／移除
        });

        try {
            DB::table('POSTED_TO_ADDR_DATA')->insert([
                'c_personid' => 101895,
                'c_office_id' => 61211,
                'c_posting_id' => 2108722,
            ]);

            Operation::create([
                'user_id' => 1,
                'c_personid' => 101895,
                'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
                'resource' => 'POSTED_TO_ADDR_DATA',
                'resource_id' => '61211_._2108722',
                'resource_data' => json_encode([
                    'rows' => [['c_personid' => 101895, 'c_posting_id' => 2108722, 'c_office_id' => 61211, 'c_addr_id' => 1]],
                    '__review_status' => 'approved',
                ], JSON_UNESCAPED_UNICODE),
                'resource_original' => json_encode(['rows' => []], JSON_UNESCAPED_UNICODE),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $this->get('/operations?proposals_only=1')->assertStatus(200);
        } finally {
            Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        }
    }

    #[Test]
    public function test_proposals_list_survives_posted_to_addr_data_rows_that_is_not_an_array(): void {
        // buildPostedToAddrDiff() 的參數宣告是 array，`?? []` 擋不到「存在但不是陣列」。
        $this->actingAsAdmin();

        Operation::create([
            'user_id' => 1,
            'c_personid' => 101895,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'POSTED_TO_ADDR_DATA',
            'resource_id' => '61211_._2108722',
            'resource_data' => json_encode(['rows' => '', '__review_status' => 'approved'], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode(['rows' => ''], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->get('/operations?proposals_only=1')->assertStatus(200);
    }

    #[Test]
    public function test_react_list_survives_non_array_proposal_meta(): void {
        // React 版的 serializeOperationRow() 在 __proposal_meta 不是陣列時仍會直接下標，
        // 但每一處都帶 `?? null`——PHP 的 `??` 對字串下標走 isset 語義，回 null 而不拋錯。
        // 這條把該行為釘住：哪天有人把 `?? null` 拿掉，或改成不帶 `??` 的存取，就會紅。
        $this->actingAsAdmin();

        Operation::create([
            'user_id' => 1,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'TEXT_CODES',
            'resource_id' => '7092',
            'resource_data' => json_encode([
                '__proposal_meta' => 'corrupt',
                '__review_status' => 'pending',
                'c_title_chn' => '書名',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode(['c_title_chn' => '舊書名'], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->get('/app/operations?proposals_only=1')->assertStatus(200);
        $this->get('/operations?proposals_only=1')->assertStatus(200);
    }

    #[Test]
    public function test_proposals_list_survives_resource_that_is_not_a_real_table(): void {
        // resource 不是真實表名（改名／別名）時，query-string 格式不可硬查 DB。
        $this->actingAsAdmin();

        Operation::create([
            'user_id' => 1,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => 'c_personid=1&c_alt_name_chn=X&c_alt_name_type_code=5',
            'resource_data' => json_encode([
                'c_personid' => 1,
                'c_alt_name_chn' => 'X',
                '__review_status' => 'approved',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 1,
                'c_alt_name_chn' => 'Y',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // ALTNAME_DATA 表刻意不建立，模擬 resource 對不到實體表的情形。
        $this->assertFalse(Schema::hasTable('ALTNAME_DATA'));

        $response = $this->get('/operations?proposals_only=1');
        $response->assertStatus(200);
    }
}
