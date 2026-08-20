<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 建立 `KINREL_REDUCTION`（親屬關係化簡規則表）。
 *
 * 用途：把複合親屬關係字串（`KINSHIP_CODES.c_kinrel`，例如 `BB`＝兄弟之兄弟）逐步化簡到
 * 等價的最簡關係（`B`），並同步調整四個親屬距離步數（up／down／collateral／marriage）。
 * 這是 `KINSHIP_CODES.c_kinrel_simplified` 的規則來源，資料由 CBDB 團隊以試算表維護。
 *
 * 設計取捨：
 * - **表名與欄名沿用 CBDB 慣例**（全大寫表名、`c_` 前綴欄名、MySQL 端 utf8mb4_general_ci），
 *   與 `KINSHIP_CODES`／`KIN_MOURNING`／`KIN_MOURNING_STEPS` 同族。
 * - **主鍵為 (`c_kinrel_target`, `c_sex`)**：同一個待化簡關係在不同 ego 性別下可有不同替換結果，
 *   `c_sex` 因此參與主鍵；現行資料全為 `B`（不分性別），但規則表本身要能承載 M／F 分歧。
 * - **四個步數欄為有號 smallint**（`smallInteger()`）：化簡通常是「減少」步數，現行資料
 *   `c_col_change = -1`，必須容納負值；型別與 `KINSHIP_CODES.c_upstep` 等一致。
 * - **不加稽核欄／timestamps**：與同族的 `KIN_MOURNING`／`KIN_MOURNING_STEPS` 一致；
 *   經 `/codes` 介面的寫入仍會留 operations + audit_log 紀錄。
 * - **不建額外索引**：主鍵前綴已覆蓋 `c_kinrel_target` 查詢，規則表列數為數十列級別。
 */
