<?php

namespace App\Services\Mutations\Concerns;

use App\Support\SelfReferencingTreeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 代碼／查找表寫入路徑（create／update／delete）的共用機制。
 *
 * 抽出來的三件事之所以必須共用，都是因為「同一張表、同一個欄位在 create 與 update
 * 表現不一致」本身就是本專案反覆踩到的 bug 類型：
 *
 * 1. `whereByPk()`：三個 handler 各抄一份，複合主鍵登錄一改就有人漏跟。
 * 2. `columnListing()`：稽核欄蓋章要先看表有沒有那個欄位；沒有快取時 batch_mutate
 *    每列都會多打一次 information_schema，而且發生在寫入交易之內。
 * 3. 資料庫例外分類：SQLite 把 UNIQUE／FOREIGN KEY／NOT NULL／CHECK 全塞在 errno 19，
 *    只能靠訊息分辨。分不出來的代價不只是訊息難看——把 NOT NULL 違反報成
 *    409「並發衝突，請重試」會誘導呼叫端進入一個永遠不會成功的重試迴圈；
 *    而外鍵違反若不轉 422，使用者會拿到 500（MariaDB 1452 不在 [1062, 19] 裡，
 *    訊息也不含 UNIQUE，於是一路冒到 controller）。
 */
trait HandlesCodeTableWrites {
    /**
     * 欄位清單快取：表名 => 小寫欄位名清單。
     *
     * @var array<string,array<int,string>>
     */
    private array $codeTableColumnCache = [];

    /** 把主鍵條件套到 query builder。 */
    protected function whereByPk(\Illuminate\Database\Query\Builder $query, array $pk): \Illuminate\Database\Query\Builder {
        foreach ($pk as $col => $value) {
            $query->where($col, $value);
        }

        return $query;
    }

    /**
     * 整數欄位的值域快取：表名 => [小寫欄位名 => [min, max]]。
     *
     * @var array<string,array<string,array{0:int,1:int}>>
     */
    private array $codeTableIntegerRangeCache = [];

    /**
     * 各整數型別的有號值域。無號欄另行處理（下限改 0、上限乘 2 加 1）。
     *
     * **刻意不含 bigint**：它的值域與 PHP int 同級，用 int 比較無法可靠判斷邊界
     * （`bigint unsigned` 的上限根本超出 PHP_INT_MAX，硬套會誤擋合法值）。
     * bigint 欄不做值域檢查，改由 CodeTableFieldValidator 的「整數溢位」檢查
     * （字串往返比對）擋掉超出 PHP int 精度的輸入。
     */
    private const INTEGER_TYPE_RANGES = [
        'tinyint' => [-128, 127],
        'smallint' => [-32768, 32767],
        'mediumint' => [-8388608, 8388607],
        'int' => [-2147483648, 2147483647],
        'integer' => [-2147483648, 2147483647],
    ];

    /**
     * 從**實際 schema** 推導整數欄的值域，供落庫前擋掉超範圍的值。
     *
     * 為什麼不能只驗語法：`c_firstyear` 是 smallint，送 `40000` 在語法上是合法整數，
     * 但本專案 `config/database.php` 設 `strict => false`，MariaDB 會**靜默截斷成 32767**
     * ——回 200、資料錯了、而且是個看起來很正常的年份。截斷是 warning 不是 exception，
     * 所以 isValueRangeOrConversionViolation() 的兜底在非 strict 部署上根本走不到。
     *
     * 值域從 schema 讀而不是寫進 config：多一份手抄清單就多一個漂移來源，
     * 而型別本來就是資料庫說了算。查不到型別的欄位不設限（fail-open——這裡的目的
     * 是擋明確的錯誤，不是替 schema 把關；schema 一致性由 drift 測試負責）。
     *
     * @return array<string,array{0:int,1:int}> 小寫欄位名 => [min, max]
     */
    protected function integerRanges(string $table): array {
        // 快取鍵要帶連線名：同一個表名在不同連線上是不同的 schema（測試會在
        // setUp 裡切到 sqlite :memory:）。只用表名當鍵的話，若 handler 實例活過
        // 一次 DB::purge()／預設連線切換，就會拿舊 schema 的值域去驗新連線的資料。
        $cacheKey = DB::getDefaultConnection() . '|' . $table;
        if (array_key_exists($cacheKey, $this->codeTableIntegerRangeCache)) {
            return $this->codeTableIntegerRangeCache[$cacheKey];
        }

        $ranges = [];

        try {
            foreach (Schema::getColumns($table) as $column) {
                $typeName = strtolower((string) ($column['type_name'] ?? ''));
                if (!isset(self::INTEGER_TYPE_RANGES[$typeName])) {
                    continue;
                }

                [$min, $max] = self::INTEGER_TYPE_RANGES[$typeName];
                // 無號欄：下限 0、上限 2 * max + 1。
                if (str_contains(strtolower((string) ($column['type'] ?? '')), 'unsigned')) {
                    $min = 0;
                    $max = $max * 2 + 1;
                }

                $ranges[strtolower((string) $column['name'])] = [$min, $max];
            }
        } catch (\Throwable $e) {
            $ranges = [];
        }

        return $this->codeTableIntegerRangeCache[$cacheKey] = $ranges;
    }

