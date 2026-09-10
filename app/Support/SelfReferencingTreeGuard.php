<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 自參照層級樹的環路守衛（目前只有 `OFFICE_TYPE_TREE`）。
 *
 * **為什麼是 static support 而不是 handler 的方法**：同一張表有**六條**寫入路徑，
 * 分屬四個不同的類別體系——
 *   1. `/api/v2/create`（CodeTableCreateHandler）
 *   2. `/api/v2/mutate` direct（AbstractCodeTableMutationHandler）
 *   3. **提案核准**（OperationsProposalController 的通用 row-overwrite 路徑）
 *   4. **operations 還原**（OperationsController::restoreUpdate）
 *   5. **`/codes` UI 的新增與修改**（CodesController::performStore／performUpdate）
 *      ——這條其實是這張表的**主要**寫入者
 *   6. **眾包核准**（CrowdsourcingController），由仍在服役的 v1 token API 餵入
 * 只掛在 API 那兩條是不夠的，而且不夠的方式很具體：提案會躺好幾天，提交時合法的
 * 「把 06 掛到 0699 之下」在核准時可能已經因為別人把 0699 搬到 06 之下而變成環。
 * 兩條邊各自都滿足自參照外鍵，資料庫不會擋。還原歷史快照同理，而 UI 與眾包端根本
 * 沒經過 API 的守衛。
 * （這與 char_variant_map 在同一個核准路徑上另外驗結構是同一類問題、同一種解法。）
 *
 * **新增寫入路徑時要記得掛**：這條規則沒有機械化把關——沒有「掃全庫找出所有寫
 * OFFICE_TYPE_TREE 的地方」的測試，只有 code review 擋得住。
 *
 * **並發（TOCTOU）**：守衛本質上是「檢查後才寫」。兩個同時進行的 `A.parent=B` 與
 * `B.parent=A` 若各自都看到對方還掛在根上，就會兩邊都通過、兩邊都提交、環成立。
 * v2 的 create 與 update 因此在**寫入交易之內**再驗一次，並以 `$lockRows = true`
 * 鎖住走訪路徑上的每一列（`SELECT … FOR UPDATE`），把這個窗口關掉。
 * 其餘四條路徑（`/codes` UI、眾包核准、提案核准、operations 還原）**只有無鎖的
 * 單次檢查**：它們都是單一管理者的互動操作，兩人同時重掛同一段樹的機率極低，而把
 * 那幾條路徑改成交易化會動到與本功能無關的錯誤處理與 redirect 流程。這是已知的
 * 殘留風險，不是遺漏。
 *
 * 為什麼資料庫擋不住：`c_parent_id` 有自參照外鍵，所以「父節點必須存在」由 DB 保證
 * （違反是 1452），但**自己當自己的父節點**滿足外鍵，A→B 與 B→A 也各自滿足。
 *
 * 成環的後果：任何「沿 parent 往上找根」的走訪都會無窮迴圈。本 repo 目前沒有程式讀
 * `c_parent_id`（站內的階層查詢是對 **id 字串**做 `LIKE '<id>%'` 前綴比對），所以這是
 * **保護資料本身與外部消費者**的守衛，不是修某個現有頁面的 bug——別因為「站內沒人讀」
 * 就拿掉它：這張表是對外釋出資料的一部分，成環的樹沒有任何消費者能安全走訪。
 */
final class SelfReferencingTreeGuard {
    /** 走訪步數上限。資料庫裡可能已經有歷史成環的資料，無上限的話守衛自己會無窮迴圈。 */
    private const MAX_DEPTH = 200;

    /**
     * 該表的「上層」欄位名（來自兩份代碼表 config 的 `tree_parent_column`）；沒登錄回 null。
     *
     * 兩份都查是因為 create 讀 `code_table_writes`、update 讀 `code_table_mutations`，
     * 而核准與還原這兩條路徑不屬於任何一份——以表名查、兩份都認。
     */
    public static function parentColumnFor(string $table): ?string {
        foreach ((array) config('code_table_writes.tables', []) as $definition) {
            if (($definition['table'] ?? null) === $table && !empty($definition['tree_parent_column'])) {
                return (string) $definition['tree_parent_column'];
            }
        }

        foreach ((array) config('code_table_mutations.tables', []) as $definition) {
            if (($definition['table'] ?? null) === $table && !empty($definition['tree_parent_column'])) {
                return (string) $definition['tree_parent_column'];
            }
        }

        return null;
    }

