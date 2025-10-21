<?php

namespace Tests\Feature;

use App\Http\Controllers\CodesController;
use App\Repositories\CodesRepository;
use App\Repositories\OperationRepository;
use App\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CodesControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['codes.tables' => ['TEST_CODES']]);
        $compiledPath = base_path('tests/storage/views');
        if (!is_dir($compiledPath)) {
            mkdir($compiledPath, 0777, true);
        }
        config(['view.compiled' => $compiledPath]);

        $this->app->instance(CodesRepository::class, new class extends CodesRepository {
            public function allowedTables(): array
            {
                return ['TEST_CODES'];
            }

            public function allowedTableMap(): array
            {
                return ['TEST_CODES' => 'TEST_CODES'];
            }
        });
    }

    public function testGuestCannotStoreRows()
    {
        $payload = [
            'code_id' => 'A1',
            'code_sub' => 'B1',
            'description' => 'guest attempt',
        ];

        $response = $this->from('/codes/TEST_CODES/create')
            ->post('/codes/TEST_CODES', $payload);

        $response->assertRedirect('/codes/TEST_CODES/create');
    }

    public function testInactiveUserCannotStoreRows()
    {
        $inactiveUser = new User([
            'name' => 'inactive',
            'email' => 'inactive@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $inactiveUser->id = 1;
        $inactiveUser->is_active = 0;
        $this->actingAs($inactiveUser);

        $payload = [
            'code_id' => 'A2',
            'code_sub' => 'B2',
            'description' => 'inactive attempt',
        ];

        $response = $this->from('/codes/TEST_CODES/create')
            ->post('/codes/TEST_CODES', $payload);

        $response->assertRedirect('/codes/TEST_CODES/create');
    }

    public function testActiveUserStoreDoesNotInvokeOperationRepository()
    {
        $activeUser = new User([
            'name' => 'active',
            'email' => 'active@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $activeUser->id = 2;
        $activeUser->is_active = 1;
        $this->actingAs($activeUser);

        $expectedInsert = [
            'code_id' => 'A3',
            'code_sub' => 'B3',
            'description' => 'active stored',
        ];
        $recordedInserts = new \ArrayObject();
        $originalDb = $this->app['db'];
        $dbStub = new class($recordedInserts) {
            private $records;

            public function __construct(\ArrayObject $records)
            {
                $this->records = $records;
            }

            public function table($name)
            {
                return new class($name, $this->records) {
                    private $name;
                    private $records;

                    public function __construct($name, \ArrayObject $records)
                    {
                        $this->name = $name;
                        $this->records = $records;
                    }

                    public function insert(array $data)
                    {
                        $this->records->append(['table' => $this->name, 'data' => $data]);
                        return true;
                    }
                };
            }
        };
        DB::swap($dbStub);

        $operationStub = new class extends OperationRepository {
            public $called = false;

            public function store($user_id, $c_personid, $op_type, $resource, $resource_id, $resource_data, $ori = '', $crowdsourcing_status = 0)
            {
                $this->called = true;
            }
        };
        $this->app->instance(OperationRepository::class, $operationStub);

        $this->app->bind(CodesController::class, function ($app) use ($operationStub) {
            return new class($app->make(CodesRepository::class), $operationStub) extends CodesController {
                protected function getIdName($table_name)
                {
                    return 'code_id';
                }

                protected function getIdName_1($table_name)
                {
                    return 'code_sub';
                }

                protected function getIdName_2($table_name)
                {
                    return 'description';
                }
            };
        });

        $payload = $expectedInsert;

        $response = $this->post('/codes/TEST_CODES', $payload);

        $response->assertRedirect(route('codes.edit', [
            'table_name' => 'TEST_CODES',
            'id' => 'A3_._B3',
        ]));

        $this->assertFalse($operationStub->called, 'OperationRepository::store should not be invoked for generic codes store');
        $this->assertCount(1, (array) $recordedInserts);
        $insertRecord = $recordedInserts[0];
        $this->assertSame('TEST_CODES', $insertRecord['table']);
        $this->assertSame($expectedInsert, $insertRecord['data']);

        $this->app->bind(CodesController::class, function ($app) {
            return new CodesController(
                $app->make(CodesRepository::class),
                $app->make(OperationRepository::class)
            );
        });
        $this->app->instance(OperationRepository::class, new OperationRepository());
        DB::swap($originalDb);
    }

    public function testSearchFiltersResults()
    {
        $rows = [
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
            ['code_id' => 'A3', 'code_sub' => 'X3', 'description' => 'Gamma entry'],
        ];

        $fakeDb = new FakeDatabaseManager($rows);
        $originalDb = $this->app['db'];
        DB::swap($fakeDb);

        $response = $this->get('/codes/TEST_CODES?search=Beta');

        $response->assertStatus(200);
        $response->assertSee('Beta entry');
        $response->assertDontSee('Alpha entry');
        $response->assertDontSee('Gamma entry');
        $response->assertSee('value="Beta"', false);

        DB::swap($originalDb);
    }

    public function testGuestViewDoesNotShowActions()
    {
        $rows = [
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ];

        $fakeDb = new FakeDatabaseManager($rows);
        $originalDb = $this->app['db'];
        DB::swap($fakeDb);

        $response = $this->get('/codes/TEST_CODES');

        $response->assertStatus(200);
        $response->assertDontSee('edit');
        $response->assertDontSee('delete');
        $response->assertDontSee('新增');

        DB::swap($originalDb);
    }
}

class FakeDatabaseManager
{
    private $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function table($name)
    {
        return new FakeQueryBuilder($this->rows);
    }

    public function connection($name = null)
    {
        return $this;
    }

    public function select($query)
    {
        return [];
    }
}

class FakeQueryBuilder
{
    private $rows;
    private $filters = [];

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function __clone()
    {
        $this->filters = [];
    }

    public function first()
    {
        $filtered = $this->applyFilters($this->rows);
        $first = reset($filtered);
        return $first ? (object) $first : null;
    }

    public function where($callback)
    {
        if (is_callable($callback)) {
            $callback($this);
        }
        return $this;
    }

    public function orWhere($column, $operator = null, $value = null)
    {
        if (strtolower($operator) === 'like') {
            $this->filters[] = ['column' => $column, 'value' => $value];
        }
        return $this;
    }

    public function paginate($perPage)
    {
        $filtered = $this->applyFilters($this->rows);
        $items = array_map(function ($row) {
            return (object) $row;
        }, $filtered);

        return new LengthAwarePaginator(
            $items,
            count($filtered),
            $perPage,
            1,
            ['path' => url()->current()]
        );
    }

    private function applyFilters(array $rows): array
    {
        if (empty($this->filters)) {
            return array_values($rows);
        }

        return array_values(array_filter($rows, function ($row) {
            foreach ($this->filters as $filter) {
                $needle = trim($filter['value'], '%');
                $value = isset($row[$filter['column']]) ? (string) $row[$filter['column']] : '';
                if (stripos($value, $needle) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }
}
