<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * config/entity_aggregates.php 的唯一查詢介面：封寫判定 + 封寫表的編輯連結解析。
 *
 * 為什麼把這兩件事放在同一個類：#1174／ca601b0d 把 OFFICE_CODES、SOCIAL_INSTITUTION_*
 * 這些下層表在泛用 /codes 介面封寫（寫入入口收歸實體聚合頁），但只改了守衛、沒改任何
 * 連結出口——/app/operations 的「查閱」仍寫死指向 /app/codes/{table}/{id}/edit，
 * 使用者點進去只會吃到「該代碼表為只讀，禁止編輯。」再被彈回列表。**封寫與連結解析
 * 分兩處推導，就是那個 bug 的成因**；分開再修一次只會把病灶換個位置重種。
 *
 * 因此 CodesController::isReadOnlyTable() 也委派到這裡（見該方法），日後任何新的封寫
 * 只要登記進 closed_code_tables，守衛與連結就同步生效。
 *
 * 範圍僅限**有實體頁可去**的表。沒有替代入口的唯讀表（DYNASTIES、GANZHI_CODES、
 * CBDB__NAME_FTS）不歸本類管，仍由 CodesController 自己的清單處理、行為不變。
 *
 * 設計參考 docs/ENTITY_AGGREGATE_ARCHITECTURE.md §4.4／§6.5。
 */
class EntityAggregateRegistry {
    /**
     * 註冊表內容（防禦性讀取）。
     *
     * config() 的預設值只在 key **不存在**時生效；key 存在但被設成 null／字串時仍會原樣
     * 回傳，直接 foreach 會拋 ErrorException 把整頁打成 500。這裡一律收斂成陣列。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function entities(): array {
        $entities = config('entity_aggregates.entities');
        if (!is_array($entities)) {
            return [];
        }

        return array_values(array_filter($entities, 'is_array'));
    }

    /**
     * 取得「認領並封寫」該表的實體聚合設定；不是被封寫的下層表則回 null。
     *
     * @return array<string, mixed>|null config/entity_aggregates.php 的單一實體項
     */
    public static function entityForClosedTable(string $table): ?array {
        $upper = strtoupper($table);

        foreach (self::entities() as $entity) {
            if (in_array($upper, self::closedTablesOf($entity), true)) {
                return $entity;
            }
        }

        return null;
    }

    /** 該表是否已被某個實體聚合封寫（＝泛用 codes 編輯頁對它是死路）。 */
    public static function isClosedByEntity(string $table): bool {
        return self::entityForClosedTable($table) !== null;
    }

    /**
     * 單一實體項認領封寫的表名（一律大寫）。
     *
     * @param array<string, mixed> $entity
     * @return array<int, string>
     */
    public static function closedTablesOf(array $entity): array {
        $closed = $entity['closed_code_tables'] ?? [];
        if (!is_array($closed)) {
            return [];
        }

        return array_values(array_map(
            fn ($t) => strtoupper((string) $t),
            array_filter($closed, 'is_scalar')
        ));
    }

    /**
     * 目前使用者能不能真的打開該封寫表所屬實體的編輯表單。
     *
     * 為什麼需要這道檢查：實體編輯頁**一律 abort(403)**，而泛用 codes 編輯頁沒有授權閘門
     * （唯讀表只是 flash 警告後 redirect）。連結若不看權限就改指實體頁，訪客與眾包帳號按
     * 「查閱」會從「警告＋回列表」變成硬 403——按鈕文案是「查閱」，語義上更不該。
     *
     * 各實體要求的能力**不一致**，所以由 config 的 form_capability 宣告、不可寫死：
     * office 的 `ensureCanReachForm()` 是 `canPropose()`，social-institution 與 text-entity
     * 的 `ensureWrite()` 是 `canWriteDirectly()`。未宣告時取較嚴的 write（fail-closed）。
     */
    public static function canReachEditForm(string $table): bool {
        $entity = self::entityForClosedTable($table);
        $user = Auth::user();
        if ($entity === null || $user === null) {
            return false;
        }

        return match ((string) ($entity['form_capability'] ?? 'write')) {
            'propose' => $user->canPropose(),
            default => $user->canWriteDirectly(),
        };
    }