    /**
     * 把 `$nodeId` 掛到 `$newParentId` 之下是否會成環。
     *
     * **根節點以「自己是自己的上層」表示**，這是 CBDB 這張表的既有慣例
     * （`OFFICE_TYPE_TREE` 的 `'0'` 的 `c_parent_id` 就是 `'0'`；全庫 2739 列只有這一筆
     * 自我引用）。走訪碰到 `parent(x) === x` 是**走到根了**、不是環——把它當環會讓每一次
     * 合法操作都被擋下，因為凡是祖先鏈通到根的節點都會誤報，也就是全部。
     *
     * 鍵值的相等判定交給資料庫（見 {@see keysAreEqual()}）：這張表的 collation 是
     * utf8mb4_general_ci 且 PAD SPACE，所以 `'06 ' = '06'`、`'AB' = 'ab'`、甚至
     * `'À' = 'a'` 在 DB 端都成立（已實測）。用 PHP `===` 比原值，送 `'06 '` 或 `'a'`
     * 會被判成「不是自己」而放行，落庫後那一列在資料庫眼中就是自己的父節點。
     *
     * @return string|null 錯誤訊息；null＝沒有環
     */
    public static function findCycle(string $table, string $keyColumn, string $parentColumn, mixed $nodeId, mixed $newParentId, bool $lockRows = false): ?string {
        if ($newParentId === null || $newParentId === '') {
            return null;
        }

        // 注意這是不對稱的、而且是刻意的：走訪**認得**「自己為上層」是根的表示法
        // （見上），但**寫入**它一律拒絕。也就是說這條路徑無法建立第二個根、也無法把
        // 一個既有節點改成根。現庫只有一個根（`'0'`），而「把某個節點變成根」不是
        // 這個 API 想開放的操作——真要動請人工處理。
        if (self::keysAreEqual($table, $keyColumn, $newParentId, $nodeId)) {
            return '節點不可以自己為上層（' . $parentColumn . ' 不可等於 ' . $keyColumn . '）';
        }

        $seen = [];
        $current = $newParentId;
        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            if ($current === null || $current === '') {
                return null;
            }

            if (self::keysAreEqual($table, $keyColumn, $current, $nodeId)) {
                return '這樣會讓層級樹成環（' . $newParentId . ' 在 ' . $nodeId . ' 的下層）';
            }

            $seenKey = self::padSpaceCasefold($current);
            if (isset($seen[$seenKey])) {
                // 走到一個**既有的**環（與本次修改無關）。仍然拒絕：在成環的分支上再掛
                // 東西只會讓問題更難修，而且守衛無法保證這次修改是安全的。
                return '上層節點所在的分支已經成環（' . $current . '），請先修好既有資料';
            }
            $seen[$seenKey] = true;

            $query = DB::table($table)->where($keyColumn, $current);
            if ($lockRows) {
                // 交易內走訪時鎖住路徑上的每一列，把「檢查後才寫」的 TOCTOU 收斂掉：
                // 兩個同時進行的 `A.parent=B` 與 `B.parent=A` 若不鎖，各自都會看到對方
                // 還掛在根上而通過，然後兩邊都提交、環成立。
                $query->lockForUpdate();
            }
            $parent = $query->value($parentColumn);

            // parent(x) === x ⇒ 走到根了（見上）。
            if ($parent !== null && self::keysAreEqual($table, $keyColumn, $parent, $current)) {
                return null;
            }

            $current = $parent;
        }

