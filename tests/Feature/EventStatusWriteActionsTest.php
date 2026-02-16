<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventStatusWriteActionsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        $this->createTables();
        $this->actingAs($this->createWriterUser());
    }

    protected function tearDown(): void {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('EVENTS_ADDR');
        Schema::dropIfExists('EVENTS_DATA');
        Schema::dropIfExists('STATUS_DATA');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    #[Test]
    public function status_store_writes_status_data_and_logs(): void {
        $response = $this->post('/basicinformation/1/statuses', [
            'c_sequence' => 1,
            'c_status_code' => 2,
            'c_source' => -999,
            'action' => 'save',
            '__proposal_comment' => 'should be ignored',
        ]);

        $response->assertRedirect(route('basicinformation.statuses.edit.query', [
            'id' => 1,
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_status_code' => 2,
        ]));

        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_status_code' => 2,
            'c_source' => 0,
            'c_created_by' => 'Writer User',
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'STATUS_DATA',
            'resource_id' => 'c_personid=1&c_sequence=1&c_status_code=2',
            'op_type' => 1,
        ]);

        $op = \DB::table('operations')
            ->where('resource', 'STATUS_DATA')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($op);
        $opPayload = json_decode($op->resource_data, true);
        $this->assertSame('should be ignored', $opPayload['__note'] ?? null);

        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'STATUS_DATA',
            'operation' => 'INSERT',
            'row_pk_text' => 'c_personid=1&c_sequence=1&c_status_code=2',
        ]);
    }

    #[Test]
    public function status_update_query_updates_record_and_logs(): void {
        $this->seedStatusRow();

        $response = $this->patch(route('basicinformation.statuses.update.query', [
            'id' => 1,
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_status_code' => 2,
        ]), [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_status_code' => 2,
            'c_source' => 10,
            'action' => 'save',
            '__proposal_comment' => 'should be ignored',
        ]);

        $response->assertRedirect(route('basicinformation.statuses.edit.query', [
            'id' => 1,
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_status_code' => 2,
        ]));

        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_status_code' => 2,
            'c_source' => 10,
            'c_modified_by' => 'Writer User',
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'STATUS_DATA',
            'resource_id' => 'c_personid=1&c_sequence=1&c_status_code=2',
            'op_type' => 3,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'STATUS_DATA',
            'operation' => 'UPDATE',
            'row_pk_text' => 'c_personid=1&c_sequence=1&c_status_code=2',
        ]);
    }

    #[Test]
    public function status_destroy_query_deletes_record_and_logs(): void {
        $this->seedStatusRow();

        $response = $this->delete(route('basicinformation.statuses.destroy.query', [
            'id' => 1,
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_status_code' => 2,
        ]));

        $response->assertRedirect(route('basicinformation.statuses.index', ['basicinformation' => 1]));

        $this->assertDatabaseMissing('STATUS_DATA', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_status_code' => 2,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'STATUS_DATA',
            'resource_id' => 'c_personid=1&c_sequence=1&c_status_code=2',
            'op_type' => 4,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'STATUS_DATA',
            'operation' => 'DELETE',
            'row_pk_text' => 'c_personid=1&c_sequence=1&c_status_code=2',
        ]);
    }

    #[Test]
    public function event_store_writes_event_and_addresses_and_logs(): void {
        $response = $this->post('/basicinformation/1/events', [
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_intercalary' => 1,
            'c_source' => 9,
            'c_addr_id' => [100, -999],
            'action' => 'save',
            '__proposal_comment' => 'should be ignored',
        ]);

        $response->assertRedirect(route('basicinformation.events.edit.query', [
            'id' => 1,
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
        ]));

        $this->assertDatabaseHas('EVENTS_DATA', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_intercalary' => 1,
            'c_source' => 9,
            'c_created_by' => 'Writer User',
        ]);

        $this->assertDatabaseHas('EVENTS_ADDR', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_addr_id' => 100,
        ]);

        $this->assertDatabaseHas('EVENTS_ADDR', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_addr_id' => 0,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'EVENTS_DATA',
            'resource_id' => 'c_personid=1&c_sequence=1&c_event_code=50',
            'op_type' => 1,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'EVENTS_DATA',
            'operation' => 'INSERT',
            'row_pk_text' => 'c_personid=1&c_sequence=1&c_event_code=50',
        ]);
    }

    #[Test]
    public function event_update_query_replaces_addr_rows_and_logs(): void {
        $this->seedEventRow();

        $response = $this->patch(route('basicinformation.events.update.query', [
            'id' => 1,
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
        ]), [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_intercalary' => 0,
            'c_source' => 10,
            'c_addr_id' => [200],
            'action' => 'save',
            '__proposal_comment' => 'should be ignored',
        ]);

        $response->assertRedirect(route('basicinformation.events.edit.query', [
            'id' => 1,
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
        ]));

        $this->assertDatabaseHas('EVENTS_DATA', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_source' => 10,
            'c_modified_by' => 'Writer User',
        ]);

        $this->assertDatabaseMissing('EVENTS_ADDR', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_addr_id' => 99,
        ]);

        $this->assertDatabaseHas('EVENTS_ADDR', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_addr_id' => 200,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'EVENTS_DATA',
            'resource_id' => 'c_personid=1&c_sequence=1&c_event_code=50',
            'op_type' => 3,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'EVENTS_DATA',
            'operation' => 'UPDATE',
            'row_pk_text' => 'c_personid=1&c_sequence=1&c_event_code=50',
        ]);
    }

    #[Test]
    public function event_destroy_query_deletes_event_and_addresses_and_logs(): void {
        $this->seedEventRow();

        $response = $this->delete(route('basicinformation.events.destroy.query', [
            'id' => 1,
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
        ]));

        $response->assertRedirect(route('basicinformation.events.index', ['basicinformation' => 1]));

        $this->assertDatabaseMissing('EVENTS_DATA', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
        ]);

        $this->assertDatabaseMissing('EVENTS_ADDR', [
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'EVENTS_DATA',
            'resource_id' => 'c_personid=1&c_sequence=1&c_event_code=50',
            'op_type' => 4,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'EVENTS_DATA',
            'operation' => 'DELETE',
            'row_pk_text' => 'c_personid=1&c_sequence=1&c_event_code=50',
        ]);
    }

    private function createTables(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('STATUS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence');
            $table->integer('c_status_code');
            $table->integer('c_source')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
            $table->primary(['c_personid', 'c_sequence', 'c_status_code']);
        });

        Schema::create('EVENTS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence');
            $table->integer('c_event_code');
            $table->integer('c_intercalary')->default(0);
            $table->integer('c_source')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
            $table->primary(['c_personid', 'c_event_code', 'c_sequence']);
        });

        Schema::create('EVENTS_ADDR', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence');
            $table->integer('c_event_code');
            $table->integer('c_addr_id');
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('c_personid');
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->json('resource_data');
            $table->json('resource_original')->nullable();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
            $table->timestamps();
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
    }

    private function createWriterUser(): User {
        return User::create([
            'name' => 'Writer User',
            'email' => 'writer@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'token',
            'is_active' => 1,
            'is_admin' => User::ROLE_EXPERT,
        ]);
    }

    private function seedStatusRow(): void {
        \DB::table('STATUS_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_status_code' => 2,
            'c_source' => 5,
            'c_created_by' => 'seed',
        ]);
    }

    private function seedEventRow(): void {
        \DB::table('EVENTS_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_intercalary' => 1,
            'c_source' => 8,
            'c_created_by' => 'seed',
        ]);

        \DB::table('EVENTS_ADDR')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_addr_id' => 99,
        ]);
    }
}
