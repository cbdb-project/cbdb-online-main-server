<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class QueryPlaygroundTest extends TestCase {
    protected $adminUser;
    protected $regularUser;

    protected function setUp(): void {
        parent::setUp();

        // Mock config for strict whitelist testing
        Config::set('codes.tables', [
            'DYNASTIES' => 'Dynasties Table',
            'ALTNAME_DATA' => 'Altname Data',
            'ALLOWED1' => 'A1',
            'ALLOWED2' => 'A2',
        ]);

        // Create users if DB is available, otherwise mock Auth
        // Assuming In-Memory SQLite usually used in Laravel tests
        // Create users using forceFill since is_admin/is_active are not fillable
        $this->adminUser = new User();
        $this->adminUser->forceFill([
            'id' => 1,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'is_admin' => User::ROLE_EXPERT,
            'is_active' => User::STATUS_ACTIVE,
        ]);

        $this->regularUser = new User();
        $this->regularUser->forceFill([
            'id' => 2,
            'name' => 'Regular User',
            'email' => 'reg@example.com',
            'is_admin' => User::ROLE_REGULAR,
            'is_active' => User::STATUS_ACTIVE,
        ]);
    }

    /** @test */
    public function guests_cannot_access_playground() {
        auth()->logout();
        $response = $this->get(route('query-playground.index'));
        $response->assertRedirect('login');
    }

    /** @test */
    public function regular_users_cannot_access_playground() {
        $this->be($this->regularUser);
        $response = $this->get(route('query-playground.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function expert_users_can_access_playground() {
        $this->be($this->adminUser);
        $response = $this->get(route('query-playground.index'));
        $response->assertStatus(200);
        $response->assertSee('SQL 查詢練習場');
    }

    /** @test */
    public function regular_users_cannot_run_queries() {
        $this->be($this->regularUser);
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM DYNASTIES',
        ]);
        $response->assertStatus(403);
    }

    /** @test */
    public function it_blocks_forbidden_keywords() {
        $this->be($this->adminUser);

        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'DELETE FROM DYNASTIES',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'Forbidden keyword detected: DELETE',
        ]);
    }

    /** @test */
    public function it_blocks_non_whitelisted_tables() {
        $this->be($this->adminUser);

        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM users',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('is not allowed', $response->json()['error'] ?? '');
    }

    /** @test */
    public function it_extracts_multiple_tables_correctly() {
        $this->be($this->adminUser);

        // 1. Valid Join
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM ALLOWED1 JOIN ALLOWED2 ON id=id',
        ]);
        // Expecting either 200 (if tables exist) or 500 (if tables missing).
        // Definitely NOT 403.
        $this->assertNotEquals(403, $response->status(), 'Should allow valid join');

        // 2. Invalid Join
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM ALLOWED1 JOIN USERS ON id=id',
        ]);
        $response->assertStatus(403);
        $this->assertStringContainsString('USERS', $response->json()['error'] ?? '');

        // 3. Comma Separated matches
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM ALLOWED1, ALLOWED2',
        ]);
        $this->assertNotEquals(403, $response->status(), 'Should allow valid comma join');

        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM ALLOWED1, USERS',
        ]);
        $response->assertStatus(403);
    }

    /** @test */
    public function it_allows_trailing_semicolon() {
        $this->be($this->adminUser);

        // Should NOT error with semicolon at end
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM ALLOWED1;',
        ]);

        $this->assertNotEquals(403, $response->status(), 'Should allow trailing semicolon');
    }

    /** @test */
    public function it_blocks_multiple_statements() {
        $this->be($this->adminUser);

        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM ALLOWED1; SELECT * FROM ALLOWED2',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Forbidden character detected', $response->json()['error'] ?? '');
    }
}
