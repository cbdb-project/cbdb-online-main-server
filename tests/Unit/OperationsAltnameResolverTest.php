<?php

namespace Tests\Unit;

use App\Http\Controllers\OperationsController;
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
                'c_alt_name_type_code' => 10,
            ]),
        ];

        $row = $controller->resolveAltname($operationPayload);

        $this->assertNotNull($row);
        $this->assertSame('張-一', $row->c_alt_name_chn);
        $this->assertSame('Zi Jing', $row->c_alt_name);
    }
}
