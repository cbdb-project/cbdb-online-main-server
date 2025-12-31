<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OptionalAuthenticationMiddlewareTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $this->setUpInMemoryDatabase();

        Route::middleware('auth.optional')->get('/testing/auth-optional', function (Request $request) {
            return response()->json([
                'user_id' => optional($request->user())->id,
            ]);
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    #[Test]
    public function test_guest_without_token_can_pass(): void {
        $this->getJson('/testing/auth-optional')
            ->assertStatus(200)
            ->assertJson(['user_id' => null]);
    }

    #[Test]
    public function test_request_with_valid_token_succeeds(): void {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/testing/auth-optional')
            ->assertStatus(200)
            ->assertJson(['user_id' => $user->id]);
    }

    #[Test]
    public function test_request_with_invalid_token_is_rejected(): void {
        $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson('/testing/auth-optional')
            ->assertStatus(401);
    }

    private function setUpInMemoryDatabase(): void {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(User::STATUS_ACTIVE);
            $table->integer('is_admin')->default(User::ROLE_REGULAR);
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
}
