<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueryPlaygroundTest extends TestCase {
    protected $adminUser;
    protected $regularUser;
    protected $inactiveUser;
    protected $crowdsourcingUser;

    protected function setUp(): void {
        parent::setUp();

        // Mock config for strict whitelist testing
        Config::set('codes.tables', [
            'DYNASTIES' => 'Dynasties Table',
            'ALTNAME_DATA' => 'Altname Data',
            'ALLOWED1' => 'A1',
            'ALLOWED2' => 'A2',
        ]);
        Config::set('services.gemini.api_key', 'test-api-key');

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

        $this->inactiveUser = new User();
        $this->inactiveUser->forceFill([
            'id' => 3,
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'is_admin' => User::ROLE_REGULAR,
            'is_active' => User::STATUS_INACTIVE,
        ]);

        $this->crowdsourcingUser = new User();
        $this->crowdsourcingUser->forceFill([
            'id' => 4,
            'name' => 'Crowdsourcing User',
            'email' => 'crowdsourcing@example.com',
            'is_admin' => User::ROLE_CROWDSOURCING,
            'is_active' => User::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function guests_cannot_access_playground() {
        auth()->logout();
        $response = $this->get(route('query-playground.index'));
        $response->assertRedirect('login');
    }

    #[Test]
    public function inactive_users_cannot_access_playground() {
        $this->be($this->inactiveUser);
        $response = $this->get(route('query-playground.index'));
        $response->assertStatus(403);
    }

    #[Test]
    public function regular_users_can_access_playground() {
        $this->be($this->regularUser);
        $response = $this->get(route('query-playground.index'));
        $response->assertRedirect(route('app.query-playground.index'));
    }

    #[Test]
    public function crowdsourcing_users_can_access_playground() {
        $this->be($this->crowdsourcingUser);
        $response = $this->get(route('query-playground.index'));
        $response->assertRedirect(route('app.query-playground.index'));
    }

    #[Test]
    public function expert_users_can_access_playground() {
        $this->be($this->adminUser);
        $response = $this->get(route('query-playground.index'));
        $response->assertRedirect(route('app.query-playground.index'));
    }

    #[Test]
    public function inactive_users_cannot_run_queries() {
        $this->be($this->inactiveUser);
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM DYNASTIES',
        ]);
        $response->assertStatus(403);
    }

    #[Test]
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

    #[Test]
    public function it_blocks_non_whitelisted_tables() {
        $this->be($this->adminUser);

        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM users',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('is not allowed', $response->json()['error'] ?? '');
    }

    #[Test]
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

    #[Test]
    public function it_allows_trailing_semicolon() {
        $this->be($this->adminUser);

        // Should NOT error with semicolon at end
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM ALLOWED1;',
        ]);

        $this->assertNotEquals(403, $response->status(), 'Should allow trailing semicolon');
    }

    #[Test]
    public function it_blocks_non_whitelisted_tables_in_union_queries() {
        $this->be($this->adminUser);

        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM ALLOWED1 UNION ALL SELECT * FROM users',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('users', strtolower($response->json()['error'] ?? ''));
    }

    #[Test]
    public function it_blocks_multiple_statements() {
        $this->be($this->adminUser);

        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM ALLOWED1; SELECT * FROM ALLOWED2',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Forbidden character detected', $response->json()['error'] ?? '');
    }

    #[Test]
    public function guests_cannot_generate_from_nl() {
        auth()->logout();
        $response = $this->postJson(route('query-playground.generate-from-nl'), [
            'question' => 'test question',
        ]);
        $response->assertStatus(401);
    }

    #[Test]
    public function inactive_users_cannot_generate_from_nl() {
        $this->be($this->inactiveUser);
        $response = $this->postJson(route('query-playground.generate-from-nl'), [
            'question' => 'test question',
        ]);
        $response->assertStatus(403);
    }

    #[Test]
    public function it_requires_question_parameter() {
        $this->be($this->adminUser);
        $response = $this->postJson(route('query-playground.generate-from-nl'), []);
        $response->assertStatus(422);
    }

    #[Test]
    public function it_validates_question_length() {
        $this->be($this->adminUser);
        $response = $this->postJson(route('query-playground.generate-from-nl'), [
            'question' => str_repeat('a', 1001),
        ]);
        $response->assertStatus(422);
    }

    #[Test]
    public function it_allows_subqueries_with_whitelisted_tables() {
        $this->be($this->adminUser);

        // Simple subquery in FROM clause
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM (SELECT * FROM ALLOWED1) AS sub',
        ]);
        $this->assertNotEquals(403, $response->status(), 'Should allow subquery with whitelisted table');

        // Subquery in JOIN clause (like user's example)
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT n.* FROM ALLOWED1 n JOIN (SELECT c_id, COUNT(*) AS cnt FROM ALLOWED2 GROUP BY c_id HAVING COUNT(*) > 1) t ON n.c_id = t.c_id',
        ]);
        $this->assertNotEquals(403, $response->status(), 'Should allow JOIN with subquery');
    }

    #[Test]
    public function it_blocks_subqueries_with_non_whitelisted_tables() {
        $this->be($this->adminUser);

        // Subquery containing forbidden table
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM (SELECT * FROM users) AS sub',
        ]);
        $response->assertStatus(403);
        $this->assertStringContainsString('users', strtolower($response->json()['error'] ?? ''));
    }

    #[Test]
    public function it_handles_nested_subqueries() {
        $this->be($this->adminUser);

        // Nested subqueries with whitelisted tables
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM (SELECT * FROM (SELECT * FROM ALLOWED1) AS inner_sub) AS outer_sub',
        ]);
        $this->assertNotEquals(403, $response->status(), 'Should allow nested subqueries with whitelisted tables');

        // Nested subqueries with one forbidden table
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM (SELECT * FROM (SELECT * FROM users) AS inner_sub) AS outer_sub',
        ]);
        $response->assertStatus(403);
        $this->assertStringContainsString('users', strtolower($response->json()['error'] ?? ''));
    }

    #[Test]
    public function it_allows_with_queries_using_whitelisted_tables() {
        $this->be($this->adminUser);

        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'WITH allowed_rows AS (SELECT * FROM ALLOWED1) SELECT * FROM allowed_rows',
        ]);

        $this->assertNotEquals(403, $response->status(), 'Should allow WITH query that only uses whitelisted tables');
    }

    #[Test]
    public function it_allows_with_recursive_queries_using_whitelisted_tables() {
        $this->be($this->adminUser);

        Config::set('codes.tables.ADDR_CODES', 'Address Codes');
        Config::set('codes.tables.ADDR_BELONGS_DATA', 'Address Belongs Data');

        // Exact query from issue #948
        $sql = <<<'SQL'
WITH RECURSIVE
  chain AS (
    SELECT ac.c_addr_id, ac.c_name_chn, ac.c_admin_cat_code, ac.c_firstyear, ac.c_lastyear,
           abd.c_belongs_to, 1 AS lvl
    FROM ADDR_CODES ac
      LEFT JOIN ADDR_BELONGS_DATA abd ON abd.c_addr_id = ac.c_addr_id
    WHERE ac.c_name_chn LIKE '%辦事大臣%'
    UNION ALL
    SELECT ac2.c_addr_id, ac2.c_name_chn, ac2.c_admin_cat_code, ac2.c_firstyear, ac2.c_lastyear,
           abd2.c_belongs_to, chain.lvl + 1
    FROM chain
      JOIN ADDR_CODES ac2 ON ac2.c_addr_id = chain.c_belongs_to
      LEFT JOIN ADDR_BELONGS_DATA abd2 ON abd2.c_addr_id = ac2.c_addr_id
    WHERE chain.c_belongs_to IS NOT NULL AND chain.lvl < 8
  )
SELECT * FROM chain ORDER BY c_addr_id, lvl
SQL;

        $response = $this->postJson(route('query-playground.run'), ['sql' => $sql]);

        // Must not get 403 — table validation should recognise ADDR_CODES and
        // ADDR_BELONGS_DATA, filtering out the CTE alias "chain".
        // A 500 (table doesn't exist in test DB) is acceptable.
        $this->assertNotEquals(403, $response->status(), 'WITH RECURSIVE query with whitelisted tables must pass table validation (issue #948)');
    }

    #[Test]
    public function it_handles_real_world_example_from_issue() {
        $this->be($this->adminUser);

        // Add NIAN_HAO to config for this test
        Config::set('codes.tables.NIAN_HAO', 'Year Names Table');

        // User's actual query that was failing
        $sql = "SELECT n.*, t.cnt AS repeat_count
                FROM NIAN_HAO n
                JOIN (SELECT c_nianhao_chn, COUNT(*) AS cnt FROM NIAN_HAO GROUP BY c_nianhao_chn HAVING COUNT(*) > 1) t
                ON n.c_nianhao_chn = t.c_nianhao_chn
                ORDER BY n.c_nianhao_chn";

        $response = $this->postJson(route('query-playground.run'), ['sql' => $sql]);

        // Should not get 403 forbidden (table validation should pass)
        // May get 500 if table doesn't exist in test DB, but that's okay
        $this->assertNotEquals(403, $response->status(), 'Should allow the real-world subquery example');

        // Verify it's not complaining about 'SELECT' being a table
        if ($response->status() === 403) {
            $error = $response->json()['error'] ?? '';
            $this->assertStringNotContainsStringIgnoringCase('SELECT', $error, 'Should not identify SELECT as a table name');
        }
    }

    #[Test]
    public function it_blocks_double_quoted_identifier_bypass_attempt() {
        $this->be($this->adminUser);

        // Security test: Attempt to bypass whitelist using double-quoted identifiers
        // In ANSI_QUOTES mode or PostgreSQL, "users" is an identifier, not a string
        // The old regex-based implementation would strip this and allow the query
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM ALLOWED1 JOIN "users" u ON ALLOWED1.id = u.id',
        ]);

        // Should block access to 'users' table
        $response->assertStatus(403);
        $this->assertStringContainsString('users', strtolower($response->json()['error'] ?? ''));
    }

    #[Test]
    public function it_blocks_backtick_quoted_identifier() {
        $this->be($this->adminUser);

        // Test with MySQL backtick-quoted identifiers
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM ALLOWED1 JOIN `users` u ON ALLOWED1.id = u.id',
        ]);

        // Should block access to 'users' table
        $response->assertStatus(403);
        $this->assertStringContainsString('users', strtolower($response->json()['error'] ?? ''));
    }

    #[Test]
    public function it_allows_quoted_whitelisted_tables() {
        $this->be($this->adminUser);

        // Quoted identifiers should work fine for whitelisted tables
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM "ALLOWED1" JOIN `ALLOWED2` ON ALLOWED1.id = ALLOWED2.id',
        ]);

        // Should allow since both tables are whitelisted (regardless of quoting)
        $this->assertNotEquals(403, $response->status(), 'Should allow quoted whitelisted table identifiers');
    }

    #[Test]
    public function inactive_users_cannot_access_qbe_schema_endpoint() {
        $this->be($this->inactiveUser);

        $response = $this->postJson(route('query-playground.schema'), [
            'tables' => ['DYNASTIES'],
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function expert_users_can_access_qbe_schema_endpoint() {
        $this->be($this->adminUser);

        $response = $this->postJson(route('query-playground.schema'), [
            'tables' => ['DYNASTIES', 'NOT_ALLOWED'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'tables' => [
                'DYNASTIES' => [
                    'description',
                    'columns',
                    'error',
                ],
            ],
        ]);
        $this->assertArrayNotHasKey('NOT_ALLOWED', $response->json('tables', []));
    }
}
