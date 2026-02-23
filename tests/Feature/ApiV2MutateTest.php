<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutateTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->integer('c_personid')->default(0);
            $table->integer('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->longText('resource_data')->nullable();
            $table->longText('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
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
            $table->string('operation_id', 64);
            $table->text('row_pk');
            $table->string('row_pk_text', 512)->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
        });

        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->nullable();
            $table->string('c_alt_name_chn');
            $table->string('c_alt_name')->nullable();
            $table->integer('c_alt_name_type_code');
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeActiveUser(): User {
        return User::create([
            'name' => 'tester',
            'email' => 'tester@example.com',
            'confirmation_token' => 'token-123',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    #[Test]
    public function testSessionAuthenticatedUserCanMutateAltnameSequenceViaApiV2Mutate() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 11,
            'c_sequence' => 1,
            'c_alt_name_chn' => '子止',
            'c_alt_name' => 'Zi Zhi',
            'c_alt_name_type_code' => 4,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 11,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 11,
                    'c_alt_name_chn' => '子止',
                    'c_alt_name_type_code' => 4,
                ],
            ],
            'changes' => [
                'c_sequence' => 2,
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'altnames',
                'mode' => 'direct',
                'operation' => 'update',
            ]);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 11,
            'c_alt_name_chn' => '子止',
            'c_alt_name_type_code' => 4,
            'c_sequence' => 2,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'ALTNAME_DATA',
            'c_personid' => 11,
            'op_type' => 3,
        ]);
    }

    #[Test]
    public function testRejectsAltnameMutationWhenPersonIdDoesNotMatchTargetPk() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 11,
            'c_sequence' => 1,
            'c_alt_name_chn' => '子止',
            'c_alt_name' => 'Zi Zhi',
            'c_alt_name_type_code' => 4,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 12,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 11,
                    'c_alt_name_chn' => '子止',
                    'c_alt_name_type_code' => 4,
                ],
            ],
            'changes' => [
                'c_sequence' => 9,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
            ]);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 11,
            'c_alt_name_chn' => '子止',
            'c_alt_name_type_code' => 4,
            'c_sequence' => 1,
        ]);

        $this->assertDatabaseMissing('operations', [
            'resource' => 'ALTNAME_DATA',
            'c_personid' => 12,
            'op_type' => 3,
        ]);
    }
}
