<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P2-8：遺留 `/api/v1` 群組（biog／add／update／delete／user）與其實作 `App\v1` 已整組刪除。
 *
 * 準確的事實紀錄（別把嚴重度說錯）：這四個端點在刪除前**全部都是 500 的死碼**。
 * `App\v1` 位於 namespace `App`，內部用無限定的 `BiogMain`，解析成不存在的
 * `App\BiogMain`（真正的類別是 `App\Models\BiogMain`），所以每一條都拋
 * `Class "App\BiogMain" not found`——這也是 BIOG_MAIN 裡 `c_created_by='Api'` 為零筆的原因。
 * 它們是**危險的設計**（無認證、無授權、不寫 operations／audit_log、連 c_modified_by 都不蓋
 * 的 BIOG_MAIN 寫入，加上一個 return phpinfo() 的方法），但**不是可用的攻擊面**。
 *
 * 刪除而非修好 import 的理由：修好唯讀那條等於「新開一個實際上從未運作過的公開搜尋端點」，
 * 比刪除風險高；寫入那三條就算修好也不該存在——程式化寫入的正當管線是 `/api/v2/*`
 * （Api\MutationController），有認證、授權、operations 紀錄、audit_log 與提案流程。
 */
class LegacyV1WriteEndpointsRetiredTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config(['cache.default' => 'array']);
        config(['session.driver' => 'array']);

        Schema::dropIfExists('BIOG_MAIN');
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1762,
            'c_name_chn' => '王安石',
            'c_name' => 'Wang Anshi',
        ]);
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_MAIN');
        parent::tearDown();
    }

    #[Test]
    public function the_whole_v1_group_no_longer_exists(): void {
        foreach (['biog', 'add', 'update', 'delete', 'user'] as $action) {
            $this->getJson('/api/v1/'.$action.'?q=1762&c_name_chn=X')
                ->assertNotFound();
        }
    }

    #[Test]
    public function the_person_row_is_untouched(): void {
        $this->getJson('/api/v1/update?q=1762&c_name_chn=竄改');
        $this->getJson('/api/v1/delete?q=1762');

        $row = DB::table('BIOG_MAIN')->where('c_personid', 1762)->first();
        $this->assertSame('王安石', $row->c_name_chn);
    }

    #[Test]
    public function no_new_person_can_be_created(): void {
        $this->getJson('/api/v1/add?c_name_chn=偽造&c_name=Forged');

        $this->assertSame(1, DB::table('BIOG_MAIN')->count());
        $this->assertSame(0, DB::table('BIOG_MAIN')->where('c_created_by', 'Api')->count());
    }

    #[Test]
    public function the_implementation_file_is_gone(): void {
        // 連 return phpinfo() 的 info() 一起消失（路徑／擴充／環境變數洩漏）。
        //
        // 刻意檢查檔案而不是 class_exists()：composer 的 classmap 會把 App\v1 對應到
        // app/v1.php，在還沒 dump-autoload 的環境下 class_exists() 會去 include 一個
        // 不存在的檔案而拋 ErrorException，測不到我們想測的東西。
        $this->assertFileDoesNotExist(app_path('v1.php'), 'app/v1.php 應已整檔刪除');
    }

    #[Test]
    public function the_retired_controller_actions_are_gone(): void {
        foreach ([
            'searchC_presonid',
            'addC_presonid',
            'updateC_presonid',
            'deleteC_presonid',
            'userC_presonid',
        ] as $action) {
            $this->assertFalse(
                method_exists(\App\Http\Controllers\ApiController::class, $action),
                "ApiController::{$action}() 應已刪除"
            );
        }
    }

    #[Test]
    public function no_route_points_at_the_retired_actions(): void {
        // 殘留的路由定義會讓整個路由檔在啟動時炸掉；這條同時擋住那種情況。
        $retired = [
            'searchC_presonid',
            'addC_presonid',
            'updateC_presonid',
            'deleteC_presonid',
            'userC_presonid',
            'App\\v1',
        ];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            foreach ($retired as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $action,
                    "路由 {$route->uri()} 仍指向已下架的 {$needle}"
                );
            }
        }
    }

    #[Test]
    public function nothing_in_the_app_still_references_the_deleted_class(): void {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            // 只抓 use/實例化，註解裡提到 App\v1 是刻意保留的說明文字。
            if (preg_match('/(^|\n)\s*use\s+App\\\\v1\s*;|new\s+v1\s*\(/', $contents)) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, "以下檔案仍引用已刪除的 App\\v1：\n".implode("\n", $offenders));
    }
}
