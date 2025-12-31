<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MigrationTest extends TestCase {
    /**
     * Test that all migrations can run successfully on a clean SQLite database.
     */
    #[Test]
    public function test_all_migrations_run_successfully_on_sqlite() {
        // 1. Verify environmental isolation BEFORE running migrations
        $driver = DB::getDriverName();
        $this->assertEquals('sqlite', $driver, 'The test MUST run on SQLite.');

        $database = config('database.connections.sqlite.database');
        $this->assertEquals(':memory:', $database, 'The test MUST run in memory to avoid accidental data loss.');

        // 2. Manually trigger migrations
        Artisan::call('migrate:fresh');

        // 3. Verify Laravel core tables
        $this->assertTrue(Schema::hasTable('users'), 'Laravel users table should exist.');
        $this->assertTrue(Schema::hasTable('operations'), 'Legacy operations table should exist.');
        $this->assertTrue(Schema::hasTable('personal_access_tokens'), 'Personal access tokens table should exist.');

        // 4. Verify all allowlisted tables from config/codes.php
        $allowlistedTables = array_keys(config('codes.tables', []));
        $this->assertNotEmpty($allowlistedTables, 'The allowlist should not be empty.');

        foreach ($allowlistedTables as $table) {
            // Some entries in allowlist might be views
            if ($this->isView($table)) {
                $this->assertTrue(true, "Checked view: $table");
            } else {
                $this->assertTrue(Schema::hasTable($table), "Allowlisted table '$table' should exist.");
            }
        }

        // 5. Verification of specific SQL views defined in migrations
        $sqlViews = [
            'View_BiogInstData',
            'View_PossessionsData',
        ];

        foreach ($sqlViews as $view) {
            $this->assertTrue($this->isView($view), "SQL View '$view' should exist.");
        }
    }

    /**
     * Check if a name refers to a view in SQLite master.
     */
    protected function isView(string $name): bool {
        return DB::connection('sqlite')
            ->table('sqlite_master')
            ->where('type', 'view')
            ->where('name', $name)
            ->exists();
    }
}
