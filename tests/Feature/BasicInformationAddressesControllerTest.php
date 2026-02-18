<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BasicInformationAddressesControllerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->json('settings')->nullable();
            $table->string('avatar')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::dropIfExists('BIOG_ADDR_DATA');
        Schema::create('BIOG_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_addr_id');
            $table->integer('c_addr_type');
            $table->integer('c_sequence');
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_ly_intercalary')->default(0);
            $table->string('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::dropIfExists('operations');
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid');
            $table->integer('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });

        Schema::dropIfExists('audit_log');
        Schema::create('audit_log', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->string('table_name');
            $table->string('operation');
            $table->string('actor_type');
            $table->string('actor_id');
            $table->string('operation_id');
            $table->json('row_pk');
            $table->string('row_pk_text');
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_ADDR_DATA');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    #[Test]
    public function testUpdateQueryWritesAuditPayloadWithOperationId(): void {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'addr-update@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_ADDR_DATA')->insert([
            'c_personid' => 1,
            'c_addr_id' => 10,
            'c_addr_type' => 2,
            'c_sequence' => 1,
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_notes' => 'old-note',
        ]);

        $response = $this->actingAs($user)->put(
            '/basicinformation/1/addresses/update?c_personid=1&c_addr_id=10&c_addr_type=2&c_sequence=1',
            [
                'c_personid' => 1,
                'c_addr_id' => 10,
                'c_addr_type' => 2,
                'c_sequence' => 1,
                'c_fy_intercalary' => 0,
                'c_ly_intercalary' => 1,
                'c_notes' => 'new-note',
                '__proposal_comment' => 'update-addr',
            ]
        );

        $response->assertStatus(302);

        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1,
            'c_addr_id' => 10,
            'c_addr_type' => 2,
            'c_sequence' => 1,
            'c_notes' => 'new-note',
            'c_ly_intercalary' => 1,
        ]);

        $operation = DB::table('operations')->orderByDesc('id')->first();
        $this->assertNotNull($operation);
        $this->assertSame(3, (int) $operation->op_type);
        $this->assertSame('BIOG_ADDR_DATA', $operation->resource);

        $log = DB::table('audit_log')->where('table_name', 'BIOG_ADDR_DATA')->where('operation', 'UPDATE')->first();
        $this->assertNotNull($log);
        $this->assertSame((string) $operation->id, $log->operation_id);
        $this->assertSame('c_personid=1&c_addr_id=10&c_addr_type=2&c_sequence=1', $log->row_pk_text);

        $oldData = json_decode($log->old_data, true);
        $newData = json_decode($log->new_data, true);
        $this->assertSame('old-note', $oldData['c_notes']);
        $this->assertSame('new-note', $newData['c_notes']);
        $this->assertSame(0, $oldData['c_ly_intercalary']);
        $this->assertSame(1, $newData['c_ly_intercalary']);
    }
}
