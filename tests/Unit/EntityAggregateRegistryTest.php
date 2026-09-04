<?php

namespace Tests\Unit;

use App\Http\Controllers\CodesController;
use App\Support\CompositePrimaryKey;
use App\Support\EntityAggregateRegistry;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * config/entity_aggregates.php 的封寫判定與連結解析（App\Support\EntityAggregateRegistry）。
 *
 * 這支測試存在的理由：#1174／ca601b0d 封寫 OFFICE_CODES 等表時只動了 CodesController 的
 * 唯讀守衛，沒有動任何連結出口，於是 /app/operations 的「查閱」全變成「點進去吃唯讀警告、
 * 再被彈回列表」的死連結——而當時的測試只斷言 URL 字串長什麼樣，沒有人斷言那個 URL 到底
 * 能不能用（tests/Feature/OperationsIndexLinksTest.php 甚至把壞掉的 URL 釘成期望值）。
 *
 * 所以這裡刻意不只測「函式回傳什麼」，而是把兩個**不變量**釘成 fail-closed 的把關：
 *  1. 封寫判定只有一份推導（CodesController 委派過來，不得自己再抄一份）。
 *  2. 每張被封寫的表，要嘛 resource_id 就解得出實體識別鍵，要嘛必須登記進
 *     UNRESOLVABLE_FROM_RESOURCE_ID 並說明理由——不允許靜默少一條連結。
 */
class EntityAggregateRegistryTest extends TestCase {
    /**
     * 被封寫、但**主鍵 schema 不含實體識別鍵**的表：resource_id 本身無從定位實體。
     *
     * 登記在此**不代表**呼叫端補得到——是否補得到取決於該表的 operation payload 有沒有
     * 帶到識別欄，兩種情況都可能，理由必須逐筆寫清楚：
     *  - 補得到：呼叫端（OperationsController）從 resource_data／resource_original 取識別欄。
     *  - 補不到：連結就是不存在，這是**正確**結果，不要為了「讓每列都有連結」去猜。
     *
     * 新封寫一張表時，要嘛它的 schema 解得出識別鍵，要嘛被迫來這裡登記並交代屬於哪一種——
     * 兩者皆非就會紅，不會靜默變成「沒有連結」。
     *
     * @var array<string, string>
     */
    private const UNRESOLVABLE_FROM_RESOURCE_ID = [
        // 主鍵只有 c_inst_name_code，而名稱碼是**跨機構共用**的
        // （SocialInstituteImportService::resolveNameCode() 同名複用既有碼），
        // 所以名稱碼根本不唯一決定一個 c_inst_code。而且該表的 operation payload
        // 只有 (c_inst_name_code, c_inst_name_hz, c_inst_name_py)，呼叫端也補不到——
        // 這類 operation 沒有實體連結是正確的終態，不是待補的缺口。
        // （同一次機構新增／改名一定會伴隨一筆 SOCIAL_INSTITUTION_CODES 的 operation，
        // 那筆有連結，使用者不會被卡住。）
        'SOCIAL_INSTITUTION_NAME_CODES' => '名稱碼跨機構共用；resource_id 與 payload 都無 c_inst_code，補不到＝正確無連結',
    ];

    /** 唯讀但**沒有實體頁可去**的表：不歸 Registry 管，連結行為維持現狀。 */
    private const NO_ENTITY_PAGE = [
        'CBDB__NAME_FTS',
        'DYNASTIES',
        'GANZHI_CODES',
    ];

    // ---------------------------------------------------------------------
    // 不變量一：封寫判定只有一份推導
    // ---------------------------------------------------------------------

    #[Test]
    public function test_codes_controller_read_only_guard_agrees_with_the_registry(): void {
        $isReadOnly = new ReflectionMethod(CodesController::class, 'isReadOnlyTable');
        $isReadOnly->setAccessible(true);
        $controller = app(CodesController::class);

        foreach (self::allClosedTables() as $table) {
            $this->assertTrue(
                $isReadOnly->invoke($controller, $table),
                "{$table} 已登記封寫，CodesController 卻仍放行——守衛與連結解析漂移了"
            );
        }

        foreach (self::NO_ENTITY_PAGE as $table) {
            $this->assertTrue(
                $isReadOnly->invoke($controller, $table),
                "{$table} 應維持唯讀"
            );
            $this->assertFalse(
                EntityAggregateRegistry::isClosedByEntity($table),
                "{$table} 沒有實體頁，不該被 Registry 認領（否則連結會被導去不存在的頁）"
            );
        }

        // 未封寫的表不得被誤判為唯讀。
        // TEXT_CODES 是**刻意**的過渡狀態（config 註解說明 parity 補齊後就會封寫）；
        // 屆時這裡與 test_unclosed_and_unknown_tables_are_not_claimed 要一起翻面，
        // 那不是 bug，是預期中的下一步。
        foreach (['ADDR_CODES', 'NIAN_HAO', 'OFFICE_CODE_TYPE_REL', 'TEXT_CODES'] as $table) {
            $this->assertFalse(
                $isReadOnly->invoke($controller, $table),
                "{$table} 目前未登記封寫，不該被判為唯讀（若剛把它加進 closed_code_tables，"
                . '這條與 test_unclosed_and_unknown_tables_are_not_claimed 要一併更新）'
            );
        }
    }

