<?php

namespace Tests\Unit;

use App\Http\Controllers\OperationsController;
use App\Models\Operation;
use App\Repositories\OperationRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsAltnameResolverTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence');
            $table->integer('c_alt_name_type_code');
            $table->string('c_alt_name')->nullable();
            $table->string('c_alt_name_chn')->nullable();
        });

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 123,
            'c_sequence' => 1,
            'c_alt_name_type_code' => 10,
            'c_alt_name' => 'Zi Jing',
            'c_alt_name_chn' => '張-一',
        ]);
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ALTNAME_DATA');
        parent::tearDown();
    }

    #[Test]
    public function test_fetch_altname_current_row_handles_dash_in_resource_id(): void {
        $controller = new class (new OperationRepository()) extends OperationsController {
            public function resolveAltname(array $payload) {
                return $this->fetchAltnameCurrentRow($payload);
            }
        };

        $operationPayload = [
            'resource_id' => '123-1-張_minus一-10',
            'resource_data' => json_encode([
                'c_personid' => 123,
                'c_sequence' => 1,
                'c_alt_name_chn' => '張-一',
                'c_alt_name_type_code' => 10,
            ]),
        ];

        $row = $controller->resolveAltname($operationPayload);

        $this->assertNotNull($row);
        $this->assertSame('張-一', $row->c_alt_name_chn);
        $this->assertSame('Zi Jing', $row->c_alt_name);
    }

    #[Test]
    public function test_fetch_altname_handles_null_sentinel_in_query_string_resource_id(): void {
        // 當 resource_data 缺少欄位，且 resource_id 用新格式包含 NULL sentinel 時，
        // c_sequence=NULL 經解析後為 PHP null。
        // Phase 1 (#834)：c_sequence 為 null 時改走 3-key 查詢（DB PK），
        // 因此能正確定位到該筆資料（不論 c_sequence 實際值為何）。
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 200,
            'c_sequence' => 0,
            'c_alt_name_type_code' => 5,
            'c_alt_name' => 'Test',
            'c_alt_name_chn' => '測試',
        ]);

        $controller = new class (new OperationRepository()) extends OperationsController {
            public function resolveAltname(array $payload) {
                return $this->fetchAltnameCurrentRow($payload);
            }
        };

        // resource_data 只有 c_personid，其餘需從 resource_id 補齊
        // c_sequence=NULL 表示該值為 null
        $operationPayload = [
            'resource_id' => 'c_personid=200&c_sequence=NULL&c_alt_name_chn=%E6%B8%AC%E8%A9%A6&c_alt_name_type_code=5',
            'resource_data' => json_encode([
                'c_personid' => 200,
            ]),
        ];

        $row = $controller->resolveAltname($operationPayload);

        // Phase 1：c_sequence 為 null → 3-key 回退查詢，以 DB PK 定位
        // (c_personid=200, c_alt_name_chn=測試, c_alt_name_type_code=5) 能唯一找到該列
        $this->assertNotNull($row);
        $this->assertEquals(200, $row->c_personid);
        $this->assertSame('測試', $row->c_alt_name_chn);
        $this->assertEquals(0, $row->c_sequence);
    }

    #[Test]
    public function test_build_key_conditions_normalizes_null_sentinel(): void {
        // buildKeyConditions() 從 resource_id 的 query-string 中讀取 'NULL' 時，
        // 應轉換為 PHP null（以便後續產生 WHERE IS NULL）
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->integer('c_personid')->nullable();
            $table->tinyInteger('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->timestamps();
            $table->tinyInteger('crowdsourcing_status')->default(0);
            $table->tinyInteger('rate')->default(0);
        });

        $controller = new class (new OperationRepository()) extends OperationsController {
            public function publicBuildKeyConditions(Operation $op, array $current, array $fallback) {
                return $this->buildKeyConditions($op, $current, $fallback);
            }
        };

        $op = new Operation();
        $op->resource = 'ALTNAME_DATA';
        $op->resource_id = 'c_personid=123&c_sequence=NULL&c_alt_name_chn=%E5%BC%B5%E4%B8%89&c_alt_name_type_code=10';

        // $current 和 $fallback 都不含完整 key，迫使 buildKeyConditions 解析 resource_id
        $conditions = $controller->publicBuildKeyConditions($op, [], []);

        $this->assertCount(4, $conditions); // ALTNAME_DATA key columns: c_personid, c_sequence, c_alt_name_chn, c_alt_name_type_code
        $this->assertSame('123', $conditions['c_personid']);
        $this->assertNull($conditions['c_sequence']); // 'NULL' 應轉為 PHP null
        $this->assertSame('張三', $conditions['c_alt_name_chn']);
        $this->assertSame('10', $conditions['c_alt_name_type_code']);

        Schema::dropIfExists('operations');
    }

    // -------------------------------------------------------
    // Phase 1 (#834)：3-key 相容層測試
    // -------------------------------------------------------

    #[Test]
    public function test_fetch_altname_current_row_with_3key_query_string(): void {
        // 3-key query-string 格式（不含 c_sequence），應以 DB 3-key 查到正確資料列
        $controller = new class (new OperationRepository()) extends OperationsController {
            public function resolveAltname(array $payload) {
                return $this->fetchAltnameCurrentRow($payload);
            }
        };

        $operationPayload = [
            'resource_id' => 'c_personid=123&c_alt_name_chn=%E5%BC%B5%2D%E4%B8%80&c_alt_name_type_code=10',
            'resource_data' => json_encode([
                'c_personid' => 123,
            ]),
        ];

        $row = $controller->resolveAltname($operationPayload);

        $this->assertNotNull($row);
        $this->assertSame('張-一', $row->c_alt_name_chn);
        $this->assertSame('Zi Jing', $row->c_alt_name);
    }

    #[Test]
    public function test_fetch_altname_current_row_with_3key_dash_format(): void {
        // 3-key dash 格式：c_personid-c_alt_name_chn-c_alt_name_type_code
        $controller = new class (new OperationRepository()) extends OperationsController {
            public function resolveAltname(array $payload) {
                return $this->fetchAltnameCurrentRow($payload);
            }
        };

        // 「張-一」在 dash 格式中編碼為 張(minus)一
        $operationPayload = [
            'resource_id' => '123-張(minus)一-10',
            'resource_data' => json_encode([]),
        ];

        $row = $controller->resolveAltname($operationPayload);

        $this->assertNotNull($row);
        $this->assertSame('張-一', $row->c_alt_name_chn);
        $this->assertEquals(1, $row->c_sequence);
    }

    #[Test]
    public function test_build_key_conditions_with_3key_altname_resource_id(): void {
        // 3-key resource_id → buildKeyConditions 應返回 3 個 condition
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->integer('c_personid')->nullable();
            $table->tinyInteger('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->timestamps();
            $table->tinyInteger('crowdsourcing_status')->default(0);
            $table->tinyInteger('rate')->default(0);
        });

        $controller = new class (new OperationRepository()) extends OperationsController {
            public function publicBuildKeyConditions(Operation $op, array $current, array $fallback) {
                return $this->buildKeyConditions($op, $current, $fallback);
            }
        };

        $op = new Operation();
        $op->resource = 'ALTNAME_DATA';
        $op->resource_id = 'c_personid=123&c_alt_name_chn=%E5%BC%B5%E4%B8%89&c_alt_name_type_code=10';

        $conditions = $controller->publicBuildKeyConditions($op, [], []);

        // 3-key resource_id → parseStoredResourceId 返回 3 個欄位
        // buildKeyConditions 中只有 3 個欄位在 namedParsed 中，c_sequence 無法從任何來源取得
        $this->assertSame('123', $conditions['c_personid']);
        $this->assertSame('張三', $conditions['c_alt_name_chn']);
        $this->assertSame('10', $conditions['c_alt_name_type_code']);
        $this->assertArrayNotHasKey('c_sequence', $conditions);

        Schema::dropIfExists('operations');
    }
}
