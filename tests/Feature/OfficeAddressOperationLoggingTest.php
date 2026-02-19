<?php

namespace Tests\Feature;

use App\Repositories\BiogMainRepository;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OfficeAddressOperationLoggingTest extends TestCase {
    protected function setUp(): void {
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
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id')->primary();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
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

        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->char('operation_id', 26);
            $table->json('row_pk');
            $table->string('row_pk_text', 512);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });

        Auth::guard()->setUser(new GenericUser(['id' => 1, 'name' => 'Testing Admin']));
    }

    #[Test]
    public function testAddressChangeCreatesStructuredOperationRecord(): void {
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
        $this->assertEquals('c_office_id=71313&c_posting_id=312754', $operation->resource_id);
        $this->assertNotNull($operation->resource_original);

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

    #[Test]
    public function testAddressChangeUpdatesAuditFields(): void {
        DB::table('POSTING_DATA')->insert([
            'c_personid' => 900001,
            'c_posting_id' => 880001,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-01 00:00:00',
        ]);

        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 900001,
            'c_office_id' => 77771,
            'c_posting_id' => 880001,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 900001,
            'c_posting_id' => 880001,
            'c_office_id' => 77771,
            'c_addr_id' => 45,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-01-02 00:00:00',
        ]);

        Auth::guard()->setUser(new GenericUser(['id' => 2, 'name' => 'Updater A']));

        $request = new Request([
            '_id' => 900001,
            '_postingid' => 880001,
            '_officeid' => 77771,
            'c_office_id' => 77771,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr' => [46],
        ]);

        $repository = new BiogMainRepository();
        $repository->officeUpdateById($request, 880001, 900001);

        $posting = DB::table('POSTING_DATA')->where('c_posting_id', 880001)->first();
        $this->assertSame('Updater A', $posting->c_modified_by);
        $this->assertNotNull($posting->c_modified_date);

        $address = DB::table('POSTED_TO_ADDR_DATA')->where([
            'c_personid' => 900001,
            'c_posting_id' => 880001,
            'c_office_id' => 77771,
            'c_addr_id' => 46,
        ])->first();

        $this->assertSame('Updater A', $address->c_created_by);
        $this->assertSame('Updater A', $address->c_modified_by);
        $this->assertNotNull($address->c_created_date);
        $this->assertNotNull($address->c_modified_date);

        $operation = DB::table('operations')->orderByDesc('id')->first();
        $this->assertSame('POSTED_TO_ADDR_DATA', $operation->resource);
        $this->assertNotNull($operation->resource_original);
        $payload = json_decode($operation->resource_data, true);
        $original = json_decode($operation->resource_original, true);

        $this->assertSame(46, $payload['rows'][0]['c_addr_id']);
        $this->assertSame(45, $original['rows'][0]['c_addr_id']);
    }

    #[Test]
    public function testOfficeUpdateDoesNotResetAddressTimestampsWhenUnchanged(): void {
        DB::table('POSTING_DATA')->insert([
            'c_personid' => 910002,
            'c_posting_id' => 880002,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-05-01 00:00:00',
            'c_modified_by' => 'Seeder',
            'c_modified_date' => '2023-05-02 00:00:00',
        ]);

        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 910002,
            'c_office_id' => 77772,
            'c_posting_id' => 880002,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 910002,
            'c_posting_id' => 880002,
            'c_office_id' => 77772,
            'c_addr_id' => 25,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-05-01 00:00:00',
            'c_modified_by' => 'Seeder',
            'c_modified_date' => '2023-05-02 00:00:00',
        ]);

        Auth::guard()->setUser(new GenericUser(['id' => 3, 'name' => 'Updater B']));

        $request = new Request([
            '_id' => 910002,
            '_postingid' => 880002,
            '_officeid' => 77772,
            'c_office_id' => 77772,
            'c_fy_intercalary' => 1,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr' => [25],
        ]);

        $repository = new BiogMainRepository();
        $repository->officeUpdateById($request, 880002, 910002);

        $address = DB::table('POSTED_TO_ADDR_DATA')->where([
            'c_personid' => 910002,
            'c_posting_id' => 880002,
            'c_office_id' => 77772,
            'c_addr_id' => 25,
        ])->first();

        $this->assertSame('2023-05-02 00:00:00', $address->c_modified_date);
        $this->assertSame('Seeder', $address->c_modified_by);

        $operations = DB::table('operations')->pluck('resource');
        $this->assertContains('POSTED_TO_OFFICE_DATA', $operations);
        $this->assertNotContains('POSTED_TO_ADDR_DATA', $operations);
    }

    #[Test]
    public function testPartialAddressChangeDoesNotTouchUnchangedAddressTimestamp(): void {
        DB::table('POSTING_DATA')->insert([
            'c_personid' => 920003,
            'c_posting_id' => 880003,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-06-01 00:00:00',
        ]);

        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 920003,
            'c_office_id' => 77773,
            'c_posting_id' => 880003,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 920003,
            'c_posting_id' => 880003,
            'c_office_id' => 77773,
            'c_addr_id' => 25,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-06-01 00:00:00',
            'c_modified_by' => 'Seeder',
            'c_modified_date' => '2023-06-02 00:00:00',
        ]);
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 920003,
            'c_posting_id' => 880003,
            'c_office_id' => 77773,
            'c_addr_id' => 26,
            'c_created_by' => 'Seeder',
            'c_created_date' => '2023-06-01 00:00:00',
            'c_modified_by' => 'Seeder',
            'c_modified_date' => '2023-06-03 00:00:00',
        ]);

        Auth::guard()->setUser(new GenericUser(['id' => 4, 'name' => 'Updater C']));

        $request = new Request([
            '_id' => 920003,
            '_postingid' => 880003,
            '_officeid' => 77773,
            'c_office_id' => 77773,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr' => [25, 27],
        ]);

        $repository = new BiogMainRepository();
        $repository->officeUpdateById($request, 880003, 920003);

        $unchangedAddress = DB::table('POSTED_TO_ADDR_DATA')->where([
            'c_personid' => 920003,
            'c_posting_id' => 880003,
            'c_office_id' => 77773,
            'c_addr_id' => 25,
        ])->first();

        $newAddress = DB::table('POSTED_TO_ADDR_DATA')->where([
            'c_personid' => 920003,
            'c_posting_id' => 880003,
            'c_office_id' => 77773,
            'c_addr_id' => 27,
        ])->first();

        $this->assertNotNull($unchangedAddress);
        $this->assertSame('Seeder', $unchangedAddress->c_modified_by);
        $this->assertSame('2023-06-02 00:00:00', $unchangedAddress->c_modified_date);

        $this->assertNotNull($newAddress);
        $this->assertSame('Updater C', $newAddress->c_modified_by);
        $this->assertNotNull($newAddress->c_modified_date);

        $this->assertDatabaseMissing('POSTED_TO_ADDR_DATA', [
            'c_personid' => 920003,
            'c_posting_id' => 880003,
            'c_office_id' => 77773,
            'c_addr_id' => 26,
        ]);
    }

    #[Test]
    public function testAddressChangeOnlyTouchesTargetOffice(): void {
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

    #[Test]
    public function testAddressUpdateKeepsOtherOfficeRows(): void {
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

    #[Test]
    public function testAddressChangeAuditPayloadUsesSharedOperationId(): void {
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 300000,
            'c_office_id' => 82001,
            'c_posting_id' => 777001,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 300000,
            'c_posting_id' => 777001,
            'c_office_id' => 82001,
            'c_addr_id' => 600,
        ]);

        $request = new Request([
            '_id' => 300000,
            '_postingid' => 777001,
            '_officeid' => 82001,
            'c_office_id' => 82001,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
            'c_addr' => [601],
        ]);

        $repository = new BiogMainRepository();
        $repository->officeUpdateById($request, 777001, 300000);

        $operation = DB::table('operations')
            ->where('resource', 'POSTED_TO_ADDR_DATA')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($operation);

        $logs = DB::table('audit_log')
            ->where('table_name', 'POSTED_TO_ADDR_DATA')
            ->orderBy('id')
            ->get();

        $this->assertSame(2, $logs->count());
        $this->assertSame(1, $logs->pluck('operation_id')->unique()->count());
        $this->assertSame((string) $operation->id, $logs->first()->operation_id);

        $deleteLog = $logs->firstWhere('operation', 'DELETE');
        $insertLog = $logs->firstWhere('operation', 'INSERT');

        $this->assertNotNull($deleteLog);
        $this->assertNotNull($insertLog);
        $this->assertSame('c_addr_id=600&c_office_id=82001&c_posting_id=777001', $deleteLog->row_pk_text);
        $this->assertSame('c_addr_id=601&c_office_id=82001&c_posting_id=777001', $insertLog->row_pk_text);

        $this->assertNull($insertLog->old_data);
        $this->assertNull($deleteLog->new_data);

        $insertNewData = json_decode($insertLog->new_data, true);
        $deleteOldData = json_decode($deleteLog->old_data, true);
        $this->assertSame(601, $insertNewData['c_addr_id']);
        $this->assertSame(600, $deleteOldData['c_addr_id']);
    }
}
