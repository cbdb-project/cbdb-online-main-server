<?php

namespace Tests\Feature;

use App\Http\Controllers\OperationsController;
use App\Models\Operation;
use App\Models\User;
use App\Repositories\OperationRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsRestoreAuthorizeTest extends TestCase {
    protected $repository;

    protected function setUp(): void {
        parent::setUp();

        $this->repository = new FakeOperationRepository();

        DB::swap(new class () {
            public function transaction(callable $callback) {
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

    #[Test]
    public function testActiveAdminCanTriggerRestore(): void {
        $user = $this->makeUser(['is_active' => 1, 'is_admin' => 1]);
        $this->actingAs($user);

        $operation = $this->makeOperation(3, 'POSTED_TO_OFFICE_DATA', ['c_personid' => 123]);

        $controller = new SpyOperationsController($this->repository);
        $controller->restore($this->makeRequest(), $operation);

        $this->assertTrue($controller->restoreInvoked);
        $this->assertCount(1, $this->repository->storeCalls);
        $this->assertEquals($operation->resource, $this->repository->storeCalls[0][3]);
    }

    #[Test]
    public function testRestoreUsesOriginalPersonIdWhenAvailable(): void {
        $user = $this->makeUser(['is_active' => 1, 'is_admin' => 1]);
        $this->actingAs($user);

        $operation = $this->makeOperation(3, 'POSTED_TO_OFFICE_DATA', ['c_personid' => 456]);

        $controller = new SpyOperationsController($this->repository, ['restoredPersonId' => 99]);
        $controller->restore($this->makeRequest(), $operation);

        $this->assertCount(1, $this->repository->storeCalls);
        $this->assertSame(456, $this->repository->storeCalls[0][1]);
    }

    #[Test]
    public function testRegularUserCannotTriggerRestore(): void {
        $user = $this->makeUser(['is_active' => 1, 'is_admin' => 0]);
        $this->actingAs($user);

        $controller = new SpyOperationsController($this->repository);
        $controller->restore($this->makeRequest(), $this->makeOperation(3, 'POSTED_TO_OFFICE_DATA'));

        $this->assertFalse($controller->restoreInvoked);
        $this->assertCount(0, $this->repository->storeCalls);
    }

    #[Test]
    public function testBannedAdminCannotTriggerRestore(): void {
        $user = $this->makeUser(['is_active' => 1, 'is_admin' => 2]);
        $this->actingAs($user);

        $controller = new SpyOperationsController($this->repository);
        $controller->restore($this->makeRequest(), $this->makeOperation(3, 'POSTED_TO_OFFICE_DATA'));

        $this->assertFalse($controller->restoreInvoked);
        $this->assertCount(0, $this->repository->storeCalls);
    }

    #[Test]
    public function testInactiveUserCannotTriggerRestore(): void {
        $user = $this->makeUser(['is_active' => 0, 'is_admin' => 1]);
        $this->actingAs($user);

        $controller = new SpyOperationsController($this->repository);
        $controller->restore($this->makeRequest(), $this->makeOperation(3, 'POSTED_TO_OFFICE_DATA'));

        $this->assertFalse($controller->restoreInvoked);
        $this->assertCount(0, $this->repository->storeCalls);
    }

    #[Test]
    public function testAddressResourceRestoreIsRejected(): void {
        $user = $this->makeUser(['is_active' => 1, 'is_admin' => 1]);
        $this->actingAs($user);

        $controller = new SpyOperationsController($this->repository);
        $controller->restore($this->makeRequest(), $this->makeOperation(3, 'POSTED_TO_ADDR_DATA'));

        $this->assertFalse($controller->restoreInvoked);
        $this->assertCount(0, $this->repository->storeCalls);
    }

    #[Test]
    public function testGuestCannotTriggerRestore(): void {
        Auth::logout();

        $controller = new SpyOperationsController($this->repository);
        $controller->restore($this->makeRequest(), $this->makeOperation(3, 'POSTED_TO_OFFICE_DATA'));

        $this->assertFalse($controller->restoreInvoked);
        $this->assertCount(0, $this->repository->storeCalls);
    }

    protected function makeUser(array $overrides = []): User {
        $user = new User();
        $user->id = $overrides['id'] ?? rand(1, 1000);
        $user->name = $overrides['name'] ?? 'Tester';
        $user->email = $overrides['email'] ?? 'tester'.uniqid().'@example.com';
        $user->password = bcrypt('secret');
        $user->is_active = $overrides['is_active'] ?? 1;
        $user->is_admin = $overrides['is_admin'] ?? 1;

        return $user;
    }

    protected function makeOperation(int $opType, string $resource, array $overrides = []): Operation {
        $operation = new Operation();
        $operation->id = 1;
        $operation->op_type = $opType;
        $operation->resource = $resource;
        $operation->resource_id = '1-1-1-1';
        $operation->resource_data = json_encode(['dummy' => 'value']);
        $operation->resource_original = json_encode(['dummy' => 'old']);
        foreach ($overrides as $key => $value) {
            $operation->{$key} = $value;
        }

        return $operation;
    }

    protected function makeRequest(): Request {
        return Request::create('/operations/restore', 'POST');
    }
}

class SpyOperationsController extends OperationsController {
    public $restoreInvoked = false;
    private $config;

    public function __construct(OperationRepository $operationRepository, array $config = []) {
        parent::__construct($operationRepository);
        $this->config = $config;
    }

    protected function performRestore(Operation $operation) {
        $this->restoreInvoked = true;
        $personId = $this->config['restoredPersonId'] ?? 99;

        return [
            'restored' => ['c_personid' => $personId, 'field' => 'restored'],
            'previous' => ['c_personid' => $personId, 'field' => 'previous'],
        ];
    }
}

class FakeOperationRepository extends OperationRepository {
    public $storeCalls = [];

    public function store($user_id, $c_personid, $op_type, $resource, $resource_id, $resource_data, $ori = '', $crowdsourcing_status = 0) {
        $this->storeCalls[] = func_get_args();
    }
}
