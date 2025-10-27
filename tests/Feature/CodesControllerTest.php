<?php

namespace Tests\Feature;

use App\Http\Controllers\CodesController;
use App\Repositories\CodesRepository;
use App\Repositories\OperationRepository;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CodesControllerTest extends TestCase
{
    protected $operationSpy;
    protected $originalDb;
    protected $fakeDb;

    protected function setUp(): void
    {
        parent::setUp();

        config(['codes.tables' => ['TEST_CODES', 'TEXT_CODES']]);
        config(['codes.connection' => null]);

        $compiledPath = base_path('tests/storage/views');
        if (!is_dir($compiledPath)) {
            mkdir($compiledPath, 0777, true);
        }
        config(['view.compiled' => $compiledPath]);

        $this->originalDb = DB::getFacadeRoot();
        $this->fakeDb = new FakeDatabaseManager(
            [
                'TEST_CODES' => [],
                'TEXT_CODES' => [],
            ],
            [
                'TEST_CODES' => ['code_id', 'code_sub', 'description'],
                'TEXT_CODES' => ['c_textid', 'c_title', 'c_title_chn'],
            ]
        );
        DB::swap($this->fakeDb);

        $this->app->instance(CodesRepository::class, new class extends CodesRepository {
            public function allowedTables(): array
            {
                return ['TEST_CODES', 'TEXT_CODES'];
            }

            public function allowedTableMap(): array
            {
                return [
                    'TEST_CODES' => 'TEST_CODES',
                    'TEXT_CODES' => 'TEXT_CODES',
                ];
            }
        });

        $this->operationSpy = new class extends OperationRepository {
            public $calls = [];

            public function store($user_id, $c_personid, $op_type, $resource, $resource_id, $resource_data, $ori = '', $crowdsourcing_status = 0)
            {
                $this->calls[] = compact('user_id', 'c_personid', 'op_type', 'resource', 'resource_id', 'resource_data', 'ori', 'crowdsourcing_status');
            }
        };
        $this->app->instance(OperationRepository::class, $this->operationSpy);
    }

    protected function tearDown(): void
    {
        DB::swap($this->originalDb);
        parent::tearDown();
    }

    public function testGuestCannotStoreRows()
    {
        $this->operationSpy->calls = [];
        $payload = [
            'code_id' => 'A1',
            'code_sub' => 'B1',
            'description' => 'guest attempt',
        ];

        $response = $this->from('/codes/TEST_CODES/create')
            ->post('/codes/TEST_CODES', $payload);

        $response->assertRedirect('/codes/TEST_CODES/create');
        $this->assertEmpty($this->operationSpy->calls);
    }

    public function testInactiveUserCannotStoreRows()
    {
        $this->operationSpy->calls = [];
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
        $this->assertEmpty($this->operationSpy->calls);
    }

    public function testActiveUserStoreLogsOperation()
    {
        $this->operationSpy->calls = [];
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
        $response = $this->post('/codes/TEST_CODES', $expectedInsert);

        $response->assertRedirect(route('codes.edit', [
            'table_name' => 'TEST_CODES',
            'id' => 'A3_._B3',
        ]));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(1, $call['op_type']);
        $this->assertSame('TEST_CODES', $call['resource']);
        $this->assertSame('A3_._B3', $call['resource_id']);
        $this->assertSame($expectedInsert['description'], $call['resource_data']['description']);
    }

    public function testSearchFiltersResults()
    {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
            ['code_id' => 'A3', 'code_sub' => 'X3', 'description' => 'Gamma entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES?search=Beta');

        $response->assertStatus(200);
        $response->assertSee('Beta entry');
        $response->assertDontSee('Alpha entry');
        $response->assertDontSee('Gamma entry');
        $response->assertSee('value="Beta"', false);
        $this->assertEmpty($this->operationSpy->calls);
    }

    public function testGuestViewDoesNotShowActions()
    {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES');

        $response->assertStatus(200);
        $response->assertDontSee('edit');
        $response->assertDontSee('delete');
        $response->assertDontSee('新增');
        $this->assertEmpty($this->operationSpy->calls);
    }

    public function testTextCodesUsesExplicitPrimaryKeyOverride()
    {
        DB::table('TEXT_CODES')->insert([
            ['c_textid' => 'T001', 'c_title' => 'Sample Title', 'c_title_chn' => 'Sample Title CHN'],
        ]);

        $user = new User([
            'name' => 'text-admin',
            'email' => 'text-admin@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 5;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->get('/codes/TEXT_CODES');

        $response->assertStatus(200);
        $response->assertViewHas('keyColumns', ['c_textid']);
        $response->assertSee('href="/codes/TEXT_CODES/T001/edit"', false);
        $response->assertDontSee('href="/codes/TEXT_CODES/T001_._', false);
        $this->assertEmpty($this->operationSpy->calls);
    }

    public function testActiveUserUpdateLogsOperation()
    {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Old'],
        ]);

        $this->operationSpy->calls = [];

        $user = new User([
            'name' => 'editor',
            'email' => 'editor@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 3;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->put('/codes/TEST_CODES/A1_._X1', [
            'description' => 'Updated',
        ]);

        $response->assertRedirect(route('codes.edit', [
            'table_name' => 'TEST_CODES',
            'id' => 'A1_._X1',
        ]));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(2, $call['op_type']);
        $this->assertSame('TEST_CODES', $call['resource']);
        $this->assertSame('A1_._X1', $call['resource_id']);
        $this->assertSame('Updated', $call['resource_data']['description']);
        $this->assertSame('Old', $call['ori']['description']);
    }

    public function testActiveUserDestroyLogsOperation()
    {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'To delete'],
        ]);

        $this->operationSpy->calls = [];

        $user = new User([
            'name' => 'deleter',
            'email' => 'deleter@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 4;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->delete('/codes/TEST_CODES/A1_._X1');

        $response->assertRedirect(route('codes.show', ['table_name' => 'TEST_CODES']));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(4, $call['op_type']);
        $this->assertSame('TEST_CODES', $call['resource']);
        $this->assertSame('A1_._X1', $call['resource_id']);
        $this->assertSame('To delete', $call['resource_data']['description']);
    }

    public function testUpdateGracefullyHandlesDuplicateKey()
    {
        $this->fakeDb->tables['TEST_CODES'][] = ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Old'];
        $this->fakeDb->setFailure('update', 'Duplicate entry #1062');
        $this->operationSpy->calls = [];

        $user = new User([
            'name' => 'editor',
            'email' => 'editor@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 6;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->from('/codes/TEST_CODES/A1_._X1/edit')->put('/codes/TEST_CODES/A1_._X1', [
            'description' => 'Updated',
        ]);

        $response->assertRedirect('/codes/TEST_CODES/A1_._X1/edit');
        $response->assertSessionHasErrors(['duplicate']);
        $response->assertSessionHas('_old_input.description', 'Updated');
        $this->assertEmpty($this->operationSpy->calls);

        $this->fakeDb->clearFailures();
        $this->assertCount(1, $this->fakeDb->failuresCleared);
    }
}

