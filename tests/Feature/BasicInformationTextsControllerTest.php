<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BasicInformationTextsControllerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->useLegacyPersonForms(); // 本類測 legacy Blade CRUD 行為，撥回 flag=old 越過下架閘門

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

        Schema::dropIfExists('BIOG_TEXT_DATA');
        Schema::create('BIOG_TEXT_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->integer('c_role_id')->default(0);
            $table->integer('c_source')->nullable();
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
        Schema::dropIfExists('BIOG_TEXT_DATA');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    #[Test]
    public function testDestroyQueryDeletesRowAndWritesAuditLog(): void {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'texts-delete@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_TEXT_DATA')->insert([
            'c_personid' => 1,
            'c_textid' => 100,
            'c_role_id' => 0,
            'c_source' => 0,
        ]);

        $response = $this->actingAs($user)->delete(
            '/basicinformation/1/texts/delete?c_personid=1&c_textid=100&c_role_id=0'
        );

        $response->assertStatus(302);

        $this->assertDatabaseMissing('BIOG_TEXT_DATA', [
            'c_personid' => 1,
            'c_textid' => 100,
            'c_role_id' => 0,
        ]);

        $this->assertDatabaseHas('operations', [
            'c_personid' => 1,
            'op_type' => 4,
            'resource' => 'BIOG_TEXT_DATA',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'BIOG_TEXT_DATA',
            'operation' => 'DELETE',
            'actor_id' => (string) $user->id,
        ]);

        $log = DB::table('audit_log')->first();
        $this->assertNotNull($log->operation_id);
        $this->assertNull($log->new_data);
        $oldData = json_decode($log->old_data, true);
        $this->assertEquals(100, $oldData['c_textid']);
    }

    #[Test]
    public function testUpdateQueryWritesAuditPayloadWithOperationId(): void {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'texts-update@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_TEXT_DATA')->insert([
            'c_personid' => 1,
            'c_textid' => 100,
            'c_role_id' => 0,
            'c_source' => 9,
        ]);

        $response = $this->actingAs($user)->put(
            '/basicinformation/1/texts/update?c_personid=1&c_textid=100&c_role_id=0',
            [
                'c_personid' => 1,
                'c_textid' => 100,
                'c_role_id' => 0,
                'c_source' => 200,
                '__proposal_comment' => 'update-source',
            ]
        );

        $response->assertStatus(302);

        $this->assertDatabaseHas('BIOG_TEXT_DATA', [
            'c_personid' => 1,
            'c_textid' => 100,
            'c_role_id' => 0,
            'c_source' => 200,
        ]);

        $operation = DB::table('operations')->orderByDesc('id')->first();
        $this->assertNotNull($operation);
        $this->assertSame(3, (int) $operation->op_type);
        $this->assertSame('BIOG_TEXT_DATA', $operation->resource);

        $log = DB::table('audit_log')->where('table_name', 'BIOG_TEXT_DATA')->where('operation', 'UPDATE')->first();
        $this->assertNotNull($log);
        $this->assertSame((string) $operation->id, $log->operation_id);
        $this->assertSame('c_personid=1&c_textid=100&c_role_id=0', $log->row_pk_text);

        $oldData = json_decode($log->old_data, true);
        $newData = json_decode($log->new_data, true);
        $this->assertSame(9, $oldData['c_source']);
        $this->assertSame(200, $newData['c_source']);
    }

    #[Test]
    public function testUpdateQueryReturns400WhenPathPersonIdMismatchesQueryPk(): void {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'texts-update-mismatch@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_TEXT_DATA')->insert([
            'c_personid' => 2,
            'c_textid' => 100,
            'c_role_id' => 0,
            'c_source' => 9,
        ]);

        $response = $this->actingAs($user)->put(
            '/basicinformation/1/texts/update?c_personid=2&c_textid=100&c_role_id=0',
            [
                'c_source' => 200,
            ]
        );

        $response->assertStatus(400);

        $this->assertDatabaseHas('BIOG_TEXT_DATA', [
            'c_personid' => 2,
            'c_textid' => 100,
            'c_role_id' => 0,
            'c_source' => 9,
        ]);
        $this->assertDatabaseCount('operations', 0);
        $this->assertDatabaseCount('audit_log', 0);
    }

    #[Test]
    public function testStoreDuplicateTextShowsErrorAndDoesNotInsertAgain(): void {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'texts-duplicate@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_TEXT_DATA')->insert([
            'c_personid' => 1,
            'c_textid' => 100,
            'c_role_id' => 0,
            'c_source' => 0,
        ]);

        $response = $this->actingAs($user)->post('/basicinformation/1/texts', [
            'c_textid' => 100,
            'c_role_id' => 0,
            'c_source' => 0,
        ]);

        $response->assertStatus(302);
        $this->assertEquals(1, DB::table('BIOG_TEXT_DATA')->count());
    }

    #[Test]
    public function testDestroyQueryReturns404WhenRowDoesNotExist(): void {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'texts-delete-missing@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        $response = $this->actingAs($user)->delete(
            '/basicinformation/1/texts/delete?c_personid=1&c_textid=999&c_role_id=0'
        );

        $response->assertStatus(404);
    }
}
