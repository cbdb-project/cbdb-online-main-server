<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * person_change_index：人物層級「建檔／最後修改」水位線（sidecar 索引表）。
 *
 * 設計見 docs/PERSON_CHANGE_INDEX_DESIGN.md。
 * - 與 BIOG_MAIN 既有的 c_modified_date 語意分離（本表是聚合層級 last-touched，BIOG_MAIN 欄位是本列修改）。
 * - 只建表、不回填；部署後須手動執行 `php artisan cbdb:rebuild-person-change-index` 做初始全量回填。
 * - 表名全小寫，與 app 層級表（audit_log、operations）一致。
 */
return new class () extends Migration {
    public function up(): void {
        // 刻意不做 hasTable 靜默跳過：這是 create migration，若該表已存在（schema drift）
        // 應明確失敗，而非無聲略過導致欄位／索引與設計不符卻顯示成功。
        Schema::create('person_change_index', function (Blueprint $table) {
            column_comment($table->integer('c_personid'), '對應 BIOG_MAIN.c_personid')->primary();
            column_comment($table->dateTime('c_last_modified_date')->nullable(), '人物層級 last-touched 水位線（跨本體與所有子資源）')->index();
            column_comment($table->dateTime('c_created_date')->nullable(), '鏡像 BIOG_MAIN.c_created_date，方便排序／同步');
            column_comment($table->dateTime('updated_at')->nullable(), '本投影列自身的維護時間（除錯用）');
        });
    }

    public function down(): void {
        Schema::dropIfExists('person_change_index');
    }
};
