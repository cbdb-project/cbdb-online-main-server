<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 重整 pinyin 表結構，準備吸收 app/Models/Pinyin.php 的單字字典資料
 * （見 docs/PINYIN_TABLE_CONSOLIDATION_PLAN.md）：
 * - lastname_chn → c_chn、lastname_pinyin → c_pinyin（比照 CBDB c_ 前綴慣例）
 * - 新增 c_lastname 旗標欄位（1=姓氏讀音，0=一般讀音），既有姓氏資料回填為 1
 * - 建立 (c_chn, c_lastname) 複合唯一鍵，允許同一字同時有姓氏／一般兩種讀音並存
 *
 * down() 刻意不做任何資料完整性檢查：完整 migrate:rollback 是 LIFO 順序，
 * 下一個 migration（匯入一般字典，2026_07_10_000001）的 down() 會先執行、
 * 並在通過內容指紋比對後才清空 c_lastname=0 的資料；等輪到本 migration 的
 * down() 執行時，表裡只會剩下 c_lastname=1 的姓氏資料，直接改回舊欄位名即可，
 * 不需要在這裡重複檢查（重複檢查反而會在正常回滾流程中誤判失敗，見 work plan）。
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        if (!Schema::hasTable('pinyin')) {
            return;
        }

        // 1. 資料完整性檢查：既有資料若有 NULL/空字串或重複姓氏字，
        //    後續的 NOT NULL 與唯一鍵約束會建立失敗。先在這裡明確中止並給出可讀訊息，
        //    而不是讓 Schema Builder 拋出難懂的資料庫錯誤。
        $nullCount = DB::table('pinyin')
            ->where(function ($query) {
                $query->whereNull('lastname_chn')->orWhere('lastname_chn', '');
            })
            ->count();

        if ($nullCount > 0) {
            throw new RuntimeException("pinyin 表整併中止：偵測到 {$nullCount} 筆 lastname_chn 為 NULL/空字串的資料，請先手動清理後再執行 migration。");
        }

        $duplicates = DB::table('pinyin')
            ->select('lastname_chn')
            ->groupBy('lastname_chn')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('lastname_chn');

        if ($duplicates->isNotEmpty()) {
            $list = $duplicates->implode('、');

            throw new RuntimeException("pinyin 表整併中止：偵測到重複的 lastname_chn 資料（{$list}），請先手動清理後再執行 migration。");
        }

        // 2. 欄位重新命名（比照 CBDB c_ 前綴慣例）
        Schema::table('pinyin', function (Blueprint $table) {
            $table->renameColumn('lastname_chn', 'c_chn');
            $table->renameColumn('lastname_pinyin', 'c_pinyin');
        });

        // 3. c_chn 收斂為 NOT NULL（唯一鍵若允許 NULL，NULL 不參與唯一性比較，
        //    多筆 NULL 可以共存，等於唯一鍵形同虛設）。通過第 1 步的檢查後才安全。
        Schema::table('pinyin', function (Blueprint $table) {
            $table->string('c_chn', 10)->nullable(false)->change();
        });

        // 4. 新增 c_lastname 旗標欄位，預設 0
        //    （合併後的表裡絕大多數列會是一般字典資料，而非姓氏，default 0 更貼近實際分布）
        Schema::table('pinyin', function (Blueprint $table) {
            $table->tinyInteger('c_lastname')->nullable(false)->default(0)->after('c_pinyin');
        });

        // 5. 既有資料此刻全部是姓氏對照，一次性整表回填為 c_lastname=1
        //    （此時尚未匯入一般字典，不需要 WHERE 條件）
        DB::table('pinyin')->update(['c_lastname' => 1]);

        // 6. 建立複合唯一鍵（允許同一字有姓氏/一般兩種讀音並存）與查詢索引
        Schema::table('pinyin', function (Blueprint $table) {
            $table->unique(['c_chn', 'c_lastname'], 'pinyin_c_chn_c_lastname_unique');
            $table->index('c_chn', 'pinyin_c_chn_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        if (!Schema::hasTable('pinyin')) {
            return;
        }

        Schema::table('pinyin', function (Blueprint $table) {
            $table->dropUnique('pinyin_c_chn_c_lastname_unique');
            $table->dropIndex('pinyin_c_chn_index');
        });

        Schema::table('pinyin', function (Blueprint $table) {
            $table->dropColumn('c_lastname');
        });

        Schema::table('pinyin', function (Blueprint $table) {
            $table->string('c_chn', 10)->nullable()->change();
        });

        Schema::table('pinyin', function (Blueprint $table) {
            $table->renameColumn('c_chn', 'lastname_chn');
            $table->renameColumn('c_pinyin', 'lastname_pinyin');
        });
    }
};
