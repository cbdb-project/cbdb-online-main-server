<?php

namespace Tests\Feature;

use App\Repositories\BiogMainRepository;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class OfficeAddressOperationLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required for this test.');
        }

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_posting_id')->primary();
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_ly_intercalary')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id');
            $table->integer('c_addr_id');
        });

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
    }

    public function testAddressChangeCreatesStructuredOperationRecord(): void
    {
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 407841,
            'c_office_id' => 71313,
            'c_posting_id' => 312754,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 407841,
            'c_posting_id' => 312754,
            'c_office_id' => 71313,
            'c_addr_id' => 3,
        ]);

        $request = new Request([
            '_id' => 407841,
            '_postingid' => 312754,
            '_officeid' => 71313,
            'c_office_id' => 71313,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr' => [2],
        ]);

        $repository = new BiogMainRepository();
        $repository->officeUpdateById($request, 312754, 407841);

        $operation = DB::table('operations')->orderByDesc('id')->first();
        $this->assertNotNull($operation);
        $this->assertEquals('POSTED_TO_ADDR_DATA', $operation->resource);
        $this->assertEquals('71313-312754', $operation->resource_id);

        $after = json_decode($operation->resource_data, true);
        $before = json_decode($operation->resource_original, true);

        $this->assertSame([
            'rows' => [
                [
                    'c_personid' => 407841,
                    'c_posting_id' => 312754,
                    'c_office_id' => 71313,
                    'c_addr_id' => 2,
                ],
            ],
        ], $after);

        $this->assertSame([
            'rows' => [
                [
                    'c_personid' => 407841,
                    'c_posting_id' => 312754,
                    'c_office_id' => 71313,
                    'c_addr_id' => 3,
                ],
            ],
        ], $before);
    }

    public function testAddressChangeOnlyTouchesTargetOffice(): void
    {
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 100000,
            'c_office_id' => 80001,
            'c_posting_id' => 555001,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 100000,
            'c_posting_id' => 555001,
            'c_office_id' => 80001,
            'c_addr_id' => 111,
        ]);
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 100000,
            'c_posting_id' => 555001,
            'c_office_id' => 90002,
            'c_addr_id' => 222,
        ]);

        $request = new Request([
            '_id' => 100000,
            '_postingid' => 555001,
            '_officeid' => 80001,
            'c_office_id' => 80001,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr' => [333],
        ]);

        $repository = new BiogMainRepository();
        $repository->officeUpdateById($request, 555001, 100000);

        $operation = DB::table('operations')->orderByDesc('id')->first();
        $payload = json_decode($operation->resource_data, true);
        $original = json_decode($operation->resource_original, true);

        $this->assertSame([
            'rows' => [[
                'c_personid' => 100000,
                'c_posting_id' => 555001,
                'c_office_id' => 80001,
                'c_addr_id' => 333,
            ]],
        ], $payload);

        $this->assertSame([
            'rows' => [[
                'c_personid' => 100000,
                'c_posting_id' => 555001,
                'c_office_id' => 80001,
                'c_addr_id' => 111,
            ]],
        ], $original);

        $this->assertDatabaseHas('POSTED_TO_ADDR_DATA', [
            'c_personid' => 100000,
            'c_posting_id' => 555001,
            'c_office_id' => 90002,
            'c_addr_id' => 222,
        ]);
    }

    public function testAddressUpdateKeepsOtherOfficeRows(): void
    {
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 200000,
            'c_office_id' => 81001,
            'c_posting_id' => 666001,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 200000,
            'c_posting_id' => 666001,
            'c_office_id' => 81001,
            'c_addr_id' => 400,
        ]);

        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 200000,
            'c_posting_id' => 666001,
            'c_office_id' => 91002,
            'c_addr_id' => 500,
        ]);

        $request = new Request([
            '_id' => 200000,
            '_postingid' => 666001,
            '_officeid' => 81001,
            'c_office_id' => 81001,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr' => [401],
        ]);

        $repository = new BiogMainRepository();
        $repository->officeUpdateById($request, 666001, 200000);

        $operation = DB::table('operations')->orderByDesc('id')->first();
        $payload = json_decode($operation->resource_data, true);
        $original = json_decode($operation->resource_original, true);

        $this->assertSame([
            'rows' => [[
                'c_personid' => 200000,
                'c_posting_id' => 666001,
                'c_office_id' => 81001,
                'c_addr_id' => 401,
            ]],
        ], $payload);

        $this->assertSame([
            'rows' => [[
                'c_personid' => 200000,
                'c_posting_id' => 666001,
                'c_office_id' => 81001,
                'c_addr_id' => 400,
            ]],
        ], $original);

        $this->assertDatabaseHas('POSTED_TO_ADDR_DATA', [
            'c_personid' => 200000,
            'c_posting_id' => 666001,
            'c_office_id' => 91002,
            'c_addr_id' => 500,
        ]);
    }
}