    #[Test]
    public function test_no_table_is_claimed_by_two_entities(): void {
        $all = [];
        foreach (EntityAggregateRegistry::entities() as $entity) {
            foreach (EntityAggregateRegistry::closedTablesOf($entity) as $table) {
                $all[] = $table;
            }
        }

        $this->assertSame(
            array_values(array_unique($all)),
            $all,
            '同一張表被兩個實體認領：連結解析會靜默選中第一個實體'
        );
    }

    // ---------------------------------------------------------------------
    // 不變量二：封寫 ⇒ 連結解得出（否則必須登記例外）
    // ---------------------------------------------------------------------

    #[Test]
    public function test_every_registered_entity_declares_a_usable_edit_route(): void {
        $entities = EntityAggregateRegistry::entities();
        $this->assertNotEmpty($entities, 'entity_aggregates 註冊表不應為空');

        foreach ($entities as $entity) {
            $resource = (string) ($entity['resource'] ?? '(unnamed)');

            $this->assertNotEmpty(
                $entity['pk'] ?? null,
                "實體 {$resource} 未宣告 pk，連結解析無從取得識別鍵"
            );

            $editRoute = (string) ($entity['edit_route'] ?? '');
            $this->assertNotSame('', $editRoute, "實體 {$resource} 未宣告 edit_route");

            $route = Route::getRoutes()->getByName($editRoute);
            $this->assertNotNull(
                $route,
                "實體 {$resource} 的 edit_route（{$editRoute}）不存在——封寫後連結會靜默消失"
            );
            // 參數名寫死在 editUrl() 的 ['id' => …]；若哪天路由改成 {office}，
            // route() 不會報錯，只會把 id 掛成 query string，連結靜默壞掉。
            $this->assertSame(
                ['id'],
                $route->parameterNames(),
                "實體 {$resource} 的 edit_route 必須是單一 {id} 參數"
            );
        }
    }

    #[Test]
    public function test_every_closed_table_either_resolves_or_is_registered_as_unresolvable(): void {
        $unresolvable = [];

        foreach (EntityAggregateRegistry::entities() as $entity) {
            $pkColumn = (string) ($entity['pk'] ?? '');
            foreach (EntityAggregateRegistry::closedTablesOf($entity) as $table) {
                $schema = CompositePrimaryKey::getSchema(
                    CompositePrimaryKey::getResourceIdSchemaTable($table)
                );
                $this->assertIsArray(
                    $schema,
                    "{$table} 已封寫卻不在 CompositePrimaryKey::SCHEMAS 裡，resource_id 永遠解不開"
                );

                if (!in_array($pkColumn, $schema, true)) {
                    $unresolvable[] = $table;

                    continue;
                }

                // schema 含識別鍵 ⇒ 具名格式的 resource_id 必須解得出實體編輯連結。
                $pk = [];
                foreach ($schema as $i => $column) {
                    $pk[$column] = $column === $pkColumn ? 4242 : ($i + 1);
                }
                $this->assertSame(
                    "/{$this->routePrefix($entity)}/4242/edit",
                    EntityAggregateRegistry::editUrl($table, CompositePrimaryKey::buildStoredResourceId($pk)),
                    "{$table} 已封寫且 schema 含 {$pkColumn}，連結卻解不出來"
                );
            }
        }

        sort($unresolvable);
        $expected = array_keys(self::UNRESOLVABLE_FROM_RESOURCE_ID);
        sort($expected);
        $this->assertSame(
            $expected,
            $unresolvable,
            'UNRESOLVABLE_FROM_RESOURCE_ID 清冊與實際情況不符：'
            . '新封寫的表若無法由 resource_id 定位實體，必須登記進清冊並寫明'
            . '呼叫端補不補得到識別鍵（補不到就是正確的無連結，不要用猜的補上）'
        );
    }

