<?php

namespace Tests\Feature;

use App\Repositories\BiogMainRepository;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OfficePostingStoreTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        Schema::create('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_posting_id')->primary();
            $table->integer('c_personid');
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_posting_id')->primary();
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_ly_intercalary')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id');
            $table->integer('c_addr_id');
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid');
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->json('resource_data');
            $table->json('resource_original')->nullable();
            $table->timestamps();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
        });

        Auth::guard()->setUser(new GenericUser(['id' => 1, 'name' => 'Testing Admin']));
    }

    protected function tearDown(): void {
        Schema::dropIfExists('operations');
        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('POSTING_DATA');

        parent::tearDown();
    }

    private function makeRequest(array $overrides = []): Request {
        $payload = array_merge([
            '_token' => 'token',
            'c_addr' => [0],
            'c_office_id' => 10,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ], $overrides);

        return new Request($payload);
    }

    #[Test]
    public function testOfficeStoreCreatesPostingRecordsWithinTransaction(): void {
        $repository = new BiogMainRepository();
        $request = $this->makeRequest();

        $response = $repository->officeStoreById($request, 123);

        $this->assertSame('10-1', $response);

        $this->assertDatabaseHas('POSTING_DATA', [
            'c_posting_id' => 1,
            'c_personid' => 123,
        ]);

        $this->assertDatabaseHas('POSTED_TO_OFFICE_DATA', [
            'c_posting_id' => 1,
            'c_personid' => 123,
            'c_office_id' => 10,
        ]);

        $this->assertDatabaseHas('POSTED_TO_ADDR_DATA', [
            'c_posting_id' => 1,
            'c_personid' => 123,
            'c_office_id' => 10,
            'c_addr_id' => 0,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => '10-1',
            'op_type' => 1,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_ADDR_DATA',
            'resource_id' => '10-1',
            'op_type' => 1,
        ]);
    }

    #[Test]
    public function testOfficeStoreRollsBackPostingDataWhenAnExceptionOccurs(): void {
        $repository = Mockery::mock(BiogMainRepository::class)->makePartial();
        $repository->shouldAllowMockingProtectedMethods();
        $repository->shouldReceive('insertAddr')->andThrow(new \RuntimeException('addr failure'));

        $request = $this->makeRequest();

        try {
            $repository->officeStoreById($request, 456);
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('addr failure', $e->getMessage());
        }

        $this->assertSame(0, DB::table('POSTING_DATA')->count());
        $this->assertSame(0, DB::table('POSTED_TO_OFFICE_DATA')->count());
        $this->assertSame(0, DB::table('operations')->count());

        $realRepo = new BiogMainRepository();
        $realRepo->officeStoreById($this->makeRequest(), 456);
        $this->assertDatabaseHas('POSTING_DATA', [
            'c_posting_id' => 1,
            'c_personid' => 456,
        ]);
    }

    #[Test]
    public function testOfficeUpdateCreatesOperationLog(): void {
        $repo = new BiogMainRepository();
        $repo->officeStoreById($this->makeRequest([
            'c_office_id' => 20,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
        ]), 789);

        $updateRequest = new Request([
            '_method' => 'PATCH',
            '_token' => 'token',
            'c_addr' => [1],
            '_id' => 789,
            '_postingid' => 1,
            '_officeid' => 0,
            'c_personid' => 789,
            'c_office_id' => 20,
            'c_fy_intercalary' => 1,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        $repo->officeUpdateById($updateRequest, 789, 789);

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => '20-1',
            'op_type' => 3,
        ]);
    }

    #[Test]
    public function testOfficeDeleteCreatesOperationLogAndCleansPostingTables(): void {
        $repo = new BiogMainRepository();
        $repo->officeStoreById($this->makeRequest([
            'c_office_id' => 30,
            'c_addr' => [5],
        ]), 555);

        $repo->officeDeleteById('30-1', 555);

        $this->assertDatabaseMissing('POSTING_DATA', ['c_posting_id' => 1]);
        $this->assertDatabaseMissing('POSTED_TO_OFFICE_DATA', ['c_posting_id' => 1]);
        $this->assertDatabaseMissing('POSTED_TO_ADDR_DATA', ['c_posting_id' => 1]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => '30-1',
            'op_type' => 4,
        ]);
    }
}
