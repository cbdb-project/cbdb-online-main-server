<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2GetTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $this->app['env'] = 'testing';
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Route::middleware(['web', 'auth.optional'])->match(['get', 'post'], 'api/v2/get', [\App\Http\Controllers\Api\MutationController::class, 'get']);

        $this->createUsersTable();
        $this->createBiogMainTable();
        $this->createAltnameTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function createUsersTable(): void {
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
    }

    protected function createBiogMainTable(): void {
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
        });
    }

    protected function createAltnameTable(): void {
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->string('c_alt_name_chn', 255)->default('');
            $table->integer('c_alt_name_type_code')->default(0);
            $table->string('c_alt_name', 255)->nullable();
            $table->integer('c_sequence')->default(0);
            $table->primary(['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code']);
        });
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'get-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function seedAltname(): void {
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1000,
            'c_name_chn' => '杜甫',
            'c_name' => 'Du Fu',
        ]);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => 4,
            'c_alt_name' => 'Zimei',
            'c_sequence' => 1,
        ]);
    }

    protected function getPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'altnames',
            'person_id' => 1000,
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_alt_name_chn' => '子美',
                    'c_alt_name_type_code' => 4,
                ],
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    #[Test]
    public function testGetEndpointReturnsRowWithCanonicalShape(): void {
        $user = $this->makeUser(email: 'get-direct@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/get', $this->getPayload());

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'altnames',
            'mode' => 'direct',
            'operation' => 'get',
            'result' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_alt_name_chn' => '子美',
                    'c_alt_name_type_code' => 4,
                ],
                'row' => [
                    'c_personid' => 1000,
                    'c_alt_name_chn' => '子美',
                    'c_alt_name_type_code' => 4,
                    'c_alt_name' => 'Zimei',
                ],
            ],
        ]);
    }

    #[Test]
    public function testGetEndpointRejectsUnauthenticatedRequest(): void {
        $this->seedAltname();

        $response = $this->postJson('/api/v2/get', $this->getPayload());

        $response->assertStatus(401)->assertJson([
            'ok' => false,
            'message' => 'Unauthenticated.',
        ]);
    }

    #[Test]
    public function testGetEndpointValidatesPersonIdAgainstRow(): void {
        $user = $this->makeUser(email: 'get-mismatch@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/get', $this->getPayload([
            'person_id' => 2000,
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('errors.person_id.0', 'mismatch');
    }
}