    // ---------------------------------------------------------------------
    // 連結解析行為
    // ---------------------------------------------------------------------

    #[Test]
    public function test_office_codes_resolves_from_every_real_stored_id_format(): void {
        $this->assertTrue(EntityAggregateRegistry::isClosedByEntity('OFFICE_CODES'));

        // 具名格式（實體頁 SharesImportHelpers::recordOp() 寫入）。
        $this->assertSame(
            '/app/office/12304/edit',
            EntityAggregateRegistry::editUrl('OFFICE_CODES', CompositePrimaryKey::buildStoredResourceId(['c_office_id' => 12304]))
        );

        // 裸值格式（封寫前 codes UI 的 buildCompositeId() 寫入）——線上壞掉的正是這批。
        $this->assertSame('/app/office/803811/edit', EntityAggregateRegistry::editUrl('OFFICE_CODES', '803811'));

        // `{c_office_id}_._{c_dy}`：getKeyColumns() 取不到 PK 而回退成「前兩欄」時的產物，
        // 生產 operations 裡確實存在（本機 19 列 OFFICE_CODES 中有 5 列是這個形狀）。
        // 單鍵表的識別鍵必為第一段，所以取 `_._` 之前即可。
        $this->assertSame('/app/office/2318/edit', EntityAggregateRegistry::editUrl('OFFICE_CODES', '2318_._15'));

        // 表名大小寫不敏感。
        $this->assertSame('/app/office/7/edit', EntityAggregateRegistry::editUrl('office_codes', '7'));
    }