return new class () extends Migration {
    /**
     * 欄位與主鍵定義單獨抽成方法，讓測試能用 **MySQL 的 schema grammar** 編譯這同一份定義
     * （不必在測試裡複製一份欄位宣告，也不必連上真的 MariaDB）。
     *
     * 這件事有實質意義：SQLite 的 grammar 沒有 `Unsigned` modifier，也不吃 COMMENT／COLLATE，
     * 所以「欄位被寫成 unsigned」「collation 沒生效」這類缺陷在 SQLite 測試上是完全隱形的。
     */
    public function defineTable(Blueprint $table): void {
        if (is_mysql()) {
            // CBDB 既有表一律 utf8mb4_general_ci（見 2025_11_20_100000_alter_admin_cat_tables_collation），
            // 與 config/database.php 的 utf8mb4_unicode_ci 預設不同，這裡顯式對齊 CBDB 慣例。
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
        }

        column_comment($table->string('c_kinrel_target', 255), '待化簡的親屬關係字串（KINSHIP_CODES.c_kinrel 形式），例如 BB＝兄弟之兄弟');
        column_comment($table->string('c_sex', 1), '規則適用的 ego 性別：M＝男、F＝女、B＝不分性別');
        column_comment($table->string('c_kinrel_replacement', 255), '化簡後的親屬關係字串，例如 BB→B');
        column_comment($table->smallInteger('c_up_change')->default(0), '化簡後上行步數（upstep）的增減量，可為負');
        column_comment($table->smallInteger('c_down_change')->default(0), '化簡後下行步數（dwnstep）的增減量，可為負');
        column_comment($table->smallInteger('c_col_change')->default(0), '化簡後旁系步數（colstep）的增減量，可為負');
        column_comment($table->smallInteger('c_mar_change')->default(0), '化簡後婚姻步數（marstep）的增減量，可為負');
        column_comment($table->string('c_notes', 255)->nullable(), '備註');
        column_comment($table->tinyInteger('c_required')->default(0), '是否為必須套用的化簡規則：1＝必須、0＝選用');
        column_comment($table->tinyInteger('c_check_ego')->default(0), '套用前是否需檢查 ego 本人的性別：1＝需要、0＝不需要');

        $table->primary(['c_kinrel_target', 'c_sex']);
    }

    /**
     * MySQL 的 DDL 不進交易，而這個 up() 是「CREATE ＋ ALTER ＋ INSERT」三段：若後兩段中途失敗，
     * 表會留下來但 `migrations` 那一列沒寫進去，之後每次 `migrate` 都會撞
     * 「Table 'KINREL_REDUCTION' already exists」。**這是刻意保留的行為**：處置方式是
     * `DROP TABLE KINREL_REDUCTION;` 再重跑 migrate。
     *
     * 曾經在這裡加過 `if (Schema::hasTable(...)) return;` 的守衛，review 後移除——它把「大聲失敗」
     * 換成了更糟的「靜默成功」：表若已存在（例如 DBA 先照試算表建了一張 schema 不同的表），
     * 這個 migration 會被記成已套用，卻既沒驗證 schema 也沒寫入那 8 筆種子；而 `down()` 仍然會
     * 去 DROP 那張不是它建的表。半套用的 migration 應該要有人處理，不該被吞掉。
     */
    public function up(): void {
        Schema::create('KINREL_REDUCTION', fn (Blueprint $table) => $this->defineTable($table));

        if (is_mysql()) {
            // 純粹是為了讓 mysqldump 的輸出與同族 CBDB 表長得一樣；**不是**主鍵長度的前提條件。
            // `config/database.php` 的 `engine` 是 null（＝用伺服器預設 InnoDB），MariaDB 10.11 的
            // innodb_default_row_format 本來就是 dynamic，所以上面的 CREATE TABLE 早就是這個組合了。
            // （真要靠這行來放寬 767 bytes 的 key 長度限制也來不及：CREATE 會先以 errno 1071 失敗。）
            DB::statement('ALTER TABLE `KINREL_REDUCTION` ENGINE=InnoDB ROW_FORMAT=DYNAMIC');
        }

        // 初始規則（8 筆），內容與 CBDB 團隊提供的 KINREL_REDUCTION.xlsx 逐格一致。
        // 語義：同輩／子輩的旁系關係疊加可消去一層旁系（c_col_change = -1）。
        DB::table('KINREL_REDUCTION')->insert([
            ['c_kinrel_target' => 'BB', 'c_sex' => 'B', 'c_kinrel_replacement' => 'B', 'c_up_change' => 0, 'c_down_change' => 0, 'c_col_change' => -1, 'c_mar_change' => 0, 'c_notes' => null, 'c_required' => 1, 'c_check_ego' => 0],
            ['c_kinrel_target' => 'BZ', 'c_sex' => 'B', 'c_kinrel_replacement' => 'Z', 'c_up_change' => 0, 'c_down_change' => 0, 'c_col_change' => -1, 'c_mar_change' => 0, 'c_notes' => null, 'c_required' => 1, 'c_check_ego' => 0],
            ['c_kinrel_target' => 'DB', 'c_sex' => 'B', 'c_kinrel_replacement' => 'S', 'c_up_change' => 0, 'c_down_change' => 0, 'c_col_change' => -1, 'c_mar_change' => 0, 'c_notes' => null, 'c_required' => 1, 'c_check_ego' => 0],
            ['c_kinrel_target' => 'DZ', 'c_sex' => 'B', 'c_kinrel_replacement' => 'D', 'c_up_change' => 0, 'c_down_change' => 0, 'c_col_change' => -1, 'c_mar_change' => 0, 'c_notes' => null, 'c_required' => 1, 'c_check_ego' => 0],
            ['c_kinrel_target' => 'SB', 'c_sex' => 'B', 'c_kinrel_replacement' => 'S', 'c_up_change' => 0, 'c_down_change' => 0, 'c_col_change' => -1, 'c_mar_change' => 0, 'c_notes' => null, 'c_required' => 1, 'c_check_ego' => 0],
            ['c_kinrel_target' => 'SZ', 'c_sex' => 'B', 'c_kinrel_replacement' => 'D', 'c_up_change' => 0, 'c_down_change' => 0, 'c_col_change' => -1, 'c_mar_change' => 0, 'c_notes' => null, 'c_required' => 1, 'c_check_ego' => 0],
            ['c_kinrel_target' => 'ZB', 'c_sex' => 'B', 'c_kinrel_replacement' => 'B', 'c_up_change' => 0, 'c_down_change' => 0, 'c_col_change' => -1, 'c_mar_change' => 0, 'c_notes' => null, 'c_required' => 1, 'c_check_ego' => 0],
            ['c_kinrel_target' => 'ZZ', 'c_sex' => 'B', 'c_kinrel_replacement' => 'Z', 'c_up_change' => 0, 'c_down_change' => 0, 'c_col_change' => -1, 'c_mar_change' => 0, 'c_notes' => null, 'c_required' => 1, 'c_check_ego' => 0],
        ]);
    }

    public function down(): void {
        Schema::dropIfExists('KINREL_REDUCTION');
    }
};
