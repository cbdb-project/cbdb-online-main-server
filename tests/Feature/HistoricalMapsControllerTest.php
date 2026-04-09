<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricalMapsControllerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\PrometheusMetrics::class);

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
            $table->string('avatar')->default('avatar0.png');
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

    protected function createUser(): User {
        return User::create([
            'name' => 'Test User',
            'email' => 'maps@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'maps-test-token',
            'is_active' => 1,
        ]);
    }

    #[Test]
    public function test_guest_cannot_access_historical_maps_page(): void {
        $response = $this->get('/app/maps');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function test_authenticated_user_can_access_historical_maps_page(): void {
        $response = $this->actingAs($this->createUser())->get('/app/maps');

        $response->assertOk();
        $response->assertViewIs('maps.index');
        $response->assertSee('中國歷代行政區地圖');
        $response->assertSee('historical-layer-select', false);
    }

    #[Test]
    public function test_legacy_maps_path_redirects_to_app_endpoint(): void {
        $response = $this->actingAs($this->createUser())->get('/maps/index.html?lat=34.1&lng=108.9&map=ad0741');

        $response->assertRedirect('/app/maps?lat=34.1&lng=108.9&map=ad0741');
    }
}