        return '層級過深或已成環，無法確認（超過 ' . self::MAX_DEPTH . ' 層）';
    }

    /**
     * 兩個鍵值在**資料庫的 collation 之下**是否相等。
     *
     * 為什麼不用 PHP 比：這張表的 collation 是 `utf8mb4_general_ci`（不分大小寫、
     * PAD SPACE，某些字母的重音形也視為相等）。用 PHP 比原值或只做 trim + casefold，
     * 都可能判成「不是同一個鍵」而放行，然後自參照外鍵把它解析到同一列——那一列就成了
     * 自己的父節點。既然判定的後果由資料庫決定，判定就交給資料庫。
     *
     * 做法是**把兩個值直接在 SQL 裡用該欄的 collation 比一次**
     * （`SELECT (? COLLATE <coll>) = (? COLLATE <coll>)`）。
     *
     * 為什麼不是「拿欄位問兩次、看有沒有列同時滿足」——那個寫法漏掉 create：新節點的列
     * 還不存在，於是「找不到列」被當成「兩個值不同」而放行，然後 InnoDB 的自參照外鍵在
     * 插入後以 case/accent-insensitive 的比對把父鍵解析到**剛插進去的那一列**，環就成了。
     * 已實測 `'À' = 'a'` 在 utf8mb4_general_ci 之下為真。
     *
     * PHP 短路只用來省查詢，而且**只敢往「資料庫也一定認為相等」的方向短路**：
     * PAD SPACE 只忽略**尾端**空格，所以只能 rtrim、不能 trim——`' 06'` 與 `'06'` 在
     * 資料庫眼中是不同的鍵，用 trim 短路會把一個合法的寫入誤判成自我引用而擋掉。
     * casefold 用 mb_strtolower：它比 general_ci 保守（general_ci 連重音形都折疊），
     * 所以只會漏掉相等、不會多判相等——漏掉的那些會落到 SQL 比對。
     *
     * 拿不到 collation 時（SQLite，或欄位讀不到）回退成 PHP 的精確字串比對：SQLite 的
     * 預設比對是 BINARY，與 PHP `===` 同義，所以那條回退在測試環境是精確的、不是猜的。
     */
    private static function keysAreEqual(string $table, string $keyColumn, mixed $a, mixed $b): bool {
        if ($a === null || $b === null) {
            return false;
        }

        // 便宜的 PHP 短路（只往安全方向）：絕大多數呼叫在這裡就有答案。
        if (self::padSpaceCasefold($a) === self::padSpaceCasefold($b)) {
            return true;
        }

        // PHP 判成不同，但資料庫可能判成相同（重音折疊等）——只有這種情況要問資料庫。
        $collation = self::collationFor($table, $keyColumn);
        if ($collation === null) {
            return (string) $a === (string) $b;
        }

        $row = DB::selectOne(
            'select ((? collate ' . $collation . ') = (? collate ' . $collation . ')) as eq',
            [(string) $a, (string) $b]
        );

        return (bool) ($row->eq ?? false);
    }

    /**
     * 該欄的 collation 名稱；拿不到（SQLite／讀不到欄位）回 null。
     *
     * 名稱要內插進 SQL（`COLLATE` 不吃 bound parameter），所以**必須**白名單化字元集。
     * 來源是 `Schema::getColumns()`（資料庫自己回報的），不是使用者輸入，但這條路徑會
     * 被內插進查詢，所以還是逐字驗一次——防的是「哪天有人把表名／欄名接進來」。
     */
    private static function collationFor(string $table, string $column): ?string {
        try {
            foreach (Schema::getColumns($table) as $definition) {
                if (strtolower((string) $definition['name']) !== strtolower($column)) {
                    continue;
                }

                $collation = (string) ($definition['collation'] ?? '');

                return preg_match('/\A[A-Za-z0-9_]+\z/', $collation) === 1 ? $collation : null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * 比較與去重用的歸一化：**rtrim（僅尾端空格，對齊 PAD SPACE）** + casefold。
     * 刻意不做 ltrim——見 keysAreEqual() 的說明。
     */
    private static function padSpaceCasefold(mixed $value): string {
        return mb_strtolower(rtrim((string) $value, ' '));
    }

    /**
     * 核准／還原這類「整列覆寫」路徑的便利入口：從 payload 與既有列推導出參數。
     *
     * @param array<string,mixed> $payload 要寫入的內容（含或不含上層欄位）
     * @param array<string,mixed> $original 既有列（提供節點主鍵與現值）
     * @param array<int,string> $keyColumns
     * @param bool $lockRows 交易內呼叫時設 true（鎖住走訪路徑，收斂 TOCTOU）
     * @return string|null 錯誤訊息；null＝沒有環或不需要檢查
     */
    public static function findCycleForRowWrite(string $table, array $keyColumns, array $payload, array $original, bool $lockRows = false): ?string {
        $parentColumn = self::parentColumnFor($table);
        if ($parentColumn === null || count($keyColumns) !== 1) {
            return null;
        }

        if (!array_key_exists($parentColumn, $payload)) {
            return null;
        }

        $keyColumn = $keyColumns[0];
        $nodeId = $payload[$keyColumn] ?? ($original[$keyColumn] ?? null);
        if ($nodeId === null) {
            return null;
        }

        // 上層沒有真的改變時不驗：根節點的上層本來就等於自己（那是「根」的表示法），
        // 把整列原樣寫回（核准一個只改說明的提案、還原一個舊快照）不該被守衛擋下。
        if (array_key_exists($parentColumn, $original)
            && (string) $payload[$parentColumn] === (string) $original[$parentColumn]
        ) {
            return null;
        }

        return self::findCycle($table, $keyColumn, $parentColumn, $nodeId, $payload[$parentColumn], $lockRows);
    }
}