    #[Test]
    public function test_composite_key_closed_table_extracts_entity_pk_not_the_whole_key(): void {
        // SOCIAL_INSTITUTION_CODES 主鍵是 (c_inst_code, c_inst_name_code)，
        // 實體識別鍵只有 c_inst_code——取錯欄位會導向另一個真實存在的機構。
        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_inst_code' => 88,
            'c_inst_name_code' => 4021,
        ]);

        $this->assertSame(
            '/app/social-institution/88/edit',
            EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_CODES', $resourceId)
        );
    }

    #[Test]
    public function test_addr_closed_table_extracts_entity_pk_from_six_column_key(): void {
        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_inst_addr_id' => 3,
            'c_inst_addr_type_code' => 1,
            'c_inst_code' => 88,
            'c_inst_name_code' => 4021,
            'inst_xcoord' => 120.5,
            'inst_ycoord' => 30.25,
        ]);

        $this->assertSame(
            '/app/social-institution/88/edit',
            EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_ADDR', $resourceId)
        );
    }

    #[Test]
    public function test_positional_resource_ids_of_multi_column_tables_are_refused(): void {
        // codes UI 的 buildCompositeId() 欄序來自 $tablePrimaryKeyOverrides，與
        // CompositePrimaryKey::SCHEMAS 不同源（前者 SOCIAL_INSTITUTION_CODES 只有 1 欄、
        // ADDR 2 欄；後者 2 欄與 6 欄）。硬信位置式切分會把名稱碼當成機構碼，
        // 導向另一個**真實存在**的機構——那比沒有連結糟得多。
        //
        // 前兩個是**生產 operations 裡真實存在**的形狀（本機各 21／19 列）：機構碼裸值、
        // 以及 `-` 連接的 `{c_inst_code}-{c_inst_addr_id}`。這兩批一律要靠呼叫端補識別鍵，
        // 這條斷言就是釘住「Registry 自己解不出來」這件事，讓呼叫端不能不補。
        $this->assertNull(EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_CODES', '3983'));
        $this->assertNull(EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_ADDR', '3983-5348'));
        $this->assertNull(EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_CODES', '4021_._88'));
        $this->assertNull(EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_ADDR', '88_._3'));

        // 但呼叫端從 payload 取到 c_inst_code 時就能補起來。
        $this->assertSame(
            '/app/social-institution/3983/edit',
            EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_CODES', '3983', 3983)
        );
        $this->assertSame(
            '/app/social-institution/3983/edit',
            EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_ADDR', '3983-5348', 3983)
        );
    }

    #[Test]
    public function test_duplicate_query_parameters_cannot_override_the_entity_pk(): void {
        // parse_str() 後者覆蓋前者；不比對分隔符數量的話，'…&c_inst_code=999'
        // 會把使用者送去 999 號機構。
        $this->assertNull(EntityAggregateRegistry::editUrl('OFFICE_CODES', 'c_office_id=12&c_office_id=999'));
        $this->assertNull(EntityAggregateRegistry::editUrl(
            'SOCIAL_INSTITUTION_CODES',
            'c_inst_code=88&c_inst_name_code=4021&c_inst_code=999'
        ));
    }

    #[Test]
    public function test_caller_payload_fallback_outranks_positional_guessing(): void {
        // 三級優先序：具名格式 → 呼叫端 payload → 單鍵位置式。
        //
        // 位置式那級只是「約定第一段是識別鍵」的推測，會落空：codes UI 的
        // buildCompositeId() 把空值的段整段濾掉，所以 `{c_office_id}_._{c_dy}` 在缺識別鍵時
        // 會塌成單獨的朝代碼（`15`），被位置式誤解成官職 15。payload 值有明確欄名、更可靠，
        // 必須贏過它——否則使用者會被送到一個真實存在但不相干的官職。
        $this->assertSame(
            '/app/office/2318/edit',
            EntityAggregateRegistry::editUrl('OFFICE_CODES', '15', 2318)
        );

        // 但具名格式仍然贏過 payload（欄名齊全，最可靠）。
        $this->assertSame(
            '/app/office/12304/edit',
            EntityAggregateRegistry::editUrl(
                'OFFICE_CODES',
                CompositePrimaryKey::buildStoredResourceId(['c_office_id' => 12304]),
                99999
            )
        );

        // 沒有 payload 時，位置式仍是最後的可用來源（不要因為它不可靠就整個拿掉）。
        $this->assertSame('/app/office/2318/edit', EntityAggregateRegistry::editUrl('OFFICE_CODES', '2318_._15'));
    }

    #[Test]
    public function test_fallback_entity_pk_is_used_only_when_resource_id_cannot_supply_it(): void {
        $nameCodeId = CompositePrimaryKey::buildStoredResourceId(['c_inst_name_code' => 4021]);
        $this->assertNull(EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_NAME_CODES', $nameCodeId));
        $this->assertSame(
            '/app/social-institution/88/edit',
            EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_NAME_CODES', $nameCodeId, 88)
        );

        // resource_id 本身就帶得出識別鍵時，fallback 不得覆蓋它。
        $instCodeId = CompositePrimaryKey::buildStoredResourceId([
            'c_inst_code' => 88,
            'c_inst_name_code' => 4021,
        ]);
        $this->assertSame(
            '/app/social-institution/88/edit',
            EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_CODES', $instCodeId, 99999)
        );
    }

    #[Test]
    public function test_unclosed_and_unknown_tables_are_not_claimed(): void {
        // TEXT_CODES 目前刻意「暫不封寫」（config 的 closed_code_tables 為空）——連結必須
        // 維持走泛用 codes 頁，不可提前改指實體頁。
        // 封寫 TEXT_CODES／TEXT_INSTANCE_DATA 時，這兩條斷言要一併翻面（連同 §4.4 的
        // parity 清單與 tests/Feature/TextEntityIndexTest 的「暫不封寫」斷言）。
        $this->assertFalse(EntityAggregateRegistry::isClosedByEntity('TEXT_CODES'));
        $this->assertNull(EntityAggregateRegistry::editUrl('TEXT_CODES', 'c_textid=123'));

        // OFFICE_CODE_TYPE_REL 被 office 聚合認領（tables）但未封寫（closed_code_tables），
        // 連結同樣維持現狀。
        $this->assertFalse(EntityAggregateRegistry::isClosedByEntity('OFFICE_CODE_TYPE_REL'));

        $this->assertFalse(EntityAggregateRegistry::isClosedByEntity('ADDR_CODES'));
        $this->assertNull(EntityAggregateRegistry::editUrl('ADDR_CODES', 'c_addr_id=1'));

        // 沒有實體頁的唯讀表不在本類處理範圍內，維持既有行為。
        foreach (self::NO_ENTITY_PAGE as $table) {
            $this->assertNull(EntityAggregateRegistry::editUrl($table, 'c_dy=15'));
        }
    }

    #[Test]
    public function test_non_numeric_and_unusable_ids_never_produce_a_link(): void {
        // 識別鍵是整數代理鍵；route() 不驗證 whereNumber，所以白名單擋在這裡，
        // 否則會組出被注入路徑的 URL。
        foreach (['', '   ', 'NULL', 'c_office_id=NULL', 'c_bogus=5', 'abc', '..', '1/../../admin', 'a/b', '1#frag', '-1', '1.5'] as $bad) {
            $this->assertNull(
                EntityAggregateRegistry::editUrl('OFFICE_CODES', $bad),
                "resource_id 「{$bad}」不該產生連結"
            );
        }

        // fallback 走同一道白名單。
        $this->assertNull(EntityAggregateRegistry::editUrl('OFFICE_CODES', '', '../admin'));
        $this->assertNull(EntityAggregateRegistry::editUrl('OFFICE_CODES', '', true));
    }

    #[Test]
    public function test_missing_or_unregistered_edit_route_yields_no_link(): void {
        config(['entity_aggregates.entities' => [[
            'resource' => 'office',
            'pk' => 'c_office_id',
            'edit_route' => 'app.office.not-a-real-route',
            'closed_code_tables' => ['OFFICE_CODES'],
        ]]]);
        $this->assertNull(EntityAggregateRegistry::editUrl('OFFICE_CODES', '12304'));

        config(['entity_aggregates.entities' => [[
            'resource' => 'office',
            'pk' => 'c_office_id',
            'closed_code_tables' => ['OFFICE_CODES'],
        ]]]);
        $this->assertNull(EntityAggregateRegistry::editUrl('OFFICE_CODES', '12304'));

        // 有路由但缺 pk。
        config(['entity_aggregates.entities' => [[
            'resource' => 'office',
            'edit_route' => 'app.office.edit',
            'closed_code_tables' => ['OFFICE_CODES'],
        ]]]);
        $this->assertNull(EntityAggregateRegistry::editUrl('OFFICE_CODES', '12304'));
    }

    #[Test]
    public function test_edit_route_with_a_different_parameter_name_yields_no_link_instead_of_throwing(): void {
        // route() 對名稱不符的參數不會報錯——它會把 id 掛成 query string；而**缺**必要參數
        // 則直接拋 UrlGenerationException，把 /app/operations 整頁打成 500。
        // 本類的契約是「解不出就回 null」，所以執行期也要兜底，不能只靠單元測試擋 config 漂移。
        Route::get('test-only/entity/{office}/edit', fn () => '')->name('test-only.entity.edit');
        // 沒有這行，getByName() 會回 null，測試就只是在重測「路由不存在」那條分支：
        // RouteCollection::add() 在 ->name() 被呼叫前就跑完 addLookups()，名稱查找表
        // 只在 app boot 時 refresh 一次。
        Route::getRoutes()->refreshNameLookups();
        $this->assertNotNull(
            Route::getRoutes()->getByName('test-only.entity.edit'),
            '測試前提不成立：路由沒註冊進名稱查找表，下面的斷言會走錯分支'
        );

        config(['entity_aggregates.entities' => [[
            'resource' => 'office',
            'pk' => 'c_office_id',
            'edit_route' => 'test-only.entity.edit',
            'closed_code_tables' => ['OFFICE_CODES'],
        ]]]);

        $this->assertNull(EntityAggregateRegistry::editUrl('OFFICE_CODES', '12304'));
    }

    #[Test]
    public function test_malformed_registry_does_not_blow_up(): void {
        // config(key, []) 的預設值只在 key **不存在**時生效；key 存在但為 null 仍回 null，
        // 直接 foreach 會把 /app/operations 整頁打成 500。
        foreach ([null, 'oops', 42] as $broken) {
            config(['entity_aggregates.entities' => $broken]);
            $this->assertSame([], EntityAggregateRegistry::entities());
            $this->assertFalse(EntityAggregateRegistry::isClosedByEntity('OFFICE_CODES'));
            $this->assertNull(EntityAggregateRegistry::editUrl('OFFICE_CODES', '12304'));
        }

        config(['entity_aggregates.entities' => [
            'not-an-array',
            ['resource' => 'x', 'closed_code_tables' => 'not-an-array'],
            ['resource' => 'y'],
            ['resource' => 'z', 'closed_code_tables' => [['nested']]],
        ]]);
        $this->assertFalse(EntityAggregateRegistry::isClosedByEntity('OFFICE_CODES'));
        $this->assertNull(EntityAggregateRegistry::editUrl('OFFICE_CODES', '12304'));
    }

    /**
     * 全部已封寫的表（跨實體攤平）。
     *
     * @return array<int, string>
     */
    private static function allClosedTables(): array {
        $tables = [];
        foreach (EntityAggregateRegistry::entities() as $entity) {
            foreach (EntityAggregateRegistry::closedTablesOf($entity) as $table) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /** 由 edit_route 推出該實體頁的 URL 前綴（測試自用，不進生產程式碼）。 */
    private function routePrefix(array $entity): string {
        $uri = Route::getRoutes()->getByName($entity['edit_route'])->uri();

        return substr($uri, 0, strpos($uri, '/{'));
    }
}
