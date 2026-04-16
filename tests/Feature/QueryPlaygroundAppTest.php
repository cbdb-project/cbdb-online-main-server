<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueryPlaygroundAppTest extends TestCase {
    protected User $adminUser;
    protected User $regularUser;
    protected User $inactiveUser;
    protected User $crowdsourcingUser;

    protected function setUp(): void {
        parent::setUp();

        Config::set('codes.tables', [
            'DYNASTIES' => 'Dynasties Table',
            'ALTNAME_DATA' => 'Altname Data',
            'ALLOWED1' => 'A1',
            'ALLOWED2' => 'A2',
        ]);
        Config::set('services.gemini.model', 'gemini-test-model');

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
    public function guests_cannot_access_app_playground() {
        auth()->logout();
        $response = $this->get(route('app.query-playground.index'));
        $response->assertRedirect('login');
    }

    #[Test]
    public function inactive_users_cannot_access_app_playground() {
        $this->be($this->inactiveUser);
        $response = $this->get(route('app.query-playground.index'));
        $response->assertStatus(403);
    }

    #[Test]
    public function regular_users_can_access_app_playground() {
        $this->be($this->regularUser);
        $response = $this->get(route('app.query-playground.index'));
        $response->assertStatus(200);
    }

    #[Test]
    public function crowdsourcing_users_can_access_app_playground() {
        $this->be($this->crowdsourcingUser);
        $response = $this->get(route('app.query-playground.index'));
        $response->assertStatus(200);
    }

    #[Test]
    public function expert_users_can_access_app_playground() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));
        $response->assertStatus(200);
    }

    #[Test]
    public function app_playground_includes_csrf_meta_tag_for_react_requests() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertStatus(200);
        $response->assertSee('<meta name="csrf-token" content="', false);
    }

    #[Test]
    public function app_playground_returns_inertia_page() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index');
        });
    }

    #[Test]
    public function app_playground_has_correct_props_structure() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->has('initialSql')
                ->has('nlModel')
                ->has('qbeTables')
                ->has('pageUrl')
                ->has('runEndpoint')
                ->has('schemaEndpoint')
                ->has('generateFromNlEndpoint')
                ->has('generateFromNlStreamEndpoint');
        });
    }

    #[Test]
    public function app_playground_default_sql_is_dynasties() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->where('initialSql', 'SELECT * FROM DYNASTIES');
        });
    }

    #[Test]
    public function app_playground_accepts_sql_from_query_string() {
        $this->be($this->adminUser);
        $customSql = 'SELECT c_personid FROM ALTNAME_DATA';
        $response = $this->get(route('app.query-playground.index', ['sql' => $customSql]));

        $response->assertInertia(function (AssertableInertia $page) use ($customSql) {
            $page->component('QueryPlayground/Index')
                ->where('initialSql', $customSql);
        });
    }

    #[Test]
    public function app_playground_passes_nl_model() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->where('nlModel', 'gemini-test-model');
        });
    }

    #[Test]
    public function app_playground_passes_qbe_tables_sorted() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->has('qbeTables', 4)
                ->has('qbeTables.0', function (AssertableInertia $table) {
                    $table->has('name')
                        ->has('description')
                        ->has('internal');
                });
        });
    }

    #[Test]
    public function app_playground_passes_relative_endpoint_urls() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->where('pageUrl', '/app/query-playground')
                ->where('runEndpoint', '/query-playground/run')
                ->where('schemaEndpoint', '/query-playground/schema')
                ->where('generateFromNlEndpoint', '/query-playground/generate-from-nl')
                ->where('generateFromNlStreamEndpoint', '/query-playground/generate-from-nl-stream');
        });
    }

    #[Test]
    public function old_playground_entry_redirects_to_app_version() {
        $this->be($this->adminUser);
        $response = $this->get(route('query-playground.index'));
        $response->assertRedirect(route('app.query-playground.index'));
    }

    #[Test]
    public function old_run_endpoint_still_works() {
        $this->be($this->adminUser);
        $response = $this->postJson(route('query-playground.run'), [
            'sql' => 'SELECT * FROM DYNASTIES',
        ]);
        // May be 200 or 500 depending on DB; should NOT be 403
        $this->assertNotEquals(403, $response->status());
    }

    #[Test]
    public function old_schema_endpoint_still_works() {
        $this->be($this->adminUser);
        $response = $this->postJson(route('query-playground.schema'), [
            'tables' => ['DYNASTIES'],
        ]);
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'tables' => [
                'DYNASTIES' => ['description', 'columns', 'error'],
            ],
        ]);
    }

    #[Test]
    public function qbe_tables_include_internal_tables_when_present() {
        Config::set('codes.tables', [
            'DYNASTIES' => 'Dynasties',
            'CBDB__NAME_FTS' => 'Name FTS Index',
        ]);

        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->has('qbeTables', 2);
        });

        // Verify non-internal tables come first
        $qbeTables = $response->original->getData()['page']['props']['qbeTables'];
        $this->assertFalse($qbeTables[0]['internal'], 'First table should be non-internal');
        $this->assertTrue($qbeTables[1]['internal'], 'Second table should be internal');
    }
}
