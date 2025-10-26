<?php

namespace Tests\Feature;

use App\Http\Controllers\OperationsController;
use App\Operation;
use App\Repositories\OperationRepository;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class OperationsRestoreAuthorizeTest extends TestCase
{
    protected $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new FakeOperationRepository();

        DB::swap(new class {
            public function transaction(callable $callback)
            {
                return $callback();
            }
        });

        Session::start();

        if (!Route::has('operations.index')) {
            Route::get('/operations', function () {
                return 'operations index';
            })->name('operations.index');
        }
    }

    public function testActiveAdminCanTriggerRestore(): void
    {
        $user = $this->makeUser([
            'is_active' => 1,
            'is_admin' => 1,
        ]);
        $this->actingAs($user);

        $operation = $this->makeOperation(3);

        $controller = new SpyOperationsController($this->repository);
        $response = $controller->restore($this->makeRequest(), $operation);

        $this->assertTrue($controller->restoreInvoked);
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals(route('operations.index'), $response->headers->get('Location'));
        $this->assertCount(1, $this->repository->storeCalls);
        $call = $this->repository->storeCalls[0];
        $this->assertEquals($user->id, $call[0]);
        $this->assertEquals(99, $call[1]);
        $this->assertEquals(3, $call[2]);
        $this->assertEquals($operation->resource, $call[3]);
        $this->assertEquals($operation->resource_id, $call[4]);
        $this->assertEquals(['c_personid' => 99, 'field' => 'restored'], $call[5]);
        $this->assertEquals(['c_personid' => 99, 'field' => 'previous'], $call[6]);
    }

    public function testRegularUserCannotTriggerRestore(): void
    {
        $user = $this->makeUser([
            'is_active' => 1,
            'is_admin' => 0,
        ]);
        $this->actingAs($user);

        $controller = new SpyOperationsController($this->repository);
        $response = $controller->restore($this->makeRequest(), $this->makeOperation(3));

        $this->assertFalse($controller->restoreInvoked);
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertCount(0, $this->repository->storeCalls);
    }

    public function testBannedAdminCannotTriggerRestore(): void
    {
        $user = $this->makeUser([
            'is_active' => 1,
            'is_admin' => 2,
        ]);
        $this->actingAs($user);

        $controller = new SpyOperationsController($this->repository);
        $response = $controller->restore($this->makeRequest(), $this->makeOperation(3));

        $this->assertFalse($controller->restoreInvoked);
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertCount(0, $this->repository->storeCalls);
    }

    public function testInactiveUserCannotTriggerRestore(): void
    {
        $user = $this->makeUser([
            'is_active' => 0,
            'is_admin' => 1,
        ]);
        $this->actingAs($user);

        $controller = new SpyOperationsController($this->repository);
        $response = $controller->restore($this->makeRequest(), $this->makeOperation(4));

        $this->assertFalse($controller->restoreInvoked);
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertCount(0, $this->repository->storeCalls);
    }

    public function testGuestCannotTriggerRestore(): void
    {
        Auth::logout();

        $controller = new SpyOperationsController($this->repository);
        $response = $controller->restore($this->makeRequest(), $this->makeOperation(3));

        $this->assertFalse($controller->restoreInvoked);
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertCount(0, $this->repository->storeCalls);
    }

    protected function makeUser(array $overrides = []): User
    {
        $user = new User();
        $user->id = $overrides['id'] ?? rand(1, 1000);
        $user->name = $overrides['name'] ?? 'Tester';
        $user->email = $overrides['email'] ?? 'tester'.uniqid().'@example.com';
        $user->password = bcrypt('secret');
        $user->is_active = $overrides['is_active'] ?? 1;
        $user->is_admin = $overrides['is_admin'] ?? 1;
        return $user;
    }

    protected function makeOperation(int $opType): Operation
    {
        $operation = new Operation();
        $operation->id = 1;
        $operation->op_type = $opType;
        $operation->resource = 'BIOG_ADDR_DATA';
        $operation->resource_id = '1-1-1-1';
        $operation->resource_data = json_encode(['dummy' => 'value']);
        $operation->resource_original = json_encode(['dummy' => 'old']);
        return $operation;
    }

    protected function makeRequest(): Request
    {
        return Request::create('/operations/restore', 'POST');
    }
}

class SpyOperationsController extends OperationsController
{
    public $restoreInvoked = false;

    protected function performRestore(Operation $operation)
    {
        $this->restoreInvoked = true;
        return [
            'restored' => ['c_personid' => 99, 'field' => 'restored'],
            'previous' => ['c_personid' => 99, 'field' => 'previous'],
        ];
    }
}

class FakeOperationRepository extends OperationRepository
{
    public $storeCalls = [];

    public function store($user_id, $c_personid, $op_type, $resource, $resource_id, $resource_data, $ori = '', $crowdsourcing_status = 0)
    {
        $this->storeCalls[] = func_get_args();
    }
}
