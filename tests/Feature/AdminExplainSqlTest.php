<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminExplainSqlTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->string('avatar')->nullable();
            $table->json('settings')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeUser(array $attributes = []): User {
        $user = new User([
            'name' => 'Tester',
            'email' => uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'avatar' => 'avatar0.png',
            'confirmation_token' => Str::random(10),
        ]);

        foreach ($attributes as $key => $value) {
            $user->{$key} = $value;
        }

        if (!isset($attributes['is_active'])) {
            $user->is_active = 1;
        }

        if (!isset($attributes['is_admin'])) {
            $user->is_admin = 1;
        }

        $user->save();

        return $user;
    }

    #[Test]
    public function test_guest_is_redirected_to_login(): void {
        $response = $this->get('/admin/explainsql');
        $response->assertStatus(302);
    }

    #[Test]
    public function test_non_admin_is_forbidden(): void {
        $user = $this->makeUser(['is_admin' => 0]);

        $this->actingAs($user);
        $response = $this->get('/admin/explainsql');
        $response->assertStatus(403);
    }

    #[Test]
    public function test_admin_can_view_form(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->get('/admin/explainsql');
        $response->assertStatus(200)->assertSee('SQL 語句');
    }

    #[Test]
    public function test_admin_can_run_explain(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::statement('CREATE TABLE sample (id INTEGER)');

        $response = $this->post('/admin/explainsql', [
            'sql' => 'SELECT * FROM sample',
        ]);

        $response->assertStatus(200)
            ->assertSee('MySQL EXPLAIN')
            ->assertSee('本次查詢共');
    }
}
