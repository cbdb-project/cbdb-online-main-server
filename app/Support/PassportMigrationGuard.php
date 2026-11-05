<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Passport `oauth_*` 遷移（database/migrations/2026_11_05_*）的共用保護。
 *
 * 背景：本專案曾裝過 Passport 又移除（2025_12_18_050122_remove_passport_oauth_tables 只 drop 其中幾張），
 * 所以部署環境可能殘留同名表。單純的 `hasTable() → return` 有兩個洞：
 *
 *  1. 殘留的是舊版／不完整的表時，迁移被當成「已套用」記錄下來，Passport 之後在執行期才因缺欄位出錯，
 *     而且迁移本身看起來成功了——要在部署當下就大聲失敗，不要悄悄跳過。
 *  2. `up()` 因表已存在而沒建任何東西，之後若 rollback，`down()` 的無條件 drop 會刪掉**不是這支遷移
 *     建立的**表與資料。
 *
 * 見 docs/OIDC_PROVIDER_PLAN.md §6.7。
 */
final class PassportMigrationGuard {
    /**
     * 要不要跳過建表。
     *
     * @param list<string> $requiredColumns Passport 目前版本需要的欄位
     *
     * @return bool true＝表已存在且欄位齊全（跳過建立）；false＝表不存在（照常建立）
     *
     * @throws RuntimeException 表已存在但缺欄位：不可悄悄標成已套用，必須人工處理
     */
    public static function shouldSkipCreate(string $table, array $requiredColumns): bool {
        if (!Schema::hasTable($table)) {
            return false;
        }

        $missing = array_values(array_filter(
            $requiredColumns,
            static fn (string $column): bool => !Schema::hasColumn($table, $column)
        ));

        if ($missing !== []) {
            throw new RuntimeException(
                "資料表 {$table} 已存在，但缺少 Passport 需要的欄位：" . implode(', ', $missing) . '。'
                . '這通常是舊版 Passport 殘留的表。請人工確認其資料後處理（備份後 drop，或補齊欄位），'
                . '再重跑 migrate；本遷移不會悄悄跳過，以免 Passport 在執行期才失敗。'
            );
        }

        return true;
    }

    /**
     * rollback：只在表是空的時候才刪。
     *
     * 表裡有資料時拒絕刪除——它可能是本遷移跳過建立的既有表（見類別說明第 2 點），也可能是尚未過期的
     * token／client 憑證。要連資料一起清掉，請先人工 truncate，讓這個動作是有意識的。
     *
     * @throws RuntimeException 表內仍有資料
     */
    public static function dropIfEmpty(string $table): void {
        if (!Schema::hasTable($table)) {
            return;
        }

        if (DB::table($table)->exists()) {
            throw new RuntimeException(
                "資料表 {$table} 仍有資料，rollback 拒絕刪除。若確定要丟棄（例如 token 可以重發），"
                . '請先人工 truncate 該表再重跑 rollback。'
            );
        }

        Schema::drop($table);
    }
}
