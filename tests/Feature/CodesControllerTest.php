<?php

namespace Tests\Feature;

use App\Http\Controllers\CodesController;
use App\Repositories\CodesRepository;
use App\Repositories\OperationRepository;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CodesControllerTest extends TestCase
{
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
}
