<?php

namespace Tests\Unit;

use App\Http\Controllers\CodesController;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `CodesController::resolveColumnForQuery()` —— JOIN 配置表的欄名解析（**注入面**）。
 *
 * 它決定使用者送來的 `sort_by`／`filters[...]` 欄名要怎麼變成 SQL 片段：
 *  - JOIN alias（`appt_name`）→ 解析成 selectList 裡的原始表達式（`code.c_appt_desc_chn`）；
 *  - base table 真實欄位 → 加上 base alias（`rel.c_appt_code`）；
 *  - 非 JOIN 表 → 直接回欄名；
 *  - **既不在 selectList 也不在 base table schema → null**（這一條是白名單的最後一道，
 *    回傳非 null 就等於把任意字串送進 ORDER BY／WHERE）。
 *
 * ── 2026-09-14（Blade 下架環節 4b-2a）─────────────────────────────
 *
 * 這 4 條原本住在 `CodesControllerTest`（整類打著 `#[Group('legacy-parity')]`），但它們
 * **完全不打 HTTP、不碰 Blade**——只是用 `ReflectionMethod` 直呼 protected 方法。
 * 環節 4b 要刪掉那個檔，整檔刪掉會**靜默毀掉這道防注入斷言**，所以先搬出來。
 *
 * 唯一的改動：`base_table_column` 那條原本依賴該檔底部約 430 行的
 * `FakeDatabaseManager`／`FakeSchemaBuilder`（用來讓 `Schema::getColumnListing()` 回傳欄位）。
 * 那組假 DB 不隨檔搬家（它只服務那一檔、且 4b 的移植一律改用真 SQLite），
 * 所以這裡改成**真的建一張 `APPOINTMENT_CODE_TYPE_REL`**——比假 DB 更貼近真實。
 *
 * 另一處差異：情境 D 的 dot-prefix 斷言由 `malicious.injection` **改寫**成
 * `other.c_appt_code`（不是新增——HEAD 原本就有那條斷言）。改寫的理由是新值的 dot 後半是
 * **真實欄名**，所以它證明「先看到 dot 就拒」，而不是靠「欄名不存在」順帶擋掉。
 */
class CodesResolveColumnForQueryTest extends TestCase {
    /** @var array<string, mixed> APPOINTMENT_CODE_TYPE_REL 的 JOIN 配置（與 config/codes_joins.php 同形狀）。 */
    private const JOIN_CONFIG = [
        'base_table' => 'APPOINTMENT_CODE_TYPE_REL',
        'base_alias' => 'rel',
        'select' => [
            'rel.c_appt_code',
            'code.c_appt_desc_chn as appt_name',
            'rel.c_appt_type_code',
            'type.c_appt_type_desc_chn as appt_type_name',
        ],
    ];

    protected function setUp(): void {
        parent::setUp();

        // 不覆寫連線設定：phpunit.xml 已是 sqlite / :memory:，而整塊替換
        // database.connections.sqlite 會把專案原本的鍵（例如 foreign_key_constraints）一併丟掉。

        // base table 的欄位清單：`resolveColumnForQuery()` 用 Schema::getColumnListing() 判斷
        // 「這是不是 base table 的真實欄位」，所以必須真的存在。
        Schema::create('APPOINTMENT_CODE_TYPE_REL', function ($table) {
            $table->integer('c_appt_code');
            $table->integer('c_appt_type_code');
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('APPOINTMENT_CODE_TYPE_REL');
        parent::tearDown();
    }

    /** @return mixed */
    private function resolve(string $column, ?array $joinConfig) {
        $controller = $this->app->make(CodesController::class);
        $method = new \ReflectionMethod(CodesController::class, 'resolveColumnForQuery');
        $method->setAccessible(true);

        return $method->invoke($controller, $column, $joinConfig);
    }

    #[Test]
    public function join_alias_resolves_to_the_original_select_expression(): void {
        $this->assertSame('code.c_appt_desc_chn', $this->resolve('appt_name', self::JOIN_CONFIG));
        $this->assertSame('type.c_appt_type_desc_chn', $this->resolve('appt_type_name', self::JOIN_CONFIG));
    }

    #[Test]
    public function base_table_column_gets_the_base_alias(): void {
        $this->assertSame('rel.c_appt_code', $this->resolve('c_appt_code', self::JOIN_CONFIG));
        $this->assertSame('rel.c_appt_type_code', $this->resolve('c_appt_type_code', self::JOIN_CONFIG));
    }

    #[Test]
    public function non_join_table_returns_the_column_verbatim(): void {
        $this->assertSame('c_name', $this->resolve('c_name', null));
        $this->assertSame('description', $this->resolve('description', null));
    }

    #[Test]
    public function unresolvable_column_returns_null(): void {
        // 既不在 selectList 也不在 base table schema ⇒ null（白名單的最後一道）。
        $this->assertNull($this->resolve('unknown_column', self::JOIN_CONFIG));

        // 帶 dot 前綴的輸入也不可被接受（否則等於讓呼叫端自己指定表別名）。
        $this->assertNull($this->resolve('other.c_appt_code', self::JOIN_CONFIG));
    }
}