    /**
     * 被封寫的下層表 → 其實體聚合編輯頁 URL；無法定位就回 null。
     *
     * 「寧可不出連結，也不要送使用者去撞牆」是這裡的取捨基準：導向一個**存在但錯誤**的
     * 實體，比沒有連結糟得多。
     *
     * ⚠️ **呼叫端一律要準備好 $fallbackEntityPk**。operations 裡的 resource_id 有多種歷史
     * 格式（見下面兩個 entityPkFrom*ResourceId()），單靠 resource_id 只解得出其中一部分——
     * 本機實測 76 列封寫表 operations，只有 OFFICE_CODES 全數可解，
     * SOCIAL_INSTITUTION_CODES／ADDR 一列都解不出。把 fallback 當成選配就會靜默少一批連結。
     *
     * @param string          $table            下層代碼表名
     * @param string          $storedResourceId operations.resource_id 原值
     * @param int|string|null $fallbackEntityPk 呼叫端另行解析出的實體識別鍵（見上）
     */
    public static function editUrl(string $table, string $storedResourceId, $fallbackEntityPk = null): ?string {
        $entity = self::entityForClosedTable($table);
        if ($entity === null) {
            return null;
        }

        $editRoute = (string) ($entity['edit_route'] ?? '');
        $route = $editRoute === '' ? null : Route::getRoutes()->getByName($editRoute);
        // 參數名不是 {id} 時 route() 會拋 UrlGenerationException，把整頁打成 500——
        // 與本類「解不出就回 null」的契約相反。config 漂移由單元測試擋，這裡是執行期兜底。
        if ($route === null || $route->parameterNames() !== ['id']) {
            return null;
        }

        // 三級優先序，**刻意**把呼叫端的 payload 值排在位置式解析之前：
        //  1. 具名格式——欄名齊全，最可靠。
        //  2. 呼叫端從 operation payload 取的識別欄——欄名明確，只是不在 resource_id 裡。
        //  3. 單鍵表的位置式第一段——只是「約定俗成第一段是識別鍵」的推測，可能落空：
        //     codes UI 的 buildCompositeId() 會把值為空的段整段濾掉，所以缺識別鍵時
        //     `{c_office_id}_._{c_dy}` 會塌成單獨的朝代碼，被這一級誤解成官職 id。
        //     排在 payload 之後，就只在真的沒有更好來源時才用。
        $id = self::entityPkFromNamedResourceId($table, $storedResourceId, $entity)
            ?? self::normalizePkValue($fallbackEntityPk)
            ?? self::entityPkFromPositionalResourceId($table, $storedResourceId, $entity);
        if ($id === null) {
            return null;
        }

        return route($editRoute, ['id' => $id], false);
    }

    /**
     * 來源 1（最可靠）：`buildStoredResourceId()` 的具名 query-string 格式。
     *
     * 欄名必須完整覆蓋該表 schema 才算數，另外比對分隔符數量，擋掉
     * `c_inst_code=88&c_inst_name_code=4021&c_inst_code=999` 這種同名參數覆蓋
     * （`CodesController::buildNamedConditionsFromId()` 已有同一道檢查）。
     *
     * @param array<string, mixed>|null $entity
     */
    protected static function entityPkFromNamedResourceId(string $table, string $storedResourceId, ?array $entity = null): ?string {
        [$pkColumn, $resourceId, $upper, $schema] = self::parseContext($table, $storedResourceId, $entity);
        if ($schema === null) {
            return null;
        }
        if (!str_contains($resourceId, '=') || str_contains($resourceId, '_._')) {
            return null;
        }

        $arity = count($schema);
        if (substr_count($resourceId, '&') !== $arity - 1 || substr_count($resourceId, '=') !== $arity) {
            return null;
        }

        $parsed = CompositePrimaryKey::parseStoredResourceId($resourceId, $upper);

        return (is_array($parsed) && array_key_exists($pkColumn, $parsed))
            ? self::normalizePkValue($parsed[$pkColumn])
            : null;
    }