class FakeDatabaseManager
{
    public $tables = [];
    public $failures = [];
    public $failuresCleared = [];
    public $schemaColumns = [];

    public function __construct(array $tables = [], array $schemaColumns = [])
    {
        $this->tables = $tables;
        $this->schemaColumns = $schemaColumns;
    }

    public function table($name)
    {
        if (!array_key_exists($name, $this->tables)) {
            $this->tables[$name] = [];
        }

        $rows =& $this->tables[$name];
        return new FakeQueryBuilder($rows, $this, $name);
    }

    public function connection($name = null)
    {
        return $this;
    }

    public function getDoctrineSchemaManager()
    {
        return new FakeDoctrineSchemaManager();
    }

    public function select($query)
    {
        return [];
    }

    public function getSchemaBuilder()
    {
        return new FakeSchemaBuilder($this->schemaColumns);
    }

    public function setFailure(string $operation, string $message = 'Simulated failure'): void
    {
        $this->failures[$operation] = $message;
    }

    public function clearFailures(): void
    {
        $this->failures = [];
        $this->failuresCleared[] = true;
    }

    public function shouldFail(string $operation): bool
    {
        return array_key_exists($operation, $this->failures);
    }

    public function failureMessage(string $operation): string
    {
        return $this->failures[$operation] ?? 'Simulated failure';
    }
}

