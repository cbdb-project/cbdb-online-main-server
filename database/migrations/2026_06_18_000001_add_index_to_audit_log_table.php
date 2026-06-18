<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 為 audit_log 補上 (table_name, occurred_at, id) 複合索引。
 *
 * person_change_index 的重建命令以「逐 table_name + (occurred_at, id) keyset 分頁」掃描 audit_log
 * （WHERE table_name = ? [AND occurred_at >= ?] ORDER BY occurred_at, id）。原 audit_log 只有 PK，
 * 在 MariaDB/MySQL 上會退化成全表掃描 + filesort，與低資源/分批設計衝突。
 *
 * 索引含尾欄 id，使 table_name 等值 + occurred_at 範圍 + 「ORDER BY occurred_at, id」keyset
 * 能完全由索引滿足（避免額外 filesort/temp）。
 *
 * 注意：audit_log 沒有 c_personid 欄位（c_personid 在 row_pk/new_data 的 JSON 內），
 * 「每 person 的 MAX(occurred_at)」仍須掃描 + 解析 JSON，無法靠索引消除；
 * 本索引的作用是把掃描收斂到「相關表 + 時間窗」並支撐 keyset。詳見 docs/PERSON_CHANGE_INDEX_DESIGN.md。
 */
return new class () extends Migration {
    public function up(): void {
        // audit_log 由 2026_02_08 migration 必然先建立，這裡不做 hasTable 靜默跳過：
        // 若該表不存在屬於異常狀態，應 fail-fast 而非掩蓋。
        Schema::table('audit_log', function (Blueprint $table) {
            $table->index(['table_name', 'occurred_at', 'id'], 'audit_log_table_name_occurred_at_id_index');
        });
    }

    public function down(): void {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropIndex('audit_log_table_name_occurred_at_id_index');
        });
    }
};
