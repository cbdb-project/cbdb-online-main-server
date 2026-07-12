<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 為 operations 補上 (resource, resource_id, op_type) 複合索引。
 *
 * 背景：每一筆子資源 create 都會做「pending 提案」預檢（避免同主鍵重複提案），查詢形如
 *   SELECT * FROM operations
 *   WHERE resource = ? AND resource_id IN (?) AND op_type = ?
 * 呼叫點遍及所有 create 路徑：
 *   - AbstractPersonSubresourceCreateHandler（address/altname/entry/status/event/assoc/kinship/
 *     possession/text/social-institution 共用）
 *   - SourceMutationHandler（BIOG_SOURCE_DATA）、PostingCreateHandler（POSTED_TO_OFFICE_DATA）
 *
 * 但 operations 原本只有 PRIMARY(id) 與 KEY(c_personid)，resource / resource_id 上無索引，
 * 上述查詢在 MariaDB/MySQL 退化成**整張 operations 全表掃描**。operations 是操作日誌表，隨每一次
 * mutation（含歷史所有提案與 direct 寫入）持續增長，於是每寫一筆就掃全表一次；批次/並發寫入時
 * 大量並發全表掃描會飽和 DB CPU/IO、堆積慢查詢、推爆 php-fpm worker（與 /codes 深分頁那次生產
 * 癱瘓同一模式）。
 *
 * 索引把該預檢由全表掃描收斂為索引 seek。欄序：resource（等值）→ resource_id（等值/IN，最具選擇性，
 * 幾近唯一定位到該主鍵的提案列）→ op_type（尾欄過濾），同時服務 hasPending{Create,Update,Delete}Proposal
 * 三種變體與任何 (resource, resource_id) 查詢。
 *
 * 部署：operations 表大，ADD INDEX 於 MariaDB 10.3 預設為 ONLINE（INPLACE/LOCK=NONE）、不長時間鎖表，
 * 但仍需一段建置時間，建議於低峰執行。SQLite（測試）建索引為即時。
 */
return new class () extends Migration {
    private const INDEX_NAME = 'operations_resource_resource_id_op_type_index';

    public function up(): void {
        // operations 由 2025_01_01 schema import 必然先建立；若不存在屬異常狀態，fail-fast 而非靜默跳過。
        if ($this->indexExists()) {
            return;
        }

        Schema::table('operations', function (Blueprint $table) {
            $table->index(['resource', 'resource_id', 'op_type'], self::INDEX_NAME);
        });
    }

    public function down(): void {
        if (!$this->indexExists()) {
            return;
        }

        Schema::table('operations', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }

    /**
     * 幂等守衛：索引已存在則跳過（重跑 migrate、或環境已手動補過索引時皆安全）。
     */
    private function indexExists(): bool {
        try {
            return Schema::hasIndex('operations', self::INDEX_NAME);
        } catch (\Throwable $e) {
            // 舊版 Schema 無 hasIndex 時退回「不確定 → 讓 up/down 照跑」；重複建索引會由 DB 報錯，屬可見失敗。
            return false;
        }
    }
};