class FakeDoctrineSchemaManager
{
    public function listTableDetails($table)
    {
        return new FakeTableDetails();
    }
}

class FakeTableDetails
{
    public function hasPrimaryKey()
    {
        return false;
    }

    public function getPrimaryKey()
    {
        return null;
    }
}

class FakeSchemaBuilder
{
    private $schemaColumns = [];

    public function __construct(array $schemaColumns = [])
    {
        $this->schemaColumns = $schemaColumns;
    }

    public function getColumnListing($table)
    {
        if (isset($this->schemaColumns[$table]) && !empty($this->schemaColumns[$table])) {
            return $this->schemaColumns[$table];
        }

        return ['code_id', 'code_sub', 'description'];
    }
}

class FakeQueryBuilder
{
    private $rows;
    private $conditions = [];
    private $table;
    private $manager;

    public function __construct(array &$rows, FakeDatabaseManager $manager, string $table)
    {
        $this->rows =& $rows;
        $this->manager = $manager;
        $this->table = $table;
    }

    public function __clone()
    {
        $this->conditions = [];
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_callable($column)) {
            $column($this);
            return $this;
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->conditions[] = [
            'column' => $column,
            'operator' => strtolower((string) $operator),
            'value' => $value,
            'boolean' => strtolower($boolean),
        ];

        return $this;
    }

    public function orWhere($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->where($column, $operator, $value, 'or');
    }

    public function first()
    {
        $filtered = $this->applyConditions();
        $first = reset($filtered);
        return $first ? (object) $first : null;
    }

    public function insert(array $data)
    {
        if ($this->manager->shouldFail('insert')) {
            throw new QueryException('insert into '.$this->table, [], new \Exception($this->manager->failureMessage('insert')));
        }

        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $row) {
                $this->rows[] = $row;
            }
        } else {
            $this->rows[] = $data;
        }
        return true;
    }

    public function update(array $data)
    {
        if ($this->manager->shouldFail('update')) {
            throw new QueryException('update '.$this->table, [], new \Exception($this->manager->failureMessage('update')));
        }

        foreach ($this->rows as &$row) {
            if ($this->rowMatches($row)) {
                foreach ($data as $key => $value) {
                    $row[$key] = $value;
                }
            }
        }

        return true;
    }

    public function delete()
    {
        if ($this->manager->shouldFail('delete')) {
            throw new QueryException('delete from '.$this->table, [], new \Exception($this->manager->failureMessage('delete')));
        }

        $this->rows = array_values(array_filter($this->rows, function ($row) {
            return !$this->rowMatches($row);
        }));
    }

    public function paginate($perPage)
    {
        $items = array_map(function ($row) {
            return (object) $row;
        }, $this->applyConditions());

        return new LengthAwarePaginator(
            $items,
            count($items),
            $perPage,
            1,
            ['path' => url()->current()]
        );
    }

    private function applyConditions(): array
    {
        if (empty($this->conditions)) {
            return $this->rows;
        }

        return array_values(array_filter($this->rows, function ($row) {
            return $this->rowMatches($row);
        }));
    }

    private function rowMatches(array $row): bool
    {
        if (empty($this->conditions)) {
            return true;
        }

        $result = null;
        foreach ($this->conditions as $condition) {
            $match = $this->matchCondition($row, $condition);
            if ($condition['boolean'] === 'or') {
                $result = ($result ?? false) || $match;
            } else {
                $result = ($result ?? true) && $match;
            }
        }

        return (bool) $result;
    }

    private function matchCondition(array $row, array $condition): bool
    {
        $value = $row[$condition['column']] ?? null;
        $expected = $condition['value'];

        if ($condition['operator'] === 'like') {
            $needle = trim((string) $expected, '%');
            return stripos((string) $value, $needle) !== false;
        }

        return (string) $value === (string) $expected;
    }
}
