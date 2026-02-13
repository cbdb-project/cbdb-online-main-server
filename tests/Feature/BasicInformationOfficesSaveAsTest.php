<?php

namespace Tests\Feature;

use App\Http\Controllers\BasicInformationOfficesController;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BasicInformationOfficesSaveAsTest extends TestCase {
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
            $table->integer('user_id')->nullable();
            $table->integer('c_personid')->nullable();
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->timestamps();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('operations');
        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('POSTING_DATA');

        parent::tearDown();
    }

    #[Test]
    public function testSaveAsCreatesPostingOfficeAddressAndOperationRecords(): void {
        DB::table('POSTING_DATA')->insert([
            'c_posting_id' => 1,
            'c_personid' => 999,
        ]);

        $user = new class implements Authenticatable {
            public $id = 1;
            public $name = 'Testing Admin';

            public function canWriteDirectly(): bool {
                return true;
            }

            public function getAuthIdentifierName() {
                return 'id';
            }

            public function getAuthIdentifier() {
                return $this->id;
            }

            public function getAuthPasswordName() {
                return 'password';
            }

            public function getAuthPassword() {
                return '';
            }

            public function getRememberToken() {
                return null;
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName() {
                return 'remember_token';
            }
        };

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(1);

        $biogMainRepository = \Mockery::mock(BiogMainRepository::class);
        $biogMainRepository->shouldReceive('officeById')->once()->with('30-1')->andReturn([
            'row' => (object) [
                'c_personid' => 999,
                'c_posting_id' => 1,
                'c_office_id' => 30,
                'c_fy_intercalary' => 0,
                'c_ly_intercalary' => 0,
                'c_source' => 0,
            ],
            'addr_str' => [
                [5, 'Addr A'],
                [6, 'Addr B'],
            ],
        ]);

        $controller = new BasicInformationOfficesController(
            $biogMainRepository,
            new OperationRepository(),
            new ToolsRepository()
        );

        $response = $controller->saveas(555, '30-1');

        $this->assertDatabaseHas('POSTING_DATA', [
            'c_posting_id' => 2,
            'c_personid' => 555,
        ]);
        $this->assertDatabaseHas('POSTED_TO_OFFICE_DATA', [
            'c_posting_id' => 2,
            'c_personid' => 555,
            'c_office_id' => 30,
        ]);
        $this->assertDatabaseHas('POSTED_TO_ADDR_DATA', [
            'c_posting_id' => 2,
            'c_personid' => 555,
            'c_office_id' => 30,
            'c_addr_id' => 5,
        ]);
        $this->assertDatabaseHas('POSTED_TO_ADDR_DATA', [
            'c_posting_id' => 2,
            'c_personid' => 555,
            'c_office_id' => 30,
            'c_addr_id' => 6,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => '30-2',
            'op_type' => 1,
        ]);
        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_ADDR_DATA',
            'resource_id' => '30-2',
            'op_type' => 1,
        ]);

        $this->assertNotNull($response);
    }
}