    /**
     * 欄位型別快取：連線|表名 => [小寫欄位名 => ['type_name' => …, 'max_length' => int|null]]。
     *
     * @var array<string,array<string,array{type_name:string,max_length:int|null}>>
     */
    private array $codeTableColumnTypeCache = [];

    /** 文本型別（可當文本主鍵）。 */
    private const TEXT_TYPE_NAMES = ['varchar', 'char', 'tinytext', 'text', 'mediumtext', 'longtext'];

    /**
     * 欄位型別（含 varchar 長度）。用來判斷主鍵是數值還是文本——本檔原本一律 `(int)`
     * 轉型，對 `OFFICE_TYPE_TREE.c_office_type_node_id` 這種**零填補的階層路徑字串**
     * （`06`、`060102`）會把 `'06'` 轉成 `6`，直接建出一筆錯鍵的節點。
     *
     * @return array<string,array{type_name:string,max_length:int|null}>
     */
    protected function columnTypes(string $table): array {
        $cacheKey = DB::getDefaultConnection() . '|' . $table;
        if (array_key_exists($cacheKey, $this->codeTableColumnTypeCache)) {
            return $this->codeTableColumnTypeCache[$cacheKey];
        }

        $types = [];

        try {
            foreach (Schema::getColumns($table) as $column) {
                $length = null;
                if (preg_match('/\((\d+)\)/', (string) ($column['type'] ?? ''), $m) === 1) {
                    $length = (int) $m[1];
                }

                $types[strtolower((string) $column['name'])] = [
                    'type_name' => strtolower((string) ($column['type_name'] ?? '')),
                    'max_length' => $length,
                ];
            }
        } catch (\Throwable $e) {
            $types = [];
        }

        return $this->codeTableColumnTypeCache[$cacheKey] = $types;
    }

    /**
     * 該欄是否為文本型別（據實際 schema）。
     *
     * 查不到型別時回 false（＝當成數值處理）。**這個方向對文本主鍵是危險的**——
     * 「當成數值」正是本機制要防的那個損壞。所以呼叫端必須先用
     * {@see schemaUnavailable()} 擋掉「整張表的型別都讀不到」的情況，
     * 不要把這個 false 當成「已確認是數值欄」。
     */
    protected function isTextColumn(string $table, string $column): bool {
        $type = $this->columnTypes($table)[strtolower($column)]['type_name'] ?? null;

        return $type !== null && in_array($type, self::TEXT_TYPE_NAMES, true);
    }

    /**
     * 整張表的欄位型別都讀不到（連線／權限異常，或表不存在）。
     *
     * 寫入路徑要 fail-closed 地擋下來：型別讀不到時 isTextColumn() 一律回 false，
     * 文本主鍵會被 (int) 轉型建到錯鍵上，而 D7 與 auto_assign 兩個守衛也都因為
     * 掛在 isTextColumn() 之下而一起失效——三個保護同時消失，且完全無聲。
     */
    protected function schemaUnavailable(string $table): bool {
        return $this->columnTypes($table) === [];
    }

    /**
     * 自參照樹的環路守衛。實作在 {@see \App\Support\SelfReferencingTreeGuard}——
     * 同一張表有四條寫入路徑（create／direct update／提案核准／operations 還原），
     * 後兩條不屬於 handler 體系，所以判定必須放在共用的 support 而不是這個 trait。
     */
    protected function findTreeCycle(string $table, string $keyColumn, string $parentColumn, mixed $nodeId, mixed $newParentId): ?string {
        return SelfReferencingTreeGuard::findCycle($table, $keyColumn, $parentColumn, $nodeId, $newParentId);
    }