    /**
     * 來源 3（最不可靠，僅在沒有更好來源時用）：單鍵表位置式 id 的第一段。
     *
     * 涵蓋 OFFICE_CODES 的裸值 `12304`，以及 `getKeyColumns()` 找不到 PK 而回退成「前兩欄」
     * 時產生的 `2318_._15`＝`{c_office_id}_._{c_dy}`（生產資料裡確實存在，本機 19 列中有 5 列）。
     *
     * **這一級是推測，不是解析**：安全性來自「`c_office_id` 恰好是該表第一欄」，不是 arity。
     * 同一個回退欄序下，若 payload 缺識別鍵，`buildCompositeId()` 的 `array_filter` 會把
     * 空的那段整段濾掉，`{c_office_id}_._{c_dy}` 就塌成單獨的朝代碼，被這裡誤解成官職 id。
     * 所以 `editUrl()` 把它排在呼叫端 payload 值**之後**。
     *
     * 為什麼不乾脆信 `parseStoredResourceId()` 的位置式回退：它的欄序來自
     * `CompositePrimaryKey::SCHEMAS`，與 codes UI 寫 operations 時用的
     * `CodesController::$tablePrimaryKeyOverrides` **本來就不同源**——
     * SOCIAL_INSTITUTION_CODES 在前者是 2 欄、後者只有 `['c_inst_code']`，
     * SOCIAL_INSTITUTION_ADDR 是 6 欄對 2 欄。今天兩表的實際段數都**少於** SCHEMAS 欄數
     * （`3983`、`3983-5348`），位置式分支會直接失敗、不至於出錯；但只要有人往
     * `$tablePrimaryKeyOverrides` 補一欄湊足段數，`4021_._88` 就會被當成
     * `(c_inst_code=4021, c_inst_name_code=88)`，把使用者導向另一個**真實存在**的機構。
     *
     * @param array<string, mixed>|null $entity
     */
    protected static function entityPkFromPositionalResourceId(string $table, string $storedResourceId, ?array $entity = null): ?string {
        [$pkColumn, $resourceId, , $schema] = self::parseContext($table, $storedResourceId, $entity);
        if ($schema === null || count($schema) !== 1 || $schema[0] !== $pkColumn) {
            return null;
        }

        return self::normalizePkValue(explode('_._', $resourceId, 2)[0]);
    }

    /**
     * 兩種解析共用的前置資料；任一必要條件不成立時 schema 回 null。
     *
     * @param array<string, mixed>|null $entity
     * @return array{0: string, 1: string, 2: string, 3: array<int, string>|null}
     */
    protected static function parseContext(string $table, string $storedResourceId, ?array $entity): array {
        $upper = strtoupper($table);
        $resourceId = trim($storedResourceId);
        $entity ??= self::entityForClosedTable($upper);
        $pkColumn = (string) ($entity['pk'] ?? '');

        if ($entity === null || $pkColumn === '' || $resourceId === '') {
            return [$pkColumn, $resourceId, $upper, null];
        }

        $schema = CompositePrimaryKey::getSchema(CompositePrimaryKey::getResourceIdSchemaTable($upper));

        return [$pkColumn, $resourceId, $upper, (is_array($schema) && $schema !== []) ? array_values($schema) : null];
    }

    /**
     * 識別鍵值正規化：**白名單**——必須是十進位整數，其餘一律視為無效。
     *
     * 目前三個實體的識別鍵都是整數代理鍵（c_office_id／c_inst_code／c_textid），三條
     * edit_route 也都掛了 `->whereNumber('id')`。但 Laravel 的 `route()` **不驗證** where
     * 約束，任何值都會照樣被拼進路徑——黑名單擋不完（`..`、`a/b`、`1#frag` 都能組出
     * 一條被注入路徑的 URL），所以這裡用白名單。
     *
     * 日後若出現非整數識別鍵的實體，正確做法是由 config 宣告該實體的鍵形，
     * 而不是回頭把這裡放寬成黑名單。
     *
     * @param mixed $value
     */
    protected static function normalizePkValue($value): ?string {
        if ($value === null || !is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        // 不接受前導零：`0012304` 在資料庫的數值親和性下仍查得到，但會產出一條長相怪異、
        // 與正規連結不一致的 URL。識別鍵的正規形只有一種。
        return preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1 ? $value : null;
    }
}