    /** @return array<int,string> 小寫欄位名；查不到時回空陣列（呼叫端須自行決定 fail-open／closed）。 */
    protected function columnListing(string $table): array {
        // 快取鍵帶連線名，理由同 integerRanges()。
        $cacheKey = DB::getDefaultConnection() . '|' . $table;
        if (!array_key_exists($cacheKey, $this->codeTableColumnCache)) {
            try {
                $this->codeTableColumnCache[$cacheKey] = array_map('strtolower', Schema::getColumnListing($table));
            } catch (\Throwable $e) {
                $this->codeTableColumnCache[$cacheKey] = [];
            }
        }

        return $this->codeTableColumnCache[$cacheKey];
    }

    /**
     * 外鍵違反：MariaDB/MySQL 1452（新增／更新子列找不到父列）與 1451（父列仍被引用）。
     * SQLite 兩種違反共用 errno 19，只能靠訊息判定。
     */
    protected function isForeignKeyViolation(\Illuminate\Database\QueryException $e): bool {
        if (in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1452], true)) {
            return true;
        }

        $msg = $e->getMessage();

        return str_contains($msg, 'FOREIGN KEY constraint failed')
            || str_contains($msg, 'a foreign key constraint fails');
    }

    /** NOT NULL／CHECK 違反（MariaDB 1048／1364／4025；SQLite 同樣是 errno 19）。 */
    protected function isNotNullOrCheckViolation(\Illuminate\Database\QueryException $e): bool {
        if (in_array((int) ($e->errorInfo[1] ?? 0), [1048, 1364, 4025, 3819], true)) {
            return true;
        }

        $msg = $e->getMessage();

        return str_contains($msg, 'NOT NULL constraint failed')
            || str_contains($msg, 'CHECK constraint failed')
            || str_contains($msg, 'cannot be null');
    }

    /**
     * 值轉換／超出範圍／過長：MariaDB 1264（out of range）、1366（incorrect value）、
     * 1406（data too long）、1265（data truncated）。
     *
     * 為什麼需要：本專案的 `config/database.php` 設 `strict => false`，本機與多數部署
     * 會把壞值靜默轉成 0／截斷；但 sql_mode 是部署層可改的，strict 的環境會拋這幾個
     * 錯誤。欄位級校驗已擋掉絕大多數（非數值字串、超長字串），這裡是兜底——同樣是
     * 呼叫端的輸入問題，不該以 500 呈現。
     */
    protected function isValueRangeOrConversionViolation(\Illuminate\Database\QueryException $e): bool {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1264, 1265, 1366, 1406], true);
    }

    /**
     * 鎖競爭：MariaDB 1213（deadlock found）與 1205（lock wait timeout）。
     *
     * 為什麼需要單獨分類：自參照樹的守衛在交易內用 `SELECT … FOR UPDATE` 走訪祖先鏈，
     * 而兩個方向相反的重掛（`A.parent=B` 與 `B.parent=A`）會以不同順序取鎖，資料庫因此
     * 可能挑一方回滾。交易回滾是**正確**的（不會寫出環），但那一方若得到 500 就太難看了
     * ——它是可以直接重試的暫時性衝突，該回 409。
     */
    protected function isLockContention(\Illuminate\Database\QueryException $e): bool {
        if (in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
            return true;
        }

        $msg = $e->getMessage();

        return str_contains($msg, 'Deadlock found')
            || str_contains($msg, 'Lock wait timeout exceeded')
            || str_contains($msg, 'database is locked');
    }

    /** 唯一鍵違反。必須排在另外兩者之後判定（errno 19 共用）。 */
    protected function isUniqueConstraintViolation(\Illuminate\Database\QueryException $e): bool {
        if ($this->isForeignKeyViolation($e) || $this->isNotNullOrCheckViolation($e) || $this->isLockContention($e)) {
            return false;
        }

        if (in_array((int) ($e->errorInfo[1] ?? 0), [1062, 19], true)) {
            return true;
        }

        $msg = $e->getMessage();

        return str_contains($msg, 'UNIQUE') || str_contains($msg, 'Duplicate entry');
    }
}
